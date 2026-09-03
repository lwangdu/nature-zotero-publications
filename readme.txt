=== Nature Zotero Publications ===
Contributors: lwangdu
Tags: zotero, bibliography, citations, gutenberg, publications
Requires at least: 6.5
Tested up to: 7.1
Stable tag: 1.0.6
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Display a searchable and filterable Zotero bibliography with authors, dates, DOI links, citation keys, and pagination.

== Description ==

Nature Zotero Publications displays live Zotero library data in a dynamic Gutenberg block. Publications are grouped by year and can include authors, linked titles, publication details, item-type badges, dates, DOI links, citation keys, source URLs, and abstracts. Tags remain searchable but are not displayed on publication cards.

The block includes search, year/type/author filters, entry counts, and pagination. Initial results are rendered on the server and progressively enhanced with the WordPress Interactivity API. Author suggestions are requested only after a visitor types at least two characters, avoiding a large inline author list.

The plugin synchronizes every top-level item from the configured Zotero library or collection into a local WordPress index using resumable background batches. Search, filters, counts, and pagination use the local index. During the first synchronization, already-indexed publications and controls remain available alongside an accessible progress indicator.

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

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/nature-zotero-publications`, or install the release ZIP through the WordPress Plugins screen.
2. Activate Nature Zotero Publications.
3. Open Settings > Nature Zotero Publications.
4. Enter the Zotero library type, library ID, optional private API key, and cache duration.
5. Add the Nature Zotero Publications block to a post or page.

Large libraries synchronize in background batches through WordPress Cron. The last completed data remains available while a later refresh is running.

== Frequently Asked Questions ==

= Is a Zotero API key required? =

An API key is required for private libraries. Public Zotero libraries can be displayed without one.

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

Open Settings > Nature Zotero Publications and select Clear Zotero Cache. This clears transient responses and the local index; the next page request schedules a new synchronization.

= Why does Zotero return a 403 error? =

Make sure User Library or Group Library matches the numeric ID. Private user or group libraries also require an API key with read permission for that library.

== Changelog ==

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
