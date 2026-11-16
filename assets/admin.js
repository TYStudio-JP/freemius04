/* Upgrade Warden: runs the scan as a series of short requests. */
( function () {
	'use strict';

	var button = document.getElementById( 'upgw-scan' );
	var status = document.getElementById( 'upgw-progress' );

	if ( ! button || ! window.upgwAdmin ) {
		return;
	}

	function post( action ) {
		var body = new URLSearchParams();

		body.append( 'action', action );
		body.append( 'nonce', window.upgwAdmin.nonce );

		return fetch( window.upgwAdmin.ajax, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}

			return response.json();
		} );
	}

	function show( progress ) {
		status.textContent = window.upgwAdmin.text.progress.replace( '%1$d', progress.done ).replace( '%2$d', progress.total );
	}

	function next( result ) {
		if ( ! result || ! result.success ) {
			throw new Error( 'refused' );
		}

		if ( 'running' !== result.data.status ) {
			window.location.reload();
			return null;
		}

		show( result.data );

		return post( 'upgw_step' ).then( next );
	}

	function run( action ) {
		button.disabled = true;

		post( action ).then( next ).catch( function () {
			status.textContent = window.upgwAdmin.text.failed;
			button.disabled = false;
		} );
	}

	button.addEventListener( 'click', function () {
		run( 'upgw_start' );
	} );

	// A scan that was interrupted by leaving the page picks up where it stopped.
	if ( '1' === button.getAttribute( 'data-running' ) ) {
		run( 'upgw_step' );
	}
}() );
