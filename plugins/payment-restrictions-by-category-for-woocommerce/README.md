# Payment Restrictions by Category for WooCommerce

Sepette seçtiğiniz kategorilerden ürün varsa belirlediğiniz ödeme yöntemlerini gizler.

> **English:** Hides chosen payment gateways when the cart contains a product from selected categories (or other product taxonomies such as tags or brands) and can show explanatory notices. With no rules the plugin does nothing.

[![Lisans: GPL-2.0-or-later](https://img.shields.io/badge/lisans-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html) ![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg) ![WooCommerce 9.0+](https://img.shields.io/badge/WooCommerce-9.0%2B-96588a.svg) ![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)

![Kural tablosu](../../docs/screenshots/payment-restrictions-by-category-for-woocommerce/1.png)

![Ürün sayfası notu (masaüstü)](../../docs/screenshots/payment-restrictions-by-category-for-woocommerce/2.png)

![Ürün sayfası notu (mobil)](../../docs/screenshots/payment-restrictions-by-category-for-woocommerce/3.png)

## Ne işe yarar

Bazı ürünlerin her ödeme yöntemiyle satılmasını istemeyebilirsiniz (örneğin yalnızca havale ile satılan ürünler). Bu eklenti, sepette ilgili sınıftan bir ürün olduğunda seçtiğiniz ödeme yöntemlerini ödeme sayfasından kaldırır ve müşteriye nedenini anlatan bir not gösterebilir.

## Özellikler

- Kural: ürün sınıfları + gizlenecek ödeme yöntemleri; birden çok kural
- Kategori, etiket ve ürüne bağlı diğer sınıflandırmalar seçilebilir (alt kategoriler otomatik kapsanır)
- Sepet ve ödeme sayfasında isteğe bağlı bilgi notu
- Ürün sayfasında isteğe bağlı bilgi notu
- Karışık sepette (kısıtlı + kısıtsız ürün) yöntem gizlenir
- Varyasyonlu ürünlerde üst ürünün sınıfları kullanılır

## Kurulum

1. `payment-restrictions-by-category-for-woocommerce` klasörünü `wp-content/plugins/` altına kopyalayın (veya zip olarak yükleyin).
2. Eklentiler ekranından etkinleştirin (WooCommerce gereklidir).
3. Aşağıdaki ayar ekranından yapılandırın. Yapılandırma yapılana kadar mağazanızda hiçbir değişiklik olmaz.

## Ayarlar

WooCommerce → **Ödeme Kısıtları**. Kurallar `eru_prc_rules` seçeneğinde saklanır; varsayılan boştur.

## Kancalar, filtreler ve veri alanları

| Ad | Tür | Açıklama |
|---|---|---|
| `eru_prc_rules` | seçenek | Kural listesi (`terms` = `taksonomi:terim_id`, `gateways`, `notice`, `product_notice`) |
| `woocommerce_available_payment_gateways` | filtre | Eklenti 100 önceliğinde ödeme yöntemlerini çıkarır |

## Entegrasyonlar ve gereksinimler

WordPress 6.5+, PHP 8.0+, WooCommerce 9.0+. HPOS (özel sipariş tabloları) ile uyumludur. WooCommerce yoksa eklenti bir uyarı gösterir ve hiçbir şey yapmaz. Harici servis kullanmaz; dış HTTP isteği yapmaz.

## Sınırlamalar

- Tüm ödeme yöntemleri gizlenirse WooCommerce "uygun ödeme yöntemi yok" iletisi gösterir; kuralları buna göre planlayın.
- Yönetici ekranlarında (AJAX hariç) filtre çalışmaz.
- Notlar kısıtlı HTML (`wp_kses_post`) kabul eder.
- Eklenti silindiğinde (Eklentiler → Sil) yalnızca kendi ayarı (`eru_prc_rules` seçeneği) silinir.

## SSS

**Alt kategoriler kapsanır mı?** Evet, seçtiğiniz kategorinin alt kategorilerindeki ürünler de kuralı tetikler.

**Hangi ödeme yöntemleri listelenir?** WooCommerce'e kayıtlı tüm ödeme yöntemleri; etkin olup olmamaları fark etmez.

## Değişiklik geçmişi

Bkz. [CHANGELOG.md](CHANGELOG.md).

## Lisans

GPL-2.0-or-later.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com/
