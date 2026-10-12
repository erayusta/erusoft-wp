( function () {
	'use strict';
	var table = document.getElementById( 'eru-edr-table' );
	var tpl = document.getElementById( 'eru-edr-row' );
	var add = document.getElementById( 'eru-edr-add' );
	if ( ! table || ! tpl || ! add ) {
		return;
	}
	var next = parseInt( table.getAttribute( 'data-next' ), 10 ) || 0;
	var name = table.getAttribute( 'data-name' );

	add.addEventListener( 'click', function () {
		var row = tpl.content.cloneNode( true );
		row.querySelectorAll( '[data-field]' ).forEach( function ( el ) {
			el.name = name + '[' + next + '][' + el.getAttribute( 'data-field' ) + ']';
		} );
		next++;
		table.tBodies[ 0 ].appendChild( row );
	} );

	table.addEventListener( 'click', function ( e ) {
		if ( e.target.classList.contains( 'eru-edr-remove' ) ) {
			e.target.closest( 'tr' ).remove();
		}
	} );
}() );
