<?php
/**
 * WooCommerce > Settings > Integration > "Sunucu Tarafı İadeler".
 *
 * @package server-side-refunds-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings screen. Secrets are write-only: never rendered, blank keeps the stored value.
 */
class Eru_SSR_Integration extends WC_Integration {

	public function __construct() {
		$this->id                 = 'eru_ssr';
		$this->method_title       = __( 'Sunucu Tarafı İadeler', 'server-side-refunds-for-woocommerce' );
		$this->method_description = __( 'İade ve iptal olaylarını GA4 ve/veya Matomo\'ya sunucudan, kuyrukla bildirir.', 'server-side-refunds-for-woocommerce' );
		$this->init_form_fields();
		$this->init_settings();
		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'keep_autoload_off' ), 20 );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'ga4_title'          => array( 'title' => __( 'Google Analytics 4', 'server-side-refunds-for-woocommerce' ), 'type' => 'title' ),
			'ga4_enabled'        => array( 'title' => __( 'GA4\'e gönder', 'server-side-refunds-for-woocommerce' ), 'type' => 'checkbox', 'label' => __( 'Etkin', 'server-side-refunds-for-woocommerce' ), 'default' => 'no' ),
			'ga4_measurement_id' => array( 'title' => __( 'Ölçüm Kimliği', 'server-side-refunds-for-woocommerce' ), 'type' => 'text', 'description' => __( 'Google Analytics veri akışındaki "G-" ile başlayan kimlik.', 'server-side-refunds-for-woocommerce' ), 'desc_tip' => true, 'default' => '' ),
			'ga4_api_secret'     => array( 'title' => __( 'API gizli anahtarı', 'server-side-refunds-for-woocommerce' ), 'type' => 'secret', 'description' => __( 'GA4 > Yönetici > Veri akışları > Measurement Protocol API gizli anahtarları.', 'server-side-refunds-for-woocommerce' ), 'default' => '' ),
			'matomo_title'       => array( 'title' => __( 'Matomo', 'server-side-refunds-for-woocommerce' ), 'type' => 'title' ),
			'matomo_enabled'     => array( 'title' => __( 'Matomo\'ya gönder', 'server-side-refunds-for-woocommerce' ), 'type' => 'checkbox', 'label' => __( 'Etkin', 'server-side-refunds-for-woocommerce' ), 'default' => 'no' ),
			'matomo_url'         => array( 'title' => __( 'İzleme adresi', 'server-side-refunds-for-woocommerce' ), 'type' => 'text', 'description' => __( 'Matomo kurulumunuzun adresi. https olmalıdır (yalnızca localhost için http kabul edilir).', 'server-side-refunds-for-woocommerce' ), 'desc_tip' => true, 'placeholder' => 'https://analytics.example.com/', 'default' => '' ),
			'matomo_site_id'     => array( 'title' => __( 'Site kimliği', 'server-side-refunds-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 1, 'step' => 1 ), 'default' => '' ),
			'matomo_token'       => array( 'title' => __( 'token_auth', 'server-side-refunds-for-woocommerce' ), 'type' => 'secret', 'description' => __( 'Yazma yetkili bir token_auth. İstek gövdesinde gönderilir, adrese yazılmaz.', 'server-side-refunds-for-woocommerce' ), 'default' => '' ),
			'matomo_mode'        => array(
				'title'   => __( 'Gönderim kipi', 'server-side-refunds-for-woocommerce' ),
				'type'    => 'select',
				'default' => 'a',
				'options' => array(
					'a' => __( 'Mod A: negatif gelirli e-ticaret siparişi', 'server-side-refunds-for-woocommerce' ),
					'b' => __( 'Mod B: özel olay', 'server-side-refunds-for-woocommerce' ),
				),
				'description' => __( 'Mod A her iadeyi Matomo\'da yeni bir sipariş olarak sayar (sipariş sayısı artar). Mod B yalnızca "ecommerce / refund" olayı yazar.', 'server-side-refunds-for-woocommerce' ),
				'desc_tip' => true,
			),
			'events_title'       => array( 'title' => __( 'Olaylar', 'server-side-refunds-for-woocommerce' ), 'type' => 'title' ),
			'ev_partial'         => array( 'title' => __( 'Kısmi iade', 'server-side-refunds-for-woocommerce' ), 'type' => 'checkbox', 'label' => __( 'Bildir', 'server-side-refunds-for-woocommerce' ), 'default' => 'yes' ),
			'ev_full'            => array( 'title' => __( 'Tam iade', 'server-side-refunds-for-woocommerce' ), 'type' => 'checkbox', 'label' => __( 'Bildir', 'server-side-refunds-for-woocommerce' ), 'default' => 'yes' ),
			'ev_cancelled'       => array( 'title' => __( 'Ödemeden sonra iptal', 'server-side-refunds-for-woocommerce' ), 'type' => 'checkbox', 'label' => __( 'Bildir (kalan tutar)', 'server-side-refunds-for-woocommerce' ), 'default' => 'yes' ),
			'ev_failed'          => array( 'title' => __( 'Ödemeden sonra başarısız', 'server-side-refunds-for-woocommerce' ), 'type' => 'checkbox', 'label' => __( 'Bildir (kalan tutar)', 'server-side-refunds-for-woocommerce' ), 'default' => 'yes' ),
			'other_title'        => array( 'title' => __( 'Diğer', 'server-side-refunds-for-woocommerce' ), 'type' => 'title' ),
			'send_user_id'       => array( 'title' => __( 'Kullanıcı kimliği', 'server-side-refunds-for-woocommerce' ), 'type' => 'checkbox', 'label' => __( 'Üye siparişlerinde WordPress kullanıcı kimliğini gönder', 'server-side-refunds-for-woocommerce' ), 'description' => __( 'Takma adlı bir kimliktir; ziyaretçilerinizi bilgilendirdiğinizden emin olun.', 'server-side-refunds-for-woocommerce' ), 'default' => 'no' ),
			'fallback_client_id' => array( 'title' => __( 'Yedek client_id', 'server-side-refunds-for-woocommerce' ), 'type' => 'checkbox', 'label' => __( '_ga çerezi yoksa <sipariş no>.<oluşturma zamanı> kullan', 'server-side-refunds-for-woocommerce' ), 'default' => 'yes' ),
			'retention_days'     => array( 'title' => __( 'Kuyruk saklama (gün)', 'server-side-refunds-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 1, 'step' => 1 ), 'description' => __( 'Gönderilmiş ve başarısız kayıtlar bu süreden sonra günlük olarak silinir.', 'server-side-refunds-for-woocommerce' ), 'desc_tip' => true, 'default' => '30' ),
			'test'               => array( 'title' => __( 'Test gönderimi', 'server-side-refunds-for-woocommerce' ), 'type' => 'test_button' ),
		);
	}

	/** Write-only secret field: renders empty with a hint. */
	public function generate_secret_html( $key, $data ) {
		$field = $this->get_field_key( $key );
		$set   = '' !== (string) $this->get_option( $key );
		$data  = wp_parse_args( $data, array( 'title' => '', 'description' => '', 'default' => '' ) );
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $data['title'] ); ?></label></th>
			<td class="forminp">
				<input class="input-text regular-input" type="password" autocomplete="new-password" name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $field ); ?>" value="" placeholder="<?php echo $set ? '••••••••' : ''; ?>" />
				<?php if ( $set ) : ?>
					<span class="description"><strong><?php esc_html_e( 'Kayıtlı', 'server-side-refunds-for-woocommerce' ); ?></strong> — <?php esc_html_e( 'değiştirmek için yeni değer girin, boş bırakırsanız korunur.', 'server-side-refunds-for-woocommerce' ); ?></span>
				<?php endif; ?>
				<?php if ( $data['description'] ) : ?>
					<p class="description"><?php echo esc_html( $data['description'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/** Blank keeps the stored secret. */
	public function validate_secret_field( $key, $value ) {
		$value = is_null( $value ) ? '' : trim( wp_unslash( (string) $value ) );
		return '' === $value ? (string) $this->get_option( $key ) : sanitize_text_field( $value );
	}

	public function validate_matomo_url_field( $key, $value ) {
		$value = esc_url_raw( trim( (string) $value ) );
		if ( '' !== $value && ! Eru_SSR_Settings::valid_matomo_url( $value ) ) {
			WC_Admin_Settings::add_error( __( 'Matomo adresi https olmalıdır (yalnızca localhost için http). Alan kaydedilmedi.', 'server-side-refunds-for-woocommerce' ) );
			return (string) $this->get_option( $key );
		}
		return $value;
	}

	public function validate_matomo_mode_field( $key, $value ) {
		return 'b' === $value ? 'b' : 'a';
	}

	public function validate_retention_days_field( $key, $value ) {
		return (string) max( 1, (int) $value );
	}

	/** Test buttons are driven by assets/admin.js (admin-ajax, nonce, textContent). */
	public function generate_test_button_html( $key, $data ) {
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<p>
					<button type="button" class="button eru-ssr-test" data-dest="ga4"><?php esc_html_e( 'GA4 test gönderimi', 'server-side-refunds-for-woocommerce' ); ?></button>
					<button type="button" class="button eru-ssr-test" data-dest="matomo"><?php esc_html_e( 'Matomo test gönderimi', 'server-side-refunds-for-woocommerce' ); ?></button>
				</p>
				<p class="description"><?php esc_html_e( 'Kayıtlı ayarları kullanır; önce kaydedin. GA4 için doğrulama (debug) uç noktası, Matomo için geçersiz site kimlikli bir toplu istek kullanılır; hiçbir veri kaydedilmez.', 'server-side-refunds-for-woocommerce' ); ?></p>
				<ul class="eru-ssr-test-result" aria-live="polite"></ul>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/** WooCommerce saves with autoload on; credentials do not belong in the autoload set. */
	public function keep_autoload_off() {
		wp_set_option_autoload( $this->get_option_key(), false );
	}
}
