/**
 * Автодополнение адреса через DaData Suggestions API.
 * Инициализируется только при наличии токена в fs_lms_join_vars.dadata_token.
 */

import { createDadataSuggest } from './dadata-suggest.js';

export function initDadataAddress( token ) {
	if ( ! token ) { return; }

	const input = document.getElementById( 'fs_address' );
	if ( ! input ) { return; }

	createDadataSuggest( input, {
		endpoint:  'address',
		buildBody: ( query ) => ( { query, count: 7 } ),
		getValue:  ( s ) => s.value,
	}, token );
}
