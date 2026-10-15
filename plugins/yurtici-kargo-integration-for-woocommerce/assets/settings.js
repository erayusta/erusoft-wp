/* Yurtiçi Kargo settings: add/remove repeater rows from <template> elements (no HTML string building). */
( function () {
	'use strict';
	document.addEventListener( 'click', function ( e ) {
		var add = e.target.closest ? e.target.closest( '.eru-yk-add' ) : null;
		if ( add ) {
			e.preventDefault();
			var name = add.getAttribute( 'data-target' );
			var tpl = document.getElementById( 'eru-yk-tpl-' + name );
			var body = document.querySelector( '.eru-yk-repeater[data-repeater="' + name + '"] tbody' );
			if ( ! tpl || ! body ) {
				return;
			}
			var index = String( Date.now() ) + String( body.children.length );
			var row = tpl.content.firstElementChild.cloneNode( true );
			row.querySelectorAll( '[name]' ).forEach( function ( el ) {
				el.setAttribute( 'name', el.getAttribute( 'name' ).replace( '__i__', index ) );
			} );
			body.appendChild( row );
			var first = row.querySelector( 'input[type="text"], select' );
			if ( first ) {
				first.focus();
			}
			return;
		}
		var rm = e.target.closest ? e.target.closest( '.eru-yk-remove' ) : null;
		if ( rm ) {
			e.preventDefault();
			var tr = rm.closest( 'tr' );
			if ( tr ) {
				tr.parentNode.removeChild( tr );
			}
		}
	} );
} )();
