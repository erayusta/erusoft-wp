( function ( $ ) {
	'use strict';
	var $body = $( '#eru-pl-rules tbody' );
	$( '#eru-pl-add' ).on( 'click', function () {
		$body.append( $( '#eru-pl-template' ).html().replace( /__i__/g, String( Date.now() ) ) );
	} );
	$body.on( 'click', '.eru-pl-remove', function () {
		$( this ).closest( 'tr' ).remove();
	} );
} )( jQuery );
