<?php
$listName = 'getme';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$db = new PDO('sqlite:' . __DIR__ . '/getme.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');

$db->exec("CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 0
)");
$db->exec("CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    checked INTEGER NOT NULL DEFAULT 0,
    position INTEGER NOT NULL DEFAULT 0
)");

$itemColumns = $db->query('PRAGMA table_info(items)')->fetchAll(PDO::FETCH_ASSOC);
$hasCategoryId = false;
foreach ($itemColumns as $column) {
    if ($column['name'] === 'category_id') {
        $hasCategoryId = true;
        break;
    }
}

if (!$hasCategoryId) {
    $db->exec('ALTER TABLE items ADD COLUMN category_id INTEGER');
}

$defaultCategoryId = ensure_default_category($db);
$stmt = $db->prepare('UPDATE items SET category_id = ? WHERE category_id IS NULL OR category_id NOT IN (SELECT id FROM categories)');
$stmt->execute([$defaultCategoryId]);

$db->exec("CREATE INDEX IF NOT EXISTS idx_categories_sort ON categories(position, id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_items_category_sort ON items(category_id, checked, position, id)");

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function request_data(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (is_array($data)) {
        return $data;
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    return [];
}

function ensure_default_category(PDO $db): int
{
    $id = $db->query('SELECT id FROM categories ORDER BY position ASC, id ASC LIMIT 1')->fetchColumn();
    if ($id) {
        return (int) $id;
    }

    $db->exec("INSERT INTO categories (name, position) VALUES ('List', 1)");
    return (int) $db->lastInsertId();
}

function all_items(PDO $db): array
{
    return $db
        ->query('SELECT items.id, items.name, items.checked, items.position, items.category_id
            FROM items
            LEFT JOIN categories ON categories.id = items.category_id
            ORDER BY categories.position ASC, categories.id ASC, items.checked ASC, items.position ASC, items.id ASC')
        ->fetchAll(PDO::FETCH_ASSOC);
}

function all_categories(PDO $db): array
{
    $categories = $db
        ->query('SELECT id, name, position FROM categories ORDER BY position ASC, id ASC')
        ->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query('SELECT id, name, checked, position, category_id FROM items ORDER BY category_id ASC, checked ASC, position ASC, id ASC');
    $itemsByCategory = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $itemsByCategory[(int) $item['category_id']][] = $item;
    }

    foreach ($categories as &$category) {
        $category['items'] = $itemsByCategory[(int) $category['id']] ?? [];
    }
    unset($category);

    return $categories;
}

function next_category_position(PDO $db): int
{
    return (int) $db->query('SELECT COALESCE(MAX(position), 0) + 1 FROM categories')->fetchColumn();
}

function next_item_position(PDO $db, int $categoryId): int
{
    $stmt = $db->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM items WHERE category_id = ?');
    $stmt->execute([$categoryId]);
    return (int) $stmt->fetchColumn();
}

function require_int(array $data, string $key, string $message): int
{
    $id = filter_var($data[$key] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        json_response(['success' => false, 'error' => $message], 422);
    }
    return $id;
}

function require_existing_item_id(PDO $db, array $data): int
{
    $id = require_int($data, 'id', 'Valid item id is required');
    $stmt = $db->prepare('SELECT COUNT(*) FROM items WHERE id = ?');
    $stmt->execute([$id]);

    if ((int) $stmt->fetchColumn() === 0) {
        json_response(['success' => false, 'error' => 'Item not found'], 404);
    }

    return $id;
}

function require_existing_category_id(PDO $db, array $data, string $key = 'id'): int
{
    $id = require_int($data, $key, 'Valid category id is required');
    $stmt = $db->prepare('SELECT COUNT(*) FROM categories WHERE id = ?');
    $stmt->execute([$id]);

    if ((int) $stmt->fetchColumn() === 0) {
        json_response(['success' => false, 'error' => 'Category not found'], 404);
    }

    return $id;
}

function require_name(array $data, string $label = 'Name'): string
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        json_response(['success' => false, 'error' => $label . ' is required'], 422);
    }
    return substr($name, 0, 160);
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = request_data();
    $action = (string) ($data['action'] ?? '');

    try {
        switch ($action) {
            case 'fetch':
                json_response(['success' => true, 'items' => all_items($db), 'categories' => all_categories($db)]);

            case 'add_category':
                $name = require_name($data, 'Category name');
                $position = next_category_position($db);
                $stmt = $db->prepare('INSERT INTO categories (name, position) VALUES (?, ?)');
                $stmt->execute([$name, $position]);
                json_response([
                    'success' => true,
                    'category' => [
                        'id' => (int) $db->lastInsertId(),
                        'name' => $name,
                        'position' => $position,
                        'items' => [],
                    ],
                ]);

            case 'edit_category':
                $stmt = $db->prepare('UPDATE categories SET name = ? WHERE id = ?');
                $stmt->execute([require_name($data, 'Category name'), require_existing_category_id($db, $data)]);
                json_response(['success' => true]);

            case 'delete_category':
                $id = require_existing_category_id($db, $data);
                $count = (int) $db->query('SELECT COUNT(*) FROM categories')->fetchColumn();
                if ($count <= 1) {
                    json_response(['success' => false, 'error' => 'At least one category is required'], 422);
                }

                $db->beginTransaction();
                $stmt = $db->prepare('DELETE FROM items WHERE category_id = ?');
                $stmt->execute([$id]);
                $stmt = $db->prepare('DELETE FROM categories WHERE id = ?');
                $stmt->execute([$id]);
                $db->commit();
                json_response(['success' => true]);

            case 'add':
            case 'add_item':
                $categoryId = isset($data['category_id'])
                    ? require_existing_category_id($db, $data, 'category_id')
                    : ensure_default_category($db);
                $stmt = $db->prepare('INSERT INTO items (name, position, category_id) VALUES (?, ?, ?)');
                $stmt->execute([require_name($data, 'Item name'), next_item_position($db, $categoryId), $categoryId]);
                json_response([
                    'success' => true,
                    'item' => $db
                        ->query('SELECT id, name, checked, position, category_id FROM items WHERE id = ' . (int) $db->lastInsertId())
                        ->fetch(PDO::FETCH_ASSOC),
                ]);

            case 'toggle':
                $stmt = $db->prepare('UPDATE items SET checked = ? WHERE id = ?');
                $stmt->execute([!empty($data['checked']) ? 1 : 0, require_existing_item_id($db, $data)]);
                json_response(['success' => true]);

            case 'edit':
                $stmt = $db->prepare('UPDATE items SET name = ? WHERE id = ?');
                $stmt->execute([require_name($data, 'Item name'), require_existing_item_id($db, $data)]);
                json_response(['success' => true]);

            case 'delete':
                $stmt = $db->prepare('DELETE FROM items WHERE id = ?');
                $stmt->execute([require_existing_item_id($db, $data)]);
                json_response(['success' => true]);

            case 'reorder':
                if (!isset($data['categories']) || !is_array($data['categories'])) {
                    if (!isset($data['items']) || !is_array($data['items'])) {
                        json_response(['success' => false, 'error' => 'Item order is required'], 422);
                    }

                    $db->beginTransaction();
                    $stmt = $db->prepare('UPDATE items SET position = ? WHERE id = ?');
                    foreach (array_values($data['items']) as $index => $id) {
                        $validId = filter_var($id, FILTER_VALIDATE_INT);
                        if ($validId) {
                            $stmt->execute([$index + 1, $validId]);
                        }
                    }
                    $db->commit();
                    json_response(['success' => true]);
                }

                $db->beginTransaction();
                $categoryStmt = $db->prepare('UPDATE categories SET position = ? WHERE id = ?');
                $itemStmt = $db->prepare('UPDATE items SET category_id = ?, position = ? WHERE id = ?');
                foreach (array_values($data['categories']) as $categoryIndex => $category) {
                    $categoryId = filter_var($category['id'] ?? null, FILTER_VALIDATE_INT);
                    if (!$categoryId) {
                        continue;
                    }
                    $categoryStmt->execute([$categoryIndex + 1, $categoryId]);
                    foreach (array_values($category['items'] ?? []) as $itemIndex => $itemId) {
                        $validItemId = filter_var($itemId, FILTER_VALIDATE_INT);
                        if ($validItemId) {
                            $itemStmt->execute([$categoryId, $itemIndex + 1, $validItemId]);
                        }
                    }
                }
                $db->commit();
                json_response(['success' => true]);

            case 'clear_checked':
                $db->exec('DELETE FROM items WHERE checked = 1');
                json_response(['success' => true]);

            case 'clear_all':
                $db->exec('DELETE FROM items');
                json_response(['success' => true]);

            default:
                json_response(['success' => false, 'error' => 'Unknown action'], 400);
        }
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        json_response(['success' => false, 'error' => 'Something went wrong'], 500);
    }
}

$categories = all_categories($db);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($listName) ?></title>
    <style>
        :root {
            color-scheme: light;
            --bg: #ffffff;
            --surface: #ffffff;
            --surface-raised: #f7f7f8;
            --text: #111111;
            --muted: #8a8a8f;
            --separator: #eeeeef;
            --accent: #19c463;
            --accent-pressed: #14ad55;
            --accent-soft: rgba(25, 196, 99, 0.12);
            --check-border: #d3d3d7;
            --danger: #ff3b30;
            --shadow: 0 10px 26px rgba(0, 0, 0, 0.08);
            --done: #19c463;
        }

        [data-theme="dark"] {
            color-scheme: dark;
            --bg: #000000;
            --surface: #000000;
            --surface-raised: #1c1c1e;
            --text: #f5f5f7;
            --muted: #8e8e93;
            --separator: #232326;
            --accent: #30d158;
            --accent-pressed: #28bd4d;
            --accent-soft: rgba(48, 209, 88, 0.18);
            --check-border: #3a3a3c;
            --danger: #ff453a;
            --shadow: 0 14px 38px rgba(0, 0, 0, 0.34);
            --done: #30d158;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Roboto, sans-serif;
            letter-spacing: 0;
        }

        button,
        input {
            font: inherit;
        }

        button {
            cursor: pointer;
        }

        .app {
            width: min(100%, 680px);
            margin: 0 auto;
            padding: max(18px, env(safe-area-inset-top)) 14px 40px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 14px;
        }

        h1 {
            margin: 0;
            color: var(--accent);
            font-size: 34px;
            line-height: 1.08;
            font-weight: 750;
        }

        .toolbar {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .icon-btn,
        .category-add,
        .category-delete,
        .item-action {
            display: inline-grid;
            place-items: center;
            width: 34px;
            height: 34px;
            border: 0;
            border-radius: 999px;
            background: transparent;
            color: var(--text);
        }

        .icon-btn:hover,
        .category-add:hover,
        .category-delete:hover,
        .item-action:hover {
            background: var(--surface-raised);
        }

        .category-add {
            color: var(--accent);
        }

        #addCategory {
            color: var(--accent);
        }

        .categories {
            display: grid;
            gap: 18px;
        }

        .category {
            background: transparent;
            border-radius: 10px;
            transition: opacity 0.16s, transform 0.16s;
        }

        .category.dragging,
        .item.dragging {
            opacity: 0.78;
            box-shadow: var(--shadow);
        }

        .category-placeholder,
        .item-placeholder {
            border-radius: 8px;
            background: var(--accent-soft);
            opacity: 0.72;
            pointer-events: none;
        }

        .category-header {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            min-height: 40px;
            gap: 8px;
            padding: 0 2px 4px;
            touch-action: none;
            cursor: grab;
        }

        .category-title {
            min-width: 0;
            color: var(--text);
            font-size: 18px;
            line-height: 1.25;
            font-weight: 800;
            overflow-wrap: anywhere;
            border-radius: 6px;
        }

        .category-title.editing,
        .item-text.editing {
            outline: 3px solid var(--accent-soft);
            background: var(--surface-raised);
        }

        .items-list {
            margin: 0;
            padding: 0;
            list-style: none;
            border-top: 1px solid var(--separator);
            background: var(--surface);
        }

        .item {
            display: grid;
            grid-template-columns: 28px minmax(0, 1fr) auto;
            align-items: center;
            min-height: 42px;
            gap: 8px;
            padding: 2px 0 2px 2px;
            border-bottom: 1px solid var(--separator);
            background: var(--surface);
            touch-action: none;
            cursor: grab;
            transition: opacity 0.15s, transform 0.15s;
        }

        .item-checkbox {
            display: grid;
            place-items: center;
            width: 22px;
            height: 22px;
            border: 1.5px solid var(--check-border);
            border-radius: 999px;
            background: transparent;
            color: #ffffff;
            padding: 0;
        }

        .item-checkbox.checked {
            border-color: var(--done);
            background: var(--done);
        }

        .item-checkbox svg {
            width: 16px;
            height: 16px;
            opacity: 0;
        }

        .item-checkbox.checked svg {
            opacity: 1;
        }

        .item-text {
            min-width: 0;
            padding: 7px 0;
            border-radius: 6px;
            color: var(--text);
            font-size: 16px;
            line-height: 1.3;
            overflow-wrap: anywhere;
        }

        .item.checked .item-text {
            color: var(--muted);
            text-decoration: line-through;
        }

        .item-action.delete,
        .category-delete {
            color: var(--danger);
        }

        .item-action.delete {
            display: none;
        }

        .item.editing .item-action.delete {
            display: inline-grid;
        }

        .draft-input {
            width: 100%;
            min-width: 0;
            height: 36px;
            border: 0;
            border-radius: 6px;
            padding: 0;
            background: transparent;
            color: var(--text);
            outline: 0;
        }

        .category .draft-input {
            font-size: 18px;
            font-weight: 800;
        }

        .empty-state {
            display: none;
            padding: 22px 2px;
            color: var(--muted);
            font-size: 15px;
        }

        .empty-state.visible {
            display: block;
        }

        .bulk-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-top: 22px;
        }

        .bulk-actions button {
            min-height: 42px;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: var(--accent);
            font-size: 13px;
        }

        .bulk-actions .danger {
            color: var(--danger);
        }

        .toast {
            position: fixed;
            right: 16px;
            bottom: 16px;
            max-width: min(340px, calc(100vw - 32px));
            padding: 12px 14px;
            border-radius: 10px;
            background: var(--surface-raised);
            color: var(--text);
            box-shadow: var(--shadow);
            opacity: 0;
            transform: translateY(8px);
            pointer-events: none;
            transition: opacity 0.2s, transform 0.2s;
        }

        .toast.visible {
            opacity: 1;
            transform: translateY(0);
        }

        svg {
            width: 18px;
            height: 18px;
        }

        @media (max-width: 540px) {
            .app {
                padding-inline: 12px;
            }

            h1 {
                font-size: 30px;
            }

            .bulk-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <main class="app">
        <header class="topbar">
            <h1><?= e($listName) ?></h1>
            <div class="toolbar">
                <button class="icon-btn" id="addCategory" type="button" aria-label="Add category" title="Add category">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M5 4h14v2H5V4Zm0 5h8v2H5V9Zm0 5h8v2H5v-2Zm12-1v4h4v2h-4v4h-2v-4h-4v-2h4v-4h2Z"/></svg>
                </button>
                <button class="icon-btn" id="themeToggle" type="button" aria-label="Toggle dark mode" title="Toggle dark mode">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M21.64 13a1 1 0 0 0-1.05-.14 8.05 8.05 0 0 1-3.37.73 8.15 8.15 0 0 1-8.14-8.1 8.59 8.59 0 0 1 .25-2A1 1 0 0 0 8 2.36 10.14 10.14 0 1 0 22 14.05 1 1 0 0 0 21.64 13Z"/></svg>
                </button>
            </div>
        </header>

        <div class="categories" id="categories">
            <?php foreach ($categories as $category): ?>
                <section class="category" data-id="<?= (int) $category['id'] ?>">
                    <div class="category-header">
                        <div class="category-title" contenteditable="false"><?= e($category['name']) ?></div>
                        <button class="category-add" type="button" aria-label="Add item" title="Add item">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z"/></svg>
                        </button>
                    </div>
                    <ul class="items-list">
                        <?php foreach ($category['items'] as $item): ?>
                            <li class="item <?= (int) $item['checked'] ? 'checked' : '' ?>" data-id="<?= (int) $item['id'] ?>">
                                <button class="item-checkbox <?= (int) $item['checked'] ? 'checked' : '' ?>" type="button" aria-label="Toggle item">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="m9.2 16.6-4.1-4.1 1.4-1.4 2.7 2.7 8.3-8.3 1.4 1.4-9.7 9.7Z"/></svg>
                                </button>
                                <span class="item-text" contenteditable="false"><?= e($item['name']) ?></span>
                                <button class="item-action delete" type="button" aria-label="Delete item" title="Delete item">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V7H6v12ZM8 4l1-1h6l1 1h4v2H4V4h4Z"/></svg>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endforeach; ?>
        </div>
        <div class="empty-state" id="emptyState">No items yet.</div>

        <div class="bulk-actions">
            <button type="button" id="clearChecked">Clear Checked</button>
            <button type="button" id="exportMarkdown">Copy Markdown</button>
            <button type="button" class="danger" id="clearAll">Clear All</button>
        </div>
    </main>
    <div class="toast" id="toast" role="status" aria-live="polite"></div>

    <script>
        const categoriesEl = document.getElementById('categories');
        const themeToggle = document.getElementById('themeToggle');
        const addCategoryButton = document.getElementById('addCategory');
        const emptyState = document.getElementById('emptyState');
        const toast = document.getElementById('toast');

        const icons = {
            check: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="m9.2 16.6-4.1-4.1 1.4-1.4 2.7 2.7 8.3-8.3 1.4 1.4-9.7 9.7Z"/></svg>',
            plus: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z"/></svg>',
            delete: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V7H6v12ZM8 4l1-1h6l1 1h4v2H4V4h4Z"/></svg>'
        };

        let toastTimeout;
        let dragState = null;
        let lastTap = { key: '', time: 0 };

        function showToast(message) {
            toast.textContent = message;
            toast.classList.add('visible');
            clearTimeout(toastTimeout);
            toastTimeout = setTimeout(() => toast.classList.remove('visible'), 2200);
        }

        async function api(action, payload = {}) {
            const response = await fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, ...payload })
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Request failed');
            }
            return data;
        }

        function escapeHtml(value) {
            const element = document.createElement('div');
            element.textContent = value;
            return element.innerHTML;
        }

        function applyTheme(theme) {
            document.documentElement.dataset.theme = theme;
            localStorage.setItem('theme', theme);
        }

        function createItemElement(item) {
            const checked = Number(item.checked) === 1;
            const element = document.createElement('li');
            element.className = 'item' + (checked ? ' checked' : '');
            element.dataset.id = item.id;
            element.innerHTML = `
                <button class="item-checkbox ${checked ? 'checked' : ''}" type="button" aria-label="Toggle item">${icons.check}</button>
                <span class="item-text" contenteditable="false">${escapeHtml(item.name)}</span>
                <button class="item-action delete" type="button" aria-label="Delete item" title="Delete item">${icons.delete}</button>
            `;
            return element;
        }

        function createCategoryElement(category) {
            const element = document.createElement('section');
            element.className = 'category';
            element.dataset.id = category.id;
            element.innerHTML = `
                <div class="category-header">
                    <div class="category-title" contenteditable="false">${escapeHtml(category.name)}</div>
                    <button class="category-add" type="button" aria-label="Add item" title="Add item">${icons.plus}</button>
                </div>
                <ul class="items-list"></ul>
            `;
            element.querySelector('.items-list').replaceChildren(...(category.items || []).map(createItemElement));
            return element;
        }

        function updateEmptyState() {
            emptyState.classList.toggle('visible', categoriesEl.querySelectorAll('.item').length === 0);
        }

        function currentOrder() {
            return [...categoriesEl.querySelectorAll('.category')].map(category => ({
                id: category.dataset.id,
                items: [...category.querySelectorAll('.item')].map(item => item.dataset.id)
            }));
        }

        function itemListForPointer(clientX, clientY) {
            const lists = [...categoriesEl.querySelectorAll('.items-list')];
            const direct = document.elementFromPoint(clientX, clientY)?.closest('.items-list');
            if (direct) {
                return direct;
            }

            return lists.reduce((best, list) => {
                const box = list.getBoundingClientRect();
                const yDistance = clientY < box.top ? box.top - clientY : clientY > box.bottom ? clientY - box.bottom : 0;
                return !best || yDistance < best.distance ? { list, distance: yDistance } : best;
            }, null)?.list || lists[0];
        }

        function afterElement(container, y, selector) {
            return [...container.querySelectorAll(selector)].reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                return offset < 0 && offset > closest.offset ? { offset, element: child } : closest;
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }

        function beginDrag(target, event, type) {
            const rect = target.getBoundingClientRect();
            const placeholder = document.createElement(type === 'category' ? 'section' : 'li');
            placeholder.className = type === 'category' ? 'category-placeholder' : 'item-placeholder';
            placeholder.style.height = `${rect.height}px`;
            target.after(placeholder);

            dragState = {
                type,
                target,
                placeholder,
                originParent: target.parentNode,
                originNext: placeholder.nextSibling,
                pointerId: event.pointerId,
                offsetX: event.clientX - rect.left,
                offsetY: event.clientY - rect.top
            };

            Object.assign(target.style, {
                position: 'fixed',
                width: `${rect.width}px`,
                left: `${rect.left}px`,
                top: `${rect.top}px`,
                zIndex: '1000',
                pointerEvents: 'none'
            });
            target.classList.add('dragging');
            target.setPointerCapture?.(event.pointerId);
            target.addEventListener('pointermove', moveDrag);
            target.addEventListener('pointerup', endDrag, { once: true });
            target.addEventListener('pointercancel', cancelDrag, { once: true });
        }

        function moveDrag(event) {
            if (!dragState || event.pointerId !== dragState.pointerId) {
                return;
            }

            event.preventDefault();
            dragState.target.style.left = `${event.clientX - dragState.offsetX}px`;
            dragState.target.style.top = `${event.clientY - dragState.offsetY}px`;

            if (dragState.type === 'category') {
                const after = afterElement(categoriesEl, event.clientY, '.category:not(.dragging)');
                after ? after.before(dragState.placeholder) : categoriesEl.appendChild(dragState.placeholder);
                return;
            }

            const list = itemListForPointer(event.clientX, event.clientY);
            const after = afterElement(list, event.clientY, '.item:not(.dragging)');
            after ? after.before(dragState.placeholder) : list.appendChild(dragState.placeholder);
        }

        function finishDrag(save) {
            if (!dragState) {
                return;
            }

            const { target, placeholder, originParent, originNext } = dragState;
            target.removeEventListener('pointermove', moveDrag);
            Object.assign(target.style, {
                position: '',
                width: '',
                left: '',
                top: '',
                zIndex: '',
                pointerEvents: ''
            });
            target.classList.remove('dragging');

            if (save) {
                placeholder.replaceWith(target);
                saveOrder();
            } else {
                placeholder.remove();
                originParent.insertBefore(target, originNext);
            }

            dragState = null;
            updateEmptyState();
        }

        function endDrag() {
            finishDrag(true);
        }

        function cancelDrag() {
            finishDrag(false);
        }

        function scheduleDrag(target, event, type) {
            if (dragState || event.button !== 0 || event.target.closest('button') || event.target.closest('[contenteditable="true"]') || event.target.closest('input')) {
                return;
            }

            const startX = event.clientX;
            const startY = event.clientY;
            const startEvent = event;

            const beginIfMoved = moveEvent => {
                if (Math.abs(moveEvent.clientX - startX) < 1 && Math.abs(moveEvent.clientY - startY) < 1) {
                    return;
                }

                moveEvent.preventDefault();
                cleanup();
                beginDrag(target, startEvent, type);
                moveDrag(moveEvent);
            };
            const cleanup = () => {
                window.removeEventListener('pointermove', beginIfMoved);
                window.removeEventListener('pointerup', cleanup);
                window.removeEventListener('pointercancel', cleanup);
            };

            window.addEventListener('pointermove', beginIfMoved);
            window.addEventListener('pointerup', cleanup);
            window.addEventListener('pointercancel', cleanup);
        }

        function isDoubleTap(key) {
            const now = Date.now();
            const matched = lastTap.key === key && now - lastTap.time < 320;
            lastTap = { key, time: now };
            return matched;
        }

        async function saveOrder() {
            try {
                await api('reorder', { categories: currentOrder() });
            } catch (error) {
                showToast(error.message);
                refreshList();
            }
        }

        function startEditing(element, action, id) {
            if (element.classList.contains('editing')) {
                return;
            }

            const original = element.textContent;
            const categoryAction = action === 'edit_category'
                ? element.closest('.category')?.querySelector('.category-add')
                : null;
            const itemRow = action === 'edit'
                ? element.closest('.item')
                : null;
            if (categoryAction) {
                categoryAction.classList.remove('category-add');
                categoryAction.classList.add('category-delete');
                categoryAction.innerHTML = icons.delete;
                categoryAction.setAttribute('aria-label', 'Delete category');
                categoryAction.setAttribute('title', 'Delete category');
            }
            itemRow?.classList.add('editing');
            element.contentEditable = 'true';
            element.classList.add('editing');
            element.focus();

            const range = document.createRange();
            range.selectNodeContents(element);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);

            const finish = async save => {
                element.removeEventListener('keydown', onKeydown);
                element.removeEventListener('blur', onBlur);
                element.contentEditable = 'false';
                element.classList.remove('editing');
                if (categoryAction?.isConnected) {
                    setTimeout(() => {
                        if (!categoryAction.classList.contains('category-delete')) {
                            return;
                        }
                        categoryAction.classList.remove('category-delete');
                        categoryAction.classList.add('category-add');
                        categoryAction.innerHTML = icons.plus;
                        categoryAction.setAttribute('aria-label', 'Add item');
                        categoryAction.setAttribute('title', 'Add item');
                    }, 0);
                }
                if (itemRow?.isConnected) {
                    setTimeout(() => itemRow.classList.remove('editing'), 0);
                }

                const next = element.textContent.trim();
                if (!save || next === '') {
                    element.textContent = original;
                    return;
                }
                if (next === original) {
                    return;
                }

                try {
                    await api(action, { id, name: next });
                } catch (error) {
                    element.textContent = original;
                    showToast(error.message);
                }
            };

            const onBlur = () => finish(true);
            const onKeydown = event => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    finish(true);
                }
                if (event.key === 'Escape') {
                    event.preventDefault();
                    finish(false);
                }
            };

            element.addEventListener('blur', onBlur);
            element.addEventListener('keydown', onKeydown);
        }

        function addDraftItem(category) {
            const list = category.querySelector('.items-list');
            const draft = document.createElement('li');
            draft.className = 'item';
            draft.innerHTML = `
                <button class="item-checkbox" type="button" aria-label="Toggle item">${icons.check}</button>
                <input class="draft-input" type="text" autocomplete="off" maxlength="160">
                <button class="item-action delete" type="button" aria-label="Delete item" title="Delete item">${icons.delete}</button>
            `;
            list.appendChild(draft);
            const input = draft.querySelector('input');
            input.focus();

            const finish = async save => {
                input.removeEventListener('keydown', onKeydown);
                input.removeEventListener('blur', onBlur);
                const name = input.value.trim();
                if (!save || name === '') {
                    draft.remove();
                    updateEmptyState();
                    return;
                }

                try {
                    const data = await api('add', { category_id: category.dataset.id, name });
                    draft.replaceWith(createItemElement(data.item));
                    updateEmptyState();
                } catch (error) {
                    draft.remove();
                    showToast(error.message);
                    updateEmptyState();
                }
            };

            const onBlur = () => finish(true);
            const onKeydown = event => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    finish(true);
                }
                if (event.key === 'Escape') {
                    event.preventDefault();
                    finish(false);
                }
            };

            input.addEventListener('blur', onBlur);
            input.addEventListener('keydown', onKeydown);
            updateEmptyState();
        }

        function addDraftCategory() {
            const draft = document.createElement('section');
            draft.className = 'category';
            draft.innerHTML = `
                <div class="category-header">
                    <input class="draft-input" type="text" autocomplete="off" maxlength="160" placeholder="New Category">
                    <button class="category-add" type="button" aria-label="Add item" title="Add item">${icons.plus}</button>
                </div>
                <ul class="items-list"></ul>
            `;
            categoriesEl.appendChild(draft);
            const input = draft.querySelector('input');
            input.focus();

            const finish = async save => {
                input.removeEventListener('keydown', onKeydown);
                input.removeEventListener('blur', onBlur);
                const name = input.value.trim();
                if (!save || name === '') {
                    draft.remove();
                    return;
                }

                try {
                    const data = await api('add_category', { name });
                    draft.replaceWith(createCategoryElement(data.category));
                    updateEmptyState();
                } catch (error) {
                    draft.remove();
                    showToast(error.message);
                }
            };

            const onBlur = () => finish(true);
            const onKeydown = event => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    finish(true);
                }
                if (event.key === 'Escape') {
                    event.preventDefault();
                    finish(false);
                }
            };

            input.addEventListener('blur', onBlur);
            input.addEventListener('keydown', onKeydown);
        }

        async function refreshList() {
            try {
                const data = await api('fetch');
                categoriesEl.replaceChildren(...data.categories.map(createCategoryElement));
                updateEmptyState();
            } catch (error) {
                showToast(error.message);
            }
        }

        applyTheme(localStorage.getItem('theme') || 'light');

        themeToggle.addEventListener('click', () => {
            applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
        });

        addCategoryButton.addEventListener('click', addDraftCategory);

        categoriesEl.addEventListener('pointerdown', event => {
            const item = event.target.closest('.item');
            if (item && !item.querySelector('.draft-input')) {
                scheduleDrag(item, event, 'item');
                return;
            }

            const header = event.target.closest('.category-header');
            const category = event.target.closest('.category');
            if (header && category && category.dataset.id) {
                scheduleDrag(category, event, 'category');
            }
        });

        categoriesEl.addEventListener('dblclick', event => {
            const itemText = event.target.closest('.item-text');
            if (itemText) {
                startEditing(itemText, 'edit', itemText.closest('.item').dataset.id);
                return;
            }

            const categoryTitle = event.target.closest('.category-title');
            if (categoryTitle) {
                startEditing(categoryTitle, 'edit_category', categoryTitle.closest('.category').dataset.id);
            }
        });

        categoriesEl.addEventListener('click', async event => {
            const itemText = event.target.closest('.item-text');
            if (itemText && isDoubleTap(`item-${itemText.closest('.item').dataset.id}`)) {
                startEditing(itemText, 'edit', itemText.closest('.item').dataset.id);
                return;
            }

            const categoryTitle = event.target.closest('.category-title');
            if (categoryTitle && isDoubleTap(`category-${categoryTitle.closest('.category').dataset.id}`)) {
                startEditing(categoryTitle, 'edit_category', categoryTitle.closest('.category').dataset.id);
                return;
            }

            const categoryDeleteButton = event.target.closest('.category-delete');
            if (categoryDeleteButton) {
                const category = categoryDeleteButton.closest('.category');
                if (!category?.dataset.id || !confirm('Delete this category and its items?')) {
                    return;
                }

                try {
                    await api('delete_category', { id: category.dataset.id });
                    category.remove();
                    updateEmptyState();
                } catch (error) {
                    showToast(error.message);
                }
                return;
            }

            const addButton = event.target.closest('.category-add');
            if (addButton) {
                const category = addButton.closest('.category');
                if (category?.dataset.id) {
                    addDraftItem(category);
                }
                return;
            }

            const checkbox = event.target.closest('.item-checkbox');
            if (checkbox) {
                const item = checkbox.closest('.item');
                if (!item?.dataset.id) {
                    return;
                }

                const checked = !item.classList.contains('checked');
                item.classList.toggle('checked', checked);
                checkbox.classList.toggle('checked', checked);
                checked ? item.parentNode.appendChild(item) : item.parentNode.prepend(item);

                try {
                    await api('toggle', { id: item.dataset.id, checked: checked ? 1 : 0 });
                    await saveOrder();
                } catch (error) {
                    item.classList.toggle('checked', !checked);
                    checkbox.classList.toggle('checked', !checked);
                    showToast(error.message);
                }
                return;
            }

            const deleteButton = event.target.closest('.delete');
            if (deleteButton) {
                const item = deleteButton.closest('.item');
                if (!item?.dataset.id) {
                    item?.remove();
                    updateEmptyState();
                    return;
                }

                try {
                    await api('delete', { id: item.dataset.id });
                    item.remove();
                    updateEmptyState();
                } catch (error) {
                    showToast(error.message);
                }
            }
        });

        document.getElementById('clearChecked').addEventListener('click', async () => {
            if (!confirm('Clear checked items?')) {
                return;
            }

            try {
                await api('clear_checked');
                categoriesEl.querySelectorAll('.item.checked').forEach(item => item.remove());
                updateEmptyState();
            } catch (error) {
                showToast(error.message);
            }
        });

        document.getElementById('clearAll').addEventListener('click', async () => {
            if (!confirm('Clear all items?')) {
                return;
            }

            try {
                await api('clear_all');
                categoriesEl.querySelectorAll('.item').forEach(item => item.remove());
                updateEmptyState();
            } catch (error) {
                showToast(error.message);
            }
        });

        document.getElementById('exportMarkdown').addEventListener('click', async () => {
            const lines = [];
            categoriesEl.querySelectorAll('.category').forEach(category => {
                const title = category.querySelector('.category-title')?.textContent.trim();
                if (title) {
                    lines.push(`## ${title}`);
                }
                category.querySelectorAll('.item').forEach(item => {
                    const checked = item.classList.contains('checked') ? 'x' : ' ';
                    const text = item.querySelector('.item-text')?.textContent.trim();
                    if (text) {
                        lines.push(`- [${checked}] ${text}`);
                    }
                });
                lines.push('');
            });

            try {
                await navigator.clipboard.writeText(lines.join('\n').trim() + (lines.length ? '\n' : ''));
                showToast('Markdown copied');
            } catch (error) {
                showToast('Clipboard is not available');
            }
        });

        let refreshTimeout;
        function queueRefresh() {
            clearTimeout(refreshTimeout);
            refreshTimeout = setTimeout(refreshList, 250);
        }

        window.addEventListener('focus', queueRefresh);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                queueRefresh();
            }
        });

        updateEmptyState();
    </script>
</body>
</html>
