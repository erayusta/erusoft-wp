# Bulk Product Visibility for WooCommerce

Ürünlerin katalog görünürlüğünü veya yayın durumunu kategori, stok ve fiyata göre toplu değiştirin.

> **English:** An admin tool to change catalog visibility or status of many products at once, filtered by category, stock status and price. A dry-run count must be shown before anything is applied, and the work runs in small AJAX batches.

[![Lisans: GPL-2.0-or-later](https://img.shields.io/badge/lisans-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html) ![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg) ![WooCommerce 9.0+](https://img.shields.io/badge/WooCommerce-9.0%2B-96588a.svg) ![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)

![Toplu görünürlük ekranı](../../docs/screenshots/bulk-product-visibility-for-woocommerce/1.png)

## Ne işe yarar

Binlerce ürünü tek tek açmadan: kategori, stok durumu ve fiyat aralığına göre filtreleyin, uygulanacak değişikliği seçin, önce kaç ürünün etkileneceğini görün, sonra onaylayın. İşlem küçük gruplar halinde yürütülür.

## Özellikler

- Filtreler: kategori (alt kategoriler dahil), stok durumu, en az / en çok fiyat
- Değişiklikler: katalog görünürlüğü (görünür, yalnızca mağaza, yalnızca arama, gizli) ve yayın durumu (yayında, taslak, özel)
- Zorunlu önizleme: eşleşen ürün sayısı ve örnek adlar; önizleme yapılmadan uygulama çalışmaz
- Gruplar halinde (varsayılan 50 ürün) AJAX ile uygulama, ilerleme çubuğu
- Yetki: `manage_woocommerce`; her istekte nonce doğrulaması

## Kurulum

1. `bulk-product-visibility-for-woocommerce` klasörünü `wp-content/plugins/` altına kopyalayın (veya zip olarak yükleyin).
2. Eklentiler ekranından etkinleştirin (WooCommerce gereklidir).
3. Ürünler → **Toplu Görünürlük** ekranını açın.

## Ayarlar

Ürünler → **Toplu Görünürlük**. Kalıcı ayar yoktur; önizleme belirteci bir saat geçerli bir geçici veridir (transient) ve kullanıcıya bağlıdır.

## Kancalar, filtreler ve veri alanları

| Ad | Tür | Açıklama |
|---|---|---|
| `eru_bpv_batch_size` | filtre | Grup başına ürün sayısı (50) |
| `Eru_BPV_Engine::preview()` | PHP | Filtre ve değişiklikle kuru çalıştırma, belirteç döner |
| `Eru_BPV_Engine::run_batch()` | PHP | Belirteç ve konumla bir grubu uygular |

## Entegrasyonlar ve gereksinimler

WordPress 6.5+, PHP 8.0+, WooCommerce 9.0+. HPOS uyumludur. Harici servis kullanmaz; Action Scheduler gerektirmez.

## Sınırlamalar

- Yalnızca ana ürünlere uygulanır; varyasyonlar ana ürünün görünürlüğünü izler.
- Fiyat filtresi ürünün güncel `_price` değerine bakar; varyabl ürünlerde en düşük fiyattır.
- Önizleme ile uygulama arasında yeni ürün eklenirse bu ürünler o işleme dahil olmaz.
- Değişiklik geri alınamaz; önce küçük bir filtreyle deneyin.
- Bir işlemde en fazla 5000 ürün değiştirilir (`eru_bpv_max_products` filtresi); daha fazlasıyla eşleşen filtre önizlemede reddedilir.

## SSS

**Sayfayı kapatırsam ne olur?** İşlem o ana kadar uygulanan gruplarla durur; önizlemeyi yeniden yapıp devam edebilirsiniz (zaten uygulanmış ürünler aynı değeri alır).

## Değişiklik geçmişi

Bkz. [CHANGELOG.md](CHANGELOG.md).

## Lisans

GPL-2.0-or-later.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com/
