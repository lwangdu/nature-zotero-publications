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

## PHP

- Follow the WordPress PHP Coding Standards.
- Use tabs for indentation and WordPress spacing conventions.
- Use one class per file and descriptive lowercase, hyphenated filenames such as `class-zotero-api.php`.
- Keep classes namespaced under `Zotero_Display`.
- Keep global functions, constants, hooks, options, transients, and REST routes consistently prefixed with `zotero_display` or `ZOTERO_DISPLAY`.
- After the file docblock, protect directly accessed PHP files with `defined( 'ABSPATH' ) || exit;`.
- Sanitize input with context-appropriate functions such as `sanitize_text_field()`, `sanitize_key()`, `absint()`, and `esc_url_raw()`.
- Escape output with context-appropriate functions such as `esc_html()`, `esc_attr()`, `esc_url()`, and `wp_kses_post()`.
- Use `$wpdb->prepare()` for dynamic SQL values.
- Prefer WordPress APIs and existing helpers over direct database queries.
- Check capabilities before administrative or user-specific actions.
- Verify nonces for state-changing admin, AJAX, REST, and form actions.
- Do not expose credentials, tokens, private data, or sensitive diagnostics in responses, markup, JavaScript, scheduled-event arguments, or logs.

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

## Blocks And JavaScript

- Use `@wordpress/scripts`, because it is already part of this project.
- Register the block through `src/block.json` and keep generated `build/block.json` in sync.
- This is a dynamic block: `save` should return `null`, and frontend markup should come from PHP rendering.
- Prefer the WordPress Interactivity API for frontend search, filters, pagination, sync polling, and client-side state.
- Follow existing state-management, data-fetching, and component patterns.
- Use buttons for actions and links for navigation.
- Do not edit generated `build/` files manually. Change `src/` files and run `npm run build`.

## Internationalization

- All user-facing strings must be translatable.
- Use the exact text domain `nature-zotero-publications`.
- Use functions such as `__()`, `_e()`, `esc_html__()`, and `esc_attr__()`.
- Use `wp_set_script_translations()` where appropriate.
- Add translator comments when a string's context is unclear.
- Keep PHP and JavaScript text domains consistent.

## Accessibility

- Review every block, admin screen, and frontend interaction for accessibility.
- Ensure all interactive elements work with keyboard navigation.
- Provide visible focus indicators.
- Give every control an associated label or accessible name.
- Use semantic headings, lists, landmarks, buttons, and links.
- Announce dynamic result counts, loading states, and errors with appropriate status or live regions.
- Do not rely on color alone to communicate meaning.
- Check WCAG 2.1 AA contrast requirements for new UI.
- Respect `prefers-reduced-motion`.
- Test empty, loading, error, and no-permission states.

## Data, Sync, And Cleanup

- Treat `{$wpdb->prefix}zotero_display_items` and `{$wpdb->prefix}zotero_display_creators` as plugin-owned local index tables.
- Do not drop persistent tables on deactivation.
- Keep `uninstall.php` responsible for deleting plugin-owned tables, settings, schema/sync options, transients, supported object-cache group data, and scheduled sync events.
- Document any changed storage or deletion behavior in `README.md` and `readme.txt`.
- For performance work, trace the actual request, render, REST, sync, cache, and database path before changing cache strategy.
- Keep first-page rendering fast when the local index already exists. Avoid making frontend page render wait on full Zotero synchronization.
- Keep sync work resumable and bounded so cache-clearing a small library does not make the page appear stalled longer than necessary.

## Common Change Paths

- **Slow page load:** inspect `includes/class-block.php`, `includes/class-rest-controller.php`, `includes/class-sync.php`, and `src/frontend.js`; measure both initial HTML response time and REST polling behavior before editing.
- **Pagination or filters:** update block attributes/defaults, PHP query handling, REST args, frontend state, generated `build/`, and docs together.
- **Zotero data fields:** update API normalization, database schema/storage, renderer output, REST responses, editor preview if relevant, and docs.
- **Storage lifecycle:** update activation/install, schema versioning, `uninstall.php`, README/readme storage notes, and manual verification steps.
- **Public API security:** check source signatures, route args, permission callbacks, API-key handling, and response payloads together.

## Validation

Run the project checks that match the files changed:

```bash
npm run lint:js
npm run lint:css
npm run build
php -l nature-zotero-publications.php
php -l uninstall.php
php -l includes/class-block.php
php -l includes/class-rest-controller.php
php -l includes/class-settings.php
php -l includes/class-sync.php
php -l includes/class-zotero-api.php
php /Users/lwangdu/Studio/stunt-ranch/wp-content/plugins/plugin-check/vendor/squizlabs/php_codesniffer/bin/phpcs --standard=WordPress-Core nature-zotero-publications.php includes/class-block.php includes/class-rest-controller.php includes/class-settings.php includes/class-sync.php includes/class-zotero-api.php uninstall.php
git diff --check
```

Manually verify activation, editor registration, frontend rendering, responsive behavior, API success and failure states, caching, permissions, and backward compatibility when relevant.

For performance-sensitive changes, also capture before/after timings for the affected page or REST endpoint. Record whether the measurement is local Studio runtime evidence or only static analysis.

## Ask Before

Ask for explicit approval before:

- Changing the minimum WordPress or PHP version.
- Adding an external service or dependency.
- Adding an npm package that makes network requests.
- Modifying `vendor/`, `node_modules/`, or generated files directly.
- Changing public REST endpoints, block attributes, or stored content formats.
- Removing features or backward compatibility.
- Removing or weakening sanitization, escaping, nonce, capability, or permission checks.
- Changing multiple version values at once. If plugin, block, and readme versions differ, report the mismatch instead of guessing.

## Completion Report

Summarize changed files, validation commands run, manual checks performed, and any unresolved issues or assumptions.
