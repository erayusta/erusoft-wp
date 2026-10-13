/* Abandoned Checkout Call List: sends contact fields on blur, only after consent. */
( function () {
	'use strict';
	if ( typeof ERU_ACCL === 'undefined' ) { return; }
	var box = document.getElementById( 'eru_accl_consent' );
	var timer = null;
	var last = '';

	function consentGiven() {
		return ERU_ACCL.requireCheckbox ? !!( box && box.checked ) : !!document.getElementById( 'eru-accl-consent' );
	}
	function val( id ) {
		var el = document.getElementById( id );
		return el ? el.value.trim() : '';
	}
	function send() {
		if ( ! consentGiven() ) { return; }
		var email = val( 'billing_email' ), phone = val( 'billing_phone' );
		if ( ! email && ! phone ) { return; }
		var sig = email + '|' + phone + '|' + val( 'billing_first_name' );
		if ( sig === last ) { return; }
		last = sig;
		var fd = new FormData();
		fd.append( 'action', 'eru_accl_capture' );
		fd.append( 'nonce', ERU_ACCL.nonce );
		fd.append( 'consent', '1' );
		fd.append( 'email', email );
		fd.append( 'phone', phone );
		fd.append( 'first_name', val( 'billing_first_name' ) );
		fetch( ERU_ACCL.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } ).catch( function () {} );
	}
	function later() {
		clearTimeout( timer );
		timer = setTimeout( send, 600 );
	}
	document.addEventListener( 'focusout', function ( e ) {
		if ( e.target && [ 'billing_email', 'billing_phone', 'billing_first_name' ].indexOf( e.target.id ) !== -1 ) { later(); }
	} );
	if ( box ) { box.addEventListener( 'change', later ); }
}() );
