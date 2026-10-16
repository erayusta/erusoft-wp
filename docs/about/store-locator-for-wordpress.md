# Store Locator for WordPress

## Ne işe yarar

Şube, bayi veya yetkili servis noktalarınızı WordPress yönetiminden girip sitenizde Google Haritalar üzerinde ve liste olarak göstermenizi sağlar. Ziyaretçi il ve ilçeye göre süzer, noktanın telefonunu arar ya da yol tarifi alır. Eklentinin yönetim menüsü adı "Store Locator"dır; depodaki eklenti adı "Eru Store Locator" olarak geçer.

## Özellikler

- "Store Locations" adlı özel içerik türü: başlık, açıklama, görsel ve nokta bilgileri (adres, telefon, e-posta, enlem, boylam, çalışma saatleri).
- İl → ilçe düzeninde hiyerarşik "Service Locations" sınıflandırması; ön yüzdeki süzgeçler bu yapıdan beslenir.
- `[eru_store_locator]` kısa kodu. Öznitelikler: `height`, `zoom`, `default_city`, `show_filters`, `show_list`, `class`.
- Kenar çubuğu veya widget alanları için "Store Locator Map" widget'ı.
- Yönetimde kısa kod üretici sayfası.
- JSON olarak içe ve dışa aktarma sayfası (arayüzde 10 MB dosya sınırı).
- Ayarlar: Google Maps API anahtarı, varsayılan yakınlaştırma ve harita merkezi, ana/ikincil renk, düğme metni, arama kutusu yer tutucusu, sonuç bulunamadı metni ve işaretçi stili.
- Noktalar AJAX ile yüklenir; istekler IP başına hız sınırına tabidir.
- Arayüz çeviriye hazırdır; Türkçe çeviri dosyası depoda bulunur.

## Kurulum

1. GitHub deposunun Releases bölümünden ya da "Code → Download ZIP" ile ZIP dosyasını indirin.
2. Eklentiler → Yeni ekle → Eklenti yükle ile ZIP'i yükleyip etkinleştirin.
3. Yönetim menüsündeki **Store Locator → Settings** sayfasında Google Maps API anahtarınızı girin.
4. **Store Locator → Add New** ile noktalarınızı ekleyin ve ilgili il/ilçe terimlerini seçin.
5. Haritayı göstermek istediğiniz sayfaya `[eru_store_locator]` kısa kodunu ya da widget'ı ekleyin.

## Gereksinimler

- WordPress 6.5 veya üzeri, PHP 8.0 veya üzeri (katalog kaydı). Eklenti üstbilgisi daha düşük asgari değerler (WordPress 5.0, PHP 7.4) bildirir.
- Kendi Google Maps API anahtarınız. Kodda Maps JavaScript API ve `places` kütüphanesi yüklenir.

## Sınırlamalar

- Harita için Google'a bağlanılır; ziyaretçi sayfayı açtığında harita ve konum istekleri Google'a gider. Bunu gizlilik metninizde belirtmeniz gerekir.
- Yönetim ekranı metinleri ağırlıklı olarak İngilizcedir; Türkçe çeviri dosyası ayrıca yüklenir.
- Ayarlar sayfasındaki "özel harita stilleri" bölümü "yakında" olarak işaretlidir; henüz çalışmaz.
- **Bilinen hata (1.0.1):** ön ek geçişi (`erustlo_` → `erusl_`) yarım kalmıştır. İçerik türü, AJAX eylemleri ve betik verisi farklı adlarla kayıtlı olduğundan mağaza listesi, il/ilçe filtreleri, kısa kod oluşturucu ve içe/dışa aktarma ekranı çalışmaz. Düzeltme sürümü yayımlanana kadar canlı sitede kullanmayın.

## SSS

**Google Maps API anahtarı şart mı?** Evet. Anahtar olmadan harita yüklenmez. Anahtarı Google Cloud Console'dan alıp Settings sayfasına girersiniz.

**Noktalarımı toplu ekleyebilir miyim?** Import/Export sayfası JSON biçimini kullanır. Önce birkaç noktayı elle ekleyip dışa aktararak biçimi görmeniz işinizi kolaylaştırır.

**Sadece listeyi veya sadece haritayı gösterebilir miyim?** Kısa kodun `show_list` ve `show_filters` öznitelikleri `true`/`false` alır; yükseklik ve yakınlaştırma için `height` ve `zoom` kullanılır.

**Renkleri ve metinleri değiştirebilir miyim?** Evet; Settings sayfasında renkler, düğme metni, arama yer tutucusu ve sonuç bulunamadı metni düzenlenir.

## Kaynak kod

[github.com/erayusta/yetkiliservis](https://github.com/erayusta/yetkiliservis)

## Destek

- Hata mı buldunuz? Eklentinin GitHub deposunda issue açın; WordPress, WooCommerce, PHP ve eklenti sürümünü, adımları ve beklenen/gerçekleşen sonucu yazın.
- Kurulum, yapılandırma, özel geliştirme veya entegrasyon desteği için **[hello@erusoft.com](mailto:hello@erusoft.com)** adresine yazın. Erusoft hakkında: [erusoft.com](https://erusoft.com).
- Lütfen şifre, API anahtarı veya müşteri verisi (ad, telefon, TCKN, sipariş dökümü) göndermeyin; ekran görüntülerindeki kişisel verileri karartın.
