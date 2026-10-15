<?php
/**
 * Settings: defaults, access, sanitising and the schedule that follows the on/off switches.
 *
 * @package growth-automations-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One option (autoload off) holding a section per automation. Every automation defaults to "no".
 */
class Eru_GRA_Settings {

	const OPTION = 'eru_gra_settings';
	const GROUP  = 'eru_gra';

	/** Automations that run on a schedule. */
	const RUNNABLE = array( 'bacs', 'cart', 'winback', 'review' );

	/** @return array Common e-mail fields for an automation. */
	private static function mail_defaults( array $over ) {
		return array_merge(
			array(
				'enabled'       => 'no',
				'delay'         => '1',
				'max_age_days'  => '14',
				'subject'       => '',
				'heading'       => '',
				'body'          => '',
				'coupon_mode'   => 'none',
				'coupon_code'   => '',
				'coupon_prefix' => 'KUPON',
				'coupon_type'   => 'percent',
				'coupon_amount' => '8',
				'coupon_min'    => '',
				'coupon_days'   => '7',
				'batch_size'    => '50',
			),
			$over
		);
	}

	/** @return array Default settings. */
	public static function defaults() {
		return array(
			'general' => array(
				'consent_text'   => 'Sepetimin ve e-posta adresimin, siparişi tamamlamazsam hatırlatma ve kampanya e-postaları göndermek için kaydedilmesini kabul ediyorum. İstediğim zaman e-postadaki bağlantıdan vazgeçebilirim.',
				'optin_text'     => 'Pazarlama e-postaları almak istiyorum.',
				'retention_days' => '90',
			),
			'bacs'    => self::mail_defaults(
				array(
					'delay'        => '3',
					'max_age_days' => '14',
					'subject'      => 'Havale/EFT ödemeniz bekleniyor: #{order_number}',
					'heading'      => 'Ödemenizi bekliyoruz',
					'body'         => "Merhaba {customer_name},\n\n#{order_number} numaralı siparişiniz için havale/EFT ödemenizi henüz almadık. Aşağıdaki banka bilgileriyle ödemeyi tamamladığınızda siparişiniz işleme alınır.",
				)
			),
			'cart'    => self::mail_defaults(
				array(
					'delay'         => '6',
					'max_age_days'  => '7',
					'cooldown'      => '72',
					'subject'       => 'Sepetiniz sizi bekliyor - {site_title}',
					'heading'       => 'Sepetinizi unutmayın',
					'body'          => "Merhaba {customer_name},\n\nSepetinizdeki ürünler hâlâ sizi bekliyor. Kaldığınız yerden devam etmek için:\n\n{cart_link}",
					'coupon_prefix' => 'HATIRLA',
					'coupon_amount' => '7',
					'coupon_days'   => '3',
				)
			),
			'winback' => self::mail_defaults(
				array(
					'delay'         => '60',
					'max_age_days'  => '180',
					'subject'       => 'Sizi özledik - {site_title}',
					'heading'       => 'Tekrar görüşmek isteriz',
					'body'          => "Merhaba {customer_name},\n\nSon siparişinizden bu yana bir süre geçti. Size özel kodunuz: {coupon_code} (son kullanma tarihi: {coupon_expiry}).",
					'coupon_mode'   => 'generate',
					'coupon_prefix' => 'DONUS',
					'coupon_amount' => '12',
					'coupon_min'    => '250',
					'coupon_days'   => '14',
				)
			),
			'review'  => self::mail_defaults(
				array(
					'delay'        => '7',
					'max_age_days' => '30',
					'subject'      => 'Siparişiniz nasıldı? - {site_title}',
					'heading'      => 'Görüşünüz bizim için değerli',
					'body'         => "Merhaba {customer_name},\n\n#{order_number} numaralı siparişiniz size ulaştıysa ürünleri değerlendirmenizi çok isteriz. Yorumunuz diğer müşterilere yardımcı olur.",
				)
			),
			'alerts'  => array(
				'enabled'         => 'no',
				'subject'         => 'Aradığınız ürün yeniden stokta: {product_name}',
				'heading'         => 'Ürün yeniden stokta',
				'body'            => "Merhaba,\n\n{product_name} yeniden stokta. Stoklar sınırlı olabilir:\n\n{product_link}",
				'confirm_subject' => 'Stok bildirimi isteğinizi onaylayın',
				'confirm_body'    => "{product_name} yeniden stoğa girdiğinde size bir e-posta göndermek için aşağıdaki bağlantıyı açıp onaylayın:\n\n{confirm_link}\n\nBu isteği siz yapmadıysanız e-postayı yok sayabilirsiniz.",
				'confirm_hours'   => '48',
				'batch_size'      => '50',
				'hourly_cap'      => '60',
			),
		);
	}

	/** @return array All settings with defaults filled in, one section at a time. */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = array();
		foreach ( self::defaults() as $tab => $def ) {
			$out[ $tab ] = array_merge( $def, isset( $saved[ $tab ] ) && is_array( $saved[ $tab ] ) ? $saved[ $tab ] : array() );
		}
		return $out;
	}

	/** @param string $tab Section. @param string $key Setting. @return mixed */
	public static function get( $tab, $key ) {
		$all = self::all();
		return $all[ $tab ][ $key ] ?? '';
	}

	/** @param string $tab Section. @return bool */
	public static function is_on( $tab ) {
		return 'yes' === self::get( $tab, 'enabled' );
	}

	/** Whether any automation that sends marketing e-mail is on (it needs the consent controls). */
	public static function marketing_on() {
		return self::is_on( 'cart' ) || self::is_on( 'winback' );
	}

	/** @return int Batch size for purge and clean-up jobs. */
	public static function batch_size() {
		return max( 1, (int) apply_filters( 'eru_gra_batch_size', 200 ) );
	}

	/** Test helper: merge values per section straight into the option, then follow the schedule. @param array $values Section => values. */
	public static function save_for_test( array $values ) {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		foreach ( $values as $tab => $vals ) {
			$saved[ $tab ] = array_merge( isset( $saved[ $tab ] ) && is_array( $saved[ $tab ] ) ? $saved[ $tab ] : array(), $vals );
		}
		update_option( self::OPTION, $saved, false );
		self::sync_schedules();
	}

	/**
	 * Make the recurring jobs match the switches: one 15-minute run per enabled automation, plus a daily housekeeping
	 * job while anything is on or data exists (so retention keeps running after a module is switched off).
	 */
	public static function sync_schedules() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! did_action( 'action_scheduler_init' ) ) {
			return;
		}
		$any = false;
		foreach ( self::RUNNABLE as $key ) {
			$on  = self::is_on( $key );
			$any = $any || $on;
			$has = self::has_recurring( 'eru_gra_run', array( $key ) );
			if ( $on && ! $has ) {
				as_schedule_recurring_action( time() + 5 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, 'eru_gra_run', array( $key ), 'eru-gra' );
			} elseif ( ! $on && $has ) {
				as_unschedule_all_actions( 'eru_gra_run', array( $key ), 'eru-gra' );
				as_unschedule_all_actions( 'eru_gra_run_more', array( $key ), 'eru-gra' );
			}
		}
		$any  = $any || self::is_on( 'alerts' );
		$need = $any || (bool) get_option( 'eru_gra_has_data' );
		$has  = self::has_recurring( 'eru_gra_daily', array() );
		if ( $need && ! $has ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'eru_gra_daily', array(), 'eru-gra' );
		} elseif ( ! $need && $has ) {
			as_unschedule_all_actions( 'eru_gra_daily', array(), 'eru-gra' );
		}
	}

	/** @param string $hook Hook. @param array $args Args. @return bool A pending recurring action exists. */
	private static function has_recurring( $hook, array $args ) {
		return (bool) as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => 'eru-gra',
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
	}

	/** Hooks. */
	public static function init() {
		add_filter( 'option_page_capability_' . self::GROUP, fn() => 'manage_woocommerce' );
		add_action(
			'admin_init',
			function () {
				register_setting(
					self::GROUP,
					self::OPTION,
					array(
						'type'              => 'array',
						'sanitize_callback' => array( __CLASS__, 'sanitize' ),
						'default'           => array(),
					)
				);
			}
		);
		add_action(
			'admin_init',
			function () {
				// Self-heal missing schedules (e.g. after clearing the queue). Reads nothing while everything is off and no data exists.
				if ( current_user_can( 'manage_woocommerce' ) && ( get_option( 'eru_gra_has_data' ) || get_option( self::OPTION ) ) && false === get_transient( 'eru_gra_sync' ) ) {
					set_transient( 'eru_gra_sync', 1, HOUR_IN_SECONDS );
					self::sync_schedules();
				}
			},
			30
		);
		foreach ( array( 'update_option_', 'add_option_' ) as $prefix ) {
			add_action(
				$prefix . self::OPTION,
				function () {
					wp_set_option_autoload( self::OPTION, false );
					self::sync_schedules();
				}
			);
		}
	}

	/** @param mixed $v Raw. @param int $min Min. @param int $max Max. @return string Integer clamp. */
	private static function int( $v, $min, $max ) {
		return (string) max( $min, min( $max, absint( $v ) ) );
	}

	/** @param mixed $v Raw. @return string Non-negative decimal. */
	private static function num( $v ) {
		$n = max( 0, (float) str_replace( ',', '.', (string) $v ) );
		return $n > 0 ? (string) $n : '';
	}

	/** @param array $in Posted section. @param array $out Result. @param string $tab Section. @param array $def Defaults. */
	private static function sanitize_mail_tab( array $in, array &$out, $tab, array $def ) {
		$out['enabled'] = ! empty( $in['enabled'] ) ? 'yes' : 'no';
		$days           = in_array( $tab, array( 'winback', 'review' ), true );
		$out['delay']   = self::int( $in['delay'] ?? $def['delay'], 1, $days ? 365 : 720 );
		if ( isset( $def['max_age_days'] ) ) {
			$out['max_age_days'] = self::int( $in['max_age_days'] ?? $def['max_age_days'], 1, 730 );
		}
		if ( isset( $def['cooldown'] ) ) {
			$out['cooldown'] = self::int( $in['cooldown'] ?? $def['cooldown'], 0, 8760 );
		}
		foreach ( array( 'subject', 'heading' ) as $k ) {
			$v         = sanitize_text_field( $in[ $k ] ?? '' );
			$out[ $k ] = '' !== $v ? $v : $def[ $k ];
		}
		$body        = sanitize_textarea_field( $in['body'] ?? '' );
		$out['body'] = '' !== $body ? $body : $def['body'];

		$out['coupon_mode'] = in_array( $in['coupon_mode'] ?? 'none', array( 'none', 'existing', 'generate' ), true ) ? $in['coupon_mode'] : 'none';
		$out['coupon_code'] = wc_format_coupon_code( sanitize_text_field( $in['coupon_code'] ?? '' ) );
		$prefix             = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $in['coupon_prefix'] ?? '' ) ) );
		$out['coupon_prefix'] = '' !== $prefix ? substr( $prefix, 0, 12 ) : $def['coupon_prefix'];
		$out['coupon_type'] = 'fixed_cart' === ( $in['coupon_type'] ?? 'percent' ) ? 'fixed_cart' : 'percent';
		$amount             = (float) self::num( $in['coupon_amount'] ?? 0 );
		$amount             = 'percent' === $out['coupon_type'] ? min( 100, $amount ) : $amount;
		$out['coupon_amount'] = (string) ( $amount > 0 ? $amount : $def['coupon_amount'] );
		$out['coupon_min']  = self::num( $in['coupon_min'] ?? '' );
		$out['coupon_days'] = self::int( $in['coupon_days'] ?? $def['coupon_days'], 1, 365 );
		$out['batch_size']  = self::int( $in['batch_size'] ?? 50, 1, 500 );

		if ( 'none' === $out['coupon_mode'] && false !== strpos( $out['subject'] . ' ' . $out['heading'] . ' ' . $out['body'], '{coupon_code}' ) && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPTION,
				'eru_gra_coupon_' . $tab,
				__( 'Metinde {coupon_code} geçiyor ama kupon seçeneği "Yok". Bu durumda e-posta gönderilmez; kuponu etkinleştirin ya da metinden çıkarın.', 'growth-automations-for-woocommerce' ),
				'warning'
			);
		}
	}

	/** @param mixed $in Posted settings (unslashed by options.php). @return array */
	public static function sanitize( $in ) {
		$saved = self::all();
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $saved;
		}
		$in  = is_array( $in ) ? $in : array();
		$def = self::defaults();
		$out = $saved;

		if ( isset( $in['general'] ) && is_array( $in['general'] ) ) {
			$g = $in['general'];
			foreach ( array( 'consent_text', 'optin_text' ) as $k ) {
				$v                    = sanitize_text_field( $g[ $k ] ?? '' );
				$out['general'][ $k ] = '' !== $v ? $v : $def['general'][ $k ];
			}
			$out['general']['retention_days'] = self::int( $g['retention_days'] ?? 90, 7, 3650 );
		}
		foreach ( self::RUNNABLE as $tab ) {
			if ( isset( $in[ $tab ] ) && is_array( $in[ $tab ] ) ) {
				self::sanitize_mail_tab( $in[ $tab ], $out[ $tab ], $tab, $def[ $tab ] );
			}
		}
		if ( isset( $in['alerts'] ) && is_array( $in['alerts'] ) ) {
			$a            = $in['alerts'];
			$out['alerts']['enabled'] = ! empty( $a['enabled'] ) ? 'yes' : 'no';
			foreach ( array( 'subject', 'heading', 'confirm_subject' ) as $k ) {
				$v                    = sanitize_text_field( $a[ $k ] ?? '' );
				$out['alerts'][ $k ] = '' !== $v ? $v : $def['alerts'][ $k ];
			}
			foreach ( array( 'body', 'confirm_body' ) as $k ) {
				$v                    = sanitize_textarea_field( $a[ $k ] ?? '' );
				$out['alerts'][ $k ] = '' !== $v ? $v : $def['alerts'][ $k ];
			}
			if ( false === strpos( $out['alerts']['confirm_body'], '{confirm_link}' ) ) {
				$out['alerts']['confirm_body'] = $def['alerts']['confirm_body'];
			}
			$out['alerts']['confirm_hours'] = self::int( $a['confirm_hours'] ?? 48, 1, 720 );
			$out['alerts']['batch_size']    = self::int( $a['batch_size'] ?? 50, 1, 500 );
			$out['alerts']['hourly_cap']    = self::int( $a['hourly_cap'] ?? 60, 1, 10000 );
		}
		return $out;
	}
}
