/**
 * VakıfBank Vinov — checkout UI. Plain JavaScript, no build step.
 *
 * Drives the "Kod gönder" / "Kodu doğrula" buttons over WooCommerce AJAX and shows the server's answer.
 * It never decides verification: the server keeps the bank's result in the session and re-checks it when
 * the order is placed. The "Doğrulandı" note is UI only.
 */
( function () {
	'use strict';

	var cfg = window.eruVnv;
	if ( ! cfg ) {
		return;
	}
	var mem = { tckn: '', phone: '', sent: false };

	function el( id ) {
		return document.getElementById( id );
	}

	function val( id ) {
		var e = el( id );
		return e ? String( e.value || '' ) : '';
	}

	function status( text, isError ) {
		var s = el( 'eru-vnv-status' );
		if ( ! s ) {
			return;
		}
		s.textContent = text || '';
		s.className = 'eru-vnv-status' + ( isError ? ' is-error' : '' );
		s.style.color = isError ? '#b32d2e' : '';
	}

	function setVerified( on ) {
		var b = el( 'eru-vnv-verified' );
		var f = el( 'eru-vnv-fields' );
		if ( b ) {
			b.hidden = ! on;
		}
		if ( f ) {
			f.setAttribute( 'data-verified', on ? '1' : '0' );
		}
	}

	function showOtp( on ) {
		var row = el( 'eru-vnv-otp-row' );
		if ( row ) {
			row.hidden = ! on;
		}
	}

	function honeypot() {
		var h = document.querySelector( 'input[name="eru_vnv_website"]' );
		return h ? h.value : '';
	}

	function post( url, data ) {
		var body = new URLSearchParams();
		Object.keys( data ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.json().catch( function () {
				return { success: false, data: { message: cfg.i18n.error } };
			} );
		} );
	}

	function message( r ) {
		return r && r.data && typeof r.data.message === 'string' && r.data.message ? r.data.message : cfg.i18n.error;
	}

	function send( btn ) {
		var tckn = val( 'eru_vnv_tckn' ).replace( /\D+/g, '' );
		var phone = val( 'eru_vnv_phone' );
		if ( tckn.length !== 11 ) {
			status( cfg.i18n.tckn, true );
			return;
		}
		if ( phone.replace( /\D+/g, '' ).length < 10 ) {
			status( cfg.i18n.phone, true );
			return;
		}
		btn.disabled = true;
		status( cfg.i18n.sending, false );
		post( cfg.sendUrl, { nonce: cfg.nonce, tckn: tckn, phone: phone, eru_vnv_website: honeypot() } )
			.then( function ( r ) {
				status( message( r ), ! r.success );
				if ( r.success ) {
					mem.sent = true;
					setVerified( false );
					showOtp( true );
					var o = el( 'eru_vnv_otp' );
					if ( o ) {
						o.value = '';
						o.focus();
					}
				}
			} )
			.catch( function () {
				status( cfg.i18n.error, true );
			} )
			.then( function () {
				btn.disabled = false;
			} );
	}

	function verify( btn ) {
		var otp = val( 'eru_vnv_otp' ).trim();
		if ( ! otp ) {
			status( cfg.i18n.otp, true );
			return;
		}
		btn.disabled = true;
		status( cfg.i18n.verifying, false );
		post( cfg.verifyUrl, {
			nonce: cfg.nonce,
			tckn: val( 'eru_vnv_tckn' ).replace( /\D+/g, '' ),
			otp: otp,
			installments: val( 'eru_vnv_installments' ) || '1',
			eru_vnv_website: honeypot()
		} )
			.then( function ( r ) {
				status( message( r ), ! r.success );
				if ( r.success && r.data && r.data.verified === true ) {
					mem.sent = false;
					showOtp( false );
					setVerified( true );
					if ( window.jQuery ) {
						window.jQuery( document.body ).trigger( 'update_checkout' );
					}
				}
			} )
			.catch( function () {
				status( cfg.i18n.error, true );
			} )
			.then( function () {
				btn.disabled = false;
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		var t = e.target;
		if ( ! t || ! t.id ) {
			return;
		}
		if ( 'eru-vnv-send' === t.id ) {
			e.preventDefault();
			send( t );
		} else if ( 'eru-vnv-verify' === t.id ) {
			e.preventDefault();
			verify( t );
		}
	} );

	document.addEventListener( 'input', function ( e ) {
		var t = e.target;
		if ( t && 'eru_vnv_tckn' === t.id ) {
			mem.tckn = t.value;
		} else if ( t && 'eru_vnv_phone' === t.id ) {
			mem.phone = t.value;
		}
	} );

	document.addEventListener( 'change', function ( e ) {
		var t = e.target;
		if ( t && 'eru_vnv_installments' === t.id ) {
			// The total changes with the fee line: any earlier verification no longer matches.
			setVerified( false );
			status( cfg.i18n.changed, false );
			if ( window.jQuery ) {
				window.jQuery( document.body ).trigger( 'update_checkout' );
			}
		}
	} );

	// WooCommerce re-renders the payment box on every totals refresh: restore what the customer typed.
	function restore() {
		var t = el( 'eru_vnv_tckn' );
		var p = el( 'eru_vnv_phone' );
		if ( t && ! t.value && mem.tckn ) {
			t.value = mem.tckn;
		}
		if ( p && ! p.value && mem.phone ) {
			p.value = mem.phone;
		}
		if ( mem.sent ) {
			showOtp( true );
		}
	}

	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'updated_checkout payment_method_selected', restore );
	}
}() );
