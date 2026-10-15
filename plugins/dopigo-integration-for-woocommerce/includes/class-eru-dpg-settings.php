<?php
/**
 * Settings: one non-autoloaded option, per-tab sanitising, write-only credentials, direction helpers,
 * status map and the double-decrement check.
 *
 * @package dopigo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything starts switched off: no outbound request, no scheduled job and no hook side effect until
 * the master switch is on and credentials are saved.
 */
class Eru_DPG_Settings {

	const OPTION = 'eru_dpg_settings';
	const GROUP  = 'eru_dpg';

	/** Fields that are never rendered back into a form. */
	const SECRETS = array( 'password', 'token' );

	/** @var bool True while the plugin itself writes the option (bypasses the form sanitiser). */
	private static $internal = false;

	/** @var array|null Last sanitised value (WordPress sanitises twice when the option is first added). */
	private static $last = null;

	/** @return array Defaults. */
	public static function defaults() {
		return array(
			// Bağlantı.
			'enabled'            => 'no',
			'auth_mode'          => 'password',
			'username'           => '',
			'password'           => '',
			'token'              => '',
			'dry_run'            => 'no',
			// Ürün.
			'stock_direction'    => 'off',
			'price_direction'    => 'off',
			'price_field'        => 'regular',
			'default_vat'        => '20',
			'vat_mode'           => 'gross',
			'queue_updates'      => 'yes',
			'import_enabled'     => 'no',
			'import_interval'    => 'daily',
			'import_status'      => 'draft',
			'category_mode'      => 'append',
			'category_parent'    => '0',
			'import_time_budget' => '20',
			'import_page_size'   => '10',
			'import_image_budget' => '25',
			// Sipariş.
			'order_push'         => 'no',
			'order_trigger'      => 'paid',
			'push_status'        => 'waiting_shipment',
			'status_direction'   => 'off',
			'sales_channel'      => '',
			'status_map'         => array(),
			// Araçlar.
			'time_budget'        => '20',
			'page_size'          => '50',
			'pull_pages'         => '5',
			'log_retention'      => '30',
		);
	}

	/** @return array<string,string> Direction choices. */
	public static function directions() {
		return array(
			'off'      => __( 'Kapalı', 'dopigo-integration-for-woocommerce' ),
			'dp_to_wc' => __( 'Dopigo → Woo', 'dopigo-integration-for-woocommerce' ),
			'wc_to_dp' => __( 'Woo → Dopigo', 'dopigo-integration-for-woocommerce' ),
			'both'     => __( 'İki yönlü', 'dopigo-integration-for-woocommerce' ),
		);
	}

	/** @return array<string,string> Dopigo order statuses (API values). */
	public static function dopigo_statuses() {
		return array(
			'undefined'        => __( 'Belirsiz', 'dopigo-integration-for-woocommerce' ),
			'waiting_shipment' => __( 'Kargo bekliyor', 'dopigo-integration-for-woocommerce' ),
			'preparing'        => __( 'Hazırlanıyor', 'dopigo-integration-for-woocommerce' ),
			'shipped'          => __( 'Kargolandı', 'dopigo-integration-for-woocommerce' ),
			'in_transit'       => __( 'Yolda', 'dopigo-integration-for-woocommerce' ),
			'delivered'        => __( 'Teslim edildi', 'dopigo-integration-for-woocommerce' ),
			'cancelled'        => __( 'İptal', 'dopigo-integration-for-woocommerce' ),
			'refunded'         => __( 'İade', 'dopigo-integration-for-woocommerce' ),
		);
	}

	/** @return array Default status mapping in both directions ('' = do not change). */
	public static function default_status_map() {
		return array(
			'wc_to_dp' => array(
				'pending'    => 'undefined',
				'on-hold'    => 'undefined',
				'processing' => 'waiting_shipment',
				'completed'  => 'delivered',
				'cancelled'  => 'cancelled',
				'refunded'   => 'refunded',
				'failed'     => 'cancelled',
			),
			'dp_to_wc' => array(
				'undefined'        => '',
				'waiting_shipment' => 'processing',
				'preparing'        => 'processing',
				'shipped'          => '',
				'in_transit'       => '',
				'delivered'        => 'completed',
				'cancelled'        => 'cancelled',
				'refunded'         => 'refunded',
			),
		);
	}

	/** @return array Saved settings over defaults. */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/** @param string $key Key. @return mixed */
	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? '';
	}

	/** @param string $key Key. @return bool */
	public static function on( $key ) {
		return 'yes' === self::get( $key );
	}

	/** @return bool Credentials for the chosen auth mode are present. */
	public static function has_credentials() {
		if ( 'token' === self::get( 'auth_mode' ) ) {
			return '' !== trim( (string) self::get( 'token' ) );
		}
		return '' !== trim( (string) self::get( 'username' ) ) && '' !== (string) self::get( 'password' );
	}

	/** @return bool Master switch on and credentials saved. */
	public static function is_enabled() {
		return self::on( 'enabled' ) && self::has_credentials();
	}

	/** @return bool Dry run: nothing is written to Dopigo or to WooCommerce from Dopigo. */
	public static function dry_run() {
		return self::on( 'dry_run' );
	}

	/**
	 * @param string $key  Direction setting.
	 * @param string $flow dp_to_wc | wc_to_dp.
	 * @return bool
	 */
	public static function allows( $key, $flow ) {
		$v = (string) self::get( $key );
		return $v === $flow || 'both' === $v;
	}

	/** @return bool Woo→Dopigo stock and order push are both on: an order would reduce Dopigo stock twice. */
	public static function double_decrement_risk() {
		return self::allows( 'stock_direction', 'wc_to_dp' ) && self::on( 'order_push' );
	}

	/** "Yalnızca sipariş gönder": keep order push, stop sending stock Woo→Dopigo. */
	public static function apply_order_only_preset() {
		$dir = (string) self::get( 'stock_direction' );
		self::update( array( 'stock_direction' => 'both' === $dir ? 'dp_to_wc' : ( 'wc_to_dp' === $dir ? 'off' : $dir ) ) );
	}

	/** @return array Mapping merged over the defaults. */
	public static function status_map() {
		$saved = self::get( 'status_map' );
		$saved = is_array( $saved ) ? $saved : array();
		$def   = self::default_status_map();
		return array(
			'wc_to_dp' => array_merge( $def['wc_to_dp'], is_array( $saved['wc_to_dp'] ?? null ) ? $saved['wc_to_dp'] : array() ),
			'dp_to_wc' => array_merge( $def['dp_to_wc'], is_array( $saved['dp_to_wc'] ?? null ) ? $saved['dp_to_wc'] : array() ),
		);
	}

	/** Internal write: merge values, keep autoload off, follow the schedule. @param array $values Values. */
	public static function update( array $values ) {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		if ( array_intersect( array( 'auth_mode', 'username', 'password', 'token' ), array_keys( $values ) ) ) {
			delete_option( 'eru_dpg_token' );
		}
		self::$internal = true;
		try {
			if ( false === get_option( self::OPTION, false ) ) {
				add_option( self::OPTION, array_merge( $saved, $values ), '', false );
			} else {
				update_option( self::OPTION, array_merge( $saved, $values ), false );
			}
		} finally {
			self::$internal = false;
		}
		if ( class_exists( 'Eru_DPG_Cron' ) ) {
			Eru_DPG_Cron::sync();
		}
	}

	/** Test helper. @param array $values Values. */
	public static function save_for_test( array $values ) {
		self::update( $values );
	}

	/** Hooks. */
	public static function init() {
		add_filter( 'option_page_capability_' . self::GROUP, fn() => 'manage_woocommerce' );
		add_action(
			'admin_init',
			function () {
				register_setting(
					self::GROUP,
					self::OPTION,
					array(
						'type'              => 'array',
						'sanitize_callback' => array( __CLASS__, 'sanitize' ),
						'default'           => array(),
					)
				);
			}
		);
		foreach ( array( 'update_option_', 'add_option_' ) as $prefix ) {
			add_action(
				$prefix . self::OPTION,
				function () {
					wp_set_option_autoload( self::OPTION, false );
					if ( class_exists( 'Eru_DPG_Cron' ) ) {
						Eru_DPG_Cron::sync();
					}
				}
			);
		}
	}

	/** @param mixed $v Raw. @param int $min Min. @param int $max Max. @return string */
	private static function int( $v, $min, $max ) {
		return (string) max( $min, min( $max, absint( $v ) ) );
	}

	/** @param mixed $v Raw. @param array $allowed Allowed. @param string $def Default. @return string */
	private static function pick( $v, array $allowed, $def ) {
		$v = is_string( $v ) ? $v : '';
		return in_array( $v, $allowed, true ) ? $v : $def;
	}

	/**
	 * options.php sanitiser. Only the fields of the posted tab change; secrets are kept when left blank.
	 *
	 * @param mixed $in Posted values (unslashed by options.php).
	 * @return array
	 */
	public static function sanitize( $in ) {
		if ( self::$internal ) {
			return is_array( $in ) ? $in : array();
		}
		if ( null !== self::$last && $in === self::$last ) {
			return $in;
		}
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		if ( ! current_user_can( 'manage_woocommerce' ) || ! is_array( $in ) ) {
			return $saved;
		}
		$out  = array_merge( self::defaults(), $saved );
		$tab  = sanitize_key( $in['_tab'] ?? '' );
		$dirs = array_keys( self::directions() );
		$yes  = fn( $k ) => ! empty( $in[ $k ] ) ? 'yes' : 'no';

		if ( 'connection' === $tab ) {
			$before            = array( $out['auth_mode'], $out['username'], $out['password'], $out['token'] );
			$out['enabled']    = $yes( 'enabled' );
			$out['dry_run']    = $yes( 'dry_run' );
			$out['auth_mode']  = self::pick( $in['auth_mode'] ?? '', array( 'password', 'token' ), 'password' );
			$out['username']   = sanitize_text_field( $in['username'] ?? '' );
			foreach ( self::SECRETS as $k ) {
				$v = trim( (string) ( $in[ $k ] ?? '' ) );
				if ( '' !== $v ) {
					$out[ $k ] = $v;
				}
			}
			if ( ! empty( $in['clear_credentials'] ) ) {
				$out['password'] = '';
				$out['token']    = '';
			}
			if ( array( $out['auth_mode'], $out['username'], $out['password'], $out['token'] ) !== $before ) {
				delete_option( 'eru_dpg_token' );
			}
		} elseif ( 'product' === $tab ) {
			$out['stock_direction']     = self::pick( $in['stock_direction'] ?? '', $dirs, 'off' );
			$out['price_direction']     = self::pick( $in['price_direction'] ?? '', $dirs, 'off' );
			$out['price_field']         = self::pick( $in['price_field'] ?? '', array( 'regular', 'sale' ), 'regular' );
			$out['default_vat']         = self::int( $in['default_vat'] ?? 20, 0, 100 );
			$out['vat_mode']            = self::pick( $in['vat_mode'] ?? '', array( 'gross', 'net' ), 'gross' );
			$out['queue_updates']       = $yes( 'queue_updates' );
			$out['import_enabled']      = $yes( 'import_enabled' );
			$out['import_interval']     = self::pick( $in['import_interval'] ?? '', array_keys( Eru_DPG_Cron::intervals() ), 'daily' );
			$out['import_status']       = self::pick( $in['import_status'] ?? '', array( 'draft', 'pending', 'publish' ), 'draft' );
			$out['category_mode']       = self::pick( $in['category_mode'] ?? '', array( 'append', 'replace' ), 'append' );
			$parent                     = absint( $in['category_parent'] ?? 0 );
			$out['category_parent']     = (string) ( $parent && term_exists( $parent, 'product_cat' ) ? $parent : 0 );
			$out['import_time_budget']  = self::int( $in['import_time_budget'] ?? 20, 5, 120 );
			$out['import_page_size']    = self::int( $in['import_page_size'] ?? 10, 1, 100 );
			$out['import_image_budget'] = self::int( $in['import_image_budget'] ?? 25, 0, 200 );
		} elseif ( 'order' === $tab ) {
			$out['order_push']       = $yes( 'order_push' );
			$out['order_trigger']    = self::pick( $in['order_trigger'] ?? '', array( 'paid', 'created' ), 'paid' );
			$out['push_status']      = self::pick( $in['push_status'] ?? '', array_merge( array( 'mapped' ), array_keys( self::dopigo_statuses() ) ), 'waiting_shipment' );
			$out['status_direction'] = self::pick( $in['status_direction'] ?? '', $dirs, 'off' );
			$out['sales_channel']    = sanitize_text_field( $in['sales_channel'] ?? '' );
			$wc_statuses             = array_map( fn( $s ) => preg_replace( '/^wc-/', '', $s ), array_keys( wc_get_order_statuses() ) );
			$dp_statuses             = array_keys( self::dopigo_statuses() );
			$map                     = array( 'wc_to_dp' => array(), 'dp_to_wc' => array() );
			foreach ( (array) ( $in['status_map']['wc_to_dp'] ?? array() ) as $k => $v ) {
				$k = sanitize_key( $k );
				if ( in_array( $k, $wc_statuses, true ) ) {
					$map['wc_to_dp'][ $k ] = self::pick( $v, $dp_statuses, '' );
				}
			}
			foreach ( (array) ( $in['status_map']['dp_to_wc'] ?? array() ) as $k => $v ) {
				$k = sanitize_key( $k );
				if ( in_array( $k, $dp_statuses, true ) ) {
					$map['dp_to_wc'][ $k ] = self::pick( $v, $wc_statuses, '' );
				}
			}
			$out['status_map'] = $map;
		} elseif ( 'tools' === $tab ) {
			$out['time_budget']   = self::int( $in['time_budget'] ?? 20, 5, 120 );
			$out['page_size']     = self::int( $in['page_size'] ?? 50, 10, 100 );
			$out['pull_pages']    = self::int( $in['pull_pages'] ?? 5, 1, 50 );
			$out['log_retention'] = self::int( $in['log_retention'] ?? 30, 1, 365 );
		}
		self::$last = $out;
		return $out;
	}
}
