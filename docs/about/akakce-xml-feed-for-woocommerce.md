# Akakçe XML Feed for WooCommerce

## Ne işe yarar

WooCommerce ürünlerinizi Akakçe fiyat karşılaştırma sitesinin beklediği XML biçiminde otomatik olarak yayınlar. Akakçe'ye bu adresi vermeniz yeterlidir; eklenti ürün, fiyat, stok, kargo ve kategori bilgilerini kendisi derler ve belirlediğiniz aralıkla yeniler.

## Özellikler

- `/akakce-feed.xml` adresinde `products` kökü altında ürün başına bir `product` kaydı: SKU, ad, adres, ana ve ek görseller (en fazla 5), açıklama, fiyat, kargo ücreti, teslimat süresi, stok adedi, marka, kategori yolu, barkod, bölgesel teslimat ve kampanya kodu.
- Varyasyonlu ürünlerde ana ürün atlanır, her stokta olan varyasyon ayrı kayıt olur ve adı "ana ürün - varyasyon" biçiminde yazılır.
- İndirimli ürünlerde indirimli fiyat yazılır.
- Kargo ücreti: varsayılan ücret, ücretsiz kargo eşiği, sanal/indirilebilir ürünler, `free-shipping` kargo sınıfı ve ürüne özel kargo alanı dikkate alınır.
- Marka bilgisi `product_brand`, `brand`, `pwb-brand`, `yith_product_brand` sınıflandırmalarından ya da yaygın marka meta alanlarından okunur. Barkod olarak WooCommerce'in GTIN alanı kullanılır.
- Ayarlar: ücretsiz kargo eşiği, varsayılan kargo ücreti, kargoya verme süresi, aynı gün kargo saati, distribütör garantisi, bölgesel teslimat (plaka kodları), hariç tutulacak kategoriler, yalnızca stoktakiler.
- Güncelleme aralığı: saatlik, günde 2, 4 veya 8 kez ya da günlük; zamanlı görev XML'i yeniler. Yayın, bir saatlik geçici önbellekten sunulur.
- Yönetimde son oluşturma zamanı, "XML'i şimdi oluştur" düğmesi ve Akakçe sunucu IP listesi yer alır.
- HPOS uyumluluğu bildirilir; Türkçe ve İngilizce çeviri dosyaları depodadır.

## Kurulum

1. GitHub deposunun Releases bölümünden ya da "Code → Download ZIP" ile ZIP dosyasını indirin.
2. Eklentiler → Yeni ekle → Eklenti yükle ile yükleyip etkinleştirin.
3. **WooCommerce → Akakçe XML Feed** sayfasında kargo, teslimat ve güncelleme ayarlarını yapıp kaydedin.
4. Besleme adresi `https://siteniz.com/akakce-feed.xml` olur. Adres 404 veriyorsa aynı sayfadaki bağlantıyla ya da Ayarlar → Kalıcı Bağlantılar'dan kaydederek kuralları yenileyin.
5. Adresi Akakçe'ye bildirin ve sunucunuzun Akakçe IP'lerine erişime izin verdiğinden emin olun.

## Gereksinimler

- WordPress 6.5 veya üzeri, WooCommerce 9.0 veya üzeri, PHP 8.0 veya üzeri (katalog kaydı). Eklenti üstbilgisi daha düşük asgari değerler bildirir (WordPress 5.0, WooCommerce 3.0, PHP 7.4).

## Sınırlamalar

- Ürün bazlı teslimat, kargo, distribütör, bölge ve kampanya değerleri, ürün meta alanlarından okunur; bu alanlar için yönetimde ayrı bir düzenleme kutusu sunulmaz.
- Depodaki README'de anılan `akakce_xml_product_data` gibi süzgeç kancaları kod tabanında bulunmuyor; XML çıktısını kancayla değiştirmek şu an mümkün görünmüyor.
- Yalnızca yayımlanmış ve satın alınabilir ürünler listelenir; "yalnızca stoktakiler" açıkken stoksuz ürünler dışarıda kalır.
- Ayar sayfasını yalnızca `manage_options` yetkisi olan kullanıcılar görür.

## SSS

**Besleme adresi neden 404 veriyor?** Yeniden yazma kuralları henüz yenilenmemiş olabilir. Ayarlar sayfasındaki yenileme bağlantısını kullanın ya da Kalıcı Bağlantılar'ı kaydedin.

**Varyasyonlu ürünler nasıl görünür?** Her varyasyon kendi SKU, fiyat ve stokuyla ayrı bir `product` kaydı olarak yazılır.

**Aynı gün kargo nasıl belirtilir?** Kargoya verme süresini 0 girin; aynı gün kargo saati XML'e eklenir.

**XML'i hemen yenileyebilir miyim?** Evet; ayar sayfasındaki "XML'i şimdi oluştur" düğmesi kaydı yeniler ve önbelleği temizler.

## Kaynak kod

[github.com/erayusta/akakce-xml-feed](https://github.com/erayusta/akakce-xml-feed)

## Destek

- Hata mı buldunuz? Eklentinin GitHub deposunda issue açın; WordPress, WooCommerce, PHP ve eklenti sürümünü, adımları ve beklenen/gerçekleşen sonucu yazın.
- Kurulum, yapılandırma, özel geliştirme veya entegrasyon desteği için **[hello@erusoft.com](mailto:hello@erusoft.com)** adresine yazın. Erusoft hakkında: [erusoft.com](https://erusoft.com).
- Lütfen şifre, API anahtarı veya müşteri verisi (ad, telefon, TCKN, sipariş dökümü) göndermeyin; ekran görüntülerindeki kişisel verileri karartın.
