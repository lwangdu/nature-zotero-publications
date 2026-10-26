# Audit remediation — 2026-10-22

Changes apply to the current working tree, including the prior uncommitted diagnostics work. No production deployment or version bump is included.

| Finding | Disposition |
| --- | --- |
| Plain permalinks | Full endpoint URLs and URL/searchParams construction; legacy page-cached restUrl remains supported. Fragment cache version incremented. |
| Clear Cache resets backoff | Cooldown and attempt transients survive cache clearing. |
| Redis/Memcached cache clearing | Delete registered-source cache slots with delete_transient; no ineffective group flush. Legacy database transients use the same API. |
| Public request cost | 120 requests/minute/client address, shared across both routes; full author facets restricted to editors; maximum 10,000 pages. Host-level limits remain necessary. Short search terms remain supported. |
| Oversized backoff | Honor the full server Backoff/Retry-After deadline; never retry early by capping the delay. |
| Render page size | Clamped to 1–100 server-side. |
| Salt rotation | Document page/CDN cache purge after rotation. |
| Unused priming function | Removed after confirming no callers. Frontend requests remain nonblocking. |
| Malformed metadata | Validate scalar metadata, creator and tag structures; substitute invalid UTF-8 before constructing every database column, including JSON, search text and creators; handle encoding failure explicitly. |
| Oversized SQL batches | Bound statements by actual prepared SQL size (512 KiB) and 40 rows. A single larger row produces an explicit error; smaller host max_allowed_packet settings can still fail. |
| DOI encoding | Encode each path segment, retaining slash separators. |
| Settings autoload | Disable on activation and migrate existing settings during admin initialization. Document constant-based credentials. |
| Orphaned sources | Retention documented. No unsafe automatic deletion: removed blocks can still be cached or referenced elsewhere. Existing Clear Cache/uninstall remain the cleanup paths. |
| Polling | Pending-success delay progressively increases up to 30 seconds; failure delay still up to 60 seconds. |
| Lock TTL | Retained. Worker checks a 12-second elapsed budget between requests, so it does not normally issue four consecutive 20-second requests. Slow database operations remain a hosting/runtime concern. |
| item_type case | Preserve case at REST sanitization boundary. |
| Minimum versions | Retain the owner's explicit WP 7.0 / PHP 8.3 requirements. |
| Readable source | Include src/ and package.json in release ZIP; document repository for full developer tooling/tests. |
| Readme/internal wording | Remove diagnostic iteration labels, add external-service disclosure and terms/privacy links, normalize section heading. |
| Naming/trademarks | Preserve stable saved-block/REST/PHP names; add independent-development statement. This is not trademark clearance or directory submission approval. |

## Verification

- PHP syntax and WordPress-Core PHPCS.
- PHP regression fixtures for redaction, diagnostics, cache deletion/cooldowns, malformed data, DOI encoding, SQL byte limits, UTF-8 handling and public request limits.
- JavaScript URL tests for pretty/plain URLs, both endpoints, existing parameters and old cached markup.
- JavaScript/CSS lint, production build, ZIP source/build consistency and test exclusion.
- Local browser: 263 initial entries; water search 130; water plus 2025 filter five. Page 2 navigation also passed after filters settled. A real plain rest_route items request returned one item and total 263.

Fixtures use WordPress/database doubles. Live Redis/Memcached and MariaDB, concurrency/load tests, and minimum-version environments are not covered. No staging/live deployment performed. Public throttling uses REMOTE_ADDR, so trusted proxy configuration and shared-address traffic must be considered. Database-only counters are best-effort under concurrency.


## WordPress.org submission pass — October 26, 2026

Status: preparation completed where supported; NOT cleared for submission.

### Changes

- Added the GPL v2 license text; declared GPL-2.0-or-later in package metadata and included LICENSE in the distribution.
- Updated 1.0.7 changelog entries for Group Library defaults, FAQs, diagnostics, bounded requests, Unicode handling, and current polling/priming behavior.
- Documented narrow Plugin Check exceptions: uninstall must drop plugin-owned tables; bulk REPLACE queries prepare identifiers and values and use fixed internal columns. No SQL protection was removed.
- Numbered pagination failed in the available alpha runtime while Next worked. Moved numbered-click handling outside the repeated item scope to update the block context. Verified Page 2 and Page 1 after hydration. Bumped rendered-fragment cache version and regenerated frontend assets.

### Verification evidence

- Full default Plugin Check set, including runtime bootstrap via cli.php, on extracted distribution: no reported errors or warnings after documented exceptions. Readme/header checks included. Experimental checks were not requested.
- PHP syntax, WordPress-Core PHPCS, PHP/JS regression suites, JS/CSS lint and build passed.
- Isolated WordPress 7.1.4-alpha-64164, PHP 8.5.5 CLI/server, SQLite integration: clean activation, default Group Library, scheduling without HTTP, bounded 100-item first batch and 5-item completion, page-two query, mocked HTTP 403 isolation, preservation on reactivation, deactivation and uninstall cleanup passed.
- Uninstall preserved unrelated options and posts. No user-created site content was deleted.
- Browser: rendered bibliography, search empty state, Next, numbered Page 2 and Page 1 passed. No frontend console errors observed.
- Draft screenshots in submission-assets use synthetic publication metadata, not a real Zotero library. screenshot-1 is the frontend; screenshot-3 is settings. Screenshot 2 (editor) remains pending.
- Available built asset manifests reference WordPress-provided packages, including React and Interactivity API. No node_modules/vendor tree is included in the ZIP. LICENSE is GPL v2 with plugin headers declaring version 2 or later. This is a distribution inventory review, not an exhaustive transitive build-tool license audit.

### Outstanding release gates

- MySQL/MariaDB and native PHP 8.3 are unavailable here (no database server or Docker executable found). Run the same lifecycle/sync tests against supported production database and minimum PHP before release.
- Current local WordPress is an alpha, not stable minimum 7.0. Test supported stable WordPress versions and then confirm Tested up to. Existing 7.0 minimum, 8.3 minimum and 7.1 Tested up to metadata were not changed or newly certified.
- Editor iframe was blank in the isolated alpha browser test with no reported console error. Insert/configure/save/reload/duplicate, two independent blocks, responsive and full keyboard checks remain unverified.
- Real public/private Zotero credentials, natural cron execution, refresh interruption recovery and Redis concurrency remain to be verified beyond isolated fixtures.
- Upgrade testing covered reactivation and current schema preservation, not a complete historical-version migration matrix.
- Existing Stunt Ranch site's SQLite database reported disk image malformed during WP-CLI bootstrap. It was not repaired or replaced. All subsequent integration testing used an isolated temporary database.
- The author confirmed the WordPress.org username lobsangw. Reserved/available slug status remains unconfirmed. Public profile and directory lookups were inaccessible to the browsing tool. Name does not claim official Zotero endorsement; final trademark/name acceptance belongs to reviewers.
- Directory icon/banner artwork is optional and not prepared. Editor screenshot and final screenshot selection remain pending.
- No plugin submission, SVN publication, tag, commit or push performed in this pass. Prepare a distinct release version before distributing new public updates; current version remains 1.0.7.

### Official references

- https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- https://developer.wordpress.org/plugins/wordpress-org/using-the-mcp-server/
- https://wordpress.org/plugins/plugin-check/
- https://developer.wordpress.org/block-editor/reference-guides/packages/packages-interactivity/
