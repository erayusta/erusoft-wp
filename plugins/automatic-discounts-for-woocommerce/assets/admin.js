( function ( $ ) {
	'use strict';
	var $body = $( '#eru-ad-rules tbody' );
	$( '#eru-ad-add' ).on( 'click', function () {
		var html = $( '#eru-ad-template' ).html().replace( /__i__/g, String( Date.now() ) );
		$body.append( html );
	} );
	$body.on( 'click', '.eru-ad-remove', function () {
		$( this ).closest( 'tr' ).remove();
	} );
} )( jQuery );
