/* Dopigo order box: queue a (re)send without submitting the surrounding order form. */
( function () {
	'use strict';
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.eru-dpg-resend' ) : null;
		if ( ! btn || ! window.eruDpgMetabox ) {
			return;
		}
		e.preventDefault();
		if ( ! window.confirm( window.eruDpgMetabox.confirm ) ) {
			return;
		}
		var out = btn.parentNode.querySelector( '.eru-dpg-resend-result' );
		var body = new FormData();
		body.append( 'action', 'eru_dpg_resend' );
		body.append( 'order', btn.getAttribute( 'data-order' ) );
		body.append( 'nonce', btn.getAttribute( 'data-nonce' ) );
		btn.disabled = true;
		fetch( window.eruDpgMetabox.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				if ( out ) { out.textContent = j && j.message ? String( j.message ) : window.eruDpgMetabox.error; }
				btn.disabled = !! ( j && j.ok );
			} )
			.catch( function () {
				if ( out ) { out.textContent = window.eruDpgMetabox.error; }
				btn.disabled = false;
			} );
	} );
} )();
