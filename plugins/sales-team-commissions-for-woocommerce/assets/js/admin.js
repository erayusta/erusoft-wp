/* Sales Team Commissions — admin: rule target switch, user suggestions, "daha fazla", full-IBAN confirm. */
( function () {
	var cfg = window.eruStcAdmin || {};

	// Rule form: show the target field of the chosen scope only.
	var scope = document.querySelector( '[data-eru-stc-scope]' );
	var syncScope = function () {
		document.querySelectorAll( '[data-eru-stc-target]' ).forEach( function ( el ) {
			el.style.display = scope && el.getAttribute( 'data-eru-stc-target' ) === scope.value ? '' : 'none';
		} );
	};
	if ( scope ) {
		scope.addEventListener( 'change', syncScope );
		syncScope();
	}

	var post = function ( data ) {
		var body = new URLSearchParams();
		Object.keys( data ).forEach( function ( k ) { body.append( k, data[ k ] ); } );
		body.append( 'nonce', cfg.nonce || '' );
		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) { return r.json(); } );
	};

	// User suggestions (datalist) for "new agent" and "link customer".
	document.querySelectorAll( '[data-eru-stc-user-search]' ).forEach( function ( input ) {
		var list = document.getElementById( input.getAttribute( 'list' ) );
		var timer = null;
		input.addEventListener( 'input', function () {
			clearTimeout( timer );
			if ( input.value.length < 2 || ! list ) {
				return;
			}
			timer = setTimeout( function () {
				post( { action: 'eru_stc_user_search', term: input.value } ).then( function ( res ) {
					if ( ! res || ! res.success ) {
						return;
					}
					while ( list.firstChild ) {
						list.removeChild( list.firstChild );
					}
					res.data.forEach( function ( u ) {
						var opt = document.createElement( 'option' );
						opt.value = u.email;
						opt.textContent = u.name + ' (#' + u.id + ')';
						list.appendChild( opt );
					} );
				} );
			}, 250 );
		} );
	} );

	// Agent detail: load more commission rows.
	document.querySelectorAll( '[data-eru-stc-more]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var tbody = document.querySelector( '[data-eru-stc-rows]' );
			var offset = parseInt( btn.getAttribute( 'data-offset' ), 10 ) || 0;
			btn.disabled = true;
			post( { action: 'eru_stc_agent_commissions', agent_id: btn.getAttribute( 'data-eru-stc-more' ), offset: offset } ).then( function ( res ) {
				btn.disabled = false;
				if ( ! res || ! res.success || ! res.data.length ) {
					btn.textContent = cfg.none || '';
					btn.disabled = true;
					return;
				}
				res.data.forEach( function ( r ) {
					var tr = document.createElement( 'tr' );
					[ r.date, '#' + r.order_id, String( r.level ), r.amount + ' ' + r.currency, r.status_label ].forEach( function ( text ) {
						var td = document.createElement( 'td' );
						td.textContent = text;
						tr.appendChild( td );
					} );
					tbody.appendChild( tr );
				} );
				btn.setAttribute( 'data-offset', String( offset + res.data.length ) );
				if ( res.data.length < 20 ) {
					btn.textContent = cfg.none || '';
					btn.disabled = true;
				}
			} );
		} );
	} );

	// Full-IBAN export asks once.
	document.querySelectorAll( '[data-eru-stc-confirm]' ).forEach( function ( box ) {
		var form = box.form;
		if ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				if ( box.checked && ! window.confirm( cfg.confirm || '' ) ) {
					e.preventDefault();
				}
			} );
		}
	} );
} )();
