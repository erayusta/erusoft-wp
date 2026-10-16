/* Sales Team Commissions — copy the referral link. */
( function () {
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '[data-eru-stc-copy]' ) : null;
		if ( ! btn ) {
			return;
		}
		var input = document.getElementById( btn.getAttribute( 'data-eru-stc-copy' ) );
		if ( ! input ) {
			return;
		}
		var done = function () {
			var old = btn.textContent;
			btn.textContent = btn.getAttribute( 'data-done' ) || old;
			setTimeout( function () { btn.textContent = old; }, 1500 );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( input.value ).then( done );
		} else {
			input.select();
			try { document.execCommand( 'copy' ); done(); } catch ( err ) {}
		}
	} );
} )();
