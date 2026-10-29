=== Publications List Display for Zotero ===
Contributors: lobsangw
Tags: zotero, bibliography, citations, gutenberg, publications
Requires at least: 7.0
Tested up to: 7.1
Stable tag: 1.0.7
Requires PHP: 8.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Display a searchable and filterable Zotero bibliography with authors, dates, DOI links, citation keys, and pagination.

== Description ==

Publications List Display for Zotero displays live Zotero library data in a dynamic Gutenberg block. Publications are grouped by year and can include authors, linked titles, publication details, item-type badges, dates, DOI links, citation keys, source URLs, and abstracts. Tags remain searchable but are not displayed on publication cards.

This is an independent third-party integration for Zotero. It is not affiliated with or endorsed by Zotero.

The block includes search, year/type/author filters, entry counts, and pagination. Initial results are rendered on the server and progressively enhanced with the WordPress Interactivity API. Author suggestions are requested only after a visitor types at least two characters, avoiding a large inline author list.

The plugin synchronizes every top-level item from the configured Zotero library or collection into a local WordPress index using resumable background batches. Search, filters, counts, and pagination use the local index. Creator names in the author-filter index are limited to 191 Unicode characters; hashes use the original names, and full names remain in publication data and search text. During the first synchronization, already-indexed publications and controls remain available alongside an accessible progress indicator.

The initial synchronization screen reloads as soon as its first indexed publications are available, then displays the partial index while background synchronization continues. Frontend page rendering schedules synchronization without waiting for Zotero. New sources show a lightweight loading state until the background worker supplies the first publications; existing indexed results render immediately. Scheduled workers process up to four pages per run. Each background Zotero request has a 20-second HTTP timeout. Connection failures automatically retry the same batch up to three times, after 60, 120, and 240 seconds, retaining indexed publications and progress. After the final failure, the normal error state and five-minute retry eligibility apply. Automatic retries depend on WordPress Cron running. Editor previews and REST requests only read the local index: typing a source ID never starts or schedules a synchronization. View the frontend page to initialize a new source. Library IDs must contain only digits; collection keys must be empty (the whole library) or exactly eight uppercase letters or digits.

Private API keys remain on the server and are never included in scheduled-event arguments, block markup, or REST responses. Public REST requests are limited to server-signed rendered block sources.

Features include:

* User libraries, group libraries, and individual collections.
* Complete-library background synchronization beyond Zotero's 100-item response limit.
* Server-rendered initial results with Interactivity API enhancements.
* General search plus year, item-type, and author filters.
* Keyboard-accessible, on-demand author suggestions.
* DOI, source, Zotero, and citation-key links and metadata.
* Server-signed public REST requests that avoid arbitrary visitor-triggered source synchronization.
* A completed local index that avoids extra Zotero requests for visitor interactions.
* 100-item default pagination.
* Query-aligned database indexes for faster large-library display and filters.
* Plugin-owned data cleanup on uninstall.

== Frequently Asked Questions ==

= Should I select Group Library or User Library? =

Choose Group Library for a shared Zotero group, or User Library for an individual account’s library. The type must match the numeric ID. Group Library is the default for new configurations; existing saved choices are preserved. Blocks inherit the plugin settings unless you set an override.

= Where do I find my library or group ID? =

For a group library, copy the numeric group ID from its Zotero group URL. For a user library, find your numeric user ID at https://www.zotero.org/settings/keys. Enter only the digits in Default Library / Group ID, not a URL, username, or API key.

= When do I need a Zotero API key? =

Publicly readable libraries can be displayed without a key. Private libraries require a valid key with read permission for the selected user or group library. Enter only the raw key, without quotes, a `Bearer` prefix, or a header name. Never share your key in screenshots or support messages.

= Why is the API key field blank after saving? =

The field stays blank intentionally so the saved key is not displayed. Check Synchronization diagnostics for “API key: configured”; this confirms a key is available, but does not verify its permissions. Leaving the field blank keeps the saved key. Select Remove the saved API key to delete it. If your host defines `ZOTERO_DISPLAY_API_KEY`, that value overrides the saved key.

= Why does the page say “Synchronizing publications”? =

The plugin is waiting for its local publication index to populate. Open the published page to initialize its source; editor previews alone do not start synchronization. Background batches need working WordPress Cron. If progress stops, open Settings → Publications List Display for Zotero → Synchronization diagnostics, check the error and retry deadline, and use Run one sync batch when eligible. Refresh settings to see the result; manual runs still respect retry delays.

= What do HTTP 400 and 403 errors mean? =

HTTP 400 indicates a rejected request; HTTP 403 indicates access was refused. Check the Response explanation in Synchronization diagnostics rather than assuming a database problem. Verify the library type and ID first. “Invalid key” means the supplied key was rejected; check the saved key and any overriding constant. For “Forbidden,” check library visibility and key permissions. An HTML error may also come from a hosting intermediary. Do not repeatedly clear the cache: it removes the local index and does not fix credentials or bypass retry delays.

= Will updating the plugin preserve my settings and publications? =

Replacing the installed plugin through WordPress’s upload/update flow preserves saved settings and the local publication index. Back up the site before updating, and do not delete the plugin first: deletion runs uninstall cleanup and removes plugin-owned data. Changing connection settings or selecting Clear Zotero Cache also clears the local index and requires synchronization again. The Group Library default does not override an existing saved User Library choice.

= Is the private API key exposed to website visitors? =

No. The key is used only for server-side requests and is not returned by the plugin's REST endpoint.

= Does the plugin store Zotero data in the WordPress database? =

Yes. Zotero publication data is stored in plugin-owned local index tables named with the site's database prefix, such as `wp_zotero_display_items` and `wp_zotero_display_creators`. The prefix may differ on your site.

= What happens to plugin data when I delete the plugin? =

Deleting the plugin through WordPress runs `uninstall.php`. It removes the plugin-owned Zotero index tables, plugin settings, schema and synchronization options, plugin transients, supported object-cache group data, and pending synchronization events. Deactivating the plugin does not drop the index tables.

= Can a block display a specific collection? =

Yes. Enter a Zotero collection key in the block settings to limit results to that collection.

= Does the plugin display the complete library? =

Yes. Zotero limits each API response to 100 items, so the first full-library synchronization runs in background batches. The existing cached results remain visible until the complete local index is ready.

= How many publications display per page? =

The block defaults to 100 items per page.

= How does the author filter work? =

Enter at least two characters in the author field. The plugin requests a limited set of suggestions from its local creator index instead of embedding every author in the page. Suggestions support mouse and keyboard selection.

= Why can the displayed count differ from Zotero? =

Compare the display with Zotero's top-level "items in this view" count. Child attachments and annotations are excluded, while top-level standalone attachments are included.

= What should I check if the library does not finish synchronizing? =

Confirm that WordPress Cron is working and allow time for the 100-item background batches to complete. Opening a page containing the block ensures its source is scheduled.

= How do I force a complete refresh? =

Open Settings > Publications List Display for Zotero and select Clear Zotero Cache. This clears transient responses and the local index; the next page request schedules a new synchronization.

== External services ==

This plugin connects to https://api.zotero.org to download publication metadata when a configured source synchronizes, including scheduled refreshes and administrator-triggered batches. Requests send the configured library type and ID, optional collection and item type, sorting and pagination parameters, API version, conditional library version when available, and API key when configured. Zotero also receives the WordPress server IP and HTTP client metadata. Visitor search/filter terms are processed locally and are not sent to Zotero. Publication links may take visitors to Zotero, DOI, or publisher websites when clicked.

Service terms: https://www.zotero.org/support/terms/terms_of_service
Privacy policy: https://www.zotero.org/support/privacy

This plugin is independently developed and is not endorsed by Zotero.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/nature-zotero-publications`, or install the release ZIP through the WordPress Plugins screen.
2. Activate Publications List Display for Zotero.
3. Open Settings > Publications List Display for Zotero.
4. Enter the Zotero library type, library ID, optional private API key, and cache duration. Group Library is the default for new configurations; existing saved choices are preserved.
5. Add the Publications List Display for Zotero block to a post or page.

Large libraries synchronize in background batches through WordPress Cron. The last completed data remains available while a later refresh is running.

== Changelog ==

= 1.0.7 =

* Handle numbered pagination clicks in the parent block context.

* Default new configurations to Group Library while preserving saved choices.
* Add setup FAQs and administrator-only synchronization diagnostics.
* Support plain permalinks and preserve scientific text and valid Unicode.
* Bound SQL writes and public requests, and honor full Zotero retry deadlines.
* Keep editor previews read-only and preserve completed data during refreshes.

* Preserve case-sensitive Zotero sorting values such as dateAdded.
* Load editor previews when using the plugin-wide default library.
* Support arrow navigation and Escape dismissal in author suggestions, restoring input focus on dismissal or keyboard selection.

* Show first indexed publications before the full initial synchronization completes; render pages without synchronous Zotero requests.

* Require PHP 8.3 or newer.
* Require WordPress 7.0 or newer.
* Back off failed polling requests up to 60 seconds and pause polling in hidden tabs.
* Honor the five-minute retry interval after synchronization failures.

= 1.0.6 =

* Require server-signed rendered block sources for public REST publication and author requests.
* Add a PHP string-length fallback for hosts without mbstring.
* Preserve canonical DOI slashes in DOI links.
* Improve uninstall cleanup variable prefixing and refresh REST security documentation.

= 1.0.5 =

* Add uninstall cleanup for plugin-owned tables, options, transients, object-cache data, and scheduled synchronization events.
* Add composite local-index database indexes for faster publication listing, facets, and author lookup.
* Default block and REST fallback pagination to 100 items per page.
* Cache completed server-rendered block fragments.
* Keep Zotero tags searchable while removing tag display from publication cards.

= 1.0.4 =

* Process up to four Zotero pages in each time-bounded background run.
* Poll synchronization progress every five seconds while the first index is being built.
* Skip rendering off-screen publication cards until they approach the viewport.
* Add compact numbered pagination with responsive wrapping.

= 1.0.3 =

* Display already-synchronized publications during the first full-library synchronization.
* Keep accessible progress and automatic reload while partial search, filters, pagination, and author suggestions are available.

= 1.0.2 =

* Remove the synchronous 2,000-item fallback from visitor page and REST requests.
* Show accessible synchronization progress until the first complete local index is ready.
* Reduce SQLite write contention with short bulk writes and one Zotero page per background run.
* Automatically reload the bibliography when its first full synchronization completes.

= 1.0.1 =

* Add resumable background synchronization for complete Zotero libraries.
* Improve frontend performance with the WordPress Interactivity API and cached local filtering.
* Add an accessible on-demand author autocomplete without embedding the full author index.
* Keep the private Zotero API key out of block context, scheduled events, and REST responses.
* Raise the minimum supported WordPress version to 6.5.

= 1.0.0 =

* Initial release.

= Index and request hardening =

Cache-duration-only settings changes preserve the index. Connection changes clear it after saving. Leave the API key blank to retain it, or select Remove the saved API key. The ZOTERO_DISPLAY_API_KEY constant in wp-config.php overrides the saved key; saving settings removes the database copy. Clear Cache after rotating this constant.

Titles and abstracts retain scientific notation and are escaped on display. Existing sources normalize again on their next stale refresh. Sync workers use expiring owner-checked locks and honor Zotero retry delays and conditional responses. Query caches are bounded and short-lived; hidden tabs pause polling. Visit the administration area after updating to apply schema upgrades.

Publishing a bibliography makes indexed metadata, including hidden abstracts and tags, publicly queryable. Display controls are not privacy controls. Publish only metadata that may be public. Uninstall removes current and legacy sync locks as well as the existing plugin-owned data.

= Administrator synchronization diagnostics =

Settings > Publications List Display for Zotero includes administrator-only synchronization diagnostics. It checks whether the publication tables are readable, shows saved source progress, sanitized errors, retry deadlines, and pending cron events. It reports rejected scheduling attempts made after this update. API keys, raw SQL, and raw transport error details are never displayed. Table readability does not establish write permission or full schema compatibility.

Run one sync batch uses a capability and nonce-protected POST and processes at most one 100-item page for a known source. It preserves completed publications and respects locks, API cooldowns, and retry delays. It can take up to 20 seconds. The panel includes the valid default source and up to 100 recent stored source options; open a configured block on the frontend first if its override is missing. No WP Crontrol installation is needed. Refresh the settings page after a run to inspect progress. Missing or unreadable tables disable manual runs; inspect activation and database permissions before proceeding.

The plugin records a bounded, redacted explanation for new HTTP failures. Explanations appear only in the administrator diagnostics panel, not public REST responses or frontend errors. For HTML responses, the panel shows redacted title, heading, first paragraph, and selected identifying headers (content-type, server, via, zotero-api-version). If those HTML elements are absent, a bounded redacted plain-text body is shown instead. Raw HTML, cookies, authorization headers, and redirect locations are not displayed. Old failures need a new manual batch after the retry deadline to capture an explanation.

Outbound Zotero requests use the Zotero-API-Key header. Enter only the API key in settings, without a Bearer prefix or header name; surrounding whitespace is removed before sending.

Operational notes: Clearing publication caches preserves Zotero cooldowns. Backoff and Retry-After deadlines are honored in full, including waits longer than one hour. Plain and pretty permalinks are supported. After rotating WordPress salts or deploying frontend changes, purge page/CDN caches so embedded source signatures and markup are refreshed.

Public API requests are limited to 120 per minute per server-reported client IP, shared across items and authors; editors are exempt. Shared-IP visitors share this allowance. The limiter stores a count under a salted hash of the client IP and minute for up to 60 seconds; it does not store the raw IP. Configure the host to provide the actual client IP behind trusted proxies. The database-only counter is best-effort under concurrency; use host/WAF limits for strong abuse protection. Public requests cannot request the complete author facet; use bounded author autocomplete. Pagination is capped at 10,000 pages. Pending synchronization polling slows progressively to 30 seconds.

Source retention: removing a block does not remove its indexed source because another page or cached block may still use it. Clear Zotero Cache removes all source indexes and they rebuild when rendered; uninstall permanently removes plugin data. Do not use automatic age-based deletion as a substitute for checking source usage.

API credentials: prefer the server-side ZOTERO_DISPLAY_API_KEY constant where hosting permits it. Saved settings are not autoloaded; existing installations are updated when an administrator visits settings.

Readable JavaScript is included in src/ in the ZIP. Developer tooling and tests are available from https://github.com/lwangdu/nature-zotero-publications; clone the repository to run the documented npm tests and build commands.
