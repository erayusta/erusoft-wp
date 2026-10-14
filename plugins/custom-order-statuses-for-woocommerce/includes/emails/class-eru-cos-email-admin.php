<?php
/**
 * Admin e-mail for one custom status (recipient editable in WooCommerce → Settings → Emails).
 *
 * @package custom-order-statuses-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_COS_Email_Admin extends Eru_COS_Email_Base {

	public function __construct( string $slug ) {
		$this->id          = Eru_COS_Emails::key( 'admin', $slug );
		$this->status_slug = $slug;
		$label             = $this->status()['label'];
		/* translators: %s: status name */
		$this->title = sprintf( __( 'Sipariş durumu: %s (yönetici)', 'custom-order-statuses-for-woocommerce' ), $label );
		/* translators: %s: status name */
		$this->description = sprintf( __( 'Sipariş "%s" durumuna geçtiğinde yöneticiye gönderilir.', 'custom-order-statuses-for-woocommerce' ), $label );
		parent::__construct( $slug );
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	protected function default_body(): string {
		$label = $this->status()['label'];
		/* translators: %s: status name */
		return sprintf( __( '#{order_number} numaralı sipariş ({customer_name}, {order_total}) "%s" durumuna geçti.', 'custom-order-statuses-for-woocommerce' ), $label );
	}

	protected function set_recipient_for( WC_Order $order ): void {
		// Recipient comes from the e-mail settings.
	}

	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields = array_slice( $this->form_fields, 0, 1, true ) + array(
			'recipient' => array(
				'title'       => __( 'Alıcı(lar)', 'custom-order-statuses-for-woocommerce' ),
				'type'        => 'text',
				/* translators: %s: admin e-mail */
				'description' => sprintf( __( 'Virgülle ayrılmış e-posta adresleri. Varsayılan: %s.', 'custom-order-statuses-for-woocommerce' ), '<code>' . esc_html( get_option( 'admin_email' ) ) . '</code>' ),
				'placeholder' => '',
				'default'     => '',
				'desc_tip'    => true,
			),
		) + array_slice( $this->form_fields, 1, null, true );
	}
}
