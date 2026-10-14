<?php
/**
 * Shared WC_Email behaviour for one custom status: subject/heading/body from the status, placeholders, templates.
 *
 * @package custom-order-statuses-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Eru_COS_Email_Base extends WC_Email {

	/** @var string Status slug without "wc-". */
	public string $status_slug = '';

	public function __construct( string $slug ) {
		$this->status_slug    = $slug;
		$this->template_base  = ERU_COS_PATH . 'templates/';
		$this->template_html  = 'emails/eru-cos-status.php';
		$this->template_plain = 'emails/plain/eru-cos-status.php';
		$this->placeholders   = array(
			'{order_date}'    => '',
			'{order_number}'  => '',
			'{customer_name}' => '',
			'{order_total}'   => '',
		);
		parent::__construct();
	}

	protected function status(): array {
		return Eru_COS_Statuses::get( $this->status_slug ) ?? array_merge( Eru_COS_Statuses::defaults(), array( 'slug' => $this->status_slug, 'label' => $this->status_slug ) );
	}

	/** Fills the order placeholders from the current object (also used by the e-mail preview). */
	protected function fill_placeholders(): void {
		if ( ! $this->object instanceof WC_Order ) {
			return;
		}
		$o    = $this->object;
		$name = trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
		$this->placeholders['{order_date}']    = wc_format_datetime( $o->get_date_created() );
		$this->placeholders['{order_number}']  = $o->get_order_number();
		$this->placeholders['{customer_name}'] = $name;
		$this->placeholders['{order_total}']   = html_entity_decode( wp_strip_all_tags( $o->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' );
		$this->placeholders                    = (array) apply_filters( 'eru_cos_email_placeholders', $this->placeholders, $o, $this );
	}

	public function format_string( $string ) {
		$this->fill_placeholders();
		return parent::format_string( $string );
	}

	public function get_default_subject() {
		$s = $this->status();
		/* translators: %s: status name */
		return '' !== $s['email_subject'] ? $s['email_subject'] : sprintf( __( '[{site_title}] #{order_number} siparişinin durumu: %s', 'custom-order-statuses-for-woocommerce' ), $s['label'] );
	}

	public function get_default_heading() {
		$s = $this->status();
		return '' !== $s['email_heading'] ? $s['email_heading'] : $s['label'];
	}

	/** Body text from the status settings, placeholders replaced. */
	public function get_body(): string {
		$s    = $this->status();
		$body = '' !== $s['email_body'] ? $s['email_body'] : $this->default_body();
		return $this->format_string( $body );
	}

	abstract protected function default_body(): string;

	public function trigger( $order_id, $order = false ) {
		$this->setup_locale();
		if ( $order_id && ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( $order instanceof WC_Order ) {
			$this->object = $order;
			$this->set_recipient_for( $order );
			$this->fill_placeholders();
		}
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
	}

	abstract protected function set_recipient_for( WC_Order $order ): void;

	private function template_args( bool $plain ): array {
		return array(
			'order'              => $this->object,
			'email_heading'      => $this->get_heading(),
			'body'               => $this->object instanceof WC_Order ? $this->get_body() : '',
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => ! $this->is_customer_email(),
			'plain_text'         => $plain,
			'email'              => $this,
		);
	}

	/** Theme override: <theme>/custom-order-statuses-for-woocommerce/emails/… */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), 'custom-order-statuses-for-woocommerce/', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), 'custom-order-statuses-for-woocommerce/', $this->template_base );
	}

	/** Enabled by default once the status's e-mail switch is on; WooCommerce → Settings → Emails can still turn it off. */
	public function is_enabled() {
		$s    = $this->status();
		$flag = 'email_' . ( $this->is_customer_email() ? 'customer' : 'admin' );
		return ! empty( $s[ $flag ] ) && parent::is_enabled();
	}
}
