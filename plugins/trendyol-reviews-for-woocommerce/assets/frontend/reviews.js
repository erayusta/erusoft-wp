/* Trendyol Reviews for WooCommerce — review list (pagination, sorting, filtering) + lightbox */
/* global eruTyrFrontend */
( function () {
	'use strict';

	/* ------------------------------------------------------------------ *
	 * Lightbox
	 * ------------------------------------------------------------------ */
	function openLightbox( src ) {
		var overlay = document.createElement( 'div' );
		overlay.className = 'eru-tyr-lightbox';
		var button = document.createElement( 'button' );
		var img = document.createElement( 'img' );
		button.type = 'button';
		button.className = 'eru-tyr-lightbox-close';
		button.setAttribute( 'aria-label', 'Kapat' );
		button.textContent = '×';
		img.src = src;
		img.alt = '';
		overlay.appendChild( button );
		overlay.appendChild( img );

		function close() {
			overlay.remove();
			document.removeEventListener( 'keydown', onKey );
		}
		function onKey( e ) {
			if ( 'Escape' === e.key ) {
				close();
			}
		}

		overlay.addEventListener( 'click', close );
		document.addEventListener( 'keydown', onKey );
		document.body.appendChild( overlay );
	}

	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest( 'a[data-eru-tyr-lightbox]' );
		if ( ! link ) {
			return;
		}
		e.preventDefault();
		openLightbox( link.getAttribute( 'href' ) );
	} );

	/* ------------------------------------------------------------------ *
	 * Review list: AJAX pagination / sorting / star filter
	 * ------------------------------------------------------------------ */
	var root = document.querySelector( '.eru-tyr-reviews[data-product]' );
	if ( ! root || 'undefined' === typeof eruTyrFrontend ) {
		return;
	}

	var productId = root.getAttribute( 'data-product' ),
		list = root.querySelector( '.eru-tyr-list' ),
		paginationWrap = root.querySelector( '.eru-tyr-pagination-wrap' ),
		sortSelect = root.querySelector( '.eru-tyr-sort' ),
		starSelect = root.querySelector( '.eru-tyr-star-filter' ),
		state = { page: 1, orderby: 'date_desc', star: 0 };

	if ( ! list ) {
		return;
	}

	function load( scroll ) {
		list.classList.add( 'eru-tyr-loading' );

		var params = new URLSearchParams( {
			action: 'eru_tyr_reviews',
			product: productId,
			page: state.page,
			orderby: state.orderby,
			star: state.star
		} );

		fetch( eruTyrFrontend.ajaxUrl + '?' + params.toString(), { credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				list.classList.remove( 'eru-tyr-loading' );
				if ( ! res || ! res.success ) {
					return;
				}
				list.innerHTML = res.data.list;
				if ( paginationWrap ) {
					paginationWrap.innerHTML = res.data.pagination;
				}
				state.page = res.data.page;
				if ( scroll ) {
					var target = root.querySelector( '.eru-tyr-toolbar' ) || root;
					target.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}
			} )
			.catch( function () {
				list.classList.remove( 'eru-tyr-loading' );
			} );
	}

	if ( sortSelect ) {
		sortSelect.addEventListener( 'change', function () {
			state.orderby = sortSelect.value;
			state.page = 1;
			load( false );
		} );
	}

	if ( starSelect ) {
		starSelect.addEventListener( 'change', function () {
			state.star = parseInt( starSelect.value, 10 ) || 0;
			state.page = 1;
			load( false );
		} );
	}

	root.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.eru-tyr-page-btn' );
		if ( ! btn || btn.disabled ) {
			return;
		}
		state.page += btn.classList.contains( 'eru-tyr-next' ) ? 1 : -1;
		if ( state.page < 1 ) {
			state.page = 1;
		}
		load( true );
	} );
} )();
