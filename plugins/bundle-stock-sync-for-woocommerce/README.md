# Bundle Stock Sync for WooCommerce

Bileşen SKU ve adetleriyle paket ürünler tanımlayın; paket stoğu bileşenlerden hesaplansın ve satışlarla tutarlı kalsın.

> **English:** Define a simple product as a bundle of component SKUs and quantities. Bundle stock = min(component stock ÷ quantity). Selling a bundle reduces its components; selling, restocking, unpublishing or refunding a component recalculates every bundle that contains it. Guarded against recursion; bundles cannot contain bundles.

[![Lisans: GPL-2.0-or-later](https://img.shields.io/badge/lisans-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html) ![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg) ![WooCommerce 9.0+](https://img.shields.io/badge/WooCommerce-9.0%2B-96588a.svg) ![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)

![Paket içeriği sekmesi](../../docs/screenshots/bundle-stock-sync-for-woocommerce/1.png)
![Ayarlar ve tanımlı paketler](../../docs/screenshots/bundle-stock-sync-for-woocommerce/2.png)

## Ne işe yarar

"Set" ürünlerinizin stoğunu elle yönetmeyin. Ürün düzenleme ekranındaki **Paket içeriği** sekmesine bileşen SKU'larını ve paket başına adetleri girin; paketin stoğu otomatik hesaplanır. Örnek: A (stok 10) ×2 ve B (stok 3) ×1 → paket stoğu 3. 1 paket satılınca A 8, B 2, paket 2 olur.

## Özellikler

- Basit ürüne bileşen listesi (SKU + adet); durum sütunu SKU'nun bulunup bulunmadığını ve bileşen stoğunu gösterir
- Paket stoğu = en küçük (bileşen stoğu ÷ adet); stok takibi olmayan bileşenler sınırlamaz
- Paket satılınca bileşen stoğu düşer (HPOS uyumlu; WooCommerce stok düşürme akışına bağlanır)
- Sipariş iptali / beklemeye dönme ve iade (stoğa geri ekle) bileşenleri geri yükler; işlem kalem başına kaydedilir, iki kez geri yüklenmez
- Bileşen stoğu, durumu, yayın durumu (taslak/çöp) değişince bu bileşeni içeren tüm paketler yeniden hesaplanır
- Özyineleme koruması; paket içinde paket (ve kendini içerme) reddedilir
- İsteğe bağlı yönetici e-postası (varsayılan kapalı)

## Kurulum

1. `bundle-stock-sync-for-woocommerce` klasörünü `wp-content/plugins/` altına kopyalayın.
2. Etkinleştirin (WooCommerce gereklidir).
3. Bir ürünü düzenleyin → Ürün verisi → **Paket içeriği**.

## Nasıl kullanılır

1. Eklentiyi etkinleştirin. Stok takibi açık iki basit ürün hazırlayın: "Demo Parça A" (SKU `DEMO-A`, stok 10) ve "Demo Parça B" (SKU `DEMO-B`, stok 3).
2. Yeni bir basit ürün oluşturun: "Demo Set", fiyat 250 TL.
3. Ürün verisi → **Paket içeriği** sekmesinde iki satır ekleyin: `DEMO-A` adet 2, `DEMO-B` adet 1. Durum sütunu SKU'ların bulunduğunu ve bileşen stoklarını gösterir.
4. Ürünü kaydedin. "Demo Set" stoğu otomatik 3 olur (A: 10 ÷ 2 = 5, B: 3 ÷ 1 = 3; en küçüğü).
5. Mağazadan bir "Demo Set" siparişi verin: A 8, B 2, paket 2 olur.
6. Siparişi iptal edin ya da iade edip stoğa geri ekleyin: bileşenler geri yüklenir, paket yeniden 3 olur.

## Ayarlar

Ürünler → **Paket Stok Eşitleme**: yönetici bildirimi ve alıcı e-posta (boşsa site yöneticisi). Tanımlı paketlerin listesi aynı ekrandadır. Paket tanımlanmadıkça eklenti hiçbir şeyi değiştirmez.

## Kancalar, filtreler ve veri alanları

| Ad | Tür | Açıklama |
|---|---|---|
| `eru_bss_max_depth` | filtre | Yeniden hesaplama derinlik sınırı (1) |
| `eru_bss_lock_seconds` | filtre | Paket başına kilit süresi (30) |
| `eru_bss_before_save` | eylem | Paket kaydedilmeden hemen önce (bileşenler bundan sonra yeniden okunur) |
| `Eru_BSS_Bundles::set_components()` | PHP | Paket tanımla (SKU + adet listesi); `WP_Error` dönebilir |
| `Eru_BSS_Sync::recalc()` | PHP | Bir paketi yeniden hesapla |
| `_eru_bss_components` | ürün meta | Bileşen listesi |

## Entegrasyonlar ve gereksinimler

WordPress 6.5+, PHP 8.0+, WooCommerce 9.0+. HPOS uyumludur. Harici servis yok. WooCommerce Product Bundles gibi başka paket eklentileriyle birlikte kullanılmak üzere tasarlanmamıştır.

## Sınırlamalar

- Yalnızca basit ürünler paket olabilir; bileşen basit ürün ya da varyasyon olabilir (SKU ile bulunur). Paket içinde paket desteklenmez.
- Paketin elle girilen stok değeri üzerine yazılır. Paketin yalnızca stok takibi olmayan bileşenleri varsa paket stok takibi kapatılır.
- Bileşen SKU'su sonradan değişir ya da bileşen sonradan oluşturulursa, paketi bir kez kaydedin.
- Stoğu bitmiş ama ön sipariş (backorder) izni olan bileşen paketi sınırlamaz.
- Eşzamanlı siparişlerde her paket kısa bir kilitle (varsayılan 30 sn, `eru_bss_lock_seconds`) yeniden hesaplanır ve kaydetmeden hemen önce bileşen stoğu yeniden okunur. Kilit alınamazsa o hesap atlanır ve paket bir sonraki stok olayında düzelir; çok yoğun trafikte kısa süreli (saniyeler) sapma kalma riski vardır.

## SSS

**Bileşen taslağa alınırsa?** Paket stokta yok olur; yeniden yayınlayınca geri döner.

**Eklentiyi devre dışı bırakırsam ya da silersem ne olur?** Devre dışı bırakınca hesaplama durur; paketler en son hesaplanan stokla sıradan basit ürün gibi kalır. Eklentiler → Sil yalnızca `eru_bss_settings` seçeneğini siler; paket tanımları (ürün meta `_eru_bss_components`) ve sipariş kalemi kayıtları ürün ve siparişlere ait olduğu için saklanır.

**HPOS ve Cart/Checkout blokları?** HPOS uyumluluğu bildirilir. Düşüm WooCommerce'in stok düşürme akışına bağlandığı için klasik ve blok ödeme aynı yoldan geçer; blok için ayrı bir bildirim yoktur.

**Dış servis, çeviri, çoklu site?** Dış servis yok. Metin alanı `bundle-stock-sync-for-woocommerce`, alan yolu `/languages`; klasörde hazır çeviri dosyası yoktur. Ağ düzeyinde etkinleştirme için özel bir destek yoktur.

**Bileşeni olmayan SKU yazarsam?** Durum sütunu SKU'nun bulunmadığını gösterir. Bileşen sonradan oluşturulursa paketi bir kez kaydedin.

**Geniş katalogda yavaşlar mı?** Bir bileşen değiştiğinde yalnızca onu içeren paketler yeniden hesaplanır; paket başına kısa bir kilit kullanılır.

## Değişiklik geçmişi

Bkz. [CHANGELOG.md](CHANGELOG.md).

## Destek

- Önce bu sayfadaki **SSS** ve **Sınırlamalar** bölümlerine göz atın; sık karşılaşılan durumlar orada.
- Hata mı buldunuz? [GitHub Issues](https://github.com/erayusta/erusoft-wp/issues) üzerinden bildirin. WordPress, WooCommerce, PHP ve eklenti sürümünü, adımları ve beklenen/gerçekleşen sonucu yazmanız işi hızlandırır.
- Kurulum, yapılandırma, özel geliştirme veya entegrasyon desteği için **[hello@erusoft.com](mailto:hello@erusoft.com)** adresine yazın. Erusoft hakkında: [erusoft.com](https://erusoft.com).
- Lütfen şifre, API anahtarı veya müşteri verisi (ad, telefon, TCKN, sipariş dökümü) göndermeyin; ekran görüntülerindeki kişisel verileri karartın.

## Lisans

GPL-2.0-or-later.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com/
