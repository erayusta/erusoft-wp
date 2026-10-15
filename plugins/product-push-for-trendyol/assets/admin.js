/* Product Push for Trendyol: pickers, repeaters, attribute form and product box buttons.
 * Every string that comes from Trendyol or the server is inserted with textContent / createElement. */
( function () {
	'use strict';
	var cfg = window.eruPpt;
	if ( ! cfg ) {
		return;
	}
	var t = cfg.i18n || {};

	function el( tag, props, kids ) {
		var n = document.createElement( tag );
		Object.keys( props || {} ).forEach( function ( k ) {
			if ( 'text' === k ) {
				n.textContent = String( props[ k ] );
			} else if ( 'className' === k ) {
				n.className = props[ k ];
			} else {
				n.setAttribute( k, String( props[ k ] ) );
			}
		} );
		( kids || [] ).forEach( function ( c ) {
			if ( c ) {
				n.appendChild( c );
			}
		} );
		return n;
	}

	function clear( n ) {
		while ( n && n.firstChild ) {
			n.removeChild( n.firstChild );
		}
	}

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
			return r.json();
		} );
	}

	function errorText( j ) {
		if ( j && j.data && j.data.message ) {
			return String( j.data.message );
		}
		return t.error;
	}

	function say( node, text, ok ) {
		if ( node ) {
			node.textContent = text;
			node.className = 'eru-ppt-msg ' + ( ok ? 'is-ok' : 'is-error' );
		}
	}

	/* ---------- search-and-pick widgets (category / brand) ---------- */
	var timers = new WeakMap();
	document.addEventListener( 'input', function ( e ) {
		var q = e.target.closest ? e.target.closest( '.eru-ppt-pick-q' ) : null;
		if ( ! q ) {
			return;
		}
		var pick = q.closest( '.eru-ppt-pick' );
		clearTimeout( timers.get( pick ) );
		timers.set( pick, setTimeout( function () {
			search( pick, q.value );
		}, 300 ) );
	} );

	function search( pick, value ) {
		var list = pick.querySelector( '.eru-ppt-pick-results' );
		clear( list );
		if ( value.trim().length < 2 ) {
			list.hidden = true;
			return;
		}
		list.hidden = false;
		list.appendChild( el( 'li', { text: t.searching } ) );
		var action = 'brand' === pick.getAttribute( 'data-kind' ) ? 'eru_ppt_brands' : 'eru_ppt_cat_search';
		post( action, { q: value } ).then( function ( j ) {
			clear( list );
			if ( ! j || ! j.success ) {
				list.appendChild( el( 'li', { className: 'is-error', text: errorText( j ) } ) );
				return;
			}
			if ( ! j.data.length ) {
				list.appendChild( el( 'li', { text: t.noResult } ) );
				return;
			}
			j.data.forEach( function ( r ) {
				var label = String( r.path || r.name || '' );
				var b = el( 'button', { type: 'button', className: 'button-link', text: label + ' (#' + r.id + ')' } );
				b.addEventListener( 'click', function () {
					choose( pick, String( r.id ), label );
				} );
				list.appendChild( el( 'li', {}, [ b ] ) );
			} );
		} ).catch( function () {
			clear( list );
			list.appendChild( el( 'li', { className: 'is-error', text: t.error } ) );
		} );
	}

	function choose( pick, id, label ) {
		pick.querySelector( '.eru-ppt-pick-id' ).value = id;
		pick.querySelector( '.eru-ppt-pick-name' ).value = label;
		pick.querySelector( '.eru-ppt-pick-current' ).textContent = id ? label + ' (#' + id + ')' : t.none;
		var list = pick.querySelector( '.eru-ppt-pick-results' );
		clear( list );
		list.hidden = true;
		pick.querySelector( '.eru-ppt-pick-q' ).value = '';
		pick.dispatchEvent( new CustomEvent( 'eru-ppt-picked', { bubbles: true, detail: { id: id } } ) );
	}

	document.addEventListener( 'click', function ( e ) {
		var c = e.target.closest ? e.target.closest( '.eru-ppt-pick-clear' ) : null;
		if ( c ) {
			e.preventDefault();
			choose( c.closest( '.eru-ppt-pick' ), '', '' );
		}
	} );

	/* ---------- repeaters ---------- */
	document.addEventListener( 'click', function ( e ) {
		var add = e.target.closest ? e.target.closest( '.eru-ppt-add' ) : null;
		if ( add ) {
			e.preventDefault();
			var target = add.getAttribute( 'data-target' );
			var tpl = document.getElementById( 'eru-ppt-tpl-' + target );
			var body = document.querySelector( '.eru-ppt-repeater[data-repeater="' + target + '"] tbody' );
			if ( ! tpl || ! body ) {
				return;
			}
			var idx = String( Date.now() );
			var frag = tpl.content.cloneNode( true );
			frag.querySelectorAll( '[name]' ).forEach( function ( n ) {
				n.setAttribute( 'name', n.getAttribute( 'name' ).replace( '__i__', idx ) );
			} );
			body.appendChild( frag );
			return;
		}
		var rm = e.target.closest ? e.target.closest( '.eru-ppt-remove' ) : null;
		if ( rm ) {
			e.preventDefault();
			rm.closest( 'tr' ).remove();
		}
	} );

	/* ---------- settings: addresses, category refresh, attribute picker ---------- */
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.eru-ppt-load-addresses, .eru-ppt-cat-refresh, .eru-ppt-attr-load' ) : null;
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		if ( btn.classList.contains( 'eru-ppt-load-addresses' ) ) {
			var msg = btn.parentNode.querySelector( '.eru-ppt-msg' );
			say( msg, t.searching, true );
			post( 'eru_ppt_addresses', {} ).then( function ( j ) {
				if ( ! j || ! j.success ) {
					say( msg, errorText( j ), false );
					return;
				}
				btn.parentNode.querySelectorAll( 'select' ).forEach( function ( sel ) {
					var cur = sel.getAttribute( 'data-current' ) || '';
					while ( sel.options.length > 1 ) {
						sel.remove( 1 );
					}
					j.data.forEach( function ( a ) {
						var o = el( 'option', { value: a.id, text: '#' + a.id + ' · ' + a.type + ' · ' + a.address } );
						if ( String( a.id ) === cur ) {
							o.selected = true;
						}
						sel.appendChild( o );
					} );
				} );
				say( msg, '', true );
			} ).catch( function () {
				say( msg, t.error, false );
			} );
			return;
		}
		if ( btn.classList.contains( 'eru-ppt-cat-refresh' ) ) {
			var m = btn.parentNode.querySelector( '.eru-ppt-msg' );
			say( m, t.searching, true );
			post( 'eru_ppt_cat_refresh', {} ).then( function ( j ) {
				say( m, j && j.success ? String( j.data.message ) : errorText( j ), !! ( j && j.success ) );
			} ).catch( function () {
				say( m, t.error, false );
			} );
			return;
		}
		// Attribute map row: list the attributes of the row's Trendyol category.
		var row = btn.closest( 'tr' );
		var cat = row.querySelector( '.eru-ppt-pick-id' ).value;
		var sel = row.querySelector( '.eru-ppt-attr-select' );
		var info = row.querySelector( '.eru-ppt-attr-values' );
		if ( ! cat ) {
			info.textContent = t.pickCat;
			return;
		}
		info.textContent = t.loading;
		post( 'eru_ppt_cat_attrs', { cat: cat } ).then( function ( j ) {
			if ( ! j || ! j.success ) {
				info.textContent = errorText( j );
				return;
			}
			clear( sel );
			sel.appendChild( el( 'option', { value: '', text: t.pickAttr } ) );
			j.data.forEach( function ( a ) {
				sel.appendChild( el( 'option', { value: a.id, text: a.name + ' (#' + a.id + ')' + ( a.varianter ? ' *' : '' ) } ) );
			} );
			sel.hidden = false;
			info.textContent = '';
			sel.onchange = function () {
				row.querySelector( '.eru-ppt-attr-id' ).value = sel.value;
				var a = j.data.filter( function ( x ) {
					return String( x.id ) === sel.value;
				} )[ 0 ];
				info.textContent = a ? a.values.slice( 0, 40 ).map( function ( v ) {
					return v.name + '=' + v.id;
				} ).join( ', ' ) + ( a.values.length > 40 ? ' …' : '' ) : '';
			};
		} ).catch( function () {
			info.textContent = t.error;
		} );
	} );

	/* ---------- product box: attribute form ---------- */
	function renderAttrs( host, attrs, saved ) {
		clear( host );
		if ( ! attrs.length ) {
			host.appendChild( el( 'p', { className: 'description', text: t.noResult } ) );
			return;
		}
		var variable = '1' === host.getAttribute( 'data-variable' );
		var tbody = el( 'tbody' );
		attrs.forEach( function ( a ) {
			var cur = saved[ a.id ] || {};
			var name = el( 'td' );
			name.appendChild( el( a.required ? 'strong' : 'span', { text: a.name } ) );
			if ( a.required ) {
				name.appendChild( el( 'span', { className: 'eru-ppt-req', text: ' (' + t.required + ')' } ) );
			}
			if ( a.varianter && variable ) {
				name.appendChild( el( 'div', { className: 'description', text: t.variant } ) );
			}
			var cell = el( 'td' );
			if ( a.values.length ) {
				var sel = el( 'select', { name: 'eru_ppt[attr][' + a.id + ']', 'aria-label': a.name } );
				sel.appendChild( el( 'option', { value: '', text: '—' } ) );
				a.values.forEach( function ( v ) {
					var o = el( 'option', { value: v.id, text: v.name } );
					if ( undefined !== cur.v && String( cur.v ) === String( v.id ) ) {
						o.selected = true;
					}
					sel.appendChild( o );
				} );
				cell.appendChild( sel );
			}
			if ( a.allowCustom ) {
				var inp = el( 'input', { type: 'text', name: 'eru_ppt[attr_custom][' + a.id + ']', placeholder: t.custom, 'aria-label': a.name } );
				inp.value = undefined !== cur.c ? String( cur.c ) : '';
				cell.appendChild( document.createTextNode( ' ' ) );
				cell.appendChild( inp );
			}
			tbody.appendChild( el( 'tr', {}, [ name, cell ] ) );
		} );
		host.appendChild( el( 'table', { className: 'widefat striped eru-ppt-attr-table' }, [
			el( 'thead', {}, [ el( 'tr', {}, [ el( 'th', { text: t.attr } ), el( 'th', { text: t.value } ) ] ) ] ),
			tbody,
		] ) );
		host.appendChild( el( 'input', { type: 'hidden', name: 'eru_ppt[attrs_loaded]', value: '1' } ) );
	}
	window.eruPptRenderAttrs = renderAttrs;

	function loadAttrs( host, cat, tries ) {
		tries = tries || 0;
		var saved = {};
		try {
			saved = JSON.parse( host.getAttribute( 'data-saved' ) || '{}' ) || {};
		} catch ( err ) {
			saved = {};
		}
		clear( host );
		if ( ! cat || '0' === String( cat ) ) {
			host.appendChild( el( 'p', { className: 'description', text: t.pickCat } ) );
			return;
		}
		host.appendChild( el( 'p', { className: 'description', text: t.loading } ) );
		post( 'eru_ppt_cat_attrs', { cat: cat } ).then( function ( j ) {
			if ( ! j || ! j.success ) {
				clear( host );
				var waiting = !! ( j && j.data && j.data.retry ) && tries < 12;
				host.appendChild( el( 'p', { className: waiting ? 'description' : 'eru-ppt-error', text: errorText( j ) } ) );
				if ( waiting ) {
					// The data is being fetched in the background (Action Scheduler): ask the cache again.
					setTimeout( function () {
						loadAttrs( host, cat, tries + 1 );
					}, 10000 );
				}
				return;
			}
			renderAttrs( host, j.data, saved );
		} ).catch( function () {
			clear( host );
			host.appendChild( el( 'p', { className: 'eru-ppt-error', text: t.error } ) );
		} );
	}

	var box = document.querySelector( '.eru-ppt-box' );
	if ( box ) {
		var host = box.querySelector( '.eru-ppt-attrs' );
		if ( host ) {
			loadAttrs( host, host.getAttribute( 'data-cat' ) );
			box.addEventListener( 'eru-ppt-picked', function ( e ) {
				var pick = e.target;
				if ( 'category' !== pick.getAttribute( 'data-kind' ) ) {
					return;
				}
				loadAttrs( host, e.detail.id || host.getAttribute( 'data-cat' ) );
			} );
		}
		box.addEventListener( 'click', function ( e ) {
			var b = e.target.closest ? e.target.closest( '.eru-ppt-do' ) : null;
			if ( ! b ) {
				return;
			}
			e.preventDefault();
			var what = b.getAttribute( 'data-do' );
			var ask = t.confirm && t.confirm[ what ];
			if ( ask && ! window.confirm( ask ) ) {
				return;
			}
			var out = box.querySelector( '.eru-ppt-result' );
			clear( out );
			b.disabled = true;
			post( 'eru_ppt_product', { product: box.getAttribute( 'data-product' ), do: what } ).then( function ( j ) {
				b.disabled = false;
				var ok = !! ( j && j.success );
				var msgs = j && j.data && j.data.messages ? j.data.messages : [ t.error ];
				out.className = 'eru-ppt-result ' + ( ok ? 'is-ok' : 'is-error' );
				msgs.forEach( function ( m ) {
					out.appendChild( el( 'li', { text: m } ) );
				} );
				if ( ok && j.data.reload ) {
					setTimeout( function () {
						window.location.reload();
					}, 1200 );
				}
			} ).catch( function () {
				b.disabled = false;
				out.appendChild( el( 'li', { text: t.error } ) );
			} );
		} );
	}
} )();
