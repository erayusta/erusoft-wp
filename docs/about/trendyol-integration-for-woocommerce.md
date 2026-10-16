# Trendyol Integration for WooCommerce

## Ne işe yarar

Trendyol pazaryerinden gelen siparişleri WooCommerce'e izole biçimde aktarır. Siparişler kendi özel durumlarında bekler; normal sipariş akışınızı (stok düşümü, ERP aktarımı, kargo ve müşteri e-postaları) tetiklemez. İsterseniz WooCommerce stoklarını Trendyol'a geri gönderebilirsiniz. Bu eklenti eski bir sürümdür; yeni kurulumlar için katalogdaki Marketplace Orders eklentisine geçmeniz önerilir.

## Özellikler

- 5 dakikalık zamanlı görevle Trendyol siparişlerini çeker; çekilecek paket durumları ve geriye dönük tarama süresi (saat) ayarlanabilir.
- Sipariş kaydı sırasında `woocommerce_new_order` dinleyicileri geçici olarak devre dışı bırakılır ve hemen geri yüklenir.
- Özel sipariş durumları: yeni, alındı, hazır, kargo, teslim, iptal ve iade (`trendyol-` öneki).
- Ürün eşleme: Trendyol `stockCode` değeri WooCommerce SKU'su ile eşleşir; eşleşmeyen kalem işaretlenir ve günlüğe düşer.
- Test modu: siparişler çekilir ama WooCommerce'e yazılmaz, yalnızca günlüğe işlenir.
- İsteğe bağlı stok gönderimi (varsayılan kapalı): değişen stoklar 10 dakikalık görevle toplu gönderilir; "tüm stokları gönder" ile tam gönderim yapılabilir. Eşleme önce SKU–`stockCode`, yedek olarak GTIN–barkod ile yapılır.
- Sipariş meta alanlarında paket kimliği, sipariş numarası, fatura bilgileri, kargo takip numarası ve komisyon bilgisi tutulur (`_eru_trendyol_*`).
- Sipariş ekranında Trendyol kutusu, "Şimdi çek" ve tek sipariş içeri alma işlemleri, işlem günlüğü ekranı.
- Entegrasyon için `eru_trendyol_crm_positive` ve `eru_trendyol_crm_negative` eylemleri; her sipariş için bir kez tetiklenir.
- HPOS uyumludur.

## Kurulum

1. GitHub deposunun Releases bölümünden ya da "Code → Download ZIP" ile ZIP dosyasını indirin.
2. Eklentiler → Yeni ekle → Eklenti yükle ile yükleyip etkinleştirin; tablolar oluşturulur ve zamanlı görev kurulur.
3. **WooCommerce → Trendyol Siparişleri** sayfasında satıcı kimliği (yalnız rakam), API anahtarı ve API sırrını girin. Eklenti varsayılan olarak kapalı gelir.
4. Önce "Test modu" ile kimlikleri doğrulayın, sonra test modunu kapatıp "Aktif" kutusunu işaretleyin.
5. Stok gönderimini açmadan önce SKU eşleşmelerini doğrulayın.

## Gereksinimler

- WordPress 6.5 veya üzeri, WooCommerce 9.0 veya üzeri, PHP 8.0 veya üzeri (katalog kaydı). Eklenti üstbilgisi daha düşük asgari değerler bildirir (WordPress 5.0, PHP 7.2).
- Trendyol satıcı panelinden alınmış API bilgileri.

## Sınırlamalar

- Zamanlı görev ziyarete bağlıdır; az trafikli sitelerde gerçek bir sistem cron'u önerilir.
- Eşleme SKU üzerindendir; büyük/küçük harf dahil birebir aynı olmalıdır.
- Stok gönderimi açıkken yanlış eşleşme Trendyol stoklarını bozabilir; önce eşleşmeleri kontrol edin.
- Sipariş meta alanları fatura bilgisi gibi kişisel veri içerebilir; veri koruma yükümlülükleri mağaza sahibindedir.
- Eski sürüm olarak sunulur; yeni özellik geliştirmesi beklenmemelidir.

## SSS

**Test modunda ne olur?** Siparişler çekilir ve günlüğe yazılır ama WooCommerce'e kaydedilmez. Kimlik bilgilerini doğrulamak için idealdir.

**Siparişler neden stok düşürmüyor?** Pazaryeri stoku kendi tarafında yönetir; çift düşümü önlemek için sipariş izole kaydedilir. İsterseniz kendi stok mantığınızı `eru_trendyol_crm_positive` eylemine bağlayabilirsiniz.

**401 hatası alıyorum.** Satıcı kimliğinin yalnız rakamdan oluştuğunu, API anahtarı ve sırrında baş/son boşluk kalmadığını kontrol edin; günlük ekranında hata görünür.

**Stok gönderimi nasıl çalışır?** Ayar açıksa değişen ürünler işaretlenir ve görev bunları toplu olarak Trendyol'a yollar.

## Kaynak kod

[github.com/erayusta/erusoft-woocommerce-trendyol](https://github.com/erayusta/erusoft-woocommerce-trendyol)

## Destek

- Hata mı buldunuz? Eklentinin GitHub deposunda issue açın; WordPress, WooCommerce, PHP ve eklenti sürümünü, adımları ve beklenen/gerçekleşen sonucu yazın.
- Kurulum, yapılandırma, özel geliştirme veya entegrasyon desteği için **[hello@erusoft.com](mailto:hello@erusoft.com)** adresine yazın. Erusoft hakkında: [erusoft.com](https://erusoft.com).
- Lütfen şifre, API anahtarı veya müşteri verisi (ad, telefon, TCKN, sipariş dökümü) göndermeyin; ekran görüntülerindeki kişisel verileri karartın.
