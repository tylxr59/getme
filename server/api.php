<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/list-service.php';

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

function dispatch_api(ListService $service): void
{
    try {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            if (!empty($_POST)) $data = $_POST;
            else throw new ApiError('Expected a JSON object', 400);
        }
        if (isset($data['version'])) {
            if ($data['version'] !== 1) throw new ApiError('Unsupported API version', 400, 'version');
            $revision = $data['revision'] ?? null;
            if ($revision !== null && (!is_int($revision) || $revision < 0)) throw new ApiError('Invalid revision');
            if (($data['action'] ?? '') === 'read') json_response($service->read($revision));
            if (($data['action'] ?? '') !== 'apply' || $revision === null || !is_array($data['operations'] ?? null)) throw new ApiError('Expected read or apply with revision and operations');
            json_response($service->apply($data['operations'], $revision));
        }
        json_response(legacy_request($service, $data));
    } catch (ApiError $error) {
        json_response(['success' => false, 'error' => $error->getMessage(), 'code' => $error->kind], $error->status);
    } catch (Throwable $error) {
        error_log((string) $error);
        json_response(['success' => false, 'error' => 'Something went wrong', 'code' => 'server_error'], 500);
    }
}

// Old clients remain usable, and their writes advance the same revision.
function legacy_request(ListService $service, array $data): array
{
    $snapshot = $service->read();
    $action = $data['action'] ?? '';
    if ($action === 'fetch') return $snapshot;
    $op = ['id' => 'legacy:' . bin2hex(random_bytes(16)), 'type' => $action];
    switch ($action) {
        case 'add':
        case 'add_item':
            if (isset($data['request_id'])) {
                if (!is_string($data['request_id']) || trim($data['request_id']) === '' || strlen($data['request_id']) > 100) throw new ApiError('Invalid request id');
                $existing = $service->legacyAdd(trim($data['request_id']));
                if ($existing) return ['success' => true, 'item' => $existing] + $snapshot;
                $op['id'] = 'legacy-add:' . hash('sha256', trim($data['request_id']));
            }
            $op['type'] = 'add';
            $op['name'] = $data['name'] ?? null;
            break;
        case 'toggle':
            $op['type'] = 'set_checked';
            $op['item_id'] = $data['id'] ?? null;
            $op['checked'] = !empty($data['checked']) ? 1 : 0;
            break;
        case 'edit':
            $op['item_id'] = $data['id'] ?? null;
            $op['name'] = $data['name'] ?? null;
            break;
        case 'delete': $op['item_id'] = $data['id'] ?? null; break;
        case 'reorder': $op['item_ids'] = $data['items'] ?? null; break;
        case 'clear_all':
        case 'clear_checked':
            $op['type'] = 'delete_items';
            $op['item_ids'] = array_column(array_filter($snapshot['items'], function ($item) use ($action) {
                return $action === 'clear_all' || (int) $item['checked'] === 1;
            }), 'id');
            break;
        default: throw new ApiError('Unknown action', 400);
    }
    $result = $service->apply([$op], $snapshot['revision']);
    if ($op['type'] === 'add') {
        $id = $result['results'][0]['item_id'];
        $result['item'] = current(array_filter($result['items'], function ($item) use ($id) { return (int) $item['id'] === $id; })) ?: null;
    }
    return $result;
}
