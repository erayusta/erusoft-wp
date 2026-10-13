/* global jQuery */
( function ( $ ) {
	'use strict';
	var next = 1000;
	$( document ).on( 'click', '#eru-bss-add', function () {
		var tpl = $( '#eru-bss-row-tpl' ).html().replace( /__i__/g, String( next++ ) );
		$( '#eru-bss-rows' ).append( tpl );
	} );
	$( document ).on( 'click', '.eru-bss-del', function () {
		var rows = $( '#eru-bss-rows tr' );
		if ( rows.length > 1 ) {
			$( this ).closest( 'tr' ).remove();
		} else {
			rows.find( 'input[type=text]' ).val( '' );
		}
	} );
}( jQuery ) );
