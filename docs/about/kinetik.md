# KİNETİK

## Ne işe yarar

KİNETİK, Türkiye'deki spor giyim mağazaları için yazılmış klasik (blok olmayan) bir WooCommerce temasıdır. Tema, Türkiye'ye özgü ihtiyaçlar için dört yardımcı eklentiyle birlikte gelir: il/ilçe/mahalle adres seçimi, siparişe özel mesafeli satış sözleşmeleri, ücretsiz kargo çubuğu ve stok bildirimi. Sayfa oluşturucu ya da lisans anahtarı gerektirmez.

## Özellikler

### Tema (`themes/kinetik`)

- Mobil öncelikli, hafif tasarım: WooCommerce'in varsayılan CSS'i yüklenmez, alan bazlı CSS dosyaları sayfaya göre yüklenir, görseller WebP üretilir.
- Ana sayfa, mağaza/kategori (kategori, beden, cinsiyet ve fiyat süzgeçleri, mobil süzgeç çekmecesi), ürün sayfası, sepet, ödeme ve hesap sayfaları için özel şablonlar.
- Ürün sayfasında tema içi beden butonları (WooCommerce varyasyon betiğiyle birlikte çalışır), beden tablosu penceresi, AJAX ile sepete ekleme.
- İletişim, Beden Rehberi, 404 ve arama sayfaları; çerez çubuğu.
- SEO çıktısı: başlık ve açıklama, canonical, Open Graph/Twitter, Organization, WebSite ve Product JSON-LD.
- Tema süzgeçleri: `kinetik_footer_credit`, `kinetik_dispatch_days`, `kinetik_return_days` ve `kinetik_welcome_coupon`.

### Dahil eklentiler (`plugins/`)

- **kinetik-sozlesmeler:** Mesafeli Satış Sözleşmesi ve Ön Bilgilendirme Formu'nu siparişe özel üretir; iki ayrı onay kutusu, isteğe bağlı ticari ileti onayı, onay kaydı ve sözleşme kopyasının siparişe yazılması. Satıcı bilgileri WooCommerce → Sözleşmeler ekranından tek yerden yönetilir.
- **kinetik-tr-adres:** İl → ilçe → mahalle → cadde/sokak seçimi; veri pakete dahildir, dış API çağrısı yapılmaz.
- **kinetik-marketing:** ücretsiz kargo çubuğu ve e-posta kaydı (`[kinetik_free_shipping_bar]`, `[kinetik_newsletter]` kısa kodları); `kinetik_free_shipping_threshold` ve `kinetik_flat_shipping_cost` süzgeçleri.
- **kinetik-growth:** stoğa gelince haber verme, Havale/EFT hatırlatma e-postası, değerlendirme isteği ve `/?kinetik_feed=google` adresinde Google Shopping beslemesi; marka adı `kinetik_brand_name` süzgeciyle değiştirilebilir.

## Kurulum

1. GitHub deposunda "Code → Download ZIP" ile depoyu indirin. Tema ve eklentiler aynı arşivde ayrı klasörlerdedir.
2. `themes/kinetik` klasörünü ayrıca ZIP'leyip Görünüm → Temalar → Yeni ekle → Tema yükle ile yükleyin (ya da klasörü `wp-content/themes/` altına kopyalayın) ve etkinleştirin.
3. İstediğiniz eklenti klasörlerini (`plugins/` altında) aynı biçimde ZIP'leyip Eklentiler → Yeni ekle → Eklenti yükle ile yükleyin (ya da `wp-content/plugins/` altına kopyalayın) ve etkinleştirin.
4. **WooCommerce → Sözleşmeler** ekranında satıcı bilgilerinizi girin.
5. Sepet ve ödeme sayfalarının `[woocommerce_cart]` ve `[woocommerce_checkout]` kısa kodlarını kullandığından emin olun; TR adres eklentisi klasik ödeme sayfası ister.

## Gereksinimler

- WordPress 6.5 veya üzeri, WooCommerce 9.0 veya üzeri, PHP 8.0 veya üzeri.
- Eklentiler WooCommerce'in etkin olmasını gerektirir.

## Sınırlamalar

- Klasik temadır; blok temaları ve tam site düzenleme desteklenmez.
- TR adres eklentisi blok tabanlı ödeme sayfasıyla çalışmaz; klasik ödeme sayfası gerekir.
- Sözleşme ve yasal sayfa şablonları başlangıç noktasıdır, hukuki danışmanlık yerine geçmez; yayından önce bir hukukçuya kontrol ettirin.
- Tema yazı tiplerini Google Fonts'tan yükler; ziyaretçi tarayıcısı Google sunucularına bağlanır. Gerekirse fontları yerel olarak barındırın ve gizlilik metninizde belirtin.
- Footer'da varsayılan olarak bir geliştirici imzası bağlantısı (Erusoft) görünür; `kinetik_footer_credit` süzgeciyle kaldırılabilir.

## SSS

**Eklentileri başka temayla kullanabilir miyim?** Depo, eklentilerin temadan bağımsız olduğunu belirtir; istediğinizi seçip başka bir temayla etkinleştirebilirsiniz.

**Kargo süresi ve iade süresi metinlerini değiştirebilir miyim?** Evet; `kinetik_dispatch_days` ve `kinetik_return_days` süzgeçleriyle. İade süresi 14 günün altına inmez.

**Ücretsiz kargo eşiği nerede ayarlanır?** Eşik WooCommerce'in ücretsiz kargo ayarından okunur; kinetik-marketing eklentisindeki `kinetik_free_shipping_threshold` süzgeciyle de değiştirilebilir.

**Kargo ücreti nereden gelir?** WooCommerce'in sabit ücret ve ücretsiz kargo yöntemlerinden okunur.

## Kaynak kod

[github.com/erayusta/kinetik](https://github.com/erayusta/kinetik)

## Destek

- Hata mı buldunuz? Eklentinin GitHub deposunda issue açın; WordPress, WooCommerce, PHP ve eklenti sürümünü, adımları ve beklenen/gerçekleşen sonucu yazın.
- Kurulum, yapılandırma, özel geliştirme veya entegrasyon desteği için **[hello@erusoft.com](mailto:hello@erusoft.com)** adresine yazın. Erusoft hakkında: [erusoft.com](https://erusoft.com).
- Lütfen şifre, API anahtarı veya müşteri verisi (ad, telefon, TCKN, sipariş dökümü) göndermeyin; ekran görüntülerindeki kişisel verileri karartın.
