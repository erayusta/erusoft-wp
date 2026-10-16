/**
 * Marketplace Orders for WooCommerce: order box buttons. Each click posts a separate small form to admin-post.php
 * (built with createElement, never from HTML strings), so the order form itself is not submitted.
 */
( function () {
	'use strict';
	document.addEventListener( 'click', function ( e ) {
		var b = e.target && e.target.closest ? e.target.closest( '[data-eru-mpo-do]' ) : null;
		if ( ! b ) {
			return;
		}
		e.preventDefault();
		var f = document.createElement( 'form' );
		f.method = 'post';
		f.action = b.getAttribute( 'data-url' );
		var fields = {
			action: b.getAttribute( 'data-eru-mpo-do' ),
			eru_mpo_order_id: b.getAttribute( 'data-order' ),
			eru_mpo_order_nonce: b.getAttribute( 'data-nonce' )
		};
		Object.keys( fields ).forEach( function ( k ) {
			var i = document.createElement( 'input' );
			i.type = 'hidden';
			i.name = k;
			i.value = fields[ k ] || '';
			f.appendChild( i );
		} );
		b.disabled = true;
		document.body.appendChild( f );
		f.submit();
	} );
}() );
