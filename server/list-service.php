<?php
final class ApiError extends RuntimeException
{
    public $status;
    public $kind;
    public function __construct(string $message, int $status = 422, string $kind = 'validation')
    {
        parent::__construct($message);
        $this->status = $status;
        $this->kind = $kind;
    }
}

final class ListService
{
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    private function revision(): int
    {
        return (int) $this->db->query('SELECT revision FROM list_state WHERE id = 1')->fetchColumn();
    }

    private function snapshot(): array
    {
        return ['revision' => $this->revision(), 'items' => array_map([self::class, 'item'], $this->db->query(
            'SELECT id, name, checked, position FROM items ORDER BY checked, position, id'
        )->fetchAll(PDO::FETCH_ASSOC))];
    }

    // PDO SQLite returns integer columns as strings before PHP 8.1.
    private static function item(array $row): array
    {
        return ['id' => (int) $row['id'], 'name' => $row['name'], 'checked' => (int) $row['checked'], 'position' => (int) $row['position']];
    }

    public function legacyAdd(string $requestId): ?array
    {
        $query = $this->db->prepare('SELECT id, name, checked, position FROM items WHERE request_id = ?');
        $query->execute([$requestId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row ? self::item($row) : null;
    }

    public function read(?int $known = null): array
    {
        $this->db->beginTransaction();
        try {
            $snapshot = $this->snapshot();
            $this->db->commit();
            return ['success' => true, 'version' => 1, 'unchanged' => $known === $snapshot['revision']] + $snapshot;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    private function id($value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) throw new ApiError('Valid item id is required');
        return $id;
    }

    private function ids($values): array
    {
        if (!is_array($values) || count($values) > 10000) throw new ApiError('Item ids are required');
        return array_values(array_unique(array_map(function ($id) { return $this->id($id); }, $values)));
    }

    private function name($value): string
    {
        if (!is_string($value) || trim($value) === '') throw new ApiError('Item name is required');
        $value = trim($value);
        if (strlen($value) > 160) throw new ApiError('Item name must fit 160 UTF-8 bytes');
        return $value;
    }

    private function existing($value): int
    {
        $id = $this->id($value);
        $query = $this->db->prepare('SELECT id FROM items WHERE id = ?');
        $query->execute([$id]);
        if (!$query->fetchColumn()) throw new ApiError('Item not found', 404, 'not_found');
        return $id;
    }

    private function execute(string $sql, array $params): void
    {
        $query = $this->db->prepare($sql);
        $query->execute($params);
    }

    private function mutate(array $op): array
    {
        switch ($op['type'] ?? '') {
            case 'add':
                $name = $this->name($op['name'] ?? null);
                $this->execute('INSERT INTO items(name, position) VALUES (?, (SELECT COALESCE(MAX(position), 0) + 1 FROM items))', [$name]);
                return ['item_id' => (int) $this->db->lastInsertId()];
            case 'set_checked':
                if (!isset($op['checked']) || !in_array($op['checked'], [0, 1, false, true], true)) throw new ApiError('Checked must be boolean or 0/1');
                $this->execute('UPDATE items SET checked = ? WHERE id = ?', [(int) $op['checked'], $this->existing($op['item_id'] ?? null)]);
                break;
            case 'edit':
                $this->execute('UPDATE items SET name = ? WHERE id = ?', [$this->name($op['name'] ?? null), $this->existing($op['item_id'] ?? null)]);
                break;
            case 'delete':
                $this->execute('DELETE FROM items WHERE id = ?', [$this->existing($op['item_id'] ?? null)]);
                break;
            case 'delete_items':
                foreach ($this->ids($op['item_ids'] ?? null) as $id) $this->execute('DELETE FROM items WHERE id = ?', [$id]);
                break;
            case 'reorder':
                foreach ($this->ids($op['item_ids'] ?? null) as $index => $id) $this->execute('UPDATE items SET position = ? WHERE id = ?', [$index + 1, $id]);
                break;
            default: throw new ApiError('Unknown operation');
        }
        return [];
    }

    public function apply(array $operations, int $baseRevision): array
    {
        if (!$operations || count($operations) > 100) throw new ApiError('Provide between 1 and 100 operations');
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $results = [];
            $new = [];
            $seen = [];
            foreach ($operations as $op) {
                if (!is_array($op) || !isset($op['id']) || !is_string($op['id']) || !preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $op['id'])) throw new ApiError('Invalid operation id');
                if (isset($seen[$op['id']])) throw new ApiError('Duplicate operation id in batch');
                $seen[$op['id']] = true;
                // Canonicalize top-level fields so JSON key ordering does not affect retry identity.
                ksort($op);
                $fingerprint = hash('sha256', json_encode($op, JSON_THROW_ON_ERROR));
                $query = $this->db->prepare('SELECT fingerprint, result FROM operations WHERE id = ?');
                $query->execute([$op['id']]);
                $saved = $query->fetch(PDO::FETCH_ASSOC);
                if ($saved) {
                    if ($saved['fingerprint'] !== $fingerprint) throw new ApiError('Operation id reused with different content', 409, 'operation_mismatch');
                    $results[$op['id']] = json_decode($saved['result'], true);
                } else {
                    $new[] = [$op, $fingerprint];
                }
            }
            // Already-applied retries succeed even with an old base revision.
            if ($new && $baseRevision !== $this->revision()) throw new ApiError('List changed. Refresh before saving again.', 409, 'conflict');
            foreach ($new as [$op, $fingerprint]) {
                $result = ['id' => $op['id'], 'status' => 'applied'] + $this->mutate($op);
                $this->execute('INSERT INTO operations VALUES (?, ?, ?)', [$op['id'], $fingerprint, json_encode($result, JSON_THROW_ON_ERROR)]);
                $results[$op['id']] = $result;
            }
            if ($new) $this->db->exec('UPDATE list_state SET revision = revision + 1 WHERE id = 1');
            $snapshot = $this->snapshot();
            $this->db->exec('COMMIT');
            return ['success' => true, 'version' => 1, 'results' => array_values($results)] + $snapshot;
        } catch (Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }
}
