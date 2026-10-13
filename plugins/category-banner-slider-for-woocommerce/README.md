# Category Banner Slider for WooCommerce

Ürün kategorisi sayfalarına masaüstü, tablet ve mobil için ayrı görselli, duyarlı banner slider ekler.

> **English:** Adds a responsive banner slider with separate desktop, tablet and mobile images to product category pages, or anywhere with a shortcode. Default: no position selected, so nothing changes until you choose one.

[![Lisans: GPL-2.0-or-later](https://img.shields.io/badge/lisans-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html) ![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg) ![WooCommerce 9.0+](https://img.shields.io/badge/WooCommerce-9.0%2B-96588a.svg) ![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)

![Kategori düzenleme ekranında slayt listesi](../../docs/screenshots/category-banner-slider-for-woocommerce/1.png)

![Kategori sayfası (masaüstü)](../../docs/screenshots/category-banner-slider-for-woocommerce/2.png)

![Kategori sayfası (mobil, 390 px)](../../docs/screenshots/category-banner-slider-for-woocommerce/3.png)

## Ne işe yarar

Her ürün kategorisi için sıralı bir slayt listesi tanımlarsınız (görsel, isteğe bağlı bağlantı ve alternatif metin). Slider kategori sayfasında seçtiğiniz konumda veya `[eru_category_slider]` kısa koduyla istediğiniz yerde görünür.

## Özellikler

- Kategori başına sıralanabilir slaytlar (sürükle-bırak)
- Masaüstü / tablet / mobil için ayrı görsel; eksikse bir üst boyuta döner
- Genel (varsayılan) slaytlar: kendi slideri olmayan kategorilerde gösterilir
- Konum ayarı: içeriğin başı, kategori açıklaması veya ürün listesinden önce
- Kısa kod: `[eru_category_slider term="kategori-slug"]`
- Bağımlılıksız hafif JS (jQuery yok): oklar, noktalar, otomatik geçiş, dokunmatik kaydırma
- Kaldırıldığında ayarlar ve kategori verileri silinir

## Kurulum

1. `category-banner-slider-for-woocommerce` klasörünü `wp-content/plugins/` altına kopyalayın (veya zip olarak yükleyin).
2. Eklentiler ekranından etkinleştirin (WooCommerce gereklidir).
3. WooCommerce → **Kategori Slider**: konumu ve davranışı seçin. Ürünler → Kategoriler → bir kategoriyi düzenleyin → **Kategori Slider** bölümünde slaytları ekleyip kutuyu işaretleyin.

## Ayarlar

Seçenekler: `eru_cbs_settings` (konum, otomatik geçiş, süre, ok/nokta, döngü, yükseklikler), `eru_cbs_global_slides`. Kategori verisi: `_eru_cbs_enabled`, `_eru_cbs_slides` (terim meta).

## Kısa kodlar, kancalar ve filtreler

| Ad | Tür | Açıklama |
|---|---|---|
| `[eru_category_slider term="slug"]` | kısa kod | Belirtilen kategorinin slaytlarını gösterir (kategori sayfasında `term` verilmezse mevcut kategori). Kategorideki "slider göster" kutusundan bağımsızdır. |
| `eru_cbs_settings` | seçenek | Genel davranış ayarları |

## Entegrasyonlar ve gereksinimler

WordPress 6.5+, PHP 8.0+, WooCommerce 9.0+. HPOS uyumludur. Harici servis kullanmaz.

## Sınırlamalar

- Konum seçenekleri bloktan bağımsız klasik WooCommerce kancalarıdır; blok tabanlı temalarda içerik genişliği temanın düzenine bağlı olabilir. Tam genişlik için kısa kodu kendi bloğunuza yerleştirin.
- Görseller medya kütüphanesinden seçilir; harici bağlantılı görsel desteklenmez.

## SSS

**Slider görünmüyor.** Ayarlarda bir konum seçtiğinizden, kategoride kutuyu işaretlediğinizden (veya genel slayt tanımladığınızdan) ve en az bir slaytta masaüstü görseli seçtiğinizden emin olun.

## Değişiklik geçmişi

Bkz. [CHANGELOG.md](CHANGELOG.md).

## Lisans

GPL-2.0-or-later.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com/
