/* Consent Manager for İYS: enable "save" only after a change. Works without JS too. */
( function () {
	var form = document.querySelector( '.eru-cmi-account-form' );
	if ( ! form ) {
		return;
	}
	var button = form.querySelector( '.eru-cmi-save' );
	var boxes = form.querySelectorAll( 'input[type="checkbox"]' );
	if ( ! button || ! boxes.length ) {
		return;
	}
	var initial = Array.prototype.map.call( boxes, function ( b ) {
		return b.checked;
	} );
	var sync = function () {
		var changed = Array.prototype.some.call( boxes, function ( b, i ) {
			return b.checked !== initial[ i ];
		} );
		button.disabled = ! changed;
	};
	Array.prototype.forEach.call( boxes, function ( b ) {
		b.addEventListener( 'change', sync );
	} );
	sync();
} )();
