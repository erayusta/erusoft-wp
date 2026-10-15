( function () {
	'use strict';
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.eru-ssr-test' );
		if ( ! btn || ! window.eruSsr ) {
			return;
		}
		e.preventDefault();
		var list = document.querySelector( '.eru-ssr-test-result' );
		var body = new URLSearchParams( { action: 'eru_ssr_test', dest: btn.getAttribute( 'data-dest' ), _wpnonce: window.eruSsr.nonce } );
		btn.disabled = true;
		fetch( window.eruSsr.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				var data = res && res.data ? res.data : { ok: false, messages: [ window.eruSsr.error ] };
				var li = document.createElement( 'li' );
				li.className = data.ok ? 'eru-ssr-ok' : 'eru-ssr-bad';
				li.textContent = ( data.ok ? '✔ ' : '✖ ' ) + btn.getAttribute( 'data-dest' ).toUpperCase() + ': ' + ( data.messages || [] ).join( ' | ' );
				list.appendChild( li );
			} )
			.catch( function () {
				var li = document.createElement( 'li' );
				li.className = 'eru-ssr-bad';
				li.textContent = window.eruSsr.error;
				list.appendChild( li );
			} )
			.finally( function () { btn.disabled = false; } );
	} );
}() );
