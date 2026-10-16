<?php
/**
 * Trendyol adapter: shipment packages → order DTOs, picking acknowledgement, re-created packages.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trendyol works in shipment packages: one package is one WooCommerce order (remote_ref = package id).
 *
 * Requests: Basic auth (API key : API secret) and the User-Agent "<seller ID> - <integration reference>"
 * ("SelfIntegration" when the reference is empty) in headers only. The base URL is the live or the stage host
 * (editable, https on trendyol.com only). Without seller ID, key, secret and a base URL the adapter is not
 * configured and sends nothing.
 *
 * Orders: GET /order/sellers/{sellerId}/orders, one status at a time (setting order_statuses, default Created,
 * Picking, Invoiced; lifecycle_statuses for the lifecycle pull), ordered by the package's last change, windows
 * of at most 14 days, every page (200 per page). A package seen under two statuses in one fetch keeps the later one.
 * Lines carry the unit price before discounts and the unit price paid; the difference times the quantity is the
 * line discount (seller and marketplace discounts together), so the lines add up to the package total. The
 * reported discounts and commission rate stay on the lines. orderDate is Turkish local time written as if it were
 * UTC; three hours are taken off (filter eru_mpo_trendyol_time_offset).
 *
 * Statuses (filter eru_mpo_trendyol_status_map): Created → new, Picking → accepted, Invoiced → ready, Shipped /
 * AtCollectionPoint / UnDelivered → shipped, Delivered → delivered, Cancelled / UnSupplied / UnPacked → cancelled,
 * Returned → returned. Cancelled lines become cancel-line-<line id> events in the importer (stock restored once).
 *
 * Acknowledgement: PUT /order/sellers/{sellerId}/shipment-packages/{packageId} with status Picking and the
 * package's line ids and quantities, run by Eru_MPO_Ack per the ack mode. A package already past Created is not
 * sent again. A check flag is stored before the request and cleared on a clear answer, so after an ambiguous
 * failure (timeout, 5xx, or the run dying after sending) the next attempt first reads the package by its order
 * number and only sends when it is still Created.
 *
 * Re-created and split packages (code collision): Trendyol may cancel a package (Cancelled, UnSupplied, UnPacked)
 * and create new ones under the same order number. For a new package whose order number belongs to an order whose
 * package Trendyol reports cancelled (its stored remote status, else read by order number; a local cancel alone
 * never counts), see predecessor():
 * - same active lines (SKU × quantity) as the order's remaining lines: the same package re-created; it is linked
 *   to that order (eru_mpo_link_order) and no second order is created;
 * - other lines (a split, e.g. A unpacked into B and C): the new package becomes its own order and the earlier
 *   order is replaced (eru_mpo_replaced_orders): cancelled, its lines zeroed, stock restored once.
 * A new package next to a still-active one gets its own order. When the earlier package cannot be read, the import
 * fails and is retried on the next pull rather than creating a possible duplicate.
 *
 * Stock bridge: listings come from the approved products list (stockCode → SKU, barcode); stock and price go to
 * price-and-inventory by barcode (absolute values, so a retry never applies a change twice).
 */
class Eru_MPO_Adapter_Trendyol extends Eru_MPO_Adapter_Base {

	const KEY         = 'trendyol';
	const BASE_LIVE   = 'https://apigw.trendyol.com/integration';
	const BASE_STAGE  = 'https://stageapigw.trendyol.com/integration';
	const DEFAULT_REF = 'SelfIntegration';
	const PATHS       = array(
		'orders'    => '/order/sellers/{sellerId}/orders',
		'package'   => '/order/sellers/{sellerId}/shipment-packages/{packageId}',
		'approved'  => '/product/sellers/{sellerId}/products/approved',
		'inventory' => '/inventory/sellers/{sellerId}/products/price-and-inventory',
	);
	const STATUS_MAP  = array(
		'Created'           => 'new',
		'Picking'           => 'accepted',
		'Invoiced'          => 'ready',
		'Shipped'           => 'shipped',
		'AtCollectionPoint' => 'shipped',
		'UnDelivered'       => 'shipped',
		'Delivered'         => 'delivered',
		'Cancelled'         => 'cancelled',
		'UnSupplied'        => 'cancelled',
		'UnPacked'          => 'cancelled',
		'Returned'          => 'returned',
	);
	const CANCELLED   = array( 'Cancelled', 'UnSupplied', 'UnPacked' );
	const PAGE_SIZE   = 200;
	const MAX_PAGES   = 100;
	const SLICE       = 1209600;
	/** Approved products per listing page; price-and-inventory items per request. */
	const LISTING_PAGE = 200;
	const PUSH_MAX     = 1000;
	/** Order meta: the last acknowledgement attempt may have reached Trendyol; read the package first. */
	const CHECK_META = '_eru_mpo_ack_check';

	/** @var array<string,string> Package id => Trendyol status seen in this request's fetches. */
	private $seen = array();

	/** @var array<string,array> Order number => packages read by number in this request. */
	private $by_number = array();

	/** @var array<string,array{link:int,replace:int}> New package => predecessor decision (this request). */
	private $decisions = array();

	/** Hooks. */
	public static function init() {
		add_filter( 'eru_mpo_adapters', array( __CLASS__, 'register' ), 5 );
		add_filter( 'eru_mpo_link_order', array( __CLASS__, 'link_order' ), 10, 3 );
		add_filter( 'eru_mpo_replaced_orders', array( __CLASS__, 'replaced_orders' ), 10, 3 );
	}

	/** @param array $list Adapters. @return array */
	public static function register( $list ) {
		$list = is_array( $list ) ? $list : array();
		if ( ! isset( $list[ self::KEY ] ) ) {
			$list[ self::KEY ] = new self();
		}
		return $list;
	}

	/** @return string */
	public function key() {
		return self::KEY;
	}

	/** @return string */
	public function label() {
		return 'Trendyol';
	}

	/**
	 * Settings of eru_mpo_mp_trendyol (see Eru_MPO_Credentials for the types).
	 *
	 * @return array
	 */
	public static function settings_fields() {
		$known = array_keys( self::STATUS_MAP );
		return array(
			'enabled'            => array(
				'type'  => 'checkbox',
				'label' => __( 'Trendyol', 'marketplace-orders-for-woocommerce' ),
				'help'  => __( 'Trendyol siparişlerini çek (ana anahtar da açık olmalı).', 'marketplace-orders-for-woocommerce' ),
			),
			'observe'            => array(
				'type'  => 'checkbox',
				'label' => __( 'Gözlem modu', 'marketplace-orders-for-woocommerce' ),
				'help'  => __( 'Yalnızca bu pazaryeri için: çek ve günlüğe yaz, sipariş oluşturma, Trendyol\'a bildirim gönderme.', 'marketplace-orders-for-woocommerce' ),
			),
			'seller_id'          => array(
				'type'    => 'text',
				'label'   => __( 'Satıcı ID', 'marketplace-orders-for-woocommerce' ),
				'pattern' => '/\D+/',
			),
			'api_key'            => array(
				'type'  => 'secret',
				'label' => __( 'API anahtarı', 'marketplace-orders-for-woocommerce' ),
			),
			'api_secret'         => array(
				'type'  => 'secret',
				'label' => __( 'API secret', 'marketplace-orders-for-woocommerce' ),
			),
			'integration_ref'    => array(
				'type'    => 'text',
				'label'   => __( 'Entegrasyon referansı', 'marketplace-orders-for-woocommerce' ),
				'help'    => __( 'User-Agent başlığında satıcı ID\'sinden sonra gönderilir. Boşsa "SelfIntegration".', 'marketplace-orders-for-woocommerce' ),
				'pattern' => '/[^A-Za-z0-9 ._-]/',
			),
			'environment'        => array(
				'type'    => 'select',
				'label'   => __( 'Ortam', 'marketplace-orders-for-woocommerce' ),
				'default' => 'live',
				'options' => array(
					'live'  => __( 'Canlı', 'marketplace-orders-for-woocommerce' ),
					'stage' => __( 'Test (stage)', 'marketplace-orders-for-woocommerce' ),
				),
			),
			'base_live'          => array(
				'type'    => 'url',
				'label'   => __( 'Canlı API adresi', 'marketplace-orders-for-woocommerce' ),
				'default' => self::BASE_LIVE,
				'hosts'   => array( 'trendyol.com' ),
			),
			'base_stage'         => array(
				'type'    => 'url',
				'label'   => __( 'Test API adresi', 'marketplace-orders-for-woocommerce' ),
				'default' => self::BASE_STAGE,
				'hosts'   => array( 'trendyol.com' ),
			),
			'order_statuses'     => array(
				'type'    => 'csv',
				'label'   => __( 'Yeni sipariş durumları', 'marketplace-orders-for-woocommerce' ),
				'help'    => __( 'Virgülle ayrılmış Trendyol paket durumları.', 'marketplace-orders-for-woocommerce' ),
				'default' => 'Created,Picking,Invoiced',
				'options' => $known,
			),
			'lifecycle_statuses' => array(
				'type'    => 'csv',
				'label'   => __( 'Yaşam döngüsü durumları', 'marketplace-orders-for-woocommerce' ),
				'default' => 'Shipped,AtCollectionPoint,UnDelivered,Delivered,Cancelled,UnSupplied,UnPacked,Returned',
				'options' => $known,
			),
			'ack_mode'           => array(
				'type'    => 'select',
				'label'   => __( '"İşleme alındı" bildirimi', 'marketplace-orders-for-woocommerce' ),
				'default' => 'never',
				'options' => Eru_MPO_Ack::modes(),
			),
			'ack_status'         => array(
				'type'    => 'select',
				'label'   => __( 'Bildirim durumu', 'marketplace-orders-for-woocommerce' ),
				'help'    => __( '"Sipariş bu duruma geçince" seçiliyse kullanılır.', 'marketplace-orders-for-woocommerce' ),
				'default' => Eru_MPO_Ack::DEFAULT_STATUS,
				'options' => Eru_MPO_Ack::status_options(),
			),
			'share_ppt'          => array(
				'type'  => 'checkbox',
				'label' => __( 'Product Push for Trendyol', 'marketplace-orders-for-woocommerce' ),
				'help'  => __( 'Trendyol bilgilerini Product Push ile paylaş (yalnızca orada boş bırakılan alanlar doldurulur).', 'marketplace-orders-for-woocommerce' ),
			),
		);
	}

	/** @return array Stored settings over the defaults. */
	public static function settings() {
		return Eru_MPO_Credentials::values( self::KEY, self::settings_fields() );
	}

	/**
	 * Stored credentials (secrets included: never print them).
	 *
	 * @return array{seller_id: string, api_key: string, api_secret: string, integration_ref: string}
	 */
	public static function credentials() {
		$s = self::settings();
		return array(
			'seller_id'       => trim( (string) $s['seller_id'] ),
			'api_key'         => trim( (string) $s['api_key'] ),
			'api_secret'      => trim( (string) $s['api_secret'] ),
			'integration_ref' => trim( (string) $s['integration_ref'] ),
		);
	}

	/** @return string live | stage. */
	public static function environment() {
		return 'stage' === self::settings()['environment'] ? 'stage' : 'live';
	}

	/** @return string Base URL without a trailing slash ('' = none). */
	public static function base_url() {
		$s   = self::settings();
		$env = self::environment();
		$url = (string) ( 'stage' === $env ? $s['base_stage'] : $s['base_live'] );
		return untrailingslashit( (string) apply_filters( 'eru_mpo_trendyol_base_url', $url, $env ) );
	}

	/** @return string Required User-Agent: "<seller ID> - <integration reference>". */
	public static function user_agent() {
		$c = self::credentials();
		return $c['seller_id'] . ' - ' . ( '' !== $c['integration_ref'] ? $c['integration_ref'] : self::DEFAULT_REF );
	}

	/** @return bool Seller ID, API key, API secret and a base URL are set. */
	public function is_configured() {
		$c = self::credentials();
		return '' !== $c['seller_id'] && '' !== $c['api_key'] && '' !== $c['api_secret'] && '' !== self::base_url();
	}

	/** @return array{state: string, tries: string, error: string, at: string, done: string, delays: int[], max: int, advance: string, label: string} */
	public function ack_profile() {
		return array(
			'state'   => '_eru_mpo_ack_state',
			'tries'   => '_eru_mpo_ack_tries',
			'error'   => '_eru_mpo_ack_error',
			'at'      => '_eru_mpo_ack_at',
			'done'    => 'done',
			'delays'  => array( MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS ),
			'max'     => 5,
			'advance' => 'accepted',
			'label'   => __( 'Trendyol "İşleme alındı" bildirimi', 'marketplace-orders-for-woocommerce' ),
		);
	}

	/**
	 * One API call.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $name   Path key (self::PATHS).
	 * @param array      $vars   {packageId} replacements.
	 * @param array      $query  Query (never credentials).
	 * @param array|null $body   JSON body.
	 * @param string     $ref    Log reference.
	 * @return array Eru_MPO_Http result.
	 */
	private function call( $method, $name, array $vars = array(), array $query = array(), $body = null, $ref = '' ) {
		$c    = self::credentials();
		$path = str_replace( '{sellerId}', rawurlencode( $c['seller_id'] ), self::PATHS[ $name ] );
		foreach ( $vars as $k => $v ) {
			$path = str_replace( '{' . $k . '}', rawurlencode( (string) $v ), $path );
		}
		$url = self::base_url() . $path;
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url );
		}
		$auth = Eru_MPO_Http::basic( $c['api_key'], $c['api_secret'] );
		return Eru_MPO_Http::request(
			self::KEY,
			$method,
			$url,
			array(
				'Authorization' => $auth,
				'User-Agent'    => self::user_agent(),
			),
			$body,
			array(
				'secrets' => array( $c['api_key'], $c['api_secret'], substr( $auth, 6 ) ),
				'ref'     => $ref,
			)
		);
	}

	/** @return WP_Error Not configured (nothing was sent). */
	private function not_configured() {
		return new WP_Error( 'eru_mpo_not_configured', __( 'Trendyol API bilgileri eksik: satıcı ID, API anahtarı, API secret ve API adresi gerekli.', 'marketplace-orders-for-woocommerce' ), array( 'retryable' => false ) );
	}

	/** @param string $key order_statuses | lifecycle_statuses. @return string[] */
	private function statuses( $key ) {
		$list = array_filter( array_map( 'trim', explode( ',', (string) self::settings()[ $key ] ) ) );
		return array_values( array_intersect( $list, array_keys( self::STATUS_MAP ) ) );
	}

	/** @param int $since UTC. @param int $until UTC. @return Eru_MPO_Order_DTO[]|WP_Error */
	public function fetch_orders( $since, $until ) {
		return $this->fetch_dtos( $this->statuses( 'order_statuses' ), $since, $until );
	}

	/** @param int $since UTC. @param int $until UTC. @return Eru_MPO_Order_DTO[]|WP_Error */
	public function fetch_lifecycle( $since, $until ) {
		return $this->fetch_dtos( $this->statuses( 'lifecycle_statuses' ), $since, $until );
	}

	/** @return Eru_MPO_Order_DTO[]|WP_Error */
	private function fetch_dtos( array $statuses, $since, $until ) {
		$this->seen      = array(); // Statuses known from this fetch only (an adapter instance outlives one pull).
		$this->by_number = array();
		$this->decisions = array();
		$packages = $this->fetch_packages( $statuses, (int) $since, (int) $until );
		if ( is_wp_error( $packages ) ) {
			return $packages;
		}
		$out = array();
		foreach ( $packages as $p ) {
			$out[] = $this->to_dto( $p );
		}
		return $out;
	}

	/**
	 * Every package changed in the window under the given statuses, deduplicated by package id.
	 *
	 * @param string[] $statuses Trendyol statuses.
	 * @param int      $since    UTC.
	 * @param int      $until    UTC.
	 * @return array[]|WP_Error
	 */
	public function fetch_packages( array $statuses, $since, $until ) {
		if ( ! $this->is_configured() ) {
			return $this->not_configured();
		}
		$until = max( (int) $since + 1, (int) $until );
		$all   = array();
		foreach ( $statuses as $status ) {
			for ( $from = (int) $since; $from < $until; $from += self::SLICE ) {
				$to   = min( $until, $from + self::SLICE );
				$page = 0;
				do {
					$res = $this->call(
						'GET',
						'orders',
						array(),
						array(
							'status'           => $status,
							'startDate'        => $from * 1000,
							'endDate'          => $to * 1000,
							'page'             => $page,
							'size'             => self::PAGE_SIZE,
							'orderByField'     => 'PackageLastModifiedDate',
							'orderByDirection' => 'DESC',
						)
					);
					if ( ! $res['ok'] ) {
						return Eru_MPO_Http::to_error( $res );
					}
					if ( ! is_array( $res['data'] ) ) {
						return new WP_Error( 'eru_mpo_bad_json', __( 'Trendyol okunamayan bir yanıt döndü.', 'marketplace-orders-for-woocommerce' ), array( 'retryable' => true ) );
					}
					foreach ( (array) ( $res['data']['content'] ?? array() ) as $p ) {
						if ( ! is_array( $p ) ) {
							continue;
						}
						$id = self::id_string( $p['shipmentPackageId'] ?? $p['id'] ?? '' );
						if ( '' === $id ) {
							continue;
						}
						$prev = $all[ $id ] ?? null;
						if ( ! $prev || Eru_MPO_Statuses::rank( $this->map_status( (string) ( $p['status'] ?? '' ) ) ) >= Eru_MPO_Statuses::rank( $this->map_status( (string) ( $prev['status'] ?? '' ) ) ) ) {
							$all[ $id ] = $p;
						}
						$this->seen[ $id ] = (string) ( $all[ $id ]['status'] ?? '' );
					}
					$pages = max( 1, (int) ( $res['data']['totalPages'] ?? 1 ) );
					++$page;
				} while ( $page < $pages && $page < self::MAX_PAGES );
			}
		}
		return array_values( $all );
	}

	/**
	 * Packages of one order number (any status), read once per request unless $fresh.
	 *
	 * @param string $number Order number.
	 * @param bool   $fresh  Skip the request cache.
	 * @return array[]|WP_Error
	 */
	public function packages_by_number( $number, $fresh = false ) {
		$number = trim( (string) $number );
		if ( '' === $number ) {
			return array();
		}
		if ( ! $fresh && isset( $this->by_number[ $number ] ) ) {
			return $this->by_number[ $number ];
		}
		if ( ! $this->is_configured() ) {
			return $this->not_configured();
		}
		$out  = array();
		$page = 0;
		do {
			$res = $this->call(
				'GET',
				'orders',
				array(),
				array(
					'orderNumber' => $number,
					'page'        => $page,
					'size'        => self::PAGE_SIZE,
				)
			);
			if ( ! $res['ok'] ) {
				return Eru_MPO_Http::to_error( $res );
			}
			foreach ( (array) ( $res['data']['content'] ?? array() ) as $p ) {
				if ( is_array( $p ) ) {
					$out[] = $p;
					$id    = self::id_string( $p['shipmentPackageId'] ?? $p['id'] ?? '' );
					if ( '' !== $id ) {
						$this->seen[ $id ] = (string) ( $p['status'] ?? '' );
					}
				}
			}
			$pages = max( 1, (int) ( $res['data']['totalPages'] ?? 1 ) );
			++$page;
		} while ( $page < $pages && $page < 20 );
		$this->by_number[ $number ] = $out;
		return $out;
	}

	/**
	 * Trendyol status of a package, from this request's fetches or read by its order number.
	 *
	 * @param string $number  Order number.
	 * @param string $package Package id.
	 * @param bool   $fresh   Read again.
	 * @return string|WP_Error '' when the package is not found.
	 */
	public function package_status( $number, $package, $fresh = false ) {
		if ( ! $fresh && isset( $this->seen[ $package ] ) ) {
			return $this->seen[ $package ];
		}
		$list = $this->packages_by_number( $number, $fresh );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		foreach ( $list as $p ) {
			if ( self::id_string( $p['shipmentPackageId'] ?? $p['id'] ?? '' ) === (string) $package ) {
				return (string) ( $p['status'] ?? '' );
			}
		}
		return '';
	}

	/** @param string $status Trendyol status. @return string Logical status. */
	public function map_status( $status ) {
		$map = apply_filters( 'eru_mpo_trendyol_status_map', self::STATUS_MAP );
		$map = is_array( $map ) ? $map : self::STATUS_MAP;
		$key = (string) ( $map[ trim( (string) $status ) ] ?? 'new' );
		return Eru_MPO_Statuses::is_key( $key ) ? $key : 'new';
	}

	/** @param string $status Line status. @return string active | cancelled | returned. */
	private static function line_status( $status ) {
		$s = trim( (string) $status );
		if ( in_array( $s, self::CANCELLED, true ) ) {
			return 'cancelled';
		}
		return 'Returned' === $s ? 'returned' : 'active';
	}

	/** @param mixed $v JSON number or string. @return string Digits without float notation. */
	public static function id_string( $v ) {
		if ( is_float( $v ) ) {
			return sprintf( '%.0f', $v );
		}
		return is_scalar( $v ) ? trim( (string) $v ) : '';
	}

	/** @param mixed $v Value. @return float */
	private static function num( $v ) {
		return is_numeric( $v ) ? (float) $v : 0.0;
	}

	/** @param array $a Trendyol address. @param array $p Package. @return array Address DTO values. */
	private static function address( array $a, array $p ) {
		$first = trim( (string) ( $a['firstName'] ?? '' ) );
		$last  = trim( (string) ( $a['lastName'] ?? '' ) );
		if ( '' === $first . $last ) {
			$full = trim( (string) ( $a['fullName'] ?? '' ) );
			if ( '' === $full ) {
				$full = trim( (string) ( $p['customerFirstName'] ?? '' ) . ' ' . (string) ( $p['customerLastName'] ?? '' ) );
			}
			$parts = preg_split( '/\s+/', $full );
			$last  = count( $parts ) > 1 ? (string) array_pop( $parts ) : '';
			$first = implode( ' ', $parts );
		}
		return array(
			'first_name'   => $first,
			'last_name'    => $last,
			'company'      => (string) ( $a['company'] ?? '' ),
			'address_1'    => (string) ( $a['address1'] ?? $a['fullAddress'] ?? '' ),
			'address_2'    => (string) ( $a['address2'] ?? '' ),
			'neighborhood' => (string) ( $a['neighborhood'] ?? '' ),
			'district'     => (string) ( $a['district'] ?? '' ),
			'city'         => (string) ( $a['city'] ?? '' ),
			'city_code'    => (int) ( $a['cityCode'] ?? 0 ),
			'postcode'     => (string) ( $a['postalCode'] ?? '' ),
			'country'      => (string) ( $a['countryCode'] ?? 'TR' ),
			'phone'        => (string) ( $a['phone'] ?? '' ),
		);
	}

	/**
	 * One shipment package as an order DTO.
	 *
	 * @param array $p Package.
	 * @return Eru_MPO_Order_DTO
	 */
	public function to_dto( array $p ) {
		$id    = self::id_string( $p['shipmentPackageId'] ?? $p['id'] ?? '' );
		$lines = array();
		foreach ( (array) ( $p['lines'] ?? array() ) as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$qty   = max( 1, (int) ( $l['quantity'] ?? 1 ) );
			$gross = self::num( $l['lineGrossAmount'] ?? $l['amount'] ?? 0 );
			$paid  = self::num( $l['lineUnitPrice'] ?? $l['price'] ?? 0 );
			if ( $paid <= 0 ) {
				$paid = $gross;
			}
			$gross = max( $gross, $paid );
			$disc  = round( ( $gross - $paid ) * $qty, 2 );
			$meta  = array();
			foreach ( array(
				'ty_seller_discount'      => $l['lineSellerDiscount'] ?? null,
				'ty_marketplace_discount' => $l['lineTyDiscount'] ?? null,
			) as $k => $v ) {
				if ( is_numeric( $v ) && (float) $v > 0 ) {
					$meta[ $k ] = wc_format_decimal( (float) $v, 2 );
				}
			}
			if ( $disc > 0 ) {
				$meta['discount'] = wc_format_decimal( $disc, 2 );
			}
			$lines[] = array(
				'remote_line_id'  => self::id_string( $l['id'] ?? $l['lineId'] ?? '' ),
				'sku'             => trim( (string) ( $l['stockCode'] ?? $l['merchantSku'] ?? '' ) ),
				'barcode'         => trim( (string) ( $l['barcode'] ?? '' ) ),
				'name'            => (string) ( $l['productName'] ?? '' ),
				'quantity'        => $qty,
				'unit_price'      => $gross,
				'discount'        => $disc,
				'vat_rate'        => is_numeric( $l['vatRate'] ?? null ) ? (float) $l['vatRate'] : null,
				'commission_rate' => is_numeric( $l['commission'] ?? null ) && (float) $l['commission'] > 0 ? (float) $l['commission'] : null,
				'status'          => self::line_status( $l['orderLineItemStatusName'] ?? $l['lineItemStatus'] ?? '' ),
				'meta'            => $meta,
			);
		}
		$inv    = is_array( $p['invoiceAddress'] ?? null ) ? $p['invoiceAddress'] : array();
		$ship   = is_array( $p['shipmentAddress'] ?? null ) ? $p['shipmentAddress'] : array();
		$offset = (int) apply_filters( 'eru_mpo_trendyol_time_offset', 3 * HOUR_IN_SECONDS );
		$date   = is_numeric( $p['orderDate'] ?? null ) ? (int) floor( (float) $p['orderDate'] / 1000 ) - $offset : 0;
		$status = (string) ( $p['status'] ?? '' );
		$first  = fn( ...$v ) => (string) ( current( array_filter( array_map( fn( $x ) => is_scalar( $x ) ? trim( (string) $x ) : '', $v ), 'strlen' ) ) ?: '' );
		$meta   = array(
			'ty_cargo_sender_number' => self::id_string( $p['cargoSenderNumber'] ?? '' ),
			'ty_invoice_link'        => (string) ( $p['invoiceLink'] ?? '' ),
		);
		if ( ! empty( $p['commercial'] ) ) {
			$meta['ty_commercial'] = 'yes';
		}
		if ( ! empty( $p['micro'] ) ) {
			$meta['ty_micro'] = 'yes';
		}
		return Eru_MPO_Order_DTO::from_array(
			array(
				'remote_ref'      => $id,
				'package_id'      => $id,
				'order_number'    => self::id_string( $p['orderNumber'] ?? '' ),
				'status'          => $this->map_status( $status ),
				'remote_status'   => $status,
				'ordered_at'      => max( 0, $date ),
				'currency'        => (string) ( $p['currencyCode'] ?? 'TRY' ),
				'customer'        => array(
					'first_name'      => (string) ( $p['customerFirstName'] ?? '' ),
					'last_name'       => (string) ( $p['customerLastName'] ?? '' ),
					'email'           => (string) ( $p['customerEmail'] ?? '' ),
					'phone'           => (string) ( $ship['phone'] ?? $inv['phone'] ?? '' ),
					'identity_number' => $first( $p['tcIdentityNumber'] ?? '', $p['identityNumber'] ?? '', $inv['tcIdentityNumber'] ?? '' ),
					'tax_number'      => $first( $inv['taxNumber'] ?? '', $p['taxNumber'] ?? '' ),
					'tax_office'      => $first( $inv['taxOffice'] ?? '', $p['taxOffice'] ?? '' ),
					'company'         => (string) ( $inv['company'] ?? '' ),
				),
				'billing'         => self::address( $inv ? $inv : $ship, $p ),
				'shipping'        => self::address( $ship ? $ship : $inv, $p ),
				'lines'           => $lines,
				'totals'          => array(
					'total' => self::num( $p['packageTotalPrice'] ?? $p['totalPrice'] ?? 0 ),
				),
				'cargo_provider'  => (string) ( $p['cargoProviderName'] ?? '' ),
				'tracking_number' => self::id_string( $p['cargoTrackingNumber'] ?? '' ),
				'tracking_url'    => (string) ( $p['cargoTrackingLink'] ?? '' ),
				'meta'            => array_filter( $meta, 'strlen' ),
				'raw'             => $p,
			)
		);
	}

	/**
	 * Tells Trendyol the shop took the package (status Picking).
	 *
	 * @param WC_Order $order Imported order (pending meta changes are saved by the caller).
	 * @return true|WP_Error
	 */
	public function push_acknowledge( $order ) {
		if ( ! $this->is_configured() ) {
			return $this->not_configured();
		}
		$pkg    = (string) $order->get_meta( '_eru_mpo_package_id' );
		$pkg    = '' !== $pkg ? $pkg : (string) $order->get_meta( '_eru_mpo_remote_ref' );
		$number = (string) $order->get_meta( '_eru_mpo_order_number' );
		if ( '' === $pkg ) {
			return new WP_Error( 'eru_mpo_ack_no_package', __( 'Siparişte Trendyol paket numarası yok.', 'marketplace-orders-for-woocommerce' ), array( 'retryable' => false ) );
		}
		$known = (string) $order->get_meta( '_eru_mpo_remote_status' );
		if ( '' !== $known && 'Created' !== $known ) {
			return $this->past_created( $known );
		}
		if ( 'yes' === (string) $order->get_meta( self::CHECK_META ) ) {
			$st = $this->package_status( $number, $pkg, true );
			if ( is_wp_error( $st ) ) {
				return $st;
			}
			if ( '' !== $st && 'Created' !== $st ) {
				$order->delete_meta_data( self::CHECK_META );
				return $this->past_created( $st );
			}
		}
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			$lid = (string) $item->get_meta( '_eru_mpo_remote_line_id' );
			if ( '' === $lid || '' !== (string) $item->get_meta( '_eru_mpo_line_status' ) ) {
				continue;
			}
			$lines[] = array(
				'lineId'   => ctype_digit( $lid ) ? (int) $lid : $lid,
				'quantity' => max( 1, (int) $item->get_quantity() ),
			);
		}
		if ( ! $lines ) {
			return new WP_Error( 'eru_mpo_ack_no_lines', __( 'Bildirilecek Trendyol satırı yok.', 'marketplace-orders-for-woocommerce' ), array( 'retryable' => false ) );
		}
		// Stored before sending: if the run dies after the request left, the next attempt reads the package first.
		$order->update_meta_data( self::CHECK_META, 'yes' );
		$order->save_meta_data();
		$res = $this->call(
			'PUT',
			'package',
			array( 'packageId' => $pkg ),
			array(),
			array(
				'lines'  => $lines,
				'params' => (object) array(),
				'status' => 'Picking',
			),
			$pkg
		);
		if ( $res['ok'] ) {
			$order->delete_meta_data( self::CHECK_META );
			return true;
		}
		if ( ! $res['ambiguous'] ) {
			$order->delete_meta_data( self::CHECK_META ); // A clear refusal: nothing was applied.
		}
		if ( in_array( $res['code'], array( 400, 409 ), true ) ) {
			// Refused: maybe someone already moved the package on. Read it once before calling it a failure.
			$st = $this->package_status( $number, $pkg, true );
			if ( is_string( $st ) && '' !== $st && 'Created' !== $st ) {
				return $this->past_created( $st );
			}
		}
		return Eru_MPO_Http::to_error( $res );
	}

	/** @param string $status Trendyol status past Created. @return true|WP_Error */
	private function past_created( $status ) {
		if ( in_array( $this->map_status( $status ), Eru_MPO_Statuses::TERMINAL, true ) ) {
			/* translators: %s: Trendyol status */
			return new WP_Error( 'eru_mpo_ack_closed', sprintf( __( 'Paket Trendyol\'da %s durumunda; bildirim gönderilmedi.', 'marketplace-orders-for-woocommerce' ), $status ), array( 'retryable' => false ) );
		}
		return true;
	}

	/**
	 * What a new package means for an existing order with the same order number whose package Trendyol reports
	 * cancelled (the stored remote status, else a read by order number; a local cancel alone does not count): when the new package's active lines (SKU, else barcode, with
	 * quantities) equal that order's remaining active lines it is the same package re-created, so it is linked to the
	 * order; otherwise (a split such as UnPacked into packages with other lines) the new package becomes its own
	 * order and the earlier order is replaced. Orders already replaced and orders whose package is still active
	 * (a genuine split next to it) are left alone.
	 *
	 * @param Eru_MPO_Order_DTO $dto New package.
	 * @return array{link: int, replace: int}
	 * @throws RuntimeException When the earlier package cannot be read (the import is retried later).
	 */
	public function predecessor( Eru_MPO_Order_DTO $dto ) {
		$ref = $dto->remote_ref;
		if ( isset( $this->decisions[ $ref ] ) ) {
			return $this->decisions[ $ref ];
		}
		$out    = array(
			'link'    => 0,
			'replace' => 0,
		);
		$number = trim( $dto->order_number );
		if ( '' !== $number && ! in_array( $dto->status, Eru_MPO_Statuses::TERMINAL, true ) ) {
			foreach ( self::orders_by_number( $number ) as $id ) {
				$order = wc_get_order( $id );
				$pkg   = $order ? (string) $order->get_meta( '_eru_mpo_remote_ref' ) : '';
				if ( '' === $pkg || $pkg === $ref || $order->get_meta( '_eru_mpo_replaced_by' ) ) {
					continue;
				}
				// Remote evidence only: the package's stored Trendyol status, else a read by order number. A local
				// (admin) cancel alone never makes an order linkable or replaceable.
				$cancelled = in_array( (string) $order->get_meta( '_eru_mpo_remote_status' ), self::CANCELLED, true );
				if ( ! $cancelled ) {
					$st = $this->package_status( $number, $pkg );
					if ( is_wp_error( $st ) ) {
						/* translators: 1: package id, 2: error */
						throw new RuntimeException( sprintf( __( 'Aynı sipariş numaralı önceki paketin (%1$s) durumu okunamadı; paket sonraki çekimde yeniden denenecek: %2$s', 'marketplace-orders-for-woocommerce' ), $pkg, $st->get_error_message() ) );
					}
					$cancelled = in_array( $st, self::CANCELLED, true );
				}
				if ( ! $cancelled ) {
					continue;
				}
				if ( self::same_lines( $order, $dto ) ) {
					$out['link'] = $id;
					break;
				}
				if ( ! $out['replace'] ) {
					$out['replace'] = $id;
				}
			}
		}
		if ( $out['link'] ) {
			$out['replace'] = 0;
		}
		$this->decisions[ $ref ] = $out;
		return $out;
	}

	/**
	 * @param WC_Order          $order Order.
	 * @param Eru_MPO_Order_DTO $dto   Package.
	 * @return bool The package's active lines equal the order's remaining active lines (SKU or barcode × quantity).
	 */
	public static function same_lines( $order, Eru_MPO_Order_DTO $dto ) {
		$want = array();
		foreach ( $dto->lines as $l ) {
			if ( 'active' === $l->status ) {
				$k          = '' !== $l->sku ? $l->sku : $l->barcode;
				$want[ $k ] = ( $want[ $k ] ?? 0 ) + (int) $l->quantity;
			}
		}
		$have = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && '' === (string) $item->get_meta( '_eru_mpo_line_status' ) ) {
				$sku          = (string) $item->get_meta( '_eru_mpo_sku' );
				$k            = '' !== $sku ? $sku : (string) $item->get_meta( '_eru_mpo_barcode' );
				$have[ $k ] = ( $have[ $k ] ?? 0 ) + (int) $item->get_quantity();
			}
		}
		ksort( $want );
		ksort( $have );
		return array() !== $want && $want === $have;
	}

	/**
	 * eru_mpo_link_order: the order a re-created package (same lines) belongs to.
	 *
	 * @param int               $found   Order id another callback found (kept).
	 * @param Eru_MPO_Order_DTO $dto     New package.
	 * @param Eru_MPO_Adapter   $adapter Adapter.
	 * @return int Order id or 0.
	 */
	public static function link_order( $found, $dto, $adapter ) {
		if ( (int) $found > 0 || ! $adapter instanceof self || ! $dto instanceof Eru_MPO_Order_DTO ) {
			return (int) $found;
		}
		return $adapter->predecessor( $dto )['link'];
	}

	/**
	 * eru_mpo_replaced_orders: the order a split package (other lines) replaces.
	 *
	 * @param int[]             $ids     Ids from other callbacks.
	 * @param Eru_MPO_Order_DTO $dto     New package (now its own order).
	 * @param Eru_MPO_Adapter   $adapter Adapter.
	 * @return int[]
	 */
	public static function replaced_orders( $ids, $dto, $adapter ) {
		$ids = is_array( $ids ) ? $ids : array();
		if ( $adapter instanceof self && $dto instanceof Eru_MPO_Order_DTO ) {
			$r = $adapter->predecessor( $dto )['replace'];
			if ( $r ) {
				$ids[] = $r;
			}
		}
		return $ids;
	}

	/**
	 * Trendyol orders with this order number (oldest first, trashed ones left out).
	 *
	 * @param string $number Order number.
	 * @return int[]
	 */
	public static function orders_by_number( $number ) {
		global $wpdb;
		if ( Eru_MPO_Importer::hpos() ) {
			$sql = "SELECT m.order_id FROM {$wpdb->prefix}wc_orders_meta m JOIN {$wpdb->prefix}wc_orders o ON o.id = m.order_id AND o.type = 'shop_order' AND o.status <> 'trash' JOIN {$wpdb->prefix}wc_orders_meta k ON k.order_id = m.order_id AND k.meta_key = '_eru_mpo_marketplace' AND k.meta_value = %s WHERE m.meta_key = '_eru_mpo_order_number' AND m.meta_value = %s ORDER BY m.order_id ASC LIMIT 10";
		} else {
			$sql = "SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} o ON o.ID = m.post_id AND o.post_type = 'shop_order' AND o.post_status <> 'trash' JOIN {$wpdb->postmeta} k ON k.post_id = m.post_id AND k.meta_key = '_eru_mpo_marketplace' AND k.meta_value = %s WHERE m.meta_key = '_eru_mpo_order_number' AND m.meta_value = %s ORDER BY m.post_id ASC LIMIT 10";
		}
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, self::KEY, (string) $number ) ) ); // phpcs:ignore
	}

	/**
	 * One page of approved products (GET /product/sellers/{id}/products/approved?page=&size=). Each content item or
	 * each of its variants becomes a listing: stockCode (the seller's SKU) → wc_sku and remote_sku, barcode,
	 * contentId → remote_id, quantity, salePrice; archived, locked or blacklisted items and items not on sale are not
	 * salable.
	 *
	 * @param int $page Page, 0-based.
	 * @return array{items: Eru_MPO_Listing_DTO[], more: bool}|WP_Error
	 */
	public function fetch_listings( $page ) {
		if ( ! $this->is_configured() ) {
			return $this->not_configured();
		}
		$page = max( 0, (int) $page );
		$res  = $this->call(
			'GET',
			'approved',
			array(),
			array(
				'page' => $page,
				'size' => self::LISTING_PAGE,
			)
		);
		if ( ! $res['ok'] ) {
			return Eru_MPO_Http::to_error( $res );
		}
		if ( ! is_array( $res['data'] ) ) {
			return new WP_Error( 'eru_mpo_bad_json', __( 'Trendyol okunamayan bir yanıt döndü.', 'marketplace-orders-for-woocommerce' ), array( 'retryable' => true ) );
		}
		$items = array();
		foreach ( (array) ( $res['data']['content'] ?? array() ) as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$variants = ( isset( $it['variants'] ) && is_array( $it['variants'] ) && $it['variants'] ) ? $it['variants'] : array( $it );
			foreach ( $variants as $v ) {
				if ( ! is_array( $v ) ) {
					continue;
				}
				$bc = self::id_string( $v['barcode'] ?? '' );
				$sc = trim( (string) ( $v['stockCode'] ?? ( $it['stockCode'] ?? '' ) ) );
				if ( '' === $bc && '' === $sc ) {
					continue;
				}
				$off     = ! empty( $v['archived'] ) || ! empty( $it['archived'] ) || ! empty( $v['locked'] ) || ! empty( $v['blacklisted'] );
				$items[] = Eru_MPO_Listing_DTO::from_array(
					array(
						'wc_sku'     => '' !== $sc ? $sc : $bc,
						'remote_sku' => $sc,
						'barcode'    => $bc,
						'remote_id'  => self::id_string( $it['contentId'] ?? ( $it['productContentId'] ?? ( $v['id'] ?? ( $it['id'] ?? '' ) ) ) ),
						'salable'    => ! $off && ( ! isset( $v['onSale'] ) || (bool) $v['onSale'] ),
						'stock'      => $v['quantity'] ?? null,
						'price'      => $v['salePrice'] ?? null,
					)
				);
			}
		}
		return array(
			'items' => $items,
			'more'  => $page + 1 < (int) ( $res['data']['totalPages'] ?? 0 ),
		);
	}

	/**
	 * Stock: POST /inventory/sellers/{id}/products/price-and-inventory with {items: [{barcode, quantity}]}.
	 *
	 * @param array $items List of {sku, barcode, quantity}.
	 * @return array{ok: int, failed: array<string,string>, batch: string}|WP_Error
	 */
	public function push_inventory( array $items ) {
		return $this->push_items( $items, 'stock' );
	}

	/**
	 * Price: the same endpoint with {items: [{barcode, salePrice, listPrice}]} (list price never below the sale price).
	 *
	 * @param array $items List of {sku, barcode, price, list_price}.
	 * @return array{ok: int, failed: array<string,string>, batch: string}|WP_Error
	 */
	public function push_price( array $items ) {
		return $this->push_items( $items, 'price' );
	}

	/**
	 * Trendyol matches price-and-inventory items by barcode: an item without one fails here without a request.
	 * The values are absolute, so a retry after an ambiguous failure cannot apply anything twice. The answer is a
	 * batch id (processed later by Trendyol); it is returned for the log.
	 *
	 * @param array  $items Items.
	 * @param string $kind  stock | price.
	 * @return array|WP_Error
	 */
	private function push_items( array $items, $kind ) {
		if ( ! $this->is_configured() ) {
			return $this->not_configured();
		}
		$send   = array();
		$failed = array();
		foreach ( $items as $i => $it ) {
			$sku = (string) ( $it['sku'] ?? '' );
			$bc  = (string) ( $it['barcode'] ?? '' );
			if ( '' === $bc ) {
				$failed[ '' !== $sku ? $sku : '#' . $i ] = __( 'Trendyol barkodu bilinmiyor (ürün Trendyol listesinde bulunamadı, GTIN de yok).', 'marketplace-orders-for-woocommerce' );
				continue;
			}
			$row = array( 'barcode' => $bc );
			if ( 'stock' === $kind ) {
				$row['quantity'] = max( 0, (int) ( $it['quantity'] ?? 0 ) );
			} else {
				$price            = round( (float) ( $it['price'] ?? 0 ), 2 );
				$row['salePrice'] = $price;
				$row['listPrice'] = round( max( $price, (float) ( $it['list_price'] ?? 0 ) ), 2 );
			}
			$send[] = $row;
		}
		$ok      = 0;
		$batches = array();
		foreach ( array_chunk( $send, self::PUSH_MAX ) as $chunk ) {
			$res = $this->call( 'POST', 'inventory', array(), array(), array( 'items' => $chunk ) );
			if ( ! $res['ok'] ) {
				return Eru_MPO_Http::to_error( $res );
			}
			$ok       += count( $chunk );
			$batches[] = is_array( $res['data'] ) ? sanitize_text_field( (string) ( $res['data']['batchRequestId'] ?? '' ) ) : '';
		}
		return array(
			'ok'     => $ok,
			'failed' => $failed,
			'batch'  => implode( ', ', array_filter( $batches ) ),
		);
	}

	/**
	 * "Yeniden çek" for one order: its packages read by order number (one request, any status), as DTOs. The
	 * importer only updates known packages from them.
	 *
	 * @param WC_Order $order Order.
	 * @return Eru_MPO_Order_DTO[]|WP_Error
	 */
	public function fetch_order( $order ) {
		$number = $order instanceof WC_Order ? (string) $order->get_meta( '_eru_mpo_order_number' ) : '';
		if ( '' === $number ) {
			return new WP_Error( 'eru_mpo_no_number', __( 'Siparişin Trendyol sipariş numarası yok.', 'marketplace-orders-for-woocommerce' ), array( 'retryable' => false ) );
		}
		$packages = $this->packages_by_number( $number, true );
		if ( is_wp_error( $packages ) ) {
			return $packages;
		}
		return array_map( array( $this, 'to_dto' ), array_values( $packages ) );
	}

	/**
	 * Connection test (run by Action Scheduler from the Trendyol tab): one order list request for the last day.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return $this->not_configured();
		}
		$res = $this->call(
			'GET',
			'orders',
			array(),
			array(
				'startDate' => ( time() - DAY_IN_SECONDS ) * 1000,
				'endDate'   => time() * 1000,
				'page'      => 0,
				'size'      => 1,
			)
		);
		return $res['ok'] ? true : Eru_MPO_Http::to_error( $res );
	}
}
