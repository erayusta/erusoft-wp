<?php
/**
 * Price list post type, list data access and matching.
 *
 * A list is a post of type `eru_plst_list` with meta:
 *  _eru_plst_roles    string[]            roles the list is assigned to
 *  _eru_plst_users    int[]               users the list is assigned to
 *  _eru_plst_percent  float               discount % on the whole catalogue (0 = none)
 *  _eru_plst_cats     array<int,float>    category id => discount %
 *  _eru_plst_prices   array<int,float>    product/variation id => fixed price
 *  _eru_plst_lines    string              the "SKU = price" text the prices were parsed from
 *  _eru_plst_priority int                 lower number wins when several lists match
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_PLST_Lists {

	const CPT     = 'eru_plst_list';
	const VERSION = 'eru_plst_version';

	private static ?array $all = null;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'on_change' ) );
		add_action( 'deleted_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'trashed_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'on_delete' ) );
	}

	public static function register(): void {
		$cap = 'manage_woocommerce';
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'          => __( 'Fiyat Listeleri', 'price-lists-for-woocommerce' ),
					'singular_name' => __( 'Fiyat Listesi', 'price-lists-for-woocommerce' ),
					'add_new'       => __( 'Yeni liste', 'price-lists-for-woocommerce' ),
					'add_new_item'  => __( 'Yeni fiyat listesi', 'price-lists-for-woocommerce' ),
					'edit_item'     => __( 'Fiyat listesini düzenle', 'price-lists-for-woocommerce' ),
					'all_items'     => __( 'Fiyat Listeleri', 'price-lists-for-woocommerce' ),
					'not_found'     => __( 'Henüz fiyat listesi yok.', 'price-lists-for-woocommerce' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'woocommerce',
				'supports'        => array( 'title' ),
				'rewrite'         => false,
				'capability_type' => array( 'eru_plst_list', 'eru_plst_lists' ),
				'map_meta_cap'    => true,
				'capabilities'    => array(
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'delete_posts'           => $cap,
					'publish_posts'          => $cap,
					'read_private_posts'     => $cap,
					'delete_private_posts'   => $cap,
					'delete_published_posts' => $cap,
					'delete_others_posts'    => $cap,
					'edit_private_posts'     => $cap,
					'edit_published_posts'   => $cap,
					'create_posts'           => $cap,
				),
			)
		);
	}

	public static function version(): int {
		return (int) get_option( self::VERSION, 1 );
	}

	public static function on_change( $post_id = 0 ): void {
		// Autosaves and revisions do not change prices; skip the cache bump.
		if ( $post_id && ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) ) {
			return;
		}
		update_option( self::VERSION, self::version() + 1, false );
		self::flush();
	}

	public static function on_delete( $post_id ): void {
		if ( get_post_type( $post_id ) === self::CPT ) {
			self::on_change();
		}
	}

	public static function flush(): void {
		self::$all = null;
		if ( class_exists( 'Eru_PLST_Pricing' ) ) {
			Eru_PLST_Pricing::flush();
		}
	}

	/** @return array[] Published lists, ordered by priority then id. */
	public static function all(): array {
		if ( null !== self::$all ) {
			return self::$all;
		}
		$posts = get_posts(
			array(
				'post_type'        => self::CPT,
				'post_status'      => 'publish',
				'numberposts'      => 200,
				'suppress_filters' => true,
			)
		);
		$lists = array();
		foreach ( $posts as $post ) {
			$lists[] = array(
				'id'       => (int) $post->ID,
				'title'    => $post->post_title,
				'roles'    => array_map( 'sanitize_key', (array) get_post_meta( $post->ID, '_eru_plst_roles', true ) ),
				'users'    => array_map( 'intval', (array) get_post_meta( $post->ID, '_eru_plst_users', true ) ),
				'percent'  => (float) get_post_meta( $post->ID, '_eru_plst_percent', true ),
				'cats'     => (array) get_post_meta( $post->ID, '_eru_plst_cats', true ),
				'prices'   => (array) get_post_meta( $post->ID, '_eru_plst_prices', true ),
				'priority' => (int) get_post_meta( $post->ID, '_eru_plst_priority', true ),
			);
		}
		usort( $lists, fn( $a, $b ) => array( $a['priority'], $a['id'] ) <=> array( $b['priority'], $b['id'] ) );
		self::$all = $lists;
		return $lists;
	}

	/**
	 * Lists that apply to a user, best first: lists assigned to the user personally
	 * come before lists assigned to one of the user's roles.
	 *
	 * @return array[]
	 */
	public static function for_user( int $user_id ): array {
		if ( $user_id < 1 ) {
			return array();
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array();
		}
		$personal = array();
		$by_role  = array();
		foreach ( self::all() as $list ) {
			if ( in_array( $user_id, $list['users'], true ) ) {
				$personal[] = $list;
			} elseif ( array_intersect( $list['roles'], (array) $user->roles ) ) {
				$by_role[] = $list;
			}
		}
		return array_merge( $personal, $by_role );
	}

	/**
	 * Parse "SKU = price" / "ID = price" lines.
	 *
	 * @return array{0:array<int,float>,1:string[]} Prices by product id and unmatched lines.
	 */
	public static function parse_lines( string $text ): array {
		$prices  = array();
		$invalid = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = preg_split( '/\s*[=;\t]\s*/', $line, 2 );
			if ( 2 !== count( $parts ) || '' === $parts[0] || ! is_numeric( str_replace( ',', '.', $parts[1] ) ) ) {
				$invalid[] = $line;
				continue;
			}
			$price = (float) str_replace( ',', '.', $parts[1] );
			$id    = (int) wc_get_product_id_by_sku( $parts[0] );
			if ( ! $id && ctype_digit( $parts[0] ) && wc_get_product( (int) $parts[0] ) ) {
				$id = (int) $parts[0];
			}
			if ( $id && $price >= 0 ) {
				$prices[ $id ] = $price;
			} else {
				$invalid[] = $line;
			}
		}
		return array( $prices, $invalid );
	}
}
