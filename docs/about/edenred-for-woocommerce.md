# Edenred for WooCommerce

## Ne işe yarar

WooCommerce mağazanıza Edenred (Ticket Restaurant) sanal POS ile ödeme yöntemi ekler. Müşteri ya Edenred'in güvenli ödeme sayfasına yönlendirilerek ya da kart numarasını mağazada girip SMS şifresini doğrulayarak öder. Eklenti resmi bir Edenred ürünü değildir; kullanmak için Edenred Türkiye'den üye işyeri sanal POS bilgilerinizi almış olmanız gerekir.

## Özellikler

- İki ödeme akışı, ayardan seçilir: **yönlendirmeli** (varsayılan; kart ve SMS şifresi Edenred sayfasında girilir) ve **API + OTP** (kart numarası mağazada alınır, SMS şifresi mağazada doğrulanır; Edenred'den ilgili yetkiyi gerektirir).
- Sonuç senkronu: Edenred'in sunucudan sunucuya bildirimi (`wc-api/edwc_result`) ve müşteri dönüşünde otomatik `Inquiry` sorgusu. Bildirim gecikse bile sipariş doğru duruma gelir.
- WooCommerce sipariş ekranından kısmi ve tekrarlı iade.
- Sipariş eylemi "Edenred: ödemeyi sorgula" ve sipariş ekranında Edenred özeti.
- Test modu (Edenred test ortamı) ve "Test modu kullanıcıları": alan doluysa ödeme yöntemi yalnızca listelenen giriş yapmış kullanıcılara görünür; canlıda kısıtlı deneme için kullanışlıdır.
- Ödeme cevapları ve bildirimleri SHA-256 özetiyle doğrulanır. Kart numarası saklanmaz; siparişe ve günlüğe yalnızca ilk 6 ve son 4 hane yazılır. Şifre, HashKey ve OTP günlüğe yazılmaz.
- Yalnızca para birimi TRY olduğunda kullanılabilir.
- HPOS ve Cart/Checkout Blocks uyumluluğu bildirilir; arayüz çeviriye hazırdır.
- İsteğe bağlı hata ayıklama günlüğü (WooCommerce → Durum → Kayıtlar, kaynak `edenred`).

## Kurulum

1. GitHub deposunun Releases bölümünden ya da "Code → Download ZIP" ile ZIP dosyasını indirin.
2. Eklentiler → Yeni ekle → Eklenti yükle ile yükleyip etkinleştirin.
3. **WooCommerce → Ayarlar → Ödemeler → Edenred (Ticket Restaurant)** sayfasında MerchantNo, TerminalNo, Password, HashKey ve ServiceId alanlarını doldurun ve akışı seçin.
4. Test modunda deneyin, sonra test modunu kapatın.
5. Edenred sonuç bildirim adresi isterse `https://example.com/wc-api/edwc_result` adresini bildirin.

## Gereksinimler

- WordPress 6.5 veya üzeri, WooCommerce 9.0 veya üzeri, PHP 8.0 veya üzeri (katalog kaydı). Eklenti üstbilgisi daha düşük asgari değerler bildirir (WordPress 6.0, WooCommerce 7.0, PHP 7.4; `mbstring` ve `json` uzantıları).
- Edenred üye işyeri sanal POS hesabı ve para birimi TRY.

## Sınırlamalar

- Edenred'in bildirimi internetten erişilemeyen yerel ortamlara ulaşamaz; bu durumda müşteri geri döndüğünde yapılan sorgu sonucu belirler.
- API + OTP akışı Edenred tarafında ayrıca yetkilendirme gerektirir.
- Edenred'in özet hesabı, özel bir karakter kodlaması gerektirir; bunu eklenti kendisi uygular, elle müdahale gerekmez.
- Edenred test ortamı her zaman erişilebilir olmayabilir; depo, uçtan uca deneme için bir sahte sunucu içerir.

## SSS

**Kart numaraları saklanıyor mu?** Hayır. API akışında kart numarası yalnızca istek sırasında bellekte tutulur; kayıtlara yalnızca maskeli hali yazılır.

**Hangi akışı seçmeliyim?** Ek yetki gerektirmediği için yönlendirmeli akışla başlayın. API + OTP, Edenred'den "API üzerinden satış" yetkisi aldığınızda kullanılır.

**İade nasıl yapılır?** Siparişin iade ekranından, kısmi ve birden çok kez.

**Ödeme sonucu gelmedi, ne yapmalıyım?** Sipariş ekranındaki "Edenred: ödemeyi sorgula" eylemini çalıştırın.

## Kaynak kod

[github.com/erayusta/edenred-for-woocommerce](https://github.com/erayusta/edenred-for-woocommerce)

## Destek

- Hata mı buldunuz? Eklentinin GitHub deposunda issue açın; WordPress, WooCommerce, PHP ve eklenti sürümünü, adımları ve beklenen/gerçekleşen sonucu yazın.
- Kurulum, yapılandırma, özel geliştirme veya entegrasyon desteği için **[hello@erusoft.com](mailto:hello@erusoft.com)** adresine yazın. Erusoft hakkında: [erusoft.com](https://erusoft.com).
- Lütfen şifre, API anahtarı veya müşteri verisi (ad, telefon, TCKN, sipariş dökümü) göndermeyin; ekran görüntülerindeki kişisel verileri karartın.
