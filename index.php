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
$doneCount = count(array_filter($items, function ($item) { return (int) $item['checked']; }));
$leftCount = count($items) - $doneCount;
if (!$items) $summary = 'Your list is empty';
elseif (!$leftCount) $summary = 'All done';
else $summary = $leftCount . ' to get' . ($doneCount ? " · $doneCount done" : '');
$progress = $items ? round($doneCount / count($items) * 100) : 0;
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#f6f1e7" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#171513" media="(prefers-color-scheme: dark)">
    <title><?= e($listName) ?></title>
    <link rel="stylesheet" href="web/styles.css">
</head>
<body>
    <main class="app">
        <header class="topbar">
            <div class="title-block">
                <h1><span class="title-mark"><?= e($listName) ?></span></h1>
                <p class="summary" id="summary" aria-live="polite"><?= e($summary) ?></p>
            </div>
            <div class="header-actions">
                <button class="icon-btn" id="menuToggle" type="button" aria-label="Open menu" title="Open menu" aria-expanded="false" aria-controls="menuPopover">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="2" fill="currentColor"/><circle cx="12" cy="12" r="2" fill="currentColor"/><circle cx="19" cy="12" r="2" fill="currentColor"/></svg>
                </button>
                <button class="icon-btn primary" id="addItem" type="button" aria-label="Add item" title="Add item">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
                </button>
                <div class="menu-popover" id="menuPopover">
                    <button type="button" id="clearChecked">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="m4 12 5 5L20 6"/></svg>
                        Clear checked
                    </button>
                    <button type="button" id="exportMarkdown">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="M9 9h10v11H9zM5 15V4h10"/></svg>
                        Copy as Markdown
                    </button>
                    <hr>
                    <button type="button" class="danger" id="clearAll">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>
                        Clear all
                    </button>
                </div>
            </div>
        </header>
        <div class="progress" aria-hidden="true"><span id="progressBar" style="width: <?= $progress ?>%"></span></div>

        <section class="sheet">
            <ul class="items-list" id="itemsList">
                <?php foreach ($items as $item): ?>
                    <li class="item <?= (int) $item['checked'] ? 'checked' : '' ?>" data-id="<?= (int) $item['id'] ?>">
                        <input type="checkbox" class="item-checkbox" aria-label="Toggle item" <?= (int) $item['checked'] ? 'checked' : '' ?>>
                        <span class="item-text" contenteditable="false"><?= e($item['name']) ?></span>
                        <button class="item-action delete" type="button" aria-label="Delete item" title="Delete item">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="empty-state">
                <div class="empty-note" aria-hidden="true"></div>
                <p class="empty-title">Nothing to get</p>
                <p class="empty-hint">Add the first thing you need.</p>
            </div>
            <button class="add-row" id="addRow" type="button">
                <span class="add-row-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" d="M12 6v12M6 12h12"/></svg>
                </span>
                Add an item
            </button>
        </section>
        <p class="hint">
            <span class="hint-mouse">Double-click to edit · drag to reorder · hover to delete</span>
            <span class="hint-touch">Double-tap to edit · hold to reorder · swipe left to delete</span>
        </p>
    </main>
    <div class="toast" id="toast" role="status" aria-live="polite"></div>

    <script>window.getmeInitialSnapshot = <?= json_encode($snapshot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="web/api-client.js"></script>
    <script src="web/list-state.js"></script>
    <script src="web/app.js"></script>
</body>
</html>
