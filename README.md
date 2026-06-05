# getme

A dead-simple, self-hosted list app. One PHP file. One SQLite database. Anyone with the URL can use it.

getme also has a Pebble companion app: [getme for Pebble](https://github.com/tylxr59/getme-for-pebble).

## Why?

Because sometimes you just need a list that:
- Works on any device with a browser
- Doesn't require an account
- Doesn't track you
- Runs on practically anything with PHP

## Features

- Add, edit, and delete items
- Check off items (they move to the bottom)
- Drag-and-drop reordering (works on mobile too)
- System dark mode
- Refreshes when you return to the tab
- Copy the list as Markdown
- Open JSON API for external integrations
- Pebble watch integration through getme for Pebble

## Related Repos

- getme web app/API: <https://github.com/tylxr59/getme>
- getme for Pebble watch app: <https://github.com/tylxr59/getme-for-pebble>

## Installation

1. Drop `index.php` on any PHP-enabled web server
2. Make sure the directory is writable (for the SQLite database)
3. Open it in your browser

The SQLite database (`getme.db`) is created automatically on first run.

To rename the list, edit `$listName` near the top of `index.php`. To move the database outside the web directory, edit `$databasePath`.

## Requirements

- PHP 7.4+ with PDO SQLite extension
- A web server (Apache, Nginx, etc.)

## API

External apps can use the same URL as the web app as a JSON API. The API is unauthenticated; anyone who can reach the URL can read and change the list.

getme for Pebble uses this API to fetch the list, add items, toggle checked state, and clear checked items.

All requests are `POST` with `Content-Type: application/json`. CORS preflight requests are supported for browser-like clients.

Fetch the current list:

```bash
curl -X POST https://your-server/getme/ \
  -H "Content-Type: application/json" \
  -d '{"action": "fetch"}'
```

Response:

```json
{
  "success": true,
  "items": [
    { "id": 1, "name": "Milk", "checked": 0, "position": 1 }
  ]
}
```

Add an item. The older `add_item` action is still accepted as an alias for `add`.

```bash
curl -X POST https://your-server/getme/ \
  -H "Content-Type: application/json" \
  -d '{"action": "add", "name": "Milk"}'
```

Edit an item:

```bash
curl -X POST https://your-server/getme/ \
  -H "Content-Type: application/json" \
  -d '{"action": "edit", "id": 1, "name": "Oat milk"}'
```

Toggle an item:

```bash
curl -X POST https://your-server/getme/ \
  -H "Content-Type: application/json" \
  -d '{"action": "toggle", "id": 1, "checked": 1}'
```

Reorder items:

```bash
curl -X POST https://your-server/getme/ \
  -H "Content-Type: application/json" \
  -d '{"action": "reorder", "items": [2, 1, 3]}'
```

Delete an item:

```bash
curl -X POST https://your-server/getme/ \
  -H "Content-Type: application/json" \
  -d '{"action": "delete", "id": 1}'
```

Clear checked items:

```bash
curl -X POST https://your-server/getme/ \
  -H "Content-Type: application/json" \
  -d '{"action": "clear_checked"}'
```

Clear all items:

```bash
curl -X POST https://your-server/getme/ \
  -H "Content-Type: application/json" \
  -d '{"action": "clear_all"}'
```

## Security Notes

This is intentionally unauthenticated. Anyone who can reach the URL can add, edit, reorder, check, and delete items.

If you leave the default database path in place, configure your web server not to serve `getme.db` directly. A safer option is to set `$databasePath` to a location outside the web root.
