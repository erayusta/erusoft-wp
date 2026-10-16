# SEO Meta REST Fields for WordPress

## Ne işe yarar

Yoast SEO, Rank Math veya All in One SEO ile girilmiş SEO başlığı, açıklama ve odak anahtar kelime gibi alanları WordPress REST API'sine tek bir `seo` alanı olarak açar. Dışarıdaki bir betik, yayın aracı veya otomasyon, hangi SEO eklentisinin kurulu olduğunu bilmeden aynı alan adlarıyla bu değerleri okuyup yazabilir. Eklenti bir "must-use" (mu-plugin) dosyasıdır.

## Özellikler

- Etkin SEO eklentisini otomatik algılar: Yoast SEO, Rank Math veya All in One SEO.
- Tüm herkese açık yazı türlerinin REST yanıtına `seo` nesnesi ekler.
- Okunan alanlar: `seo_title`, `meta_description`, `focus_keyword`, `seo_score`, `canonical_url`, `robots_noindex`, `og_title`, `og_description`, `og_image`, `twitter_title`, `twitter_description` ve etkin eklentinin adı (`plugin`).
- Yazma: `seo_title`, `meta_description`, `focus_keyword`, `canonical_url`, `og_title`, `og_description`, `og_image`, `twitter_title` ve `twitter_description`. Yazma için kullanıcının o yazıyı düzenleme yetkisi (`edit_post`) gerekir; değerler temizlenerek kaydedilir.
- Yoast ve Rank Math için yazı meta alanları, All in One SEO için kendi `aioseo_posts` tablosu kullanılır; tablo yoksa başlık ve açıklama meta alanına yedeklenir.
- Durum uç noktası: `GET /wp-json/wp-ai-toolkit/v1/seo-status` etkin eklentiyi ve desteklenen listeyi döndürür.
- Depoda yalnızca tek bir SEO eklentisini hedefleyen üç ayrı dosya daha vardır (Yoast, Rank Math, AIOSEO).

## Kurulum

Bu bir mu-plugin'dir; Eklentiler ekranından yüklenmez.

1. GitHub deposunun Releases bölümünden ya da "Code → Download ZIP" ile depoyu indirin.
2. Arşivdeki `wordpress/wp-ai-toolkit-seo-rest.php` dosyasını sitenizde `wp-content/mu-plugins/` dizinine kopyalayın. Dizin yoksa oluşturun.
3. Başka bir adım gerekmez; mu-plugin'ler otomatik etkindir ve ayar ekranı yoktur.
4. Deneyin: `GET /wp-json/wp/v2/posts/<id>` yanıtında `seo` nesnesini görmelisiniz. Yazmak için uygulama parolasıyla kimlik doğrulayıp aynı uç noktaya `seo` nesnesi gönderin.

## Gereksinimler

- WordPress 6.5 veya üzeri, PHP 8.0 veya üzeri (katalog kaydı). Dosya üstbilgisinde asgari sürüm tanımlı değildir.
- Yoast SEO, Rank Math veya All in One SEO eklentilerinden biri etkin olmalıdır.

## Sınırlamalar

- Hiçbir desteklenen SEO eklentisi etkin değilse `seo` alanı eklenmez.
- `seo_score` ve `robots_noindex` yalnızca okunur; REST üzerinden yazılmaz.
- Yoast ve Rank Math için `og_image` gibi alanlar düz metin olarak saklanır; All in One SEO'da görsel adresi temizlenerek yazılır.
- Güncelleme işlevi, hangi alanların gerçekten kaydedildiğine bakmaksızın başarılı döner.
- Durum uç noktası herkese açıktır ve yalnızca etkin SEO eklentisinin adını gösterir.
- Aynı depo, bu mu-plugin dışında geliştirici araçları (bir MCP sunucusu) da içerir; bu sayfa yalnızca `wordpress/` dizinindeki dosyaları anlatır.

## SSS

**Hangi SEO eklentisini kullandığımı belirtmem gerekir mi?** Hayır. Eklenti Yoast, Rank Math ve All in One SEO'yu kendisi algılar.

**Alanları nasıl yazarım?** Standart yazı uç noktasına, örneğin `{"seo": {"seo_title": "...", "meta_description": "..."}}` gövdesiyle istek gönderirsiniz; yalnızca gönderdiğiniz alanlar güncellenir.

**Yetkisiz biri SEO alanlarını değiştirebilir mi?** Yazma, `edit_post` yetkisi olan kimliği doğrulanmış kullanıcılarla sınırlıdır. Okuma ise yazının kendi REST görünürlüğüne bağlıdır.

**Sayfalar ve ürünler de kapsanır mı?** Evet, herkese açık tüm yazı türleri (sayfalar ve REST'e açık ürünler dahil) için alan eklenir.

## Kaynak kod

[github.com/erayusta/wp-ai-toolkit](https://github.com/erayusta/wp-ai-toolkit)

## Destek

- Hata mı buldunuz? Eklentinin GitHub deposunda issue açın; WordPress, WooCommerce, PHP ve eklenti sürümünü, adımları ve beklenen/gerçekleşen sonucu yazın.
- Kurulum, yapılandırma, özel geliştirme veya entegrasyon desteği için **[hello@erusoft.com](mailto:hello@erusoft.com)** adresine yazın. Erusoft hakkında: [erusoft.com](https://erusoft.com).
- Lütfen şifre, API anahtarı veya müşteri verisi (ad, telefon, TCKN, sipariş dökümü) göndermeyin; ekran görüntülerindeki kişisel verileri karartın.
