<?php
require __DIR__ . '/../server/api.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $work, string $kind) {
    try { $work(); } catch (ApiError $e) { check($e->kind === $kind, $e->getMessage()); return; }
    throw new RuntimeException('Expected ' . $kind);
}
$path = tempnam(sys_get_temp_dir(), 'getme-test-');
try {
    // Upgrade an existing installation without replacing items or IDs.
    $old = new PDO('sqlite:' . $path);
    $old->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, checked INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0)');
    $old->exec("INSERT INTO items VALUES(42, 'Existing', 0, 1)");
    $old = null;
    $db = open_database($path);
    $s = new ListService($db);
    check($s->read()['items'][0]['id'] === 42, 'Migration preserved ID');
    $db->exec("UPDATE items SET request_id = 'pre-upgrade' WHERE id = 42");
    $preserved = legacy_request($s, ['action' => 'add', 'name' => 'Existing', 'request_id' => 'pre-upgrade']);
    check($preserved['item']['id'] === 42 && $preserved['revision'] === 0, 'Pre-upgrade request ID retained');
    $op = ['id' => 'add-1', 'type' => 'add', 'name' => 'Milk'];
    $one = $s->apply([$op], 0);
    check($one['revision'] === 1 && count($one['items']) === 2, 'Add committed');
    $id = $one['results'][0]['item_id'];
    $two = $s->apply([$op], 0);
    check($two['revision'] === 1 && $two['results'] === $one['results'], 'Lost response retry deduplicated');
    rejects(function () use ($s, $op) { $s->apply([array_merge($op, ['name' => 'Eggs'])], 1); }, 'operation_mismatch');
    rejects(function () use ($s) { $s->apply([['id' => 'stale', 'type' => 'add', 'name' => 'Stale']], 0); }, 'conflict');
    check($s->read()['revision'] === 1, 'Conflict did not write');
    rejects(function () use ($s) { $s->apply([['id' => 'batch-1', 'type' => 'add', 'name' => 'Rollback'], ['id' => 'batch-2', 'type' => 'edit', 'item_id' => 999, 'name' => 'No']], 1); }, 'not_found');
    check(count($s->read()['items']) === 2 && $s->read()['revision'] === 1, 'Batch rolled back including operation log');
    $s->apply([['id' => 'check', 'type' => 'set_checked', 'item_id' => $id, 'checked' => 1]], 1);
    $s->apply([['id' => 'check-other', 'type' => 'set_checked', 'item_id' => 42, 'checked' => 1]], 2);
    $clear = $s->apply([['id' => 'clear', 'type' => 'delete_items', 'item_ids' => [$id]]], 3);
    check(count($clear['items']) === 1 && $clear['items'][0]['id'] === 42, 'Clear only deletes explicit targets');
    check($s->read(4)['unchanged'], 'Revision read');
    $legacy = legacy_request($s, ['action' => 'add', 'name' => 'Legacy', 'request_id' => 'old-1']);
    $retry = legacy_request($s, ['action' => 'add', 'name' => 'Legacy', 'request_id' => 'old-1']);
    check($legacy['item']['id'] === $retry['item']['id'] && $legacy['revision'] === $retry['revision'], 'Legacy retry');
    $s2 = new ListService(open_database($path));
    check($s2->read()['revision'] === $retry['revision'], 'Second connection sees revision');
    rejects(function () use ($s2) { $s2->apply([['id' => 'other-client', 'type' => 'add', 'name' => 'No']], 0); }, 'conflict');
    echo "API integration tests passed\n";
} finally { unlink($path); }
