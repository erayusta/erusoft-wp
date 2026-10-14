/* global jQuery, eruCos */
( function ( $ ) {
	'use strict';

	// Same folding as Eru_COS_Statuses::make_slug (server re-validates).
	function fold( text ) {
		var map = { 'İ': 'i', 'I': 'i', 'ı': 'i', 'Ş': 's', 'ş': 's', 'Ğ': 'g', 'ğ': 'g', 'Ç': 'c', 'ç': 'c', 'Ö': 'o', 'ö': 'o', 'Ü': 'u', 'ü': 'u' };
		text = text.replace( /[İIıŞşĞğÇçÖöÜü]/g, function ( c ) { return map[ c ]; } );
		text = text.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).toLowerCase();
		return text.replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' );
	}
	function fit( slug, max ) {
		if ( slug.length <= max ) {
			return slug;
		}
		var cut = slug.slice( 0, max );
		if ( slug.charAt( max ) !== '-' && cut.lastIndexOf( '-' ) >= 3 ) {
			cut = cut.slice( 0, cut.lastIndexOf( '-' ) );
		}
		return cut.replace( /-+$/, '' );
	}

	$( function () {
		var $slug = $( '#eru-cos-slug' );
		var auto = $slug.data( 'auto' ) === 1 && ! $slug.val();
		$slug.on( 'input', function () { auto = false; } );
		$( '#eru-cos-label' ).on( 'input', function () {
			if ( auto ) {
				$slug.val( fit( fold( this.value ), eruCos.max ) );
			}
			$( '.eru-cos-preview' ).text( this.value );
		} );
		$( '#eru-cos-color' ).on( 'input', function () {
			var hex = this.value.replace( '#', '' );
			var lin = function ( c ) { c = parseInt( c, 16 ) / 255; return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 ); };
			var l = 0.2126 * lin( hex.substr( 0, 2 ) ) + 0.7152 * lin( hex.substr( 2, 2 ) ) + 0.0722 * lin( hex.substr( 4, 2 ) );
			$( '.eru-cos-preview' ).css( { background: this.value, color: ( 1.05 / ( l + 0.05 ) ) >= ( ( l + 0.05 ) / 0.05 ) ? '#ffffff' : '#1d2327' } );
		} );

		var $list = $( '#eru-cos-sortable' );
		if ( $list.find( 'tr[data-slug]' ).length > 1 ) {
			$list.sortable( {
				handle: '.eru-cos-handle',
				items: 'tr[data-slug]',
				axis: 'y',
				placeholder: 'eru-cos-sortable-placeholder',
				update: function () {
					var order = $list.find( 'tr[data-slug]' ).map( function () { return $( this ).data( 'slug' ); } ).get();
					$.post( eruCos.ajax, { action: 'eru_cos_reorder', _ajax_nonce: eruCos.nonce, order: order } )
						.done( function () { $( '.eru-cos-reorder-status' ).text( eruCos.saved ); } )
						.fail( function () { $( '.eru-cos-reorder-status' ).text( eruCos.error ); } );
				}
			} );
		}
	} );
}( jQuery ) );
