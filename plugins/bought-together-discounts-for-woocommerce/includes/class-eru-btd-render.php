<?php
/** Product page block ("Birlikte alın, %X kazanın") and the add-all-to-cart handler. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_BTD_Render {

	const TD = 'bought-together-discounts-for-woocommerce';

	public static function init(): void {
		add_action( 'woocommerce_after_add_to_cart_form', array( __CLASS__, 'print_block' ), 20 );
		add_action( 'wp_loaded', array( __CLASS__, 'handle_add' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue(): void {
		if ( function_exists( 'is_product' ) && is_product() && Eru_BTD_Sets::get( true ) ) {
			wp_enqueue_style( 'eru-btd', ERU_BTD_URL . 'assets/style.css', array(), ERU_BTD_VERSION );
		}
	}

	/** First active set that contains this product (or one of its variations), or null. */
	public static function set_for( WC_Product $product ): ?array {
		$ids = array( $product->get_id() );
		if ( $product->is_type( 'variable' ) ) {
			$ids = array_merge( $ids, $product->get_children() );
		}
		foreach ( Eru_BTD_Sets::get( true ) as $set ) {
			if ( array_intersect( $ids, array_map( 'intval', $set['products'] ) ) ) {
				return $set;
			}
		}
		return null;
	}

	/** Block HTML for the product, or '' when it belongs to no active set. */
	public static function block( WC_Product $product ): string {
		$set = self::set_for( $product );
		if ( ! $set ) {
			return '';
		}
		$items = array();
		$sum   = 0.0;
		foreach ( $set['products'] as $pid ) {
			$p = wc_get_product( (int) $pid );
			if ( ! $p || ! $p->is_purchasable() || ! $p->is_in_stock() ) {
				return ''; // The offer needs every item to be buyable.
			}
			$items[] = $p;
			$sum    += (float) wc_get_price_to_display( $p );
		}
		$discount = 'percent' === $set['mode'] ? $sum * (float) $set['value'] / 100 : min( $sum, (float) $set['value'] );
		$heading  = '' !== $set['heading'] ? $set['heading'] : __( 'Birlikte alın, {reward} kazanın', 'bought-together-discounts-for-woocommerce' );
		$heading  = str_replace( '{reward}', Eru_BTD_Sets::reward_text( $set ), $heading );

		ob_start();
		?>
		<div class="eru-btd-block">
			<h3 class="eru-btd-title"><?php echo esc_html( $heading ); ?></h3>
			<ul class="eru-btd-items">
				<?php foreach ( $items as $p ) : ?>
					<li class="eru-btd-item">
						<a href="<?php echo esc_url( get_permalink( $p->is_type( 'variation' ) ? $p->get_parent_id() : $p->get_id() ) ); ?>"><?php echo wp_kses_post( $p->get_image( 'thumbnail' ) ); ?></a>
						<span class="eru-btd-name"><?php echo esc_html( wp_strip_all_tags( $p->get_name() ) ); ?></span>
						<span class="eru-btd-price"><?php echo wp_kses_post( wc_price( wc_get_price_to_display( $p ) ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="eru-btd-total">
				<?php esc_html_e( 'Set fiyatı:', 'bought-together-discounts-for-woocommerce' ); ?>
				<del><?php echo wp_kses_post( wc_price( $sum ) ); ?></del>
				<strong><?php echo wp_kses_post( wc_price( max( 0.0, $sum - $discount ) ) ); ?></strong>
			</p>
			<form class="eru-btd-form" method="post" action="<?php echo esc_url( get_permalink( $product->get_id() ) ?: '' ); ?>">
				<?php wp_nonce_field( 'eru_btd_add_' . $set['id'], 'eru_btd_nonce' ); ?>
				<input type="hidden" name="eru_btd_set" value="<?php echo esc_attr( (string) $set['id'] ); ?>">
				<button type="submit" class="button alt eru-btd-button"><?php esc_html_e( 'Hepsini sepete ekle', 'bought-together-discounts-for-woocommerce' ); ?></button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function print_block(): void {
		global $product;
		if ( $product instanceof WC_Product ) {
			echo self::block( $product ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in block().
		}
	}

	/**
	 * Adds every item of the set, or nothing: when an item cannot be bought (or WooCommerce refuses
	 * one), lines added so far are removed and an error notice is shown.
	 */
	public static function add_set( array $set ): bool {
		$items = array();
		foreach ( (array) $set['products'] as $pid ) {
			$p = wc_get_product( (int) $pid );
			if ( ! $p || ! $p->is_purchasable() || ! $p->is_in_stock() ) {
				wc_add_notice( __( 'Setteki ürünlerden biri şu anda satın alınamıyor; set sepete eklenmedi.', 'bought-together-discounts-for-woocommerce' ), 'error' );
				return false;
			}
			$items[] = $p;
		}
		$keys = array();
		foreach ( $items as $p ) {
			$key = $p->is_type( 'variation' )
				? WC()->cart->add_to_cart( $p->get_parent_id(), 1, $p->get_id(), $p->get_variation_attributes() )
				: WC()->cart->add_to_cart( $p->get_id(), 1 );
			if ( ! $key ) {
				foreach ( $keys as $k ) {
					$line = WC()->cart->get_cart_item( $k );
					if ( $line ) {
						$qty = (int) $line['quantity'] - 1;
						$qty > 0 ? WC()->cart->set_quantity( $k, $qty, false ) : WC()->cart->remove_cart_item( $k );
					}
				}
				wc_add_notice( __( 'Set sepete eklenemedi.', 'bought-together-discounts-for-woocommerce' ), 'error' );
				return false;
			}
			$keys[] = $key;
		}
		wc_add_notice( __( 'Set ürünleri sepete eklendi.', 'bought-together-discounts-for-woocommerce' ) );
		return true;
	}

	/** POST handler: add every item of the chosen set (all or nothing), then go to the cart. */
	public static function handle_add(): void {
		if ( ! isset( $_POST['eru_btd_set'] ) || empty( $_POST['eru_btd_nonce'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$set_id = sanitize_key( wp_unslash( $_POST['eru_btd_set'] ) );
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eru_btd_nonce'] ) ), 'eru_btd_add_' . $set_id ) ) {
			return;
		}
		$set = null;
		foreach ( Eru_BTD_Sets::get( true ) as $candidate ) {
			if ( (string) $candidate['id'] === $set_id ) {
				$set = $candidate;
			}
		}
		if ( ! $set ) {
			return;
		}
		if ( ! self::add_set( $set ) ) {
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : wc_get_cart_url() );
			exit;
		}
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}
}
