( function ( $ ) {
	'use strict';
	$( document ).on( 'click', '.eru-bcb-upload', function ( e ) {
		e.preventDefault();
		var target = $( this ).data( 'target' );
		var frame = wp.media( {
			title: window.eruBcb ? window.eruBcb.title : '',
			button: { text: window.eruBcb ? window.eruBcb.button : '' },
			multiple: false,
			library: { type: 'image' }
		} );
		frame.on( 'select', function () {
			var a = frame.state().get( 'selection' ).first().toJSON();
			$( '#' + target ).val( a.id );
			$( '#' + target + '_preview' ).empty().append( $( '<img>', { src: a.url, alt: '' } ) );
			$( '.eru-bcb-remove[data-target="' + target + '"]' ).show();
		} );
		frame.open();
	} );
	$( document ).on( 'click', '.eru-bcb-remove', function ( e ) {
		e.preventDefault();
		var target = $( this ).data( 'target' );
		$( '#' + target ).val( '' );
		$( '#' + target + '_preview' ).empty();
		$( this ).hide();
	} );
}( jQuery ) );
