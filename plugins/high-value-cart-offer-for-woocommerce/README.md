# High-Value Cart Offer for WooCommerce

Sepet tutarı eşiği aşıldığında sepet özetiyle WhatsApp sohbeti açan "Bu sepete özel teklif alın" kutusu.

> **English:** When the cart total reaches a configurable amount, shows an offer box whose button opens a WhatsApp chat (`wa.me`) pre-filled with the cart summary. Number, threshold and all texts are settings; nothing is shown until a WhatsApp number is set.

[![Lisans: GPL-2.0-or-later](https://img.shields.io/badge/lisans-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html) ![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg) ![WooCommerce 9.0+](https://img.shields.io/badge/WooCommerce-9.0%2B-96588a.svg) ![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)

![Ayarlar](../../docs/screenshots/high-value-cart-offer-for-woocommerce/1.png)

## Ne işe yarar

Yüksek tutarlı sepetlerde müşteriyi satış ekibine yönlendirir: kutudaki buton, ürün listesini ve sepet tutarını hazır yazılmış mesaj olarak WhatsApp'ta açar. Toplu alımlarda pazarlık veya özel teklif isteyen müşteriler için uygundur.

## Özellikler

- Sepet tutarı eşiği (boşsa her sepette görünür)
- WhatsApp numarası, başlık, açıklama, buton metni ve mesajın ilk satırı ayarlardan
- Mesaj: ilk satır + `- Ürün adı x adet` satırları + sepet tutarı (URL kodlu `wa.me` bağlantısı)
- Sepet sayfasında ve isteğe bağlı olarak mini sepette gösterim
- **Numara girilmedikçe hiçbir çıktı yoktur**

## Nasıl kullanılır

1. Eklentiyi etkinleştirin; WooCommerce → **Sepet Teklifi** ekranını açın.
2. WhatsApp numarası olarak ülke koduyla örnek bir numara girin (ör. `90XXXXXXXXXX`).
3. Eşiği 250 TL yapın; başlığı, açıklamayı ve buton metnini istediğiniz gibi yazın.
4. İsterseniz mini sepette gösterimi açıp kaydedin.
5. Mağazada "Demo Ürün"ü sepete ekleyip sepet sayfasını açın: tutar 250 TL'yi aşınca teklif kutusu görünür; altındaysa görünmez.
6. Butona tıklayın: WhatsApp, ürün listesi ve sepet tutarı hazır yazılmış bir mesajla açılır.

## Kurulum

1. `high-value-cart-offer-for-woocommerce` klasörünü `wp-content/plugins/` altına kopyalayın (veya zip olarak yükleyin).
2. Eklentiler ekranından etkinleştirin (WooCommerce gereklidir).
3. Aşağıdaki ayar ekranından yapılandırın. Yapılandırma yapılana kadar mağazanızda hiçbir değişiklik olmaz.

## Ayarlar

WooCommerce → **Sepet Teklifi**. Ayarlar `eru_hvco_settings` seçeneğinde saklanır. Numara ülke koduyla girilir (ör. `90XXXXXXXXXX`); 0 ile başlayan Türkiye numaraları, başında 5 olan 10 haneli cep numaraları ve `00` öneki Catalog Mode & WhatsApp Orders ile aynı kurallarla uluslararası biçime çevrilir. Eşik, kupon sonrası ve kargo hariç sepet tutarına (KDV dahil) bakar.

## Kancalar, filtreler ve veri alanları

| Ad | Tür | Açıklama |
|---|---|---|
| `eru_hvco_settings` | seçenek | `number`, `threshold`, `title`, `message`, `button`, `intro`, `show_cart`, `show_mini` |
| `eru_hvco_cart_value` | filtre | Eşik karşılaştırmasında kullanılan sepet değeri (`$value`, `$cart`) |

## Entegrasyonlar ve gereksinimler

WordPress 6.5+, PHP 8.0+, WooCommerce 9.0+. HPOS (özel sipariş tabloları) ile uyumludur. WooCommerce yoksa eklenti bir uyarı gösterir ve hiçbir şey yapmaz. Harici servis kullanmaz.

## Sınırlamalar

- Yalnızca bir bağlantı gösterir; WhatsApp Business API ile mesaj göndermez ve dış HTTP isteği yapmaz.
- Kutu klasik sepet sayfasında ve mini sepette çıkar; blok tabanlı sepette görünmez.
- Yüzen WhatsApp butonu içermez.
- Eklenti silindiğinde (Eklentiler → Sil) yalnızca kendi ayarı silinir.

## SSS

**Müşterinin telefonu paylaşılır mı?** Hayır; bağlantı yalnızca sizin numaranızı ve sepet özetini içerir, sohbeti müşteri kendi WhatsApp hesabıyla başlatır.

**Eklentiyi silersem ne olur?** Silme sırasında yalnızca `eru_hvco_settings` seçeneği silinir. Devre dışı bırakmak ayarı silmez.

**Dış servise bağlanır mı?** Hayır. Kutu yalnızca bir `wa.me` bağlantısı üretir; sitenizden dış HTTP isteği yapılmaz.

**Blok tabanlı sepette çalışır mı?** Hayır; kutu klasik sepet sayfasında ve mini sepette çıkar (bkz. Sınırlamalar).

**Eşik hangi tutara bakar?** Kupon sonrası, kargo hariç sepet tutarına (KDV dahil). Başka bir değer kullanmak için `eru_hvco_cart_value` filtresi vardır.

**Çeviri nasıl yapılır?** Metin alanı `high-value-cart-offer-for-woocommerce`; çeviri yolu `/languages`.

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
