# Order JSON Export for WooCommerce

Siparişleri belgelenmiş, nötr bir şemayla JSON olarak dışa aktarır: toplu işlem, tarih aralığı ekranı ve yetkili REST uç noktası.

> **English:** Exports WooCommerce orders as JSON with a documented schema (order, items, billing, shipping, totals, allow-listed meta). Bulk action in the orders list, a date-range screen and an authenticated REST endpoint. Only meta keys you allow are exported.

[![Lisans: GPL-2.0-or-later](https://img.shields.io/badge/lisans-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html) ![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg) ![WooCommerce 9.0+](https://img.shields.io/badge/WooCommerce-9.0%2B-96588a.svg) ![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)

![Ekran görüntüsü 1](../../docs/screenshots/order-json-export-for-woocommerce/1.png)

## Ne işe yarar

Muhasebe, ERP veya kendi yazdığınız entegrasyonlar için siparişleri tek bir tutarlı JSON şemasında alırsınız. Hangi sipariş meta alanlarının dışarı çıkacağına siz karar verirsiniz; listede olmayan hiçbir meta aktarılmaz.

## Özellikler

- Sipariş listesinden seçili siparişler için **JSON olarak dışa aktar** toplu işlemi (HPOS ve klasik liste)
- WooCommerce → JSON Dışa Aktarım ekranında tarih aralığı ve durum filtresiyle indirme
- Satır bazında SKU, adet, birim fiyat, KDV tutarı ve KDV oranı; kargo, ücret ve kupon satırları
- İzinli meta anahtarları (varsayılan boş); müşteri notu isteğe bağlı
- Yetkili REST uç noktası: `GET /wp-json/eru-oje/v1/orders?after=…` (`manage_woocommerce`, Uygulama Parolaları)
- Sayfa başına en çok 100 sipariş; `X-WP-Total` ve `X-WP-TotalPages` başlıkları
- Türkçe karakterler kaçışsız (UTF-8) yazılır

## Kurulum

1. Klasörü `wp-content/plugins/` altına kopyalayın ve etkinleştirin (WooCommerce gerekir).
2. WooCommerce → **JSON Dışa Aktarım** ekranından indirin veya Siparişler listesinde toplu işlem kullanın.

## Ayarlar

| Ayar | Varsayılan |
|---|---|
| `eru_oje_meta_allowlist` İzinli sipariş meta anahtarları (satır başına bir) | boş |
| `eru_oje_include_note` Müşteri notunu dahil et | kapalı |

**JSON şeması (`eru-oje/1`):** `schema`, `generated_at`, `count`, `orders[]`. Her sipariş: `id`, `number`, `status`, `currency`, `created_at`/`paid_at`/`completed_at` (ISO 8601, site saat dilimi), `payment_method`, `payment_method_title`, `customer_note`, `totals{subtotal,discount,shipping,fees,tax,total,refunded}`, `billing{…}`, `shipping{…}`, `items[{product_id,variation_id,sku,name,quantity,unit_price,subtotal,subtotal_tax,total,total_tax,tax_rate}]`, `shipping_lines[]`, `fees[]`, `coupons[]`, `meta{}`.

## Kısa kodlar, kancalar ve filtreler

| Ad | Tür | Açıklama |
|---|---|---|
| `eru_oje_order` | filtre | Dışa aktarılan tek siparişin dizisi (`$data`, `$order`) |
| `eru_oje_meta_allowlist` | filtre | İzinli meta anahtarları |
| `GET /eru-oje/v1/orders` | REST | `after`, `before`, `status`, `per_page` (1-100), `page` |

## Entegrasyonlar ve gereksinimler

WordPress 6.5+, PHP 8.0+, WooCommerce 9.0+, HPOS uyumlu.

## Sınırlamalar

- Zamanlanmış (cron) dışa aktarma, FTP gönderimi ve fatura yükleme yoktur; dosyayı elle indirir veya REST ile çekersiniz.
- Tarih aralığı indirmesi en çok 5.000 siparişle sınırlıdır; aralıkta daha fazla sipariş varsa indirme yapılmaz ve ekranda uyarı gösterilir (sessizce kesilmez). Aralığı daraltın veya REST sayfalamasını kullanın.
- JSON dosyası müşteri kişisel verisi içerir: güvenli saklayın, KVKK yükümlülüklerinize uyun.

## SSS

**Hangi meta alanları dışarı çıkar?** Yalnızca ayarlardaki izin listesinde olanlar. Liste varsayılan olarak boştur.

**REST uç noktasını kim çağırabilir?** `manage_woocommerce` yetkisi olan kullanıcılar; Uygulama Parolası ile Basic kimlik doğrulama kullanın.

Kaldırma: `eru_oje_*` ayarları silinir; siparişlere dokunulmaz.

## Değişiklik geçmişi

Bkz. [CHANGELOG.md](CHANGELOG.md).

## Lisans

GPL-2.0-or-later. Diğer Erusoft eklentileri: https://wordpress.erusoft.com/
