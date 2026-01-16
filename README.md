# getme

A dead-simple, self-hosted grocery list. One PHP file. One SQLite database. That's it.

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
- Simple API for external integrations

## Installation

1. Drop `index.php` on any PHP-enabled web server
2. Make sure the directory is writable (for the SQLite database)
3. Open it in your browser

The SQLite database (`grocery.db`) is created automatically on first run.

## Requirements

- PHP 7.4+ with PDO SQLite extension
- A web server (Apache, Nginx, etc.)

## API

External apps can add items via POST:

```bash
curl -X POST https://your-server/grocery/ \
  -H "Content-Type: application/json" \
  -d '{"action": "add_item", "name": "Milk"}'
```

## Security Notes

- CSRF protection is built-in for browser requests
- Consider placing behind authentication if exposed to the internet
- The `add_item` API endpoint is open by design (for external integrations)

## License

MIT Licensed. Fork, remix, and reuse as you see fit.
