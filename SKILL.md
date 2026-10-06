---
name: nature-zotero-publications
description: Develop, debug, review, or prepare releases for the Nature Zotero Publications WordPress plugin, including its dynamic bibliography block, Zotero synchronization, REST endpoints, and frontend interactivity. Use for this plugin's implementation and maintenance, not general Zotero research.
license: GPL-2.0-or-later
metadata:
  project-type: wordpress-plugin
  text-domain: nature-zotero-publications
---

# Nature Zotero Publications

Maintain the plugin's dynamic bibliography block, local Zotero index,
and interactive frontend while preserving stored content, public
interfaces, and completed synchronization data.

## Start Here

1. Locate the repository root and read its applicable `AGENTS.md`.
2. Inspect `git status --short` and preserve unrelated changes.
3. Read `package.json`, the plugin bootstrap, and the implementation
   relevant to the task.
4. Verify supported WordPress/PHP versions, configured commands,
   stable identifiers, and existing security patterns.
5. Trace the affected behavior before editing. Use the project map
   below to select files; do not read every subsystem unnecessarily.
6. Use relevant WordPress Agent Skills when available. If unavailable,
   follow this workflow directly without blocking routine work.

Treat this document's project map and regression expectations as
guidance to verify against the checkout. Report discrepancies rather
than silently changing public behavior to match this document.

Follow the repository's `AGENTS.md` for coding standards, identifiers,
PHP access guards, generated assets, data ownership, commands, and
approval requirements. Do not duplicate those rules here.

## Project Map

Paths below are relative to the repository root. Verify their existence
before editing.

| Concern | Start with |
| --- | --- |
| Bootstrap, constants, lifecycle hooks, Plugins screen links | `nature-zotero-publications.php` |
| Settings, API key storage, default source, manual cache clearing | `includes/class-settings.php` |
| Dynamic rendering, fragment cache, initial state, accessible markup | `includes/class-block.php` |
| Items and author-autocomplete REST endpoints | `includes/class-rest-controller.php` |
| Local index, synchronization, queries, indexes, source signatures | `includes/class-sync.php` |
| Zotero requests, normalization, URLs, creator formatting, errors | `includes/class-zotero-api.php` |
| Block metadata and editor controls | `src/block.json`, `src/edit.js` |
| Frontend Interactivity API behavior | `src/frontend.js` |
| Generated production assets | `build/` |
| Storage and deletion behavior | `uninstall.php`, `README.md`, `readme.txt` |

Edit source files and regenerate production assets using the configured
build command. Do not hand-edit `build/`.

## Dynamic Block Workflow

Trace a behavior change through the relevant layers:

1. Block attributes and editor controls.
2. PHP attribute normalization and rendering.
3. REST arguments and query behavior.
4. Interactivity API context and actions.
5. Generated assets and affected documentation.

Update only the layers required by the change.

- Preserve the block name `zotero-display/library`.
- Keep metadata in `src/block.json` authoritative and maintain
  server-side registration through the existing build pipeline.
- Preserve the dynamic architecture: `save` returns `null`, and
  `includes/class-block.php` produces frontend markup.
- Validate attributes in PHP, including missing or malformed values
  from older saved content.
- Preserve attribute names, types, defaults, and serialization unless
  a compatibility change is authorized.
- Do not introduce attributes whose `source` depends on frontend HTML
  that this dynamic block does not save.
- If introducing InnerBlocks, explicitly design its saved-content
  handling; do not assume `save: null` persists inner content.
- Preserve editor and frontend wrapper integration using
  `useBlockProps()` and `get_block_wrapper_attributes()`.
- Never store credentials or private data in attributes, examples,
  patterns, or saved post content.
- Verify existing blocks can still open, save, reload, and render.

## Block Theme Integration

- Keep the bibliography block, REST endpoints, and synchronization
  inside the plugin.
- Respect theme typography, colors, layout constraints, and exposed
  block supports.
- Scope plugin styles to the block wrapper and avoid requiring
  theme-specific preset slugs.
- Preserve WordPress-generated wrapper classes and styles.
- Check both the editor and frontend when changing block presentation.
- When a task also changes a block theme, use HTML block templates in
  `templates/` and `parts/`, with theme design settings in `theme.json`.
- The HTML-template requirement does not prohibit the plugin's
  dynamic PHP renderer.
- Check for Site Editor overrides when theme file changes do not
  appear. Preserve user customizations.

## REST and Zotero API Workflow

- Preserve the REST namespace `zotero-display/v1`.
- Define an explicit `permission_callback` for each route.
- Use public permission callbacks only for intentionally public data.
  Never use `__return_true` to authorize privileged or state-changing
  operations.
- Preserve source-signature validation for public source requests.
  Public visitors may query only a source made available through the
  existing server-rendered, signed block flow.
- Treat a source signature as scope validation, not user authentication.
  A signature exposed in public markup must not grant access to data
  intended to remain private.
- Validate and sanitize route parameters at the boundary. Bound page
  sizes, search input, response sizes, and request frequency.
- Preserve response shapes unless an interface change is authorized.
- Return only fields required by the client. Never expose API keys,
  arbitrary plugin settings, or sensitive upstream error details.
- Keep privileged operations behind appropriate capability checks
  and authentication. Apply nonce protection to browser-originated
  state changes as required by their authentication context.
- Use the existing WordPress HTTP API integration, bounded timeouts,
  and controlled Zotero URLs. Do not allow public input to become an
  unrestricted server-side fetch destination.
- Handle rate limits, malformed responses, empty results, and upstream
  failures. Respect upstream retry guidance where applicable.
- Scope caches to the relevant source and access context so cached
  data cannot cross privacy boundaries.

## Interactivity and Accessibility

- Preserve the `zotero-display` Interactivity API store.
- Keep instance-specific filters, pagination, loading state, and
  control identifiers independent across blocks on the same page.
- Preserve useful server-rendered initial results.
- Handle stale or out-of-order requests so older responses cannot
  overwrite newer search results.
- Keep secrets out of client-visible state and context.
- Preserve loading, empty, error, retry, and completed states.
- Verify labels, visible focus, keyboard operation, result
  announcements, and responsive layout.
- For autocomplete, check keyboard selection, dismissal, and focus
  behavior after the suggestion list closes.
- Avoid excessive live-region announcements and unexpected focus moves.
- Keep PHP and JavaScript labels consistent and translatable using
  `nature-zotero-publications`.
- Add translator comments for substitution placeholders and
  ambiguous wording.
- Verify changed Interactivity API exports, directives, and async
  patterns against the minimum supported WordPress version.

## Synchronization and Data Integrity

Before changing synchronization, trace its entry point, scheduling,
locking, batching, persistence, cache invalidation, and frontend status.

- Serve existing indexed results without waiting for a full remote sync.
- Keep synchronization resumable and bounded.
- Preserve the completed generation during refreshes and failed
  refreshes until a successful replacement is available.
- Check how concurrent workers are prevented from duplicating or
  conflicting with the same synchronization.
- Preserve retry-delay and error-state behavior.
- Invalidate affected caches when replacement data becomes available.
- Keep sources isolated in queries, synchronization state, and caches.
- Do not clear the user's index merely to reproduce a failure.
  Use fixtures or a disposable site.
- For storage changes, update schema/version handling, lifecycle
  behavior, uninstall cleanup, and administrator documentation.
- Keep deactivation distinct from permanent deletion.

## Common Change Paths

| Task | Trace and verify |
| --- | --- |
| Slow initial page | Renderer, local queries, fragment cache, sync scheduling, initial HTML timing |
| Excessive polling | Frontend actions, REST status responses, retry timing, stop conditions |
| Pagination or filters | Attributes, PHP query, REST schema, instance state, generated assets |
| New Zotero fields | API normalization, storage, schema migration if needed, rendering, REST payload |
| Storage lifecycle | Installation, upgrades, scheduled events, uninstall, storage documentation |
| Public API security | Signatures, permissions, route validation, credentials, cache scope, responses |
| Theme styling | Block supports, wrappers, scoped CSS, editor styles, frontend inheritance |

## Validation

Use relevant commands actually configured in the checkout, followed by
`git diff --check`.

The supplied project guidance reports no configured test script.
Verify this in `package.json`; do not invent `npm test` or claim it
passed without running an existing script.

Use focused fixtures or manual checks for behavior that syntax checks,
linting, and builds cannot establish.

### Polling Regression Checks

The documented behavior is:

- Failed HTTP requests, network failures, and pending-sync responses
  wait five seconds before retrying.
- A ready response reloads once and stops polling.
- A sync error stops polling and displays its message.

Verify the implementation and test these paths using mocked responses
and timers. Do not flood a live endpoint or change retry semantics
incidentally.

### Retry Eligibility

Read `Sync::RETRY_DELAY` from the implementation. The supplied baseline
is 300 seconds; verify the current value rather than hardcoding it
into a new test or implementation.

Check that:

- Failed initial syncs and refreshes remain in the error state during
  the retry delay.
- Retries become eligible after the delay.
- New sources, fresh completed sources, stale completed sources, and
  in-progress syncs retain their intended scheduling behavior.

### Completed Data

Verify that refreshes and failed refreshes preserve the completed
generation and readable results. Check that successful replacement
updates results and invalidates affected caches.

### Block and Frontend Behavior

For relevant changes, test:

- Insert, configure, save, reload, and duplicate.
- Older saved blocks.
- Two independently configured blocks on one page.
- Search, filters, autocomplete, and pagination.
- Loading, empty, failure, and recovery states.
- Editor/frontend presentation and keyboard operation.

### Compatibility and Performance

- Test changed behavior on the minimum supported WordPress version
  when available.
- Distinguish source inspection from runtime validation.
- Update `Tested up to` only for versions actually checked.
- For performance work, record before/after timings under comparable
  source, cache, and runtime conditions.
- Identify whether evidence comes from local Studio, another runtime,
  mocked tests, or static analysis.
- Report unavailable checks and their remaining uncertainty.

## Release and Completion

For release work, follow the repository's `AGENTS.md` release workflow.
Verify matching source/build assets and authorized version metadata.
Do not infer permission to publish from a request to prepare a release.

Report:

- Changed files and resulting behavior.
- Validation commands and outcomes.
- Manual or fixture-based checks performed.
- Compatibility or performance evidence when relevant.
- Unresolved issues, assumptions, and unavailable checks.