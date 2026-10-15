/* Reference pattern tester: posts the current pattern, template and sample to the server (same PCRE as the matcher). */
( function () {
	var btn = document.getElementById( 'eru-gha-test-btn' );
	if ( ! btn || ! window.eruGha ) {
		return;
	}
	var out = document.getElementById( 'eru-gha-test-out' );
	var val = function ( id ) {
		var el = document.getElementById( id );
		return el ? el.value : '';
	};
	var show = function ( cls, parts ) {
		while ( out.firstChild ) {
			out.removeChild( out.firstChild );
		}
		out.className = 'eru-gha-test-out ' + cls;
		parts.forEach( function ( p ) {
			var line = document.createElement( 'div' );
			line.textContent = p;
			out.appendChild( line );
		} );
	};
	btn.addEventListener( 'click', function () {
		var body = new URLSearchParams();
		body.append( 'action', 'eru_gha_test_pattern' );
		body.append( 'nonce', window.eruGha.nonce );
		body.append( 'pattern', val( 'eru-gha-ref_pattern' ) );
		body.append( 'template', val( 'eru-gha-ref_template' ) );
		body.append( 'text', val( 'eru-gha-test-text' ) );
		body.append( 'number', val( 'eru-gha-test-number' ) );
		btn.disabled = true;
		fetch( window.eruGha.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				var d = ( j && j.data ) || {};
				var t = window.eruGha.i18n;
				if ( ! j || ! j.success || d.error ) {
					show( 'bad', [ t.error + ' ' + ( d.error || '?' ) ] );
					return;
				}
				show( d.match ? 'ok' : 'warn', [ d.match ? t.match : t.nomatch, t.refs + ' ' + ( d.refs && d.refs.length ? d.refs.join( ', ' ) : t.none ) ] );
			} )
			.catch( function () {
				show( 'bad', [ window.eruGha.i18n.error ] );
			} )
			.then( function () {
				btn.disabled = false;
			} );
	} );
}() );
