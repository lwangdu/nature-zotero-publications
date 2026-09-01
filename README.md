# Nature Zotero Publications

Nature Zotero Publications is a dynamic WordPress block that displays a complete Zotero user or group library as a searchable, filterable bibliography.

Current version: **1.0.3**

## Requirements

- WordPress 6.5 or newer
- PHP 7.4 or newer
- A numeric Zotero user ID or group ID
- A Zotero API key for private libraries; public libraries do not require one
- Working WordPress Cron so large libraries can synchronize in background batches

## Features

- User libraries, group libraries, and individual Zotero collections
- Complete-library synchronization beyond Zotero's 100-item response limit
- Server-rendered initial results for SEO and no-JavaScript access
- WordPress Interactivity API search, filters, counts, and pagination
- Year and item-type filters
- Accessible, on-demand author autocomplete
- Linked titles, authors, publication details, item-type badges, dates, DOI, source and Zotero links, citation keys, tags, and expandable abstracts
- Configurable sorting, items per page, statistics, filters, search, and abstracts
- Local querying after synchronization, without requesting Zotero again for every visitor interaction
- Server-only handling of private Zotero API keys

## How synchronization works

Zotero limits an API response to 100 items. The plugin uses a resumable background synchronization system in `includes/class-sync.php`:

1. The block schedules synchronization for its configured library or collection.
2. WordPress Cron downloads top-level Zotero items in 100-item batches.
3. Normalized items and creators are stored in plugin-owned local index tables.
4. A newly completed generation replaces the previous generation only after every batch succeeds.
5. The completed index powers search, filters, entry counts, and pagination.

Visitors continue to see the last completed generation while a refresh is running. During the first synchronization, the page displays already-indexed publications together with an accessible progress indicator and automatically reloads when the complete index is ready. Search, filters, pagination, and author suggestions work with the available partial index. Visitor page and REST requests never download a synchronous 2,000-item fallback.

Version 1.0.2 stores each Zotero page with short multi-row writes and processes one page per background run. This substantially reduces write-lock time on SQLite-backed WordPress Studio sites while remaining compatible with MySQL.

The cache-duration setting controls when a completed source becomes eligible for another background refresh. Changing connection settings or selecting **Clear Zotero Cache** removes both transient data and the local index so the source can synchronize again.

## Installation and configuration

1. Upload the plugin to `/wp-content/plugins/nature-zotero-publications`, or install the release ZIP in WordPress.
2. Activate **Nature Zotero Publications**.
3. Open **Settings → Nature Zotero Publications**.
4. Select the default library type: **User Library** or **Group Library**.
5. Enter the numeric Zotero user ID or group ID.
6. Enter an API key only when the library is private.
7. Choose the cache duration in minutes and save the settings.
8. Add the **Nature Zotero Publications** block to a post or page.

The numeric group ID appears in a Zotero group URL. A Zotero user ID and API key can be found or created at [zotero.org/settings/keys](https://www.zotero.org/settings/keys).

## Block settings

Each block can use the plugin defaults or override them with its own:

- Library type
- Library or group ID
- Collection key
- Sort field and direction
- Items per page
- Entry-count display
- Search field
- Filters
- Abstract display

Changing a block's library, collection, or sorting configuration creates a distinct source configuration and cache key.

## Frontend performance and accessibility

The first result page, year options, and item-type options are rendered by PHP. The [WordPress Interactivity API](https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/) progressively enhances the controls without replacing the initial server-rendered content.

The complete author index is not embedded in page HTML. After a visitor enters at least two characters, the browser requests a small set of author suggestions from the local creator index. The autocomplete supports mouse use and keyboard navigation with Arrow Down, Enter, Space, and Escape.

Search, filter, and pagination requests omit the large facet collections they do not need. Small initial facets are cached briefly, and the private Zotero key is never placed in block context or browser requests.

## REST endpoints

The plugin provides two public, read-only WordPress REST routes:

- `GET /wp-json/zotero-display/v1/items` returns normalized, paginated publications from the completed local index.
- `GET /wp-json/zotero-display/v1/authors` returns a limited set of author suggestions after at least two search characters.

These routes identify the library or collection but never return or accept the saved private API key. Zotero requests are performed only on the server.

## Publication data

The plugin displays Zotero's native `citationKey` value when present. For older Better BibTeX records, it also recognizes a `Citation Key:` value in the Extra field.

Child attachments and annotations are excluded. Top-level standalone attachments remain visible so the total aligns more closely with Zotero's **items in this view** count. Untitled top-level records receive an **Untitled** display label.

General search covers titles, authors, dates, DOI values, citation keys, abstracts, publications, and tags. Publication titles link to their best available external URL and fall back to the Zotero item page.

## Development

Install dependencies and create a production build:

```bash
npm install
npm run build
```

Other useful commands:

```bash
npm run start
npm run lint:js
npm run lint:css
npm run plugin-zip
```

The build uses `wp-scripts build --experimental-modules` so `viewScriptModule` can load the Interactivity API frontend module. `npm run plugin-zip` creates the WordPress-ready distribution archive.

## Project structure

```text
nature-zotero-publications.php Plugin bootstrap, version, activation hooks
includes/
  class-zotero-api.php          Zotero API client and transient fallback
  class-sync.php                Background synchronization and local index
  class-settings.php            Settings page and cache controls
  class-rest-controller.php     Publication and author REST routes
  class-block.php                Dynamic block registration and rendering
src/
  block.json                    Block metadata and Interactivity support
  index.js                      Block editor registration
  edit.js                       Inspector controls and editor preview
  frontend.js                   Interactivity API store and actions
  style.scss                    Shared frontend and editor styles
  editor.scss                   Editor-only styles
build/                          Production assets generated by npm run build
```

## Troubleshooting

### The full library has not appeared yet

Large libraries require multiple background requests. Visit the page once to ensure the source is scheduled, confirm WordPress Cron can run, and allow the synchronization to finish. The page reports first-sync progress and reloads automatically when ready; existing completed data remains visible during later refreshes.

### The Zotero API returns 403

Confirm that the selected library type matches the ID. For a group URL, use **Group Library** and the numeric group ID. Private groups also require a Zotero key with permission to read that group.

### Search or filters show old data

Open **Settings → Nature Zotero Publications** and select **Clear Zotero Cache**. This removes cached responses and the completed local index; the next page request starts a new synchronization.

### The displayed count differs from Zotero

Compare against Zotero's top-level **items in this view** count. Child attachments and annotations are intentionally excluded, while standalone attachments are included.

## Changelog

### 1.0.3

- Displayed already-indexed publications during the first full-library synchronization.
- Kept accessible progress and automatic reload while partial search, filters, pagination, and author suggestions remain available.

### 1.0.2

- Removed the synchronous 2,000-item visitor fallback.
- Added accessible first-sync progress with automatic completion reload.
- Replaced row-by-row index writes with short multi-row writes.
- Limited each background event to one 100-item Zotero page to reduce SQLite write contention.

### 1.0.1

- Added resumable background synchronization for complete Zotero libraries.
- Added a generation-based local publication and creator index.
- Migrated frontend interactions to the WordPress Interactivity API.
- Added accessible, on-demand author autocomplete.
- Reduced initial page and REST payload sizes by removing the inline author facet.
- Raised the minimum WordPress version to 6.5.

### 1.0.0

- Initial release.
