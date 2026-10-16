<?php
/**
 * WooCommerce e-mail "Ödülünüz hazır": sent to the customer when a reward matures into a coupon.
 * Listed under WooCommerce → Settings → E-mails, where it can be turned off or reworded; the templates
 * can be overridden in the theme under campaign-engine-for-woocommerce/emails/.
 *
 * Placeholders: {coupon_code}, {amount}, {expires}.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\Reward;

use Erusoft\CampaignEngine\Engine\Money;
use Erusoft\CampaignEngine\Infrastructure\Clock;
use WC_Email;

final class RewardReadyEmail extends WC_Email {

	/** @var array<string,mixed> */
	public array $reward = array();

	public function __construct() {
		$this->id             = 'eru_ce_reward_ready';
		$this->customer_email = true;
		$this->title          = __( 'Ödülünüz hazır', 'campaign-engine-for-woocommerce' );
		$this->description    = __( 'Kampanyadan kazanılan ödül kuponu kullanılabilir olduğunda müşteriye gönderilir.', 'campaign-engine-for-woocommerce' );
		$this->template_base  = ERU_CE_PATH . 'templates/';
		$this->template_html  = 'emails/eru-ce-reward-ready.php';
		$this->template_plain = 'emails/plain/eru-ce-reward-ready.php';
		$this->placeholders   = array(
			'{coupon_code}' => '',
			'{amount}'      => '',
			'{expires}'     => '',
		);

		parent::__construct();
	}

	public function get_default_subject() {
		return __( '[{site_title}] Ödülünüz hazır: {amount} indirim', 'campaign-engine-for-woocommerce' );
	}

	public function get_default_heading() {
		return __( 'Ödülünüz hazır', 'campaign-engine-for-woocommerce' );
	}

	public function get_default_additional_content() {
		return __( 'Kuponunuzu bir sonraki alışverişinizde ödeme adımında kullanabilirsiniz.', 'campaign-engine-for-woocommerce' );
	}

	/** @param array<string,mixed> $reward reward row */
	public function trigger( $reward ): void {
		if ( ! is_array( $reward ) || '' === (string) ( $reward['email'] ?? '' ) || '' === (string) ( $reward['code'] ?? '' ) ) {
			return;
		}

		$this->setup_locale();

		$this->reward    = $reward;
		$this->recipient = (string) $reward['email'];
		$expires         = Clock::ts( $reward['expires_at'] ?? null );

		$this->placeholders['{coupon_code}'] = (string) $reward['code'];
		$this->placeholders['{amount}']      = html_entity_decode( wp_strip_all_tags( wc_price( (float) Money::toDecimal( (int) $reward['amount_minor'], wc_get_price_decimals() ), array( 'currency' => (string) ( $reward['currency'] ?? '' ) ) ) ), ENT_QUOTES, 'UTF-8' );
		$this->placeholders['{expires}']     = null === $expires ? '' : wp_date( (string) get_option( 'date_format' ), $expires );

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	/** @return array<string,mixed> */
	private function args( bool $plain ): array {
		return array(
			'email_heading'      => $this->get_heading(),
			'coupon_code'        => $this->placeholders['{coupon_code}'],
			'amount'             => $this->placeholders['{amount}'],
			'expires'            => $this->placeholders['{expires}'],
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain,
			'email'              => $this,
		);
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->args( false ), 'campaign-engine-for-woocommerce/', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->args( true ), 'campaign-engine-for-woocommerce/', $this->template_base );
	}
}
