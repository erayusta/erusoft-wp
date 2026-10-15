/* Yurtiçi Kargo order box: dispatch / query / cancel without submitting the surrounding order form. */
( function () {
	'use strict';
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.eru-yk-btn' ) : null;
		if ( ! btn || ! window.eruYkMetabox ) {
			return;
		}
		e.preventDefault();
		var action = btn.getAttribute( 'data-do' );
		var ask = window.eruYkMetabox.confirm[ action ];
		if ( ask && ! window.confirm( ask ) ) {
			return;
		}
		var box = btn.closest( '.eru-yk-box' );
		var out = box ? box.querySelector( '.eru-yk-result' ) : null;
		var body = new FormData();
		body.append( 'action', 'eru_yk_order' );
		body.append( 'do', action );
		body.append( 'order', btn.getAttribute( 'data-order' ) );
		body.append( 'nonce', btn.getAttribute( 'data-nonce' ) );
		if ( 'reset' === action ) {
			body.append( 'confirm', '1' ); // The confirm dialog above is the admin's confirmation.
		}
		btn.disabled = true;
		fetch( window.eruYkMetabox.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				if ( out ) {
					out.textContent = j && j.message ? String( j.message ) : window.eruYkMetabox.error;
					out.className = 'eru-yk-result ' + ( j && j.ok ? 'is-ok' : 'is-error' );
				}
				btn.disabled = false;
				if ( j && j.reload ) {
					window.setTimeout( function () { window.location.reload(); }, 1500 );
				}
			} )
			.catch( function () {
				if ( out ) {
					out.textContent = window.eruYkMetabox.error;
				}
				btn.disabled = false;
			} );
	} );
} )();
