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

function require_existing_id(PDO $db, array $data): int
{
    $id = require_id($data);
    $stmt = $db->prepare('SELECT COUNT(*) FROM items WHERE id = ?');
    $stmt->execute([$id]);

    if ((int) $stmt->fetchColumn() === 0) {
        json_response(['success' => false, 'error' => 'Item not found'], 404);
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
                $stmt->execute([!empty($data['checked']) ? 1 : 0, require_existing_id($db, $data)]);
                json_response(['success' => true]);

            case 'edit':
                $stmt = $db->prepare('UPDATE items SET name = ? WHERE id = ?');
                $stmt->execute([require_name($data), require_existing_id($db, $data)]);
                json_response(['success' => true]);

            case 'delete':
                $stmt = $db->prepare('DELETE FROM items WHERE id = ?');
                $stmt->execute([require_existing_id($db, $data)]);
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
    <title><?= e($listName) ?></title>
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

        .icon-btn,
        .item-action {
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
        .item-action:hover {
            background: var(--surface-strong);
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
            grid-template-columns: auto minmax(0, 1fr) auto;
            align-items: center;
            gap: 8px;
            min-height: 60px;
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface);
            box-shadow: var(--shadow);
            transition: opacity 0.15s, transform 0.15s, border-color 0.15s;
            cursor: grab;
            touch-action: none;
        }

        .item.dragging,
        .item.touch-dragging {
            opacity: 0.7;
            border-color: var(--accent);
            cursor: grabbing;
        }

        .item.checked {
            opacity: 0.62;
        }

        .item.reorder-placeholder {
            box-shadow: none;
            opacity: 0.35;
            pointer-events: none;
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

        .item.editing {
            cursor: default;
            touch-action: auto;
        }

        .item.checked .item-text.editing {
            text-decoration: none;
        }

        .item-action {
            color: var(--muted);
        }

        .item-action.delete {
            display: none;
            color: var(--danger);
        }

        .item.editing .item-action.delete {
            display: inline-grid;
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
                grid-template-columns: auto minmax(0, 1fr) auto;
                gap: 6px;
            }

            .icon-btn,
            .item-action {
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
                <h1><?= e($listName) ?></h1>
            </div>
            <button class="icon-btn" id="addItem" type="button" aria-label="Add item" title="Add item">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M19 11h-6V5h-2v6H5v2h6v6h2v-6h6v-2Z"/></svg>
            </button>
        </header>

        <ul class="items-list" id="itemsList">
            <?php foreach ($items as $item): ?>
                <li class="item <?= (int) $item['checked'] ? 'checked' : '' ?>" data-id="<?= (int) $item['id'] ?>">
                    <input type="checkbox" class="item-checkbox" aria-label="Toggle item" <?= (int) $item['checked'] ? 'checked' : '' ?>>
                    <span class="item-text" contenteditable="false"><?= e($item['name']) ?></span>
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
        const addItemButton = document.getElementById('addItem');
        const toast = document.getElementById('toast');

        const icons = {
            delete: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V7H6v12ZM8 4l1-1h6l1 1h4v2H4V4h4Z"/></svg>'
        };

        let toastTimeout;
        let dragState = null;
        let pendingDrag = null;

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
            const disabled = item.id ? '' : ' disabled';
            return `
                <input type="checkbox" class="item-checkbox" aria-label="Toggle item" ${checked ? 'checked' : ''}${disabled}>
                <span class="item-text" contenteditable="false">${escapeHtml(item.name)}</span>
                <button class="item-action delete" type="button" aria-label="Delete item" title="Delete item">${icons.delete}</button>
            `;
        }

        function createItemElement(item) {
            const element = document.createElement('li');
            element.className = 'item' + (Number(item.checked) === 1 ? ' checked' : '');
            if (item.id) {
                element.dataset.id = item.id;
            }
            element.innerHTML = itemTemplate(item);
            setupDragAndDrop(element);
            return element;
        }

        function updateCount() {
            const items = [...itemsList.querySelectorAll('.item')];
            const total = items.length;
            document.querySelector('.empty-state').classList.toggle('visible', total === 0);
        }

        function currentOrder() {
            return [...itemsList.querySelectorAll('.item')].map(item => item.dataset.id);
        }

        function appendToUncheckedItems(item) {
            const firstCheckedItem = itemsList.querySelector('.item.checked');
            firstCheckedItem ? firstCheckedItem.before(item) : itemsList.appendChild(item);
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

        addItemButton.addEventListener('click', () => {
            const pendingItem = itemsList.querySelector('.item.new-item');
            if (pendingItem) {
                startEditing(pendingItem);
                return;
            }

            const item = createItemElement({ id: null, name: '', checked: 0 });
            item.classList.add('new-item');
            appendToUncheckedItems(item);
            updateCount();
            startEditing(item);
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
            const deleteButton = event.target.closest('.delete');

            if (deleteButton) {
                const item = deleteButton.closest('.item');
                if (!item.dataset.id) {
                    item.remove();
                    updateCount();
                    return;
                }

                try {
                    await api('delete', { id: item.dataset.id });
                    item.remove();
                    updateCount();
                } catch (error) {
                    showToast(error.message);
                }
            }
        });

        itemsList.addEventListener('pointerdown', event => {
            if (event.target.closest('.delete')) {
                event.preventDefault();
            }
        });

        itemsList.addEventListener('dblclick', event => {
            const item = event.target.closest('.item');
            if (item && !event.target.closest('button, input')) {
                startEditing(item);
            }
        });

        function startEditing(item) {
            const text = item.querySelector('.item-text');
            if (text.classList.contains('editing')) {
                text.focus();
                return;
            }

            const isNew = !item.dataset.id;
            const original = text.textContent;
            text.contentEditable = 'true';
            text.classList.add('editing');
            item.classList.add('editing');
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
                item.classList.remove('editing');

                const next = text.textContent.trim();
                if (!save || next === '') {
                    if (isNew) {
                        item.remove();
                        updateCount();
                    } else {
                        text.textContent = original;
                    }
                    return;
                }
                if (!isNew && next === original) {
                    return;
                }

                try {
                    if (isNew) {
                        const data = await api('add', { name: next });
                        item.dataset.id = data.item.id;
                        item.classList.remove('new-item');
                        item.querySelector('.item-checkbox').disabled = false;
                        text.textContent = data.item.name;
                        await api('reorder', { items: currentOrder() });
                    } else {
                        await api('edit', { id: item.dataset.id, name: next });
                    }
                } catch (error) {
                    if (isNew) {
                        item.classList.add('new-item');
                        text.textContent = next;
                        setTimeout(() => startEditing(item), 0);
                    } else {
                        text.textContent = original;
                    }
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
            element.draggable = false;
            element.addEventListener('pointerdown', startPointerDrag);
        }

        function dragAfterElement(y, selector) {
            return [...itemsList.querySelectorAll(selector)].reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                return offset < 0 && offset > closest.offset ? { offset, element: child } : closest;
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }

        function captureItemPositions() {
            const positions = new Map();
            itemsList.querySelectorAll('.item:not(.touch-dragging)').forEach(item => {
                positions.set(item, item.getBoundingClientRect());
            });
            return positions;
        }

        function animateMovedItems(previousPositions) {
            itemsList.querySelectorAll('.item:not(.touch-dragging)').forEach(item => {
                const previous = previousPositions.get(item);
                if (!previous) {
                    return;
                }

                const next = item.getBoundingClientRect();
                const deltaY = previous.top - next.top;
                if (Math.abs(deltaY) < 1) {
                    return;
                }

                item.animate([
                    { transform: `translateY(${deltaY}px)` },
                    { transform: 'translateY(0)' }
                ], {
                    duration: 150,
                    easing: 'ease-out'
                });
            });
        }

        function movePlaceholder(y) {
            const previousPositions = captureItemPositions();
            const after = dragAfterElement(y, '.item:not(.touch-dragging):not(.reorder-placeholder)');
            after ? after.before(dragState.placeholder) : itemsList.appendChild(dragState.placeholder);
            animateMovedItems(previousPositions);
        }

        function startPointerDrag(event) {
            if (dragState || pendingDrag || event.button !== 0) {
                return;
            }

            const item = event.target.closest('.item');
            if (!item || item.classList.contains('editing') || event.target.closest('button, input')) {
                return;
            }

            pendingDrag = {
                item,
                pointerId: event.pointerId,
                startX: event.clientX,
                startY: event.clientY
            };

            item.setPointerCapture(event.pointerId);
            item.addEventListener('pointermove', movePointerDrag);
            item.addEventListener('pointerup', endPointerDrag, { once: true });
            item.addEventListener('pointercancel', cancelPointerDrag, { once: true });
        }

        function beginPointerDrag(event) {
            const item = pendingDrag.item;

            const rect = item.getBoundingClientRect();
            const originalNextSibling = item.nextElementSibling;
            const placeholder = item.cloneNode(false);
            placeholder.className = item.className.replace(/\btouch-dragging\b/g, '').trim() + ' reorder-placeholder';
            placeholder.style.height = `${rect.height}px`;
            item.after(placeholder);

            dragState = {
                item,
                placeholder,
                originalNextSibling,
                pointerId: event.pointerId,
                offsetX: event.clientX - rect.left,
                offsetY: event.clientY - rect.top
            };

            Object.assign(item.style, {
                position: 'fixed',
                width: `${rect.width}px`,
                left: `${rect.left}px`,
                top: `${rect.top}px`,
                zIndex: '1000',
                pointerEvents: 'none'
            });
            item.classList.add('touch-dragging');
            pendingDrag = null;
        }

        function movePointerDrag(event) {
            if (pendingDrag && event.pointerId === pendingDrag.pointerId) {
                const deltaX = event.clientX - pendingDrag.startX;
                const deltaY = event.clientY - pendingDrag.startY;
                if (Math.hypot(deltaX, deltaY) < 6) {
                    return;
                }
                event.preventDefault();
                beginPointerDrag(event);
            }

            if (!dragState || event.pointerId !== dragState.pointerId) {
                return;
            }

            event.preventDefault();
            dragState.item.style.left = `${event.clientX - dragState.offsetX}px`;
            dragState.item.style.top = `${event.clientY - dragState.offsetY}px`;
            movePlaceholder(event.clientY);
        }

        function finishPointerDrag(save) {
            pendingDrag = null;

            if (!dragState) {
                return;
            }

            const { item, placeholder, originalNextSibling } = dragState;
            Object.assign(item.style, {
                position: '',
                width: '',
                left: '',
                top: '',
                zIndex: '',
                pointerEvents: ''
            });
            item.classList.remove('touch-dragging');
            if (save) {
                placeholder.replaceWith(item);
            } else {
                placeholder.remove();
                if (originalNextSibling && originalNextSibling.parentNode === itemsList) {
                    itemsList.insertBefore(item, originalNextSibling);
                } else {
                    itemsList.appendChild(item);
                }
            }
            dragState = null;
            updateCount();

            if (save) {
                saveOrder();
            }
        }

        function endPointerDrag(event) {
            event.currentTarget.removeEventListener('pointermove', movePointerDrag);
            finishPointerDrag(true);
        }

        function cancelPointerDrag(event) {
            event.currentTarget.removeEventListener('pointermove', movePointerDrag);
            finishPointerDrag(false);
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
