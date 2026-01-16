<?php
session_start();

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Database setup
$dbFile = 'grocery.db';
$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create table if it doesn't exist
$db->exec("CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    checked INTEGER DEFAULT 0,
    position INTEGER DEFAULT 0
)");

// Create index for faster sorting
$db->exec("CREATE INDEX IF NOT EXISTS idx_checked_position ON items(checked, position)");

// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);

    // CSRF validation (skip for external API endpoints)
    if (isset($data['action']) && $data['action'] !== 'add_item') {
        if (!isset($data['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $data['csrf_token'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
    }

    if (isset($data['action'])) {
        switch ($data['action']) {
            case 'add':
                $name = trim($data['name'] ?? '');
                if (empty($name)) {
                    echo json_encode(['success' => false, 'error' => 'Item name is required']);
                    break;
                }
                $stmt = $db->prepare("INSERT INTO items (name, position) VALUES (?, (SELECT COALESCE(MAX(position), 0) + 1 FROM items))");
                $stmt->execute([$name]);
                echo json_encode(['id' => $db->lastInsertId()]);
                break;

            case 'add_item':
                // API endpoint for external apps (like Android)
                try {
                    if (!isset($data['name']) || empty(trim($data['name']))) {
                        echo json_encode(['success' => false, 'error' => 'Item name is required']);
                        break;
                    }
                    
                    $stmt = $db->prepare("INSERT INTO items (name, position) VALUES (?, (SELECT COALESCE(MAX(position), 0) + 1 FROM items))");
                    $result = $stmt->execute([trim($data['name'])]);
                    
                    echo json_encode(['success' => $result]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'toggle':
                $stmt = $db->prepare("UPDATE items SET checked = ? WHERE id = ?");
                $stmt->execute([$data['checked'], $data['id']]);
                echo json_encode(['success' => true]);
                break;

            case 'edit':
                $name = trim($data['name'] ?? '');
                if (empty($name)) {
                    echo json_encode(['success' => false, 'error' => 'Item name is required']);
                    break;
                }
                $stmt = $db->prepare("UPDATE items SET name = ? WHERE id = ?");
                $stmt->execute([$name, $data['id']]);
                echo json_encode(['success' => true]);
                break;

            case 'delete':
                $stmt = $db->prepare("DELETE FROM items WHERE id = ?");
                $stmt->execute([$data['id']]);
                echo json_encode(['success' => true]);
                break;

            case 'reorder':
                try {
                    $db->beginTransaction();
                    foreach ($data['items'] as $index => $id) {
                        $stmt = $db->prepare("UPDATE items SET position = ? WHERE id = ?");
                        $stmt->execute([$index, $id]);
                    }
                    $db->commit();
                    echo json_encode(['success' => true]);
                } catch (Exception $e) {
                    $db->rollBack();
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;

            case 'fetch':
                $items = $db->query("SELECT * FROM items ORDER BY checked ASC, position ASC")->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['items' => $items]);
                break;

            case 'clear_checked':
                $db->exec("DELETE FROM items WHERE checked = 1");
                echo json_encode(['success' => true]);
                break;

            case 'clear_all':
                $db->exec("DELETE FROM items");
                echo json_encode(['success' => true]);
                break;
        }
    }
    exit;
}

// Fetch items
$items = $db->query("SELECT * FROM items ORDER BY checked ASC, position ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grocery List</title>
    <style>
        :root {
            --bg-color: #ffffff;
            --text-color: #333333;
            --border-color: #e0e0e0;
            --hover-bg: #f5f5f5;
            --checked-color: #999999;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        [data-theme="dark"] {
            --bg-color: #1a1a1a;
            --text-color: #e0e0e0;
            --border-color: #333333;
            --hover-bg: #2a2a2a;
            --checked-color: #666666;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: background-color 0.3s, color 0.3s;
            min-height: 100vh;
            padding: 20px;
            touch-action: pan-y;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        h1 {
            font-size: 28px;
            font-weight: 600;
        }

        .theme-toggle {
            background: none;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 8px 12px;
            cursor: pointer;
            color: var(--text-color);
            font-size: 20px;
            transition: background-color 0.2s;
        }

        .theme-toggle:hover {
            background-color: var(--hover-bg);
        }

        .add-form {
            margin-bottom: 20px;
        }

        .add-form input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 16px;
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: border-color 0.2s;
        }

        .add-form input:focus {
            outline: none;
            border-color: #4a90e2;
        }

        .items-list {
            list-style: none;
        }

        .item {
            display: flex;
            align-items: center;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 8px;
            background-color: var(--bg-color);
            transition: all 0.2s;
            position: relative;
            touch-action: none;
        }

        .item:hover {
            background-color: var(--hover-bg);
            box-shadow: var(--shadow);
        }

        .item.checked {
            opacity: 0.6;
        }

        .item.checked .item-text {
            text-decoration: line-through;
            color: var(--checked-color);
        }

        .drag-handle {
            cursor: grab;
            margin-right: 12px;
            color: var(--checked-color);
            font-size: 18px;
            touch-action: none;
            display: flex;
            align-items: center;
        }

        .drag-handle:active {
            cursor: grabbing;
        }

        .item-checkbox {
            width: 20px;
            height: 20px;
            margin-right: 12px;
            cursor: pointer;
        }

        .item-text {
            flex: 1;
            font-size: 16px;
        }

        .delete-btn {
            opacity: 0;
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            font-size: 18px;
            color: #e74c3c;
            transition: opacity 0.2s;
        }

        .item:hover .delete-btn {
            opacity: 1;
        }

        .delete-btn:hover {
            color: #c0392b;
        }

        .edit-btn {
            opacity: 0;
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            font-size: 18px;
            color: #f39c12;
            transition: opacity 0.2s;
            margin-right: 4px;
        }

        .item:hover .edit-btn {
            opacity: 1;
        }

        .edit-btn:hover {
            color: #e67e22;
        }

        .item-text.editing {
            outline: 2px solid #f39c12;
            padding: 4px 8px;
            border-radius: 4px;
            background-color: var(--hover-bg);
        }


        @media (max-width: 768px) {
            .delete-btn {
                opacity: 1;
            }

            .edit-btn {
                opacity: 1;
            }
        }

        .clear-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .clear-btn {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: all 0.2s;
        }

        .clear-btn:hover {
            background-color: var(--hover-bg);
            box-shadow: var(--shadow);
        }

        .clear-btn.danger {
            color: #e74c3c;
            border-color: #e74c3c;
        }

        .clear-btn.danger:hover {
            background-color: #e74c3c;
            color: white;
        }
    </style>
</head>

<body>
    <div class="container">
        <header>
            <h1>Grocery List</h1>
            <button class="theme-toggle">🌙</button>
        </header>

        <form class="add-form" id="addForm">
            <input type="text" id="newItem" placeholder="Add new item..." autocomplete="off">
        </form>

        <ul class="items-list" id="itemsList">
            <?php foreach ($items as $item): ?>
                <li class="item <?= $item['checked'] ? 'checked' : '' ?>" data-id="<?= $item['id'] ?>" draggable="true">
                    <span class="drag-handle">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor"
                            class="bi bi-grip-vertical" viewBox="0 0 16 16">
                            <path
                                d="M7 2a1 1 0 1 1-2 0 1 1 0 0 1 2 0m3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0M7 5a1 1 0 1 1-2 0 1 1 0 0 1 2 0m3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0M7 8a1 1 0 1 1-2 0 1 1 0 0 1 2 0m3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0m-3 3a1 1 0 1 1-2 0 1 1 0 0 1 2 0m3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0m-3 3a1 1 0 1 1-2 0 1 1 0 0 1 2 0m3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0" />
                        </svg>
                    </span>
                    <input type="checkbox" class="item-checkbox" <?= $item['checked'] ? 'checked' : '' ?>>
                    <span class="item-text" contenteditable="false"><?= htmlspecialchars($item['name']) ?></span>
                    <button class="edit-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor"
                            class="bi bi-pencil" viewBox="0 0 16 16">
                            <path
                                d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10a.5.5 0 0 1-.168.11l-5 2a.5.5 0 0 1-.65-.65l2-5a.5.5 0 0 1 .11-.168l10-10zM11.207 2.5 13.5 4.793 14.793 3.5 12.5 1.207zm1.586 3L10.5 3.207 4 9.707V10h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.293zm-9.761 5.175-.106.106-1.528 3.821 3.821-1.528.106-.106A.5.5 0 0 1 5 12.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.468-.325z" />
                        </svg>
                    </button>
                    <button class="delete-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor"
                            class="bi bi-trash" viewBox="0 0 16 16">
                            <path
                                d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z" />
                            <path
                                d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM2.5 3h11V2h-11z" />
                        </svg>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="clear-buttons">
            <button class="clear-btn" id="clearChecked">Clear Checked</button>
            <button class="clear-btn danger" id="clearAll">Clear All</button>
        </div>
    </div>

    <script>
        // CSRF token for API requests
        const csrfToken = '<?= htmlspecialchars($csrfToken) ?>';

        // Cache DOM elements
        const itemsList = document.getElementById('itemsList');
        const newItemInput = document.getElementById('newItem');
        const themeToggle = document.querySelector('.theme-toggle');
        const addForm = document.getElementById('addForm');

        // Create item HTML template (reusable function)
        function createItemHTML(item) {
            return `
                <span class="drag-handle">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-grip-vertical" viewBox="0 0 16 16">
                        <path d="M7 2a1 1 0 1 1-2 0 1 1 0 0 1 2 0m3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0M7 5a1 1 0 1 1-2 0 1 1 0 0 1 2 0m3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0M7 8a1 1 0 1 1-2 0 1 1 0 0 1 2 0m3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0m-3 3a1 1 0 1 1-2 0 1 1 0 0 1 2 0m3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0m-3 3a1 1 0 1 1-2 0 1 1 0 0 1 2 0m3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0"/>
                    </svg>
                </span>
                <input type="checkbox" class="item-checkbox" ${item.checked ? 'checked' : ''}>
                <span class="item-text" contenteditable="false">${escapeHtml(item.name)}</span>
                <button class="edit-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-pencil" viewBox="0 0 16 16">
                        <path d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10a.5.5 0 0 1-.168.11l-5 2a.5.5 0 0 1-.65-.65l2-5a.5.5 0 0 1 .11-.168l10-10zM11.207 2.5 13.5 4.793 14.793 3.5 12.5 1.207zm1.586 3L10.5 3.207 4 9.707V10h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.293zm-9.761 5.175-.106.106-1.528 3.821 3.821-1.528.106-.106A.5.5 0 0 1 5 12.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.468-.325z"/>
                    </svg>
                </button>
                <button class="delete-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-trash" viewBox="0 0 16 16">
                        <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/>
                        <path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM2.5 3h11V2h-11z"/>
                    </svg>
                </button>
            `;
        }

        function createItemElement(item) {
            const li = document.createElement('li');
            li.className = 'item' + (item.checked ? ' checked' : '');
            li.setAttribute('data-id', item.id);
            li.setAttribute('draggable', 'true');
            li.innerHTML = createItemHTML(item);
            setupDragAndDrop(li);
            return li;
        }

        // Theme toggle
        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            themeToggle.textContent = newTheme === 'dark' ? '☀️' : '🌙';
        }

        // Load saved theme
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
        themeToggle.textContent = savedTheme === 'dark' ? '☀️' : '🌙';

        // Event delegation for theme toggle
        themeToggle.addEventListener('click', toggleTheme);

        // Refresh list from database
        async function refreshList() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'fetch', csrf_token: csrfToken })
                });

                if (!response.ok) throw new Error('Network error');
                const data = await response.json();
                if (data.error) throw new Error(data.error);
                
                itemsList.innerHTML = '';
                data.items.forEach(item => {
                    itemsList.appendChild(createItemElement(item));
                });
            } catch (error) {
                console.error('Failed to refresh list:', error);
            }
        }

        // Debounced refresh
        let refreshTimeout;
        function debounceRefresh() {
            clearTimeout(refreshTimeout);
            refreshTimeout = setTimeout(refreshList, 300);
        }

        // Listen for focus/blur events
        window.addEventListener('focus', debounceRefresh);

        // Listen for visibility change (for mobile devices switching tabs)
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) debounceRefresh();
        });

        // Event delegation for add form
        addForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const name = newItemInput.value.trim();

            if (!name) return;

            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'add', name, csrf_token: csrfToken })
                });

                if (!response.ok) throw new Error('Network error');
                const data = await response.json();
                if (data.error) throw new Error(data.error);

                itemsList.appendChild(createItemElement({ id: data.id, name, checked: 0 }));
                newItemInput.value = '';
            } catch (error) {
                console.error('Failed to add item:', error);
                alert('Failed to add item. Please try again.');
            }
        });

        // Event delegation for checkboxes and delete buttons
        itemsList.addEventListener('change', async (e) => {
            if (e.target.classList.contains('item-checkbox')) {
                const item = e.target.closest('.item');
                const id = parseInt(item.dataset.id);
                const checked = e.target.checked;

                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'toggle', id, checked: checked ? 1 : 0, csrf_token: csrfToken })
                    });

                    if (!response.ok) throw new Error('Network error');

                    if (checked) {
                        item.classList.add('checked');
                        itemsList.appendChild(item);
                    } else {
                        item.classList.remove('checked');
                        const firstChecked = document.querySelector('.item.checked');
                        if (firstChecked) {
                            firstChecked.before(item);
                        }
                    }
                } catch (error) {
                    console.error('Failed to toggle item:', error);
                    e.target.checked = !checked; // Revert checkbox state
                }
            }
        });

        itemsList.addEventListener('click', async (e) => {
            // Handle edit button
            if (e.target.closest('.edit-btn')) {
                const item = e.target.closest('.item');
                const textSpan = item.querySelector('.item-text');
                const originalText = textSpan.textContent;

                // Enable editing
                textSpan.setAttribute('contenteditable', 'true');
                textSpan.classList.add('editing');
                textSpan.focus();

                // Select all text
                const range = document.createRange();
                range.selectNodeContents(textSpan);
                const selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);

                // Handle Enter key (save)
                const handleKeydown = (event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        textSpan.blur();
                    } else if (event.key === 'Escape') {
                        textSpan.textContent = originalText;
                        textSpan.blur();
                    }
                };

                // Handle blur (save)
                const handleBlur = async () => {
                    textSpan.removeEventListener('keydown', handleKeydown);
                    const newText = textSpan.textContent.trim();

                    if (newText && newText !== originalText) {
                        const id = parseInt(item.dataset.id);
                        try {
                            const response = await fetch('', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ action: 'edit', id, name: newText, csrf_token: csrfToken })
                            });
                            if (!response.ok) throw new Error('Network error');
                        } catch (error) {
                            console.error('Failed to edit item:', error);
                            textSpan.textContent = originalText;
                        }
                    } else if (!newText) {
                        textSpan.textContent = originalText;
                    }

                    textSpan.setAttribute('contenteditable', 'false');
                    textSpan.classList.remove('editing');
                };

                textSpan.addEventListener('blur', handleBlur, { once: true });
                textSpan.addEventListener('keydown', handleKeydown);

                return;
            }

            // Handle delete button
            if (e.target.closest('.delete-btn')) {
                const item = e.target.closest('.item');
                const id = parseInt(item.dataset.id);

                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete', id, csrf_token: csrfToken })
                    });
                    if (!response.ok) throw new Error('Network error');
                    item.remove();
                } catch (error) {
                    console.error('Failed to delete item:', error);
                }
            }
        });

        // Drag and drop
        let draggedElement = null;
        let touchDragElement = null;
        let touchStartY = 0;
        let touchOffsetY = 0;
        let placeholder = null;

        function setupDragAndDrop(element) {
            // Desktop drag and drop
            element.addEventListener('dragstart', handleDragStart);
            element.addEventListener('dragover', handleDragOver);
            element.addEventListener('drop', handleDrop);
            element.addEventListener('dragend', handleDragEnd);

            // Touch events for mobile
            const handle = element.querySelector('.drag-handle');
            handle.addEventListener('touchstart', handleTouchStart, { passive: false });
            handle.addEventListener('touchmove', handleTouchMove, { passive: false });
            handle.addEventListener('touchend', handleTouchEnd);
        }

        // Desktop drag handlers
        function handleDragStart(e) {
            draggedElement = this;
            this.classList.add('dragging');
        }

        function handleDragOver(e) {
            e.preventDefault();
            const afterElement = getDragAfterElement(e.clientY);
            if (afterElement == null) {
                itemsList.appendChild(draggedElement);
            } else {
                afterElement.before(draggedElement);
            }
        }

        function handleDrop(e) {
            e.preventDefault();
        }

        function handleDragEnd() {
            this.classList.remove('dragging');
            updateOrder();
        }

        // Touch handlers for mobile
        function handleTouchStart(e) {
            e.preventDefault();
            touchDragElement = e.target.closest('.item');
            const touch = e.touches[0];
            const rect = touchDragElement.getBoundingClientRect();

            touchStartY = touch.clientY;
            touchOffsetY = touch.clientY - rect.top;

            // Create a placeholder
            placeholder = document.createElement('li');
            placeholder.className = 'item';
            placeholder.style.height = rect.height + 'px';
            placeholder.style.opacity = '0';
            touchDragElement.after(placeholder);

            // Make the element fixed position
            touchDragElement.style.position = 'fixed';
            touchDragElement.style.width = rect.width + 'px';
            touchDragElement.style.left = rect.left + 'px';
            touchDragElement.style.top = rect.top + 'px';
            touchDragElement.style.zIndex = '1000';
            touchDragElement.classList.add('touch-dragging');
        }

        function handleTouchMove(e) {
            if (!touchDragElement) return;
            e.preventDefault();

            const touch = e.touches[0];

            // Move the element to follow the finger
            touchDragElement.style.top = (touch.clientY - touchOffsetY) + 'px';

            // Find where to insert the placeholder
            const afterElement = getDragAfterElementTouch(touch.clientY);

            if (afterElement == null) {
                itemsList.appendChild(placeholder);
            } else {
                afterElement.before(placeholder);
            }
        }

        function handleTouchEnd(e) {
            if (!touchDragElement) return;

            // Remove fixed positioning
            touchDragElement.style.position = '';
            touchDragElement.style.width = '';
            touchDragElement.style.left = '';
            touchDragElement.style.top = '';
            touchDragElement.style.zIndex = '';
            touchDragElement.classList.remove('touch-dragging');

            // Replace placeholder with actual element
            if (placeholder && placeholder.parentNode) {
                placeholder.replaceWith(touchDragElement);
            }

            placeholder = null;
            touchDragElement = null;
            updateOrder();
        }

        function getDragAfterElement(y) {
            const elements = [...document.querySelectorAll('.item:not(.dragging)')];
            return elements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset, element: child };
                }
                return closest;
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }

        function getDragAfterElementTouch(y) {
            const elements = [...document.querySelectorAll('.item:not(.touch-dragging)')];
            return elements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset, element: child };
                }
                return closest;
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }

        async function updateOrder() {
            const items = [...document.querySelectorAll('.item')].map(el => el.getAttribute('data-id'));
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reorder', items, csrf_token: csrfToken })
                });
                if (!response.ok) throw new Error('Network error');
            } catch (error) {
                console.error('Failed to update order:', error);
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Setup drag and drop for existing items
        document.querySelectorAll('.item').forEach(setupDragAndDrop);

        // Clear buttons
        document.getElementById('clearChecked').addEventListener('click', async () => {
            if (confirm('Clear all checked items?')) {
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'clear_checked', csrf_token: csrfToken })
                    });
                    if (!response.ok) throw new Error('Network error');
                    document.querySelectorAll('.item.checked').forEach(item => item.remove());
                } catch (error) {
                    console.error('Failed to clear checked items:', error);
                }
            }
        });

        document.getElementById('clearAll').addEventListener('click', async () => {
            if (confirm('Clear all items? This cannot be undone.')) {
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'clear_all', csrf_token: csrfToken })
                    });
                    if (!response.ok) throw new Error('Network error');
                    itemsList.innerHTML = '';
                } catch (error) {
                    console.error('Failed to clear all items:', error);
                }
            }
        });
    </script>
</body>
</html>
