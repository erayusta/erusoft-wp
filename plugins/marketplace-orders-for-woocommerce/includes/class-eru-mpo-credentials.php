<?php
/**
 * Marketplace settings and credentials (option eru_mpo_mp_<key>): field schema, sanitising with write-only
 * secrets, and a plain settings tab per marketplace.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapters describe their fields with settings_fields(): key => array{type, label, help?, default?, options?,
 * pattern?, hosts?}. Types: checkbox (yes|no), text, secret, select, url, csv (comma list limited to options).
 *
 * Secrets are write-only: the form renders them empty with a "Kayıtlı" hint, a blank value keeps the stored one and
 * "clear_<key>" empties it. They are stored as typed (the handler unslashes the post once; trimmed, control
 * characters removed, nothing else changed). URL fields accept https only and only on the hosts listed for the field (a credential
 * goes to that host); anything else falls back to the default and is reported. The option is stored with autoload
 * off (Eru_MPO_Settings::mp_update()). Values are read with Eru_MPO_Settings::mp_get() or values().
 */
class Eru_MPO_Credentials {

	const CAP = 'manage_woocommerce';

	/** @var bool */
	private static $booted = false;

	/** Hooks (admin only; once). */
	public static function init() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_filter( 'eru_mpo_admin_tabs', array( __CLASS__, 'tabs' ), 20 );
		add_action( 'admin_post_eru_mpo_save_mp', array( __CLASS__, 'save' ) );
		foreach ( self::adapters() as $key => $a ) {
			add_action( 'eru_mpo_admin_tab_' . $key, fn() => self::render( $a ) );
		}
	}

	/** @return array<string,Eru_MPO_Adapter> Adapters that describe their settings. */
	private static function adapters() {
		return array_filter( Eru_MPO_Registry::all(), fn( $a ) => method_exists( $a, 'settings_fields' ) );
	}

	/** @param array $tabs Tabs. @return array Tabs plus one per marketplace. */
	public static function tabs( $tabs ) {
		foreach ( self::adapters() as $key => $a ) {
			if ( ! isset( $tabs[ $key ] ) ) {
				$tabs[ $key ] = $a->label();
			}
		}
		return $tabs;
	}

	/**
	 * Stored values over the field defaults.
	 *
	 * @param string $key    Marketplace key.
	 * @param array  $fields Field schema.
	 * @return array
	 */
	public static function values( $key, array $fields ) {
		$out = array();
		foreach ( $fields as $f => $def ) {
			$out[ $f ] = Eru_MPO_Settings::mp_get( $key, $f, $def['default'] ?? ( 'checkbox' === $def['type'] ? 'no' : '' ) );
		}
		return $out;
	}

	/**
	 * Cleans posted (unslashed) values against the schema.
	 *
	 * @param array $fields Field schema.
	 * @param array $in     Posted values.
	 * @param array $old    Stored values (secrets left blank keep these).
	 * @return array{values: array, errors: string[]}
	 */
	public static function sanitize( array $fields, array $in, array $old ) {
		$out    = array();
		$errors = array();
		foreach ( $fields as $k => $def ) {
			$default = $def['default'] ?? '';
			$raw     = $in[ $k ] ?? null;
			switch ( $def['type'] ) {
				case 'checkbox':
					$out[ $k ] = 'yes' === $raw ? 'yes' : 'no';
					break;
				case 'secret':
					// Stored as typed: the handler wp_unslash()es once; only surrounding whitespace and control
					// characters go. sanitize_text_field() would alter passwords (%xx octets, "<", line breaks).
					$v         = is_scalar( $raw ) ? trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $raw ) ) : '';
					$out[ $k ] = '' !== $v ? $v : (string) ( $old[ $k ] ?? '' );
					if ( ! empty( $in[ 'clear_' . $k ] ) ) {
						$out[ $k ] = '';
					}
					break;
				case 'select':
					$v         = is_scalar( $raw ) ? (string) $raw : '';
					$out[ $k ] = isset( $def['options'][ $v ] ) ? $v : (string) $default;
					break;
				case 'csv':
					$parts     = array_filter( array_map( 'trim', explode( ',', is_scalar( $raw ) ? (string) $raw : '' ) ) );
					$parts     = array_values( array_intersect( array_unique( $parts ), (array) ( $def['options'] ?? array() ) ) );
					$out[ $k ] = $parts ? implode( ',', $parts ) : (string) $default;
					break;
				case 'url':
					$url  = esc_url_raw( trim( is_scalar( $raw ) ? (string) $raw : '' ), array( 'https' ) );
					$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
					$ok   = false;
					foreach ( (array) ( $def['hosts'] ?? array() ) as $h ) {
						$ok = $ok || $host === $h || str_ends_with( $host, '.' . $h );
					}
					if ( '' !== $url && ! $ok ) {
						/* translators: 1: field label, 2: allowed domains */
						$errors[] = sprintf( __( '%1$s yalnızca https ve %2$s alan adında olabilir; varsayılan adres kullanıldı.', 'marketplace-orders-for-woocommerce' ), $def['label'], implode( ', ', (array) ( $def['hosts'] ?? array() ) ) );
						$url      = '';
					}
					$out[ $k ] = '' !== $url ? untrailingslashit( $url ) : (string) $default;
					break;
				default:
					$v = is_scalar( $raw ) ? trim( sanitize_text_field( (string) $raw ) ) : '';
					if ( isset( $def['pattern'] ) ) {
						$v = (string) preg_replace( $def['pattern'], '', $v );
					}
					$out[ $k ] = '' !== $v ? $v : (string) $default;
			}
		}
		return array(
			'values' => $out,
			'errors' => $errors,
		);
	}

	/**
	 * Saves posted values for a marketplace (internal use: the handler, tests, CLI).
	 *
	 * @param Eru_MPO_Adapter $adapter Adapter with settings_fields().
	 * @param array           $in      Unslashed values.
	 * @return string[] Errors.
	 */
	public static function update( $adapter, array $in ) {
		$fields = $adapter->settings_fields();
		$clean  = self::sanitize( $fields, $in, self::values( $adapter->key(), $fields ) );
		Eru_MPO_Settings::mp_update( $adapter->key(), $clean['values'] );
		return $clean['errors'];
	}

	/** @param Eru_MPO_Adapter $adapter Adapter. Renders its tab. */
	public static function render( $adapter ) {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$key    = $adapter->key();
		$fields = $adapter->settings_fields();
		$vals   = self::values( $key, $fields );
		$errors = get_transient( 'eru_mpo_mp_errors_' . get_current_user_id() );
		if ( is_array( $errors ) && $errors ) {
			delete_transient( 'eru_mpo_mp_errors_' . get_current_user_id() );
			foreach ( $errors as $e ) {
				echo '<div class="notice notice-warning"><p>' . esc_html( (string) $e ) . '</p></div>';
			}
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'eru_mpo_save_mp_' . $key );
		echo '<input type="hidden" name="action" value="eru_mpo_save_mp"><input type="hidden" name="mp" value="' . esc_attr( $key ) . '">';
		echo '<table class="form-table" role="presentation">';
		foreach ( $fields as $f => $def ) {
			$id   = 'eru-mpo-' . $key . '-' . $f;
			$name = 'eru_mpo_mp[' . $f . ']';
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $def['label'] ) . '</label></th><td>';
			switch ( $def['type'] ) {
				case 'checkbox':
					echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="yes"' . checked( 'yes', $vals[ $f ], false ) . '> ' . esc_html( (string) ( $def['help'] ?? '' ) ) . '</label>';
					break;
				case 'secret':
					$stored = '' !== (string) $vals[ $f ];
					echo '<input type="password" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="" autocomplete="new-password">';
					if ( $stored ) {
						echo ' <span class="description">' . esc_html__( 'Kayıtlı. Değiştirmek için yenisini yazın.', 'marketplace-orders-for-woocommerce' ) . '</span>';
						echo '<br><label><input type="checkbox" name="eru_mpo_mp[clear_' . esc_attr( $f ) . ']" value="1"> ' . esc_html__( 'Kayıtlı değeri sil', 'marketplace-orders-for-woocommerce' ) . '</label>';
					}
					break;
				case 'select':
					echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
					foreach ( (array) $def['options'] as $v => $l ) {
						echo '<option value="' . esc_attr( (string) $v ) . '"' . selected( (string) $vals[ $f ], (string) $v, false ) . '>' . esc_html( (string) $l ) . '</option>';
					}
					echo '</select>';
					break;
				default:
					echo '<input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $vals[ $f ] ) . '">';
			}
			if ( 'checkbox' !== $def['type'] && ! empty( $def['help'] ) ) {
				echo '<p class="description">' . esc_html( (string) $def['help'] ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
		submit_button( __( 'Kaydet', 'marketplace-orders-for-woocommerce' ) );
		echo '</form>';
	}

	/** admin-post handler: capability, nonce, sanitise, save, back to the tab. */
	public static function save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'marketplace-orders-for-woocommerce' ), 403 );
		}
		$key     = isset( $_POST['mp'] ) ? sanitize_key( wp_unslash( $_POST['mp'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$adapter = self::adapters()[ $key ] ?? null;
		if ( ! $adapter ) {
			wp_die( esc_html__( 'Bilinmeyen pazaryeri.', 'marketplace-orders-for-woocommerce' ), 400 );
		}
		check_admin_referer( 'eru_mpo_save_mp_' . $key );
		$in     = isset( $_POST['eru_mpo_mp'] ) && is_array( $_POST['eru_mpo_mp'] ) ? wp_unslash( $_POST['eru_mpo_mp'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$errors = self::update( $adapter, $in );
		if ( $errors ) {
			set_transient( 'eru_mpo_mp_errors_' . get_current_user_id(), array_slice( $errors, 0, 10 ), 5 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => Eru_MPO_Admin::PAGE,
					'tab'     => $key,
					'updated' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
