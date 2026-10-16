/**
 * Campaign Engine — campaign wizard: step navigation, fields per campaign type, the AND/OR condition
 * builder (it writes the JSON field the server reads) and the live preview.
 *
 * Every piece of DOM is built with createElement/textContent; no server or user string is ever
 * inserted as HTML.
 */
( function () {
	'use strict';

	var data = window.eruCeWizard || {};
	var i18n = data.i18n || {};
	var wizard = document.querySelector( '.eru-ce-wizard' );

	if ( ! wizard ) {
		return;
	}

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( 'className' === key ) {
				node.className = attrs[ key ];
			} else {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		if ( undefined !== text && null !== text ) {
			node.textContent = String( text );
		}
		return node;
	}

	function field( name ) {
		return wizard.querySelector( '[name="' + name + '"]' );
	}

	function checkedValue( name ) {
		var node = wizard.querySelector( '[name="' + name + '"]:checked' );
		return node ? node.value : '';
	}

	/* ------------------------------------------------------------------ steps */
	var sections = Array.prototype.slice.call( wizard.querySelectorAll( '.eru-ce-step' ) );
	var tabs = Array.prototype.slice.call( wizard.querySelectorAll( '.eru-ce-step-tab' ) );
	var current = parseInt( wizard.getAttribute( 'data-start' ) || '1', 10 );

	sections.forEach( function ( section, index ) {
		if ( section.querySelector( '.eru-ce-field-error' ) && 1 === current ) {
			current = index + 1;
		}
		if ( section.querySelector( '.eru-ce-field-error' ) ) {
			var tab = tabs[ index ];
			if ( tab ) {
				tab.classList.add( 'has-error' );
				tab.setAttribute( 'title', i18n.stepError || '' );
			}
		}
	} );

	function show( step ) {
		current = Math.max( 1, Math.min( sections.length, step ) );
		sections.forEach( function ( section, index ) {
			section.hidden = index + 1 !== current;
		} );
		tabs.forEach( function ( tab, index ) {
			tab.classList.toggle( 'is-current', index + 1 === current );
			tab.setAttribute( 'aria-current', index + 1 === current ? 'step' : 'false' );
		} );
		wizard.querySelector( '.eru-ce-prev' ).disabled = 1 === current;
		wizard.querySelector( '.eru-ce-next' ).disabled = sections.length === current;
	}

	wizard.classList.add( 'is-js' );
	tabs.forEach( function ( tab ) {
		tab.addEventListener( 'click', function () {
			show( parseInt( tab.getAttribute( 'data-step' ), 10 ) );
		} );
	} );
	wizard.querySelector( '.eru-ce-prev' ).addEventListener( 'click', function () {
		show( current - 1 );
	} );
	wizard.querySelector( '.eru-ce-next' ).addEventListener( 'click', function () {
		show( current + 1 );
	} );
	show( current );

	/* ------------------------------------------------------------------ fields per type */
	function syncType() {
		var type = checkedValue( 'eru_ce[type]' );
		wizard.querySelectorAll( '.eru-ce-for' ).forEach( function ( block ) {
			block.hidden = -1 === ( ' ' + block.getAttribute( 'data-for' ) + ' ' ).indexOf( ' ' + type + ' ' );
		} );
		wizard.querySelectorAll( '.eru-ce-not-for' ).forEach( function ( block ) {
			block.hidden = -1 !== ( ' ' + block.getAttribute( 'data-for' ) + ' ' ).indexOf( ' ' + type + ' ' );
		} );
		syncExclusive();
	}

	function syncExclusive() {
		var box = wizard.querySelector( '.eru-ce-exclusive' );
		var warning = wizard.querySelector( '.eru-ce-exclusive-warning' );
		var type = checkedValue( 'eru_ce[type]' );
		var extra = [];
		try {
			extra = JSON.parse( field( 'eru_ce[extra_actions]' ).value || '[]' ) || [];
		} catch ( e ) {
			extra = [];
		}
		var lines = ( data.lineTypes || [] ).indexOf( type ) !== -1 || extra.some( function ( row ) {
			return row && ( data.lineTypes || [] ).indexOf( row.type ) !== -1;
		} );
		if ( warning && box ) {
			warning.hidden = ! ( box.checked && ! lines );
		}
	}

	function syncRecurrence() {
		var freq = field( 'eru_ce[recurrence][freq]' );
		var box = wizard.querySelector( '.eru-ce-rec' );
		if ( ! freq || ! box ) {
			return;
		}
		var value = freq.value;
		box.hidden = 'none' === value;
		var visible = {
			'eru_ce[recurrence][start]': [ 'daily', 'weekly', 'monthly' ],
			'eru_ce[recurrence][end]': [ 'daily', 'weekly', 'monthly' ],
			'eru_ce[recurrence][month_days]': [ 'monthly' ],
			'eru_ce[recurrence][from_day]': [ 'weekly_span' ],
			'eru_ce[recurrence][from]': [ 'weekly_span' ],
			'eru_ce[recurrence][to_day]': [ 'weekly_span' ],
			'eru_ce[recurrence][to]': [ 'weekly_span' ]
		};
		Object.keys( visible ).forEach( function ( name ) {
			var input = field( name );
			if ( input && input.closest( 'p' ) ) {
				input.closest( 'p' ).hidden = visible[ name ].indexOf( value ) === -1;
			}
		} );
		var days = box.querySelector( 'fieldset' );
		if ( days ) {
			days.hidden = 'weekly' !== value;
		}
	}

	wizard.querySelectorAll( '[name="eru_ce[type]"]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', syncType );
	} );
	var exclusiveBox = wizard.querySelector( '.eru-ce-exclusive' );
	if ( exclusiveBox ) {
		exclusiveBox.addEventListener( 'change', syncExclusive );
	}
	if ( field( 'eru_ce[recurrence][freq]' ) ) {
		field( 'eru_ce[recurrence][freq]' ).addEventListener( 'change', syncRecurrence );
	}
	syncType();
	syncRecurrence();

	/* ------------------------------------------------------------------ condition builder */
	var builder = wizard.querySelector( '.eru-ce-builder' );
	var json = wizard.querySelector( '.eru-ce-rules-json' );
	var catalogue = {};
	( data.conditions || [] ).forEach( function ( c ) {
		catalogue[ c.id ] = c;
	} );
	var tree = null;

	function opsFor( kind ) {
		switch ( kind ) {
			case 'money':
			case 'int':
				return [ '>=', '>', '<=', '<', '==', '!=', 'between' ];
			case 'ids':
			case 'list':
			case 'weekday':
			case 'attribute':
				return [ 'in', 'not_in' ];
			default:
				return [];
		}
	}

	function depth( node ) {
		if ( ! node || ! node.children ) {
			return 0;
		}
		var max = 0;
		node.children.forEach( function ( child ) {
			max = Math.max( max, depth( child ) );
		} );
		return 1 + max;
	}

	function load() {
		var raw = json.value.trim();
		builder.textContent = '';
		if ( '' === raw ) {
			tree = { op: 'and', children: [] };
		} else {
			try {
				tree = JSON.parse( raw );
			} catch ( e ) {
				builder.appendChild( el( 'p', { className: 'eru-ce-field-error' }, i18n.badJson ) );
				tree = null;
				return;
			}
			if ( tree && tree.condition ) {
				tree = { op: 'and', children: [ tree ] };
			}
		}
		if ( ! tree || 'object' !== typeof tree || depth( tree ) > 2 ) {
			builder.appendChild( el( 'p', { className: 'description' }, i18n.jsonOnly ) );
			tree = null;
			return;
		}
		tree.children = tree.children || [];
		render();
	}

	function save() {
		if ( ! tree ) {
			return;
		}
		json.value = tree.children.length ? JSON.stringify( tree ) : '';
	}

	function list( value ) {
		if ( Array.isArray( value ) ) {
			return value.map( String );
		}
		return String( undefined === value || null === value ? '' : value ).split( ',' ).map( function ( s ) {
			return s.trim();
		} ).filter( function ( s ) {
			return '' !== s;
		} );
	}

	function render() {
		builder.textContent = '';
		builder.appendChild( group( tree, 0, null ) );
	}

	function group( node, level, parent ) {
		var box = el( 'div', { className: 'eru-ce-group' + ( level ? ' eru-ce-group--nested' : '' ) } );
		var head = el( 'div', { className: 'eru-ce-group-head' } );
		var op = el( 'select', { 'aria-label': i18n.and } );
		[ 'and', 'or' ].forEach( function ( value ) {
			var option = el( 'option', { value: value }, i18n[ value ] );
			if ( ( node.op || 'and' ) === value ) {
				option.selected = true;
			}
			op.appendChild( option );
		} );
		op.addEventListener( 'change', function () {
			node.op = op.value;
			save();
		} );
		head.appendChild( op );
		if ( parent ) {
			var drop = el( 'button', { type: 'button', className: 'button-link eru-ce-remove' }, i18n.remove );
			drop.addEventListener( 'click', function () {
				parent.children.splice( parent.children.indexOf( node ), 1 );
				save();
				render();
			} );
			head.appendChild( drop );
		}
		box.appendChild( head );

		var children = el( 'div', { className: 'eru-ce-children' } );
		if ( ! node.children.length && ! level ) {
			children.appendChild( el( 'p', { className: 'description' }, i18n.empty ) );
		}
		node.children.forEach( function ( child ) {
			children.appendChild( child.children ? group( child, level + 1, node ) : leaf( child, node ) );
		} );
		box.appendChild( children );

		var actions = el( 'div', { className: 'eru-ce-group-actions' } );
		var add = el( 'button', { type: 'button', className: 'button' }, i18n.addCondition );
		add.addEventListener( 'click', function () {
			node.children.push( { condition: 'cart.subtotal', cmp: '>=', value: '' } );
			save();
			render();
		} );
		actions.appendChild( add );
		if ( ! level ) {
			var addGroup = el( 'button', { type: 'button', className: 'button' }, i18n.addGroup );
			addGroup.addEventListener( 'click', function () {
				node.children.push( { op: 'or', children: [ { condition: 'date.weekday', cmp: 'in', value: [] } ] } );
				save();
				render();
			} );
			actions.appendChild( addGroup );
		}
		box.appendChild( actions );
		return box;
	}

	function leaf( node, parent ) {
		var row = el( 'div', { className: 'eru-ce-leaf' } );
		var pick = el( 'select', { 'aria-label': i18n.addCondition } );
		var groups = {};
		( data.conditions || [] ).forEach( function ( c ) {
			if ( ! groups[ c.group ] ) {
				groups[ c.group ] = el( 'optgroup', { label: c.group } );
				pick.appendChild( groups[ c.group ] );
			}
			var option = el( 'option', { value: c.id }, c.label );
			if ( c.id === node.condition ) {
				option.selected = true;
			}
			groups[ c.group ].appendChild( option );
		} );
		if ( ! catalogue[ node.condition ] ) {
			var unknown = el( 'option', { value: node.condition || '' }, node.condition || '?' );
			unknown.selected = true;
			pick.appendChild( unknown );
		}
		pick.addEventListener( 'change', function () {
			var def = catalogue[ pick.value ] || {};
			Object.keys( node ).forEach( function ( key ) {
				delete node[ key ];
			} );
			node.condition = pick.value;
			if ( def.cmp ) {
				node.cmp = def.cmp;
			}
			if ( 'flag' === def.kind ) {
				node.value = true;
			} else if ( 'daterange' !== def.kind && 'timerange' !== def.kind ) {
				node.value = 'list' === def.kind || 'ids' === def.kind || 'weekday' === def.kind || 'attribute' === def.kind ? [] : '';
			}
			save();
			render();
		} );
		row.appendChild( pick );

		var def = catalogue[ node.condition ] || { kind: 'text' };
		var ops = opsFor( def.kind );
		if ( ops.length ) {
			var cmp = el( 'select', { 'aria-label': 'cmp' } );
			ops.forEach( function ( op ) {
				var option = el( 'option', { value: op }, ( data.operators || {} )[ op ] || op );
				if ( ( node.cmp || def.cmp ) === op ) {
					option.selected = true;
				}
				cmp.appendChild( option );
			} );
			cmp.addEventListener( 'change', function () {
				var wasBetween = 'between' === node.cmp;
				node.cmp = cmp.value;
				if ( 'between' === node.cmp && ! Array.isArray( node.value ) ) {
					node.value = [ node.value || '', '' ];
				} else if ( wasBetween && Array.isArray( node.value ) ) {
					node.value = node.value[ 0 ] || '';
				}
				save();
				render();
			} );
			row.appendChild( cmp );
		}

		row.appendChild( valueInputs( node, def ) );

		var drop = el( 'button', { type: 'button', className: 'button-link eru-ce-remove' }, i18n.remove );
		drop.addEventListener( 'click', function () {
			parent.children.splice( parent.children.indexOf( node ), 1 );
			save();
			render();
		} );
		row.appendChild( drop );
		return row;
	}

	function textInput( value, label, onInput, type ) {
		var input = el( 'input', { type: type || 'text', 'aria-label': label, placeholder: label } );
		input.value = undefined === value || null === value ? '' : String( value );
		input.addEventListener( 'input', function () {
			onInput( input.value );
			save();
		} );
		return input;
	}

	function valueInputs( node, def ) {
		var box = el( 'span', { className: 'eru-ce-value' } );
		var suffix = 'money' === def.kind ? ' (' + ( data.currency || '' ) + ')' : '';
		switch ( def.kind ) {
			case 'money':
			case 'int':
				if ( 'between' === node.cmp ) {
					var pair = Array.isArray( node.value ) ? node.value : [ '', '' ];
					node.value = pair;
					box.appendChild( textInput( pair[ 0 ], i18n.from + suffix, function ( v ) {
						pair[ 0 ] = v;
					} ) );
					box.appendChild( textInput( pair[ 1 ], i18n.to + suffix, function ( v ) {
						pair[ 1 ] = v;
					} ) );
				} else {
					box.appendChild( textInput( Array.isArray( node.value ) ? node.value[ 0 ] : node.value, ( def.label || '' ) + suffix, function ( v ) {
						node.value = v;
					} ) );
				}
				break;
			case 'flag':
				var flag = el( 'select', { 'aria-label': def.label || '' } );
				[ [ 'true', i18n.yes ], [ 'false', i18n.no ] ].forEach( function ( pairing ) {
					var option = el( 'option', { value: pairing[ 0 ] }, pairing[ 1 ] );
					if ( String( false !== node.value ) === pairing[ 0 ] ) {
						option.selected = true;
					}
					flag.appendChild( option );
				} );
				flag.addEventListener( 'change', function () {
					node.value = 'true' === flag.value;
					save();
				} );
				box.appendChild( flag );
				break;
			case 'weekday':
				var chosen = list( node.value );
				node.value = chosen;
				[ 'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU' ].forEach( function ( code, index ) {
					var label = el( 'label', { className: 'eru-ce-day' } );
					var box2 = el( 'input', { type: 'checkbox', value: code } );
					box2.checked = chosen.indexOf( code ) !== -1;
					box2.addEventListener( 'change', function () {
						var at = node.value.indexOf( code );
						if ( box2.checked && -1 === at ) {
							node.value.push( code );
						} else if ( ! box2.checked && -1 !== at ) {
							node.value.splice( at, 1 );
						}
						save();
					} );
					label.appendChild( box2 );
					label.appendChild( document.createTextNode( ' ' + ( ( data.days || [] )[ index ] || code ) ) );
					box.appendChild( label );
				} );
				break;
			case 'daterange':
				box.appendChild( textInput( node.from, i18n.from, function ( v ) {
					node.from = v;
				}, 'date' ) );
				box.appendChild( textInput( node.to, i18n.to, function ( v ) {
					node.to = v;
				}, 'date' ) );
				break;
			case 'timerange':
				box.appendChild( textInput( node.from, i18n.from, function ( v ) {
					node.from = v;
				}, 'time' ) );
				box.appendChild( textInput( node.to, i18n.to, function ( v ) {
					node.to = v;
				}, 'time' ) );
				break;
			case 'attribute':
				box.appendChild( textInput( node.attribute, i18n.attribute, function ( v ) {
					node.attribute = v.trim();
				} ) );
				box.appendChild( textInput( list( node.value ).join( ', ' ), i18n.listHint, function ( v ) {
					node.value = list( v );
				} ) );
				break;
			default:
				if ( def.options && def.options.length ) {
					var multi = el( 'select', { multiple: 'multiple', 'aria-label': def.label || '' } );
					var values = list( node.value );
					node.value = values;
					def.options.forEach( function ( opt ) {
						var option = el( 'option', { value: opt.value }, opt.label );
						option.selected = values.indexOf( String( opt.value ) ) !== -1;
						multi.appendChild( option );
					} );
					multi.addEventListener( 'change', function () {
						node.value = Array.prototype.filter.call( multi.options, function ( o ) {
							return o.selected;
						} ).map( function ( o ) {
							return o.value;
						} );
						save();
					} );
					box.appendChild( multi );
				} else {
					box.appendChild( textInput( list( node.value ).join( ', ' ), ( def.label || '' ) + ' — ' + i18n.listHint, function ( v ) {
						node.value = list( v );
					} ) );
				}
		}
		return box;
	}

	if ( builder && json ) {
		json.addEventListener( 'change', load );
		load();
	}

	/* ------------------------------------------------------------------ preview */
	var preview = document.querySelector( '.eru-ce-preview' );

	if ( ! preview ) {
		return;
	}

	var cartSelect = preview.querySelector( '.eru-ce-preview-cart' );
	var custom = preview.querySelector( '.eru-ce-preview-custom' );
	var result = preview.querySelector( '.eru-ce-preview-result' );

	cartSelect.addEventListener( 'change', function () {
		custom.hidden = 'custom' !== cartSelect.value;
	} );

	function table( lines ) {
		var t = el( 'table', { className: 'widefat striped eru-ce-preview-table' } );
		var head = el( 'tr' );
		[ i18n.product, i18n.qty, i18n.line, i18n.discount ].forEach( function ( h ) {
			head.appendChild( el( 'th', {}, h ) );
		} );
		var thead = el( 'thead' );
		thead.appendChild( head );
		t.appendChild( thead );
		var body = el( 'tbody' );
		lines.forEach( function ( line ) {
			var tr = el( 'tr' );
			tr.appendChild( el( 'td', {}, line.name ) );
			tr.appendChild( el( 'td', {}, line.qty ) );
			tr.appendChild( el( 'td', {}, line.line ) );
			tr.appendChild( el( 'td', {}, line.discount_minor > 0 ? '−' + line.discount : '—' ) );
			body.appendChild( tr );
		} );
		t.appendChild( body );
		return t;
	}

	function bullets( title, items, className ) {
		var wrap = el( 'div', { className: className || '' } );
		wrap.appendChild( el( 'p', {}, title ) );
		var ul = el( 'ul' );
		items.forEach( function ( item ) {
			ul.appendChild( el( 'li', {}, item ) );
		} );
		wrap.appendChild( ul );
		return wrap;
	}

	preview.querySelector( '.eru-ce-preview-run' ).addEventListener( 'click', function () {
		var form = document.getElementById( 'post' );
		var body = new FormData();
		if ( form ) {
			new FormData( form ).forEach( function ( value, key ) {
				if ( 0 === key.indexOf( 'eru_ce[' ) ) {
					body.append( key, value );
				}
			} );
		}
		body.append( 'action', data.action );
		body.append( 'nonce', data.nonce );
		body.append( 'cart', cartSelect.value );
		body.append( 'cart_lines', preview.querySelector( '.eru-ce-preview-lines' ).value );
		body.append( 'customer', preview.querySelector( '.eru-ce-preview-customer' ).value );
		body.append( 'post_id', preview.getAttribute( 'data-post' ) );
		result.textContent = '';
		result.appendChild( el( 'p', { className: 'description' }, i18n.running ) );

		fetch( data.ajax, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( response ) {
			return response.json();
		} ).then( function ( json ) {
			result.textContent = '';
			if ( ! json || ! json.success ) {
				var errors = json && json.data && ( json.data.errors || ( json.data.message ? [ json.data.message ] : null ) );
				result.appendChild( bullets( i18n.failed, errors || [], 'eru-ce-field-error' ) );
				return;
			}
			var d = json.data;
			if ( d.lines && d.lines.length ) {
				result.appendChild( table( d.lines ) );
			}
			var sums = el( 'p', { className: 'eru-ce-preview-sums' } );
			sums.appendChild( el( 'span', {}, i18n.subtotal + ': ' + d.subtotal ) );
			sums.appendChild( el( 'br' ) );
			sums.appendChild( el( 'strong', {}, i18n.discount + ': −' + d.total_discount ) );
			sums.appendChild( el( 'br' ) );
			sums.appendChild( el( 'span', {}, i18n.total + ': ' + d.total ) );
			result.appendChild( sums );
			if ( d.free_shipping ) {
				result.appendChild( el( 'p', {}, i18n.freeShipping ) );
			}
			if ( d.reward ) {
				result.appendChild( el( 'p', {}, i18n.reward + ' ' + d.reward ) );
			}
			if ( ! d.applied && d.trace && d.trace.length ) {
				result.appendChild( bullets( i18n.notApplied, d.trace, 'eru-ce-preview-why' ) );
			} else if ( d.trace && d.trace.length ) {
				result.appendChild( bullets( i18n.notes, d.trace, 'eru-ce-preview-why' ) );
			}
			if ( d.warnings && d.warnings.length ) {
				result.appendChild( bullets( '', d.warnings, 'eru-ce-preview-warn' ) );
			}
		} ).catch( function () {
			result.textContent = '';
			result.appendChild( el( 'p', { className: 'eru-ce-field-error' }, i18n.failed ) );
		} );
	} );
}() );
