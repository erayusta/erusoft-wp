# Product Ribbons for WooCommerce

Ürün görsellerine köşegen şerit ekler. Kategori, etiket, indirimde, yeni ürün ve düşük stok kuralları ayarlardan tanımlanır; istenirse ürün düzenleme ekranından elle şerit girilir.

![Ayarlar](../../docs/screenshots/product-ribbons-for-woocommerce/1.png)

![Mağaza sayfasında şeritler](../../docs/screenshots/product-ribbons-for-woocommerce/2.png)

## Özellikler

- Kural tablosu: ürün kategorisi, ürün etiketi, indirimde, yeni ürün, düşük stok
- Her kural için metin, arka plan ve yazı rengi; yukarıdan aşağıya ilk eşleşen kazanır
- "Yeni ürün" süresi ve "düşük stok" eşiği ayarlanabilir
- Ürün bazında elle şerit (kuralların önüne geçer)
- Mağaza listeleri, ilgili ürünler ve tekil ürün görselinde; klasik ve blok temalarda
- Kural yoksa ön yüze hiçbir şey eklenmez (stil yalnızca şerit varsa yüklenir)

## Kurulum

1. `product-ribbons-for-woocommerce` klasörünü `wp-content/plugins/` altına kopyalayın (veya zip olarak yükleyin).
2. Eklentiler ekranından etkinleştirin.
3. WooCommerce → Ürün Şeritleri ekranından kuralları ekleyin.

## Nasıl kullanılır

Örnek senaryo: indirimdeki ürünlere "İndirim" şeridi ve tek bir ürüne elle şerit eklemek.

1. Eklentiyi etkinleştirin ve **WooCommerce → Ürün Şeritleri** ekranını açın.
2. Kural tablosuna bir kural ekleyin: tür "İndirimde", metin "İndirim", arka plan ve yazı rengini seçin. Kaydedin.
3. "Demo Ürün" ürününü düzenleyin; normal fiyatı 250 TL, indirimli fiyatı 199 TL yapıp güncelleyin.
4. Mağaza sayfasını açın: "Demo Ürün" görselinin köşesinde "İndirim" şeridi görünür. İndirimi kaldırdığınızda şerit de kalkar.
5. İkinci bir kural olarak tür "Yeni ürün" ekleyip yeni ürün gün sayısını belirleyin. Birden fazla kural eşleşirse tablodaki ilk eşleşen kazanır; sıralamayı buna göre düzenleyin.
6. Ürün düzenleme ekranının Genel sekmesinde "Şerit" kutusunu işaretleyip "Şerit metni" alanına "Sınırlı seri" yazın: bu ürün için kurallar yerine elle girdiğiniz şerit gösterilir.

## Ayarlar

WooCommerce → Ürün Şeritleri: kural tablosu, varsayılan renkler, yazı boyutu, şerit genişliği, yeni ürün günü, düşük stok eşiği (`eru_pr_options`). Ürün düzenleme → Genel sekmesinde elle şerit alanları.

## Kancalar ve filtreler

| Ad | Tür | Açıklama |
|---|---|---|
| `eru_pr_ribbon` | filtre | Ürün için şeridi değiştirir veya kaldırır (`$ribbon`: text, bg, color, font_size veya null; `$product`) |

## Gereksinimler

WordPress 6.5+, PHP 8.0+, WooCommerce 9.0+. WooCommerce HPOS (özel sipariş tabloları) uyumluluğu bildirilir; eklenti sipariş verisine dokunmaz. Harici servis kullanmaz.

## Sınırlamalar

- Eski "WooCommerce Blocks" ürün ızgarası (wc-block-grid) şeritlerini göstermez; Ürün Koleksiyonu bloğu ve klasik şablonlar desteklenir.
- Düşük stok kuralı yalnızca stok yönetimi açık ürünlerde çalışır.
- Özel temalar ürün görselini farklı işaretlerle çıkarıyorsa CSS ayarı gerekebilir.
- Eklenti silindiğinde (Eklentiler → Sil) yalnızca kendi ayarları (`eru_pr_*` seçenekleri) silinir; ürün bazlı şerit ayarları (`_eru_pr_*` ürün metası) korunur.

## SSS

**Hangi şerit gösterilir?** Önce üründeki elle şerit, yoksa kurallar sırasıyla; ilk eşleşen.

**Şeridi kod ile değiştirebilir miyim?** Evet, `eru_pr_ribbon` filtresi.

**Devre dışı bırakırsam ya da silersem ne olur?** Devre dışı bırakınca şeritler görünmez; kurallar ve ürün alanları durur, tekrar etkinleştirince geri gelir. Eklentiyi silerseniz (Eklentiler → Sil) yalnızca `eru_pr_options` seçeneği silinir; ürünlerdeki `_eru_pr_*` alanları korunur. Veriyi silen bir sabit yoktur.

**Önbellek eklentisiyle sorun olur mu?** Şerit kullanıcıya göre değişmez, yalnızca ürün ve kurallara bağlıdır; sayfa önbelleği sorun çıkarmaz. Kuralları ya da ürünü değiştirince sayfa önbelleğini temizleyin.

**Tekil ürün sayfasındaki şerit nasıl eklenir?** Tekil ürün galerisindeki şerit, sayfa sonunda çalışan küçük bir betikle galeriye yerleştirilir; JavaScript kapalıysa orada görünmez. Mağaza listelerindeki şeritler sunucu tarafında üretilir.

**HPOS ve blok sepet/ödeme?** Eklenti HPOS uyumluluğunu bildirir. Sepet ve ödeme sayfalarına bir şey eklemez; yalnızca ürün görselleriyle ilgilidir.

**Büyük katalogda yavaşlatır mı?** Şerit her ürün için kural listesine bakılarak hesaplanır; kural sayısı az olduğu sürece ek yük küçüktür. Çok sayıda kural tanımlamayın.

## English summary

Adds diagonal ribbons to product images. Rules (category, tag, on sale, new, low stock) with label and colours are defined in settings; per-product manual ribbons override rules. Works with classic and block themes and outputs nothing when no rule matches. It declares HPOS compatibility and makes no external requests. Deactivation hides the ribbons; uninstall removes only the plugin's options and keeps per-product ribbon fields.

## Sürüm notları

Bkz. [CHANGELOG.md](CHANGELOG.md).

## Destek

- Önce bu sayfadaki **SSS** ve **Sınırlamalar** bölümlerine göz atın; sık karşılaşılan durumlar orada.
- Hata mı buldunuz? [GitHub Issues](https://github.com/erayusta/erusoft-wp/issues) üzerinden bildirin. WordPress, WooCommerce, PHP ve eklenti sürümünü, adımları ve beklenen/gerçekleşen sonucu yazmanız işi hızlandırır.
- Kurulum, yapılandırma, özel geliştirme veya entegrasyon desteği için **[hello@erusoft.com](mailto:hello@erusoft.com)** adresine yazın. Erusoft hakkında: [erusoft.com](https://erusoft.com).
- Lütfen şifre, API anahtarı veya müşteri verisi (ad, telefon, TCKN, sipariş dökümü) göndermeyin; ekran görüntülerindeki kişisel verileri karartın.

## Lisans

GPL-2.0-or-later. Diğer Erusoft eklentileri: https://wordpress.erusoft.com
