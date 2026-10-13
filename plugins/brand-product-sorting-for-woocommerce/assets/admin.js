( function ( $ ) {
	'use strict';
	var $list = $( '#eru-bps-list' );
	var $msg = $( '#eru-bps-msg' );
	if ( $list.length ) {
		$list.sortable( { handle: '.eru-bps-handle', axis: 'y', placeholder: 'eru-bps-ph', forcePlaceholderSize: true } );
	}
	function say( text, ok ) {
		$msg.text( text ).toggleClass( 'is-error', ! ok );
	}
	function post( data, done ) {
		data.nonce = eruBps.nonce;
		$.post( eruBps.ajax, data ).done( function ( r ) {
			if ( r && r.success ) { done(); } else { say( eruBps.failed, false ); }
		} ).fail( function () { say( eruBps.failed, false ); } );
	}
	$( '#eru-bps-save' ).on( 'click', function () {
		var ids = $list.children( 'li' ).map( function () { return $( this ).data( 'id' ); } ).get();
		post( { action: 'eru_bps_save', term: $( this ).data( 'term' ), ids: ids }, function () { say( eruBps.saved, true ); } );
	} );
	$( '#eru-bps-clear' ).on( 'click', function () {
		if ( ! window.confirm( eruBps.ask ) ) { return; }
		post( { action: 'eru_bps_clear', term: $( this ).data( 'term' ) }, function () { window.location.reload(); } );
	} );
}( jQuery ) );
