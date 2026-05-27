# getme

A dead-simple, self-hosted grocery list. One PHP file. One SQLite database. Anyone with the URL can use it.

## Why?

Because sometimes you just need a grocery list that:
- Works on any device with a browser
- Doesn't require an account
- Doesn't track you
- Runs on practically anything with PHP

## Features

- Add, edit, and delete items
- Check off items (they move to the bottom)
- Drag-and-drop reordering (works on mobile too)
- Dark mode
- Syncs across devices automatically
- Copy the list as Markdown
- Open JSON API for external integrations

## Installation

1. Drop `index.php` on any PHP-enabled web server
2. Make sure the directory is writable (for the SQLite database)
3. Open it in your browser

The SQLite database (`grocery.db`) is created automatically on first run.

## Requirements

- PHP 7.4+ with PDO SQLite extension
- A web server (Apache, Nginx, etc.)

## API

External apps can add items via POST. The older `add_item` action is still accepted as an alias for `add`.

```bash
curl -X POST https://your-server/grocery/ \
  -H "Content-Type: application/json" \
  -d '{"action": "add", "name": "Milk"}'
```

## Security Notes

This is intentionally unauthenticated. Anyone who can reach the URL can add, edit, reorder, check, and delete items.

## License

MIT Licensed. Fork, remix, and reuse as you see fit.
