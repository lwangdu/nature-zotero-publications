/**
 * Interactivity API behavior for publication search, filters, and pagination.
 */

import { getContext, store, withSyncEvent } from '@wordpress/interactivity';

const delay = ( milliseconds ) =>
	new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );

function sourceParams( context ) {
	return {
		library_type: context.libraryType,
		library_id: context.libraryId,
		collection: context.collection,
		sort: context.sortBy,
		direction: context.sortDirection,
	};
}

function prepareItems( items, context ) {
	let currentYear = null;

	return items.map( ( item ) => {
		const yearLabel = item.date_year || context.undatedLabel;
		const showYearHeading = yearLabel !== currentYear;
		const citationParts = [
			item.publication
				? `${ context.inLabel } ${ item.publication }`
				: '',
			item.date || '',
		].filter( Boolean );
		currentYear = yearLabel;

		return {
			...item,
			linkHref: item.external_url || item.zotero_link,
			yearLabel,
			showYearHeading,
			showAbstract: Boolean( context.showAbstract && item.abstract ),
			citationText: citationParts.length
				? `${ citationParts.join( ', ' ) }.`
				: '',
			tagsText: Array.isArray( item.tags ) ? item.tags.join( ', ' ) : '',
		};
	} );
}

function* fetchItems( context, requestId ) {
	context.isLoading = true;

	const params = new URLSearchParams( {
		...sourceParams( context ),
		per_page: context.perPage,
		page: context.page,
		search: context.search,
		filter_type: context.filterType,
		filter_year: context.filterYear,
		filter_author: context.filterAuthor,
		include_facets: '0',
	} );

	try {
		const response = yield fetch(
			`${ context.restUrl }items?${ params.toString() }`
		);
		if ( ! response.ok ) {
			throw new Error( 'Request failed' );
		}

		const data = yield response.json();
		if ( requestId !== context.requestId ) {
			return;
		}

		context.items = prepareItems( data.items, context );
		context.hasFetched = true;
		context.totalItems = data.pagination.total_items;
		context.totalPages = data.pagination.total_pages;
		context.isEmpty = data.items.length === 0;
		context.message = context.noResultsMessage;
	} catch {
		if ( requestId === context.requestId ) {
			context.hasFetched = true;
			context.items = [];
			context.isEmpty = true;
			context.message = context.errorMessage;
		}
	} finally {
		if ( requestId === context.requestId ) {
			context.isLoading = false;
		}
	}
}

function* requestItems( context ) {
	const requestId = ++context.requestId;
	yield* fetchItems( context, requestId );
}

store( 'zotero-display', {
	state: {
		get isPaginationHidden() {
			return getContext().totalPages <= 1;
		},
		get isPreviousDisabled() {
			const context = getContext();
			return context.isLoading || context.page <= 1;
		},
		get isNextDisabled() {
			const context = getContext();
			return context.isLoading || context.page >= context.totalPages;
		},
	},
	actions: {
		search: withSyncEvent( function* ( event ) {
			const context = getContext();
			context.search = event.target.value;
			context.page = 1;
			const requestId = ++context.requestId;

			yield delay( 350 );
			if ( requestId === context.requestId ) {
				yield* fetchItems( context, requestId );
			}
		} ),
		filterType: withSyncEvent( function* ( event ) {
			const context = getContext();
			context.filterType = event.target.value;
			context.page = 1;
			yield* requestItems( context );
		} ),
		filterYear: withSyncEvent( function* ( event ) {
			const context = getContext();
			context.filterYear = event.target.value;
			context.page = 1;
			yield* requestItems( context );
		} ),
		searchAuthors: withSyncEvent( function* ( event ) {
			const context = getContext();
			const query = event.target.value;
			context.authorQuery = query;
			const requestId = ++context.authorRequestId;

			if ( context.filterAuthor && query !== context.filterAuthor ) {
				context.filterAuthor = '';
				context.page = 1;
				yield* requestItems( context );
			}

			if ( query.trim().length < 2 ) {
				context.authorSuggestions = [];
				context.authorOpen = false;
				context.activeAuthorIndex = -1;
				return;
			}

			yield delay( 250 );
			if ( requestId !== context.authorRequestId ) {
				return;
			}

			const params = new URLSearchParams( {
				...sourceParams( context ),
				search: query,
				limit: '30',
			} );

			try {
				const response = yield fetch(
					`${ context.restUrl }authors?${ params.toString() }`
				);
				if ( ! response.ok ) {
					throw new Error( 'Request failed' );
				}
				const data = yield response.json();
				if ( requestId === context.authorRequestId ) {
					context.authorSuggestions = data.authors;
					context.authorOpen = data.authors.length > 0;
					context.activeAuthorIndex = -1;
				}
			} catch {
				if ( requestId === context.authorRequestId ) {
					context.authorSuggestions = [];
					context.authorOpen = false;
				}
			}
		} ),
		authorKeydown: withSyncEvent( function ( event ) {
			const context = getContext();
			if ( event.key === 'Escape' ) {
				context.authorOpen = false;
				return;
			}

			if ( event.key === 'ArrowDown' && context.authorOpen ) {
				const root = event.currentTarget.closest(
					'[data-wp-interactive="zotero-display"]'
				);
				const firstOption = root?.querySelector(
					'.zotero-author-results [role="option"]'
				);
				if ( firstOption ) {
					event.preventDefault();
					firstOption.focus();
				}
			}
		} ),
		*selectAuthor() {
			const context = getContext();
			context.filterAuthor = context.author.value;
			context.authorQuery = context.author.value;
			context.authorOpen = false;
			context.page = 1;
			yield* requestItems( context );
		},
		selectAuthorKeydown: withSyncEvent( function* ( event ) {
			if ( event.key !== 'Enter' && event.key !== ' ' ) {
				return;
			}

			event.preventDefault();
			const context = getContext();
			context.filterAuthor = context.author.value;
			context.authorQuery = context.author.value;
			context.authorOpen = false;
			context.page = 1;
			yield* requestItems( context );
		} ),
		*previousPage() {
			const context = getContext();
			if ( context.page > 1 && ! context.isLoading ) {
				--context.page;
				yield* requestItems( context );
			}
		},
		*nextPage() {
			const context = getContext();
			if ( context.page < context.totalPages && ! context.isLoading ) {
				++context.page;
				yield* requestItems( context );
			}
		},
	},
} );
