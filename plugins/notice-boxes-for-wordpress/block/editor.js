/* Notice Box block: plain JS against the wp.* globals, no build step. Rendering happens in PHP. */
( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var c = wp.components;

	wp.blocks.registerBlockType( 'eru/notice-box', {
		edit: function ( props ) {
			var a = props.attributes;
			var blockProps = be.useBlockProps( {
				className: 'eru-nb eru-nb--' + a.type + ' eru-nb--' + a.variant
			} );
			return el(
				wp.element.Fragment,
				null,
				el(
					be.InspectorControls,
					null,
					el(
						c.PanelBody,
						{ title: __( 'Kutu ayarları', 'notice-boxes-for-wordpress' ) },
						el( c.SelectControl, {
							label: __( 'Tür', 'notice-boxes-for-wordpress' ),
							value: a.type,
							options: [
								{ label: __( 'Bilgi', 'notice-boxes-for-wordpress' ), value: 'info' },
								{ label: __( 'Uyarı', 'notice-boxes-for-wordpress' ), value: 'warning' },
								{ label: __( 'Başarı', 'notice-boxes-for-wordpress' ), value: 'success' },
								{ label: __( 'Hata', 'notice-boxes-for-wordpress' ), value: 'error' }
							],
							onChange: function ( v ) { props.setAttributes( { type: v } ); }
						} ),
						el( c.SelectControl, {
							label: __( 'Görünüm', 'notice-boxes-for-wordpress' ),
							value: a.variant,
							options: [
								{ label: __( 'Yumuşak', 'notice-boxes-for-wordpress' ), value: 'soft' },
								{ label: __( 'Dolgulu', 'notice-boxes-for-wordpress' ), value: 'solid' },
								{ label: __( 'Çerçeveli', 'notice-boxes-for-wordpress' ), value: 'outline' }
							],
							onChange: function ( v ) { props.setAttributes( { variant: v } ); }
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( 'div', { className: 'eru-nb__body' },
						el( be.RichText, {
							tagName: 'p',
							className: 'eru-nb__title',
							value: a.title,
							placeholder: __( 'Başlık (isteğe bağlı)', 'notice-boxes-for-wordpress' ),
							allowedFormats: [],
							onChange: function ( v ) { props.setAttributes( { title: v } ); }
						} ),
						el( 'div', { className: 'eru-nb__content' }, el( be.InnerBlocks, {
							template: [ [ 'core/paragraph', { placeholder: __( 'Kutu metni…', 'notice-boxes-for-wordpress' ) } ] ]
						} ) )
					)
				)
			);
		},
		save: function () {
			return el( be.InnerBlocks.Content );
		}
	} );
} )( window.wp );
