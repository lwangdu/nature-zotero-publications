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
import { useState, useEffect, useCallback } from '@wordpress/element';
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
							'nature-zotero-publications'
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
						`${ __( 'In:', 'nature-zotero-publications' ) } ${
							item.publication
						}` }
					{ item.publication && item.date ? ', ' : '' }
					{ item.date }.
				</p>
			) }
			<div className="zotero-publication-actions">
				{ showAbstract && item.abstract && (
					<details className="zotero-publication-abstract">
						<summary>
							{ __( 'Abstract', 'nature-zotero-publications' ) }
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
						{ __( 'DOI', 'nature-zotero-publications' ) }
						<span className="screen-reader-text">
							{ ` (${ __(
								'opens in a new tab',
								'nature-zotero-publications'
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
						{ __( 'Source', 'nature-zotero-publications' ) }
						<span className="screen-reader-text">
							{ ` (${ __(
								'opens in a new tab',
								'nature-zotero-publications'
							) })` }
						</span>
					</a>
				) }
				<a
					href={ item.zotero_link }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Zotero', 'nature-zotero-publications' ) }
					<span className="screen-reader-text">
						{ ` (${ __(
							'opens in a new tab',
							'nature-zotero-publications'
						) })` }
					</span>
				</a>
				{ item.citation_key && (
					<span>
						{ __( 'Citation key:', 'nature-zotero-publications' ) }{ ' ' }
						<code>{ item.citation_key }</code>
					</span>
				) }
			</div>
			{ item.tags.length > 0 && (
				<p className="zotero-publication-tags">
					<span>{ __( 'Tags:', 'nature-zotero-publications' ) }</span>{ ' ' }
					{ item.tags.join( ', ' ) }
				</p>
			) }
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

	const fetchPreview = useCallback( () => {
		if ( ! libraryId ) {
			setData( null );
			setError( null );
			return;
		}
		setIsLoading( true );
		setError( null );

		apiFetch( {
			path: addQueryArgs( '/zotero-display/v1/items', {
				library_type: libraryType,
				library_id: libraryId,
				collection,
				sort: sortBy,
				direction: sortDirection,
				per_page: itemsPerPage,
				page: 1,
			} ),
		} )
			.then( ( response ) => {
				setData( response );
				setIsLoading( false );
			} )
			.catch( ( err ) => {
				setError(
					err.message ||
						__(
							'Failed to load Zotero data.',
							'nature-zotero-publications'
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

	useEffect( () => {
		const timeout = setTimeout( fetchPreview, 400 ); // debounce while typing IDs
		return () => clearTimeout( timeout );
	}, [ fetchPreview ] );

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Zotero Source',
						'nature-zotero-publications'
					) }
					initialOpen={ true }
				>
					<SelectControl
						label={ __(
							'Library Type',
							'nature-zotero-publications'
						) }
						value={ libraryType }
						options={ [
							{
								label: __(
									'Use plugin default',
									'nature-zotero-publications'
								),
								value: '',
							},
							{
								label: __(
									'User Library',
									'nature-zotero-publications'
								),
								value: 'user',
							},
							{
								label: __(
									'Group Library',
									'nature-zotero-publications'
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
							'nature-zotero-publications'
						) }
						value={ libraryId }
						help={ __(
							'Leave blank to use the plugin-wide default set in Settings → Nature Zotero Publications.',
							'nature-zotero-publications'
						) }
						onChange={ ( value ) =>
							setAttributes( { libraryId: value } )
						}
					/>
					<TextControl
						label={ __(
							'Collection Key (optional)',
							'nature-zotero-publications'
						) }
						value={ collection }
						help={ __(
							'Limit results to a specific Zotero collection.',
							'nature-zotero-publications'
						) }
						onChange={ ( value ) =>
							setAttributes( { collection: value } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __(
						'Sorting & Layout',
						'nature-zotero-publications'
					) }
					initialOpen={ false }
				>
					<SelectControl
						label={ __( 'Sort By', 'nature-zotero-publications' ) }
						value={ sortBy }
						options={ [
							{
								label: __(
									'Date',
									'nature-zotero-publications'
								),
								value: 'date',
							},
							{
								label: __(
									'Title',
									'nature-zotero-publications'
								),
								value: 'title',
							},
							{
								label: __(
									'Creator',
									'nature-zotero-publications'
								),
								value: 'creator',
							},
							{
								label: __(
									'Date Added',
									'nature-zotero-publications'
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
							'nature-zotero-publications'
						) }
						value={ sortDirection }
						options={ [
							{
								label: __(
									'Descending',
									'nature-zotero-publications'
								),
								value: 'desc',
							},
							{
								label: __(
									'Ascending',
									'nature-zotero-publications'
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
							'nature-zotero-publications'
						) }
						value={ itemsPerPage }
						onChange={ ( value ) =>
							setAttributes( { itemsPerPage: value } )
						}
						min={ 1 }
						max={ 50 }
					/>
				</PanelBody>
				<PanelBody
					title={ __(
						'Display Options',
						'nature-zotero-publications'
					) }
					initialOpen={ false }
				>
					<ToggleControl
						label={ __(
							'Show Stats',
							'nature-zotero-publications'
						) }
						checked={ showStats }
						onChange={ ( value ) =>
							setAttributes( { showStats: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show Search',
							'nature-zotero-publications'
						) }
						checked={ showSearch }
						onChange={ ( value ) =>
							setAttributes( { showSearch: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show Filters',
							'nature-zotero-publications'
						) }
						checked={ showFilters }
						onChange={ ( value ) =>
							setAttributes( { showFilters: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show Abstract',
							'nature-zotero-publications'
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
							'Using the plugin-wide default library (set in Settings → Nature Zotero Publications). Set a Library ID above to override it for this block.',
							'nature-zotero-publications'
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
								'nature-zotero-publications'
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
											'nature-zotero-publications'
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
											'nature-zotero-publications'
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
													'nature-zotero-publications'
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
													'nature-zotero-publications'
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
													'nature-zotero-publications'
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
									{ __(
										'No items found for this library/collection.',
										'nature-zotero-publications'
									) }
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
									'nature-zotero-publications'
								) }{ ' ' }
								({ data.pagination.total_pages }{ ' ' }
								{ __(
									'pages total',
									'nature-zotero-publications'
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
