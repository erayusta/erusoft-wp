<?php
/**
 * Inactive block payment method type.
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Always inactive in 1.0.0: the block checkout never lists Vinov.
 */
final class Eru_VNV_Blocks_Method extends AbstractPaymentMethodType {

	/** @var string Payment method name. */
	protected $name = 'eru_vnv';

	/** Nothing to load. */
	public function initialize() {
		$this->settings = array();
	}

	/** @return bool Never active in the block checkout. */
	public function is_active() {
		return false;
	}

	/** @return string[] No script. */
	public function get_payment_method_script_handles() {
		return array();
	}

	/** @return array Data for the editor. */
	public function get_payment_method_data() {
		return array(
			'title'       => __( 'VakıfBank Vinov', 'vakifbank-vinov-integration-for-woocommerce' ),
			'description' => __( 'Vinov yalnızca klasik ödeme sayfasında kullanılabilir.', 'vakifbank-vinov-integration-for-woocommerce' ),
			'supports'    => array( 'products' ),
		);
	}
}
