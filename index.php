<?php
$db = new PDO('sqlite:' . __DIR__ . '/grocery.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');

$db->exec("CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    checked INTEGER NOT NULL DEFAULT 0,
    position INTEGER NOT NULL DEFAULT 0
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_items_sort ON items(checked, position, id)");

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

function all_items(PDO $db): array
{
    return $db
        ->query('SELECT id, name, checked, position FROM items ORDER BY checked ASC, position ASC, id ASC')
        ->fetchAll(PDO::FETCH_ASSOC);
}

function next_position(PDO $db): int
{
    return (int) $db->query('SELECT COALESCE(MAX(position), 0) + 1 FROM items')->fetchColumn();
}

function require_id(array $data): int
{
    $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        json_response(['success' => false, 'error' => 'Valid item id is required'], 422);
    }
    return $id;
}

function require_name(array $data): string
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        json_response(['success' => false, 'error' => 'Item name is required'], 422);
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
                json_response(['success' => true, 'items' => all_items($db)]);

            case 'add':
            case 'add_item':
                $stmt = $db->prepare('INSERT INTO items (name, position) VALUES (?, ?)');
                $stmt->execute([require_name($data), next_position($db)]);
                json_response([
                    'success' => true,
                    'item' => $db
                        ->query('SELECT id, name, checked, position FROM items WHERE id = ' . (int) $db->lastInsertId())
                        ->fetch(PDO::FETCH_ASSOC),
                ]);

            case 'toggle':
                $stmt = $db->prepare('UPDATE items SET checked = ? WHERE id = ?');
                $stmt->execute([!empty($data['checked']) ? 1 : 0, require_id($data)]);
                json_response(['success' => true]);

            case 'edit':
                $stmt = $db->prepare('UPDATE items SET name = ? WHERE id = ?');
                $stmt->execute([require_name($data), require_id($data)]);
                json_response(['success' => true]);

            case 'delete':
                $stmt = $db->prepare('DELETE FROM items WHERE id = ?');
                $stmt->execute([require_id($data)]);
                json_response(['success' => true]);

            case 'reorder':
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

$items = all_items($db);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grocery List</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f5f7f7;
            --surface: #ffffff;
            --surface-strong: #eef3f1;
            --text: #222222;
            --muted: #64716c;
            --border: #d7dfdc;
            --accent: #207c68;
            --accent-strong: #166552;
            --accent-focus: rgba(32, 124, 104, 0.22);
            --danger: #b83232;
            --danger-border: #c96f6f;
            --soft: rgba(255, 255, 255, 0.68);
            --shadow: 0 12px 32px rgba(20, 44, 38, 0.08);
        }

        [data-theme="dark"] {
            color-scheme: dark;
            --bg: #161616;
            --surface: #222222;
            --surface-strong: #2d2d2d;
            --text: #f1f1ee;
            --muted: #aaa59c;
            --border: #44413c;
            --accent: #45b897;
            --accent-strong: #69c8ad;
            --accent-focus: rgba(69, 184, 151, 0.25);
            --danger: #ff7878;
            --danger-border: #b35d5d;
            --soft: rgba(34, 34, 34, 0.68);
            --shadow: 0 12px 32px rgba(0, 0, 0, 0.24);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        button,
        input {
            font: inherit;
        }

        button {
            cursor: pointer;
        }

        .app {
            width: min(100%, 720px);
            margin: 0 auto;
            padding: 24px 16px 40px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
        }

        h1 {
            margin: 0;
            font-size: 30px;
            line-height: 1.1;
            font-weight: 750;
        }

        .count {
            margin-top: 4px;
            color: var(--muted);
            font-size: 14px;
        }

        .icon-btn,
        .item-action,
        .drag-handle {
            display: inline-grid;
            place-items: center;
            width: 42px;
            height: 42px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface);
            color: var(--text);
        }

        .icon-btn:hover,
        .item-action:hover,
        .drag-handle:hover {
            background: var(--surface-strong);
        }

        .add-form {
            position: sticky;
            top: 0;
            z-index: 10;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
            padding: 10px 0 14px;
            background: var(--bg);
        }

        .add-form input {
            min-width: 0;
            height: 48px;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0 14px;
            background: var(--surface);
            color: var(--text);
            font-size: 16px;
        }

        .add-form input:focus {
            outline: 3px solid var(--accent-focus);
            border-color: var(--accent);
        }

        .add-form button {
            height: 48px;
            min-width: 84px;
            border: 0;
            border-radius: 8px;
            background: var(--accent);
            color: #ffffff;
            font-weight: 700;
        }

        .add-form button:hover {
            background: var(--accent-strong);
        }

        .items-list {
            display: grid;
            gap: 8px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .item {
            display: grid;
            grid-template-columns: auto auto minmax(0, 1fr) auto auto;
            align-items: center;
            gap: 8px;
            min-height: 60px;
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface);
            box-shadow: var(--shadow);
            transition: opacity 0.15s, transform 0.15s, border-color 0.15s;
        }

        .item.dragging,
        .item.touch-dragging {
            opacity: 0.7;
            border-color: var(--accent);
        }

        .item.checked {
            opacity: 0.62;
        }

        .item-checkbox {
            width: 24px;
            height: 24px;
            accent-color: var(--accent);
            cursor: pointer;
        }

        .item-text {
            min-width: 0;
            overflow-wrap: anywhere;
            line-height: 1.35;
            font-size: 17px;
        }

        .item.checked .item-text {
            color: var(--muted);
            text-decoration: line-through;
        }

        .item-text.editing {
            padding: 7px 8px;
            border-radius: 6px;
            outline: 2px solid var(--accent);
            background: var(--surface-strong);
            text-decoration: none;
        }

        .drag-handle {
            color: var(--muted);
            touch-action: none;
            cursor: grab;
        }

        .drag-handle:active {
            cursor: grabbing;
        }

        .item-action {
            color: var(--muted);
        }

        .item-action.delete {
            color: var(--danger);
        }

        .bulk-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-top: 14px;
        }

        .bulk-actions button {
            min-height: 44px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface);
            color: var(--text);
        }

        .bulk-actions button:hover {
            background: var(--surface-strong);
        }

        .bulk-actions .danger {
            color: var(--danger);
            border-color: var(--danger-border);
        }

        .empty-state {
            display: none;
            padding: 30px 16px;
            border: 1px dashed var(--border);
            border-radius: 8px;
            color: var(--muted);
            text-align: center;
            background: var(--soft);
        }

        .empty-state.visible {
            display: block;
        }

        .toast {
            position: fixed;
            right: 16px;
            bottom: 16px;
            max-width: min(340px, calc(100vw - 32px));
            padding: 12px 14px;
            border-radius: 8px;
            background: var(--text);
            color: var(--bg);
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
                padding: 18px 10px 32px;
            }

            .item {
                grid-template-columns: auto auto minmax(0, 1fr) auto auto;
                gap: 6px;
            }

            .icon-btn,
            .item-action,
            .drag-handle {
                width: 40px;
                height: 40px;
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
            <div>
                <h1>Grocery List</h1>
                <div class="count" id="itemCount"></div>
            </div>
            <button class="icon-btn" id="themeToggle" type="button" aria-label="Toggle dark mode" title="Toggle dark mode">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M21.64 13a1 1 0 0 0-1.05-.14 8.05 8.05 0 0 1-3.37.73 8.15 8.15 0 0 1-8.14-8.1 8.59 8.59 0 0 1 .25-2A1 1 0 0 0 8 2.36 10.14 10.14 0 1 0 22 14.05 1 1 0 0 0 21.64 13Z"/></svg>
            </button>
        </header>

        <form class="add-form" id="addForm">
            <input type="text" id="newItem" name="name" placeholder="Add an item" autocomplete="off" maxlength="160">
            <button type="submit">Add</button>
        </form>

        <ul class="items-list" id="itemsList">
            <?php foreach ($items as $item): ?>
                <li class="item <?= (int) $item['checked'] ? 'checked' : '' ?>" data-id="<?= (int) $item['id'] ?>" draggable="true">
                    <button class="drag-handle" type="button" aria-label="Drag item" title="Drag item">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M9 5a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm10 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0ZM9 12a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm10 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0ZM9 19a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm10 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z"/></svg>
                    </button>
                    <input type="checkbox" class="item-checkbox" aria-label="Toggle item" <?= (int) $item['checked'] ? 'checked' : '' ?>>
                    <span class="item-text" contenteditable="false"><?= e($item['name']) ?></span>
                    <button class="item-action edit" type="button" aria-label="Edit item" title="Edit item">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M4 17.46V20h2.54L17.96 8.58l-2.54-2.54L4 17.46ZM19.71 6.83a1 1 0 0 0 0-1.41l-1.13-1.13a1 1 0 0 0-1.41 0l-.88.88 2.54 2.54.88-.88Z"/></svg>
                    </button>
                    <button class="item-action delete" type="button" aria-label="Delete item" title="Delete item">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V7H6v12ZM8 4l1-1h6l1 1h4v2H4V4h4Z"/></svg>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="empty-state">No groceries yet.</div>

        <div class="bulk-actions">
            <button type="button" id="clearChecked">Clear Checked</button>
            <button type="button" id="exportMarkdown">Copy Markdown</button>
            <button type="button" class="danger" id="clearAll">Clear All</button>
        </div>
    </main>
    <div class="toast" id="toast" role="status" aria-live="polite"></div>

    <script>
        const itemsList = document.getElementById('itemsList');
        const addForm = document.getElementById('addForm');
        const newItemInput = document.getElementById('newItem');
        const itemCount = document.getElementById('itemCount');
        const themeToggle = document.getElementById('themeToggle');
        const toast = document.getElementById('toast');

        const icons = {
            drag: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M9 5a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm10 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0ZM9 12a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm10 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0ZM9 19a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm10 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z"/></svg>',
            edit: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M4 17.46V20h2.54L17.96 8.58l-2.54-2.54L4 17.46ZM19.71 6.83a1 1 0 0 0 0-1.41l-1.13-1.13a1 1 0 0 0-1.41 0l-.88.88 2.54 2.54.88-.88Z"/></svg>',
            delete: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V7H6v12ZM8 4l1-1h6l1 1h4v2H4V4h4Z"/></svg>'
        };

        let toastTimeout;
        let draggedElement = null;
        let touchDragElement = null;
        let touchOffsetY = 0;
        let placeholder = null;

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

        function itemTemplate(item) {
            const checked = Number(item.checked) === 1;
            return `
                <button class="drag-handle" type="button" aria-label="Drag item" title="Drag item">${icons.drag}</button>
                <input type="checkbox" class="item-checkbox" aria-label="Toggle item" ${checked ? 'checked' : ''}>
                <span class="item-text" contenteditable="false">${escapeHtml(item.name)}</span>
                <button class="item-action edit" type="button" aria-label="Edit item" title="Edit item">${icons.edit}</button>
                <button class="item-action delete" type="button" aria-label="Delete item" title="Delete item">${icons.delete}</button>
            `;
        }

        function createItemElement(item) {
            const element = document.createElement('li');
            element.className = 'item' + (Number(item.checked) === 1 ? ' checked' : '');
            element.dataset.id = item.id;
            element.draggable = true;
            element.innerHTML = itemTemplate(item);
            setupDragAndDrop(element);
            return element;
        }

        function updateCount() {
            const items = [...itemsList.querySelectorAll('.item')];
            const open = items.filter(item => !item.classList.contains('checked')).length;
            const total = items.length;
            itemCount.textContent = total === 0
                ? '0 items'
                : `${open} to get, ${total} total`;
            document.querySelector('.empty-state').classList.toggle('visible', total === 0);
        }

        function currentOrder() {
            return [...itemsList.querySelectorAll('.item')].map(item => item.dataset.id);
        }

        async function refreshList() {
            try {
                const data = await api('fetch');
                itemsList.replaceChildren(...data.items.map(createItemElement));
                updateCount();
            } catch (error) {
                showToast(error.message);
            }
        }

        function applyTheme(theme) {
            document.documentElement.dataset.theme = theme;
            localStorage.setItem('theme', theme);
        }

        applyTheme(localStorage.getItem('theme') || 'light');

        themeToggle.addEventListener('click', () => {
            applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
        });

        addForm.addEventListener('submit', async event => {
            event.preventDefault();
            const name = newItemInput.value.trim();
            if (!name) {
                return;
            }

            newItemInput.value = '';
            try {
                const data = await api('add', { name });
                itemsList.appendChild(createItemElement(data.item));
                updateCount();
            } catch (error) {
                newItemInput.value = name;
                showToast(error.message);
            }
        });

        itemsList.addEventListener('change', async event => {
            if (!event.target.classList.contains('item-checkbox')) {
                return;
            }

            const item = event.target.closest('.item');
            const checked = event.target.checked;
            item.classList.toggle('checked', checked);
            checked ? itemsList.appendChild(item) : itemsList.prepend(item);
            updateCount();

            try {
                await api('toggle', { id: item.dataset.id, checked: checked ? 1 : 0 });
                await api('reorder', { items: currentOrder() });
            } catch (error) {
                event.target.checked = !checked;
                item.classList.toggle('checked', !checked);
                showToast(error.message);
            }
        });

        itemsList.addEventListener('click', async event => {
            const editButton = event.target.closest('.edit');
            const deleteButton = event.target.closest('.delete');

            if (editButton) {
                startEditing(editButton.closest('.item'));
                return;
            }

            if (deleteButton) {
                const item = deleteButton.closest('.item');
                try {
                    await api('delete', { id: item.dataset.id });
                    item.remove();
                    updateCount();
                } catch (error) {
                    showToast(error.message);
                }
            }
        });

        itemsList.addEventListener('dblclick', event => {
            const item = event.target.closest('.item');
            if (item && event.target.classList.contains('item-text')) {
                startEditing(item);
            }
        });

        function startEditing(item) {
            const text = item.querySelector('.item-text');
            if (text.classList.contains('editing')) {
                return;
            }

            const original = text.textContent;
            text.contentEditable = 'true';
            text.classList.add('editing');
            text.focus();

            const range = document.createRange();
            range.selectNodeContents(text);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);

            const finish = async save => {
                text.removeEventListener('keydown', onKeydown);
                text.removeEventListener('blur', onBlur);
                text.contentEditable = 'false';
                text.classList.remove('editing');

                const next = text.textContent.trim();
                if (!save || next === '') {
                    text.textContent = original;
                    return;
                }
                if (next === original) {
                    return;
                }

                try {
                    await api('edit', { id: item.dataset.id, name: next });
                } catch (error) {
                    text.textContent = original;
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

            text.addEventListener('blur', onBlur);
            text.addEventListener('keydown', onKeydown);
        }

        function setupDragAndDrop(element) {
            element.addEventListener('dragstart', event => {
                if (!event.target.closest('.drag-handle')) {
                    event.preventDefault();
                    return;
                }

                draggedElement = element;
                element.classList.add('dragging');
                event.dataTransfer.effectAllowed = 'move';
            });

            element.addEventListener('dragover', event => {
                event.preventDefault();
                const after = dragAfterElement(event.clientY, '.item:not(.dragging)');
                after ? after.before(draggedElement) : itemsList.appendChild(draggedElement);
            });

            element.addEventListener('dragend', () => {
                element.classList.remove('dragging');
                draggedElement = null;
                saveOrder();
            });

            const handle = element.querySelector('.drag-handle');
            handle.addEventListener('touchstart', startTouchDrag, { passive: false });
            handle.addEventListener('touchmove', moveTouchDrag, { passive: false });
            handle.addEventListener('touchend', endTouchDrag);
        }

        function dragAfterElement(y, selector) {
            return [...itemsList.querySelectorAll(selector)].reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                return offset < 0 && offset > closest.offset ? { offset, element: child } : closest;
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }

        function startTouchDrag(event) {
            event.preventDefault();
            touchDragElement = event.target.closest('.item');
            const touch = event.touches[0];
            const rect = touchDragElement.getBoundingClientRect();
            touchOffsetY = touch.clientY - rect.top;

            placeholder = document.createElement('li');
            placeholder.className = 'item';
            placeholder.style.height = `${rect.height}px`;
            placeholder.style.opacity = '0';
            touchDragElement.after(placeholder);

            Object.assign(touchDragElement.style, {
                position: 'fixed',
                width: `${rect.width}px`,
                left: `${rect.left}px`,
                top: `${rect.top}px`,
                zIndex: '1000'
            });
            touchDragElement.classList.add('touch-dragging');
        }

        function moveTouchDrag(event) {
            if (!touchDragElement) {
                return;
            }
            event.preventDefault();
            const touch = event.touches[0];
            touchDragElement.style.top = `${touch.clientY - touchOffsetY}px`;
            const after = dragAfterElement(touch.clientY, '.item:not(.touch-dragging)');
            after ? after.before(placeholder) : itemsList.appendChild(placeholder);
        }

        function endTouchDrag() {
            if (!touchDragElement) {
                return;
            }

            Object.assign(touchDragElement.style, {
                position: '',
                width: '',
                left: '',
                top: '',
                zIndex: ''
            });
            touchDragElement.classList.remove('touch-dragging');
            placeholder.replaceWith(touchDragElement);
            placeholder = null;
            touchDragElement = null;
            saveOrder();
        }

        async function saveOrder() {
            try {
                await api('reorder', { items: currentOrder() });
            } catch (error) {
                showToast(error.message);
            }
        }

        document.getElementById('clearChecked').addEventListener('click', async () => {
            if (!confirm('Clear checked items?')) {
                return;
            }

            try {
                await api('clear_checked');
                itemsList.querySelectorAll('.item.checked').forEach(item => item.remove());
                updateCount();
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
                itemsList.replaceChildren();
                updateCount();
            } catch (error) {
                showToast(error.message);
            }
        });

        document.getElementById('exportMarkdown').addEventListener('click', async () => {
            const lines = [...itemsList.querySelectorAll('.item')].map(item => {
                const checked = item.querySelector('.item-checkbox').checked ? 'x' : ' ';
                const text = item.querySelector('.item-text').textContent.trim();
                return `- [${checked}] ${text}`;
            });

            try {
                await navigator.clipboard.writeText(lines.join('\n') + (lines.length ? '\n' : ''));
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

        document.querySelectorAll('.item').forEach(setupDragAndDrop);
        updateCount();
    </script>
</body>
</html>
