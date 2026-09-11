---
name: nature-zotero-publications
description: Guide WordPress plugin, Gutenberg block, REST API, Interactivity API, synchronization, performance, accessibility, and release work for the Nature Zotero Publications plugin.
license: GPL-2.0-or-later
metadata:
  project-type: wordpress-plugin
  text-domain: nature-zotero-publications
---

# Nature Zotero Publications

Use this skill when working on the Nature Zotero Publications WordPress plugin, including plugin architecture, Gutenberg block behavior, REST endpoints, Zotero synchronization, administrative screens, local database tables, frontend interactivity, accessibility, performance, or release preparation.

## Project Map

Use this map to start in the right place:

- Plugin lifecycle, constants, activation, deactivation, and Plugins screen links: `nature-zotero-publications.php`.
- Admin settings, API key storage, default Zotero source, and manual cache clearing: `includes/class-settings.php`.
- Dynamic block registration, server-rendered markup, fragment cache, accessibility markup, and initial frontend state: `includes/class-block.php`.
- Public REST routes for items and author autocomplete: `includes/class-rest-controller.php`.
- Local Zotero index tables, synchronization state, database queries, indexes, and source signatures: `includes/class-sync.php`.
- Zotero API requests, normalization, URL handling, creator formatting, and API error handling: `includes/class-zotero-api.php`.
- Block metadata and editor controls: `src/block.json`, `src/edit.js`, and generated `build/`.
- Frontend Interactivity API behavior: `src/frontend.js` and generated `build/frontend.js`.
- Storage/deletion documentation: `README.md`, `readme.txt`, and `uninstall.php`.

## Before Changing Code

1. Inspect the repository structure and existing documentation.
2. Read `AGENTS.md`, `package.json`, the plugin bootstrap file, relevant files in `includes/`, and relevant `src/block.json` or `build/block.json` files.
3. Identify the actual build, lint, format, and test commands. Do not invent commands that are not configured.
4. Confirm the minimum WordPress and PHP versions, text domain, namespace, plugin prefix, REST namespace, and existing security patterns.
5. Check for uncommitted changes and avoid overwriting unrelated work.
6. For WordPress-specific work, use the matching official WordPress Agent Skill when available; use this file for the project-specific details those general skills will not know.

## Shared Rules

Follow [AGENTS.md](AGENTS.md) for coding standards, stable identifiers, PHP access guards (including the uninstall exception), generated assets, data ownership, development commands, release metadata, and approval requirements. Keep those rules in that file rather than duplicating them here.

## REST And External APIs

- Follow the existing REST namespace: `zotero-display/v1`.
- Define an explicit `permission_callback`; never use `__return_true` for private or state-changing data.
- Keep source-signature validation for public Zotero source requests.
- Validate and sanitize every request parameter at the REST route boundary where possible.
- Return only the data required by the client.
- Set sensible timeouts and handle API errors, empty responses, rate limits, and malformed data.
- Bound pagination, response sizes, and request frequency.
- Cache responses only when the cache does not expose private data.
- Do not expose arbitrary saved plugin settings through public REST. Public visitors may only query a source that was already rendered into block markup and signed by the server.

## Blocks, Internationalization, And Accessibility

- Trace changes through editor controls, PHP-rendered markup, Interactivity API context, and frontend actions. Update only the layers affected by the requested behavior.
- Preserve initial server-rendered results while enhancing search, filters, author suggestions, and pagination.
- For changed UI, verify labels, visible focus, keyboard operation, dynamic result/error announcements, and loading and empty states. Check author suggestion selection and focus after closing the list.
- Keep PHP-rendered and JavaScript-rendered labels consistent and translatable; add translator comments for placeholders and ambiguous context.

## Data, Sync, And Cleanup

- For performance work, trace the actual request, render, REST, sync, cache, and database path before changing cache strategy.
- Keep first-page rendering fast when the local index already exists. Avoid making frontend page render wait on full Zotero synchronization.
- Keep sync work resumable and bounded so cache-clearing a small library does not make the page appear stalled longer than necessary.

## Common Change Paths

- **Slow page load:** inspect `includes/class-block.php`, `includes/class-rest-controller.php`, `includes/class-sync.php`, and `src/frontend.js`; measure both initial HTML response time and REST polling behavior before editing.
- **Pagination or filters:** trace block attributes/defaults, PHP queries, REST args, frontend state, generated `build/`, and docs; update the affected layers together while preserving existing public formats unless their change is authorized.
- **Zotero data fields:** update API normalization, database schema/storage, renderer output, REST responses, editor preview if relevant, and docs.
- **Storage lifecycle:** update activation/install, schema versioning, `uninstall.php`, README/readme storage notes, and manual verification steps.
- **Public API security:** check source signatures, route args, permission callbacks, API-key handling, and response payloads together.

## Validation

Use the commands in [AGENTS.md](AGENTS.md#development-commands) that match the changed files, then run `git diff --check`. The repository currently has no configured test script; do not claim `npm test` passed. Use focused fixtures or manual checks for behavior that lint cannot verify, and describe their scope.

### Focused Regression Checks

Run the relevant cases when changing synchronization, polling, or compatibility:

- **Polling:** failed HTTP responses, network failures, and pending sync responses wait five seconds before retrying. A ready response reloads once and stops polling; a sync error stops polling and displays its message. Use mocked fetch responses and timers so failure tests do not flood a real endpoint.
- **Retry interval:** a failed initial sync and a failed refresh remain in the error state for `Sync::RETRY_DELAY` (currently 300 seconds). Verify a retry becomes eligible after the interval, and that new sources, fresh completed sources, stale completed sources, and in-progress syncs still follow their normal scheduling paths.
- **Completed data:** refreshing or failing a refresh preserves the completed generation and its readable results until a successful replacement is available. Exercise failure paths with fixtures or a disposable site rather than clearing the user's index.
- **WordPress compatibility:** verify imported Interactivity API exports and directives exist in the declared minimum WordPress version. Test the affected behavior on that version when available and distinguish source inspection from runtime validation. Keep `Tested up to` limited to versions actually checked; do not claim compatibility with untested future versions.

Manually verify activation, editor registration, frontend rendering, responsive behavior, API success and failure states, caching, permissions, and backward compatibility when relevant.

For performance-sensitive changes, also capture before/after timings for the affected page or REST endpoint. Record whether the measurement is local Studio runtime evidence or only static analysis.

## Completion Report

Summarize changed files, validation commands run, manual checks performed, and any unresolved issues or assumptions.
