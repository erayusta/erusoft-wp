<?php
/**
 * Turkish text for the engine's trace codes, argument errors and form fields, for the preview, the
 * campaign editor, the status screen and the CLI.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Admin;

final class TraceText {

	/**
	 * One trace entry as a sentence.
	 *
	 * @param array{campaign_id:int,step:string,status:string,code:string,data:array<string,mixed>} $entry
	 */
	public static function describe( array $entry ): string {
		$data = is_array( $entry['data'] ?? null ) ? $entry['data'] : array();
		$code = (string) ( $entry['code'] ?? '' );

		switch ( $code ) {
			case 'outside_schedule':
				return __( 'Kampanya şu an zaman planının dışında (başlamadı, bitti ya da tekrar penceresi kapalı).', 'campaign-engine-for-woocommerce' );
			case 'coupon_in_cart':
				return __( 'Sepette kupon var ve ayarlar kampanyaların kuponla birlikte çalışmasına izin vermiyor.', 'campaign-engine-for-woocommerce' );
			case 'no_scope':
				return __( 'Sepetteki ürünlerin hiçbiri kampanyanın hedef ürünleri arasında değil.', 'campaign-engine-for-woocommerce' );
			case 'usage_limit':
				return __( 'Kampanyanın kullanım hakkı doldu (toplam ya da bu müşteri için).', 'campaign-engine-for-woocommerce' );
			case 'conditions':
				return 'item' === ( $data['pass'] ?? '' )
					? __( 'Koşullar sağlanmadı: hiçbir sepet satırı ürün koşullarını karşılamıyor.', 'campaign-engine-for-woocommerce' )
					: __( 'Koşullar sağlanmadı: sepet, müşteri ya da zaman koşullarından biri tutmuyor.', 'campaign-engine-for-woocommerce' );
			case 'unknown_action':
				/* translators: %s: action type */
				return sprintf( __( 'Tanınmayan kampanya aksiyonu (%s); kampanya uygulanmadı.', 'campaign-engine-for-woocommerce' ), (string) ( $data['type'] ?? '' ) );
			case 'invalid_args':
				/* translators: 1: field label, 2: reason */
				return sprintf( __( 'Kampanya ayarı geçersiz: %1$s — %2$s. Kampanya uygulanmadı; kampanyayı açıp düzeltin.', 'campaign-engine-for-woocommerce' ), self::field( (string) ( $data['field'] ?? '' ) ), self::reason( (string) ( $data['reason'] ?? '' ) ) );
			case 'no_discount':
				return __( 'Koşullar sağlandı ama bu sepette indirim çıkmadı (örneğin set tamamlanmadı ya da ilk kademeye ulaşılmadı).', 'campaign-engine-for-woocommerce' );
			case 'exclusive':
				/* translators: %d: winning campaign id */
				return sprintf( __( '"Tek başına" işaretli kampanya #%d uygulandığı için bu kampanya devre dışı kaldı.', 'campaign-engine-for-woocommerce' ), (int) ( $data['winner'] ?? 0 ) );
			case 'excluded_others':
				$ids = array_map( 'intval', (array) ( $data['excluded'] ?? array() ) );
				/* translators: %s: campaign ids */
				return sprintf( __( 'Bu kampanya "tek başına" uygulandı; diğer kampanyalar devre dışı kaldı: %s.', 'campaign-engine-for-woocommerce' ), array() === $ids ? '—' : '#' . implode( ', #', $ids ) );
			case 'stacking':
				if ( 'info' === ( $entry['status'] ?? '' ) ) {
					/* translators: %d: winning campaign id */
					return sprintf( __( 'Bir sepet satırında kampanya #%d daha avantajlı olduğu için o satırda o uygulandı.', 'campaign-engine-for-woocommerce' ), (int) ( $data['winner'] ?? 0 ) );
				}
				return __( 'Birleştirme kuralı nedeniyle bu kampanyanın indirimi hiçbir satırda uygulanmadı.', 'campaign-engine-for-woocommerce' );
			case 'lost_all_lines':
				return __( 'Bütün satırlarda başka bir kampanya kazandığı için bu kampanya uygulanmadı; ücretsiz kargo ve ödülü de verilmez.', 'campaign-engine-for-woocommerce' );
			case 'campaign_cap':
				return __( 'Kampanyanın en yüksek indirim tutarı sınırı uygulandı.', 'campaign-engine-for-woocommerce' );
			case 'cart_cap':
				/* translators: %s: percentage */
				return sprintf( __( 'Sepet indirim sınırı (%%%s) uygulandı; indirim bu oranı aşamaz.', 'campaign-engine-for-woocommerce' ), (string) ( $data['cap'] ?? '' ) );
			case 'min_price':
				return __( 'En düşük birim fiyat sınırı nedeniyle bir satırın indirimi azaltıldı.', 'campaign-engine-for-woocommerce' );
			case 'line_missing':
				return __( 'Bir indirim, sepette artık bulunmayan bir satıra aitti ve uygulanmadı.', 'campaign-engine-for-woocommerce' );
			case 'ok':
				return self::step( (string) ( $entry['step'] ?? '' ) ) . ': ' . __( 'geçti.', 'campaign-engine-for-woocommerce' );
		}

		/* translators: %s: trace code */
		return sprintf( __( 'Kampanya değerlendirme adımı: %s.', 'campaign-engine-for-woocommerce' ), $code );
	}

	/** Step names for passed steps. */
	public static function step( string $step ): string {
		$map = array(
			'schedule'   => __( 'Zaman planı', 'campaign-engine-for-woocommerce' ),
			'coupons'    => __( 'Kupon kuralı', 'campaign-engine-for-woocommerce' ),
			'scope'      => __( 'Hedef ürünler', 'campaign-engine-for-woocommerce' ),
			'usage'      => __( 'Kullanım hakkı', 'campaign-engine-for-woocommerce' ),
			'conditions' => __( 'Koşullar', 'campaign-engine-for-woocommerce' ),
			'action'     => __( 'Kampanya ödülü', 'campaign-engine-for-woocommerce' ),
		);

		return $map[ $step ] ?? __( 'Kampanya adımı', 'campaign-engine-for-woocommerce' );
	}

	/** A field name (engine or form) as a Turkish label. */
	public static function field( string $field ): string {
		$map = array(
			'type'                     => __( 'Kampanya türü', 'campaign-engine-for-woocommerce' ),
			'actions'                  => __( 'Kampanya türü', 'campaign-engine-for-woocommerce' ),
			'actions.type'             => __( 'Kampanya türü', 'campaign-engine-for-woocommerce' ),
			'extra_actions'            => __( 'Ek aksiyonlar', 'campaign-engine-for-woocommerce' ),
			'extra_meta'               => __( 'Ek bilgiler', 'campaign-engine-for-woocommerce' ),
			'percent'                  => __( 'İndirim yüzdesi', 'campaign-engine-for-woocommerce' ),
			'amount'                   => __( 'İndirim tutarı', 'campaign-engine-for-woocommerce' ),
			'price'                    => __( 'Kampanya fiyatı', 'campaign-engine-for-woocommerce' ),
			'tiers'                    => __( 'Kademeler', 'campaign-engine-for-woocommerce' ),
			'min'                      => __( 'Kademe alt sınırı', 'campaign-engine-for-woocommerce' ),
			'max'                      => __( 'Kademe üst sınırı', 'campaign-engine-for-woocommerce' ),
			'per'                      => __( 'Sabit kademe türü', 'campaign-engine-for-woocommerce' ),
			'set_size'                 => __( 'Set adedi', 'campaign-engine-for-woocommerce' ),
			'reward_count'             => __( 'Ödüllü adet', 'campaign-engine-for-woocommerce' ),
			'reward'                   => __( 'Ödül türü', 'campaign-engine-for-woocommerce' ),
			'value'                    => __( 'Ödül değeri', 'campaign-engine-for-woocommerce' ),
			'target'                   => __( 'Ödüllü ürün seçimi', 'campaign-engine-for-woocommerce' ),
			'pool'                     => __( 'Set havuzu', 'campaign-engine-for-woocommerce' ),
			'repeat'                   => __( 'Tekrar', 'campaign-engine-for-woocommerce' ),
			'reward_products'          => __( 'Ödüllü ürünler', 'campaign-engine-for-woocommerce' ),
			'reward_terms'             => __( 'Ödüllü kategoriler', 'campaign-engine-for-woocommerce' ),
			'min_cart'                 => __( 'Kuponun en düşük sepet tutarı', 'campaign-engine-for-woocommerce' ),
			'valid_days'               => __( 'Kupon geçerlilik süresi', 'campaign-engine-for-woocommerce' ),
			'category_ids'             => __( 'Kupon kategorileri', 'campaign-engine-for-woocommerce' ),
			'include_products'         => __( 'Hedef ürünler', 'campaign-engine-for-woocommerce' ),
			'include_terms'            => __( 'Hedef kategoriler', 'campaign-engine-for-woocommerce' ),
			'exclude_products'         => __( 'Hariç ürünler', 'campaign-engine-for-woocommerce' ),
			'exclude_terms'            => __( 'Hariç kategoriler', 'campaign-engine-for-woocommerce' ),
			'sku'                      => __( 'Hedef stok kodları', 'campaign-engine-for-woocommerce' ),
			'basis'                    => __( 'İndirim tabanı', 'campaign-engine-for-woocommerce' ),
			'rules'                    => __( 'Koşullar', 'campaign-engine-for-woocommerce' ),
			'starts_at'                => __( 'Başlangıç', 'campaign-engine-for-woocommerce' ),
			'ends_at'                  => __( 'Bitiş', 'campaign-engine-for-woocommerce' ),
			'recurrence'               => __( 'Tekrar eden zaman', 'campaign-engine-for-woocommerce' ),
			'schedule'                 => __( 'Zaman planı', 'campaign-engine-for-woocommerce' ),
			'priority'                 => __( 'Öncelik', 'campaign-engine-for-woocommerce' ),
			'max_discount'             => __( 'En yüksek indirim tutarı', 'campaign-engine-for-woocommerce' ),
			'max_discount_minor'       => __( 'En yüksek indirim tutarı', 'campaign-engine-for-woocommerce' ),
			'usage_limit'              => __( 'Toplam kullanım sınırı', 'campaign-engine-for-woocommerce' ),
			'usage_limit_per_customer' => __( 'Müşteri başına kullanım sınırı', 'campaign-engine-for-woocommerce' ),
			'stacking'                 => __( 'Kampanya birleştirme', 'campaign-engine-for-woocommerce' ),
			'max_cart_discount_percent' => __( 'Sepet indirim sınırı', 'campaign-engine-for-woocommerce' ),
			'min_unit_price_minor'     => __( 'En düşük birim fiyat', 'campaign-engine-for-woocommerce' ),
			'min_unit_price'           => __( 'En düşük birim fiyat', 'campaign-engine-for-woocommerce' ),
			'with_coupons'             => __( 'Kuponla birlikte', 'campaign-engine-for-woocommerce' ),
			'reward_maturation_days'   => __( 'Ödül bekleme süresi', 'campaign-engine-for-woocommerce' ),
			'reward_coupon_days'       => __( 'Ödül kuponu geçerlilik süresi', 'campaign-engine-for-woocommerce' ),
			'reward_refund_policy'     => __( 'İadede ödül', 'campaign-engine-for-woocommerce' ),
			'reservation_mode'         => __( 'Kullanım hakkı ayırma', 'campaign-engine-for-woocommerce' ),
			'reservation_ttl_hours'    => __( 'Ayırma süresi', 'campaign-engine-for-woocommerce' ),
			'cache_adaptor'            => __( 'Önbellek temizleme', 'campaign-engine-for-woocommerce' ),
			'retention_days'           => __( 'Kişisel veri saklama süresi', 'campaign-engine-for-woocommerce' ),
			'archive_slug'             => __( 'Kampanyalar sayfası adresi', 'campaign-engine-for-woocommerce' ),
			'single_slug'              => __( 'Kampanya detay adresi', 'campaign-engine-for-woocommerce' ),
			'account_endpoint'         => __( 'Hesabım → Ödüllerim adresi', 'campaign-engine-for-woocommerce' ),
		);

		if ( isset( $map[ $field ] ) ) {
			return $map[ $field ];
		}

		if ( 0 === strpos( $field, 'targets.' ) ) {
			return __( 'Hedef / hariç ürünler', 'campaign-engine-for-woocommerce' );
		}

		if ( 0 === strpos( $field, 'rules' ) ) {
			return __( 'Koşullar', 'campaign-engine-for-woocommerce' );
		}

		return '' === $field ? __( 'Kampanya', 'campaign-engine-for-woocommerce' ) : $field;
	}

	/** An argument error reason as Turkish text. */
	public static function reason( string $reason ): string {
		$map = array(
			'missing'        => __( 'boş bırakılamaz', 'campaign-engine-for-woocommerce' ),
			'invalid'        => __( 'geçerli bir değer değil', 'campaign-engine-for-woocommerce' ),
			'not_integral'   => __( 'tam sayı olmalı', 'campaign-engine-for-woocommerce' ),
			'too_precise'    => __( 'en fazla iki ondalık basamak olabilir', 'campaign-engine-for-woocommerce' ),
			'out_of_range'   => __( 'izin verilen aralığın dışında', 'campaign-engine-for-woocommerce' ),
			'unknown_choice' => __( 'tanınmayan bir seçim', 'campaign-engine-for-woocommerce' ),
			'in_use'         => __( 'başka bir sayfa veya ayar tarafından kullanılıyor', 'campaign-engine-for-woocommerce' ),
			'forbidden'      => __( 'bu işlem için yetkiniz yok', 'campaign-engine-for-woocommerce' ),
		);

		return $map[ $reason ] ?? __( 'geçersiz', 'campaign-engine-for-woocommerce' );
	}

	/** "Field: reason" for a form error. */
	public static function error( string $field, string $reason ): string {
		return self::field( $field ) . ': ' . self::reason( $reason );
	}
}
