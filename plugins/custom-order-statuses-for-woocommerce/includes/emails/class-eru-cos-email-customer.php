<?php
/**
 * Customer e-mail for one custom status.
 *
 * @package custom-order-statuses-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_COS_Email_Customer extends Eru_COS_Email_Base {

	public function __construct( string $slug ) {
		$this->id             = Eru_COS_Emails::key( 'customer', $slug );
		$this->customer_email = true;
		$this->status_slug    = $slug;
		$label                = $this->status()['label'];
		/* translators: %s: status name */
		$this->title = sprintf( __( 'Sipariş durumu: %s (müşteri)', 'custom-order-statuses-for-woocommerce' ), $label );
		/* translators: %s: status name */
		$this->description = sprintf( __( 'Sipariş "%s" durumuna geçtiğinde müşteriye gönderilir.', 'custom-order-statuses-for-woocommerce' ), $label );
		parent::__construct( $slug );
	}

	protected function default_body(): string {
		$label = $this->status()['label'];
		/* translators: %s: status name */
		return sprintf( __( "Merhaba {customer_name},\n\n#{order_number} numaralı siparişinizin durumu \"%s\" olarak güncellendi.", 'custom-order-statuses-for-woocommerce' ), $label );
	}

	protected function set_recipient_for( WC_Order $order ): void {
		$this->recipient = $order->get_billing_email();
	}
}
