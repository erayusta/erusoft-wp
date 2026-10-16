# Hepsiburada Integration for WooCommerce

## Ne işe yarar

Hepsiburada pazaryerinden gelen siparişleri WooCommerce'e izole biçimde aktarır. Siparişler kendi özel durumlarında bekler; normal sipariş akışınızı (stok düşümü, ERP aktarımı, müşteri e-postaları) tetiklemez, böylece pazaryerinin zaten yaptığı işler sitenizde ikinci kez yapılmaz. Bu eklenti eski bir sürümdür; yeni kurulumlar için katalogdaki Marketplace Orders eklentisine geçmeniz önerilir.

## Özellikler

- Hepsiburada OMS servisinden "paketlenecek" kalemleri 5 dakikalık zamanlı görevle çeker; iptal taraması da aynı turda yapılır.
- Aynı sipariş numarasına bağlı kalemler tek bir WooCommerce siparişinde birleştirilir.
- Sipariş kaydedilirken `woocommerce_new_order` dinleyicileri o kayıt boyunca geçici olarak devre dışı bırakılır ve hemen geri yüklenir.
- Özel sipariş durumları: `hepsiburada-yeni`, `hepsiburada-hazir`, `hepsiburada-iptal`.
- Ürün eşleme: Hepsiburada `merchantSku` değeri WooCommerce SKU'su ile eşleştirilir; eşleşmeyen kalem siparişte "eşleşmedi" olarak işaretlenir ve günlüğe yazılır, sipariş kaybolmaz.
- Test (SIT) ve canlı ortam için ayrı kimlik bilgileri; ortam değiştirmek diğerinin bilgilerini silmez.
- Test ortamında zamanlı görev yalnızca sayım ve örnek içerik günlüğü yapar; "Şimdi çek" düğmesi bu kuralı aşar.
- Sipariş ekranında Hepsiburada bilgi kutusu ve sipariş listesinde "HB" sütunu.
- İşlem günlüğü ekranı ve iki veritabanı tablosu (siparişler, günlük).
- Entegrasyon için iki eylem: `eru_hepsiburada_crm_positive` ve `eru_hepsiburada_crm_negative`; her sipariş için yalnızca bir kez tetiklenir.
- HPOS uyumludur.

## Kurulum

1. GitHub deposunun Releases bölümünden ya da "Code → Download ZIP" ile ZIP dosyasını indirin.
2. Eklentiler → Yeni ekle → Eklenti yükle ile yükleyip etkinleştirin; tablolar oluşturulur ve zamanlı görev kurulur.
3. **WooCommerce → Hepsiburada Siparişleri** sayfasında Merchant ID, servis anahtarı ve zorunlu Developer Username bilgilerini girin. Eklenti varsayılan olarak kapalı ve test ortamında gelir.
4. Önce test ortamında doğrulayın, ardından canlı ortamı seçip "Aktif" kutusunu işaretleyin.
5. Az trafikli sitelerde WordPress zamanlı görevi ziyarete bağlıdır; gerçek bir sistem cron'u kurup `DISABLE_WP_CRON` tanımlamanız önerilir.

## Gereksinimler

- WordPress 6.5 veya üzeri, WooCommerce 9.0 veya üzeri, PHP 8.0 veya üzeri (katalog kaydı). Eklenti üstbilgisi daha düşük asgari değerler bildirir (WordPress 5.0, PHP 7.2).
- Hepsiburada satıcı hesabından alınmış API bilgileri.

## Sınırlamalar

- Yalnızca sipariş içeri alır; stok veya ürün bilgisini Hepsiburada'ya göndermez. Stok/ERP senkronunu kendi kodunuzla yukarıdaki iki eyleme bağlarsınız.
- Eşleme yalnızca SKU üzerindendir; SKU birebir aynı olmalıdır.
- Developer Username boşsa veya yanlışsa Hepsiburada istekleri 401 ile reddeder.
- Eski sürüm olarak sunulur; yeni özellik geliştirmesi beklenmemelidir.

## SSS

**Siparişler neden normal sipariş gibi stok düşürmüyor?** Bilinçli tasarım: pazaryeri stoku kendi tarafında zaten yönetir. Çift düşümü önlemek için sipariş izole kaydedilir.

**401 hatası alıyorum.** Developer Username'i, seçili ortamla kimlik bilgilerinin eşleştiğini ve servis anahtarını kontrol edin.

**Sipariş geldi ama kalem eşleşmedi.** Hepsiburada `merchantSku` ile WooCommerce SKU'su birebir aynı olmalıdır.

**Zamanlı görev çalışmıyor.** Eklentiyi devre dışı bırakıp yeniden etkinleştirmek görevi yeniden kurar.

## Kaynak kod

[github.com/erayusta/erusoft-woocommerce-hepsiburada](https://github.com/erayusta/erusoft-woocommerce-hepsiburada)

## Destek

- Hata mı buldunuz? Eklentinin GitHub deposunda issue açın; WordPress, WooCommerce, PHP ve eklenti sürümünü, adımları ve beklenen/gerçekleşen sonucu yazın.
- Kurulum, yapılandırma, özel geliştirme veya entegrasyon desteği için **[hello@erusoft.com](mailto:hello@erusoft.com)** adresine yazın. Erusoft hakkında: [erusoft.com](https://erusoft.com).
- Lütfen şifre, API anahtarı veya müşteri verisi (ad, telefon, TCKN, sipariş dökümü) göndermeyin; ekran görüntülerindeki kişisel verileri karartın.
