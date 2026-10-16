# Content Automation for WordPress

Konu kuyruğunu WP-Cron ile yapay zekâya yazdırır; varsayılan olarak taslak kaydeder.

> **English:** A scheduled content pipeline: a topic queue is turned into posts by OpenAI, Anthropic or Perplexity on a WP-Cron schedule. Disabled by default; bring your own key (host-bound, never echoed back); drafts by default, bounded batch per run, overlap lock, daily request cap. Site owners are responsible for AI output and provider terms.

[![Lisans: GPL-2.0-or-later](https://img.shields.io/badge/lisans-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html) ![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg) ![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)

![Ayarlar ve kuyruk](../../docs/screenshots/content-automation-for-wordpress/1.png)

## Ne işe yarar

Konuları (isteğe bağlı anahtar kelimelerle) kuyruğa eklersiniz; açtığınızda her çalışmada sınırlı sayıda konu için yazı üretilir. Varsayılan kural taslaktır; zamanlı yayın ya da hemen yayın seçilebilir.

## Özellikler

- Konu kuyruğu (her satıra bir konu, `konu | anahtar kelime`), durum izleme ve temizleme
- WP-Cron ile saatlik / günde iki kez / günlük çalışma; yalnızca etkinleştirilince zamanlanır
- Çalışma başına en çok 1-3 konu, çakışmayı önleyen kilit, 3 başarısız denemeden sonra "başarısız"
- Yayın kuralı: taslak (varsayılan), incelemede, zamanla (gecikmeli) veya hemen yayımla
- OpenAI, Anthropic (`claude-sonnet-5-5`) ve Perplexity; anahtarlar parola alanı, autoload kapalı, sağlayıcı adresine bağlı
- Günlük istek sınırı ve istek başına token sınırı
- İsteğe bağlı öne çıkan görsel (yalnızca OpenAI görsel yanıtı)
- Çakışmayı önleyen atomik kilit (belirteçli, süresi dolunca devralınır)
- "Zamanla" ve "hemen yayımla" kurallarında harici adresli `<img>` etiketleri kaldırılır; taslaklarda kalır (karar sizindir)
- Çıktı `wp_kses_post` ile temizlenir; anahtar yoksa hiçbir istek gönderilmez

## Kurulum

1. `content-automation-for-wordpress` klasörünü `wp-content/plugins/` altına kopyalayın (veya zip olarak yükleyin).
2. Eklentiler ekranından etkinleştirin.
3. Ayarları yapın (aşağıya bakın).

## Nasıl kullanılır

Örnek senaryo: tek bir konudan taslak yazı üretmek.

1. Eklentiyi etkinleştirin ve **Ayarlar → İçerik Otomasyonu** ekranını açın.
2. Bir sağlayıcı seçin (OpenAI, Anthropic veya Perplexity) ve kendi API anahtarınızı o sağlayıcının alanına girin; örnek anahtar biçimi için sağlayıcının belgelerine bakın. Yayın kuralını "Taslak (önerilen)" bırakın.
3. "Konu kuyruğu" kutusuna `WordPress yedekleme için 5 ipucu | yedekleme, güvenlik` yazıp **Kuyruğa ekle**'ye basın.
4. Günlük istek sınırını 5, çalışma başına konu sayısını 1 yapıp kaydedin.
5. **Şimdi çalıştır**'a basın. Birkaç saniye sonra "Çalıştırıldı: 1 yazı oluşturuldu" bildirimi gelir; kuyrukta konunun durumu "Tamam" olur ve yanında oluşan yazının bağlantısı görünür.
6. **Yazılar** ekranında taslağı açıp okuyun, düzenleyin ve kendiniz yayımlayın. Zamanlanmış çalışma için "Zamanlanmış çalışmayı etkinleştir" kutusunu işaretleyin.

## Ayarlar

Ayarlar → **İçerik Otomasyonu**: etkinleştirme, sıklık, çalışma başına konu, sağlayıcı/anahtar/model, dil, yayın kuralı, kategori, sınırlar, görsel; aynı ekranda kuyruk ve "Şimdi çalıştır". Seçenekler: `eru_ca_settings`, `eru_ca_keys`, `eru_ca_queue`, `eru_ca_usage`, `eru_ca_lock`.

## Kısa kodlar, kancalar, filtreler

| Ad | Tür | Açıklama |
|---|---|---|
| `eru_ca_tick` | eylem (cron) | Boru hattının zamanlanmış çalışması |
| `eru_ca_endpoint` | filtre | `($url, $sağlayıcı)`; adres sağlayıcı sunucusuyla eşleşmek zorundadır |
| `_eru_ca_item` | yazı meta | Kuyruk öğesi numarası |

## Entegrasyonlar ve gereksinimler

WordPress 6.5+, PHP 8.0+. WP-Cron çalışmalıdır (düşük trafikli sitelerde sunucu cron'u önerilir). Seçtiğiniz sağlayıcının kendi API anahtarı gerekir; ücretlendirme sağlayıcıya aittir.

## Hangisini seçmeliyim?

- **AI Post Generator for WordPress**: tek seferlik taslaklar.
- **Content Automation** (bu eklenti): zamanlanmış konu kuyruğu hattı.
- **Site Content Generator** (ileride): toplu site içeriği üretimi.

> **Sorumluluk:** Yapay zekâ çıktısından ve sağlayıcıların kullanım koşullarından site sahibi sorumludur; yayımlamadan önce metni okuyup doğrulayın.

## Sınırlamalar

- Zamanlama yalnızca WP-Cron ile yapılır.
- Üretilen metin yanlış olabilir; "hemen yayımla" kuralını yalnızca metinleri kontrol edecekseniz seçin.
- Kuyruk en fazla 200 öğe tutar.
- Markdown dönüştürücü ve üçüncü taraf görsel servisleri (stok fotoğraf siteleri) yoktur; öne çıkan görsel yalnızca sağlayıcının döndürdüğü görselden oluşturulur.

## SSS

**Etkinleştirdim ama yazı çıkmıyor.** Anahtarın girildiğinden, kuyrukta bekleyen konu olduğundan ve günlük sınırın dolmadığından emin olun; "Şimdi çalıştır" ile deneyebilirsiniz.

**Devre dışı bırakırsam ya da silersem ne olur?** Devre dışı bırakınca zamanlanmış çalışma (`eru_ca_tick`) kaldırılır; ayarlar, anahtarlar ve kuyruk durur. Eklentiyi silerseniz (Eklentiler → Sil) zamanlama ve `eru_ca_settings`, `eru_ca_keys`, `eru_ca_queue`, `eru_ca_usage`, `eru_ca_lock` seçenekleri (saklanan API anahtarları ve konu kuyruğu dahil) silinir. Üretilmiş yazılar içeriğiniz olduğu için korunur. Veriyi koruyan bir sabit yoktur.

**Hangi veri dışarı gider?** Her çalışmada seçtiğiniz sağlayıcının API adresine (OpenAI, Anthropic veya Perplexity) konu, varsa anahtar kelimeler ve yazı dili gönderilir; anahtarınız yalnızca o sağlayıcının sunucusuna gider. Başka bir üçüncü taraf servis yoktur. Anahtar girilmediyse hiçbir istek yapılmaz.

**WP-Cron çalışmıyor, yazılar gelmiyor.** Zamanlama yalnızca WP-Cron ile yapılır; düşük trafikli sitelerde tetiklenmeyebilir. Sunucu cron'u kurun ya da **Şimdi çalıştır** düğmesini kullanın.

**Birden fazla konuyu aynı anda işler mi?** Çalışma başına en çok 3 konu işlenir; bir kilit iki çalışmanın çakışmasını önler. Bir konu 3 başarısız denemeden sonra "başarısız" olur ve günlük istek sınırı dolunca o gün durulur.

**Önbellek eklentileri ve WooCommerce?** Eklenti yalnızca yönetim tarafında ve cron'da çalışır, ön yüzde çıktı üretmez; sayfa önbelleğinden etkilenmez. WooCommerce'e bağlı değildir, HPOS ya da blok ödeme ile ilgisi yoktur.

## Değişiklik geçmişi

Bkz. [CHANGELOG.md](CHANGELOG.md).

## Destek

- Önce bu sayfadaki **SSS** ve **Sınırlamalar** bölümlerine göz atın; sık karşılaşılan durumlar orada.
- Hata mı buldunuz? [GitHub Issues](https://github.com/erayusta/erusoft-wp/issues) üzerinden bildirin. WordPress, PHP ve eklenti sürümünü, adımları ve beklenen/gerçekleşen sonucu yazmanız işi hızlandırır.
- Kurulum, yapılandırma, özel geliştirme veya entegrasyon desteği için **[hello@erusoft.com](mailto:hello@erusoft.com)** adresine yazın. Erusoft hakkında: [erusoft.com](https://erusoft.com).
- Lütfen şifre, API anahtarı veya müşteri verisi (ad, telefon, TCKN, sipariş dökümü) göndermeyin; ekran görüntülerindeki kişisel verileri karartın.

## Lisans

GPL-2.0-or-later.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com/
