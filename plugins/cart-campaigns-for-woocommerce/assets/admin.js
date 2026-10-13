( function ( $ ) {
	'use strict';
	var $body = $( '#eru-cc-tiers tbody' );
	$( '#eru-cc-add' ).on( 'click', function () {
		var html = $( '#eru-cc-template' ).html().replace( /__i__/g, String( Date.now() ) );
		$body.append( html );
	} );
	$body.on( 'click', '.eru-cc-remove', function () {
		$( this ).closest( 'tr' ).remove();
	} );
} )( jQuery );
