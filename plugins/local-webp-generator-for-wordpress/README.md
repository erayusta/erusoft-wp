# Local WebP Generator for WordPress

Yüklenen görsellerin her boyutu için sunucunuzda WebP kopyası üretir ve `<picture>` ile sunar; orijinallere dokunmaz.

> **English:** Creates a `.webp` copy of every uploaded JPEG/PNG and each generated size with GD or Imagick, regenerates existing media in batches with a progress bar, serves via `<picture>` (or swapped URLs) and shows an `.htaccess` snippet that it never writes itself. No external service.

[![Lisans: GPL-2.0-or-later](https://img.shields.io/badge/lisans-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html) ![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg) ![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)

![Ayarlar ve toplu dönüştürme](../../docs/screenshots/local-webp-generator-for-wordpress/1.png)

## Ne işe yarar

Her yüklemede orijinal ve tüm ara boyutlar için `dosya.jpg.webp` kardeşi üretilir. Sayfalarda görsel `<picture>` ile WebP destekleyen tarayıcılara verilir, diğerleri orijinali almaya devam eder. Mevcut medya kitaplığı küçük gruplar halinde dönüştürülür.

## Özellikler

- GD (`imagewebp`) veya Imagick ile yerel üretim; motor yoksa yönetim panelinde uyarı
- Kalite ayarı ve en küçük dosya eşiği; WebP orijinalden büyük çıkarsa saklanmaz
- Dönüştürme hataları (bellek, bozuk dosya) yüklemeyi asla bozmaz; yol, uploads klasörünün dışına çıkamaz
- Orijinal dosyalar asla değiştirilmez veya üzerine yazılmaz
- Mevcut görseller için gruplar halinde toplu dönüştürme ve ilerleme çubuğu (var olanları atlar)
- Ek silinince WebP kardeşleri de silinir
- Sunum: `<picture>` (varsayılan), doğrudan `.webp` adresi veya kapalı; Apache için `.htaccess` kuralı yalnızca gösterilir

## Kurulum

1. `local-webp-generator-for-wordpress` klasörünü `wp-content/plugins/` altına kopyalayın (veya zip olarak yükleyin).
2. Eklentiler ekranından etkinleştirin.
3. Ayarları yapın (aşağıya bakın).

## Ayarlar

Ayarlar → **Yerel WebP**: kalite (30-100), en küçük dosya (bayt), sunum biçimi, toplu dönüştürme düğmesi ve `.htaccess` örneği. Seçenek: `eru_webp_settings`.

## Kısa kodlar, kancalar, filtreler

| Ad | Tür | Açıklama |
|---|---|---|
| `eru_webp_settings` | seçenek | Eklenti ayarları |
| `eru_webp_max_megapixels` | filtre | `($mp, $yol)`; bundan büyük görseller dönüştürülmez (varsayılan 40 MP, bellek koruması) |
| `eru_webp_serve_in_admin` | filtre | `<picture>` çıktısını yönetim ekranlarında da açar (varsayılan kapalı) |
| `wp_ajax_eru_webp_bulk` | AJAX | Toplu dönüştürme (yalnızca `manage_options`, nonce'lu) |

## Entegrasyonlar ve gereksinimler

WordPress 6.5+, PHP 8.0+, GD (WebP destekli) veya Imagick (WEBP biçimi). Harici servis kullanmaz.


## Sınırlamalar

- Yalnızca JPEG ve PNG dönüştürülür; GIF ve SVG atlanır.
- Tema CSS'i `picture` etiketini hedefliyorsa kontrol edin; sorun olursa sunumu "Adresleri doğrudan .webp yap" yapın.
- `.htaccess` kuralı hiçbir zaman otomatik yazılmaz.
- Eklenti silinince üretilmiş `.webp` dosyaları uploads içinde kalır.

## SSS

**Orijinal görseller silinir mi?** Hayır, orijinaller hiç değiştirilmez; WebP kopyaları yanlarında durur.

## Değişiklik geçmişi

Bkz. [CHANGELOG.md](CHANGELOG.md).

## Lisans

GPL-2.0-or-later.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com/
