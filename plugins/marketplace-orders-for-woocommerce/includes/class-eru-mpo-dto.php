<?php
/**
 * Normalised data transfer objects. Adapters turn marketplace payloads into these; the importer, lifecycle and
 * stock bridge only ever see these shapes.
 *
 * Money is a decimal number in the order currency with VAT included (marketplaces quote consumer prices); the
 * importer converts to minor units (kuruş) before any arithmetic. Times are UTC Unix timestamps.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shared casting helpers. */
abstract class Eru_MPO_DTO {

	/** @param mixed $v Value. @return string Trimmed text (arrays/objects become ''). */
	protected static function str( $v ) {
		return is_scalar( $v ) ? trim( (string) $v ) : '';
	}

	/** @param mixed $v Value. @return float */
	protected static function num( $v ) {
		return is_numeric( $v ) ? (float) $v : 0.0;
	}

	/** @param mixed $v Value. @return float|null */
	protected static function num_or_null( $v ) {
		return is_numeric( $v ) ? (float) $v : null;
	}

	/** @param mixed $v Value. @return array Scalar-only map with keys [a-z0-9_]{1,40}. */
	protected static function meta( $v ) {
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $k => $val ) {
			if ( is_string( $k ) && preg_match( '/^[a-z0-9_]{1,40}$/', $k ) && is_scalar( $val ) ) {
				$out[ $k ] = (string) $val;
			}
		}
		return $out;
	}

	/** @return array Public properties (nested DTOs as arrays). */
	public function to_array() {
		$out = array();
		foreach ( get_object_vars( $this ) as $k => $v ) {
			if ( $v instanceof Eru_MPO_DTO ) {
				$v = $v->to_array();
			} elseif ( is_array( $v ) ) {
				$v = array_map( fn( $x ) => $x instanceof Eru_MPO_DTO ? $x->to_array() : $x, $v );
			}
			$out[ $k ] = $v;
		}
		return $out;
	}
}

/** Buyer. */
class Eru_MPO_Customer_DTO extends Eru_MPO_DTO {
	public string $first_name = '';
	public string $last_name  = '';
	public string $email      = '';
	public string $phone      = '';
	/** Identity number; copied to order meta only when "e-Arşiv için TC kimlik no sakla" is on. */
	public string $identity_number = '';
	public string $tax_number      = '';
	public string $tax_office      = '';
	public string $company         = '';

	/** @param array $a Values. @return self */
	public static function from_array( array $a ) {
		$d = new self();
		foreach ( array( 'first_name', 'last_name', 'email', 'phone', 'identity_number', 'tax_number', 'tax_office', 'company' ) as $k ) {
			$d->$k = self::str( $a[ $k ] ?? '' );
		}
		return $d;
	}
}

/** Postal address (Turkish addresses: city = il, district = ilçe, city_code = plate number 1–81). */
class Eru_MPO_Address_DTO extends Eru_MPO_DTO {
	public string $first_name   = '';
	public string $last_name    = '';
	public string $company      = '';
	public string $address_1    = '';
	public string $address_2    = '';
	public string $neighborhood = '';
	public string $district     = '';
	public string $city         = '';
	public int $city_code       = 0;
	public string $postcode     = '';
	public string $country      = 'TR';
	public string $phone        = '';

	/** @param array $a Values. @return self */
	public static function from_array( array $a ) {
		$d = new self();
		foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'neighborhood', 'district', 'city', 'postcode', 'phone' ) as $k ) {
			$d->$k = self::str( $a[ $k ] ?? '' );
		}
		$d->city_code = is_numeric( $a['city_code'] ?? null ) ? (int) $a['city_code'] : 0;
		$c            = strtoupper( self::str( $a['country'] ?? '' ) );
		$d->country   = preg_match( '/^[A-Z]{2}$/', $c ) ? $c : 'TR';
		return $d;
	}

	/** @return bool Nothing filled in. */
	public function is_empty() {
		return '' === $this->address_1 . $this->city . $this->district . $this->first_name . $this->last_name;
	}
}

/** One order line. */
class Eru_MPO_Line_DTO extends Eru_MPO_DTO {
	public string $remote_line_id = '';
	/** Merchant SKU / stock code as the marketplace knows it. */
	public string $sku     = '';
	public string $barcode = '';
	public string $name    = '';
	public int $quantity   = 1;
	/** Unit consumer price, VAT included. */
	public float $unit_price = 0.0;
	/** Discount on the whole line (all units), VAT included. */
	public float $discount = 0.0;
	/** VAT percent (e.g. 20); null = take the matched product's tax class. */
	public ?float $vat_rate = null;
	/** Marketplace commission percent; null = unknown. */
	public ?float $commission_rate = null;
	/**
	 * active | cancelled | returned. A non-active line on a new order is kept at zero price without stock; a line
	 * that turns "cancelled" on a known order becomes a cancel_lines event "cancel-line-<remote_line_id>" (stock
	 * restored once). Adapters that report cancellations as events should use the same id.
	 */
	public string $status = 'active';
	/** Extra line meta, stored as _eru_mpo_<key>. */
	public array $meta = array();

	/** @param array $a Values. @return self */
	public static function from_array( array $a ) {
		$d = new self();
		foreach ( array( 'remote_line_id', 'sku', 'barcode', 'name' ) as $k ) {
			$d->$k = self::str( $a[ $k ] ?? '' );
		}
		$d->quantity        = max( 1, (int) ( $a['quantity'] ?? 1 ) );
		$d->unit_price      = max( 0.0, self::num( $a['unit_price'] ?? 0 ) );
		$d->discount        = max( 0.0, self::num( $a['discount'] ?? 0 ) );
		$d->vat_rate        = self::num_or_null( $a['vat_rate'] ?? null );
		$d->commission_rate = self::num_or_null( $a['commission_rate'] ?? null );
		$s                  = self::str( $a['status'] ?? 'active' );
		$d->status          = in_array( $s, array( 'active', 'cancelled', 'returned' ), true ) ? $s : 'active';
		$d->meta            = self::meta( $a['meta'] ?? array() );
		return $d;
	}
}

/** Order totals as the marketplace reports them (VAT included). */
class Eru_MPO_Totals_DTO extends Eru_MPO_DTO {
	public float $shipping = 0.0;
	/** Shipping VAT percent (null = no split; shipping is then stored gross). */
	public ?float $shipping_vat_rate = null;
	/** Order-level discount NOT already in the line discounts (VAT included); spread over the active lines. */
	public float $discount = 0.0;
	/** Amount the buyer paid for this package; 0 = use the sum of lines + shipping. */
	public float $total = 0.0;

	/** @param array $a Values. @return self */
	public static function from_array( array $a ) {
		$d           = new self();
		$d->shipping = max( 0.0, self::num( $a['shipping'] ?? 0 ) );
		$d->shipping_vat_rate = self::num_or_null( $a['shipping_vat_rate'] ?? null );
		$d->discount = max( 0.0, self::num( $a['discount'] ?? 0 ) );
		$d->total    = max( 0.0, self::num( $a['total'] ?? 0 ) );
		return $d;
	}
}

/**
 * Lifecycle event. Applied once per order (the id is remembered in _eru_mpo_events).
 *
 * type status:       move to $status (forward only).
 * type cargo:        cargo provider / tracking number / tracking URL.
 * type cancel_lines: some lines (or units) were cancelled: $lines = [{remote_line_id, sku, barcode, quantity}].
 */
class Eru_MPO_Event_DTO extends Eru_MPO_DTO {
	public string $id              = '';
	public string $type            = 'status';
	public string $status          = '';
	public int $at                 = 0;
	public string $cargo_provider  = '';
	public string $tracking_number = '';
	public string $tracking_url    = '';
	public array $lines            = array();
	public string $note            = '';

	/** @param array $a Values. @return self */
	public static function from_array( array $a ) {
		$d = new self();
		foreach ( array( 'id', 'status', 'cargo_provider', 'tracking_number', 'tracking_url', 'note' ) as $k ) {
			$d->$k = self::str( $a[ $k ] ?? '' );
		}
		$t       = self::str( $a['type'] ?? 'status' );
		$d->type = in_array( $t, array( 'status', 'cargo', 'cancel_lines' ), true ) ? $t : 'status';
		$d->at   = max( 0, (int) ( $a['at'] ?? 0 ) );
		foreach ( is_array( $a['lines'] ?? null ) ? $a['lines'] : array() as $l ) {
			if ( is_array( $l ) ) {
				$d->lines[] = array(
					'remote_line_id' => self::str( $l['remote_line_id'] ?? '' ),
					'sku'            => self::str( $l['sku'] ?? '' ),
					'barcode'        => self::str( $l['barcode'] ?? '' ),
					'quantity'       => max( 1, (int) ( $l['quantity'] ?? 1 ) ),
					'index'          => isset( $l['index'] ) && is_numeric( $l['index'] ) ? (int) $l['index'] : -1,
				);
			}
		}
		if ( '' === $d->id ) {
			$d->id = substr( md5( (string) wp_json_encode( array( $d->type, $d->status, $d->at, $d->tracking_number, $d->lines ) ) ), 0, 16 );
		}
		return $d;
	}
}

/**
 * One marketplace order (a package: the unit the marketplace ships and the importer de-duplicates on).
 */
class Eru_MPO_Order_DTO extends Eru_MPO_DTO {
	/** Adapter key; the importer sets it from the adapter. */
	public string $marketplace = '';
	/** De-duplication key, unique per marketplace (Trendyol: shipment package id; Hepsiburada: order number). */
	public string $remote_ref      = '';
	public string $order_number    = '';
	public string $package_id      = '';
	public string $remote_order_id = '';
	/** Logical status: new | accepted | ready | shipped | delivered | cancelled | returned. */
	public string $status        = 'new';
	public string $remote_status = '';
	public int $ordered_at       = 0;
	public string $currency      = 'TRY';
	public Eru_MPO_Customer_DTO $customer;
	public Eru_MPO_Address_DTO $billing;
	public Eru_MPO_Address_DTO $shipping;
	/** @var Eru_MPO_Line_DTO[] */
	public array $lines = array();
	public Eru_MPO_Totals_DTO $totals;
	public string $cargo_provider  = '';
	public string $tracking_number = '';
	public string $tracking_url    = '';
	/** @var Eru_MPO_Event_DTO[] */
	public array $events = array();
	/** Extra order meta, stored as _eru_mpo_<key> (reserved keys ignored). */
	public array $meta = array();
	/** Raw payload; stored only in the raw table (identity numbers masked), never in order meta. */
	public array $raw = array();

	public function __construct() {
		$this->customer = new Eru_MPO_Customer_DTO();
		$this->billing  = new Eru_MPO_Address_DTO();
		$this->shipping = new Eru_MPO_Address_DTO();
		$this->totals   = new Eru_MPO_Totals_DTO();
	}

	/** @param array $a Values (nested arrays for customer, billing, shipping, lines, totals, events). @return self */
	public static function from_array( array $a ) {
		$d = new self();
		foreach ( array( 'marketplace', 'remote_ref', 'order_number', 'package_id', 'remote_order_id', 'remote_status', 'cargo_provider', 'tracking_number', 'tracking_url' ) as $k ) {
			$d->$k = self::str( $a[ $k ] ?? '' );
		}
		$d->status     = Eru_MPO_Statuses::is_key( self::str( $a['status'] ?? '' ) ) ? self::str( $a['status'] ) : 'new';
		$d->ordered_at = max( 0, (int) ( $a['ordered_at'] ?? 0 ) );
		$cur           = strtoupper( self::str( $a['currency'] ?? '' ) );
		$d->currency   = preg_match( '/^[A-Z]{3}$/', $cur ) ? $cur : 'TRY';
		$d->customer   = Eru_MPO_Customer_DTO::from_array( is_array( $a['customer'] ?? null ) ? $a['customer'] : array() );
		$d->billing    = Eru_MPO_Address_DTO::from_array( is_array( $a['billing'] ?? null ) ? $a['billing'] : array() );
		$d->shipping   = Eru_MPO_Address_DTO::from_array( is_array( $a['shipping'] ?? null ) ? $a['shipping'] : array() );
		$d->totals     = Eru_MPO_Totals_DTO::from_array( is_array( $a['totals'] ?? null ) ? $a['totals'] : array() );
		foreach ( is_array( $a['lines'] ?? null ) ? $a['lines'] : array() as $l ) {
			$d->lines[] = $l instanceof Eru_MPO_Line_DTO ? $l : Eru_MPO_Line_DTO::from_array( is_array( $l ) ? $l : array() );
		}
		foreach ( is_array( $a['events'] ?? null ) ? $a['events'] : array() as $e ) {
			$d->events[] = $e instanceof Eru_MPO_Event_DTO ? $e : Eru_MPO_Event_DTO::from_array( is_array( $e ) ? $e : array() );
		}
		$d->meta = self::meta( $a['meta'] ?? array() );
		$d->raw  = is_array( $a['raw'] ?? null ) ? $a['raw'] : array();
		return $d;
	}
}

/** One marketplace listing (remote SKU/barcode/id, salable flag, stock and price), used by the stock bridge. */
class Eru_MPO_Listing_DTO extends Eru_MPO_DTO {
	public string $wc_sku      = '';
	public string $remote_sku  = '';
	public string $barcode     = '';
	public string $remote_id   = '';
	public bool $salable       = true;
	public ?int $stock         = null;
	public ?float $price       = null;

	/** @param array $a Values. @return self */
	public static function from_array( array $a ) {
		$d = new self();
		foreach ( array( 'wc_sku', 'remote_sku', 'barcode', 'remote_id' ) as $k ) {
			$d->$k = self::str( $a[ $k ] ?? '' );
		}
		$d->salable = ! isset( $a['salable'] ) || (bool) $a['salable'];
		$d->stock   = is_numeric( $a['stock'] ?? null ) ? (int) $a['stock'] : null;
		$d->price   = self::num_or_null( $a['price'] ?? null );
		return $d;
	}
}
