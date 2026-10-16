<?php
/**
 * Stored data the engine refused to read: a compiled campaign row or the settings option that throws
 * ArgumentError. The broken piece is skipped (or replaced by a safe value), every cart keeps working,
 * the problem is logged once and shown to shop managers as an admin notice until it is fixed.
 *
 * Entries live in `eru_ce_config_errors` (autoload no), at most MAX of them.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure;

final class ConfigErrors {

	public const OPTION = 'eru_ce_config_errors';
	private const MAX   = 50;

	/** @var array<string,true> signatures recorded in this request */
	private static array $seen = array();

	public static function hooks(): void {
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/**
	 * Records a problem; logs it only the first time it is seen.
	 *
	 * @param string $type 'campaign' or 'settings'.
	 * @return bool true when the entry is new.
	 */
	public static function record( string $type, int $id, string $field, string $reason, int $version = 0 ): bool {
		$sig = md5( $type . '|' . $id . '|' . $version . '|' . $field . '|' . $reason );

		if ( isset( self::$seen[ $sig ] ) ) {
			return false;
		}

		self::$seen[ $sig ] = true;
		$all                = self::all();

		if ( isset( $all[ $sig ] ) ) {
			return false;
		}

		$all[ $sig ] = array(
			'type'    => $type,
			'id'      => $id,
			'version' => $version,
			'field'   => $field,
			'reason'  => $reason,
			'time'    => time(),
		);

		if ( count( $all ) > self::MAX ) {
			$all = array_slice( $all, -self::MAX, null, true );
		}

		update_option( self::OPTION, $all, false );

		/**
		 * Fires once per newly recorded problem (also logged to the WooCommerce log, source eru-ce).
		 *
		 * @param array $entry type, id, version, field, reason, time.
		 */
		do_action( 'eru_ce_config_error', $all[ $sig ] );

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning(
				sprintf( 'Campaign Engine skipped stored %s #%d: field "%s" is %s.', $type, $id, $field, $reason ),
				array( 'source' => 'eru-ce' )
			);
		}

		return true;
	}

	/** @return array<string,array{type:string,id:int,version:int,field:string,reason:string,time:int}> */
	public static function all(): array {
		$all = get_option( self::OPTION, array() );

		return is_array( $all ) ? $all : array();
	}

	/** Clears the entries of one campaign (after a valid save) or of the settings. */
	public static function clear( string $type, ?int $id = null ): void {
		$all  = self::all();
		$kept = array_filter( $all, static fn( array $e ): bool => $e['type'] !== $type || ( null !== $id && (int) $e['id'] !== $id ) );

		if ( count( $kept ) !== count( $all ) ) {
			array() === $kept ? delete_option( self::OPTION ) : update_option( self::OPTION, $kept, false );
		}
	}

	public static function notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$all = self::all();

		if ( array() === $all ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Campaign Engine: bazı kayıtlı veriler okunamadı ve güvenli değerlerle çalışılıyor.', 'campaign-engine-for-woocommerce' ) . '</strong></p><ul>';

		foreach ( array_slice( $all, 0, 5 ) as $e ) {
			if ( 'settings' === $e['type'] ) {
				/* translators: 1: field name, 2: reason code */
				$text = sprintf( __( 'Ayarlar: "%1$s" alanı geçersiz (%2$s); güvenli varsayılan kullanılıyor.', 'campaign-engine-for-woocommerce' ), $e['field'], $e['reason'] );
			} else {
				/* translators: 1: campaign id, 2: field name, 3: reason code */
				$text = sprintf( __( 'Kampanya #%1$d atlandı: "%2$s" alanı geçersiz (%3$s). Kampanyayı açıp yeniden kaydedin.', 'campaign-engine-for-woocommerce' ), (int) $e['id'], $e['field'], $e['reason'] );
			}

			echo '<li>' . esc_html( $text ) . '</li>';
		}

		echo '</ul></div>';
	}
}
