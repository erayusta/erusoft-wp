<?php
/**
 * Address fields (classic checkout + My Account), AJAX data endpoint, validation, saving, display.
 *
 * Field keys per group (billing_ / shipping_): <group>_eru_taf_district, _neighborhood, _street, _no_number.
 * WooCommerce stores them as order meta `_<group>_eru_taf_*` and as customer meta.
 *
 * @package turkish-address-fields-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_TAF_Checkout {

	const OTHER = '__other__';

	public static function init(): void {
		// Public read-only data endpoint (works even when the fields are switched off, e.g. for previews).
		add_action( 'wp_ajax_eru_taf_data', array( __CLASS__, 'ajax_data' ) );
		add_action( 'wp_ajax_nopriv_eru_taf_data', array( __CLASS__, 'ajax_data' ) );

		if ( ! Eru_TAF_Settings::on( 'enabled' ) ) {
			return;
		}
		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'billing_fields' ), 20, 2 );
		add_filter( 'woocommerce_shipping_fields', array( __CLASS__, 'shipping_fields' ), 20, 2 );
		add_filter( 'woocommerce_get_country_locale', array( __CLASS__, 'country_locale' ), 20 );
		if ( Eru_TAF_Settings::on( 'lock_country' ) ) {
			add_filter( 'woocommerce_countries_allowed_countries', array( __CLASS__, 'only_tr' ) );
			add_filter( 'woocommerce_countries_shipping_countries', array( __CLASS__, 'only_tr' ) );
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );

		add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'posted_data' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_checkout' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'create_order' ), 10, 2 );
		add_action( 'woocommerce_after_save_address_validation', array( __CLASS__, 'validate_account' ), 10, 4 );

		if ( ! Eru_TAF_Settings::on( 'write_address' ) ) {
			add_filter( 'woocommerce_order_formatted_billing_address', array( __CLASS__, 'merge_billing' ), 10, 2 );
			add_filter( 'woocommerce_order_formatted_shipping_address', array( __CLASS__, 'merge_shipping' ), 10, 2 );
		}
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_parts_billing' ) );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'admin_parts_shipping' ) );
	}

	/* ------------------------------------------------------------------ fields */

	public static function key( string $group, string $level ): string {
		return $group . '_eru_taf_' . $level;
	}

	private static function applies( string $group ): bool {
		if ( ! in_array( $group, Eru_TAF_Settings::groups(), true ) ) {
			return false;
		}
		$account = function_exists( 'is_account_page' ) && is_account_page() && ! ( function_exists( 'is_checkout' ) && is_checkout() );
		return ! $account || Eru_TAF_Settings::on( 'account' );
	}

	public static function billing_fields( $fields, $country = '' ): array {
		return self::add_fields( (array) $fields, 'billing', (string) $country );
	}

	public static function shipping_fields( $fields, $country = '' ): array {
		return self::add_fields( (array) $fields, 'shipping', (string) $country );
	}

	/** Field definitions for one group. $country decides whether the configured required flags apply. */
	public static function add_fields( array $fields, string $group, string $country ): array {
		if ( ! self::applies( $group ) ) {
			return $fields;
		}
		$tr     = '' === $country || 'TR' === strtoupper( $country );
		$labels = array(
			'district'     => __( 'İlçe', 'turkish-address-fields-for-woocommerce' ),
			'neighborhood' => __( 'Mahalle / Köy', 'turkish-address-fields-for-woocommerce' ),
			'street'       => __( 'Cadde / Sokak', 'turkish-address-fields-for-woocommerce' ),
		);
		$waits  = array(
			'district'     => __( 'Önce il seçin', 'turkish-address-fields-for-woocommerce' ),
			'neighborhood' => __( 'Önce ilçe seçin', 'turkish-address-fields-for-woocommerce' ),
			'street'       => __( 'Önce mahalle seçin', 'turkish-address-fields-for-woocommerce' ),
		);
		$prio   = array( 'district' => 45, 'neighborhood' => 46, 'street' => 47 );
		$levels = Eru_TAF_Settings::levels();
		foreach ( $levels as $level ) {
			// Full width: half-width rows wrapped labels like "Cadde / Sokak (isteğe bağlı)" onto two lines.
			$fields[ self::key( $group, $level ) ] = array(
				'type'     => 'select',
				'label'    => $labels[ $level ],
				'required' => $tr && Eru_TAF_Settings::required( $level ),
				'priority' => $prio[ $level ],
				'class'    => array( 'form-row-wide', 'eru-taf-row', 'eru-taf-' . $level ),
				'options'  => array( '' => $waits[ $level ] ),
			);
		}
		$a1 = $group . '_address_1';
		if ( isset( $fields[ $a1 ] ) && in_array( 'street', $levels, true ) ) {
			$fields[ $a1 ]['label']       = __( 'Bina no / Kat / Daire', 'turkish-address-fields-for-woocommerce' );
			$fields[ $a1 ]['placeholder'] = __( 'Örn: No: 12 Kat: 3 Daire: 7', 'turkish-address-fields-for-woocommerce' );
		}
		if ( Eru_TAF_Settings::on( 'door_check' ) ) {
			$fields[ self::key( $group, 'no_number' ) ] = array(
				'type'     => 'checkbox',
				'label'    => __( 'Adresimde bina / kapı numarası yok', 'turkish-address-fields-for-woocommerce' ),
				'required' => false,
				'priority' => 51,
				'class'    => array( 'form-row-wide', 'eru-taf-row', 'eru-taf-no-number' ),
			);
		}
		if ( 'billing' === $group && Eru_TAF_Settings::on( 'phone_check' ) && isset( $fields['billing_phone'] ) ) {
			$fields['billing_phone']['required']          = true;
			$fields['billing_phone']['placeholder']       = '05XX XXX XX XX';
			$fields['billing_phone']['custom_attributes'] = array_merge( (array) ( $fields['billing_phone']['custom_attributes'] ?? array() ), array( 'inputmode' => 'tel' ) );
		}
		return $fields;
	}

	/** TR locale: İl above the cascade, city filled from İlçe (hidden), optional postcode. */
	public static function country_locale( $locale ): array {
		$locale       = (array) $locale;
		$tr           = (array) ( $locale['TR'] ?? array() );
		$tr['state']  = array_merge( (array) ( $tr['state'] ?? array() ), array( 'label' => __( 'İl', 'turkish-address-fields-for-woocommerce' ), 'required' => true, 'priority' => 44 ) );
		$tr['city']   = array_merge( (array) ( $tr['city'] ?? array() ), array( 'required' => false, 'hidden' => true ) );
		if ( in_array( 'street', Eru_TAF_Settings::levels(), true ) ) {
			// The locale script re-applies labels on every country change, so the label lives here too.
			$tr['address_1'] = array_merge(
				(array) ( $tr['address_1'] ?? array() ),
				array(
					'label'       => __( 'Bina no / Kat / Daire', 'turkish-address-fields-for-woocommerce' ),
					'placeholder' => __( 'Örn: No: 12 Kat: 3 Daire: 7', 'turkish-address-fields-for-woocommerce' ),
				)
			);
		}
		if ( Eru_TAF_Settings::on( 'hide_postcode' ) ) {
			$tr['postcode'] = array_merge( (array) ( $tr['postcode'] ?? array() ), array( 'required' => false, 'hidden' => true ) );
		}
		$locale['TR'] = $tr;
		return $locale;
	}

	public static function only_tr( $countries ): array {
		$all = WC()->countries ? WC()->countries->get_countries() : array();
		return array( 'TR' => $all['TR'] ?? 'Türkiye' );
	}

	/* ------------------------------------------------------------------ assets */

	public static function assets(): void {
		$checkout = function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page();
		$account  = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'edit-address' );
		if ( ! $checkout && ! ( $account && Eru_TAF_Settings::on( 'account' ) ) ) {
			return;
		}
		$deps = array( 'jquery' );
		foreach ( array( 'selectWoo', 'select2' ) as $h ) {
			if ( wp_script_is( $h, 'registered' ) ) {
				wp_enqueue_script( $h );
				$deps[] = $h;
				break;
			}
		}
		if ( wp_style_is( 'select2', 'registered' ) ) {
			wp_enqueue_style( 'select2' );
		}
		wp_enqueue_style( 'eru-taf', ERU_TAF_URL . 'assets/taf.css', array(), ERU_TAF_VERSION );
		wp_enqueue_script( 'eru-taf', ERU_TAF_URL . 'assets/taf.js', $deps, ERU_TAF_VERSION, true );

		$saved = array();
		foreach ( Eru_TAF_Settings::groups() as $g ) {
			foreach ( Eru_TAF_Settings::levels() as $level ) {
				$k                       = self::key( $g, $level );
				$saved[ $g ][ $level ] = $checkout ? (string) WC()->checkout()->get_value( $k ) : ( is_user_logged_in() ? (string) get_user_meta( get_current_user_id(), $k, true ) : '' );
			}
		}
		wp_localize_script(
			'eru-taf',
			'eruTaf',
			array(
				'ajax'   => admin_url( 'admin-ajax.php' ),
				'v'      => ERU_TAF_VERSION,
				'groups' => Eru_TAF_Settings::groups(),
				'levels' => Eru_TAF_Settings::levels(),
				'saved'  => $saved,
				'other'  => self::OTHER,
				'i18n'   => array(
					'pickDistrict'     => __( 'İlçe seçin', 'turkish-address-fields-for-woocommerce' ),
					'pickNeighborhood' => __( 'Mahalle / köy seçin', 'turkish-address-fields-for-woocommerce' ),
					'pickStreet'       => __( 'Cadde / sokak seçin', 'turkish-address-fields-for-woocommerce' ),
					'waitProvince'     => __( 'Önce il seçin', 'turkish-address-fields-for-woocommerce' ),
					'waitDistrict'     => __( 'Önce ilçe seçin', 'turkish-address-fields-for-woocommerce' ),
					'waitNeighborhood' => __( 'Önce mahalle seçin', 'turkish-address-fields-for-woocommerce' ),
					'loading'          => __( 'Yükleniyor…', 'turkish-address-fields-for-woocommerce' ),
					'other'            => __( 'Listede yok / elle gireceğim', 'turkish-address-fields-for-woocommerce' ),
					'writeBelow'       => __( 'Adres satırına yazın', 'turkish-address-fields-for-woocommerce' ),
					'noStreets'        => __( 'Bu mahallede kayıt yok; adres satırına yazın', 'turkish-address-fields-for-woocommerce' ),
					'districtByHand'   => __( 'İlçe (elle)', 'turkish-address-fields-for-woocommerce' ),
					'noResults'        => __( 'Sonuç bulunamadı', 'turkish-address-fields-for-woocommerce' ),
					'searching'        => __( 'Aranıyor…', 'turkish-address-fields-for-woocommerce' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ AJAX */

	/**
	 * Public, read-only lookup of bundled public-sector address data (no personal data, no writes),
	 * so no nonce: cached checkout pages would otherwise break. Inputs are digits only.
	 *
	 *   ?action=eru_taf_data&level=districts&il=06
	 *   ?action=eru_taf_data&level=neighborhoods&district=1231
	 *   ?action=eru_taf_data&level=streets&district=1231&neighborhood=1565
	 */
	public static function ajax_data(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read-only data, see docblock.
		$level = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
		$il    = isset( $_GET['il'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_GET['il'] ) ) ) : '';
		$dist  = isset( $_GET['district'] ) ? absint( $_GET['district'] ) : 0;
		$hood  = isset( $_GET['neighborhood'] ) ? absint( $_GET['neighborhood'] ) : 0;
		// phpcs:enable
		$data = self::lookup( $level, (string) $il, $dist, $hood );
		if ( null === $data ) {
			wp_send_json_error( null, 400 );
		}
		if ( ! headers_sent() ) {
			header_remove( 'Pragma' );
			header( 'Cache-Control: public, max-age=604800', true );
			header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + 604800 ) . ' GMT', true );
		}
		wp_send_json_success( $data );
	}

	/** Data for one AJAX level; null for invalid input. */
	public static function lookup( string $level, string $il, int $district, int $neighborhood ): ?array {
		switch ( $level ) {
			case 'districts':
				$il = str_pad( substr( $il, 0, 2 ), 2, '0', STR_PAD_LEFT );
				return '' !== Eru_TAF_Data::province_name( $il ) ? Eru_TAF_Data::districts( $il ) : null;
			case 'neighborhoods':
				return Eru_TAF_Data::district_exists( $district ) ? Eru_TAF_Data::neighborhoods( $district ) : null;
			case 'streets':
				return Eru_TAF_Data::district_exists( $district ) && $neighborhood > 0 ? Eru_TAF_Data::streets( $district, $neighborhood ) : null;
		}
		return null;
	}

	/* ------------------------------------------------------------------ processing */

	private static function clean_choice( $v ): string {
		$v = trim( (string) $v );
		return self::OTHER === $v ? '' : $v;
	}

	/** Runs before WooCommerce validation: city = İlçe, cleaned address line, normalised phone. */
	public static function posted_data( $data ): array {
		$data = (array) $data;
		foreach ( array( 'billing', 'shipping' ) as $g ) {
			if ( 'TR' !== ( $data[ $g . '_country' ] ?? '' ) ) {
				continue;
			}
			$district = (string) ( $data[ self::key( $g, 'district' ) ] ?? '' );
			if ( '' !== $district && self::OTHER !== $district ) {
				$data[ $g . '_city' ] = $district;
			}
			if ( Eru_TAF_Settings::on( 'normalize' ) && isset( $data[ $g . '_address_1' ] ) ) {
				$data[ $g . '_address_1' ] = Eru_TAF_Normalize::line( (string) $data[ $g . '_address_1' ] );
			}
		}
		if ( Eru_TAF_Settings::on( 'phone_check' ) && ! empty( $data['billing_phone'] ) ) {
			$p = Eru_TAF_Normalize::phone( (string) $data['billing_phone'] );
			if ( null !== $p ) {
				$data['billing_phone'] = $p;
			}
		}
		return $data;
	}

	/**
	 * Errors for one address group (TR only): chosen values must exist in the bundled data,
	 * a hand-written district needs the city field, and the address line needs a door number
	 * unless "no number" is ticked.
	 *
	 * @return array<string, string> field key => message
	 */
	public static function group_errors( array $data, string $group ): array {
		if ( 'TR' !== ( $data[ $group . '_country' ] ?? '' ) || ! in_array( $group, Eru_TAF_Settings::groups(), true ) ) {
			return array();
		}
		$errors = array();
		$prefix = 'shipping' === $group ? __( 'Teslimat adresi', 'turkish-address-fields-for-woocommerce' ) : __( 'Fatura adresi', 'turkish-address-fields-for-woocommerce' );
		$plate  = Eru_TAF_Data::plate( (string) ( $data[ $group . '_state' ] ?? '' ) );
		$levels = Eru_TAF_Settings::levels();
		$dname  = (string) ( $data[ self::key( $group, 'district' ) ] ?? '' );
		$did    = 0;
		$hid    = 0;

		if ( self::OTHER === $dname ) {
			if ( '' === trim( (string) ( $data[ $group . '_city' ] ?? '' ) ) ) {
				/* translators: %s: billing / shipping address */
				$errors[ $group . '_city' ] = sprintf( __( '%s: ilçeyi yazın.', 'turkish-address-fields-for-woocommerce' ), $prefix );
			}
		} elseif ( '' !== $dname ) {
			$did = Eru_TAF_Data::district_id( $plate, $dname );
			if ( ! $did ) {
				/* translators: %s: billing / shipping address */
				$errors[ self::key( $group, 'district' ) ] = sprintf( __( '%s: seçilen ilçe bu ile ait değil.', 'turkish-address-fields-for-woocommerce' ), $prefix );
			}
		}
		$hname = self::clean_choice( $data[ self::key( $group, 'neighborhood' ) ] ?? '' );
		if ( $did && in_array( 'neighborhood', $levels, true ) && '' !== $hname ) {
			$hid = Eru_TAF_Data::neighborhood_id( $did, $hname );
			if ( ! $hid ) {
				/* translators: %s: billing / shipping address */
				$errors[ self::key( $group, 'neighborhood' ) ] = sprintf( __( '%s: seçilen mahalle bu ilçede bulunamadı.', 'turkish-address-fields-for-woocommerce' ), $prefix );
			}
		}
		$sname = self::clean_choice( $data[ self::key( $group, 'street' ) ] ?? '' );
		if ( $hid && in_array( 'street', $levels, true ) && '' !== $sname && ! in_array( $sname, Eru_TAF_Data::streets( $did, $hid ), true ) ) {
			/* translators: %s: billing / shipping address */
			$errors[ self::key( $group, 'street' ) ] = sprintf( __( '%s: seçilen cadde / sokak bu mahallede bulunamadı.', 'turkish-address-fields-for-woocommerce' ), $prefix );
		}

		$line = (string) ( $data[ $group . '_address_1' ] ?? '' );
		if ( Eru_TAF_Settings::on( 'door_check' ) && '' !== trim( $line ) && empty( $data[ self::key( $group, 'no_number' ) ] ) && ! Eru_TAF_Normalize::has_door_number( $line ) ) {
			/* translators: %s: billing / shipping address */
			$errors[ $group . '_address_1' ] = sprintf( __( '%s: bina / kapı numarasını ekleyin. Numarası olmayan bir adresse "Adresimde bina / kapı numarası yok" kutusunu işaretleyin.', 'turkish-address-fields-for-woocommerce' ), $prefix );
		}
		return $errors;
	}

	public static function validate_checkout( $data, $errors ): void {
		$data   = (array) $data;
		$groups = array( 'billing' );
		if ( ! empty( $data['ship_to_different_address'] ) && WC()->cart && WC()->cart->needs_shipping_address() ) {
			$groups[] = 'shipping';
		}
		foreach ( $groups as $g ) {
			foreach ( self::group_errors( $data, $g ) as $key => $msg ) {
				$errors->add( $key . '_eru_taf', $msg, array( 'id' => $key ) );
			}
		}
		if ( Eru_TAF_Settings::on( 'phone_check' ) && '' !== (string) ( $data['billing_phone'] ?? '' ) && null === Eru_TAF_Normalize::phone( (string) $data['billing_phone'] ) ) {
			$errors->add( 'billing_phone_eru_taf', __( 'Telefon numarası geçerli görünmüyor. Türkiye numarası olarak yazın: 05XX XXX XX XX', 'turkish-address-fields-for-woocommerce' ), array( 'id' => 'billing_phone' ) );
		}
	}

	/** My Account → Adresler save. */
	public static function validate_account( $user_id, $load_address, $address = array(), $customer = null ): void {
		$group = 'shipping' === $load_address ? 'shipping' : 'billing';
		if ( ! Eru_TAF_Settings::on( 'account' ) ) {
			return;
		}
		$data = array();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the edit-address nonce before this hook.
		foreach ( $_POST as $k => $v ) {
			if ( is_string( $k ) && str_starts_with( $k, $group . '_' ) && is_scalar( $v ) ) {
				$data[ $k ] = wc_clean( wp_unslash( (string) $v ) );
			}
		}
		// phpcs:enable
		$data = self::posted_data( $data );
		foreach ( self::group_errors( $data, $group ) as $msg ) {
			wc_add_notice( $msg, 'error' );
		}
		// City follows İlçe and the address line is cleaned for the saved profile as well.
		if ( $customer instanceof WC_Customer && 'TR' === ( $data[ $group . '_country' ] ?? '' ) ) {
			if ( isset( $data[ $group . '_city' ] ) ) {
				$customer->{'set_' . $group . '_city'}( $data[ $group . '_city' ] );
			}
			if ( isset( $data[ $group . '_address_1' ] ) ) {
				$customer->{'set_' . $group . '_address_1'}( $data[ $group . '_address_1' ] );
			}
		}
	}

	/**
	 * Address parts of a group from posted data. When the order ships to the billing address,
	 * the shipping parts are the billing parts (WooCommerce only copies fields that exist in both groups).
	 *
	 * @return array{street:string,neighborhood:string,district:string}
	 */
	public static function parts( array $data, string $group ): array {
		$src = ( 'shipping' === $group && empty( $data['ship_to_different_address'] ) ) ? 'billing' : $group;
		return array(
			'street'       => self::clean_choice( $data[ self::key( $src, 'street' ) ] ?? '' ),
			'neighborhood' => self::clean_choice( $data[ self::key( $src, 'neighborhood' ) ] ?? '' ),
			'district'     => self::clean_choice( $data[ self::key( $src, 'district' ) ] ?? '' ),
		);
	}

	/**
	 * Order address lines built from the TR fields: address_1 = street + building/door,
	 * address_2 = neighbourhood (+ the customer's own address_2), city = district.
	 */
	public static function create_order( $order, $data ): void {
		if ( ! $order instanceof WC_Order || ! Eru_TAF_Settings::on( 'write_address' ) ) {
			return;
		}
		$data = (array) $data;
		foreach ( array( 'billing', 'shipping' ) as $g ) {
			$getter = 'get_' . $g . '_country';
			if ( 'TR' !== $order->$getter() ) {
				continue;
			}
			$p = self::parts( $data, $g );
			if ( '' === $p['street'] && '' === $p['neighborhood'] && '' === $p['district'] ) {
				continue;
			}
			$a1 = (string) $order->{'get_' . $g . '_address_1'}();
			$a2 = (string) $order->{'get_' . $g . '_address_2'}();
			if ( '' !== $p['street'] ) {
				$order->{'set_' . $g . '_address_1'}( trim( $p['street'] . ' ' . $a1 ) );
			}
			if ( '' !== $p['neighborhood'] ) {
				$order->{'set_' . $g . '_address_2'}( implode( ', ', array_filter( array( $p['neighborhood'], trim( $a2 ) ) ) ) );
			}
			if ( '' !== $p['district'] ) {
				$order->{'set_' . $g . '_city'}( $p['district'] );
			}
			// Keep the parts on shipping too when shipping was copied from billing.
			if ( 'shipping' === $g && empty( $data['ship_to_different_address'] ) ) {
				foreach ( $p as $level => $v ) {
					$order->update_meta_data( '_' . self::key( 'shipping', $level ), $v );
				}
			}
		}
	}

	/* ------------------------------------------------------------------ display */

	private static function merge( array $address, WC_Order $order, string $g ): array {
		$street = (string) $order->get_meta( '_' . self::key( $g, 'street' ) );
		$hood   = (string) $order->get_meta( '_' . self::key( $g, 'neighborhood' ) );
		if ( self::OTHER !== $street && '' !== $street ) {
			$address['address_1'] = trim( $street . ' ' . ( $address['address_1'] ?? '' ) );
		}
		if ( self::OTHER !== $hood && '' !== $hood ) {
			$address['address_2'] = implode( ', ', array_filter( array( $hood, trim( (string) ( $address['address_2'] ?? '' ) ) ) ) );
		}
		return $address;
	}

	public static function merge_billing( $address, $order ): array {
		return $order instanceof WC_Order ? self::merge( (array) $address, $order, 'billing' ) : (array) $address;
	}

	public static function merge_shipping( $address, $order ): array {
		return $order instanceof WC_Order ? self::merge( (array) $address, $order, 'shipping' ) : (array) $address;
	}

	private static function admin_parts( $order, string $g ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$parts = array();
		foreach ( array( 'district', 'neighborhood', 'street' ) as $level ) {
			$v = (string) $order->get_meta( '_' . self::key( $g, $level ) );
			if ( '' !== $v && self::OTHER !== $v ) {
				$parts[] = $v;
			}
		}
		if ( $parts ) {
			echo '<p><strong>' . esc_html__( 'İlçe / Mahalle / Sokak:', 'turkish-address-fields-for-woocommerce' ) . '</strong> ' . esc_html( implode( ' / ', $parts ) ) . '</p>';
		}
		if ( $order->get_meta( '_' . self::key( $g, 'no_number' ) ) ) {
			echo '<p><em>' . esc_html__( 'Müşteri: adreste bina / kapı numarası yok.', 'turkish-address-fields-for-woocommerce' ) . '</em></p>';
		}
	}

	public static function admin_parts_billing( $order ): void {
		self::admin_parts( $order, 'billing' );
	}

	public static function admin_parts_shipping( $order ): void {
		self::admin_parts( $order, 'shipping' );
	}
}
