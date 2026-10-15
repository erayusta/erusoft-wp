( function () {
	'use strict';
	var cfg = window.eruDti || {};

	function ajaxGet( params, cb ) {
		var qs = Object.keys( params ).map( function ( k ) { return encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] ); } ).join( '&' );
		fetch( cfg.ajax + '?' + qs, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) { if ( j && j.success ) { cb( j.data ); } } )
			.catch( function () {} );
	}

	function fill( select, items, labelOf, valueOf ) {
		while ( select.options.length > 1 ) { select.remove( 1 ); }
		items.forEach( function ( it ) {
			var o = document.createElement( 'option' );
			o.value = valueOf( it );
			o.textContent = labelOf( it );
			select.appendChild( o );
		} );
		select.disabled = ! items.length;
	}

	function init( root ) {
		var use = root.querySelector( '.eru-dti__use' ), body = root.querySelector( '.eru-dti__body' );
		var l1 = root.querySelector( '.eru-dti__l1' ), l2 = root.querySelector( '.eru-dti__l2' ), l3 = root.querySelector( '.eru-dti__l3' );
		var l3row = root.querySelector( '.eru-dti__row--l3' ), dev = root.querySelector( '.eru-dti__device' );
		var est = root.querySelector( '.eru-dti__estimate' ), estVal = root.querySelector( '.eru-dti__estimate-value' ), estLab = root.querySelector( '.eru-dti__estimate-label' );
		var devices = [];

		function refresh() {
			var cond = root.querySelector( 'input[name="eru_dti[condition]"]:checked' );
			var d = devices.filter( function ( x ) { return String( x.id ) === dev.value; } )[ 0 ];
			root.querySelectorAll( '.eru-dti__cond-val' ).forEach( function ( s ) {
				s.textContent = d && d.values[ s.dataset.key ] ? d.values[ s.dataset.key ].f : '';
			} );
			if ( d && cond && d.values[ cond.value ] ) {
				estLab.textContent = cfg.est + ': ';
				estVal.textContent = d.values[ cond.value ].f;
				est.hidden = false;
			} else {
				est.hidden = true;
			}
		}
		function setDevices( list ) {
			devices = list;
			dev.value = '';
			var usesL3 = root.dataset.l3 === '1';
			var single = list.length === 1 && ( list[ 0 ].std || ! usesL3 );
			fill( l3, list, function ( x ) { return x.label; }, function ( x ) { return x.id; } );
			l3row.hidden = ! usesL3 || single;
			if ( single || ( ! usesL3 && list.length ) ) { dev.value = list[ 0 ].id; }
			refresh();
		}

		use.addEventListener( 'change', function () { body.hidden = ! use.checked; } );
		l1.addEventListener( 'change', function () {
			fill( l2, [], String, String ); fill( l3, [], String, String ); l3row.hidden = root.dataset.l3 !== '1'; dev.value = ''; devices = []; refresh();
			if ( ! l1.value ) { return; }
			ajaxGet( { action: 'eru_dti_options', l1: l1.value }, function ( d ) { fill( l2, d.options, String, String ); } );
		} );
		l2.addEventListener( 'change', function () {
			fill( l3, [], String, String ); dev.value = ''; refresh();
			if ( ! l2.value ) { return; }
			ajaxGet( { action: 'eru_dti_options', l1: l1.value, l2: l2.value }, function ( d ) { setDevices( d.options ); } );
		} );
		l3.addEventListener( 'change', function () { dev.value = l3.value; refresh(); } );
		root.addEventListener( 'change', function ( e ) { if ( e.target.name === 'eru_dti[condition]' ) { refresh(); } } );

		// Lead form.
		var open = root.querySelector( '.eru-dti__lead-open' ), box = root.querySelector( '.eru-dti__lead' );
		if ( open && box ) {
			open.addEventListener( 'click', function () { box.hidden = ! box.hidden; } );
			var msg = box.querySelector( '.eru-dti__lead-msg' );
			box.querySelector( '.eru-dti__lead-send' ).addEventListener( 'click', function () {
				var fd = new FormData();
				fd.append( 'action', 'eru_dti_lead' );
				fd.append( 'nonce', cfg.leadNonce );
				fd.append( 'name', box.querySelector( '.eru-dti__lead-name' ).value );
				fd.append( 'email', box.querySelector( '.eru-dti__lead-email' ).value );
				fd.append( 'phone', box.querySelector( '.eru-dti__lead-phone' ).value );
				fd.append( 'device_text', box.querySelector( '.eru-dti__lead-device' ).value );
				fd.append( 'website', box.querySelector( '.eru-dti__lead-hp' ).value );
				fetch( cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( j ) { msg.textContent = ( j && j.data && j.data.message ) || cfg.error; } )
					.catch( function () { msg.textContent = cfg.error; } );
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.eru-dti' ).forEach( init );
	} );
}() );
