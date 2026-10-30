# HTTP sync API v1

POST JSON to the same URL as the web application. Successful responses contain
`success: true`, `version: 1`, `revision`, and `items`. Items have `id`, `name`,
`checked` (0/1), and `position`; ordering is checked, position, then ID.

Read:

```json
{"version":1,"action":"read","revision":12}
```

`revision` is optional. `unchanged` compares the supplied revision to the current
one. Responses currently include the complete snapshot even when unchanged.

Apply:

```json
{"version":1,"action":"apply","revision":12,"operations":[
  {"id":"client-unique-operation-id","type":"set_checked","item_id":7,"checked":1}
]}
```

The base revision is required. Between 1 and 100 operations may be submitted.
The response adds `results`, with an `id` and `status: "applied"` for each operation.
Adds also return `item_id`. IDs must be unique strings of 1–100 ASCII letters,
digits, periods, underscores, colons, or hyphens. Preserve IDs and exact operation
contents across retries. Top-level JSON key ordering does not affect identity.

Operations:

| Type | Fields |
| --- | --- |
| `add` | `name` (nonblank, at most 160 UTF-8 bytes) |
| `edit` | `item_id`, `name` |
| `set_checked` | `item_id`, `checked` (boolean or integer 0/1) |
| `delete` | `item_id` |
| `delete_items` | `item_ids` (explicit targets; already absent IDs are harmless) |
| `reorder` | `item_ids` (ordered IDs) |

A batch advances the revision once. All operations commit together or none do.
Entirely deduplicated retries do not advance it. Retried results refer to the
original operation; returned items describe the current list and may since have
changed or been deleted. Reusing an operation ID with changed contents returns
409 `operation_mismatch`. A new operation against a stale revision returns 409
`conflict`. Refresh and let the user resolve it; do not silently replay it.

Errors contain `success: false`, `error` (displayable message), and `code`.
Validation errors return 422, missing items 404, malformed JSON/unsupported
versions 400, and unexpected server failures 500. Treat network timeouts, 5xx,
and 429 as potentially ambiguous: retry the identical envelope, never mint a
replacement operation ID. Operation results are retained indefinitely.

Unversioned legacy actions remain supported. Legacy writes use the same service
and advance the revision. Legacy `add`/`add_item` requests with `request_id` retain
duplicate protection, including IDs stored before the migration.

Authentication remains the existing URL-based shared-list model. Anyone who can
reach the endpoint can read and change the list. No account service is introduced.
