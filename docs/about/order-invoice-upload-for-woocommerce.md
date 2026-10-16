# Order Invoice Upload for WooCommerce

## Ne işe yarar

Sipariş ekranından o siparişe PDF fatura yüklemenizi, müşterinizin de bu faturayı kendi hesabından indirmesini sağlar. Eklenti fatura üretmez ve e-fatura sistemleriyle konuşmaz; başka bir yerde hazırladığınız PDF dosyasını siparişe bağlayıp güvenli biçimde müşteriye ulaştırır.

## Özellikler

- Sipariş düzenleme ekranında "E-Fatura Yönetimi" kutusu: PDF yükleme, mevcut faturaları listeleme, indirme ve silme. Klasik sipariş ekranı ve HPOS ekranı birlikte desteklenir.
- Yükleme ve silme işlemleri sipariş notu olarak kaydedilir.
- Yüklemede dört kontrol yapılır: MIME türü PDF olmalı, uzantı `.pdf` olmalı, dosya `%PDF-` imzasıyla başlamalı ve boyut 10 MB'ı aşmamalı.
- Dosyalar özel bir alt dizine (`uploads/e-fatura-invoices`), tahmin edilmesi zor bir adla kaydedilir.
- Yükleme ve silme yalnızca `manage_woocommerce` yetkisine sahip kullanıcılara açıktır.
- Müşteri tarafında: sipariş detay sayfasında "Faturalar" tablosu ve Hesabım → Siparişler listesinde "Fatura" sütunu (en son yüklenen fatura).
- İndirme bağlantıları nonce ile korunur; sipariş sahibi, yönetici ya da sipariş anahtarını (`key`) taşıyan misafir isteği kabul edilir.
- "Sipariş tamamlandı" ve "müşteri faturası" e-postalarına en son yüklenen fatura ek olarak eklenir.
- Kullanıcı başına saatte en fazla 10 yükleme ve 10 indirme isteği (başarılı istekler dahil).
- Arayüz metinleri çeviriye hazırdır (`.pot` dosyası depoda).

## Kurulum

1. GitHub deposunda "Code → Download ZIP" ile ZIP dosyasını indirin.
2. WordPress yönetiminde Eklentiler → Yeni ekle → Eklenti yükle yolunu izleyip ZIP'i yükleyin ve etkinleştirin. Etkinleştirmede faturalar için bir veritabanı tablosu ve yükleme dizini oluşturulur.
3. Ayar ekranı yoktur. Bir siparişi açın; yan sütundaki **E-Fatura Yönetimi** kutusundan PDF'i seçip "Fatura Yükle" düğmesine basın.

## Gereksinimler

- WordPress 6.5 veya üzeri, WooCommerce 9.0 veya üzeri, PHP 8.0 veya üzeri (katalog kaydı).
- Eklentinin kendi `readme.txt` dosyası daha düşük asgari değerler (WordPress 6.0, PHP 7.4) bildirir; bu sayfada katalogdaki değerler esas alınır.

## Sınırlamalar

- Yalnızca PDF desteklenir; başka dosya türleri reddedilir.
- Fatura oluşturma, numaralandırma veya resmi e-fatura/e-arşiv entegrasyonu yoktur.
- Koruma dosyası (`.htaccess`) dizin listelemeyi ve betik türlerini kapatır, ancak PDF'lerin adresten doğrudan açılmasını engellemez. Dosya adı tahmin edilmesi zor olsa da, hassas ortamlarda bu dizini sunucu kuralıyla ayrıca kapatmanız önerilir. Apache dışı sunucularda `.htaccess` hiç uygulanmaz.
- Toplu fatura yüklemede saatlik 10 yükleme sınırına takılabilirsiniz.
- Hesabım listesinde siparişin yalnızca en son faturası görünür; diğerleri sipariş detay sayfasındaki tabloda listelenir.
- Giriş yapmamış (misafir) kullanıcıların hız sınırı sayacı ortaktır.

## SSS

**Yalnızca PDF mi yükleyebilirim?** Evet. Güvenlik gerekçesiyle MIME türü, uzantı ve dosya imzası kontrol edilir; PDF olmayan dosyalar reddedilir.

**Müşteri faturayı nereden indirir?** Sipariş detay sayfasındaki "Faturalar" tablosundan ya da Hesabım → Siparişler listesindeki "Fatura" sütunundan.

**Fatura e-postaya eklenir mi?** Evet; "Sipariş tamamlandı" ve "müşteri faturası" e-postalarına, o sipariş için en son yüklenen fatura eklenir.

**Faturayı yanlış yükledim, ne yapmalıyım?** Sipariş ekranındaki kutudan "Sil" ile kaldırıp doğru dosyayı yükleyin; işlem sipariş notuna yazılır.

## Kaynak kod

[github.com/erayusta/efatura](https://github.com/erayusta/efatura)

## Destek

- Hata mı buldunuz? Eklentinin GitHub deposunda issue açın; WordPress, WooCommerce, PHP ve eklenti sürümünü, adımları ve beklenen/gerçekleşen sonucu yazın.
- Kurulum, yapılandırma, özel geliştirme veya entegrasyon desteği için **[hello@erusoft.com](mailto:hello@erusoft.com)** adresine yazın. Erusoft hakkında: [erusoft.com](https://erusoft.com).
- Lütfen şifre, API anahtarı veya müşteri verisi (ad, telefon, TCKN, sipariş dökümü) göndermeyin; ekran görüntülerindeki kişisel verileri karartın.
