<?php
/**
 * Health checks shared by Kampanyalar → Durum and `wp eru-ce doctor`: PHP, WooCommerce and database
 * versions, the plugin's tables, Action Scheduler, stored data that could not be read, price hooks that
 * run after the campaign priority, overlapping Erusoft plugins, the cache adaptor and the campaign page
 * rules.
 *
 * Each row: key, status (ok | warn | error | info), label, detail.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Admin;

use ActionScheduler_Store;
use Erusoft\CampaignEngine\Infrastructure\ConfigErrors;
use Erusoft\CampaignEngine\Infrastructure\Database\CampaignRepository;
use Erusoft\CampaignEngine\Infrastructure\Database\RewardRepository;
use Erusoft\CampaignEngine\Infrastructure\Database\Schema;
use Erusoft\CampaignEngine\Infrastructure\Database\UsageRepository;
use Erusoft\CampaignEngine\Infrastructure\Jobs;
use Erusoft\CampaignEngine\Infrastructure\Settings\Options;
use Erusoft\CampaignEngine\Infrastructure\Settings\StorefrontOptions;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\CartApplier;

final class Doctor {

	/** Price hooks whose later callbacks can change a campaign line's price. */
	private const PRICE_HOOKS = array( 'woocommerce_product_get_price', 'woocommerce_product_variation_get_price', 'woocommerce_before_calculate_totals' );

	/** @return array<int,array{key:string,status:string,label:string,detail:string}> */
	public static function report(): array {
		$rows = array();

		$rows[] = self::row(
			'php',
			PHP_VERSION_ID >= 80100 ? 'ok' : 'error',
			__( 'PHP sürümü', 'campaign-engine-for-woocommerce' ),
			/* translators: %s: version */
			sprintf( __( 'PHP %s (8.1 veya üstü gerekir).', 'campaign-engine-for-woocommerce' ), PHP_VERSION )
		);

		$wc     = defined( 'WC_VERSION' ) ? (string) WC_VERSION : '0';
		$rows[] = self::row(
			'woocommerce',
			version_compare( $wc, '9.9', '>=' ) ? 'ok' : 'error',
			__( 'WooCommerce sürümü', 'campaign-engine-for-woocommerce' ),
			/* translators: %s: version */
			sprintf( __( 'WooCommerce %s (9.9 veya üstü gerekir).', 'campaign-engine-for-woocommerce' ), $wc )
		);

		$rows[] = self::database();
		$rows[] = self::tables();
		$rows[] = self::scheduler();

		$errors = ConfigErrors::all();
		$rows[] = self::row(
			'stored_data',
			array() === $errors ? 'ok' : 'error',
			__( 'Kayıtlı kampanya ve ayar verisi', 'campaign-engine-for-woocommerce' ),
			array() === $errors
				? __( 'Bütün kayıtlar okunabiliyor.', 'campaign-engine-for-woocommerce' )
				/* translators: %d: number of problems */
				: sprintf( __( '%d kayıt okunamadı ve atlanıyor; ayrıntılar yönetim bildiriminde.', 'campaign-engine-for-woocommerce' ), count( $errors ) )
		);

		$rows[] = self::row(
			'campaigns',
			'info',
			__( 'Kampanyalar ve açık işler', 'campaign-engine-for-woocommerce' ),
			/* translators: 1: active campaigns, 2: held uses, 3: open rewards */
			sprintf( __( '%1$d etkin kampanya, %2$d tutulan kullanım hakkı, %3$d bekleyen ya da kullanılabilir ödül.', 'campaign-engine-for-woocommerce' ), count( CampaignRepository::active() ), UsageRepository::openCount(), RewardRepository::openCount() )
		);

		$rows[] = self::hooks();
		$rows[] = self::overlaps();
		$rows[] = self::cache();
		$rows[] = self::rewrite();

		return $rows;
	}

	private static function database(): array {
		global $wpdb;

		$info  = (string) $wpdb->db_server_info();
		$maria = false !== stripos( $info, 'mariadb' );

		if ( 1 !== preg_match( '/(\d+\.\d+\.\d+)/', (string) $wpdb->db_version() . ' ' . $info, $m ) ) {
			return self::row( 'database', 'warn', __( 'Veritabanı', 'campaign-engine-for-woocommerce' ), __( 'Sürüm okunamadı. MySQL 5.7.5+ ya da MariaDB 10.0.2+ gerekir.', 'campaign-engine-for-woocommerce' ) );
		}

		if ( $maria && 1 === preg_match( '/(\d+\.\d+\.\d+)-MariaDB/i', $info, $mm ) ) {
			$m[1] = $mm[1];
		}

		$ok = $maria ? version_compare( $m[1], '10.0.2', '>=' ) : version_compare( $m[1], '5.7.5', '>=' );

		return self::row(
			'database',
			$ok ? 'ok' : 'error',
			__( 'Veritabanı', 'campaign-engine-for-woocommerce' ),
			/* translators: 1: server type, 2: version */
			sprintf( __( '%1$s %2$s. Aynı anda birden çok adlandırılmış kilit için MySQL 5.7.5+ ya da MariaDB 10.0.2+ gerekir.', 'campaign-engine-for-woocommerce' ), $maria ? 'MariaDB' : 'MySQL', $m[1] )
		);
	}

	private static function tables(): array {
		global $wpdb;

		$missing = array();

		foreach ( Schema::tables() as $name => $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				$missing[] = $name;
			}
		}

		$version = (string) get_option( Schema::VERSION_OPTION, '' );

		if ( array() !== $missing ) {
			/* translators: %s: table names */
			return self::row( 'tables', 'error', __( 'Tablolar', 'campaign-engine-for-woocommerce' ), sprintf( __( 'Eksik tablolar: %s. Eklentiyi devre dışı bırakıp yeniden etkinleştirin.', 'campaign-engine-for-woocommerce' ), implode( ', ', $missing ) ) );
		}

		return self::row(
			'tables',
			Schema::VERSION === $version ? 'ok' : 'warn',
			__( 'Tablolar', 'campaign-engine-for-woocommerce' ),
			/* translators: 1: table count, 2: schema version */
			sprintf( __( '%1$d tablo mevcut, şema sürümü %2$s.', 'campaign-engine-for-woocommerce' ), count( Schema::tables() ), '' === $version ? '—' : $version )
		);
	}

	private static function scheduler(): array {
		if ( ! Jobs::available() ) {
			return self::row( 'action_scheduler', 'error', __( 'Action Scheduler', 'campaign-engine-for-woocommerce' ), __( 'Action Scheduler bulunamadı; ödüller ve kullanım hakları işlenemez.', 'campaign-engine-for-woocommerce' ) );
		}

		$query = static fn( string $status, array $extra = array() ): array => (array) as_get_scheduled_actions(
			array_merge(
				array(
					'group'    => Jobs::GROUP,
					'status'   => $status,
					'per_page' => 100,
				),
				$extra
			),
			'ids'
		);

		$pending = count( $query( ActionScheduler_Store::STATUS_PENDING ) );
		$late    = count( $query( ActionScheduler_Store::STATUS_PENDING, array( 'date' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), 'date_compare' => '<=' ) ) );
		$failed  = count( $query( ActionScheduler_Store::STATUS_FAILED, array( 'date' => gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ), 'date_compare' => '>=' ) ) );
		$status  = $failed > 0 || $late > 0 ? 'warn' : 'ok';

		return self::row(
			'action_scheduler',
			$status,
			__( 'Action Scheduler (eru-ce grubu)', 'campaign-engine-for-woocommerce' ),
			/* translators: 1: pending, 2: overdue more than an hour, 3: failed in the last 7 days */
			sprintf( __( '%1$d bekleyen iş, %2$d iş bir saatten fazla gecikmiş, son 7 günde %3$d başarısız iş.', 'campaign-engine-for-woocommerce' ), $pending, $late, $failed )
		);
	}

	/** Callbacks on the price hooks that run after the campaign priority. */
	private static function hooks(): array {
		global $wp_filter;

		$priority = CartApplier::pricePriority();
		$late     = array();
		$same     = array();

		foreach ( self::PRICE_HOOKS as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}

			foreach ( $wp_filter[ $hook ]->callbacks as $at => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$name = self::callbackName( $callback['function'] );

					if ( 0 === strpos( $name, 'Erusoft\\CampaignEngine\\' ) ) {
						continue;
					}

					if ( (int) $at > $priority ) {
						$late[] = $hook . ' @' . $at . ': ' . $name;
					} elseif ( (int) $at === $priority ) {
						$same[] = $hook . ' @' . $at . ': ' . $name;
					}
				}
			}
		}

		if ( array() !== $late ) {
			/* translators: %s: callbacks */
			return self::row( 'hooks', 'warn', __( 'Fiyat kancaları', 'campaign-engine-for-woocommerce' ), sprintf( __( 'Kampanya önceliğinden (eru_ce_price_priority) sonra fiyat değiştirebilecek kodlar: %s. Bu kodlar fiyatı değiştirirse o satırın kampanya indirimi geri çekilir.', 'campaign-engine-for-woocommerce' ), implode( '; ', array_slice( $late, 0, 8 ) ) ) );
		}

		if ( array() !== $same ) {
			/* translators: %s: callbacks */
			return self::row( 'hooks', 'info', __( 'Fiyat kancaları', 'campaign-engine-for-woocommerce' ), sprintf( __( 'Kampanyayla aynı öncelikte çalışan kodlar: %s.', 'campaign-engine-for-woocommerce' ), implode( '; ', array_slice( $same, 0, 8 ) ) ) );
		}

		/* translators: %d: priority */
		return self::row( 'hooks', 'ok', __( 'Fiyat kancaları', 'campaign-engine-for-woocommerce' ), sprintf( __( 'Kampanya önceliğinden (%d) sonra fiyat değiştiren kod yok.', 'campaign-engine-for-woocommerce' ), $priority ) );
	}

	private static function overlaps(): array {
		$active  = ConflictNotice::active();
		$related = ConflictNotice::active( ConflictNotice::RELATED );

		if ( array() !== $active ) {
			/* translators: %s: plugin names */
			return self::row( 'overlap', 'warn', __( 'Benzer Erusoft eklentileri', 'campaign-engine-for-woocommerce' ), sprintf( __( 'Etkin: %s. Aynı kurallar iki kez indirim yapabilir; bir kuralı yalnızca bir eklentide tutun.', 'campaign-engine-for-woocommerce' ), implode( ', ', $active ) ) );
		}

		if ( array() !== $related ) {
			/* translators: %s: plugin names */
			return self::row( 'overlap', 'info', __( 'Benzer Erusoft eklentileri', 'campaign-engine-for-woocommerce' ), sprintf( __( 'Etkin: %s. Kısmen örtüşür; aynı ürün setine iki yerden indirim tanımlamayın.', 'campaign-engine-for-woocommerce' ), implode( ', ', $related ) ) );
		}

		return self::row( 'overlap', 'ok', __( 'Benzer Erusoft eklentileri', 'campaign-engine-for-woocommerce' ), __( 'Örtüşen indirim eklentisi etkin değil.', 'campaign-engine-for-woocommerce' ) );
	}

	private static function cache(): array {
		$adaptor = (string) Options::get( 'cache_adaptor' );

		if ( 'litespeed' === $adaptor && ! defined( 'LSCWP_V' ) ) {
			return self::row( 'cache', 'warn', __( 'Sayfa önbelleği', 'campaign-engine-for-woocommerce' ), __( 'LiteSpeed Cache seçili ama etkin görünmüyor; kampanya değişince önbellek temizlenmez.', 'campaign-engine-for-woocommerce' ) );
		}

		/* translators: %s: adaptor */
		return self::row( 'cache', 'info', __( 'Sayfa önbelleği', 'campaign-engine-for-woocommerce' ), sprintf( __( 'Temizleme: %s. Sayfa önbelleği kullanıyorsanız kampanya rozetleri ve fiyat gösterimi önbellek süresince eski kalabilir; sepet her zaman güncel hesaplanır.', 'campaign-engine-for-woocommerce' ), $adaptor ) );
	}

	private static function rewrite(): array {
		if ( ! StorefrontOptions::on( 'pages' ) ) {
			return self::row( 'pages', 'info', __( 'Kampanya sayfaları', 'campaign-engine-for-woocommerce' ), __( 'Kapalı.', 'campaign-engine-for-woocommerce' ) );
		}

		$rules = (array) get_option( 'rewrite_rules', array() );
		$slug  = (string) StorefrontOptions::get( 'archive_slug' );
		$found = false;

		foreach ( array_keys( $rules ) as $regex ) {
			if ( 0 === strpos( (string) $regex, '^' . $slug . '/' ) || 0 === strpos( (string) $regex, $slug . '/' ) ) {
				$found = true;
				break;
			}
		}

		return self::row(
			'pages',
			$found ? 'ok' : 'warn',
			__( 'Kampanya sayfaları', 'campaign-engine-for-woocommerce' ),
			$found
				/* translators: %s: URL */
				? sprintf( __( 'Adres: %s', 'campaign-engine-for-woocommerce' ), home_url( '/' . $slug . '/' ) )
				: __( 'Kalıcı bağlantı kuralları henüz yenilenmedi; Ayarlar → Kalıcı bağlantılar ekranını bir kez kaydedin.', 'campaign-engine-for-woocommerce' )
		);
	}

	/** @param mixed $fn */
	private static function callbackName( $fn ): string {
		if ( is_string( $fn ) ) {
			return $fn;
		}

		if ( is_array( $fn ) && 2 === count( $fn ) ) {
			return ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) . '::' . (string) $fn[1];
		}

		return $fn instanceof \Closure ? 'closure' : 'callable';
	}

	/** @return array{key:string,status:string,label:string,detail:string} */
	private static function row( string $key, string $status, string $label, string $detail ): array {
		return array(
			'key'    => $key,
			'status' => $status,
			'label'  => $label,
			'detail' => $detail,
		);
	}

	/** Status words for the screen and the CLI. */
	public static function statusLabel( string $status ): string {
		$map = array(
			'ok'    => __( 'Tamam', 'campaign-engine-for-woocommerce' ),
			'warn'  => __( 'Uyarı', 'campaign-engine-for-woocommerce' ),
			'error' => __( 'Hata', 'campaign-engine-for-woocommerce' ),
			'info'  => __( 'Bilgi', 'campaign-engine-for-woocommerce' ),
		);

		return $map[ $status ] ?? $status;
	}
}
