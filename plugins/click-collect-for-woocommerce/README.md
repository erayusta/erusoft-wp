# Click & Collect for WooCommerce

Mağazadan teslim, gerçek bir kargo yöntemi olarak: tanımlı teslim noktaları, ödemede nokta seçimi, siparişte ve e-postalarda gösterim, isteğe bağlı "Teslim almaya hazır" durumu.

> **English:** Store pickup as a proper WooCommerce shipping method (zones compatible). Define pickup locations (name, address, hours); the customer picks one at checkout, it is validated server-side, saved on the order and shown in the admin order, e-mails, thank-you page and My Account. Optional "Ready for pickup" order status. No locations = method not offered.

[![Lisans: GPL-2.0-or-later](https://img.shields.io/badge/lisans-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html) ![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg) ![WooCommerce 9.0+](https://img.shields.io/badge/WooCommerce-9.0%2B-96588a.svg) ![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)

![Ekran görüntüsü 1](../../docs/screenshots/click-collect-for-woocommerce/1.png)

## Ne işe yarar

Müşteri siparişini kargo yerine sizin tanımladığınız bir teslim noktasından alır. Seçilen nokta siparişe kaydedilir ve müşteriye e-postada gösterilir.

## Özellikler

- `WC_Shipping_Method` tabanlı **Mağazadan teslim** yöntemi: gönderim bölgeleriyle çalışır, kendi başlık/ücret/vergi ayarı vardır
- Teslim noktaları: ad, adres, çalışma saatleri (varsayılan: hiç yok → yöntem sunulmaz)
- Ödemede nokta seçimi; sunucuda tanımlı noktalara karşı doğrulanır
- Sipariş metası: `_eru_ccl_location` (ad), `_eru_ccl_location_id`, `_eru_ccl_location_address`, `_eru_ccl_location_hours`
- Yönetici sipariş ekranında, müşteri e-postalarında (HTML ve düz metin), teşekkür sayfasında ve Hesabım'da gösterim
- İsteğe bağlı **Teslim almaya hazır** sipariş durumu (toplu işlem ve müşteri e-postasıyla)
- Tek teslim noktası varsa seçim otomatik atanır

## Kurulum

1. Klasörü `wp-content/plugins/` altına kopyalayın ve etkinleştirin.
2. WooCommerce → **Mağazadan Teslim**: teslim noktalarını girin.
3. WooCommerce → Ayarlar → Gönderim bölgelerinde bölgenize **Mağazadan teslim** yöntemini ekleyin.
4. Ödeme sayfanız klasik `[woocommerce_checkout]` kısa kodunu kullanmalıdır.

## Nasıl kullanılır

1. Eklentiyi etkinleştirin ve WooCommerce → **Mağazadan Teslim** ekranında iki teslim noktası ekleyin: "Demo Şube 1" ve "Demo Şube 2" (adres ve çalışma saatleri, örn. "Hafta içi 10:00-18:00").
2. WooCommerce → Ayarlar → Gönderim'de bir bölgeye **Mağazadan teslim** yöntemini ekleyin; ücreti 0 bırakın.
3. Ödeme sayfasının klasik `[woocommerce_checkout]` kısa koduyla çalıştığından emin olun.
4. "Demo Ürün" (250 TL) sepetle ödemeye gidin, Mağazadan teslim'i seçin ve açılan listeden "Demo Şube 2"yi seçin. Seçim yapmadan sipariş vermeyi denerseniz doğrulama hatası alırsınız.
5. Siparişi `ornek@example.com` ile tamamlayın; nokta adı, adres ve saatler teşekkür sayfasında, müşteri e-postasında ve yönetici sipariş ekranında görünür.
6. İsterseniz "Teslim almaya hazır" durumunu ayarlardan açın; siparişi bu duruma alınca müşteriye bildirim gider.

## Ayarlar

| Ayar | Varsayılan |
|---|---|
| Teslim noktaları (`eru_ccl_locations`) | boş |
| "Teslim almaya hazır" durumu | kapalı |
| Hazır olunca müşteriye e-posta | açık (durum etkinse) |

**Diğer eklentilerle arayüz:** Teslim noktası adı sipariş metasında `_eru_ccl_location` anahtarında tutulur; Shipping Method Report for WooCommerce bu alanı okuyarak rapora teslim noktası kırılımı ekler.

## Kısa kodlar, kancalar ve filtreler

| Ad | Tür | Açıklama |
|---|---|---|
| `eru_ccl_locations` | filtre | Teslim noktaları dizisi (id, name, address, hours) |
| `woocommerce_shipping_eru_ccl_is_available` | filtre | Yöntemin uygunluğu |

## Entegrasyonlar ve gereksinimler

WordPress 6.5+, PHP 8.0+, WooCommerce 9.0+, HPOS uyumlu. Klasik ödeme sayfası.

## Sınırlamalar

- Blok tabanlı ödeme sayfasında nokta seçici gösterilemez: tek nokta tanımlıysa otomatik atanır; birden fazla nokta varsa yöntem blok ödemede sunulmaz ve konumsuz bir teslim alma siparişi reddedilir (`eru_ccl_location` hatası). Birden fazla nokta için klasik ödeme kullanın.
- Stoğa göre nokta uygunluğu yoktur; her nokta her sepet için sunulur.
- "Teslim almaya hazır" e-postası basit bir bildirimdir; WooCommerce e-posta şablonları ayarlarına bağlı değildir.

## SSS

**Hiç teslim noktası yoksa?** Yöntem müşteriye sunulmaz.

**Teslim noktasını sipariş sonrası değiştirebilir miyim?** Sipariş metasını değiştirmek için kendi koduna ihtiyaç vardır; arayüz yoktur.

**Eklentiyi devre dışı bırakırsam ya da silersem ne olur?** Devre dışı bırakınca yöntem ödemede sunulmaz; teslim noktaları ve siparişler olduğu gibi kalır. Eklentiler → Sil ile `eru_ccl_locations` ve `eru_ccl_settings` seçenekleri silinir; sipariş metası (`_eru_ccl_*`) siparişlere ait olduğu için korunur. Gönderim bölgelerindeki yöntem örnekleri WooCommerce'indir.

**HPOS ve blok ödeme?** HPOS uyumluluğu bildirilir. Blok ödemede Store API siparişi işlenirken tek nokta varsa otomatik atanır; birden fazla noktada klasik ödeme gerekir (Sınırlamalar).

**Dış servis, çeviri, çoklu site?** Dış servise bağlanmaz. Metin alanı `click-collect-for-woocommerce`, alan yolu `/languages`; klasörde hazır çeviri dosyası yoktur. Ağ düzeyinde etkinleştirme için özel bir destek yoktur.

**Teslim noktası sayısı performansı etkiler mi?** Noktalar tek bir seçenekte tutulur ve ödeme sırasında bu listeye karşı doğrulanır; çok büyük listeler için tasarlanmamıştır.

## Değişiklik geçmişi

Bkz. [CHANGELOG.md](CHANGELOG.md).

## Destek

- Önce bu sayfadaki **SSS** ve **Sınırlamalar** bölümlerine göz atın; sık karşılaşılan durumlar orada.
- Hata mı buldunuz? [GitHub Issues](https://github.com/erayusta/erusoft-wp/issues) üzerinden bildirin. WordPress, WooCommerce, PHP ve eklenti sürümünü, adımları ve beklenen/gerçekleşen sonucu yazmanız işi hızlandırır.
- Kurulum, yapılandırma, özel geliştirme veya entegrasyon desteği için **[hello@erusoft.com](mailto:hello@erusoft.com)** adresine yazın. Erusoft hakkında: [erusoft.com](https://erusoft.com).
- Lütfen şifre, API anahtarı veya müşteri verisi (ad, telefon, TCKN, sipariş dökümü) göndermeyin; ekran görüntülerindeki kişisel verileri karartın.

## Lisans

GPL-2.0-or-later. Diğer Erusoft eklentileri: https://wordpress.erusoft.com/
