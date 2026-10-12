<?php
/**
 * Single settings option with sanitising update.
 *
 * @package trendyol-reviews-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wrapper around the `eru_tyr_settings` option.
 */
class Eru_TYR_Settings {

	const OPTION        = 'eru_tyr_settings';
	const SECRET_OPTION = 'eru_tyr_api_secret';

	/**
	 * Defaults. Nothing causes outbound requests until the merchant enters
	 * credentials and starts an import. The API secret is kept in its own
	 * non-autoloaded option (see set_secret()).
	 */
	public static function defaults(): array {
		return array(
			'seller_id'           => '',
			'api_key'             => '',
			'barcode_meta'        => '_global_unique_id',
			'max_reviews'         => 100,
			'min_rating'          => 1,
			'import_photos'       => 0,
			'structured_data'     => 0,
			'sync_enabled'        => 0,
			'delete_on_uninstall' => 0,
		);
	}

	public static function all(): array {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Merge and sanitise the given values (unknown keys are ignored).
	 */
	public static function update( array $values ): array {
		$clean = self::all();

		foreach ( self::defaults() as $key => $default ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$value = $values[ $key ];
			switch ( $key ) {
				case 'seller_id':
					$clean[ $key ] = self::sanitize_seller_id( (string) $value );
					break;
				case 'api_key':
					$clean[ $key ] = trim( sanitize_text_field( (string) $value ) );
					break;
				case 'barcode_meta':
					$meta          = preg_replace( '/[^A-Za-z0-9_\-:]/', '', sanitize_text_field( (string) $value ) );
					$clean[ $key ] = '' !== $meta ? substr( $meta, 0, 191 ) : '_global_unique_id';
					break;
				case 'max_reviews':
					$clean[ $key ] = max( 1, min( 1000, (int) $value ) );
					break;
				case 'min_rating':
					$clean[ $key ] = max( 1, min( 5, (int) $value ) );
					break;
				default:
					$clean[ $key ] = (int) (bool) $value;
			}
		}

		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	public static function get_secret(): string {
		return (string) get_option( self::SECRET_OPTION, '' );
	}

	/**
	 * API secrets are opaque: keep every printable character (sanitize_text_field
	 * would drop sequences such as "%ab" or "<x"), only trim and strip control
	 * characters.
	 */
	public static function sanitize_secret( $secret ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $secret ) );
	}

	/**
	 * Store the API secret (never autoloaded, never printed back).
	 */
	public static function set_secret( string $secret ): void {
		$secret = self::sanitize_secret( $secret );
		if ( '' === $secret ) {
			delete_option( self::SECRET_OPTION );
			return;
		}
		delete_option( self::SECRET_OPTION );
		add_option( self::SECRET_OPTION, $secret, '', false );
	}

	public static function has_credentials(): bool {
		$all = self::all();
		return '' !== $all['seller_id'] && '' !== $all['api_key'] && '' !== self::get_secret();
	}

	/**
	 * Trendyol seller (Mağaza) IDs are numeric; anything else becomes ''.
	 */
	public static function sanitize_seller_id( string $value ): string {
		$value = trim( $value );
		return preg_match( '/^\d{1,12}$/', $value ) ? $value : '';
	}
}
