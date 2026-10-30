<?php
$listName = 'getme';
$databasePath = getenv('GETME_DATABASE') ?: __DIR__ . '/getme.db';
require_once __DIR__ . '/server/api.php';
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
try {
    $service = new ListService(open_database($databasePath));
} catch (Throwable $error) {
    error_log((string) $error);
    json_response(['success' => false, 'error' => 'Database unavailable', 'code' => 'server_error'], 503);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') dispatch_api($service);
$snapshot = $service->read();
$items = $snapshot['items'];
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($listName) ?></title>
    <link rel="stylesheet" href="web/styles.css">
</head>
<body>
    <main class="app">
        <header class="topbar">
            <div>
                <h1><?= e($listName) ?></h1>
            </div>
            <div class="header-actions">
                <button class="icon-btn" id="menuToggle" type="button" aria-label="Open menu" title="Open menu" aria-expanded="false" aria-controls="menuPopover">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M4 7h16v2H4V7Zm0 4h16v2H4v-2Zm0 4h16v2H4v-2Z"/></svg>
                </button>
                <button class="icon-btn" id="addItem" type="button" aria-label="Add item" title="Add item">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M19 11h-6V5h-2v6H5v2h6v6h2v-6h6v-2Z"/></svg>
                </button>
                <div class="menu-popover" id="menuPopover">
                    <button type="button" id="clearChecked">Clear Checked</button>
                    <button type="button" id="exportMarkdown">Copy Markdown</button>
                    <button type="button" class="danger" id="clearAll">Clear All</button>
                </div>
            </div>
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
        <div class="empty-state">No items yet.</div>

    </main>
    <div class="toast" id="toast" role="status" aria-live="polite"></div>

    <script>window.getmeInitialSnapshot = <?= json_encode($snapshot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="web/api-client.js"></script>
    <script src="web/list-state.js"></script>
    <script src="web/app.js"></script>
</body>
</html>
