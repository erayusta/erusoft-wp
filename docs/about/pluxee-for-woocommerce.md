# Pluxee for WooCommerce

## Ne işe yarar

Pluxee (eski adıyla Sodexo) Türkiye mobil ödeme koduyla WooCommerce'te ödeme almanızı sağlar. Müşteri ödeme sayfasında "Pluxee ile Öde"yi seçer, Pluxee uygulamasına kayıtlı telefon numarasını ve uygulamadan aldığı tek kullanımlık kodu (OTP) girer; eklenti ödemeyi Pluxee'nin SOAP web servisi üzerinden tamamlar. Kullanmak için Pluxee ile üye işyeri sözleşmeniz ve servis bilgileriniz olmalıdır.

## Özellikler

- Klasik ve blok tabanlı ödeme sayfası desteği; HPOS ve `cart_checkout_blocks` uyumluluğu bildirilir.
- UAT (test) ve PROD (canlı) ortamları için kullanıcı adı, şifre, üye no, terminal no, HMAC anahtarı ve RSA açık anahtarı ayrı saklanır; ortam değiştirmek diğerinin bilgilerini silmez.
- VKN girerek üye no ve terminal no bilgilerini Pluxee'den otomatik çekme ve "Bağlantıyı test et" aracı.
- Yanıt alınamayan (zaman aşımı) ödemelerde otomatik teknik iptal; çift çekimi önlemeyi amaçlar.
- WooCommerce iade aracından Pluxee'ye kısmi veya tam iade gönderimi (`MakeRefund`).
- "Pluxee İşlemleri" ekranı: işlem kayıt tablosu, mutabakat görünümü ve CSV dışa aktarma; sipariş ekranında Pluxee kutusu.
- Ürün uygunluğu kuralı: kapalı, yalnızca seçili kategorilerde göster veya seçili kategorilerde gizle. Uygun olmayan ürün sepette varsa yöntem gizlenir ve uyarı gösterilir. Ürün bazında `_pluxee_eligible` ile ezme ve `pluxee_wc_product_eligible` süzgeci vardır.
- Zorunlu bilgilendirme metni için `[pluxee_notice]` kısa kodu.
- "Test modu kullanıcıları" ayarı ile yöntemi canlıda yalnızca belirli kullanıcılara gösterme.
- OTP RSA/OAEP ile şifrelenip gönderilir, açık hali saklanmaz; GSM numaraları ve gizli değerler günlüklerde maskelenir.
- Kimlik bilgilerini `wp-config.php` sabitleriyle tanımlayıp veritabanı dışında tutma seçeneği.
- WP-CLI komutları: `wp pluxee test-connection`, `wp pluxee info`.
- GitHub Releases üzerinden güncelleme denetimi (`pluxee_wc_enable_updater` süzgeciyle kapatılabilir); güncelleme denetimi varsayılan olarak açıktır ve `api.github.com` adresine istek yapar.
- Ayar ekranı, sunucunun çıkış IP adresini göstermek için `api.ipify.org` adresine istek yapar (sonuç bir gün önbelleğe alınır).

## Kurulum

1. GitHub deposunun **Releases** bölümünden `pluxee-for-woocommerce.zip` dosyasını indirin. Kaynak kod arşivi ("Download ZIP") yerine release dosyasını seçin; eklenti klasörü doğru adla (`pluxee-for-woocommerce`) paketlenmiştir.
2. Eklentiler → Yeni ekle → Eklenti yükle ile yükleyip etkinleştirin.
3. **WooCommerce → Ayarlar → Ödemeler → Pluxee ile Öde** sayfasında ortamı seçin, servis bilgilerini girin, RSA açık anahtarını yapıştırın ve kaydedin.
4. Kaydettikten sonra "Bağlantıyı test et" düğmesini kullanın; test, açık anahtarı formdan değil kayıtlı ayardan okur.
5. Ayar ekranında gösterilen sunucu çıkış IP adresini Pluxee'ye bildirip yetkilendirtin; aksi hâlde hiçbir servis çağrısı başarılı olmaz.

## Gereksinimler

- WordPress 6.5 veya üzeri, WooCommerce 9.0 veya üzeri, PHP 8.0 veya üzeri (katalog kaydı). Eklenti üstbilgisi daha düşük asgari değerler bildirir (WordPress 6.4, WooCommerce 8.0, PHP 7.4; `openssl` ve `mbstring` uzantıları).
- Pluxee üye işyeri sözleşmesi, servis bilgileri ve para birimi TRY.

## Sınırlamalar

- Pluxee tüm çağrılarda sunucu IP kısıtı uygular.
- Pluxee sözleşmesi gıda dışı ürünlerde bu ödemeyi yasakladığından, kural ayarını mağazanıza göre yapılandırmanız gerekir.
- Depodaki README'nin bir bölümü iade ve süzgeç listesi konusunda güncel değildir; kod tabanı iade (`MakeRefund`) ve ürün uygunluğu süzgecini içerir.
- Veriler eklenti silinirken yalnızca "Kaldırma" ayarı işaretliyse temizlenir.

## SSS

**Kaydetmeden test etsem olur mu?** Hayır. Bağlantı testi RSA açık anahtarını kayıtlı ayardan okur; yeni anahtarı yapıştırdıktan sonra önce kaydedin.

**Yanıt gelmeyen ödemede ne olur?** Eklenti otomatik teknik iptal dener; durum işlem tablosunda "Teknik iptal" tipiyle görünür.

**Giyim gibi gıda dışı ürünler satıyorum, ne yapmalıyım?** Ürün uygunluğu kuralını ve kategori listesini ayarlayın; uygun olmayan ürün sepetteyken yöntem gizlenir.

**İade yapabilir miyim?** Evet; WooCommerce iade aracı Pluxee'ye iade isteği gönderir ve kayıtlar işlem tablosunda "İade" tipiyle listelenir.

## Kaynak kod

[github.com/erayusta/pluxee-for-woocommerce](https://github.com/erayusta/pluxee-for-woocommerce)

## Destek

- Hata mı buldunuz? Eklentinin GitHub deposunda issue açın; WordPress, WooCommerce, PHP ve eklenti sürümünü, adımları ve beklenen/gerçekleşen sonucu yazın.
- Kurulum, yapılandırma, özel geliştirme veya entegrasyon desteği için **[hello@erusoft.com](mailto:hello@erusoft.com)** adresine yazın. Erusoft hakkında: [erusoft.com](https://erusoft.com).
- Lütfen şifre, API anahtarı veya müşteri verisi (ad, telefon, TCKN, sipariş dökümü) göndermeyin; ekran görüntülerindeki kişisel verileri karartın.
