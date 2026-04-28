/**
 * Disciple.Tools Migration - Downloadable JSON export preflight (memory heuristic).
 */
( function( $ ) {
	'use strict';

	const strings = ( typeof dtMigrationExport !== 'undefined' && dtMigrationExport.strings ) ? dtMigrationExport.strings : {};

	function t( key, fallback ) {
		return strings[ key ] || fallback;
	}

	$( function() {
		const $form = $( '#dt-migration-download-export-form' );
		if ( ! $form.length || typeof dtMigrationExport === 'undefined' ) {
			return;
		}

		const endpoint = dtMigrationExport.preflightUrl || '';
		const nonce = dtMigrationExport.nonce || '';

		$form.on( 'submit', function( e ) {
			e.preventDefault();

			const bodyStr = $form.serialize();

			fetch( endpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
					'X-WP-Nonce': nonce
				},
				credentials: 'same-origin',
				body: bodyStr
			} )
				.then( function( resp ) {
					return resp.json().then( function( data ) {
						return { ok_http: resp.ok, status: resp.status, payload: data };
					} );
				} )
				.then( function( wrapped ) {
					const data = wrapped.payload;
					if ( ! wrapped.ok_http ) {
						throw new Error( 'http' );
					}
					if ( data && data.ok ) {
						$form.off( 'submit' );
						$form.get( 0 ).submit();
						return;
					}
					window.alert( data && data.message ? data.message : t( 'memoryBlocked', t( 'preflightFailed', 'Request failed.' ) ) );
				} )
				.catch( function() {
					window.alert( t( 'preflightFailed', 'Could not verify export safety. Try again or check your connection.' ) );
				} );
		} );
	} );
}( jQuery ) );
