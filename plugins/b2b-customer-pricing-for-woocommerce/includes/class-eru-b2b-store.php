<?php
/** Storage for groups, rules and the label. All options are non-autoloaded. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_B2B_Store {

	const RULES   = 'eru_b2b_rules';
	const GROUPS  = 'eru_b2b_groups';
	const LABEL   = 'eru_b2b_label';
	const VERSION = 'eru_b2b_version';
	const USERMETA = 'eru_b2b_group';

	/** @return array<string,string> Group slug => name. */
	public static function groups(): array {
		$groups = get_option( self::GROUPS, array() );
		return is_array( $groups ) ? $groups : array();
	}

	/** @return array[] Rules (see sanitize_rule()). */
	public static function rules(): array {
		$rules = get_option( self::RULES, array() );
		return is_array( $rules ) ? array_values( array_filter( $rules, 'is_array' ) ) : array();
	}

	public static function label(): string {
		$label = get_option( self::LABEL, null );
		return null === $label ? __( 'Size özel fiyat', 'b2b-customer-pricing-for-woocommerce' ) : (string) $label;
	}

	/** Bumped on every change so cached variation price ranges are rebuilt. */
	public static function version(): int {
		return (int) get_option( self::VERSION, 1 );
	}

	private static function touch(): void {
		update_option( self::VERSION, self::version() + 1, false );
		if ( class_exists( 'Eru_B2B_Pricing' ) ) {
			Eru_B2B_Pricing::flush();
		}
	}

	public static function save_rules( array $rules ): void {
		update_option( self::RULES, array_values( $rules ), false );
		self::touch();
	}

	public static function save_groups( array $groups ): void {
		update_option( self::GROUPS, $groups, false );
		self::touch();
	}

	public static function save_label( string $label ): void {
		update_option( self::LABEL, sanitize_text_field( $label ), false );
	}

	public static function add_group( string $name ): string {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return '';
		}
		$groups = self::groups();
		$slug   = sanitize_key( sanitize_title( $name ) );
		if ( '' === $slug ) {
			$slug = 'grup';
		}
		$base = $slug;
		$i    = 2;
		while ( isset( $groups[ $slug ] ) ) {
			$slug = $base . '-' . $i++;
		}
		$groups[ $slug ] = $name;
		self::save_groups( $groups );
		return $slug;
	}

	public static function delete_group( string $slug ): void {
		$groups = self::groups();
		unset( $groups[ $slug ] );
		self::save_groups( $groups );
		self::save_rules(
			array_filter(
				self::rules(),
				fn( $r ) => ! ( 'group' === $r['scope'] && $r['target'] === $slug )
			)
		);
		delete_metadata( 'user', 0, self::USERMETA, $slug, true );
	}

	/** Validate one rule; returns null when it is not acceptable. */
	public static function sanitize_rule( array $in ): ?array {
		$scope  = in_array( $in['scope'] ?? '', array( 'user', 'group' ), true ) ? $in['scope'] : '';
		$type   = in_array( $in['type'] ?? '', array( 'all', 'category', 'product' ), true ) ? $in['type'] : '';
		$mode   = in_array( $in['mode'] ?? '', array( 'price', 'percent' ), true ) ? $in['mode'] : '';
		$value  = isset( $in['value'] ) && is_scalar( $in['value'] ) ? trim( (string) $in['value'] ) : '';
		if ( '' === $scope || '' === $type || '' === $mode || '' === $value ) {
			return null;
		}
		$value = (float) wc_format_decimal( $value );
		if ( $value < 0 || ( 'percent' === $mode && $value > 100 ) ) {
			return null;
		}
		if ( 'user' === $scope ) {
			$target = (int) ( $in['target'] ?? 0 );
			if ( $target < 1 || ! get_userdata( $target ) ) {
				return null;
			}
		} else {
			$target = sanitize_key( (string) ( $in['target'] ?? '' ) );
			if ( ! isset( self::groups()[ $target ] ) ) {
				return null;
			}
		}
		$object = 'all' === $type ? 0 : (int) ( $in['object'] ?? 0 );
		if ( 'product' === $type && ( $object < 1 || ! wc_get_product( $object ) ) ) {
			return null;
		}
		if ( 'category' === $type && ( $object < 1 || ! term_exists( $object, 'product_cat' ) ) ) {
			return null;
		}
		return array(
			'id'     => (string) ( $in['id'] ?? wp_generate_uuid4() ),
			'scope'  => $scope,
			'target' => $target,
			'type'   => $type,
			'object' => $object,
			'mode'   => $mode,
			'value'  => $value,
		);
	}

	/** Add a rule; returns its id or '' when invalid. */
	public static function add_rule( array $in ): string {
		unset( $in['id'] );
		$rule = self::sanitize_rule( $in );
		if ( ! $rule ) {
			return '';
		}
		$rules   = self::rules();
		$rules[] = $rule;
		self::save_rules( $rules );
		return $rule['id'];
	}

	public static function delete_rule( string $id ): void {
		self::save_rules( array_filter( self::rules(), fn( $r ) => ( $r['id'] ?? '' ) !== $id ) );
	}

	public static function user_group( int $user_id ): string {
		$slug = (string) get_user_meta( $user_id, self::USERMETA, true );
		return isset( self::groups()[ $slug ] ) ? $slug : '';
	}
}
