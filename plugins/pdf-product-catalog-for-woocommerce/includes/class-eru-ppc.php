<?php
/**
 * PDF catalogue: product collection, print-ready HTML layout, optional PDF engine hook, download endpoints.
 *
 * @package pdf-product-catalog-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_PPC {

	const OPTION = 'eru_ppc_settings';
	const TD     = 'pdf-product-catalog-for-woocommerce';

	public static function defaults() {
		return array(
			'title'        => __( 'Ürün Kataloğu', 'pdf-product-catalog-for-woocommerce' ),
			'subtitle'     => '',
			'note'         => '',
			'footer'       => '',
			'logo'         => 'site_icon',
			'columns'      => 3,
			'orientation'  => 'portrait',
			'show_image'   => 1,
			'show_sku'     => 1,
			'show_price'   => 1,
			'show_desc'    => 0,
			'in_stock_only' => 0,
			'front'        => 0,
		);
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function sanitize_settings( $in ) {
		$in   = is_array( $in ) ? $in : array();
		$d    = self::defaults();
		$logo = isset( $in['logo'] ) ? sanitize_key( $in['logo'] ) : 'site_icon';
		$title = isset( $in['title'] ) ? sanitize_text_field( (string) $in['title'] ) : '';
		return array(
			'title'         => '' === $title ? $d['title'] : substr( $title, 0, 120 ),
			'subtitle'      => isset( $in['subtitle'] ) ? substr( sanitize_text_field( (string) $in['subtitle'] ), 0, 200 ) : '',
			'note'          => isset( $in['note'] ) ? substr( sanitize_textarea_field( (string) $in['note'] ), 0, 600 ) : '',
			'footer'        => isset( $in['footer'] ) ? substr( sanitize_text_field( (string) $in['footer'] ), 0, 200 ) : '',
			'logo'          => in_array( $logo, array( 'site_icon', 'custom_logo', 'none' ), true ) ? $logo : 'site_icon',
			'columns'       => min( 4, max( 2, isset( $in['columns'] ) ? absint( $in['columns'] ) : 3 ) ),
			'orientation'   => isset( $in['orientation'] ) && 'landscape' === $in['orientation'] ? 'landscape' : 'portrait',
			'show_image'    => empty( $in['show_image'] ) ? 0 : 1,
			'show_sku'      => empty( $in['show_sku'] ) ? 0 : 1,
			'show_price'    => empty( $in['show_price'] ) ? 0 : 1,
			'show_desc'     => empty( $in['show_desc'] ) ? 0 : 1,
			'in_stock_only' => empty( $in['in_stock_only'] ) ? 0 : 1,
			'front'         => empty( $in['front'] ) ? 0 : 1,
		);
	}

	public static function init() {
		add_action( 'admin_post_eru_ppc_download', array( __CLASS__, 'admin_download' ) );
		if ( self::get_settings()['front'] ) {
			add_action( 'template_redirect', array( __CLASS__, 'front_download' ) );
			add_shortcode( 'eru_pdf_catalog', array( __CLASS__, 'shortcode' ) );
		}
	}

	/* --------------------------------------------------------------- storage */

	public static function dir( $sub = '' ) {
		$u    = wp_upload_dir( null, false );
		$base = trailingslashit( $u['basedir'] ) . 'eru-ppc';
		$dir  = '' === $sub ? $base : $base . '/' . $sub;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $base . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $base . '/index.php', "<?php\n// Silence is golden.\n" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $base . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		return $dir;
	}

	/* ------------------------------------------------------------- products */

	/** Products grouped by category: array of array( 'name' => string, 'products' => WC_Product[] ). */
	public static function collect( array $term_ids, array $s ) {
		$max = (int) apply_filters( 'eru_ppc_max_products', 500 );
		if ( ! $term_ids ) {
			$all      = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'parent' => 0, 'fields' => 'ids' ) );
			$term_ids = is_array( $all ) ? array_map( 'intval', $all ) : array();
		}
		$groups = array();
		$seen   = array();
		$left   = $max;
		foreach ( $term_ids as $tid ) {
			$term = get_term( (int) $tid, 'product_cat' );
			if ( ! $term || is_wp_error( $term ) || $left <= 0 ) {
				continue;
			}
			$args = array(
				'status'   => 'publish',
				'limit'    => $left,
				'orderby'  => 'title',
				'order'    => 'ASC',
				'category' => array( $term->slug ),
				'type'     => array( 'simple', 'variable', 'external', 'grouped' ),
			);
			if ( $s['in_stock_only'] ) {
				$args['stock_status'] = 'instock';
			}
			$list = array();
			foreach ( wc_get_products( $args ) as $p ) {
				if ( isset( $seen[ $p->get_id() ] ) || 'hidden' === $p->get_catalog_visibility() ) {
					continue;
				}
				$seen[ $p->get_id() ] = true;
				$list[]               = $p;
				--$left;
			}
			if ( $list ) {
				$groups[] = array( 'name' => $term->name, 'products' => $list );
			}
		}
		return $groups;
	}

	private static function price_text( WC_Product $p ) {
		$fmt = fn( $v ) => html_entity_decode( wp_strip_all_tags( wc_price( (float) $v ) ), ENT_QUOTES, 'UTF-8' );
		if ( $p->is_type( 'variable' ) ) {
			$min = $p->get_variation_price( 'min', true );
			$max = $p->get_variation_price( 'max', true );
			if ( '' === $min ) {
				return '';
			}
			return $min === $max ? $fmt( $min ) : $fmt( $min ) . ' – ' . $fmt( $max );
		}
		$price = $p->get_price();
		return '' === $price ? '' : $fmt( wc_get_price_to_display( $p ) );
	}

	private static function image_url( WC_Product $p ) {
		$id = $p->get_image_id();
		if ( ! $id ) {
			return '';
		}
		$src = wp_get_attachment_image_src( $id, 'woocommerce_thumbnail' );
		return $src ? $src[0] : '';
	}

	private static function logo_url( array $s ) {
		$id = 0;
		if ( 'custom_logo' === $s['logo'] ) {
			$id = (int) get_theme_mod( 'custom_logo' );
		} elseif ( 'site_icon' === $s['logo'] ) {
			$id = (int) get_option( 'site_icon' );
		}
		$src = $id ? wp_get_attachment_image_src( $id, 'medium' ) : false;
		return $src ? $src[0] : '';
	}

	/* ----------------------------------------------------------------- html */

	/** Print-ready, self-contained HTML document (A4 @page rules, system fonts that cover Turkish). */
	public static function build_html( array $groups, array $s ) {
		$cols = (int) $s['columns'];
		$e    = 'esc_html';
		$size = 'landscape' === $s['orientation'] ? 'A4 landscape' : 'A4 portrait';

		$css = '*{box-sizing:border-box}body{margin:0;background:#e5e7eb;font-family:"DejaVu Sans","Segoe UI",Roboto,Helvetica,Arial,sans-serif;font-size:13px;line-height:1.35;color:#1f2933}'
			. '@page{size:' . $size . ';margin:12mm}'
			. '.bar{position:sticky;top:0;z-index:5;display:flex;gap:12px;align-items:center;justify-content:center;flex-wrap:wrap;padding:10px 16px;background:#1f2933;color:#fff}'
			. '.bar button{font:inherit;font-weight:600;padding:8px 16px;border:0;border-radius:6px;background:#fff;color:#1f2933;cursor:pointer}'
			. '.sheet{max-width:' . ( 'landscape' === $s['orientation'] ? '297' : '210' ) . 'mm;margin:16px auto;padding:12mm;background:#fff}'
			. '.cover{text-align:center;padding:14mm 0;break-after:page;page-break-after:always}'
			. '.cover img{width:34mm;height:auto;margin-bottom:6mm}.cover .brand{font-size:15px;color:#6b7280;margin-bottom:10mm}'
			. '.cover h1{font-size:40px;line-height:1.1;margin:0 0 8px}.cover .sub{font-size:20px;color:#4b5563;margin:0 0 8mm}'
			. '.cover .note{font-size:14px;color:#4b5563;margin:0 auto 8mm;max-width:130mm}.cover .date{font-size:13px;color:#6b7280}'
			. 'h2{font-size:20px;margin:0 0 10px;padding-bottom:5px;border-bottom:2px solid #1f2933;break-after:avoid;page-break-after:avoid}'
			. '.grid{display:grid;grid-template-columns:repeat(' . $cols . ',1fr);gap:14px;margin-bottom:22px}'
			. '.item{text-align:center;break-inside:avoid;page-break-inside:avoid;padding:6px}'
			. '.item img{width:100%;max-width:60mm;aspect-ratio:1/1;object-fit:contain;margin:0 auto}'
			. '.name{font-weight:700;margin-top:6px}.meta{font-size:11px;color:#6b7280;margin-top:2px}'
			. '.price{font-weight:700;font-size:14px;margin-top:4px}.desc{font-size:11px;color:#4b5563;margin-top:4px;text-align:left}'
			. '.foot{margin-top:10mm;font-size:11px;color:#6b7280;text-align:center}'
			. '@media print{.cover{padding:' . ( 'landscape' === $s['orientation'] ? '30' : '70' ) . 'mm 0}body{background:#fff}.bar{display:none}.sheet{margin:0;padding:0;max-width:none}*{-webkit-print-color-adjust:exact;print-color-adjust:exact}}'
			. '@media (max-width:600px){.sheet{padding:14px}.grid{grid-template-columns:repeat(2,1fr)}}';

		$html  = '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">';
		$html .= '<title>' . $e( $s['title'] ) . '</title><style>' . $css . '</style></head><body>';
		$html .= '<div class="bar"><span>' . $e( __( 'Kaydederken hedef olarak "PDF olarak kaydet" seçin.', 'pdf-product-catalog-for-woocommerce' ) ) . '</span><button type="button" onclick="window.print()">' . $e( __( 'Yazdır / PDF olarak kaydet', 'pdf-product-catalog-for-woocommerce' ) ) . '</button></div>';
		$html .= '<div class="sheet"><div class="cover">';
		$logo  = self::logo_url( $s );
		if ( $logo ) {
			$html .= '<img src="' . esc_url( $logo ) . '" alt="">';
		}
		$html .= '<div class="brand">' . $e( get_bloginfo( 'name' ) ) . '</div><h1>' . $e( $s['title'] ) . '</h1>';
		if ( '' !== $s['subtitle'] ) {
			$html .= '<p class="sub">' . $e( $s['subtitle'] ) . '</p>';
		}
		if ( '' !== $s['note'] ) {
			$html .= '<p class="note">' . nl2br( $e( $s['note'] ) ) . '</p>';
		}
		$html .= '<div class="date">' . $e( wp_date( 'j F Y' ) ) . '</div></div>';

		foreach ( $groups as $g ) {
			$html .= '<h2>' . $e( $g['name'] ) . '</h2><div class="grid">';
			foreach ( $g['products'] as $p ) {
				$html .= '<div class="item">';
				if ( $s['show_image'] ) {
					$img = self::image_url( $p );
					if ( '' !== $img ) {
						$html .= '<img src="' . esc_url( $img ) . '" alt="">';
					}
				}
				$html .= '<div class="name">' . $e( $p->get_name() ) . '</div>';
				if ( $s['show_sku'] && '' !== (string) $p->get_sku() ) {
					$html .= '<div class="meta">' . $e( __( 'Stok kodu', 'pdf-product-catalog-for-woocommerce' ) ) . ': ' . $e( $p->get_sku() ) . '</div>';
				}
				if ( $s['show_price'] ) {
					$price = self::price_text( $p );
					if ( '' !== $price ) {
						$html .= '<div class="price">' . $e( $price ) . '</div>';
					}
				}
				if ( $s['show_desc'] ) {
					$d = wp_trim_words( wp_strip_all_tags( $p->get_short_description() ), 22 );
					if ( '' !== $d ) {
						$html .= '<div class="desc">' . $e( $d ) . '</div>';
					}
				}
				$html .= '</div>';
			}
			$html .= '</div>';
		}
		if ( ! $groups ) {
			$html .= '<p>' . $e( __( 'Seçilen kategorilerde ürün bulunamadı.', 'pdf-product-catalog-for-woocommerce' ) ) . '</p>';
		}
		if ( '' !== $s['footer'] ) {
			$html .= '<div class="foot">' . $e( $s['footer'] ) . '</div>';
		}
		return $html . '</div></body></html>';
	}

	/** @return string Print-ready HTML catalogue for the given category IDs. */
	public static function generate( array $term_ids = array(), array $override = array() ) {
		$s = array_merge( self::get_settings(), $override );
		return self::build_html( self::collect( $term_ids, $s ), $s );
	}

	/**
	 * Catalogue for the public download, built as a guest: the file is cached and shared, so a
	 * logged-in viewer's own prices (B2B, price lists, role prices) must never end up in it.
	 */
	public static function generate_public( array $term_ids = array() ) {
		$uid = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			return self::generate( $term_ids );
		} finally {
			wp_set_current_user( $uid );
		}
	}

	/**
	 * Optional server-side PDF. No PDF engine is bundled; a site can plug one in with the
	 * `eru_ppc_render_pdf` filter (receives the HTML, returns PDF bytes or null).
	 *
	 * @return string|null PDF bytes or null when no engine is available.
	 */
	public static function render_pdf( $html ) {
		$pdf = apply_filters( 'eru_ppc_render_pdf', null, $html );
		return is_string( $pdf ) && str_starts_with( $pdf, '%PDF' ) ? $pdf : null;
	}

	private static function send( $html, $name, $private ) {
		if ( $private ) {
			nocache_headers();
		}
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );
		$pdf = self::render_pdf( $html );
		if ( null !== $pdf ) {
			header( 'Content-Type: application/pdf' );
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '.pdf"' );
			header( 'Content-Length: ' . strlen( $pdf ) );
			echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
			exit;
		}
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in build_html().
		exit;
	}

	public static function admin_download() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'pdf-product-catalog-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'eru_ppc_download' );
		$ids = isset( $_POST['cats'] ) && is_array( $_POST['cats'] ) ? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['cats'] ) ) ) ) : array();
		self::send( self::generate( $ids ), 'katalog-' . gmdate( 'Y-m-d' ), true );
	}

	/* ---------------------------------------------------------------- front */

	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return (string) apply_filters( 'eru_ppc_client_ip', $ip );
	}

	public static function front_download() {
		if ( ! isset( $_GET['eru_ppc'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- public, read-only download.
			return;
		}
		$slug = sanitize_title( wp_unslash( $_GET['eru_ppc'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$ids  = array();
		if ( 'all' !== $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( ! $term ) {
				status_header( 404 );
				wp_die( esc_html__( 'Katalog bulunamadı.', 'pdf-product-catalog-for-woocommerce' ), '', array( 'response' => 404 ) );
			}
			$ids = array( (int) $term->term_id );
		}
		$s     = self::get_settings();
		$key   = md5( wp_json_encode( array( $slug, $s, get_option( 'site_icon' ) ) ) );
		$file  = self::dir( 'out' ) . '/' . $key . '.html';
		$ttl   = (int) apply_filters( 'eru_ppc_cache_ttl', HOUR_IN_SECONDS );
		$fresh = is_readable( $file ) && ( time() - filemtime( $file ) ) < $ttl;
		if ( ! $fresh ) {
			$rl = 'eru_ppc_rl_' . md5( self::client_ip() );
			$n  = (int) get_transient( $rl );
			if ( $n >= (int) apply_filters( 'eru_ppc_rate_limit', 5 ) ) {
				status_header( 429 );
				wp_die( esc_html__( 'Çok fazla istek. Lütfen daha sonra deneyin.', 'pdf-product-catalog-for-woocommerce' ), '', array( 'response' => 429 ) );
			}
			set_transient( $rl, $n + 1, HOUR_IN_SECONDS );
			// Atomic write: a concurrent request never reads a half-written file.
			$tmp = $file . '.' . wp_generate_password( 8, false ) . '.tmp';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false !== file_put_contents( $tmp, self::generate_public( $ids ) ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				rename( $tmp, $file );
			}
		}
		self::send( is_readable( $file ) ? (string) file_get_contents( $file ) : self::generate_public( $ids ), 'katalog-' . $slug, false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'category' => 'all', 'label' => __( 'PDF kataloğu indir', 'pdf-product-catalog-for-woocommerce' ) ), $atts, 'eru_pdf_catalog' );
		$slug = sanitize_title( $atts['category'] );
		return '<a class="eru-ppc-link" href="' . esc_url( add_query_arg( 'eru_ppc', '' === $slug ? 'all' : $slug, home_url( '/' ) ) ) . '" rel="nofollow">' . esc_html( $atts['label'] ) . '</a>';
	}
}
