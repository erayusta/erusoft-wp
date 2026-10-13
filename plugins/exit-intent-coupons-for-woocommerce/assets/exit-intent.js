( function () {
	'use strict';
	var root = document.getElementById( 'eru-eic' );
	if ( ! root ) { return; }
	var cfg = {};
	try { cfg = JSON.parse( root.getAttribute( 'data-config' ) || '{}' ); } catch ( e ) { return; }

	var shown = false, busy = false, lastFocus = null, loadedAt = Date.now();

	function hasCookie() { return document.cookie.indexOf( cfg.cookie + '=' ) !== -1; }
	function setCookie() {
		var d = new Date(); d.setTime( d.getTime() + cfg.cookieDays * 86400000 );
		document.cookie = cfg.cookie + '=1; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
	}
	function el( sel ) { return root.querySelector( sel ); }

	function reveal() {
		shown = true;
		setCookie();
		lastFocus = document.activeElement;
		root.hidden = false;
		document.body.classList.add( 'eru-eic-open' );
		var x = el( '.eru-eic__x' ); if ( x ) { x.focus(); }
		document.addEventListener( 'keydown', onKey );
	}

	function close() {
		root.hidden = true;
		document.body.classList.remove( 'eru-eic-open' );
		document.removeEventListener( 'keydown', onKey );
		if ( lastFocus && lastFocus.focus ) { lastFocus.focus(); }
	}

	function onKey( e ) {
		if ( e.key === 'Escape' ) { close(); return; }
		if ( e.key !== 'Tab' ) { return; }
		var f = root.querySelectorAll( 'button' );
		if ( ! f.length ) { return; }
		var first = f[ 0 ], last = f[ f.length - 1 ];
		if ( e.shiftKey && document.activeElement === first ) { e.preventDefault(); last.focus(); }
		else if ( ! e.shiftKey && document.activeElement === last ) { e.preventDefault(); first.focus(); }
	}

	function fmtDate( ts ) {
		try { return new Date( ts * 1000 ).toLocaleString( document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' } ); } catch ( e ) { return ''; }
	}

	function trigger() {
		if ( shown || busy || hasCookie() ) { return; }
		if ( cfg.mode !== 'generated' ) { reveal(); return; }
		busy = true;
		fetch( cfg.endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: '{}', cache: 'no-store' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( c ) {
				busy = false;
				if ( ! c || ! c.ok ) { shown = true; return; } // budget used up or rate limited: stay silent.
				el( '[data-eru-eic-code]' ).textContent = c.code;
				var meta = el( '[data-eru-eic-meta]' );
				if ( meta && c.expires ) { meta.textContent = cfg.validUntil + ' ' + fmtDate( c.expires ); }
				reveal();
			} )
			.catch( function () { busy = false; } );
	}

	root.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '[data-eru-eic-close]' ) ) { close(); return; }
		var b = e.target.closest( '[data-eru-eic-copy]' );
		if ( b ) {
			var code = el( '[data-eru-eic-code]' ).textContent;
			var done = function () { b.textContent = cfg.copied; setTimeout( function () { b.textContent = cfg.copy; }, 1600 ); };
			if ( navigator.clipboard && navigator.clipboard.writeText ) { navigator.clipboard.writeText( code ).then( done, done ); }
			else { var t = document.createElement( 'textarea' ); t.value = code; document.body.appendChild( t ); t.select(); try { document.execCommand( 'copy' ); } catch ( err ) {} t.remove(); done(); }
		}
	} );

	if ( hasCookie() ) { return; }

	var fine = window.matchMedia && window.matchMedia( '(hover: hover) and (pointer: fine)' ).matches;
	if ( fine ) {
		// Desktop: the pointer leaves through the top edge of the viewport.
		document.addEventListener( 'mouseout', function ( e ) {
			if ( e.relatedTarget || e.clientY > 0 ) { return; }
			if ( Date.now() - loadedAt < cfg.minSeconds * 1000 ) { return; }
			trigger();
		} );
	} else if ( cfg.mobile ) {
		// Touch: after a delay and some scrolling, a quick scroll back to the top reads as "leaving".
		var lastY = window.scrollY, deepest = 0;
		window.addEventListener( 'scroll', function () {
			var y = window.scrollY;
			deepest = Math.max( deepest, y );
			var stayed = Date.now() - loadedAt > cfg.mobileDelay * 1000;
			if ( stayed && deepest > cfg.mobileDepth && lastY - y > 60 && y < 200 ) { trigger(); }
			lastY = y;
		}, { passive: true } );
	}

	window.eruEic = { open: trigger, reset: function () { shown = false; document.cookie = cfg.cookie + '=; Max-Age=0; path=/'; close(); } };
} )();
