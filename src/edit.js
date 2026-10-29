import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	SelectControl,
	RangeControl,
	ToggleControl,
	Spinner,
	Notice,
} from '@wordpress/components';
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * Publication sub-component for the editor preview, mirroring the PHP-rendered
 * frontend markup closely enough to give an accurate WYSIWYG preview.
 *
 * @param {Object}  props              Component properties.
 * @param {Object}  props.item         Normalized Zotero item.
 * @param {boolean} props.showAbstract Whether to show the abstract.
 * @return {Element} Item card preview.
 */
function ItemCard( { item, showAbstract } ) {
	const linkHref = item.external_url || item.zotero_link;
	return (
		<article className="zotero-publication">
			<p className="zotero-publication-authors">{ item.creator_str }</p>
			<h3 className="zotero-publication-title">
				<a href={ linkHref } target="_blank" rel="noopener noreferrer">
					{ item.title }
					<span className="screen-reader-text">
						{ ` (${ __(
							'opens in a new tab',
							'publications-list-display-for-zotero'
						) })` }
					</span>
				</a>{ ' ' }
				<span className="zotero-type-badge">
					{ item.item_type_label }
				</span>
			</h3>
			{ ( item.publication || item.date ) && (
				<p className="zotero-publication-citation">
					{ item.publication &&
						`${ __(
							'In:',
							'publications-list-display-for-zotero'
						) } ${ item.publication }` }
					{ item.publication && item.date ? ', ' : '' }
					{ item.date }.
				</p>
			) }
			<div className="zotero-publication-actions">
				{ showAbstract && item.abstract && (
					<details className="zotero-publication-abstract">
						<summary>
							{ __(
								'Abstract',
								'publications-list-display-for-zotero'
							) }
						</summary>
						<p>{ item.abstract }</p>
					</details>
				) }
				{ item.doi && (
					<a
						href={ item.doi_url }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'DOI', 'publications-list-display-for-zotero' ) }
						<span className="screen-reader-text">
							{ ` (${ __(
								'opens in a new tab',
								'publications-list-display-for-zotero'
							) })` }
						</span>
					</a>
				) }
				{ item.source_url && (
					<a
						href={ item.source_url }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __(
							'Source',
							'publications-list-display-for-zotero'
						) }
						<span className="screen-reader-text">
							{ ` (${ __(
								'opens in a new tab',
								'publications-list-display-for-zotero'
							) })` }
						</span>
					</a>
				) }
				<a
					href={ item.zotero_link }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Zotero', 'publications-list-display-for-zotero' ) }
					<span className="screen-reader-text">
						{ ` (${ __(
							'opens in a new tab',
							'publications-list-display-for-zotero'
						) })` }
					</span>
				</a>
				{ item.citation_key && (
					<span>
						{ __(
							'Citation key:',
							'publications-list-display-for-zotero'
						) }{ ' ' }
						<code>{ item.citation_key }</code>
					</span>
				) }
			</div>
		</article>
	);
}

export default function Edit( { attributes, setAttributes } ) {
	const {
		libraryType,
		libraryId,
		collection,
		sortBy,
		sortDirection,
		itemsPerPage,
		showStats,
		showFilters,
		showSearch,
		showAbstract,
	} = attributes;

	const blockProps = useBlockProps( {
		className: 'zotero-display-block zotero-display-block-editor',
	} );

	const [ data, setData ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( null );

	const previewRequest = useRef( 0 );
	const previewController = useRef( null );

	const fetchPreview = useCallback( () => {
		const request = ++previewRequest.current;
		previewController.current?.abort();
		const controller = new AbortController();
		previewController.current = controller;
		setIsLoading( true );
		setError( null );

		apiFetch( {
			signal: controller.signal,
			path: addQueryArgs( '/zotero-display/v1/items', {
				library_type: libraryType,
				library_id: libraryId,
				collection,
				sort: sortBy,
				direction: sortDirection,
				per_page: itemsPerPage,
				page: 1,
				include_facets: false,
			} ),
		} )
			.then( ( response ) => {
				if ( request !== previewRequest.current ) {
					return;
				}
				setData( response );
				setIsLoading( false );
			} )
			.catch( ( err ) => {
				if (
					request !== previewRequest.current ||
					err.name === 'AbortError'
				) {
					return;
				}
				setError(
					err.message ||
						__(
							'Failed to load Zotero data.',
							'publications-list-display-for-zotero'
						)
				);
				setIsLoading( false );
			} );
	}, [
		libraryType,
		libraryId,
		collection,
		sortBy,
		sortDirection,
		itemsPerPage,
	] );

	const cancelPreview = useCallback( () => {
		++previewRequest.current;
		previewController.current?.abort();
	}, [] );

	useEffect( () => {
		const timeout = setTimeout( fetchPreview, 400 ); // debounce while typing IDs
		return () => {
			clearTimeout( timeout );
			cancelPreview();
		};
	}, [ fetchPreview, cancelPreview ] );

	let emptyMessage = __(
		'No indexed publications yet. View the page to start synchronization, then refresh this preview.',
		'publications-list-display-for-zotero'
	);
	if ( data?.sync?.status === 'syncing' ) {
		emptyMessage = __(
			'Synchronizing publications. Refresh this preview shortly.',
			'publications-list-display-for-zotero'
		);
	} else if ( data?.sync?.status === 'error' ) {
		emptyMessage = __(
			'Synchronization failed. Check the source settings and try again later.',
			'publications-list-display-for-zotero'
		);
	} else if ( data?.sync?.ready ) {
		emptyMessage = __(
			'No items found for this library/collection.',
			'publications-list-display-for-zotero'
		);
	}

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Zotero Source',
						'publications-list-display-for-zotero'
					) }
					initialOpen={ true }
				>
					<SelectControl
						label={ __(
							'Library Type',
							'publications-list-display-for-zotero'
						) }
						value={ libraryType }
						options={ [
							{
								label: __(
									'Use plugin default',
									'publications-list-display-for-zotero'
								),
								value: '',
							},
							{
								label: __(
									'User Library',
									'publications-list-display-for-zotero'
								),
								value: 'user',
							},
							{
								label: __(
									'Group Library',
									'publications-list-display-for-zotero'
								),
								value: 'group',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { libraryType: value } )
						}
					/>
					<TextControl
						label={ __(
							'Library / Group ID (optional override)',
							'publications-list-display-for-zotero'
						) }
						value={ libraryId }
						help={ __(
							'Leave blank to use the plugin-wide default set in Settings → Publications List Display for Zotero.',
							'publications-list-display-for-zotero'
						) }
						onChange={ ( value ) =>
							setAttributes( { libraryId: value } )
						}
					/>
					<TextControl
						label={ __(
							'Collection Key (optional)',
							'publications-list-display-for-zotero'
						) }
						value={ collection }
						help={ __(
							'Limit results to a specific Zotero collection.',
							'publications-list-display-for-zotero'
						) }
						onChange={ ( value ) =>
							setAttributes( { collection: value } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __(
						'Sorting & Layout',
						'publications-list-display-for-zotero'
					) }
					initialOpen={ false }
				>
					<SelectControl
						label={ __(
							'Sort By',
							'publications-list-display-for-zotero'
						) }
						value={ sortBy }
						options={ [
							{
								label: __(
									'Date',
									'publications-list-display-for-zotero'
								),
								value: 'date',
							},
							{
								label: __(
									'Title',
									'publications-list-display-for-zotero'
								),
								value: 'title',
							},
							{
								label: __(
									'Creator',
									'publications-list-display-for-zotero'
								),
								value: 'creator',
							},
							{
								label: __(
									'Date Added',
									'publications-list-display-for-zotero'
								),
								value: 'dateAdded',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { sortBy: value } )
						}
					/>
					<SelectControl
						label={ __(
							'Sort Direction',
							'publications-list-display-for-zotero'
						) }
						value={ sortDirection }
						options={ [
							{
								label: __(
									'Descending',
									'publications-list-display-for-zotero'
								),
								value: 'desc',
							},
							{
								label: __(
									'Ascending',
									'publications-list-display-for-zotero'
								),
								value: 'asc',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { sortDirection: value } )
						}
					/>
					<RangeControl
						label={ __(
							'Items Per Page',
							'publications-list-display-for-zotero'
						) }
						value={ itemsPerPage }
						onChange={ ( value ) =>
							setAttributes( { itemsPerPage: value } )
						}
						min={ 1 }
						max={ 100 }
					/>
				</PanelBody>
				<PanelBody
					title={ __(
						'Display Options',
						'publications-list-display-for-zotero'
					) }
					initialOpen={ false }
				>
					<ToggleControl
						label={ __(
							'Show Stats',
							'publications-list-display-for-zotero'
						) }
						checked={ showStats }
						onChange={ ( value ) =>
							setAttributes( { showStats: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show Search',
							'publications-list-display-for-zotero'
						) }
						checked={ showSearch }
						onChange={ ( value ) =>
							setAttributes( { showSearch: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show Filters',
							'publications-list-display-for-zotero'
						) }
						checked={ showFilters }
						onChange={ ( value ) =>
							setAttributes( { showFilters: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show Abstract',
							'publications-list-display-for-zotero'
						) }
						checked={ showAbstract }
						onChange={ ( value ) =>
							setAttributes( { showAbstract: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ ! libraryId && (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'Using the plugin-wide default library (set in Settings → Publications List Display for Zotero). Set a Library ID above to override it for this block.',
							'publications-list-display-for-zotero'
						) }
					</Notice>
				) }

				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }

				{ isLoading && (
					<div className="zotero-display-loading">
						<Spinner />
						<span>
							{ __(
								'Loading Zotero data…',
								'publications-list-display-for-zotero'
							) }
						</span>
					</div>
				) }

				{ ! isLoading && data && (
					<>
						{ showStats && (
							<div
								className="zotero-display-stats"
								role="status"
								aria-live="polite"
								aria-atomic="true"
							>
								<div className="zotero-stat">
									<span className="zotero-stat-value">
										{ data.stats.total_items }
									</span>
									<span className="zotero-stat-label">
										{ __(
											'entries',
											'publications-list-display-for-zotero'
										) }
									</span>
								</div>
							</div>
						) }

						{ ( showSearch || showFilters ) && (
							<div className="zotero-display-controls zotero-display-controls-preview">
								{ showSearch && (
									<input
										type="search"
										className="zotero-display-search"
										placeholder={ __(
											'Search publications…',
											'publications-list-display-for-zotero'
										) }
										disabled
									/>
								) }
								{ showFilters && (
									<div className="zotero-filter-row">
										<select
											className="zotero-display-filter"
											disabled
										>
											<option>
												{ __(
													'All years',
													'publications-list-display-for-zotero'
												) }
											</option>
										</select>
										<select
											className="zotero-display-filter"
											disabled
										>
											<option>
												{ __(
													'All types',
													'publications-list-display-for-zotero'
												) }
											</option>
										</select>
										<select
											className="zotero-display-filter"
											disabled
										>
											<option>
												{ __(
													'All authors',
													'publications-list-display-for-zotero'
												) }
											</option>
										</select>
									</div>
								) }
							</div>
						) }

						<div className="zotero-publication-list">
							{ data.items.length === 0 && (
								<p className="zotero-display-empty-preview">
									{ emptyMessage }
								</p>
							) }
							{ data.items.map( ( item ) => (
								<ItemCard
									key={ item.key }
									item={ item }
									showAbstract={ showAbstract }
								/>
							) ) }
						</div>

						{ data.pagination.total_pages > 1 && (
							<p className="zotero-display-pagination-preview-note">
								{ __(
									'Pagination is interactive on the published page.',
									'publications-list-display-for-zotero'
								) }{ ' ' }
								({ data.pagination.total_pages }{ ' ' }
								{ __(
									'pages total',
									'publications-list-display-for-zotero'
								) }
								)
							</p>
						) }
					</>
				) }
			</div>
		</>
	);
}
