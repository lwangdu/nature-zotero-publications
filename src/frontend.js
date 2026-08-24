/**
 * Frontend behavior for the Nature Zotero Publications block.
 *
 * The block is server-rendered for the initial view (SEO, no-JS support).
 * This script enhances it with interactive search, filtering, and pagination
 * by calling the plugin's REST endpoint, which itself reads from the same
 * WordPress transient cache as the PHP render — so no extra Zotero API hits.
 */
( function () {
	'use strict';

	function debounce( fn, wait ) {
		let t;
		return function ( ...args ) {
			clearTimeout( t );
			t = setTimeout( () => fn.apply( this, args ), wait );
		};
	}

	function escapeHtml( str ) {
		const div = document.createElement( 'div' );
		div.textContent = str === null || str === undefined ? '' : str;
		return div.innerHTML;
	}

	function renderPublication( item, showAbstract ) {
		const linkHref = item.external_url || item.zotero_link;
		const newTabLabel = `<span class="screen-reader-text"> (${ escapeHtml(
			window.zoteroDisplaySettings.newTabLabel || 'opens in a new tab'
		) })</span>`;
		const actionHtml = [
			showAbstract && item.abstract
				? `<details class="zotero-publication-abstract"><summary>Abstract</summary><p>${ escapeHtml(
						item.abstract
				  ) }</p></details>`
				: '',
			item.doi
				? `<a href="${ escapeHtml(
						item.doi_url
				  ) }" target="_blank" rel="noopener noreferrer">DOI${ newTabLabel }</a>`
				: '',
			item.source_url
				? `<a href="${ escapeHtml(
						item.source_url
				  ) }" target="_blank" rel="noopener noreferrer">Source${ newTabLabel }</a>`
				: '',
			`<a href="${ escapeHtml(
				item.zotero_link
			) }" target="_blank" rel="noopener noreferrer">Zotero${ newTabLabel }</a>`,
			item.citation_key
				? `<span>Citation key: <code>${ escapeHtml(
						item.citation_key
				  ) }</code></span>`
				: '',
		].join( '' );
		const citationParts = [
			item.publication ? `In: ${ item.publication }` : '',
			item.date || '',
		].filter( Boolean );
		const citationHtml = citationParts.length
			? `<p class="zotero-publication-citation">${ escapeHtml(
					citationParts.join( ', ' )
			  ) }.</p>`
			: '';
		const tagsHtml = item.tags.length
			? `<p class="zotero-publication-tags"><span>Tags:</span> ${ escapeHtml(
					item.tags.join( ', ' )
			  ) }</p>`
			: '';

		return `
			<article class="zotero-publication" data-zotero-item-type="${ escapeHtml(
				item.item_type
			) }" data-zotero-item-year="${ escapeHtml( item.date_year ) }">
				<p class="zotero-publication-authors">${ escapeHtml( item.creator_str ) }</p>
				<h3 class="zotero-publication-title">
					<a href="${ escapeHtml(
						linkHref
					) }" target="_blank" rel="noopener noreferrer">${ escapeHtml(
						item.title
					) }${ newTabLabel }</a> <span class="zotero-type-badge">${ escapeHtml(
						item.item_type_label
					) }</span>
				</h3>
				${ citationHtml }
				<div class="zotero-publication-actions">${ actionHtml }</div>
				${ tagsHtml }
			</article>
		`;
	}

	function renderItems( items, showAbstract ) {
		let currentYear = null;
		return items
			.map( ( item ) => {
				const year = item.date_year || 'Undated';
				const heading =
					year !== currentYear
						? `<h2 class="zotero-year-heading">${ escapeHtml(
								year
						  ) }</h2>`
						: '';
				currentYear = year;
				return heading + renderPublication( item, showAbstract );
			} )
			.join( '' );
	}

	function initBlock( root ) {
		const grid = root.querySelector( '[data-zotero-grid]' );
		const emptyEl = root.querySelector( '[data-zotero-empty]' );
		const searchEl = root.querySelector( '[data-zotero-search]' );
		const typeEl = root.querySelector( '[data-zotero-filter-type]' );
		const yearEl = root.querySelector( '[data-zotero-filter-year]' );
		const authorEl = root.querySelector( '[data-zotero-filter-author]' );
		const pagination = root.querySelector( '[data-zotero-pagination]' );
		const prevBtn = root.querySelector( '[data-zotero-page-prev]' );
		const nextBtn = root.querySelector( '[data-zotero-page-next]' );
		const currentPageEl = root.querySelector(
			'[data-zotero-current-page]'
		);
		const totalPagesEl = root.querySelector( '[data-zotero-total-pages]' );
		const statsEl = root.querySelector( '[data-zotero-stats]' );
		const facetsEl = root.querySelector( '[data-zotero-facets]' );

		const config = {
			libraryType: root.dataset.libraryType || '',
			libraryId: root.dataset.libraryId || '',
			collection: root.dataset.collection || '',
			sortBy: root.dataset.sortBy || 'date',
			sortDirection: root.dataset.sortDirection || 'desc',
			perPage: parseInt( root.dataset.itemsPerPage, 10 ) || 10,
			showAbstract: root.dataset.showAbstract === '1',
		};

		const state = {
			page: 1,
			search: '',
			filterType: '',
			filterYear: '',
			filterAuthor: '',
		};

		function buildFacetOptions( selectEl, facets ) {
			if ( ! selectEl || ! Array.isArray( facets ) ) {
				return;
			}

			facets.forEach( ( facet ) => {
				const option = document.createElement( 'option' );
				option.value = facet.value;
				option.textContent = `${ facet.label || facet.value } (${
					facet.count
				})`;
				selectEl.appendChild( option );
			} );
		}

		if ( facetsEl ) {
			try {
				const facets = JSON.parse( facetsEl.textContent );
				buildFacetOptions( typeEl, facets.available_types );
				buildFacetOptions( yearEl, facets.available_years );

				let authorOptionsBuilt = false;
				const buildAuthorOptions = () => {
					if ( authorOptionsBuilt ) {
						return;
					}
					authorOptionsBuilt = true;
					buildFacetOptions( authorEl, facets.available_authors );
				};

				authorEl?.addEventListener( 'focus', buildAuthorOptions );
				authorEl?.addEventListener( 'pointerdown', buildAuthorOptions );
			} catch {
				// Keep the default filter options when embedded data is malformed.
			}
		}

		function fetchAndRender() {
			const params = new URLSearchParams( {
				library_type: config.libraryType,
				library_id: config.libraryId,
				collection: config.collection,
				sort: config.sortBy,
				direction: config.sortDirection,
				per_page: config.perPage,
				page: state.page,
				search: state.search,
				filter_type: state.filterType,
				filter_year: state.filterYear,
				filter_author: state.filterAuthor,
				include_facets: '0',
			} );

			grid.setAttribute( 'aria-busy', 'true' );

			const requestOptions = {};
			if ( window.zoteroDisplaySettings.nonce ) {
				requestOptions.headers = {
					'X-WP-Nonce': window.zoteroDisplaySettings.nonce,
				};
			}

			fetch(
				`${
					window.zoteroDisplaySettings.restUrl
				}items?${ params.toString() }`,
				requestOptions
			)
				.then( ( res ) => {
					if ( ! res.ok ) {
						throw new Error( 'Request failed' );
					}
					return res.json();
				} )
				.then( ( data ) => {
					grid.removeAttribute( 'aria-busy' );

					// Render publication entries.
					if ( data.items.length === 0 ) {
						grid.innerHTML = '';
						if ( emptyEl ) {
							emptyEl.hidden = false;
						}
					} else {
						if ( emptyEl ) {
							emptyEl.hidden = true;
						}
						grid.innerHTML = renderItems(
							data.items,
							config.showAbstract
						);
					}

					// Update stats.
					if ( statsEl ) {
						const values =
							statsEl.querySelectorAll( '.zotero-stat-value' );
						if ( values[ 0 ] ) {
							values[ 0 ].textContent = data.stats.total_items;
						}
						if ( values[ 1 ] ) {
							values[ 1 ].textContent = data.stats.total_types;
						}
					}

					// Update pagination.
					if ( pagination ) {
						const totalPages = data.pagination.total_pages;
						pagination.dataset.totalPages = totalPages;
						pagination.dataset.currentPage = state.page;
						pagination.hidden = totalPages <= 1;
						if ( currentPageEl ) {
							currentPageEl.textContent = state.page;
						}
						if ( totalPagesEl ) {
							totalPagesEl.textContent = totalPages;
						}
						if ( prevBtn ) {
							prevBtn.disabled = state.page <= 1;
						}
						if ( nextBtn ) {
							nextBtn.disabled = state.page >= totalPages;
						}
					}
				} )
				.catch( () => {
					grid.removeAttribute( 'aria-busy' );
					if ( emptyEl ) {
						emptyEl.hidden = false;
						emptyEl.textContent = 'Unable to load items right now.';
					}
				} );
		}

		if ( searchEl ) {
			searchEl.addEventListener(
				'input',
				debounce( ( e ) => {
					state.search = e.target.value;
					state.page = 1;
					fetchAndRender();
				}, 350 )
			);
		}

		if ( typeEl ) {
			typeEl.addEventListener( 'change', ( e ) => {
				state.filterType = e.target.value;
				state.page = 1;
				fetchAndRender();
			} );
		}

		if ( yearEl ) {
			yearEl.addEventListener( 'change', ( e ) => {
				state.filterYear = e.target.value;
				state.page = 1;
				fetchAndRender();
			} );
		}

		if ( authorEl ) {
			authorEl.addEventListener( 'change', ( e ) => {
				state.filterAuthor = e.target.value;
				state.page = 1;
				fetchAndRender();
			} );
		}

		if ( prevBtn ) {
			prevBtn.addEventListener( 'click', () => {
				if ( state.page > 1 ) {
					state.page -= 1;
					fetchAndRender();
				}
			} );
		}

		if ( nextBtn ) {
			nextBtn.addEventListener( 'click', () => {
				const totalPages =
					parseInt( pagination?.dataset.totalPages, 10 ) || 1;
				if ( state.page < totalPages ) {
					state.page += 1;
					fetchAndRender();
				}
			} );
		}

		// The first page and filter facets are server-rendered. Defer REST work
		// until the visitor searches, filters, or changes pages.
	}

	function init() {
		document
			.querySelectorAll( '[data-zotero-display="true"]' )
			.forEach( initBlock );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
