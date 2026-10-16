# Shipping Method Report for WooCommerce

Tarih aralığında kargo yöntemi (ve teslim noktası) başına sipariş, ürün adedi ve kargo tutarı raporu; CSV dışa aktarma.

> **English:** Admin report of orders, item quantities and shipping totals per shipping method for a date range, split by pickup location when Click & Collect for WooCommerce is active. CSV export (UTF-8, formula-safe).

[![Lisans: GPL-2.0-or-later](https://img.shields.io/badge/lisans-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html) ![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg) ![WooCommerce 9.0+](https://img.shields.io/badge/WooCommerce-9.0%2B-96588a.svg) ![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)

![Ekran görüntüsü 1](../../docs/screenshots/shipping-method-report-for-woocommerce/1.png)

## Ne işe yarar

Hangi kargo yönteminden kaç sipariş çıktığını ve kaç ürün adedi gönderildiğini tek ekranda görürsünüz. Mağazadan Teslim eklentisi etkinse teslim noktası bazında da ayrılır.

## Özellikler

- WooCommerce → Kargo Yöntemi Raporu: tarih aralığı ve sipariş durumu seçimi
- Yöntem başına sipariş sayısı, ürün adedi ve kargo tutarı; kargosuz siparişler ayrı satır
- Teslim noktası kırılımı: sipariş metası `_eru_ccl_location` varsa okunur
- CSV indirme (UTF-8 BOM, `;` ayracı, elektronik tablo formül enjeksiyonuna karşı korumalı)
- HPOS uyumlu; siparişler 200'lük gruplarla okunur

## Nasıl kullanılır

1. Eklentiyi etkinleştirin; WooCommerce → **Kargo Yöntemi Raporu** ekranını açın.
2. Başlangıç ve bitiş tarihini seçin (ör. geçen ayın ilk ve son günü) ve sipariş durumlarını belirleyin.
3. Raporu çalıştırın: her kargo yöntemi için sipariş sayısı, ürün adedi ve kargo tutarı satırlarını ve alttaki toplamı görün.
4. Kargo ücreti olmayan siparişler ayrı bir satırda toplanır; Click & Collect for WooCommerce etkinse "Demo Mağaza" gibi teslim noktası adlarına göre de ayrılır.
5. Tablo büyükse CSV olarak indirin ve elektronik tabloda açın (`;` ayraçlı, UTF-8).

## Kurulum

1. Klasörü `wp-content/plugins/` altına kopyalayın ve etkinleştirin.
2. WooCommerce → **Kargo Yöntemi Raporu** ekranını açın.

## Ayarlar

Ayar yoktur; varsayılan durumlar Hazırlanıyor, Beklemede ve Tamamlandı'dır.

**Teslim noktası arayüzü:** Rapor, sipariş metasındaki `_eru_ccl_location` (teslim noktası adı) değerini okur. Click & Collect for WooCommerce bu alanı yazar; kendi eklentiniz de aynı anahtarı yazabilir.

## Kısa kodlar, kancalar ve filtreler

Kanca yoktur.

## Entegrasyonlar ve gereksinimler

WordPress 6.5+, PHP 8.0+, WooCommerce 9.0+, HPOS uyumlu. İsteğe bağlı: Click & Collect for WooCommerce.

## Sınırlamalar

- Haftalık e-postayla rapor gönderme yoktur.
- En çok 20.000 sipariş işlenir; aşılırsa uyarı gösterilir.
- Yöntemler `method_id` ve sipariş satırındaki ada göre gruplanır.

## SSS

**Neden rapor boş?** Tarih aralığında seçili durumlarda sipariş yoktur; durumları genişletin.

**Eklentiyi silersem ne olur?** Eklenti seçenek, tablo ya da meta saklamaz; silinecek bir veri yoktur. Sipariş verisi değişmez, rapor yalnızca okur.

**Dış servise bağlanır mı?** Hayır, kodda dış HTTP isteği yoktur.

**HPOS ile uyumlu mu?** Evet, eklenti özel sipariş tabloları uyumluluğunu bildirir ve siparişleri 200'lük gruplarla okur.

**Çok sayıda siparişte ne olur?** En çok 20.000 sipariş işlenir; sınır aşılırsa "Sonuç üst sınıra ulaştı; tarih aralığını daraltın." uyarısı çıkar.

**Çeviri nasıl yapılır?** Metin alanı `shipping-method-report-for-woocommerce`; çeviri yolu `/languages`.

## Değişiklik geçmişi

Bkz. [CHANGELOG.md](CHANGELOG.md).

## Destek

- Önce bu sayfadaki **SSS** ve **Sınırlamalar** bölümlerine göz atın; sık karşılaşılan durumlar orada.
- Hata mı buldunuz? [GitHub Issues](https://github.com/erayusta/erusoft-wp/issues) üzerinden bildirin. WordPress, WooCommerce, PHP ve eklenti sürümünü, adımları ve beklenen/gerçekleşen sonucu yazmanız işi hızlandırır.
- Kurulum, yapılandırma, özel geliştirme veya entegrasyon desteği için **[hello@erusoft.com](mailto:hello@erusoft.com)** adresine yazın. Erusoft hakkında: [erusoft.com](https://erusoft.com).
- Lütfen şifre, API anahtarı veya müşteri verisi (ad, telefon, TCKN, sipariş dökümü) göndermeyin; ekran görüntülerindeki kişisel verileri karartın.

## Lisans

GPL-2.0-or-later. Diğer Erusoft eklentileri: https://wordpress.erusoft.com/
