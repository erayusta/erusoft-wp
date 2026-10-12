/* Trendyol Reviews for WooCommerce — admin: inline mapping of unmatched products */
/* global jQuery, eruTyrAdmin */
( function ( $ ) {
	'use strict';

	function post( data ) {
		return $.post( eruTyrAdmin.ajaxUrl, $.extend( { nonce: eruTyrAdmin.nonce }, data ) );
	}

	function fail( $cell, res ) {
		var msg = res && res.data && res.data.message ? res.data.message : '';
		$cell.find( '.eru-tyr-msg' ).remove();
		$( '<span class="eru-tyr-msg eru-tyr-err"></span>' ).text( eruTyrAdmin.i18n.error + ' ' + msg ).appendTo( $cell );
	}

	$( document ).on( 'click', '.eru-tyr-map-btn', function () {
		var $btn = $( this ),
			$row = $btn.closest( 'tr' ),
			$cell = $btn.closest( 'td' ),
			productId = $row.find( 'select.eru-tyr-product' ).val();

		if ( ! productId ) {
			window.alert( eruTyrAdmin.i18n.pick );
			return;
		}
		$btn.prop( 'disabled', true ).text( eruTyrAdmin.i18n.working );
		post( { action: 'eru_tyr_map', content_id: $row.data( 'content' ), product_id: productId } )
			.done( function ( res ) {
				if ( res && res.success ) {
					$cell.empty().append( $( '<span class="eru-tyr-msg eru-tyr-ok"></span>' ).text( res.data.message ) );
				} else {
					$btn.prop( 'disabled', false );
					fail( $cell, res );
				}
			} )
			.fail( function ( xhr ) {
				$btn.prop( 'disabled', false );
				fail( $cell, xhr.responseJSON );
			} );
	} );

	$( document ).on( 'click', '.eru-tyr-unmap-btn', function () {
		var $row = $( this ).closest( 'tr' );
		if ( ! window.confirm( eruTyrAdmin.i18n.confirm ) ) {
			return;
		}
		post( { action: 'eru_tyr_unmap', content_id: $row.data( 'content' ) } ).done( function ( res ) {
			if ( res && res.success ) {
				$row.remove();
			}
		} );
	} );
} )( jQuery );
