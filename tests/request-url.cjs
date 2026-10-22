const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
(async () => {
 const source = fs.readFileSync( path.join( __dirname, '../src/request-url.js' ), 'utf8' );
 const { endpointUrl } = await import( `data:text/javascript;base64,${ Buffer.from( source ).toString( 'base64' ) }` );
 for ( const endpoint of [ 'items', 'authors' ] ) {
  for ( const plain of [ true, false ] ) {
   for ( const legacy of [ true, false ] ) {
    const route = '/zotero-display/v1/' + ( legacy ? '' : endpoint );
    const base = plain ? `https://example.org/sub/?rest_route=${ encodeURIComponent( route ) }&lang=en` : `https://example.org/sub/wp-json${ route }?lang=en`;
    const context = legacy ? { restUrl: base } : { [ `${ endpoint }Url` ]: base };
    const url = new URL( endpointUrl( context, endpoint, new URLSearchParams( { search: 'CO2 & water?', page: '2' } ) ) );
    assert.equal( url.searchParams.get( 'search' ), 'CO2 & water?' );
    assert.equal( url.searchParams.get( 'lang' ), 'en' );
    assert.equal( plain ? url.searchParams.get( 'rest_route' ) : url.pathname, plain ? `/zotero-display/v1/${ endpoint }` : `/sub/wp-json/zotero-display/v1/${ endpoint }` );
   }
  }
 }
 console.log( 'PASS plain/pretty permalinks, both endpoints, legacy markup and query preservation' );
})().catch( ( error ) => { console.error( error ); process.exitCode = 1; } );
