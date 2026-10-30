# getme sync architecture

The PHP API owns confirmed list state. SQLite stores items, a list revision, and
immutable operation results. The browser and watch keep optimistic local views.
The phone is an HTTP/AppMessage adapter, not another list authority.

## Server

- `index.php`: configuration, request routing, and initial HTML rendering.
- `server/database.php`: serialized, additive upgrades of existing SQLite files.
- `server/list-service.php`: validation, list rules, and transactions.
- `server/api.php`: versioned JSON endpoint and legacy action adapter.

Every accepted batch runs under `BEGIN IMMEDIATE`. Item changes, operation
receipts, and revision advancement commit together. Reads use a transaction so
items and revision agree. An operation ID is permanently bound to its payload.
A retry of a committed operation succeeds even with its original base revision;
it returns its original receipt and the current snapshot. A new operation with a
stale base revision receives HTTP 409. Batches are all-or-nothing; rejected batches
make no changes and store no receipts. Receipts are not automatically pruned.

## Browser

- `web/api-client.js`: confirmed snapshot, API translation, bounded retries with
  identical operation IDs, and conflict baseline refresh.
- `web/list-state.js`: serialized writes and refresh guards.
- `web/app.js`: DOM rendering, editing, dragging, and optimistic feedback.
- `web/styles.css`: presentation.

The DOM retains provisional edits. Failed saves remain visible or roll back using
the existing UI behavior. A conflict schedules reconciliation and is never
silently replayed. The browser does not promise durable offline edits. Refreshes
occur on focus/visibility changes, with guards against replacing active edits.

## Pebble companion

In the sibling `getme-for-pebble` repository:

- `src/pkjs/index.js`: platform event wiring.
- `settings.js`: Clay settings and URL normalization.
- `http-client.js`: JSON HTTP, timeout, and definitive/temporary error classification.
- `sync.js`: immutable prepared envelopes, own-write revision chain, and reconciliation.
- `watch-transport.js`: UTF-8 limits and sequenced snapshot transfer.
- `src/c/messaging.c`: sync lifecycle, timers, pending overlay, and acknowledgments.
- `sync_state.*`: durable operation model.
- `pending_store.*`: checksummed, two-bank storage journal.
- `watch_protocol.*`: outbound AppMessage encoding.
- `checklist.*`, `windows/*`: existing cached list, rendering, and controls.

The watch persists operation sequence IDs and pending work before sending. The
phone persists the exact HTTP envelope before submitting it, including base
revision and explicit deletion targets. A restart retries that envelope unchanged.
Server success is acknowledged to the watch only by a complete snapshot transfer.
The next outbound request carries a cumulative acknowledgment so the phone can
prune prepared envelopes. An HTTP or Bluetooth receipt alone is not a saved edit.

The phone tracks revisions produced by its own consecutive writes, allowing
queued local actions from the same watch snapshot to save in order. It never
adopts a freshly read remote revision to silently rebase an offline mutation.
Conflicts are reported and followed by a fresh snapshot; the rejected action is
resolved as failed. Temporary failures preserve pending work for refresh/reopen.

The snapshot protocol is version 2; the HTTP API is independently version 1.
Begin/items/end transfers include sequence, protocol version, revision, expected
count, and total count. Only complete transfers replace the cached list. The watch
shows the first 52 server-ordered items and explicitly reports the total when more
exist. Clearing checked items targets IDs captured from the watch's visible list,
never checked items outside that view. Changing servers explicitly discards pending
watch edits with an error instead of sending them to a different list.

## Deployment and recovery

1. Back up `getme.db` before upgrading.
2. Upload `index.php`, `server/`, and `web/` together. Existing item IDs are retained.
3. Verify the browser and existing watch app against the legacy API.
4. Install the rebuilt Pebble bundle, which contains both watch and phone code.
5. Exercise add/check/clear and disconnect/reopen before relying on offline edits.

Old watch releases can use the legacy HTTP adapter. The new watch requires the new
server. The web page now depends on its accompanying directories; copying only
`index.php` is no longer an installation. Use `GETME_DATABASE` to locate the database
outside the web root, or keep the existing PHP configuration path.

No deployment, app installation, release tag, or publication is performed by tests.
Host tests cover server rollback/retries/conflicts, browser request ordering,
phone restart/reconciliation, and journal power-loss boundaries. An SDK build
checks compatibility with emery. Physical Bluetooth disconnects, dictation,
settings, and display behavior still require emulator/device acceptance testing.
