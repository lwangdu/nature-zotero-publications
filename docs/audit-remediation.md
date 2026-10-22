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
