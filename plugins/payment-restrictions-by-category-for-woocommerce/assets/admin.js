( function ( $ ) {
	'use strict';
	var $body = $( '#eru-prc-rules tbody' );
	$( '#eru-prc-add' ).on( 'click', function () {
		$body.append( $( '#eru-prc-template' ).html().replace( /__i__/g, String( Date.now() ) ) );
	} );
	$body.on( 'click', '.eru-prc-remove', function () {
		$( this ).closest( 'tr' ).remove();
	} );
} )( jQuery );
