<?php
/**
 * The four-step wizard (Tür → Koşullar → Ödül → Zamanlama ve sınırlar) and the preview box on the
 * campaign editor. All four steps are one form: switching steps never loses input, and without
 * JavaScript the steps are simply shown one under the other. The condition builder writes the JSON
 * field; the JSON stays editable under "Gelişmiş".
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Admin;

use Erusoft\CampaignEngine\Infrastructure\Campaign\CampaignWriter;
use Erusoft\CampaignEngine\Presentation\Texts;
use WP_Post;

final class WizardView {

	/** @var array<string,string> errors of a refused save */
	private static array $errors = array();

	/** @var array<string,mixed> values shown in the form */
	private static array $values = array();

	public static function render( WP_Post $post ): void {
		$refused = CampaignScreen::refused();
		$stored  = get_post_meta( $post->ID, CampaignWriter::META, true );

		self::$errors = null !== $refused ? $refused['errors'] : array();
		self::$values = null !== $refused ? $refused['input'] : WizardForm::fromDefinition( is_array( $stored ) ? $stored : array() );

		wp_nonce_field( 'eru_ce_wizard_' . $post->ID, CampaignScreen::NONCE_FIELD );

		$steps = array(
			1 => __( 'Tür', 'campaign-engine-for-woocommerce' ),
			2 => __( 'Koşullar', 'campaign-engine-for-woocommerce' ),
			3 => __( 'Ödül', 'campaign-engine-for-woocommerce' ),
			4 => __( 'Zamanlama ve sınırlar', 'campaign-engine-for-woocommerce' ),
		);
		$start = isset( $_GET['eru_ce_step'] ) ? max( 1, min( 4, absint( $_GET['eru_ce_step'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="eru-ce-wizard" data-start="' . esc_attr( (string) $start ) . '">';
		echo '<ol class="eru-ce-steps">';

		foreach ( $steps as $n => $label ) {
			echo '<li><button type="button" class="eru-ce-step-tab" data-step="' . esc_attr( (string) $n ) . '"><span class="eru-ce-step-no">' . esc_html( (string) $n ) . '</span> ' . esc_html( $label ) . '</button></li>';
		}

		echo '</ol>';

		self::stepType();
		self::stepConditions();
		self::stepReward();
		self::stepSchedule();

		echo '<div class="eru-ce-nav"><button type="button" class="button eru-ce-prev">' . esc_html__( '← Geri', 'campaign-engine-for-woocommerce' ) . '</button> <button type="button" class="button button-primary eru-ce-next">' . esc_html__( 'İleri →', 'campaign-engine-for-woocommerce' ) . '</button></div>';
		echo '</div>';
	}

	private static function stepType(): void {
		$type = (string) ( self::$values['type'] ?? 'product_percent' );

		self::open( 1, __( 'Kampanya ne yapsın?', 'campaign-engine-for-woocommerce' ) );
		echo '<div class="eru-ce-types">';

		foreach ( Texts::typeLabels() as $value => $label ) {
			echo '<label class="eru-ce-type"><input type="radio" name="eru_ce[type]" value="' . esc_attr( $value ) . '"' . checked( $type, $value, false ) . '> <span>' . esc_html( $label ) . '</span></label>';
		}

		echo '</div>';
		self::error( 'type' );
		echo '<p class="description">' . esc_html__( 'Hazır bir şablonla başlamak için Kampanyalar → Yeni kampanya ekranını kullanın.', 'campaign-engine-for-woocommerce' ) . '</p>';
		self::close();
	}

	private static function stepConditions(): void {
		self::open( 2, __( 'Hangi ürünlerde ve hangi koşulda?', 'campaign-engine-for-woocommerce' ) );

		echo '<div class="eru-ce-grid">';
		self::products( 'include_products', __( 'Hedef ürünler', 'campaign-engine-for-woocommerce' ), __( 'Boş bırakılırsa ve kategori seçilmezse kampanya bütün ürünlerde geçerlidir.', 'campaign-engine-for-woocommerce' ) );
		self::terms( 'include_terms', __( 'Hedef kategoriler', 'campaign-engine-for-woocommerce' ) );
		self::products( 'exclude_products', __( 'Hariç ürünler', 'campaign-engine-for-woocommerce' ), '' );
		self::terms( 'exclude_terms', __( 'Hariç kategoriler', 'campaign-engine-for-woocommerce' ) );
		self::text( 'sku', __( 'Hedef stok kodları (virgülle)', 'campaign-engine-for-woocommerce' ) );
		self::text( 'exclude_sku', __( 'Hariç stok kodları (virgülle)', 'campaign-engine-for-woocommerce' ) );
		echo '</div>';

		self::select(
			'basis',
			__( 'İndirim hangi fiyattan hesaplansın?', 'campaign-engine-for-woocommerce' ),
			array(
				'current'      => __( 'Güncel satış fiyatından', 'campaign-engine-for-woocommerce' ),
				'regular'      => __( 'Normal fiyattan (indirimli ürünlerde yalnızca fark verilir)', 'campaign-engine-for-woocommerce' ),
				'exclude_sale' => __( 'İndirimdeki ürünlere uygulanmasın', 'campaign-engine-for-woocommerce' ),
			)
		);

		echo '<h4>' . esc_html__( 'Koşullar', 'campaign-engine-for-woocommerce' ) . '</h4>';
		echo '<p class="description">' . esc_html__( 'Koşul eklemezseniz kampanya hedef ürünler sepete girdiğinde uygulanır. Tutarlar mağaza para biriminde yazılır.', 'campaign-engine-for-woocommerce' ) . '</p>';
		echo '<div class="eru-ce-builder" aria-live="polite"></div>';
		self::error( 'rules' );
		echo '<details class="eru-ce-json"><summary>' . esc_html__( 'Gelişmiş: koşulları JSON olarak düzenle', 'campaign-engine-for-woocommerce' ) . '</summary>';
		echo '<textarea name="eru_ce[rules_json]" rows="6" class="large-text code eru-ce-rules-json">' . esc_textarea( (string) ( self::$values['rules_json'] ?? '' ) ) . '</textarea>';
		echo '</details>';
		self::close();
	}

	private static function stepReward(): void {
		$tiered = is_array( self::$values['tiered'] ?? null ) ? self::$values['tiered'] : array();
		$set    = is_array( self::$values['set'] ?? null ) ? self::$values['set'] : array();
		$future = is_array( self::$values['future'] ?? null ) ? self::$values['future'] : array();
		$type   = (string) ( self::$values['type'] ?? '' );

		self::open( 3, __( 'Müşteri ne kazanır?', 'campaign-engine-for-woocommerce' ) );

		echo '<div class="eru-ce-for" data-for="product_percent cart_percent">';
		self::text( 'percent', __( 'İndirim yüzdesi (%)', 'campaign-engine-for-woocommerce' ), 'small-text', 'decimal' );
		echo '</div>';

		echo '<div class="eru-ce-for" data-for="product_fixed cart_fixed">';
		self::text( 'amount', __( 'İndirim tutarı (ürün türünde adet başına, sepet türünde sepete)', 'campaign-engine-for-woocommerce' ), 'regular-text', 'decimal' );
		echo '</div>';

		echo '<div class="eru-ce-for" data-for="product_price">';
		self::text( 'price', __( 'Kampanya fiyatı (adet başına)', 'campaign-engine-for-woocommerce' ), 'regular-text', 'decimal' );
		echo '</div>';

		echo '<div class="eru-ce-for" data-for="tiered">';
		echo '<div class="eru-ce-grid">';
		self::selectRaw(
			'eru_ce[tiered][measure]',
			(string) ( $tiered['measure'] ?? 'quantity' ),
			__( 'Kademeyi belirleyen', 'campaign-engine-for-woocommerce' ),
			array(
				'quantity' => __( 'Adet', 'campaign-engine-for-woocommerce' ),
				'subtotal' => __( 'Tutar', 'campaign-engine-for-woocommerce' ),
			)
		);
		self::selectRaw(
			'eru_ce[tiered][mode]',
			(string) ( $tiered['mode'] ?? 'all_units' ),
			__( 'Uygulama', 'campaign-engine-for-woocommerce' ),
			array(
				'all_units'   => __( 'Ulaşılan kademe bütün adetlere', 'campaign-engine-for-woocommerce' ),
				'incremental' => __( 'Her kademe kendi adetlerine (yalnızca adet)', 'campaign-engine-for-woocommerce' ),
			)
		);
		self::selectRaw(
			'eru_ce[tiered][group]',
			(string) ( $tiered['group'] ?? 'product' ),
			__( 'Sayım', 'campaign-engine-for-woocommerce' ),
			array(
				'product' => __( 'Her ürün ayrı sayılır', 'campaign-engine-for-woocommerce' ),
				'pool'    => __( 'Hedef ürünler birlikte sayılır', 'campaign-engine-for-woocommerce' ),
			)
		);
		echo '</div>';
		echo '<table class="widefat eru-ce-tiers"><thead><tr><th>' . esc_html__( 'En az', 'campaign-engine-for-woocommerce' ) . '</th><th>' . esc_html__( 'En çok', 'campaign-engine-for-woocommerce' ) . '</th><th>' . esc_html__( 'Tür', 'campaign-engine-for-woocommerce' ) . '</th><th>' . esc_html__( 'Değer', 'campaign-engine-for-woocommerce' ) . '</th><th>' . esc_html__( 'Sabit tutar', 'campaign-engine-for-woocommerce' ) . '</th></tr></thead><tbody>';
		$rows = array_values( (array) ( $tiered['rows'] ?? array() ) );

		while ( count( $rows ) < 5 ) {
			$rows[] = array();
		}

		foreach ( $rows as $i => $row ) {
			$row  = is_array( $row ) ? $row : array();
			$base = 'eru_ce[tiered][rows][' . $i . ']';
			echo '<tr>';
			echo '<td><input type="text" inputmode="decimal" class="small-text" name="' . esc_attr( $base . '[min]' ) . '" value="' . esc_attr( (string) ( $row['min'] ?? '' ) ) . '"></td>';
			echo '<td><input type="text" inputmode="decimal" class="small-text" name="' . esc_attr( $base . '[max]' ) . '" value="' . esc_attr( (string) ( $row['max'] ?? '' ) ) . '"></td>';
			echo '<td><select name="' . esc_attr( $base . '[type]' ) . '"><option value="percent"' . selected( $row['type'] ?? 'percent', 'percent', false ) . '>%</option><option value="fixed"' . selected( $row['type'] ?? '', 'fixed', false ) . '>' . esc_html( get_woocommerce_currency_symbol() ) . '</option></select></td>';
			echo '<td><input type="text" inputmode="decimal" class="small-text" name="' . esc_attr( $base . '[value]' ) . '" value="' . esc_attr( (string) ( $row['value'] ?? '' ) ) . '"></td>';
			echo '<td><select name="' . esc_attr( $base . '[per]' ) . '"><option value="group"' . selected( $row['per'] ?? 'group', 'group', false ) . '>' . esc_html__( 'Gruba bir kez', 'campaign-engine-for-woocommerce' ) . '</option><option value="unit"' . selected( $row['per'] ?? '', 'unit', false ) . '>' . esc_html__( 'Her adede', 'campaign-engine-for-woocommerce' ) . '</option></select></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		self::error( 'tiers' );
		echo '<p class="description">' . esc_html__( 'Boş satırlar yok sayılır. Tutar ölçüsünde "En az" mağaza para birimindedir.', 'campaign-engine-for-woocommerce' ) . '</p>';
		echo '</div>';

		echo '<div class="eru-ce-for" data-for="set_reward"><div class="eru-ce-grid">';
		self::textRaw( 'eru_ce[set][set_size]', (string) ( $set['set_size'] ?? '3' ), __( 'Set adedi (kaç üründe bir)', 'campaign-engine-for-woocommerce' ), 'set_size', 'small-text', 'numeric' );
		self::textRaw( 'eru_ce[set][reward_count]', (string) ( $set['reward_count'] ?? '1' ), __( 'Ödüllü adet (0 = setin tamamı)', 'campaign-engine-for-woocommerce' ), 'reward_count', 'small-text', 'numeric' );
		self::selectRaw(
			'eru_ce[set][reward]',
			(string) ( $set['reward'] ?? 'free' ),
			__( 'Ödül', 'campaign-engine-for-woocommerce' ),
			array(
				'free'    => __( 'Ücretsiz', 'campaign-engine-for-woocommerce' ),
				'percent' => __( 'Yüzde indirim', 'campaign-engine-for-woocommerce' ),
				'fixed'   => __( 'Sabit tutar indirim', 'campaign-engine-for-woocommerce' ),
			)
		);
		self::textRaw( 'eru_ce[set][value]', (string) ( $set['value'] ?? '' ), __( 'Ödül değeri (yüzde ya da tutar)', 'campaign-engine-for-woocommerce' ), 'set_reward' === $type ? 'value' : 'set_value', 'small-text', 'decimal' );
		self::selectRaw(
			'eru_ce[set][target]',
			(string) ( $set['target'] ?? 'cheapest' ),
			__( 'Ödüllü ürün', 'campaign-engine-for-woocommerce' ),
			array(
				'cheapest'       => __( 'Setteki en ucuz', 'campaign-engine-for-woocommerce' ),
				'most_expensive' => __( 'Setteki en pahalı', 'campaign-engine-for-woocommerce' ),
			)
		);
		self::selectRaw(
			'eru_ce[set][pool]',
			(string) ( $set['pool'] ?? 'product' ),
			__( 'Set nasıl dolar', 'campaign-engine-for-woocommerce' ),
			array(
				'product' => __( 'Aynı üründen', 'campaign-engine-for-woocommerce' ),
				'mixed'   => __( 'Hedef ürünlerin karışımından', 'campaign-engine-for-woocommerce' ),
			)
		);
		self::selectRaw(
			'eru_ce[set][repeat]',
			(string) ( $set['repeat'] ?? 'every' ),
			__( 'Tekrar', 'campaign-engine-for-woocommerce' ),
			array(
				'every' => __( 'Her tamamlanan sette', 'campaign-engine-for-woocommerce' ),
				'once'  => __( 'Sepet başına bir kez', 'campaign-engine-for-woocommerce' ),
			)
		);
		echo '</div>';
		echo '<input type="hidden" name="eru_ce[set][reward_products]" value="' . esc_attr( implode( ',', array_map( 'strval', (array) ( $set['reward_products'] ?? array() ) ) ) ) . '">';
		echo '</div>';

		echo '<div class="eru-ce-for" data-for="future_reward"><div class="eru-ce-grid">';
		self::selectRaw(
			'eru_ce[future][reward]',
			(string) ( $future['reward'] ?? 'fixed' ),
			__( 'Kupon türü', 'campaign-engine-for-woocommerce' ),
			array(
				'fixed'   => __( 'Sabit tutar', 'campaign-engine-for-woocommerce' ),
				'percent' => __( 'Bu sepetin yüzdesi kadar tutar', 'campaign-engine-for-woocommerce' ),
			)
		);
		self::textRaw( 'eru_ce[future][value]', (string) ( $future['value'] ?? '' ), __( 'Değer', 'campaign-engine-for-woocommerce' ), 'future_reward' === $type ? 'value' : 'future_value', 'small-text', 'decimal' );
		self::textRaw( 'eru_ce[future][min_cart]', (string) ( $future['min_cart'] ?? '' ), __( 'Kuponun kullanılacağı en düşük sepet', 'campaign-engine-for-woocommerce' ), 'min_cart', 'regular-text', 'decimal' );
		self::textRaw( 'eru_ce[future][valid_days]', (string) ( $future['valid_days'] ?? '' ), __( 'Kupon kaç gün geçerli (boş = ayarlardaki süre)', 'campaign-engine-for-woocommerce' ), 'valid_days', 'small-text', 'numeric' );
		echo '</div>';
		echo '<input type="hidden" name="eru_ce[future][category_ids]" value="' . esc_attr( implode( ',', array_map( 'strval', (array) ( $future['category_ids'] ?? array() ) ) ) ) . '">';
		echo '<p class="description">' . esc_html__( 'Kupon, sipariş ödendikten ve ayarlardaki bekleme süresi geçtikten sonra müşteriye e-postayla gönderilir; Hesabım → Ödüllerim sekmesi Ayarlar ekranından açılır.', 'campaign-engine-for-woocommerce' ) . '</p>';
		echo '</div>';

		echo '<div class="eru-ce-for" data-for="free_shipping"><p class="description">' . esc_html__( 'Koşullar sağlandığında sepetteki kargo yöntemlerinin ücreti sıfırlanır.', 'campaign-engine-for-woocommerce' ) . '</p></div>';

		echo '<p class="eru-ce-not-for" data-for="free_shipping">';
		self::checkbox( 'also_free_shipping', __( 'Ayrıca kargo da ücretsiz olsun', 'campaign-engine-for-woocommerce' ) );
		echo '</p>';

		self::text( 'badge', __( 'Rozet metni (boşsa kampanyadan üretilir)', 'campaign-engine-for-woocommerce' ) );
		self::textarea( 'description', __( 'Kampanya açıklaması (kampanya sayfasında görünür)', 'campaign-engine-for-woocommerce' ) );
		echo '<input type="hidden" name="eru_ce[extra_actions]" value="' . esc_attr( (string) ( self::$values['extra_actions'] ?? '' ) ) . '">';
		echo '<input type="hidden" name="eru_ce[extra_meta]" value="' . esc_attr( (string) ( self::$values['extra_meta'] ?? '' ) ) . '">';
		self::error( 'extra_actions' );
		self::close();
	}

	private static function stepSchedule(): void {
		$rec  = is_array( self::$values['recurrence'] ?? null ) ? self::$values['recurrence'] : array();
		$days = array(
			'MO' => __( 'Pzt', 'campaign-engine-for-woocommerce' ),
			'TU' => __( 'Sal', 'campaign-engine-for-woocommerce' ),
			'WE' => __( 'Çar', 'campaign-engine-for-woocommerce' ),
			'TH' => __( 'Per', 'campaign-engine-for-woocommerce' ),
			'FR' => __( 'Cum', 'campaign-engine-for-woocommerce' ),
			'SA' => __( 'Cmt', 'campaign-engine-for-woocommerce' ),
			'SU' => __( 'Paz', 'campaign-engine-for-woocommerce' ),
		);

		self::open( 4, __( 'Ne zaman ve ne kadar?', 'campaign-engine-for-woocommerce' ) );
		echo '<div class="eru-ce-grid">';
		self::text( 'starts_at', __( 'Başlangıç', 'campaign-engine-for-woocommerce' ), 'regular-text', '', 'datetime-local' );
		self::text( 'ends_at', __( 'Bitiş', 'campaign-engine-for-woocommerce' ), 'regular-text', '', 'datetime-local' );
		echo '</div>';
		/* translators: %s: time zone name */
		echo '<p class="description">' . esc_html( sprintf( __( 'Saatler mağaza saat dilimindedir (%s). Ürün sayfası ve kampanya sayfasındaki geri sayım yalnızca bitiş zamanı girilmiş kampanyalarda, bu zamana göre gösterilir.', 'campaign-engine-for-woocommerce' ), wp_timezone_string() ) ) . '</p>';
		echo '<input type="hidden" name="eru_ce[flash]" value="' . esc_attr( (string) ( self::$values['flash'] ?? '' ) ) . '">';

		self::selectRaw(
			'eru_ce[recurrence][freq]',
			(string) ( $rec['freq'] ?? 'none' ),
			__( 'Tekrar eden saatler', 'campaign-engine-for-woocommerce' ),
			array(
				'none'        => __( 'Yok: tarih aralığının tamamında', 'campaign-engine-for-woocommerce' ),
				'daily'       => __( 'Her gün belirli saatlerde', 'campaign-engine-for-woocommerce' ),
				'weekly'      => __( 'Haftanın seçili günlerinde', 'campaign-engine-for-woocommerce' ),
				'weekly_span' => __( 'Haftalık aralık (ör. Cuma 18:00 – Pazar 23:59)', 'campaign-engine-for-woocommerce' ),
				'monthly'     => __( 'Ayın seçili günlerinde', 'campaign-engine-for-woocommerce' ),
			),
			'recurrence'
		);
		echo '<div class="eru-ce-rec eru-ce-grid">';
		self::textRaw( 'eru_ce[recurrence][start]', (string) ( $rec['start'] ?? '' ), __( 'Başlangıç saati (SS:DD)', 'campaign-engine-for-woocommerce' ), 'recurrence_start', 'small-text', '', 'time' );
		self::textRaw( 'eru_ce[recurrence][end]', (string) ( $rec['end'] ?? '' ), __( 'Bitiş saati (dahil)', 'campaign-engine-for-woocommerce' ), 'recurrence_end', 'small-text', '', 'time' );
		echo '<fieldset><legend>' . esc_html__( 'Günler (haftalık)', 'campaign-engine-for-woocommerce' ) . '</legend>';
		$chosen = 'weekly' === ( $rec['freq'] ?? '' ) ? array_map( 'strval', (array) ( $rec['days'] ?? array() ) ) : array();

		foreach ( $days as $code => $label ) {
			echo '<label class="eru-ce-day"><input type="checkbox" name="eru_ce[recurrence][days][]" value="' . esc_attr( $code ) . '"' . checked( in_array( $code, $chosen, true ), true, false ) . '> ' . esc_html( $label ) . '</label> ';
		}

		echo '</fieldset>';
		self::textRaw( 'eru_ce[recurrence][month_days]', (string) ( $rec['month_days'] ?? '' ), __( 'Ayın günleri (aylık, virgülle: 1, 15)', 'campaign-engine-for-woocommerce' ), 'recurrence_month_days' );
		$span_days = array_combine( array_keys( $days ), array_values( $days ) );
		self::selectRaw( 'eru_ce[recurrence][from_day]', (string) ( $rec['from_day'] ?? 'FR' ), __( 'Aralık başlangıç günü', 'campaign-engine-for-woocommerce' ), $span_days );
		self::textRaw( 'eru_ce[recurrence][from]', (string) ( $rec['from'] ?? '' ), __( 'Aralık başlangıç saati', 'campaign-engine-for-woocommerce' ), 'recurrence_from', 'small-text', '', 'time' );
		self::selectRaw( 'eru_ce[recurrence][to_day]', (string) ( $rec['to_day'] ?? 'SU' ), __( 'Aralık bitiş günü', 'campaign-engine-for-woocommerce' ), $span_days );
		self::textRaw( 'eru_ce[recurrence][to]', (string) ( $rec['to'] ?? '' ), __( 'Aralık bitiş saati (dahil)', 'campaign-engine-for-woocommerce' ), 'recurrence_to', 'small-text', '', 'time' );
		echo '</div>';
		self::error( 'recurrence' );

		echo '<div class="eru-ce-grid">';
		self::text( 'priority', __( 'Öncelik (büyük olan önce değerlendirilir)', 'campaign-engine-for-woocommerce' ), 'small-text', 'numeric' );
		self::text( 'max_discount', __( 'Bu kampanyanın sepet başına en yüksek indirimi', 'campaign-engine-for-woocommerce' ), 'regular-text', 'decimal' );
		self::text( 'usage_limit', __( 'Toplam kullanım sınırı', 'campaign-engine-for-woocommerce' ), 'small-text', 'numeric' );
		self::text( 'usage_limit_per_customer', __( 'Müşteri başına kullanım sınırı', 'campaign-engine-for-woocommerce' ), 'small-text', 'numeric' );
		echo '</div>';

		echo '<p>';
		self::checkbox( 'exclusive', __( 'Tek başına: bu kampanya uygulandığında, önceliği daha düşük olan bütün kampanyalar devre dışı kalır', 'campaign-engine-for-woocommerce' ) );
		echo '</p><p class="eru-ce-exclusive-warning notice-warning" hidden>' . esc_html( WizardForm::warnings( array( 'exclusive' => true, 'actions' => array( array( 'type' => 'free_shipping' ) ) ) )[0] ) . '</p>';
		echo '<p>';
		self::checkbox( 'public', __( 'Kampanya sayfasında listelensin (Ayarlar\'da kampanya sayfaları açıksa)', 'campaign-engine-for-woocommerce' ) );
		echo '</p><p>';
		self::checkbox( 'paused', __( 'Duraklatıldı (yayında kalır ama uygulanmaz)', 'campaign-engine-for-woocommerce' ) );
		echo '</p>';
		self::close();
	}

	public static function renderPreview( WP_Post $post ): void {
		echo '<div class="eru-ce-preview" data-post="' . esc_attr( (string) $post->ID ) . '">';
		echo '<p><label for="eru-ce-preview-cart">' . esc_html__( 'Örnek sepet', 'campaign-engine-for-woocommerce' ) . '</label><br><select id="eru-ce-preview-cart" class="eru-ce-preview-cart">';

		foreach ( PreviewController::carts() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}

		echo '</select></p>';
		echo '<p class="eru-ce-preview-custom" hidden><label for="eru-ce-preview-lines">' . esc_html__( 'Ürün no x adet, her satıra bir ürün', 'campaign-engine-for-woocommerce' ) . '</label><textarea id="eru-ce-preview-lines" rows="3" class="widefat eru-ce-preview-lines" placeholder="12 x 3"></textarea></p>';
		echo '<p><label for="eru-ce-preview-customer">' . esc_html__( 'Müşteri', 'campaign-engine-for-woocommerce' ) . '</label><br><select id="eru-ce-preview-customer" class="eru-ce-preview-customer">';

		foreach ( PreviewController::customers() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}

		echo '</select></p>';
		echo '<p><button type="button" class="button eru-ce-preview-run">' . esc_html__( 'Önizle', 'campaign-engine-for-woocommerce' ) . '</button></p>';
		echo '<div class="eru-ce-preview-result" aria-live="polite"><p class="description">' . esc_html__( 'Formdaki kampanya, kaydedilmeden, yalnız başına ve mağaza ayarlarıyla örnek sepette çalıştırılır.', 'campaign-engine-for-woocommerce' ) . '</p></div>';
		echo '</div>';
	}

	/** Data for the wizard script: conditions, operators, strings, the preview endpoint. */
	public static function scriptData(): array {
		$gateways = array();

		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
				$gateways[] = array(
					'value' => (string) $id,
					'label' => wp_strip_all_tags( (string) $gateway->get_title() ),
				);
			}
		}

		$roles = array();

		foreach ( wp_roles()->get_names() as $role => $name ) {
			$roles[] = array(
				'value' => (string) $role,
				'label' => translate_user_role( (string) $name ),
			);
		}

		return array(
			'ajax'       => admin_url( 'admin-ajax.php' ),
			'action'     => PreviewController::ACTION,
			'nonce'      => wp_create_nonce( PreviewController::NONCE ),
			'currency'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'conditions' => self::conditions( $gateways, $roles ),
			'operators'  => array(
				'>='     => __( 'en az', 'campaign-engine-for-woocommerce' ),
				'>'      => __( 'büyüktür', 'campaign-engine-for-woocommerce' ),
				'<='     => __( 'en çok', 'campaign-engine-for-woocommerce' ),
				'<'      => __( 'küçüktür', 'campaign-engine-for-woocommerce' ),
				'=='     => __( 'eşittir', 'campaign-engine-for-woocommerce' ),
				'!='     => __( 'eşit değil', 'campaign-engine-for-woocommerce' ),
				'between' => __( 'arasında', 'campaign-engine-for-woocommerce' ),
				'in'     => __( 'şunlardan biri', 'campaign-engine-for-woocommerce' ),
				'not_in' => __( 'bunların hiçbiri', 'campaign-engine-for-woocommerce' ),
				'all'    => __( 'hepsi', 'campaign-engine-for-woocommerce' ),
			),
			'lineTypes'  => WizardForm::LINE_TYPES,
			'days'       => array(
				__( 'Pzt', 'campaign-engine-for-woocommerce' ),
				__( 'Sal', 'campaign-engine-for-woocommerce' ),
				__( 'Çar', 'campaign-engine-for-woocommerce' ),
				__( 'Per', 'campaign-engine-for-woocommerce' ),
				__( 'Cum', 'campaign-engine-for-woocommerce' ),
				__( 'Cmt', 'campaign-engine-for-woocommerce' ),
				__( 'Paz', 'campaign-engine-for-woocommerce' ),
			),
			'i18n'       => array(
				'and'          => __( 'Aşağıdakilerin HEPSİ (VE)', 'campaign-engine-for-woocommerce' ),
				'or'           => __( 'Aşağıdakilerden EN AZ BİRİ (VEYA)', 'campaign-engine-for-woocommerce' ),
				'addCondition' => __( '+ Koşul ekle', 'campaign-engine-for-woocommerce' ),
				'addGroup'     => __( '+ Koşul grubu ekle', 'campaign-engine-for-woocommerce' ),
				'remove'       => __( 'Kaldır', 'campaign-engine-for-woocommerce' ),
				'yes'          => __( 'Evet', 'campaign-engine-for-woocommerce' ),
				'no'           => __( 'Hayır', 'campaign-engine-for-woocommerce' ),
				'from'         => __( 'başlangıç', 'campaign-engine-for-woocommerce' ),
				'to'           => __( 'bitiş', 'campaign-engine-for-woocommerce' ),
				'listHint'     => __( 'virgülle ayırın', 'campaign-engine-for-woocommerce' ),
				'attribute'    => __( 'özellik (ör. pa_renk)', 'campaign-engine-for-woocommerce' ),
				'jsonOnly'     => __( 'Bu koşul ağacı sihirbazın gösterebileceğinden derin; "Gelişmiş" alanından JSON olarak düzenleyin.', 'campaign-engine-for-woocommerce' ),
				'badJson'      => __( 'JSON okunamadı; düzeltin ya da temizleyin.', 'campaign-engine-for-woocommerce' ),
				'empty'        => __( 'Koşul yok: hedef ürünler sepetteyse kampanya uygulanır.', 'campaign-engine-for-woocommerce' ),
				'running'      => __( 'Hesaplanıyor…', 'campaign-engine-for-woocommerce' ),
				'failed'       => __( 'Önizleme alınamadı.', 'campaign-engine-for-woocommerce' ),
				'product'      => __( 'Ürün', 'campaign-engine-for-woocommerce' ),
				'qty'          => __( 'Adet', 'campaign-engine-for-woocommerce' ),
				'line'         => __( 'Tutar', 'campaign-engine-for-woocommerce' ),
				'discount'     => __( 'İndirim', 'campaign-engine-for-woocommerce' ),
				'subtotal'     => __( 'Ara toplam', 'campaign-engine-for-woocommerce' ),
				'total'        => __( 'Kampanyalı toplam', 'campaign-engine-for-woocommerce' ),
				'freeShipping' => __( 'Kargo ücretsiz olur.', 'campaign-engine-for-woocommerce' ),
				'reward'       => __( 'Ödenen siparişe sonraki alışveriş için kupon:', 'campaign-engine-for-woocommerce' ),
				'notApplied'   => __( 'Bu sepette kampanya uygulanmıyor:', 'campaign-engine-for-woocommerce' ),
				'notes'        => __( 'Notlar:', 'campaign-engine-for-woocommerce' ),
				'stepError'    => __( 'Bu adımda düzeltilecek alan var.', 'campaign-engine-for-woocommerce' ),
			),
		);
	}

	/**
	 * Condition catalogue for the builder: id, group, label, kind (money, int, ids, list, flag, weekday,
	 * daterange, timerange, attribute), default operator and options.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function conditions( array $gateways, array $roles ): array {
		$groups = array(
			'cart'     => __( 'Sepet', 'campaign-engine-for-woocommerce' ),
			'item'     => __( 'Ürün', 'campaign-engine-for-woocommerce' ),
			'customer' => __( 'Müşteri', 'campaign-engine-for-woocommerce' ),
			'datetime' => __( 'Zaman', 'campaign-engine-for-woocommerce' ),
		);
		$list   = array(
			array( 'cart.subtotal', 'cart', __( 'Sepet tutarı (indirimler öncesi)', 'campaign-engine-for-woocommerce' ), 'money', '>=' ),
			array( 'cart.eligible_subtotal', 'cart', __( 'Hedef ürünlerin tutarı (indirimler öncesi)', 'campaign-engine-for-woocommerce' ), 'money', '>=' ),
			array( 'cart.quantity', 'cart', __( 'Sepetteki toplam adet', 'campaign-engine-for-woocommerce' ), 'int', '>=' ),
			array( 'cart.eligible_quantity', 'cart', __( 'Hedef ürünlerin adedi', 'campaign-engine-for-woocommerce' ), 'int', '>=' ),
			array( 'cart.coupon', 'cart', __( 'Sepetteki kupon kodu', 'campaign-engine-for-woocommerce' ), 'list', 'in' ),
			array( 'cart.payment_method', 'cart', __( 'Ödeme yöntemi', 'campaign-engine-for-woocommerce' ), 'list', 'in', $gateways ),
			array( 'cart.shipping_method', 'cart', __( 'Kargo yöntemi (ör. flat_rate)', 'campaign-engine-for-woocommerce' ), 'list', 'in' ),
			array( 'cart.shipping_country', 'cart', __( 'Teslimat ülkesi (ör. TR)', 'campaign-engine-for-woocommerce' ), 'list', 'in' ),
			array( 'item.product', 'item', __( 'Ürün no', 'campaign-engine-for-woocommerce' ), 'ids', 'in' ),
			array( 'item.quantity', 'item', __( 'Satırdaki adet', 'campaign-engine-for-woocommerce' ), 'int', '>=' ),
			array( 'item.category', 'item', __( 'Kategori no', 'campaign-engine-for-woocommerce' ), 'ids', 'in' ),
			array( 'item.tag', 'item', __( 'Etiket no', 'campaign-engine-for-woocommerce' ), 'ids', 'in' ),
			array( 'item.brand', 'item', __( 'Marka no', 'campaign-engine-for-woocommerce' ), 'ids', 'in' ),
			array( 'item.term', 'item', __( 'Terim no (her taksonomi)', 'campaign-engine-for-woocommerce' ), 'ids', 'in' ),
			array( 'item.attribute', 'item', __( 'Ürün özelliği', 'campaign-engine-for-woocommerce' ), 'attribute', 'in' ),
			array( 'item.sku', 'item', __( 'Stok kodu', 'campaign-engine-for-woocommerce' ), 'list', 'in' ),
			array( 'item.on_sale', 'item', __( 'Ürün indirimde', 'campaign-engine-for-woocommerce' ), 'flag', '' ),
			array( 'item.price', 'item', __( 'Ürün birim fiyatı', 'campaign-engine-for-woocommerce' ), 'money', '>=' ),
			array(
				'item.stock_status',
				'item',
				__( 'Stok durumu', 'campaign-engine-for-woocommerce' ),
				'list',
				'in',
				array(
					array(
						'value' => 'instock',
						'label' => __( 'Stokta', 'campaign-engine-for-woocommerce' ),
					),
					array(
						'value' => 'outofstock',
						'label' => __( 'Stokta yok', 'campaign-engine-for-woocommerce' ),
					),
					array(
						'value' => 'onbackorder',
						'label' => __( 'Ön siparişte', 'campaign-engine-for-woocommerce' ),
					),
				),
			),
			array( 'item.stock_quantity', 'item', __( 'Stok adedi', 'campaign-engine-for-woocommerce' ), 'int', '>=' ),
			array( 'item.age_days', 'item', __( 'Ürünün yayın yaşı (gün)', 'campaign-engine-for-woocommerce' ), 'int', '<=' ),
			array( 'customer.logged_in', 'customer', __( 'Üye girişi yapmış', 'campaign-engine-for-woocommerce' ), 'flag', '' ),
			array( 'customer.role', 'customer', __( 'Kullanıcı rolü', 'campaign-engine-for-woocommerce' ), 'list', 'in', $roles ),
			array( 'customer.order_count', 'customer', __( 'Ödenmiş sipariş sayısı', 'campaign-engine-for-woocommerce' ), 'int', '>=' ),
			array( 'customer.first_order', 'customer', __( 'İlk siparişi', 'campaign-engine-for-woocommerce' ), 'flag', '' ),
			array( 'customer.total_spent', 'customer', __( 'Toplam harcaması', 'campaign-engine-for-woocommerce' ), 'money', '>=' ),
			array( 'customer.days_since_last_order', 'customer', __( 'Son siparişten bu yana gün', 'campaign-engine-for-woocommerce' ), 'int', '>=' ),
			array( 'date.range', 'datetime', __( 'Tarih aralığı', 'campaign-engine-for-woocommerce' ), 'daterange', '' ),
			array( 'date.weekday', 'datetime', __( 'Haftanın günü', 'campaign-engine-for-woocommerce' ), 'weekday', 'in' ),
			array( 'time.window', 'datetime', __( 'Saat aralığı', 'campaign-engine-for-woocommerce' ), 'timerange', '' ),
		);
		$out    = array();

		foreach ( $list as $row ) {
			$out[] = array(
				'id'      => $row[0],
				'group'   => $groups[ $row[1] ],
				'label'   => $row[2],
				'kind'    => $row[3],
				'cmp'     => $row[4],
				'options' => $row[5] ?? array(),
			);
		}

		return $out;
	}

	private static function open( int $step, string $title ): void {
		echo '<section class="eru-ce-step" data-step="' . esc_attr( (string) $step ) . '"><h3>' . esc_html( $step . '. ' . $title ) . '</h3>';
	}

	private static function close(): void {
		echo '</section>';
	}

	private static function error( string $field ): void {
		if ( isset( self::$errors[ $field ] ) ) {
			echo '<p class="eru-ce-field-error" role="alert">' . esc_html( TraceText::error( $field, (string) self::$errors[ $field ] ) ) . '</p>';
		}
	}

	private static function text( string $field, string $label, string $class = 'regular-text', string $mode = '', string $type = 'text' ): void {
		self::textRaw( 'eru_ce[' . $field . ']', (string) ( self::$values[ $field ] ?? '' ), $label, $field, $class, $mode, $type );
	}

	private static function textRaw( string $name, string $value, string $label, string $field, string $class = 'regular-text', string $mode = '', string $type = 'text' ): void {
		$id = 'eru-ce-' . sanitize_html_class( str_replace( array( '[', ']' ), '-', $name ) );
		echo '<p class="eru-ce-field' . ( isset( self::$errors[ $field ] ) ? ' has-error' : '' ) . '"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><br>';
		echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="' . esc_attr( $class ) . '"' . ( '' !== $mode ? ' inputmode="' . esc_attr( $mode ) . '"' : '' ) . '>';
		echo '</p>';
		self::error( $field );
	}

	private static function textarea( string $field, string $label ): void {
		echo '<p class="eru-ce-field"><label for="eru-ce-' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label><br><textarea id="eru-ce-' . esc_attr( $field ) . '" name="eru_ce[' . esc_attr( $field ) . ']" rows="3" class="large-text">' . esc_textarea( (string) ( self::$values[ $field ] ?? '' ) ) . '</textarea></p>';
	}

	private static function checkbox( string $field, string $label ): void {
		echo '<label><input type="checkbox" name="eru_ce[' . esc_attr( $field ) . ']" value="yes"' . checked( 'yes', (string) ( self::$values[ $field ] ?? '' ), false ) . ' class="eru-ce-' . esc_attr( $field ) . '"> ' . esc_html( $label ) . '</label>';
	}

	/** @param array<string,string> $options */
	private static function select( string $field, string $label, array $options ): void {
		self::selectRaw( 'eru_ce[' . $field . ']', (string) ( self::$values[ $field ] ?? '' ), $label, $options, $field );
	}

	/** @param array<string,string> $options */
	private static function selectRaw( string $name, string $value, string $label, array $options, string $field = '' ): void {
		$id = 'eru-ce-' . sanitize_html_class( str_replace( array( '[', ']' ), '-', $name ) );
		echo '<p class="eru-ce-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><br><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';

		foreach ( $options as $key => $text ) {
			echo '<option value="' . esc_attr( (string) $key ) . '"' . selected( $value, (string) $key, false ) . '>' . esc_html( $text ) . '</option>';
		}

		echo '</select></p>';

		if ( '' !== $field ) {
			self::error( $field );
		}
	}

	private static function products( string $field, string $label, string $hint ): void {
		$ids = self::$values[ $field ] ?? array();
		$ids = is_array( $ids ) ? $ids : preg_split( '/[\s,]+/', (string) $ids, -1, PREG_SPLIT_NO_EMPTY );
		echo '<p class="eru-ce-field' . ( isset( self::$errors[ $field ] ) ? ' has-error' : '' ) . '"><label for="eru-ce-' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label><br>';
		echo '<select id="eru-ce-' . esc_attr( $field ) . '" class="wc-product-search eru-ce-wide" multiple="multiple" name="eru_ce[' . esc_attr( $field ) . '][]" data-placeholder="' . esc_attr__( 'Ürün ara…', 'campaign-engine-for-woocommerce' ) . '" data-action="woocommerce_json_search_products_and_variations">';

		foreach ( (array) $ids as $id ) {
			$id      = absint( $id );
			$product = $id > 0 ? wc_get_product( $id ) : null;
			$name    = $product ? wp_strip_all_tags( $product->get_formatted_name() ) : '#' . $id;

			if ( $id > 0 ) {
				echo '<option value="' . esc_attr( (string) $id ) . '" selected="selected">' . esc_html( $name ) . '</option>';
			}
		}

		echo '</select>';

		if ( '' !== $hint ) {
			echo '<br><span class="description">' . esc_html( $hint ) . '</span>';
		}

		echo '</p>';
		self::error( $field );
	}

	private static function terms( string $field, string $label ): void {
		$chosen = array_map( 'intval', (array) ( self::$values[ $field ] ?? array() ) );
		$terms  = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 500,
			)
		);
		echo '<p class="eru-ce-field"><label for="eru-ce-' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label><br>';
		echo '<select id="eru-ce-' . esc_attr( $field ) . '" class="wc-enhanced-select eru-ce-wide" multiple="multiple" name="eru_ce[' . esc_attr( $field ) . '][]" data-placeholder="' . esc_attr__( 'Kategori seçin…', 'campaign-engine-for-woocommerce' ) . '">';

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			echo '<option value="' . esc_attr( (string) $term->term_id ) . '"' . selected( in_array( (int) $term->term_id, $chosen, true ), true, false ) . '>' . esc_html( $term->name ) . '</option>';
		}

		echo '</select></p>';
		self::error( $field );
	}
}
