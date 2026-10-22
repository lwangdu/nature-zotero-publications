/**
 * Build REST URLs without losing plain-permalink routing or existing parameters.
 * @param {Object}          context  Block context.
 * @param {string}          endpoint Endpoint name.
 * @param {URLSearchParams} params   Request parameters.
 */
export function endpointUrl( context, endpoint, params ) {
	const url = new URL( context[ `${ endpoint }Url` ] || context.restUrl );
	// Support page-cached markup produced before full endpoint URLs were added.
	if ( ! context[ `${ endpoint }Url` ] ) {
		if ( url.searchParams.has( 'rest_route' ) ) {
			url.searchParams.set(
				'rest_route',
				`${ url.searchParams
					.get( 'rest_route' )
					.replace( /\/$/, '' ) }/${ endpoint }`
			);
		} else {
			url.pathname = `${ url.pathname.replace(
				/\/$/,
				''
			) }/${ endpoint }`;
		}
	}
	for ( const [ key, value ] of params ) {
		url.searchParams.set( key, value );
	}
	return url.toString();
}
