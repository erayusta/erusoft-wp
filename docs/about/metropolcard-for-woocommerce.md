# MetropolCard for WooCommerce

## Ne işe yarar

WooCommerce ödeme sayfasında MetropolCard vPos-OTP ile ödeme almanızı sağlar. Müşteri kart numarasını girer, "Kod Gönder" ile telefonuna SMS şifresi ister ve şifreyi girerek ödemeyi sayfadan ayrılmadan tamamlar. İptal ve iadeler WooCommerce sipariş ekranından yapılır. Eklenti resmi bir MetropolCard ürünü değildir; MetropolCard'dan üye işyeri hesabı ve API bilgileri almanız gerekir.

## Özellikler

- Ödeme sayfası içinde OTP akışı: kart numarası → kod gönder → SMS şifresiyle öde; yönlendirme yoktur.
- Cüzdan seçimi: kartta birden çok cüzdan varsa müşteri seçer, tek ise otomatik seçilir.
- İptal/iade WooCommerce'in standart iade ekranından yapılır; aynı gün ve tam tutar ise "iptal", diğer durumlarda "iade" olarak gönderilir.
- Test modu ve "Test modu kullanıcıları": alan doluysa yöntem yalnızca listelenen giriş yapmış kullanıcılara gösterilir.
- Kimlik doğrulama bilgileri için ayarlar: ConsumerId, ConsumerName, RefNo, AccessKey, AES anahtarı (SaltKey), İş Yeri Kodu ve Terminal Kodu; canlı API adresi ayrı bir alandır.
- API belirteci, SaltKey uzunluğuna göre AES-128/192/256-CBC ile şifrelenip istenir ve geçici olarak önbelleğe alınır.
- Kart numarası saklanmaz ve kaydedilmez; yalnızca maskeli hali görünür. OTP bağlamı 10 dakikalık geçici kayıtta tutulur.
- OTP istendikten sonra sepet tutarı değişirse ödeme reddedilir ve yeni kod istenir.
- API hata kodları Türkçe mesajlara çevrilir.
- İsteğe bağlı hata ayıklama günlüğü (WooCommerce → Durum → Kayıtlar, kaynak `metropolcard`).
- HPOS uyumludur.

## Kurulum

1. GitHub deposunun Releases bölümünden ya da "Code → Download ZIP" ile ZIP dosyasını indirin.
2. Eklentiler → Yeni ekle → Eklenti yükle ile yükleyip etkinleştirin.
3. **WooCommerce → Ayarlar → Ödemeler → MetropolCard** sayfasında MetropolCard'ın ilettiği bilgileri girin.
4. Test modunda deneyin, sonra test modunu kapatıp canlı API adresini doğrulayın.

## Gereksinimler

- WordPress 6.5 veya üzeri, WooCommerce 9.0 veya üzeri, PHP 8.0 veya üzeri (katalog kaydı). Eklenti üstbilgisi daha düşük asgari değerler bildirir (WordPress 6.0, WooCommerce 7.0, PHP 7.4; `openssl` uzantısı).
- Klasik ödeme sayfası (`[woocommerce_checkout]`).
- MetropolCard üye işyeri hesabı ve API bilgileri.

## Sınırlamalar

- Blok tabanlı ödeme sayfası desteklenmez; yalnızca klasik ödeme sayfası çalışır.
- Ödeme yöntemi, ayarlar tamamlanmadan müşteriye gösterilmez.
- Tahsilat, OTP'nin üretildiği tutar için yapılır; tutar değişirse yeni kod gerekir.

## SSS

**Kart numaraları saklanıyor mu?** Hayır. Kart numarası yalnızca API isteği sırasında bellekte tutulur; kayıtlara yalnızca maskeli hali yazılır.

**Blok ödeme sayfası destekleniyor mu?** Şimdilik hayır; sayfanız `[woocommerce_checkout]` kısa kodunu kullanmalıdır.

**İptal ile iade farkı nedir?** Eklenti seçimi sizin yerinize yapar: aynı gün ve tam tutarlı işlemler iptal, diğerleri iade olarak gönderilir.

**Canlıda herkese açmadan deneyebilir miyim?** Evet; "Test modu kullanıcıları" alanına e-posta veya kullanıcı adlarını yazarsanız yalnızca onlar yöntemi görür.

## Kaynak kod

[github.com/erayusta/metropolcard-for-woocommerce](https://github.com/erayusta/metropolcard-for-woocommerce)

## Destek

- Hata mı buldunuz? Eklentinin GitHub deposunda issue açın; WordPress, WooCommerce, PHP ve eklenti sürümünü, adımları ve beklenen/gerçekleşen sonucu yazın.
- Kurulum, yapılandırma, özel geliştirme veya entegrasyon desteği için **[hello@erusoft.com](mailto:hello@erusoft.com)** adresine yazın. Erusoft hakkında: [erusoft.com](https://erusoft.com).
- Lütfen şifre, API anahtarı veya müşteri verisi (ad, telefon, TCKN, sipariş dökümü) göndermeyin; ekran görüntülerindeki kişisel verileri karartın.
