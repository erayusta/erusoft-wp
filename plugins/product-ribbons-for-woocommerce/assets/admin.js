( function () {
	'use strict';
	var table = document.getElementById( 'eru-pr-rules' );
	var tpl = document.getElementById( 'eru-pr-row' );
	var add = document.getElementById( 'eru-pr-add' );
	if ( ! table || ! tpl || ! add ) {
		return;
	}
	var next = parseInt( table.getAttribute( 'data-next' ), 10 ) || 0;

	function sync( row ) {
		var type = row.querySelector( '.eru-pr-type' ).value;
		row.querySelectorAll( '.eru-pr-term' ).forEach( function ( s ) {
			s.style.display = s.classList.contains( 'eru-pr-term-' + type ) ? '' : 'none';
		} );
		row.querySelector( '.eru-pr-term-none' ).style.display = ( 'product_cat' === type || 'product_tag' === type ) ? 'none' : '';
	}

	table.querySelectorAll( 'tbody tr' ).forEach( sync );

	add.addEventListener( 'click', function () {
		var html = tpl.innerHTML.split( '__i__' ).join( String( next ) );
		next++;
		var holder = document.createElement( 'tbody' );
		holder.innerHTML = html;
		var row = holder.querySelector( 'tr' );
		table.tBodies[ 0 ].appendChild( row );
		sync( row );
	} );

	table.addEventListener( 'change', function ( e ) {
		if ( e.target.classList.contains( 'eru-pr-type' ) ) {
			sync( e.target.closest( 'tr' ) );
		}
	} );
	table.addEventListener( 'click', function ( e ) {
		if ( e.target.classList.contains( 'eru-pr-remove' ) ) {
			e.target.closest( 'tr' ).remove();
		}
	} );
}() );
