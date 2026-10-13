/* global jQuery, eruSxi */
( function ( $ ) {
	'use strict';

	function post( action, data ) {
		return $.post( eruSxi.ajax, $.extend( { action: action, nonce: eruSxi.nonce }, data ) );
	}

	var $actions = $( '.eru-sxi-actions' );
	var feed = $actions.data( 'feed' );
	var $prog = $( '#eru-sxi-progress' );
	var $err = $( '#eru-sxi-error' );

	function fail( msg ) {
		$err.find( 'p' ).text( msg || eruSxi.failed );
		$err.prop( 'hidden', false );
		$actions.find( 'button' ).prop( 'disabled', false );
		$( '#eru-sxi-cancel' ).prop( 'hidden', true );
	}

	function show( s ) {
		var pct = s.total ? Math.round( ( s.done / s.total ) * 100 ) : 0;
		var text;
		if ( 'staging' === s.status ) {
			text = eruSxi.staging;
		} else if ( 'finalizing' === s.status ) {
			text = eruSxi.final;
			pct = 100;
		} else {
			text = eruSxi.working.replace( '%1$d', s.done ).replace( '%2$d', s.total );
		}
		$prog.prop( 'hidden', false ).find( 'progress' ).val( pct );
		$prog.find( '.eru-sxi-ptext' ).text( text );
	}

	function loop() {
		post( 'eru_sxi_step', { feed: feed } ).done( function ( r ) {
			if ( ! r.success || ! r.data ) {
				return fail( r.data && r.data.message );
			}
			var s = r.data;
			if ( [ 'staging', 'processing', 'finalizing' ].indexOf( s.status ) > -1 ) {
				show( s );
				return window.setTimeout( loop, s.busy ? 2000 : 50 );
			}
			window.location.reload();
		} ).fail( function () {
			fail();
		} );
	}

	$actions.on( 'click', 'button[data-mode]', function () {
		var mode = $( this ).data( 'mode' );
		if ( 'live' === mode && ! window.confirm( eruSxi.confirm ) ) {
			return;
		}
		$err.prop( 'hidden', true );
		$actions.find( 'button' ).prop( 'disabled', true );
		$( '#eru-sxi-cancel' ).prop( 'hidden', false ).prop( 'disabled', false );
		post( 'eru_sxi_start', { feed: feed, mode: mode } ).done( function ( r ) {
			if ( ! r.success ) {
				return fail( r.data && r.data.message );
			}
			show( r.data );
			loop();
		} ).fail( function () {
			fail();
		} );
	} );

	$( '#eru-sxi-cancel' ).on( 'click', function () {
		post( 'eru_sxi_cancel', { feed: feed } ).always( function () {
			window.location.reload();
		} );
	} );

	$( '#eru-sxi-sample' ).on( 'click', function () {
		var $out = $( '#eru-sxi-sample-out' ).empty();
		post( 'eru_sxi_sample', { feed: $( this ).data( 'feed' ) } ).done( function ( r ) {
			if ( ! r.success ) {
				$out.append( $( '<p class="description">' ).text( r.data && r.data.message ? r.data.message : eruSxi.failed ) );
				return;
			}
			var $t = $( '<table class="widefat striped">' ).append( $( '<thead>' ).append( $( '<tr>' ).append( $( '<th>' ).text( eruSxi.path ), $( '<th>' ).text( eruSxi.value ) ) ) );
			var $b = $( '<tbody>' ).appendTo( $t );
			r.data.rows.forEach( function ( row ) {
				$b.append( $( '<tr>' ).append( $( '<td>' ).append( $( '<code>' ).text( row.path ) ), $( '<td>' ).text( row.value ) ) );
			} );
			$out.append( $t );
		} ).fail( function () {
			$out.append( $( '<p class="description">' ).text( eruSxi.failed ) );
		} );
	} );

	// Resume the progress display when a run is already active (e.g. after a reload).
	if ( 1 === parseInt( $actions.data( 'active' ), 10 ) ) {
		$actions.find( 'button[data-mode]' ).prop( 'disabled', true );
		$( '#eru-sxi-cancel' ).prop( 'hidden', false );
		loop();
	}
}( jQuery ) );
