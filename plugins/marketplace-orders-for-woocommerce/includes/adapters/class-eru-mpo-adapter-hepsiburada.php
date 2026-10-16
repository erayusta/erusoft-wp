<?php
/**
 * Hepsiburada adapter: OMS order lines grouped into order DTOs, lifecycle from the package endpoints, optional
 * auto-pack, cargo details recovered after the first fetch.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hepsiburada's order endpoint lists order LINES; lines are grouped by order number into one DTO (remote_ref =
 * order number), so an order with several lines is one WooCommerce order.
 *
 * Requests: Basic auth (merchant ID : password) and the required User-Agent = the developer username, headers
 * only. The base URL is the live or the SIT OMS host (editable, https on hepsiburada.com only). Without merchant ID,
 * password, developer username and a base URL the adapter is not configured and sends nothing. Dates are sent as
 * "Y-m-d H:i" in Europe/Istanbul; lists are read with offset/limit until a short page or totalCount.
 *
 * Lines: unit price and line total are before discounts; the seller's and Hepsiburada's discounts together are
 * the line discount (the buyer paid the rest). Both discounts, the reported commission amount and the Hepsiburada
 * SKU stay as line meta (_eru_mpo_hb_merchant_discount, _eru_mpo_hb_marketplace_discount,
 * _eru_mpo_commission_amount, _eru_mpo_hb_sku). The commission rate is the reported rate when there is one,
 * otherwise the reported amount over the line total less the seller's discount.
 *
 * Lifecycle (update only, window at most 30 days): /packages/…/shipped → shipped, /delivered → delivered,
 * /undelivered → shipped (a failed attempt; remote status UnDelivered, not terminal), /orders/…/cancelled → the
 * cancelled lines (cancel-line-<line id> events in the importer) or, when every active line of the known order is
 * cancelled, the whole order. A 404 means "nothing in this window". A failing endpoint does not stop the others:
 * it is logged and backs off on its own (option eru_mpo_sync_endpoints_hepsiburada).
 *
 * Lines are grouped by order number and de-duplicated by line id (offset paging can return a shifted row twice).
 *
 * Stock bridge: the listing API (its own host, setting listing_base, no default) lists the merchant's listings
 * (merchantSku, hepsiburadaSku, isSalable) and takes stock-uploads and price-uploads by merchant SKU.
 *
 * Cargo recovery: known orders without a tracking number (open statuses, inside the lifecycle window, not asked in
 * the last 30 minutes) are re-polled, up to 20 per run (filter eru_mpo_hepsiburada_cargo_budget): the package
 * number comes from this plugin's packing or from the open package list, and the package detail gives the cargo
 * company, tracking number and link (and shipped/delivered).
 *
 * Auto-pack = this marketplace's acknowledgement (Eru_MPO_Ack, ack mode): the order's lines are packed with
 * POST /packages/merchantid/{id} (lines that may share a package are asked first). Progress is kept on the order
 * (_eru_mpo_hb_packed_lines, _eru_mpo_hb_skipped_lines, _eru_mpo_hb_packages); the retry queue state is
 * _eru_mpo_hb_pack_state (queued | retry | packed | failed) with _eru_mpo_hb_pack_tries, backoff 5, 15, 60, 60
 * minutes, at most 5 attempts. A check flag is stored before each POST and cleared on a clear answer; while it is
 * set, the next attempt first reads the open packages of the order: lines a package shows are taken as packed,
 * a package of the order that does not show the lines keeps the attempt ambiguous (no POST, review flag, retried),
 * no package at all means it did not pack. One attempt spends at most PACK_BUDGET seconds on requests.
 */
class Eru_MPO_Adapter_Hepsiburada extends Eru_MPO_Adapter_Base {

	const KEY        = 'hepsiburada';
	const BASE_LIVE  = 'https://oms-external.hepsiburada.com';
	const BASE_SIT   = 'https://oms-external-sit.hepsiburada.com';
	const TZ         = 'Europe/Istanbul';
	const MAX_PAGES  = 100;
	const CHECK_META = '_eru_mpo_hb_pack_check';
	/** Seconds one pack attempt may spend on requests (the acknowledgement lock outlives it, Eru_MPO_Ack::LOCK_TTL). */
	const PACK_BUDGET = 600;
	/** Option: per-endpoint backoff of the lifecycle endpoints ({name: {fails, until}}). */
	const ENDPOINTS = 'eru_mpo_sync_endpoints_hepsiburada';
	const PACKED     = '_eru_mpo_hb_packed_lines';
	const SKIPPED    = '_eru_mpo_hb_skipped_lines';
	const PACKAGES   = '_eru_mpo_hb_packages';
	/** Listings per page and items per stock/price upload. */
	const LISTING_PAGE = 100;
	const UPLOAD_MAX   = 500;
	/** Package status (letters only, lower case) → logical status. */
	const PACKAGE_STATUS = array(
		'shipped'           => 'shipped',
		'intransit'         => 'shipped',
		'transferred'       => 'shipped',
		'onthewaytobuyer'   => 'shipped',
		'atcollectionpoint' => 'shipped',
		'delivered'         => 'delivered',
	);

	/** @var array|null Open packages read in this request. */
	private $open = null;

	/** Hooks. */
	public static function init() {
		add_filter( 'eru_mpo_adapters', array( __CLASS__, 'register' ), 5 );
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
		return 'Hepsiburada';
	}

	/** @return array Settings of eru_mpo_mp_hepsiburada (see Eru_MPO_Credentials). */
	public static function settings_fields() {
		return array(
			'enabled'      => array(
				'type'  => 'checkbox',
				'label' => __( 'Hepsiburada', 'marketplace-orders-for-woocommerce' ),
				'help'  => __( 'Hepsiburada siparişlerini çek (ana anahtar da açık olmalı).', 'marketplace-orders-for-woocommerce' ),
			),
			'observe'      => array(
				'type'  => 'checkbox',
				'label' => __( 'Gözlem modu', 'marketplace-orders-for-woocommerce' ),
				'help'  => __( 'Yalnızca bu pazaryeri için: çek ve günlüğe yaz, sipariş oluşturma, paketleme yapma.', 'marketplace-orders-for-woocommerce' ),
			),
			'merchant_id'  => array(
				'type'    => 'text',
				'label'   => __( 'Mağaza ID (merchant ID)', 'marketplace-orders-for-woocommerce' ),
				'pattern' => '/[^A-Za-z0-9-]/',
			),
			'password'     => array(
				'type'  => 'secret',
				'label' => __( 'API şifresi', 'marketplace-orders-for-woocommerce' ),
			),
			'dev_username' => array(
				'type'    => 'text',
				'label'   => __( 'Geliştirici kullanıcı adı', 'marketplace-orders-for-woocommerce' ),
				'help'    => __( 'Hepsiburada\'nın zorunlu tuttuğu User-Agent başlığında gönderilir.', 'marketplace-orders-for-woocommerce' ),
				'pattern' => '/[^A-Za-z0-9 ._@-]/',
			),
			'environment'  => array(
				'type'    => 'select',
				'label'   => __( 'Ortam', 'marketplace-orders-for-woocommerce' ),
				'default' => 'live',
				'options' => array(
					'live' => __( 'Canlı', 'marketplace-orders-for-woocommerce' ),
					'sit'  => __( 'Test (SIT)', 'marketplace-orders-for-woocommerce' ),
				),
			),
			'base_live'    => array(
				'type'    => 'url',
				'label'   => __( 'Canlı OMS adresi', 'marketplace-orders-for-woocommerce' ),
				'default' => self::BASE_LIVE,
				'hosts'   => array( 'hepsiburada.com' ),
			),
			'base_sit'     => array(
				'type'    => 'url',
				'label'   => __( 'Test OMS adresi', 'marketplace-orders-for-woocommerce' ),
				'default' => self::BASE_SIT,
				'hosts'   => array( 'hepsiburada.com' ),
			),
			'listing_base' => array(
				'type'    => 'url',
				'label'   => __( 'Listeleme API adresi', 'marketplace-orders-for-woocommerce' ),
				'help'    => __( 'Stok köprüsü için (ilanlar, stok ve fiyat gönderimi). Hepsiburada\'nın size verdiği, ortamınıza uygun listeleme adresini girin; boşken stok ve fiyat gönderilmez.', 'marketplace-orders-for-woocommerce' ),
				'default' => '',
				'hosts'   => array( 'hepsiburada.com' ),
			),
			'ack_mode'     => array(
				'type'    => 'select',
				'label'   => __( 'Otomatik paketleme', 'marketplace-orders-for-woocommerce' ),
				'default' => 'never',
				'options' => Eru_MPO_Ack::modes(),
			),
			'ack_status'   => array(
				'type'    => 'select',
				'label'   => __( 'Paketleme durumu', 'marketplace-orders-for-woocommerce' ),
				'help'    => __( '"Sipariş bu duruma geçince" seçiliyse kullanılır.', 'marketplace-orders-for-woocommerce' ),
				'default' => Eru_MPO_Ack::DEFAULT_STATUS,
				'options' => Eru_MPO_Ack::status_options(),
			),
		);
	}

	/** @return array Stored settings over the defaults. */
	public static function settings() {
		return Eru_MPO_Credentials::values( self::KEY, self::settings_fields() );
	}

	/** @return array{merchant_id: string, password: string, dev_username: string} Secrets included: never print them. */
	public static function credentials() {
		$s = self::settings();
		return array(
			'merchant_id'  => trim( (string) $s['merchant_id'] ),
			'password'     => (string) $s['password'],
			'dev_username' => trim( (string) $s['dev_username'] ),
		);
	}

	/** @return string live | sit. */
	public static function environment() {
		return 'sit' === self::settings()['environment'] ? 'sit' : 'live';
	}

	/** @return string OMS base URL without a trailing slash ('' = none). */
	public static function base_url() {
		$s   = self::settings();
		$env = self::environment();
		$url = (string) ( 'sit' === $env ? $s['base_sit'] : $s['base_live'] );
		return untrailingslashit( (string) apply_filters( 'eru_mpo_hepsiburada_base_url', $url, $env ) );
	}

	/** @return string Required User-Agent: the developer username. */
	public static function user_agent() {
		return self::credentials()['dev_username'];
	}

	/** @return bool Merchant ID, password, developer username and a base URL are set. */
	public function is_configured() {
		$c = self::credentials();
		return '' !== $c['merchant_id'] && '' !== $c['password'] && '' !== $c['dev_username'] && '' !== self::base_url();
	}

	/** @return array Acknowledgement profile (Eru_MPO_Ack): packing. */
	public function ack_profile() {
		return array(
			'state'   => '_eru_mpo_hb_pack_state',
			'tries'   => '_eru_mpo_hb_pack_tries',
			'error'   => '_eru_mpo_hb_pack_error',
			'at'      => '_eru_mpo_hb_packed_at',
			'done'    => 'packed',
			'delays'  => array( 5 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS, HOUR_IN_SECONDS ),
			'max'     => 5,
			'advance' => 'ready',
			'label'   => __( 'Hepsiburada otomatik paketleme', 'marketplace-orders-for-woocommerce' ),
		);
	}

	/** @return WP_Error Not configured (nothing was sent). */
	private function not_configured() {
		return new WP_Error( 'eru_mpo_not_configured', __( 'Hepsiburada API bilgileri eksik: mağaza ID, şifre, geliştirici kullanıcı adı ve OMS adresi gerekli.', 'marketplace-orders-for-woocommerce' ), array( 'retryable' => false ) );
	}

	/** @return string Merchant segment for paths. */
	private static function mid() {
		return rawurlencode( self::credentials()['merchant_id'] );
	}

	/**
	 * One API call.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path from the base URL.
	 * @param array      $query  Query (never credentials).
	 * @param array|null $body   JSON body.
	 * @param string     $ref    Log reference.
	 * @return array Eru_MPO_Http result.
	 */
	private function call( $method, $path, array $query = array(), $body = null, $ref = '' ) {
		$c   = self::credentials();
		$url = self::base_url() . $path;
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url );
		}
		$auth = Eru_MPO_Http::basic( $c['merchant_id'], $c['password'] );
		return Eru_MPO_Http::request(
			self::KEY,
			$method,
			$url,
			array(
				'Authorization' => $auth,
				'User-Agent'    => $c['dev_username'],
			),
			$body,
			array(
				'secrets' => array( $c['password'], substr( $auth, 6 ) ),
				'ref'     => $ref,
			)
		);
	}

	/**
	 * Every row of a list endpoint (offset/limit).
	 *
	 * @param string $path  Path.
	 * @param array  $query Extra query.
	 * @param int    $limit     Page size (filter eru_mpo_hepsiburada_page_limit can only lower it).
	 * @param int    $deadline  UTC timestamp after which no further page is asked (0 = none).
	 * @param int    $max_pages Page cap.
	 * @return array[]|WP_Error A 404 is returned as an error with status 404.
	 */
	private function paged( $path, array $query, $limit, $deadline = 0, $max_pages = self::MAX_PAGES ) {
		$limit  = max( 1, min( (int) $limit, (int) apply_filters( 'eru_mpo_hepsiburada_page_limit', (int) $limit, $path ) ) );
		$all    = array();
		$offset = 0;
		$page   = 0;
		do {
			if ( $deadline && time() > $deadline ) {
				return new WP_Error( 'eru_mpo_time_budget', __( 'Süre sınırı doldu; kalan iş bir sonraki denemede.', 'marketplace-orders-for-woocommerce' ), array( 'retryable' => true ) );
			}
			$res = $this->call( 'GET', $path, array_merge( $query, array( 'offset' => $offset, 'limit' => $limit ) ) );
			if ( ! $res['ok'] ) {
				return Eru_MPO_Http::to_error( $res );
			}
			$data = $res['data'];
			if ( ! is_array( $data ) ) {
				return new WP_Error( 'eru_mpo_bad_json', __( 'Hepsiburada okunamayan bir yanıt döndü.', 'marketplace-orders-for-woocommerce' ), array( 'retryable' => true ) );
			}
			if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
				$rows = $data['items'];
			} elseif ( self::is_list( $data ) ) {
				$rows = $data;
			} else {
				$rows = array();
			}
			foreach ( $rows as $r ) {
				if ( is_array( $r ) ) {
					$all[] = $r;
				}
			}
			$total   = isset( $data['totalCount'] ) && is_numeric( $data['totalCount'] ) ? (int) $data['totalCount'] : null;
			$offset += $limit;
			++$page;
		} while ( count( $rows ) === $limit && ( null === $total || $offset < $total ) && $page < (int) $max_pages );
		return $all;
	}

	/** @param int $ts UTC. @return string "Y-m-d H:i" in Turkish time. */
	private static function local( $ts ) {
		return wp_date( 'Y-m-d H:i', (int) $ts, new DateTimeZone( self::TZ ) );
	}

	/** @return array begindate / enddate for a window (end + 1 minute, the format has no seconds). */
	private static function window( $since, $until ) {
		return array(
			'begindate' => self::local( $since ),
			'enddate'   => self::local( (int) $until + MINUTE_IN_SECONDS ),
		);
	}

	/** @param array $a Array. @return bool A plain list (PHP 8.0 has no array_is_list()). */
	private static function is_list( array $a ) {
		return array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 );
	}

	/** @param mixed $v Value. @return float */
	private static function num( $v ) {
		return is_numeric( $v ) ? (float) $v : 0.0;
	}

	/** @param array $row Row. @param string[] $keys Candidate keys (camelCase and PascalCase). @return string First non-empty scalar. */
	private static function val( array $row, array $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $row[ $k ] ) && is_scalar( $row[ $k ] ) && '' !== trim( (string) $row[ $k ] ) ) {
				return trim( (string) $row[ $k ] );
			}
		}
		return '';
	}

	/** @param int $since UTC. @param int $until UTC. @return Eru_MPO_Order_DTO[]|WP_Error One DTO per order number. */
	public function fetch_orders( $since, $until ) {
		if ( ! $this->is_configured() ) {
			return $this->not_configured();
		}
		$items = $this->paged( '/orders/merchantid/' . self::mid(), self::window( $since, $until ), 100 );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$groups = array();
		foreach ( $items as $item ) {
			$no = self::val( $item, array( 'orderNumber', 'OrderNumber' ) );
			if ( '' === $no ) {
				Eru_MPO_Logger::warning( __( 'Sipariş numarası olmayan Hepsiburada satırı atlandı.', 'marketplace-orders-for-woocommerce' ), self::KEY );
				continue;
			}
			// Offset paging can return a row twice when rows shift between pages: one line per line id.
			$lid = self::val( $item, array( 'id', 'lineItemId' ) );
			$key = '' !== $lid ? 'l' . $lid : 'n' . count( $groups[ $no ] ?? array() );
			if ( ! isset( $groups[ $no ][ $key ] ) ) {
				$groups[ $no ][ $key ] = $item;
			}
		}
		$out = array();
		foreach ( $groups as $no => $lines ) {
			$out[] = $this->group_to_dto( (string) $no, array_values( $lines ) );
		}
		return $out;
	}

	/** @param array $item Line. @param string $key merchantDiscount | hbDiscount. @param int $qty Quantity. @return float Line total of that discount. */
	private static function discount( array $item, $key, $qty ) {
		$node = is_array( $item[ $key ] ?? null ) ? $item[ $key ] : array();
		$t    = self::num( $node['totalPrice']['amount'] ?? 0 );
		if ( $t <= 0 ) {
			$t = self::num( $node['unitPrice']['amount'] ?? 0 ) * max( 1, (int) $qty );
		}
		return round( max( 0.0, $t ), 2 );
	}

	/** @param array $a Hepsiburada address. @param string $fallback_name Name when the address has none. @return array Address DTO values. */
	private static function address( array $a, $fallback_name ) {
		$full  = trim( (string) ( $a['name'] ?? '' ) );
		$full  = '' !== $full ? $full : trim( (string) $fallback_name );
		$parts = '' !== $full ? preg_split( '/\s+/', $full ) : array();
		$last  = count( $parts ) > 1 ? (string) array_pop( $parts ) : '';
		return array(
			'first_name'   => implode( ' ', $parts ),
			'last_name'    => $last,
			'address_1'    => self::val( $a, array( 'addressDetail', 'address' ) ),
			'neighborhood' => (string) ( $a['district'] ?? '' ),
			'district'     => (string) ( $a['town'] ?? '' ),
			'city'         => (string) ( $a['city'] ?? '' ),
			'postcode'     => (string) ( $a['postalCode'] ?? '' ),
			'country'      => (string) ( $a['countryCode'] ?? 'TR' ),
			'phone'        => (string) ( $a['phoneNumber'] ?? '' ),
		);
	}

	/**
	 * The lines of one order number as one order DTO.
	 *
	 * @param string  $number Order number.
	 * @param array[] $items  Lines.
	 * @return Eru_MPO_Order_DTO
	 */
	public function group_to_dto( $number, array $items ) {
		$first = (array) reset( $items );
		$lines = array();
		$total = 0;
		foreach ( $items as $item ) {
			$qty  = max( 1, (int) ( $item['quantity'] ?? 1 ) );
			$unit = self::num( $item['unitPrice']['amount'] ?? 0 );
			$sum  = self::num( $item['totalPrice']['amount'] ?? 0 );
			if ( $sum <= 0 ) {
				$sum = $unit * $qty;
			}
			if ( $unit <= 0 ) {
				$unit = $sum / $qty;
			}
			$md   = self::discount( $item, 'merchantDiscount', $qty );
			$hd   = self::discount( $item, 'hbDiscount', $qty );
			$disc = min( round( $unit * $qty, 2 ), round( $md + $hd, 2 ) );
			$com  = self::num( $item['commission']['amount'] ?? 0 );
			$rate = null;
			foreach ( array( $item['commission']['rate'] ?? null, $item['commissionRate'] ?? null ) as $r ) {
				if ( null === $rate && is_numeric( $r ) && (float) $r > 0 ) {
					$rate = (float) $r;
				}
			}
			$base = round( $unit * $qty - $md, 2 );
			if ( null === $rate && $com > 0 && $base > 0 ) {
				$rate = round( $com / $base * 100, 2 );
			}
			$meta = array( 'hb_sku' => (string) ( $item['sku'] ?? '' ) );
			if ( $com > 0 ) {
				$meta['commission_amount'] = wc_format_decimal( $com, 2 );
			}
			if ( $md > 0 ) {
				$meta['hb_merchant_discount'] = wc_format_decimal( $md, 2 );
			}
			if ( $hd > 0 ) {
				$meta['hb_marketplace_discount'] = wc_format_decimal( $hd, 2 );
			}
			$custom = array();
			foreach ( $item as $k => $v ) {
				if ( is_string( $k ) && 0 === stripos( $k, 'customizedText' ) && is_scalar( $v ) && '' !== trim( (string) $v ) ) {
					$custom[] = trim( (string) $v );
				}
			}
			if ( $custom ) {
				$meta['customized'] = implode( ' | ', $custom );
			}
			$lines[] = array(
				'remote_line_id'  => self::val( $item, array( 'id', 'lineItemId' ) ),
				'sku'             => self::val( $item, array( 'merchantSKU', 'merchantSku', 'merchantsku' ) ),
				'barcode'         => (string) ( $item['barcode'] ?? '' ),
				'name'            => (string) ( $item['name'] ?? $item['productName'] ?? '' ),
				'quantity'        => $qty,
				'unit_price'      => $unit,
				'discount'        => $disc,
				'vat_rate'        => is_numeric( $item['vatRate'] ?? null ) ? (float) $item['vatRate'] : null,
				'commission_rate' => $rate,
				'meta'            => array_filter( $meta, 'strlen' ),
			);
			$total += (int) round( $unit * $qty * 100 ) - (int) round( $disc * 100 );
		}
		$ship    = is_array( $first['shippingAddress'] ?? null ) ? $first['shippingAddress'] : array();
		$invoice = is_array( $first['invoice'] ?? null ) ? $first['invoice'] : array();
		$iadr    = is_array( $invoice['address'] ?? null ) ? $invoice['address'] : array();
		$name    = (string) ( $first['customerName'] ?? '' );
		$cust    = self::address( $ship, $name );
		$date    = 0;
		if ( ! empty( $first['orderDate'] ) && is_string( $first['orderDate'] ) ) {
			try {
				$date = ( new DateTimeImmutable( $first['orderDate'], new DateTimeZone( self::TZ ) ) )->getTimestamp();
			} catch ( Exception $e ) {
				$date = 0;
			}
		}
		$tax = (string) ( $invoice['taxNumber'] ?? '' );
		return Eru_MPO_Order_DTO::from_array(
			array(
				'remote_ref'      => $number,
				'order_number'    => $number,
				'remote_order_id' => self::val( $first, array( 'orderId', 'OrderId' ) ),
				'status'          => 'new',
				'remote_status'   => (string) ( $first['status'] ?? '' ),
				'ordered_at'      => $date,
				'currency'        => (string) ( $first['totalPrice']['currency'] ?? 'TRY' ),
				'customer'        => array(
					'first_name'      => $cust['first_name'],
					'last_name'       => $cust['last_name'],
					'email'           => self::val( array_merge( $iadr, $ship ), array( 'email' ) ),
					'phone'           => $cust['phone'],
					'identity_number' => (string) ( $invoice['turkishIdentityNumber'] ?? '' ),
					'tax_number'      => $tax,
					'tax_office'      => (string) ( $invoice['taxOffice'] ?? '' ),
					'company'         => '' !== $tax ? (string) ( $iadr['name'] ?? '' ) : '',
				),
				'billing'         => self::address( $iadr ? $iadr : $ship, $name ),
				'shipping'        => $cust,
				'lines'           => $lines,
				'totals'          => array( 'total' => $total / 100 ),
				'cargo_provider'  => self::val( is_array( $first['cargoCompanyModel'] ?? null ) ? $first['cargoCompanyModel'] : array(), array( 'name' ) ) ?: self::val( $first, array( 'cargoCompany' ) ),
				'meta'            => array_filter(
					array(
						'hb_due_date'      => (string) ( $first['dueDate'] ?? '' ),
						'hb_delivery_type' => (string) ( $first['deliveryType'] ?? '' ),
					),
					'strlen'
				),
				'raw'             => array( 'items' => $items ),
			)
		);
	}

	/**
	 * Lifecycle updates of known orders, merged per order number.
	 *
	 * @param int $since UTC.
	 * @param int $until UTC.
	 * @return Eru_MPO_Order_DTO[]|WP_Error
	 */
	public function fetch_lifecycle( $since, $until ) {
		if ( ! $this->is_configured() ) {
			return $this->not_configured();
		}
		$this->open = null; // Open packages are read fresh for every lifecycle run.
		$until      = (int) $until;
		$since      = max( (int) $since, $until - 30 * DAY_IN_SECONDS );
		$win   = self::window( $since, $until );
		$specs = array();
		$add   = function ( $no, array $spec ) use ( &$specs ) {
			$cur = $specs[ $no ] ?? array(
				'status'        => '',
				'remote_status' => '',
				'lines'         => array(),
				'meta'          => array(),
			);
			if ( '' !== ( $spec['status'] ?? '' ) && Eru_MPO_Statuses::rank( $spec['status'] ) > Eru_MPO_Statuses::rank( $cur['status'] ) ) {
				$cur['status']        = $spec['status'];
				$cur['remote_status'] = (string) ( $spec['remote_status'] ?? '' );
			}
			foreach ( array( 'cargo_provider', 'tracking_number', 'tracking_url' ) as $k ) {
				if ( '' === (string) ( $cur[ $k ] ?? '' ) && '' !== (string) ( $spec[ $k ] ?? '' ) ) {
					$cur[ $k ] = (string) $spec[ $k ];
				}
			}
			$cur['lines']  = array_merge( $cur['lines'], (array) ( $spec['lines'] ?? array() ) );
			$cur['meta']   = array_merge( $cur['meta'], array_filter( (array) ( $spec['meta'] ?? array() ), 'strlen' ) );
			$specs[ $no ] = $cur;
		};

		$delivered = array();
		foreach ( array(
			'delivered'   => array( 'delivered', 'Delivered' ),
			'shipped'     => array( 'shipped', 'Shipped' ),
			'undelivered' => array( 'shipped', 'UnDelivered' ), // A failed attempt: still on its way (not terminal).
		) as $path => $st ) {
			$rows = $this->endpoint( $path, '/packages/merchantid/' . self::mid() . '/' . $path, $win );
			foreach ( $rows as $row ) {
				$no = self::val( $row, array( 'orderNumber', 'OrderNumber' ) );
				if ( '' === $no || ( 'undelivered' === $path && isset( $delivered[ $no ] ) ) ) {
					continue;
				}
				if ( 'delivered' === $path ) {
					$delivered[ $no ] = true;
				}
				$add( $no, $this->package_spec( $row, $st[0], $st[1] ) );
			}
		}

		$cancelled = $this->endpoint( 'cancelled', '/orders/merchantid/' . self::mid() . '/cancelled', $win );
		$by_order = array();
		foreach ( $cancelled as $c ) {
			$no  = self::val( $c, array( 'orderNumber', 'OrderNumber' ) );
			$lid = self::val( $c, array( 'lineItemId', 'LineItemId', 'id' ) );
			if ( '' !== $no && '' !== $lid ) {
				$by_order[ $no ][ $lid ] = max( 1, (int) ( $c['quantity'] ?? 1 ) );
			}
		}
		foreach ( $by_order as $no => $lids ) {
			$add( (string) $no, $this->cancel_spec( (string) $no, $lids ) );
		}

		foreach ( $this->cargo_recovery( $since ) as $no => $spec ) {
			$add( (string) $no, $spec );
		}

		$out = array();
		foreach ( $specs as $no => $s ) {
			$out[] = Eru_MPO_Order_DTO::from_array(
				array(
					'remote_ref'      => (string) $no,
					'order_number'    => (string) $no,
					'status'          => '' !== $s['status'] ? $s['status'] : 'new',
					'remote_status'   => $s['remote_status'],
					'cargo_provider'  => (string) ( $s['cargo_provider'] ?? '' ),
					'tracking_number' => (string) ( $s['tracking_number'] ?? '' ),
					'tracking_url'    => (string) ( $s['tracking_url'] ?? '' ),
					'lines'           => $s['lines'],
					'meta'            => $s['meta'],
				)
			);
		}
		return $out;
	}

	/**
	 * Rows of one lifecycle endpoint. A failure is that endpoint's own: it is logged, backs off on its own (1, 2, 4 …
	 * minutes, at most 30; a longer Retry-After is honoured up to that), and the other endpoints are still applied.
	 * Inside its backoff the endpoint is not asked. A 404 means "nothing in this window".
	 *
	 * @param string $name  Endpoint name (delivered, shipped, undelivered, cancelled).
	 * @param string $path  Path.
	 * @param array  $query Window.
	 * @return array[] Rows (empty when failed or backing off).
	 */
	private function endpoint( $name, $path, array $query ) {
		$all = get_option( self::ENDPOINTS, array() );
		$all = is_array( $all ) ? $all : array();
		$st  = isset( $all[ $name ] ) && is_array( $all[ $name ] ) ? $all[ $name ] : array();
		if ( ! empty( $st['until'] ) && (int) $st['until'] > time() ) {
			return array();
		}
		$rows = $this->paged( $path, $query, 50 );
		if ( is_wp_error( $rows ) && 404 !== Eru_MPO_Http::status( $rows ) ) {
			$n     = (int) ( $st['fails'] ?? 0 ) + 1;
			$data  = $rows->get_error_data();
			$delay = (int) min( 1800, max( MINUTE_IN_SECONDS * ( 2 ** min( 10, $n - 1 ) ), (int) ( is_array( $data ) ? ( $data['retry_after'] ?? 0 ) : 0 ) ) );
			$all[ $name ] = array(
				'fails' => $n,
				'until' => time() + $delay,
			);
			self::save_endpoints( $all );
			/* translators: 1: endpoint, 2: error, 3: minutes */
			Eru_MPO_Logger::warning( sprintf( __( 'Hepsiburada /%1$s ucu başarısız (%2$s); diğer uçlar uygulandı, bu uç %3$d dakika sonra yeniden denenecek.', 'marketplace-orders-for-woocommerce' ), $name, $rows->get_error_message(), (int) ceil( $delay / MINUTE_IN_SECONDS ) ), self::KEY );
			return array();
		}
		if ( isset( $all[ $name ] ) ) {
			unset( $all[ $name ] );
			self::save_endpoints( $all );
		}
		return is_wp_error( $rows ) ? array() : $rows;
	}

	/** Stores the endpoint backoff (autoload off). @param array $all State. */
	private static function save_endpoints( array $all ) {
		if ( false === get_option( self::ENDPOINTS, false ) ) {
			add_option( self::ENDPOINTS, $all, '', false );
		} else {
			update_option( self::ENDPOINTS, $all, false );
		}
	}

	/** @return array Spec from a package row (status endpoints and package detail). */
	private function package_spec( array $row, $status, $remote_status ) {
		return array(
			'status'          => $status,
			'remote_status'   => $remote_status,
			'cargo_provider'  => self::val( $row, array( 'cargoCompany', 'CargoCompany' ) ),
			'tracking_number' => self::val( $row, array( 'trackingInfoCode', 'TrackingInfoCode' ) ),
			'tracking_url'    => self::val( $row, array( 'trackingInfoUrl', 'TrackingInfoUrl' ) ),
			'meta'            => array(
				'hb_package_number' => self::val( $row, array( 'packageNumber', 'PackageNumber' ) ),
				'hb_cargo_barcode'  => self::val( $row, array( 'barcode', 'Barcode' ) ),
			),
		);
	}

	/**
	 * Cancelled lines of an order: the whole order when every active line of the known order is cancelled.
	 *
	 * @param string          $no   Order number.
	 * @param array<string,int> $lids Line id => cancelled quantity.
	 * @return array Spec.
	 */
	private function cancel_spec( $no, array $lids ) {
		$order  = wc_get_order( Eru_MPO_Importer::find_order_by_ref( self::KEY, $no ) );
		$active = array();
		if ( $order ) {
			foreach ( $order->get_items() as $item ) {
				$lid = (string) $item->get_meta( '_eru_mpo_remote_line_id' );
				if ( '' !== $lid && '' === (string) $item->get_meta( '_eru_mpo_line_status' ) ) {
					$active[] = $lid;
				}
			}
		}
		if ( $active && ! array_diff( $active, array_map( 'strval', array_keys( $lids ) ) ) ) {
			return array(
				'status'        => 'cancelled',
				'remote_status' => 'Cancelled',
			);
		}
		$lines = array();
		foreach ( $lids as $lid => $qty ) {
			$lines[] = array(
				'remote_line_id' => (string) $lid,
				'quantity'       => (int) $qty,
				'status'         => 'cancelled',
			);
		}
		return array( 'lines' => $lines );
	}

	/**
	 * Open packages (packed, not shipped yet), read once per request unless $fresh.
	 *
	 * @param bool $fresh Read again.
	 * @return array[]|WP_Error
	 */
	private function open_packages( $fresh = false, $deadline = 0 ) {
		if ( null !== $this->open && ! $fresh ) {
			return $this->open;
		}
		$hours = min( 744, max( 24, Eru_MPO_Settings::days( 'lifecycle_days' ) * 24 ) );
		$rows  = $this->paged( '/packages/merchantid/' . self::mid(), array( 'timespan' => $hours ), 10, $deadline, $deadline ? 20 : self::MAX_PAGES );
		if ( is_wp_error( $rows ) ) {
			if ( 404 !== Eru_MPO_Http::status( $rows ) ) {
				return $rows;
			}
			$rows = array();
		}
		$this->open = $rows;
		return $rows;
	}

	/**
	 * Known orders without a tracking number, re-polled for their cargo details.
	 *
	 * @param int $since Window start (UTC).
	 * @return array<string,array> Order number => spec.
	 */
	private function cargo_recovery( $since ) {
		global $wpdb;
		$budget = max( 0, min( 100, (int) apply_filters( 'eru_mpo_hepsiburada_cargo_budget', 20 ) ) );
		if ( $budget < 1 ) {
			return array();
		}
		$from  = gmdate( 'Y-m-d H:i:s', (int) $since );
		$quiet = gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS );
		if ( Eru_MPO_Importer::hpos() ) {
			$sql = "SELECT o.id FROM {$wpdb->prefix}wc_orders o
				JOIN {$wpdb->prefix}wc_orders_meta k ON k.order_id = o.id AND k.meta_key = '_eru_mpo_marketplace' AND k.meta_value = %s
				JOIN {$wpdb->prefix}wc_orders_meta s ON s.order_id = o.id AND s.meta_key = '_eru_mpo_status' AND s.meta_value IN ('new','accepted','ready','shipped')
				LEFT JOIN {$wpdb->prefix}wc_orders_meta t ON t.order_id = o.id AND t.meta_key = '_eru_mpo_cargo_tracking'
				LEFT JOIN {$wpdb->prefix}wc_orders_meta a ON a.order_id = o.id AND a.meta_key = '_eru_mpo_hb_track_at'
				WHERE o.type = 'shop_order' AND o.status <> 'trash' AND ( t.meta_value IS NULL OR t.meta_value = '' )
				AND o.date_created_gmt >= %s AND ( a.meta_value IS NULL OR a.meta_value < %s ) ORDER BY o.id DESC LIMIT %d";
		} else {
			$sql = "SELECT o.ID FROM {$wpdb->posts} o
				JOIN {$wpdb->postmeta} k ON k.post_id = o.ID AND k.meta_key = '_eru_mpo_marketplace' AND k.meta_value = %s
				JOIN {$wpdb->postmeta} s ON s.post_id = o.ID AND s.meta_key = '_eru_mpo_status' AND s.meta_value IN ('new','accepted','ready','shipped')
				LEFT JOIN {$wpdb->postmeta} t ON t.post_id = o.ID AND t.meta_key = '_eru_mpo_cargo_tracking'
				LEFT JOIN {$wpdb->postmeta} a ON a.post_id = o.ID AND a.meta_key = '_eru_mpo_hb_track_at'
				WHERE o.post_type = 'shop_order' AND o.post_status <> 'trash' AND ( t.meta_value IS NULL OR t.meta_value = '' )
				AND o.post_date_gmt >= %s AND ( a.meta_value IS NULL OR a.meta_value < %s ) ORDER BY o.ID DESC LIMIT %d";
		}
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, self::KEY, $from, $quiet, $budget ) ) ); // phpcs:ignore
		$out = array();
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			$no    = $order ? (string) $order->get_meta( '_eru_mpo_order_number' ) : '';
			if ( '' === $no ) {
				continue;
			}
			$spec     = array( 'meta' => array( 'hb_track_at' => $now ) );
			$packages = $order->get_meta( self::PACKAGES );
			$number   = is_array( $packages ) && $packages ? (string) reset( $packages ) : (string) $order->get_meta( '_eru_mpo_hb_package_number' );
			if ( '' === $number ) {
				$open = $this->open_packages();
				if ( is_wp_error( $open ) ) {
					break;
				}
				foreach ( $open as $row ) {
					if ( self::val( $row, array( 'orderNumber', 'OrderNumber' ) ) === $no ) {
						$number = self::val( $row, array( 'packageNumber', 'PackageNumber' ) );
						$spec   = array_merge_recursive( $spec, array_diff_key( $this->package_spec( $row, '', '' ), array( 'status' => 1, 'remote_status' => 1 ) ) );
						break;
					}
				}
			}
			if ( '' !== $number ) {
				$res = $this->call( 'GET', '/packages/merchantid/' . self::mid() . '/packagenumber/' . rawurlencode( $number ), array(), null, $no );
				if ( $res['ok'] && is_array( $res['data'] ) ) {
					$row  = isset( $res['data'][0] ) && is_array( $res['data'][0] ) ? $res['data'][0] : $res['data'];
					$key  = strtolower( (string) preg_replace( '/[^a-z]/i', '', self::val( $row, array( 'status', 'Status' ) ) ) );
					$st   = self::PACKAGE_STATUS[ $key ] ?? '';
					$spec = array_merge(
						$spec,
						$this->package_spec( $row, $st, '' !== $st ? self::val( $row, array( 'status', 'Status' ) ) : '' )
					);
					$spec['meta']['hb_package_number'] = $number;
					$spec['meta']['hb_track_at']       = $now;
				} elseif ( ! $res['ok'] && $res['retryable'] ) {
					break; // Rate limit or outage: stop asking in this run.
				}
			}
			$out[ $no ] = $spec;
		}
		return $out;
	}

	/**
	 * Packs the order's remaining lines (Hepsiburada's acknowledgement). Progress meta is set on the order; the
	 * caller saves it.
	 *
	 * @param WC_Order $order Imported order.
	 * @return true|WP_Error
	 */
	public function push_acknowledge( $order ) {
		if ( ! $this->is_configured() ) {
			return $this->not_configured();
		}
		$no       = (string) $order->get_meta( '_eru_mpo_order_number' );
		$packed   = array_map( 'strval', (array) ( $order->get_meta( self::PACKED ) ?: array() ) );
		$skipped  = array_map( 'strval', (array) ( $order->get_meta( self::SKIPPED ) ?: array() ) );
		$packages = array_map( 'strval', (array) ( $order->get_meta( self::PACKAGES ) ?: array() ) );
		$left     = array();
		foreach ( $order->get_items() as $item ) {
			$lid = (string) $item->get_meta( '_eru_mpo_remote_line_id' );
			if ( '' === $lid || in_array( $lid, $packed, true ) || in_array( $lid, $skipped, true ) || '' !== (string) $item->get_meta( '_eru_mpo_line_status' ) ) {
				continue;
			}
			$left[ $lid ] = max( 1, (int) $item->get_quantity() );
		}
		if ( ! $left ) {
			return $packed ? true : new WP_Error( 'eru_mpo_pack_no_lines', __( 'Siparişte paketlenecek Hepsiburada satırı yok.', 'marketplace-orders-for-woocommerce' ), array( 'retryable' => false ) );
		}
		$save = function () use ( $order, &$packed, &$skipped, &$packages ) {
			$order->update_meta_data( self::PACKED, array_values( array_unique( $packed ) ) );
			$order->update_meta_data( self::SKIPPED, array_values( array_unique( $skipped ) ) );
			$order->update_meta_data( self::PACKAGES, array_values( array_unique( $packages ) ) );
		};

		$deadline = time() + self::PACK_BUDGET;
		if ( 'yes' === (string) $order->get_meta( self::CHECK_META ) ) {
			// The last attempt may have packed: look for an open package of this order before packing again. Only
			// lines an open package shows are taken as packed; a package of the order that does not show our lines
			// keeps the attempt ambiguous (no new POST) and flags the order for review.
			$open = $this->open_packages( true, $deadline );
			if ( is_wp_error( $open ) ) {
				return $open;
			}
			$hit   = array();
			$other = false;
			foreach ( $open as $row ) {
				if ( self::val( $row, array( 'orderNumber', 'OrderNumber' ) ) !== $no ) {
					continue;
				}
				$ids = array();
				foreach ( array( 'lineItems', 'items', 'lines' ) as $k ) {
					foreach ( is_array( $row[ $k ] ?? null ) ? $row[ $k ] : array() as $l ) {
						$ids[] = is_array( $l ) ? self::val( $l, array( 'lineItemId', 'LineItemId', 'id' ) ) : ( is_scalar( $l ) ? (string) $l : '' );
					}
				}
				$mine = array_values( array_intersect( array_map( 'strval', array_keys( $left ) ), array_filter( $ids, 'strlen' ) ) );
				if ( $mine ) {
					$hit        = array_merge( $hit, $mine );
					$packages[] = self::val( $row, array( 'packageNumber', 'PackageNumber' ) );
				} else {
					$other = true;
				}
			}
			if ( $hit ) {
				$packages = array_filter( $packages, 'strlen' );
				foreach ( $hit as $lid ) {
					$packed[] = $lid;
					unset( $left[ $lid ] );
				}
				$order->delete_meta_data( self::CHECK_META );
				$save();
				if ( ! $left ) {
					return true;
				}
			} elseif ( $other ) {
				$order->update_meta_data( '_eru_mpo_review', 'yes' );
				Eru_MPO_Map::set_review( self::KEY, (string) $order->get_meta( '_eru_mpo_remote_ref' ), true );
				if ( 'yes' !== (string) $order->get_meta( '_eru_mpo_hb_pack_noted' ) ) {
					$order->update_meta_data( '_eru_mpo_hb_pack_noted', 'yes' );
					$order->add_order_note( __( 'Hepsiburada\'da bu siparişin açık bir paketi var ama satırları eşleşmedi; yeniden paketlenmedi. Hepsiburada panelinden kontrol edin.', 'marketplace-orders-for-woocommerce' ) );
				}
				$save();
				return new WP_Error( 'eru_mpo_pack_ambiguous', __( 'Önceki paketleme denemesinin sonucu belirsiz; açık paketin satırları eşleşmedi.', 'marketplace-orders-for-woocommerce' ), array( 'retryable' => true ) );
			} else {
				$order->delete_meta_data( self::CHECK_META ); // No package of the order: the last attempt did not pack.
			}
		}

		$mid   = self::mid();
		$loops = 0;
		while ( $left && $loops < 10 ) {
			++$loops;
			if ( time() > $deadline ) {
				break; // Time budget spent: the rest goes to the next attempt (partial below).
			}
			$first = (string) array_key_first( $left );
			$group = array( $first => $left[ $first ] );
			if ( count( $left ) > 1 ) {
				$res = $this->call( 'GET', '/lineitems/merchantid/' . $mid . '/packageablewith/lineitemid/' . rawurlencode( $first ), array(), null, $no );
				if ( $res['ok'] && is_array( $res['data'] ) ) {
					$rows = isset( $res['data']['lineItems'] ) && is_array( $res['data']['lineItems'] ) ? $res['data']['lineItems'] : ( self::is_list( $res['data'] ) ? $res['data'] : array() );
					foreach ( $rows as $r ) {
						$lid = is_array( $r ) ? self::val( $r, array( 'lineItemId', 'LineItemId', 'id' ) ) : '';
						if ( '' !== $lid && isset( $left[ $lid ] ) ) {
							$q             = (int) ( $r['quantity'] ?? 0 );
							$group[ $lid ] = $q > 0 ? min( $left[ $lid ], $q ) : $left[ $lid ];
						}
					}
				} elseif ( ! $res['ok'] && 404 !== $res['code'] ) {
					$save();
					return Eru_MPO_Http::to_error( $res );
				}
			}
			$req = array();
			foreach ( $group as $lid => $q ) {
				$req[] = array(
					'id'       => (string) $lid,
					'quantity' => (int) $q,
				);
			}
			// Stored before sending: if the run dies after the request left, the next attempt looks before packing.
			$save();
			$order->update_meta_data( self::CHECK_META, 'yes' );
			$order->save_meta_data();
			$res = $this->call( 'POST', '/packages/merchantid/' . $mid, array(), array( 'lineItemRequests' => $req ), $no );
			if ( ! $res['ambiguous'] ) {
				$order->delete_meta_data( self::CHECK_META ); // A clear answer.
			}
			if ( $res['ok'] ) {
				$pn = is_array( $res['data'] ) ? self::val( $res['data'], array( 'packageNumber', 'PackageNumber' ) ) : '';
				if ( '' === $pn && is_array( $res['data'] ) ) {
					foreach ( $res['data'] as $v ) {
						if ( '' === $pn && is_array( $v ) ) {
							$pn = self::val( $v, array( 'packageNumber', 'PackageNumber' ) );
						}
					}
				}
				if ( '' !== $pn ) {
					$packages[] = $pn;
				}
				foreach ( array_keys( $group ) as $lid ) {
					$packed[] = (string) $lid;
					unset( $left[ $lid ] );
				}
				continue;
			}
			if ( 409 === $res['code'] ) {
				// Not packable any more (cancelled or already packed elsewhere): leave these lines to the panel.
				foreach ( array_keys( $group ) as $lid ) {
					$skipped[] = (string) $lid;
					unset( $left[ $lid ] );
				}
				/* translators: %s: line ids */
				$order->add_order_note( sprintf( __( 'Hepsiburada bu satırları paketlemedi (iptal edilmiş ya da paketlenemez): %s. Hepsiburada panelinden kontrol edin.', 'marketplace-orders-for-woocommerce' ), implode( ', ', array_keys( $group ) ) ) );
				continue;
			}
			$save();
			return Eru_MPO_Http::to_error( $res );
		}
		$save();
		if ( $left ) {
			return new WP_Error( 'eru_mpo_pack_partial', __( 'Kalan satırlar bir sonraki denemede paketlenecek.', 'marketplace-orders-for-woocommerce' ), array( 'retryable' => true ) );
		}
		return $packed ? true : new WP_Error( 'eru_mpo_pack_skipped', __( 'Hiçbir satır paketlenemedi; Hepsiburada panelinden kontrol edin.', 'marketplace-orders-for-woocommerce' ), array( 'retryable' => false ) );
	}

	/** @return string Listing API base URL without a trailing slash ('' = not set: the stock bridge sends nothing). */
	public static function listing_url() {
		$url = (string) self::settings()['listing_base'];
		return untrailingslashit( (string) apply_filters( 'eru_mpo_hepsiburada_listing_url', $url, self::environment() ) );
	}

	/** @return bool The stock bridge can run: OMS credentials plus the listing API address. */
	public function bridge_ready() {
		return $this->is_configured() && '' !== self::listing_url();
	}

	/** @return WP_Error The listing API address or the credentials are missing (nothing was sent). */
	private function listing_not_configured() {
		return new WP_Error( 'eru_mpo_not_configured', __( 'Hepsiburada listeleme API adresi veya API bilgileri eksik; stok ve fiyat gönderilmedi.', 'marketplace-orders-for-woocommerce' ), array( 'retryable' => false ) );
	}

	/**
	 * One listing API call (same credentials and headers as the OMS calls, another host).
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path from the listing base URL.
	 * @param array      $query  Query.
	 * @param array|null $body   JSON body.
	 * @return array Eru_MPO_Http result.
	 */
	private function listing_call( $method, $path, array $query = array(), $body = null ) {
		$c   = self::credentials();
		$url = self::listing_url() . $path;
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url );
		}
		$auth = Eru_MPO_Http::basic( $c['merchant_id'], $c['password'] );
		return Eru_MPO_Http::request(
			self::KEY,
			$method,
			$url,
			array(
				'Authorization' => $auth,
				'User-Agent'    => $c['dev_username'],
			),
			$body,
			array( 'secrets' => array( $c['password'], substr( $auth, 6 ) ) )
		);
	}

	/**
	 * One page of listings (GET /listings/merchantid/{id}?offset=&limit=): merchantSku → wc_sku, hepsiburadaSku →
	 * remote_sku and remote_id, isSalable, availableStock, price.
	 *
	 * @param int $page Page, 0-based.
	 * @return array{items: Eru_MPO_Listing_DTO[], more: bool}|WP_Error
	 */
	public function fetch_listings( $page ) {
		if ( ! $this->is_configured() || '' === self::listing_url() ) {
			return $this->listing_not_configured();
		}
		$limit = max( 1, min( self::LISTING_PAGE, (int) apply_filters( 'eru_mpo_hepsiburada_page_limit', self::LISTING_PAGE, 'listings' ) ) );
		$page  = max( 0, (int) $page );
		$res   = $this->listing_call(
			'GET',
			'/listings/merchantid/' . self::mid(),
			array(
				'offset' => $page * $limit,
				'limit'  => $limit,
			)
		);
		if ( ! $res['ok'] ) {
			return Eru_MPO_Http::to_error( $res );
		}
		$data = $res['data'];
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'eru_mpo_bad_json', __( 'Hepsiburada okunamayan bir yanıt döndü.', 'marketplace-orders-for-woocommerce' ), array( 'retryable' => true ) );
		}
		$rows  = isset( $data['listings'] ) && is_array( $data['listings'] ) ? $data['listings'] : ( isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : ( self::is_list( $data ) ? $data : array() ) );
		$items = array();
		foreach ( $rows as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$sku = self::val( $l, array( 'merchantSku', 'merchantSKU', 'MerchantSku' ) );
			if ( '' === $sku ) {
				continue;
			}
			$hb      = self::val( $l, array( 'hepsiburadaSku', 'HepsiburadaSku' ) );
			$items[] = Eru_MPO_Listing_DTO::from_array(
				array(
					'wc_sku'     => $sku,
					'remote_sku' => $hb,
					'remote_id'  => $hb,
					'salable'    => ! isset( $l['isSalable'] ) || (bool) $l['isSalable'],
					'stock'      => $l['availableStock'] ?? null,
					'price'      => $l['price'] ?? null,
				)
			);
		}
		$total = isset( $data['totalCount'] ) && is_numeric( $data['totalCount'] ) ? (int) $data['totalCount'] : null;
		return array(
			'items' => $items,
			'more'  => null === $total ? count( $rows ) === $limit : ( $page + 1 ) * $limit < $total,
		);
	}

	/**
	 * Stock: POST /listings/merchantid/{id}/stock-uploads with [{merchantSku, availableStock}].
	 *
	 * @param array $items List of {sku, barcode, quantity}.
	 * @return array{ok: int, failed: array<string,string>, batch: string}|WP_Error
	 */
	public function push_inventory( array $items ) {
		return $this->upload( $items, 'stock' );
	}

	/**
	 * Price: POST /listings/merchantid/{id}/price-uploads with [{merchantSku, price}].
	 *
	 * @param array $items List of {sku, barcode, price, list_price}.
	 * @return array{ok: int, failed: array<string,string>, batch: string}|WP_Error
	 */
	public function push_price( array $items ) {
		return $this->upload( $items, 'price' );
	}

	/**
	 * Hepsiburada matches uploads by the merchant SKU. Values are absolute, so a retry cannot apply a change twice.
	 * The answer is an upload id (processed later by Hepsiburada); it is returned for the log.
	 *
	 * @param array  $items Items.
	 * @param string $kind  stock | price.
	 * @return array|WP_Error
	 */
	private function upload( array $items, $kind ) {
		if ( ! $this->is_configured() || '' === self::listing_url() ) {
			return $this->listing_not_configured();
		}
		$send   = array();
		$failed = array();
		foreach ( $items as $i => $it ) {
			$sku = (string) ( $it['sku'] ?? '' );
			if ( '' === $sku ) {
				$failed[ '#' . $i ] = __( 'Ürünün SKU\'su yok; Hepsiburada satıcı SKU\'su ile eşleştirir.', 'marketplace-orders-for-woocommerce' );
				continue;
			}
			$send[] = 'stock' === $kind
				? array(
					'merchantSku'    => $sku,
					'availableStock' => max( 0, (int) ( $it['quantity'] ?? 0 ) ),
				)
				: array(
					'merchantSku' => $sku,
					'price'       => round( (float) ( $it['price'] ?? 0 ), 2 ),
				);
		}
		$ok  = 0;
		$ids = array();
		foreach ( array_chunk( $send, self::UPLOAD_MAX ) as $chunk ) {
			$res = $this->listing_call( 'POST', '/listings/merchantid/' . self::mid() . ( 'stock' === $kind ? '/stock-uploads' : '/price-uploads' ), array(), $chunk );
			if ( ! $res['ok'] ) {
				return Eru_MPO_Http::to_error( $res );
			}
			$ok   += count( $chunk );
			$ids[] = is_array( $res['data'] ) ? sanitize_text_field( (string) ( $res['data']['id'] ?? ( $res['data']['trackingId'] ?? '' ) ) ) : '';
		}
		return array(
			'ok'     => $ok,
			'failed' => $failed,
			'batch'  => implode( ', ', array_filter( $ids ) ),
		);
	}

	/**
	 * Connection test (run by Action Scheduler from the Hepsiburada tab): one order list request for the last day.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return $this->not_configured();
		}
		$res = $this->call( 'GET', '/orders/merchantid/' . self::mid(), array_merge( self::window( time() - DAY_IN_SECONDS, time() ), array( 'offset' => 0, 'limit' => 1 ) ) );
		return $res['ok'] ? true : Eru_MPO_Http::to_error( $res );
	}
}
