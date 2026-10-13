( function ( $ ) {
	'use strict';
	var $body = $( '#eru-btd-sets tbody' );
	$( '#eru-btd-add' ).on( 'click', function () {
		var html = $( '#eru-btd-template' ).html().replace( /__i__/g, String( Date.now() ) );
		$body.append( html );
		$( document.body ).trigger( 'wc-enhanced-select-init' );
	} );
	$body.on( 'click', '.eru-btd-remove', function () {
		$( this ).closest( 'tr' ).remove();
	} );
} )( jQuery );
