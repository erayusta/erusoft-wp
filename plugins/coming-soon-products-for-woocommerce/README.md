# Coming Soon Products for WooCommerce

Ürünü "yakında" olarak işaretler; fiyat yerine rozet ve mesaj gösterir, satışı kapatır.

> **English:** Mark individual products as coming soon: purchase is blocked, add-to-cart is hidden and a badge plus message (with an optional release date) is shown instead of the price. Nothing changes until a product is flagged.

[![Lisans: GPL-2.0-or-later](https://img.shields.io/badge/lisans-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html) ![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg) ![WooCommerce 9.0+](https://img.shields.io/badge/WooCommerce-9.0%2B-96588a.svg) ![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)

![Ayarlar](../../docs/screenshots/coming-soon-products-for-woocommerce/1.png)
![Ürün düzenleme](../../docs/screenshots/coming-soon-products-for-woocommerce/2.png)
![Ürün sayfası, masaüstü](../../docs/screenshots/coming-soon-products-for-woocommerce/3.png)
![Ürün sayfası, mobil](../../docs/screenshots/coming-soon-products-for-woocommerce/4.png)

## Ne işe yarar

Henüz satışa açmadığınız ürünler için ürün sayfasında "Yakında" rozeti ve mesajı gösterilir, sepete ekleme kapanır. İsterseniz çıkış tarihi girersiniz; tarih gelince ürün kendiliğinden satışa açılır.

## Özellikler

- Ürün başına onay kutusu, mesaj ve çıkış tarihi (Genel sekmesi)
- Genel varsayılan mesaj ve rozet metni ayarlardan değiştirilebilir
- Ürün satın alınamaz hâle gelir (varyasyonlar dahil); sepete eklenemez
- Mağaza listelerinde ve ürün sayfasında fiyat yerine rozet, ürün sayfasında mesaj ve tahmini çıkış tarihi
- Çıkış tarihi gelince mod kendiliğinden biter
- Hiçbir ürün işaretlenmedikçe ön yüzde değişiklik olmaz

## Kurulum

1. `coming-soon-products-for-woocommerce` klasörünü `wp-content/plugins/` altına kopyalayın (veya zip olarak yükleyin).
2. Eklentiler ekranından etkinleştirin (WooCommerce gereklidir).
3. Ürün düzenleme ekranında Genel sekmesindeki "Yakında" kutusunu işaretleyin.

## Nasıl kullanılır

1. Eklentiyi etkinleştirin; WooCommerce → **Yakında Ürünler** ekranında varsayılan mesajı ve rozet metnini isteğinize göre değiştirin.
2. "Demo Ürün" (250 TL) düzenleme ekranında **Genel** sekmesindeki "Yakında" kutusunu işaretleyin.
3. İsterseniz özel bir mesaj ve çıkış tarihi girin (ör. gelecekteki bir gün); ürünü güncelleyin.
4. Mağaza listesinde fiyat yerine rozetin göründüğünü, ürün sayfasında mesaj ve tahmini çıkış tarihinin çıktığını, sepete ekleme düğmesinin olmadığını doğrulayın.
5. Çıkış tarihi geldiğinde (mağaza saat dilimindeki gün, tarihin kendisi dahil) ürün kendiliğinden normal satışa döner.

## Ayarlar

WooCommerce → **Yakında Ürünler**: varsayılan mesaj, rozet metni, fiyat yerine rozet, çıkış tarihini göster. Ayarlar `eru_csp_settings` seçeneğinde, ürün alanları `_eru_csp_enabled`, `_eru_csp_message`, `_eru_csp_date` meta alanlarında saklanır.

## Kancalar, filtreler ve veri alanları

| Ad | Tür | Açıklama |
|---|---|---|
| `Eru_CSP::is_active( $product )` | PHP | Ürün şu an yakında modunda mı |
| `_eru_csp_enabled / _eru_csp_message / _eru_csp_date` | ürün meta | `yes`, metin, `YYYY-AA-GG` |
| `.eru-csp-badge, .eru-csp-notice` | CSS | Rozet ve mesaj kutusu sınıfları |

## Entegrasyonlar ve gereksinimler

WordPress 6.5+, PHP 8.0+, WooCommerce 9.0+. HPOS uyumludur. Harici servis kullanmaz.

## Sınırlamalar

- Mesaj ve rozet klasik (şablon tabanlı) ürün sayfası kancalarıyla gösterilir; blok tabanlı ürün sayfası şablonlarında mesaj kutusu görünmeyebilir, satış engeli yine çalışır.
- Çıkış tarihi mağaza saat dilimine göre gün olarak değerlendirilir.
- Ürün için hatırlatma e-postası veya bekleme listesi yoktur.

## SSS

**Çıkış tarihi geçince ne olur?** Ürün kendiliğinden normal satışa döner; işaret kutusunun kaldırılması gerekmez.

**Eklentiyi silersem ürünlerim satışa açılır mı?** Eklenti devre dışı kalınca satış engeli ve rozet de kalkar, ürünler normal satılabilir olur. Eklenti silindiğinde yalnızca `eru_csp_settings` seçeneği silinir; ürünlerdeki `_eru_csp_*` meta alanları ürünle birlikte kalır.

**Varyasyonlu ürünlerde çalışır mı?** Evet. Satın alınabilirlik filtresi varyasyonlara da uygulanır; işaret ana ürüne konur.

**HPOS ve önbellek?** Eklenti özel sipariş tabloları uyumluluğunu bildirir. Çıkış tarihi sayfa yüklenirken hesaplandığı için tam sayfa önbelleği kullanıyorsanız rozet, tarih gününde önbellek yenilenene kadar görünmeye devam edebilir.

**Dış servis kullanıyor mu?** Hayır.

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
