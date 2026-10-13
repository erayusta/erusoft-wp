<?php
/** Front end: pop-up markup, assets and the "should it run on this page" decision. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_EIC_Front {

	const COOKIE = 'eru_eic_seen';

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'markup' ), 20 );
	}

	/** Active = enabled, configured and allowed on this page. Nothing is output otherwise. */
	public static function active(): bool {
		if ( is_admin() || is_feed() || is_robots() || is_embed() ) {
			return false;
		}
		$s = Eru_EIC_Settings::get();
		if ( empty( $s['enabled'] ) ) {
			return false;
		}
		if ( 'fixed' === $s['mode'] && '' === $s['code'] ) {
			return false;
		}
		if ( ! empty( $s['hide_checkout'] ) && function_exists( 'is_checkout' ) && is_checkout() ) {
			return false;
		}
		if ( ! empty( $s['hide_cart'] ) && function_exists( 'is_cart' ) && is_cart() ) {
			return false;
		}
		if ( ! empty( $s['need_cart'] ) && WC()->cart && WC()->cart->is_empty() ) {
			return false;
		}
		// The "already seen" cookie is checked in the browser only, so cached HTML stays the same for everyone.
		return (bool) apply_filters( 'eru_eic_active', true, $s );
	}

	public static function enqueue(): void {
		if ( ! self::active() ) {
			return;
		}
		wp_enqueue_style( 'eru-eic', ERU_EIC_URL . 'assets/exit-intent.css', array(), ERU_EIC_VERSION );
		wp_enqueue_script( 'eru-eic', ERU_EIC_URL . 'assets/exit-intent.js', array(), ERU_EIC_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	public static function markup(): void {
		if ( ! self::active() ) {
			return;
		}
		$s     = Eru_EIC_Settings::get();
		$fixed = 'fixed' === $s['mode'];
		$data  = array(
			'mode'         => $s['mode'],
			'cookie'       => self::COOKIE,
			'cookieDays'   => (int) $s['cookie_days'],
			'minSeconds'   => (int) $s['min_seconds'],
			'mobile'       => (int) $s['mobile'],
			'mobileDelay'  => (int) $s['mobile_delay'],
			'mobileDepth'  => (int) $s['mobile_depth'],
			'endpoint'     => $fixed ? '' : esc_url_raw( rest_url( 'eru-eic/v1/coupon' ) ),
			'copy'         => __( 'Kopyala', 'exit-intent-coupons-for-woocommerce' ),
			'copied'       => __( 'Kopyalandı', 'exit-intent-coupons-for-woocommerce' ),
			'validUntil'   => __( 'Son geçerlilik:', 'exit-intent-coupons-for-woocommerce' ),
			'unavailable'  => __( 'Şu anda kupon verilemiyor.', 'exit-intent-coupons-for-woocommerce' ),
		);
		?>
		<div id="eru-eic" class="eru-eic" hidden role="dialog" aria-modal="true" aria-labelledby="eru-eic-title" style="--eru-eic-accent:<?php echo esc_attr( $s['accent'] ); ?>" data-config="<?php echo esc_attr( wp_json_encode( $data ) ); ?>">
			<div class="eru-eic__backdrop" data-eru-eic-close></div>
			<div class="eru-eic__panel">
				<button type="button" class="eru-eic__x" data-eru-eic-close aria-label="<?php esc_attr_e( 'Kapat', 'exit-intent-coupons-for-woocommerce' ); ?>">&times;</button>
				<h2 id="eru-eic-title" class="eru-eic__title"><?php echo esc_html( $s['title'] ); ?></h2>
				<?php if ( '' !== $s['text'] ) : ?>
					<p class="eru-eic__text"><?php echo esc_html( $s['text'] ); ?></p>
				<?php endif; ?>
				<div class="eru-eic__codebox">
					<code class="eru-eic__code" data-eru-eic-code><?php echo $fixed ? esc_html( mb_strtoupper( $s['code'] ) ) : ''; ?></code>
					<button type="button" class="eru-eic__copy" data-eru-eic-copy><?php esc_html_e( 'Kopyala', 'exit-intent-coupons-for-woocommerce' ); ?></button>
				</div>
				<p class="eru-eic__meta" data-eru-eic-meta></p>
				<?php if ( '' !== $s['note'] ) : ?>
					<p class="eru-eic__note"><?php echo esc_html( $s['note'] ); ?></p>
				<?php endif; ?>
				<button type="button" class="eru-eic__cta" data-eru-eic-close><?php echo esc_html( $s['button'] ); ?></button>
			</div>
		</div>
		<?php
	}
}
