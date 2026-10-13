( function ( $ ) {
	'use strict';
	var $body = $( '#eru-gpr-rules tbody' );
	$( '#eru-gpr-add' ).on( 'click', function () {
		var html = $( '#eru-gpr-template' ).html().replace( /__i__/g, String( Date.now() ) );
		$body.append( html );
	} );
	$body.on( 'click', '.eru-gpr-remove', function () {
		$( this ).closest( 'tr' ).remove();
	} );
} )( jQuery );
