( function () {
	'use strict';
	var body = document.querySelector( '#eru-dti-conds tbody' );
	var add = document.getElementById( 'eru-dti-add-cond' );
	var tpl = document.getElementById( 'eru-dti-cond-tpl' );
	if ( ! body || ! add || ! tpl ) { return; }
	var name = JSON.parse( tpl.textContent ).name;
	var T = window.eruDtiAdmin || { remove: 'Remove' };

	function cell( type, field, cls, extra ) {
		var td = document.createElement( 'td' ), i = document.createElement( 'input' );
		i.type = type;
		i.name = name + '[conditions][' + body.rows.length + '][' + field + ']';
		if ( cls ) { i.className = cls; }
		if ( extra ) { Object.keys( extra ).forEach( function ( k ) { i.setAttribute( k, extra[ k ] ); } ); }
		td.appendChild( i );
		return td;
	}
	function reindex() {
		Array.prototype.forEach.call( body.rows, function ( tr, n ) {
			tr.querySelectorAll( 'input' ).forEach( function ( i ) {
				i.name = i.name.replace( /\[conditions\]\[\d+\]/, '[conditions][' + n + ']' );
			} );
		} );
	}
	add.addEventListener( 'click', function () {
		var tr = document.createElement( 'tr' );
		tr.appendChild( cell( 'text', 'key', 'small-text', { readonly: 'readonly', placeholder: 'auto' } ) );
		tr.appendChild( cell( 'text', 'label' ) );
		tr.appendChild( cell( 'text', 'description', 'large-text' ) );
		var pct = cell( 'number', 'percent', 'small-text', { step: '0.01', min: '0', max: '100' } );
		pct.appendChild( document.createTextNode( ' %' ) );
		tr.appendChild( pct );
		var td = document.createElement( 'td' ), b = document.createElement( 'button' );
		b.type = 'button'; b.className = 'button-link-delete eru-dti-del'; b.textContent = T.remove;
		td.appendChild( b ); tr.appendChild( td );
		body.appendChild( tr );
	} );
	body.addEventListener( 'click', function ( e ) {
		if ( e.target.classList.contains( 'eru-dti-del' ) ) {
			e.target.closest( 'tr' ).remove();
			reindex();
		}
	} );
}() );
