<?php
/**
 * Live preview of the campaign being edited: the wizard posts its fields (AJAX, capability + nonce),
 * the definition is read exactly as saving would read it, and the campaign runs alone on a demo cart
 * built from real products of the store, with the store's own settings (caps, stacking, coupon rule).
 * Nothing is written.
 *
 * Demo carts: "mixed" (the first three priced products, one each), "multi" (the first product × 3),
 * "large" (the first two products × 4) and "custom" (lines typed as "id x quantity").
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Admin;

use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Context\Customer;
use Erusoft\CampaignEngine\Engine\Evaluator;
use Erusoft\CampaignEngine\Engine\Trace;
use Erusoft\CampaignEngine\Engine\UsageLimits;
use Erusoft\CampaignEngine\Infrastructure\Campaign\PostType;
use Erusoft\CampaignEngine\Infrastructure\Settings\Options;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\ContextFactory;
use Erusoft\CampaignEngine\Presentation\Texts;
use WC_Product;

final class PreviewController {

	public const ACTION = 'eru_ce_preview';
	public const NONCE  = 'eru_ce_preview';

	/** Most lines a typed demo cart may have. */
	private const MAX_LINES = 20;

	public static function hooks(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'ajax' ) );
	}

	/** @return array<string,string> demo cart id => label */
	public static function carts(): array {
		return array(
			'mixed'  => __( 'Üç farklı ürün, birer adet', 'campaign-engine-for-woocommerce' ),
			'multi'  => __( 'Tek ürün, 3 adet', 'campaign-engine-for-woocommerce' ),
			'large'  => __( 'İki ürün, dörder adet', 'campaign-engine-for-woocommerce' ),
			'custom' => __( 'Kendi sepetim (ürün no x adet)', 'campaign-engine-for-woocommerce' ),
		);
	}

	/** @return array<string,string> customer profile id => label */
	public static function customers(): array {
		return array(
			'guest'     => __( 'Misafir, ilk sipariş', 'campaign-engine-for-woocommerce' ),
			'returning' => __( 'Üye, 3 ödenmiş siparişi var', 'campaign-engine-for-woocommerce' ),
		);
	}

	/** wp_ajax handler: capability and nonce first, then the preview as JSON. */
	public static function ajax(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( PostType::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Önizleme için yetkiniz yok ya da oturum süresi doldu; sayfayı yenileyin.', 'campaign-engine-for-woocommerce' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed field by field by WizardForm.
		$input  = isset( $_POST['eru_ce'] ) && is_array( $_POST['eru_ce'] ) ? wp_unslash( $_POST['eru_ce'] ) : array();
		$parsed = WizardForm::parse( $input );

		if ( array() !== $parsed['errors'] ) {
			$messages = array();

			foreach ( $parsed['errors'] as $field => $reason ) {
				$messages[] = TraceText::error( (string) $field, (string) $reason );
			}

			wp_send_json_error( array( 'errors' => $messages ), 200 );
		}

		$cart     = isset( $_POST['cart'] ) ? sanitize_key( wp_unslash( $_POST['cart'] ) ) : 'mixed';
		$typed    = isset( $_POST['cart_lines'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cart_lines'] ) ) : '';
		$customer = isset( $_POST['customer'] ) ? sanitize_key( wp_unslash( $_POST['customer'] ) ) : 'guest';
		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$lines    = self::demoLines( $cart, $typed );

		if ( array() === $lines ) {
			wp_send_json_error( array( 'errors' => array( __( 'Önizleme sepeti boş: mağazada fiyatlı ürün bulunamadı ya da yazdığınız satırlar okunamadı ("12 x 3" biçiminde yazın).', 'campaign-engine-for-woocommerce' ) ) ), 200 );
		}

		$label = $post_id > 0 ? get_the_title( $post_id ) : '';
		$out   = self::run( $parsed['definition'], $lines, array( 'customer' => $customer, 'id' => $post_id, 'label' => $label ) );

		$out['warnings'] = WizardForm::warnings( $parsed['definition'] );

		wp_send_json_success( $out );
	}

	/**
	 * Runs one campaign definition on product lines. Pure read: nothing is stored.
	 *
	 * @param array<string,mixed>          $definition engine definition (as saved)
	 * @param array<int,array{0:int,1:int}> $lines     product id and quantity per line
	 * @param array<string,mixed>          $opts      customer (guest|returning), id, label
	 * @return array{subtotal_minor:int,total_discount_minor:int,free_shipping:bool,reward_minor:int,lines:array<int,array<string,mixed>>,trace:string[],applied:bool}
	 */
	public static function run( array $definition, array $lines, array $opts = array() ): array {
		$products = array();

		foreach ( $lines as $line ) {
			$product = wc_get_product( (int) ( $line[0] ?? 0 ) );

			if ( $product instanceof WC_Product && $product->is_purchasable() && ! $product->is_type( 'variable' ) ) {
				$products[] = array( $product, max( 1, (int) ( $line[1] ?? 1 ) ) );
			}
		}

		$customer = 'returning' === ( $opts['customer'] ?? 'guest' )
			? Customer::fromArray(
				array(
					'id'                => 1,
					'logged_in'         => true,
					'roles'             => array( 'customer' ),
					'order_count'       => 3,
					'total_spent_minor' => 0,
					'first_order_ts'    => time() - 200 * DAY_IN_SECONDS,
					'last_order_ts'     => time() - 20 * DAY_IN_SECONDS,
				)
			)
			: Customer::guest();

		$context  = ContextFactory::fromProducts( $products, $customer );
		$id       = max( 1, (int) ( $opts['id'] ?? 0 ) );
		$campaign = Campaign::fromArray( array_merge( $definition, array( 'id' => $id, 'label' => (string) ( $opts['label'] ?? '' ) ) ) );
		$trace    = new Trace();
		$result   = Evaluator::withDefaults( Options::engineSettings() )->evaluate( $context, array( $campaign ), UsageLimits::none(), $trace );
		$out      = array();
		$subtotal = 0;

		foreach ( $context->items as $index => $item ) {
			$discount  = $result->adjustments->lineDiscount( $item->key );
			$subtotal += $item->lineSubtotalMinor();
			$product   = $products[ $index ][0];
			$out[]     = array(
				'name'           => wp_strip_all_tags( $product->get_name() ),
				'qty'            => $item->qty,
				'unit_minor'     => $item->unit_minor,
				'line_minor'     => $item->lineSubtotalMinor(),
				'discount_minor' => $discount,
				'unit'           => Texts::money( $item->unit_minor ),
				'line'           => Texts::money( $item->lineSubtotalMinor() ),
				'discount'       => Texts::money( $discount ),
				'net'            => Texts::money( $item->lineSubtotalMinor() - $discount ),
			);
		}

		$reward = 0;

		foreach ( $result->adjustments->rewards() as $adjustment ) {
			$reward += (int) ( $adjustment->meta['amount_minor'] ?? 0 );
		}

		$texts = array();

		foreach ( $trace->entries() as $entry ) {
			if ( 'pass' !== $entry['status'] ) {
				$texts[] = TraceText::describe( $entry );
			}
		}

		$total = $result->adjustments->totalDiscount();

		return array(
			'subtotal_minor'       => $subtotal,
			'total_discount_minor' => $total,
			'free_shipping'        => $result->adjustments->freeShipping(),
			'reward_minor'         => $reward,
			'applied'              => in_array( $id, $result->appliedCampaignIds(), true ),
			'lines'                => $out,
			'trace'                => $texts,
			'subtotal'             => Texts::money( $subtotal ),
			'total_discount'       => Texts::money( $total ),
			'total'                => Texts::money( $subtotal - $total ),
			'reward'               => $reward > 0 ? Texts::money( $reward ) : '',
		);
	}

	/**
	 * Product lines of a demo cart.
	 *
	 * @return array<int,array{0:int,1:int}>
	 */
	public static function demoLines( string $cart, string $typed = '' ): array {
		if ( 'custom' === $cart ) {
			$lines = array();

			foreach ( preg_split( '/[\r\n,;]+/', $typed, -1, PREG_SPLIT_NO_EMPTY ) as $row ) {
				if ( count( $lines ) >= self::MAX_LINES ) {
					break;
				}

				if ( 1 === preg_match( '/^\s*(\d{1,19})\s*(?:[x×*]\s*(\d{1,4}))?\s*$/iu', $row, $m ) ) {
					$lines[] = array( (int) $m[1], isset( $m[2] ) && '' !== $m[2] ? max( 1, (int) $m[2] ) : 1 );
				}
			}

			return $lines;
		}

		$ids = wc_get_products(
			array(
				'status'   => 'publish',
				'type'     => array( 'simple' ),
				'limit'    => 3,
				'orderby'  => 'date',
				'order'    => 'DESC',
				'return'   => 'ids',
			)
		);
		$ids = array_values( array_map( 'intval', (array) $ids ) );

		switch ( $cart ) {
			case 'multi':
				return isset( $ids[0] ) ? array( array( $ids[0], 3 ) ) : array();
			case 'large':
				return array_map( static fn( int $id ): array => array( $id, 4 ), array_slice( $ids, 0, 2 ) );
			default:
				return array_map( static fn( int $id ): array => array( $id, 1 ), $ids );
		}
	}
}
