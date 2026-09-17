---
name: add-api-action
description: Checklist for adding or changing an endpoint (?action=...) in YT Archiver's public/api.php, including validation, CSRF, logging, the logs page filter, the frontend call and docs. Use whenever a new API action is added or an existing action's input/output changes.
---

# Add or change an API action

1. **Router** (`public/api.php`, the `switch ($action)`): check `$method` first and throw `Exception('Method not allowed')` otherwise.
   Every action already requires an approved account (`$currentUser`). For administrator-only actions call `requireAdmin($currentUser)` first.
   The acting user's email is available as `$currentUser['email']` (it is logged automatically).
2. **Input**
   - POST with a body: `$input = requireJsonRequest();` (this is also the CSRF guard; every POST must call it, even with no body).
   - Type-check every field (`is_string`, `in_array(..., true)`, regex). Never pass raw input to shell commands, paths or yt-dlp.
   - URLs: only through `parseYoutubeUrl()` and rebuild the canonical URL from the extracted IDs.
   - IDs used for files: look up the record in `queue.json`/`database.json` and use the stored ID, not the request value.
3. **State**: wrap read-modify-write of queue/database in `withLock(fn() => ...)`, persist with `saveQueue`/`saveDatabase`.
   Shared helpers belong in `public/includes/common.php`, since the worker may need them too.
4. **Response**: `echo json_encode([...])`. Errors: throw `Exception` (→ 400) or set an explicit `http_response_code`.
5. **Logging**: `logRequest()` logs everything except `version`, `status`, `logs` and `GET videos`. Add a new noisy, polled GET action to the skip list.
   Add the action to the `actionFilter` `<select>` in `public/logs.html`, and optionally a colour in `ACTION_COLORS` in `public/js/logs.js`.
6. **Frontend**: call it via `fetchApi(action, { method, body, params })` in `public/js/app.js` (this sets the JSON Content-Type for POST).
   Render results with `escapeHtml()`.
7. **Docs**: add the action to the API table in `README.md` and in `docs/knowledge-base/architecture.md`.
8. **Tests**: add a check to `tests/integration.php` (it calls the API over HTTP through `dev/serve.php`).
   If the action needs new URL paths or deny rules, change `nginx.conf` **and** `dev/router.php`.
9. **Verify**: run the `verify` skill. For security-relevant actions, run the `security-reviewer` agent.
