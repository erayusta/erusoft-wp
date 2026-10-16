/**
 * VakıfBank Vinov — order box buttons. Plain JavaScript, no build step.
 *
 * "Durumu sorgula" and the confirmed "Talimatı iptal et" post to admin-ajax with the box's per-order nonce;
 * the server checks the capability, the nonce and the confirmation. Answers are written with textContent.
 */
( function () {
	'use strict';

	var cfg = window.eruVnvAdmin;
	var box = document.getElementById( 'eru-vnv-box' );
	if ( ! cfg || ! box ) {
		return;
	}
	var result = box.querySelector( '.eru-vnv-result' );
	var confirmBox = box.querySelector( '.eru-vnv-confirm' );

	function clear( node ) {
		while ( node && node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function say( text, isError ) {
		if ( ! result ) {
			return;
		}
		clear( result );
		var p = document.createElement( 'p' );
		p.textContent = text || '';
		if ( isError ) {
			p.className = 'is-error';
		}
		result.appendChild( p );
	}

	function debts( list ) {
		if ( ! result || ! list || ! list.length ) {
			return;
		}
		var table = document.createElement( 'table' );
		table.className = 'widefat striped';
		var head = document.createElement( 'tr' );
		[ cfg.i18n.amount, cfg.i18n.due, cfg.i18n.state ].forEach( function ( t ) {
			var th = document.createElement( 'th' );
			th.textContent = t;
			head.appendChild( th );
		} );
		var thead = document.createElement( 'thead' );
		thead.appendChild( head );
		table.appendChild( thead );
		var body = document.createElement( 'tbody' );
		list.forEach( function ( d ) {
			var tr = document.createElement( 'tr' );
			[ d.amount, d.maturity, d.status ].forEach( function ( v ) {
				var td = document.createElement( 'td' );
				td.textContent = String( v === undefined || v === null ? '' : v );
				tr.appendChild( td );
			} );
			body.appendChild( tr );
		} );
		table.appendChild( body );
		result.appendChild( table );
	}

	function send( action, confirmed, btn ) {
		var data = new URLSearchParams();
		data.append( 'action', 'eru_vnv_order' );
		data.append( 'do', action );
		data.append( 'order_id', box.getAttribute( 'data-order' ) || '' );
		data.append( 'nonce', box.getAttribute( 'data-nonce' ) || '' );
		if ( confirmed ) {
			data.append( 'confirm', '1' );
		}
		btn.disabled = true;
		say( cfg.i18n.working, false );
		fetch( cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: data.toString()
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( r ) {
				var d = r && r.data ? r.data : {};
				say( typeof d.message === 'string' && d.message ? d.message : cfg.i18n.error, ! r.success );
				if ( r.success && d.data && d.data.debts ) {
					debts( d.data.debts );
				}
				var changed = r.success && [ 'paid', 'cancelled', 'adopted', 'gave_up' ].indexOf( d.code ) !== -1;
				if ( changed ) {
					var p = document.createElement( 'p' );
					p.textContent = cfg.i18n.reload;
					result.appendChild( p );
					window.setTimeout( function () {
						window.location.reload();
					}, 1500 );
				}
			} )
			.catch( function () {
				say( cfg.i18n.error, true );
			} )
			.then( function () {
				btn.disabled = false;
			} );
	}

	box.addEventListener( 'click', function ( e ) {
		var t = e.target;
		if ( ! t || ! t.closest ) {
			return;
		}
		var ask = t.closest( '[data-eru-vnv-ask]' );
		if ( ask && confirmBox ) {
			e.preventDefault();
			confirmBox.hidden = ask.getAttribute( 'data-eru-vnv-ask' ) !== '1';
			return;
		}
		var btn = t.closest( '[data-eru-vnv-do]' );
		if ( btn ) {
			e.preventDefault();
			send( btn.getAttribute( 'data-eru-vnv-do' ), btn.getAttribute( 'data-confirm' ) === '1', btn );
		}
	} );
} )();
