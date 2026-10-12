<?php
/** Kayıt sırasında e-posta alan adına göre rol atar. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_Edr {

	const OPTION_MAPPINGS = 'eru_edr_mappings';
	const OPTION_FALLBACK = 'eru_edr_fallback_role';

	/** Bu yeteneklerden birine sahip roller asla atanamaz. */
	const PRIVILEGED_CAPS = array( 'manage_options', 'promote_users', 'edit_users', 'create_users', 'delete_users', 'unfiltered_html', 'install_plugins', 'activate_plugins', 'edit_plugins', 'edit_theme_options', 'switch_themes', 'manage_woocommerce' );

	public static function init(): void {
		add_action( 'user_register', array( __CLASS__, 'assign_role' ), 20, 1 );
	}

	/** @return array<int,array{domain:string,role:string}> */
	public static function mappings(): array {
		$m = get_option( self::OPTION_MAPPINGS, array() );
		return is_array( $m ) ? $m : array();
	}

	/**
	 * Atanabilecek roller (rol anahtarı => ad). Yönetim/yetki yükseltme yeteneklerinden (PRIVILEGED_CAPS)
	 * herhangi birine sahip roller hariçtir; e-posta alan adı doğrulanmadığı için bu bilinçli bir güvenlik önlemidir.
	 * Filtre: eru_edr_allowed_roles.
	 */
	public static function allowed_roles(): array {
		$out = array();
		foreach ( wp_roles()->roles as $key => $role ) {
			$caps = array_keys( array_filter( (array) $role['capabilities'] ) );
			if ( ! array_intersect( self::PRIVILEGED_CAPS, $caps ) ) {
				$out[ $key ] = translate_user_role( $role['name'] );
			}
		}
		return (array) apply_filters( 'eru_edr_allowed_roles', $out );
	}

	public static function normalize_domain( string $domain ): string {
		$domain = strtolower( trim( $domain ) );
		$domain = preg_replace( '/^@+/', '', $domain );
		return (string) $domain;
	}

	public static function is_valid_domain( string $domain ): bool {
		return (bool) preg_match( '/^(\*\.)?([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z0-9-]{2,}$/', $domain );
	}

	/** Eşleşen rolü döndürür; eşleşme yoksa boş string. */
	public static function role_for_email( string $email ): string {
		$pos = strrpos( $email, '@' );
		if ( false === $pos ) {
			return '';
		}
		$domain  = strtolower( substr( $email, $pos + 1 ) );
		$allowed = self::allowed_roles();

		foreach ( self::mappings() as $map ) {
			$pattern = (string) ( $map['domain'] ?? '' );
			$role    = (string) ( $map['role'] ?? '' );
			if ( '' === $pattern || ! isset( $allowed[ $role ] ) ) {
				continue;
			}
			if ( 0 === strpos( $pattern, '*.' ) ) {
				$suffix = substr( $pattern, 1 ); // ".example.org"
				if ( strlen( $domain ) > strlen( $suffix ) && substr( $domain, -strlen( $suffix ) ) === $suffix ) {
					return $role;
				}
			} elseif ( $pattern === $domain ) {
				return $role;
			}
		}

		$fallback = (string) get_option( self::OPTION_FALLBACK, '' );
		return ( '' !== $fallback && isset( $allowed[ $fallback ] ) ) ? $fallback : '';
	}

	public static function assign_role( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || '' === $user->user_email ) {
			return;
		}

		// Bir yönetici (kullanıcı oluşturma yetkisi) kullanıcıyı elle ekliyorsa rolü ona bırak.
		if ( current_user_can( 'create_users' ) && ! apply_filters( 'eru_edr_assign_for_admin_created', false, (int) $user_id ) ) {
			return;
		}

		// Yönetici bilinçli olarak başka bir rol seçtiyse dokunma: yalnız varsayılan roller değiştirilir.
		$replaceable = (array) apply_filters( 'eru_edr_replaceable_roles', array_unique( array( (string) get_option( 'default_role', 'subscriber' ), 'customer' ) ) );
		if ( $user->roles && array_diff( $user->roles, $replaceable ) ) {
			return;
		}

		$role = self::role_for_email( $user->user_email );
		if ( '' === $role ) {
			return;
		}

		$user->set_role( $role );
		do_action( 'eru_edr_role_assigned', (int) $user_id, $role, $user->user_email );
	}
}
