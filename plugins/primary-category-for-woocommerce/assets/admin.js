( function ( $ ) {
	'use strict';
	// Keep the picker in sync with the category checklist on the product edit screen.
	var $sel = $( '#eru-pc-select' ), $list = $( '#product_catchecklist' );
	if ( ! $sel.length || ! $list.length ) { return; }
	function rebuild() {
		var cur = $sel.val() || String( $sel.data( 'current' ) || 0 );
		var first = $sel.find( 'option' ).first().clone();
		$sel.empty().append( first );
		$list.find( 'input:checked' ).each( function () {
			var $i = $( this );
			$sel.append( $( '<option>' ).val( $i.val() ).text( $.trim( $i.parent().text() ) ) );
		} );
		$sel.val( cur );
		if ( $sel.val() === null ) { $sel.val( '0' ); }
	}
	$list.on( 'change', 'input', rebuild );
	$( document ).on( 'wpListAddEnd', rebuild );
}( jQuery ) );
