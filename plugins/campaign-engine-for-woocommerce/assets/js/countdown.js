/**
 * Campaign Engine — countdowns to a campaign's real end time. The server clock comes from
 * GET /wp-json/eru-ce/v1/clock, so a cached page still counts down correctly. If the clock cannot be
 * read, the printed end date stays as it is.
 */
( function () {
	'use strict';

	var nodes = document.querySelectorAll( '[data-eru-ce-end]' );
	var cfg = window.eruCeClock || {};

	if ( ! nodes.length || ! cfg.url || ! window.fetch ) {
		return;
	}

	function pad( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	var first = nodes[ 0 ].getAttribute( 'data-eru-ce-campaign' ) || '0';
	var url = cfg.url + ( cfg.url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'campaign=' + encodeURIComponent( first );

	fetch( url, { credentials: 'same-origin' } ).then( function ( r ) {
		return r.ok ? r.json() : null;
	} ).then( function ( data ) {
		if ( ! data || 'number' !== typeof data.now ) {
			return;
		}
		var offset = data.now - Date.now() / 1000;

		function tick() {
			var now = Date.now() / 1000 + offset;
			Array.prototype.forEach.call( nodes, function ( node ) {
				var end = parseInt( node.getAttribute( 'data-eru-ce-end' ), 10 );
				var left = Math.floor( end - now );
				if ( left <= 0 ) {
					node.textContent = cfg.ended;
					node.classList.add( 'is-ended' );
					return;
				}
				var days = Math.floor( left / 86400 );
				var rest = left % 86400;
				var text = ( days > 0 ? days + ' ' + cfg.day + ' ' : '' ) + pad( Math.floor( rest / 3600 ) ) + ':' + pad( Math.floor( ( rest % 3600 ) / 60 ) ) + ':' + pad( rest % 60 );
				node.textContent = cfg.left + ': ' + text;
			} );
		}

		tick();
		window.setInterval( tick, 1000 );
	} ).catch( function () {} );
}() );
