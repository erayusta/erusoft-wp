( function ( $ ) {
	'use strict';
	var $form = $( '#eru-bpv-form' ), $out = $( '#eru-bpv-out' ), $apply = $( '#eru-bpv-apply' ), $bar = $( '#eru-bpv-bar' );
	var token = '';
	function fmt( s ) {
		var a = Array.prototype.slice.call( arguments, 1 ), i = 0;
		return s.replace( /%(\d\$)?d/g, function () { return a[ i++ ]; } );
	}
	function err( r ) {
		var m = r && r.responseJSON && r.responseJSON.data && r.responseJSON.data.message;
		$out.text( m || eruBpv.failed ).addClass( 'is-error' );
	}
	$form.on( 'change input', function () { token = ''; $apply.prop( 'disabled', true ); } );
	$( '#eru-bpv-preview' ).on( 'click', function () {
		$out.removeClass( 'is-error' ).text( '' );
		$.post( eruBpv.ajax, $form.serialize() + '&action=eru_bpv_preview&nonce=' + eruBpv.nonce ).done( function ( r ) {
			token = r.data.token;
			$out.empty().append( $( '<strong>' ).text( fmt( eruBpv.matches, r.data.count ) ) );
			if ( r.data.sample.length ) {
				$out.append( $( '<p>' ).text( eruBpv.sample + ' ' + r.data.sample.join( ', ' ) ) );
			}
			$apply.prop( 'disabled', false );
		} ).fail( err );
	} );
	$apply.on( 'click', function () {
		var t = token, total = 0;
		if ( ! t ) { return; }
		$apply.prop( 'disabled', true );
		$bar.prop( 'hidden', false );
		( function step( off ) {
			$.post( eruBpv.ajax, { action: 'eru_bpv_apply', nonce: eruBpv.nonce, token: t, offset: off } ).done( function ( r ) {
				total = r.data.total;
				$bar.find( 'span' ).css( 'width', Math.round( 100 * r.data.processed / total ) + '%' );
				if ( r.data.done ) {
					$out.removeClass( 'is-error' ).text( fmt( eruBpv.done, total ) );
					token = '';
				} else {
					$out.text( fmt( eruBpv.progress, r.data.processed, total ) );
					step( r.data.next );
				}
			} ).fail( err );
		}( 0 ) );
	} );
}( jQuery ) );
