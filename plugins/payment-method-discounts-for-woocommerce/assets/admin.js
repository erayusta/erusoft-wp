( function ( $ ) {
	'use strict';
	var $body = $( '#eru-pmd-rules tbody' );
	$( '#eru-pmd-add' ).on( 'click', function () {
		var html = $( '#eru-pmd-template' ).html().replace( /__i__/g, String( Date.now() ) );
		$body.append( html );
	} );
	$body.on( 'click', '.eru-pmd-remove', function () {
		$( this ).closest( 'tr' ).remove();
	} );
} )( jQuery );
