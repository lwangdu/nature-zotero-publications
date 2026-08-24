=== Nature Zotero Publications ===
Contributors: lwangdu
Tags: zotero, bibliography, citations, gutenberg, publications
Requires at least: 6.4
Tested up to: 7.1
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Display a searchable and filterable Zotero bibliography with authors, dates, DOI links, citation keys, tags, and pagination.

== Description ==

Nature Zotero Publications displays live Zotero library data in a dynamic Gutenberg block. Publications are grouped by year and can include authors, linked titles, publication details, item-type badges, dates, DOI links, citation keys, source URLs, tags, and abstracts.

The block includes search, year/type/author filters, entry counts, and pagination. Initial results are rendered on the server and progressively enhanced in the browser.

Zotero API responses are cached with WordPress transients. Private API keys remain on the server and are never included in block markup or REST responses.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/nature-zotero-publications`, or install the release ZIP through the WordPress Plugins screen.
2. Activate Nature Zotero Publications.
3. Open Settings > Nature Zotero Publications.
4. Enter the Zotero library type, library ID, optional private API key, and cache duration.
5. Add the Nature Zotero Publications block to a post or page.

== Frequently Asked Questions ==

= Is a Zotero API key required? =

An API key is required for private libraries. Public Zotero libraries can be displayed without one.

= Is the private API key exposed to website visitors? =

No. The key is used only for server-side requests and is not returned by the plugin's REST endpoint.

= Can a block display a specific collection? =

Yes. Enter a Zotero collection key in the block settings to limit results to that collection.

== Changelog ==

= 1.0.0 =

* Initial release.
