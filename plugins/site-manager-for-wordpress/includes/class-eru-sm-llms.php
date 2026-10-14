<?php
/** Virtual /llms.txt (and optional /llms-full.txt). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SM_Llms {

	const QUERY_VAR = 'eru_sm_llms';
	const FLAG      = 'eru_sm_flush_rules';
	const MAX_FULL  = 1048576;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_rules' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
		add_action( 'save_post', array( __CLASS__, 'flush_cache' ) );
		foreach ( array( 'added', 'updated', 'deleted' ) as $op ) {
			add_action(
				$op . '_post_meta',
				static function ( $a, $b, $key ) {
					if ( in_array( $key, array( '_yoast_wpseo_meta-robots-noindex', 'rank_math_robots' ), true ) ) {
						self::flush_cache();
					}
				},
				10,
				3
			);
		}
		add_action( 'deleted_post', array( __CLASS__, 'flush_cache' ) );
		add_action(
			'set_object_terms',
			static function ( $id, $terms, $tt_ids, $taxonomy ) {
				if ( 'product_visibility' === $taxonomy ) {
					self::flush_cache();
				}
			},
			10,
			4
		);
		add_action( 'trashed_post', array( __CLASS__, 'flush_cache' ) );
		// Rewrite rules are flushed once, when the module is switched on or off.
		$mark = function ( $old, $new ) {
			$a = is_array( $old ) && ! empty( $old['llms']['enabled'] );
			$b = is_array( $new ) && ! empty( $new['llms']['enabled'] );
			if ( $a !== $b ) {
				update_option( self::FLAG, 1, false );
			}
		};
		add_action( 'update_option_' . Eru_SM_Settings::OPTION, $mark, 10, 2 );
		add_action( 'update_option_' . Eru_SM_Settings::OPTION, array( __CLASS__, 'flush_cache' ) );
		add_action( 'add_option_' . Eru_SM_Settings::OPTION, array( __CLASS__, 'flush_cache' ) );
		add_action(
			'add_option_' . Eru_SM_Settings::OPTION,
			function ( $o, $v ) use ( $mark ) {
				$mark( array(), $v );
			},
			10,
			2
		);
	}

	public static function enabled(): bool {
		return ! empty( Eru_SM_Settings::get( 'llms' )['enabled'] );
	}

	public static function add_rules(): void {
		if ( ! self::enabled() ) {
			return;
		}
		add_rewrite_rule( '^llms\.txt$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?' . self::QUERY_VAR . '=full', 'top' );
	}

	public static function remove_rules(): void {
		global $wp_rewrite;
		unset( $wp_rewrite->extra_rules_top['^llms\.txt$'], $wp_rewrite->extra_rules_top['^llms-full\.txt$'] );
	}

	public static function maybe_flush(): void {
		if ( get_option( self::FLAG ) ) {
			delete_option( self::FLAG );
			flush_rewrite_rules( false );
		}
	}

	public static function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function flush_cache(): void {
		delete_transient( 'eru_sm_llms' );
		delete_transient( 'eru_sm_llms_full' );
	}

	/** Public post types that can be listed. WooCommerce products only when WooCommerce is active. */
	public static function available_post_types(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $slug => $obj ) {
			if ( 'attachment' === $slug || ( 'product' === $slug && ! class_exists( 'WooCommerce' ) ) ) {
				continue;
			}
			$out[ $slug ] = $obj->labels->name;
		}
		return $out;
	}

	/** Which file is being requested: '', 'short' or 'full'. */
	private static function requested(): string {
		$v = get_query_var( self::QUERY_VAR );
		if ( '1' === $v ) {
			return 'short';
		}
		if ( 'full' === $v ) {
			return 'full';
		}
		// Fallback when rewrite rules are not in place (plain permalinks, rules not flushed yet).
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( ! is_string( $path ) || 0 !== strpos( $path, $base ) ) {
			return '';
		}
		$rel = substr( $path, strlen( $base ) );
		return 'llms.txt' === $rel ? 'short' : ( 'llms-full.txt' === $rel ? 'full' : '' );
	}

	public static function serve(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$which = self::requested();
		if ( '' === $which ) {
			return;
		}
		$body = self::render( 'full' === $which );
		if ( '' === $body ) {
			return;
		}
		global $wp_query;
		if ( $wp_query ) {
			$wp_query->is_404 = false;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			exit;
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- plain text.
		exit;
	}

	/** Text of the file; '' when the module (or the full variant) is off and $force is false. */
	public static function render( bool $full = false, bool $force = false ): string {
		$s = Eru_SM_Settings::get( 'llms' );
		if ( ! $force && ( empty( $s['enabled'] ) || '0' === (string) get_option( 'blog_public' ) || ( $full && empty( $s['full'] ) ) ) ) {
			return '';
		}
		$key = $full ? 'eru_sm_llms_full' : 'eru_sm_llms';
		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}
		$text = self::build( $s, $full );
		if ( ! $force ) {
			set_transient( $key, $text, DAY_IN_SECONDS );
		}
		return $text;
	}

	private static function line( string $s ): string {
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	private static function build( array $s, bool $full ): string {
		$title   = self::line( get_bloginfo( 'name' ) );
		$summary = self::line( '' !== $s['summary'] ? $s['summary'] : get_bloginfo( 'description' ) );
		$lines   = array( '# ' . $title );
		if ( '' !== $summary ) {
			$lines[] = '';
			$lines[] = '> ' . $summary;
		}
		if ( '' !== trim( $s['intro'] ) ) {
			$lines[] = '';
			$lines[] = trim( $s['intro'] );
		}
		$types   = self::available_post_types();
		$exclude = array_filter( array_map( 'absint', explode( ',', $s['exclude'] ) ) );
		$size    = strlen( implode( "\n", $lines ) );
		foreach ( (array) $s['post_types'] as $ptype ) {
			if ( ! isset( $types[ $ptype ] ) ) {
				continue;
			}
			$posts = self::posts( $ptype, (int) $s['max'], $exclude );
			if ( ! $posts ) {
				continue;
			}
			$lines[] = '';
			$lines[] = '## ' . $types[ $ptype ];
			foreach ( $posts as $p ) {
				$t = str_replace( array( '[', ']' ), array( '(', ')' ), self::line( get_the_title( $p ) ) );
				if ( $full ) {
					$body  = self::line( strip_shortcodes( $p->post_content ) );
					$block = array( '', '### ' . $t, self::md_url( get_permalink( $p ) ), '', $body );
					$add   = strlen( implode( "\n", $block ) ) + 1;
					if ( $size + $add > self::MAX_FULL - 2048 ) {
						break 2;
					}
					$size   += $add;
					$lines   = array_merge( $lines, $block );
					continue;
				}
				$ex      = self::excerpt( $p );
				$lines[] = '- [' . $t . '](' . self::md_url( get_permalink( $p ) ) . ')' . ( '' !== $ex ? ': ' . $ex : '' );
			}
		}
		$links = self::links( $s['links'] );
		if ( $links ) {
			$lines[] = '';
			$lines[] = '## ' . __( 'Bağlantılar', 'site-manager-for-wordpress' );
			$lines   = array_merge( $lines, $links );
		}
		/**
		 * Filter the lines of llms.txt before they are joined.
		 *
		 * @param string[] $lines
		 * @param bool     $full  True for llms-full.txt.
		 */
		$lines = apply_filters( 'eru_sm_llms_lines', $lines, $full );
		$text  = implode( "\n", array_map( 'strval', (array) $lines ) ) . "\n";
		return $full && strlen( $text ) > self::MAX_FULL ? mb_strcut( $text, 0, self::MAX_FULL ) : $text;
	}

	/** Parentheses would end a markdown link early. */
	private static function md_url( string $u ): string {
		return str_replace( array( '(', ')' ), array( '%28', '%29' ), $u );
	}

	private static function excerpt( WP_Post $p ): string {
		$src = '' !== trim( $p->post_excerpt ) ? $p->post_excerpt : wp_trim_words( self::line( strip_shortcodes( $p->post_content ) ), 30, '…' );
		return self::line( $src );
	}

	/** "Title | URL | description" lines; lines without a valid URL are dropped. */
	private static function links( string $raw ): array {
		$out = array();
		foreach ( preg_split( '/\R/', $raw ) as $row ) {
			$parts = array_map( 'trim', explode( '|', $row, 3 ) );
			$url   = isset( $parts[1] ) && preg_match( '#^https?://#i', $parts[1] ) ? esc_url_raw( $parts[1], array( 'http', 'https' ) ) : '';
			if ( '' === $parts[0] || '' === $url ) {
				continue;
			}
			$out[] = '- [' . str_replace( array( '[', ']' ), array( '(', ')' ), $parts[0] ) . '](' . self::md_url( $url ) . ')' . ( ! empty( $parts[2] ) ? ': ' . $parts[2] : '' );
		}
		return $out;
	}

	/** Published, public, indexable posts; password-protected, private and noindex ones are skipped. */
	private static function posts( string $ptype, int $max, array $exclude ): array {
		$found = array();
		$paged = 1;
		$tax   = array();
		if ( 'product' === $ptype && taxonomy_exists( 'product_visibility' ) ) {
			// Products hidden from the shop or from search ("Catalog visibility") are not listed.
			$tax = array(
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => array( 'exclude-from-catalog', 'exclude-from-search' ),
					'operator' => 'NOT IN',
				),
			);
		}
		while ( count( $found ) < $max && $paged <= 20 ) {
			$batch = get_posts(
				array(
					'tax_query'        => $tax, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- cached output, rebuilt on change only.
					'post_type'        => $ptype,
					'post_status'      => 'publish',
					'has_password'     => false,
					'post__not_in'     => $exclude,
					'posts_per_page'   => 100,
					'paged'            => $paged,
					'orderby'          => 'modified',
					'order'            => 'DESC',
					'suppress_filters' => true,
					'no_found_rows'    => true,
				)
			);
			if ( ! $batch ) {
				break;
			}
			foreach ( $batch as $p ) {
				if ( ! self::noindex( $p->ID ) ) {
					$found[] = $p;
					if ( count( $found ) >= $max ) {
						break;
					}
				}
			}
			++$paged;
		}
		return $found;
	}

	private static function noindex( int $id ): bool {
		if ( '1' === (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return true;
		}
		$rm = get_post_meta( $id, 'rank_math_robots', true );
		return is_array( $rm ) && in_array( 'noindex', $rm, true );
	}
}
