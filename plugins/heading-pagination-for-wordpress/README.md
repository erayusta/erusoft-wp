# Heading Pagination for WordPress

Uzun yazıları seçtiğiniz başlık düzeyinde sayfalara böler; içindekiler ve önceki/sonraki bağlantıları ekler.

> **English:** Splits long posts into pages at a chosen heading level (H2–H4) using WordPress's own pagination, adds a table of contents and prev/next links. Posts with too few headings or words are left untouched.

[![Lisans: GPL-2.0-or-later](https://img.shields.io/badge/lisans-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html) ![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg) ![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)

![Ayarlar](../../docs/screenshots/heading-pagination-for-wordpress/1.png)
![Sayfa 2, masaüstü](../../docs/screenshots/heading-pagination-for-wordpress/2.png)
![Sayfa 2, mobil](../../docs/screenshots/heading-pagination-for-wordpress/3.png)

## Ne işe yarar

Çok uzun bir yazıyı her başlıkta ayrı sayfaya böler. Okuyucu içindekiler kutusundan istediği bölüme geçer; altta önceki/sonraki bağlantıları bulunur. Yeterli başlığı ya da kelimesi olmayan içeriğe hiç dokunulmaz.

## Özellikler

- Bölme başlığı H2, H3 veya H4 (varsayılan H2)
- En az bölüm sayısı ve en az kelime sayısı eşiği; altındaki içerik değişmez
- WordPress'in kendi sayfalamasını kullanır (`/yazi/2/`); adresler kalıcı bağlantı yapısını izler
- İçindekiler kutusu ve önceki/sonraki bağlantıları (ayrı ayrı kapatılabilir)
- Blok editörü başlık bloklarıyla uyumlu; elle `<!--nextpage-->` kullanan içeriğe dokunmaz
- İçerik türü seçimi (varsayılan yalnızca yazılar)

## Kurulum

1. `heading-pagination-for-wordpress` klasörünü `wp-content/plugins/` altına kopyalayın (veya zip olarak yükleyin).
2. Eklentiler ekranından etkinleştirin.
3. Ayarları yapın (aşağıya bakın).

## Nasıl kullanılır

Örnek senaryo: uzun bir rehber yazısını bölümlere ayırmak.

1. Eklentiyi etkinleştirin ve **Ayarlar → Başlık Sayfalama** ekranında içerik türü olarak "Yazılar"ı bırakın.
2. Bölme başlığını H2, en az bölüm sayısını 3, en az kelime sayısını 600 olarak kaydedin; içindekiler ve önceki/sonraki seçeneklerini açık tutun.
3. "Demo Rehber" adında bir yazı oluşturun: 600 kelimeden uzun olsun ve en az üç adet H2 başlığı içersin (ör. "Hazırlık", "Kurulum", "Sonuç"). Yazıda elle `<!--nextpage-->` kullanmayın.
4. Yazıyı yayımlayıp açın: ilk sayfada giriş metni ve ilk bölüm ile bir içindekiler kutusu görünür; bölümler `/demo-rehber/2/`, `/demo-rehber/3/` gibi adreslerde yer alır.
5. İçindekiler kutusundan bir bölüme geçin, sayfanın altındaki önceki/sonraki bağlantılarını deneyin.
6. Aynı yazıyı bölünmeden tek sayfada tutmak isterseniz `eru_hp_applies` filtresiyle o yazı için `false` dönün.

## Ayarlar

Ayarlar → **Başlık Sayfalama**: içerik türleri, bölme başlığı, en az bölüm (varsayılan 3), en az kelime (varsayılan 600), içindekiler/gezinti, içindekiler başlığı. Seçenek: `eru_hp_settings`.

## Kısa kodlar, kancalar, filtreler

| Ad | Tür | Açıklama |
|---|---|---|
| `eru_hp_applies` | filtre | `($uygula, $yazı)`; belirli içeriklerde sayfalamayı kapatır/açar |
| `eru_hp_settings` | seçenek | Eklenti ayarları |

## Entegrasyonlar ve gereksinimler

WordPress 6.5+, PHP 8.0+. Harici servis kullanmaz.

## SEO notu

Bölüm sayfaları (`/yazi/2/`, `/yazi/3/`) kendi adreslerini canonical olarak taşır ve ayrı sayfalar olarak indekslenir; ilk sayfadaki içindekiler kutusu bu sayfalara bağlanır. Son bölümün ötesindeki adresler (`/yazi/9/`) gerçek 404 döndürür. Tüm yazının tek sayfada indekslenmesini isterseniz bu içerik için `eru_hp_applies` filtresiyle sayfalamayı kapatın.

## Sınırlamalar

- Sonraki bölümler AJAX ile değil, normal sayfa yenilemesiyle yüklenir.
- Bölüm başlıkları içerikte kullandığınız başlık düzeyine göre belirlenir; başlıksız giriş metni ilk sayfada kalır.
- Her sayfa ayrı sayfa görüntülemesi sayılır; analiz araçlarınızı buna göre yorumlayın.

## SSS

**Kısa yazılar etkilenir mi?** Hayır. Eşiklerin altındaki içerikler olduğu gibi tek sayfada gösterilir.

**Devre dışı bırakırsam ya da silersem ne olur?** Devre dışı bırakınca yazılar yeniden tek sayfa olarak gösterilir; yazı içeriğinizde hiçbir değişiklik yapılmadığı için veri kaybı olmaz. Eklentiyi silerseniz yalnızca `eru_hp_settings` seçeneği silinir. Veriyi koruyan bir sabit yoktur. Not: eski `/yazi/2/` adresleri devre dışı kaldıktan sonra artık bölüm olarak sunulmaz.

**Elle `<!--nextpage-->` kullandığım yazılara ne olur?** Dokunulmaz; o yazılar WordPress'in kendi sayfalamasıyla çalışmaya devam eder.

**Besleme (feed) ve arşiv sayfalarında bölünür mü?** Hayır. Bölme yalnızca tekil içerik sayfalarında, yönetim ekranı ve beslemeler dışında uygulanır; bu çıktılar bölünmez.

**WooCommerce, HPOS, blok sepet/ödeme?** Eklentinin WooCommerce ile bir ilişkisi yoktur. İçerik türü ayarında herkese açık türler listelenir; yalnızca seçtiğiniz türlere uygulanır (varsayılan yazılar).

**Önbellek eklentileriyle sorun olur mu?** Her bölüm kendi adresinde olduğundan sayfa önbelleği her bölümü ayrı kaydeder; ek bir ayar gerekmez.

**Dış servis kullanıyor mu?** Hayır.

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
