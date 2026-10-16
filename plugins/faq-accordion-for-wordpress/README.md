# FAQ Accordion for WordPress

Sıkça sorulan soruları gruplayıp erişilebilir akordeon olarak gösterir; isteğe bağlı FAQPage şeması ekler.

> **English:** Manage FAQs as sortable items grouped by category and show them with the accessible `[eru_faq]` accordion (native `<details>/<summary>`). Optional FAQPage JSON-LD (default on) is printed once per page.

[![Lisans: GPL-2.0-or-later](https://img.shields.io/badge/lisans-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html) ![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg) ![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)

![Soru listesi (sürükle-bırak)](../../docs/screenshots/faq-accordion-for-wordpress/1.png)
![Ayarlar](../../docs/screenshots/faq-accordion-for-wordpress/2.png)
![Önyüz, masaüstü](../../docs/screenshots/faq-accordion-for-wordpress/3.png)
![Önyüz, mobil](../../docs/screenshots/faq-accordion-for-wordpress/4.png)

## Ne işe yarar

Sorularınızı yönetim panelinde SSS içerik türü olarak girer, gruplara ayırır ve sürükleyerek sıralarsınız. `[eru_faq]` kısa kodu bunları klavyeyle de kullanılabilen bir akordeon olarak gösterir; arama motorları için FAQPage şeması sayfaya bir kez eklenir.

## Özellikler

- SSS içerik türü ve grup sınıflandırması; liste ekranında sürükle-bırak sıralama
- `<details>/<summary>` tabanlı, JavaScript gerektirmeyen erişilebilir akordeon
- İsteğe bağlı "tek seferde bir soru" davranışı (destekleyen tarayıcılarda)
- FAQPage JSON-LD (varsayılan açık) sayfa başına yalnızca bir kez basılır
- Cevaplar `wp_kses_post` ile temizlenir; koyu/açık temada çalışan sade CSS

## Nasıl kullanılır

1. Eklentiyi etkinleştirin; yönetim panelinde **SSS** menüsünü açın.
2. "Yeni ekle" ile soru olarak "Demo soru nedir?" başlığını, cevap olarak kısa bir metin yazın ve "Demo Grup" adlı bir grup seçin.
3. Birkaç soru daha ekleyip liste ekranında sürükleyerek sıralayın.
4. Bir sayfaya `[eru_faq group="demo-grup" open="first"]` kısa kodunu ekleyin (grup kısa adı sınıflandırma ekranında yazar).
5. Sayfayı açın: sorular akordeon olarak görünür, ilki açıktır; sayfanın kaynağında bir FAQPage JSON-LD bloğu bulunur.
6. Şemayı istemezseniz SSS → Ayarlar'dan kapatın ya da `schema="0"` verin.

## Kurulum

1. `faq-accordion-for-wordpress` klasörünü `wp-content/plugins/` altına kopyalayın (veya zip olarak yükleyin).
2. Eklentiler ekranından etkinleştirin.
3. Ayarları yapın (aşağıya bakın).

## Ayarlar

SSS → **Ayarlar**: şema açık/kapalı, açılış durumu (hepsi kapalı / ilk açık / hepsi açık), tek seferde bir soru, vurgu rengi. Ayarlar `eru_faq_settings` seçeneğinde saklanır.

## Kısa kodlar, kancalar, filtreler

| Ad | Tür | Açıklama |
|---|---|---|
| `[eru_faq]` | kısa kod | `group` (grup kısa adı), `limit`, `open` (`none`/`first`/`all`), `schema` (`1`/`0`) |
| `eru_faq_print_schema` | filtre | `($yazdır, $öğeler)`; şema çıktısını koşullu kapatır |
| `eru_faq` / `eru_faq_group` | içerik türü / sınıflandırma | Sorular ve gruplar |

## Entegrasyonlar ve gereksinimler

WordPress 6.5+, PHP 8.0+. WooCommerce gerekmez. Harici servis kullanmaz.


## Sınırlamalar

- Aynı sayfadaki birden çok kısa kod için FAQPage şeması yalnızca ilk kısa kodun sorularını içerir (arama motorları sayfa başına tek FAQPage ister).
- Blok sürümü yoktur; kısa kod bloğu içinde kullanın.

## SSS

**Şemayı kapatabilir miyim?** Evet: Ayarlar'dan ya da `[eru_faq schema="0"]` ile.

**Eklentiyi silersem sorularım gider mi?** Hayır. Silmede yalnızca `eru_faq_settings` seçeneği silinir; SSS kayıtları içerik olduğu için kalır. Devre dışı bırakmak hiçbir veriyi silmez.

**JavaScript gerekir mi?** Akordeon `<details>/<summary>` ile çalıştığı için gerekmez; "tek seferde bir soru" davranışı destekleyen tarayıcılarda çalışır.

**Aynı sayfada birden çok kısa kod kullanabilir miyim?** Evet; ancak FAQPage şeması yalnızca ilk kısa kodun sorularını içerir (bkz. Sınırlamalar).

**Dış servise bağlanır mı?** Hayır.

**Çeviri nasıl yapılır?** Metin alanı `faq-accordion-for-wordpress`; çeviri yolu `/languages`.

## Değişiklik geçmişi

Bkz. [CHANGELOG.md](CHANGELOG.md).

## Destek

- Önce bu sayfadaki **SSS** ve **Sınırlamalar** bölümlerine göz atın; sık karşılaşılan durumlar orada.
- Hata mı buldunuz? [GitHub Issues](https://github.com/erayusta/erusoft-wp/issues) üzerinden bildirin. WordPress, PHP ve eklenti sürümünü, adımları ve beklenen/gerçekleşen sonucu yazmanız işi hızlandırır.
- Kurulum, yapılandırma, özel geliştirme veya entegrasyon desteği için **[hello@erusoft.com](mailto:hello@erusoft.com)** adresine yazın. Erusoft hakkında: [erusoft.com](https://erusoft.com).
- Lütfen şifre, API anahtarı veya müşteri verisi (ad, telefon, TCKN, sipariş dökümü) göndermeyin; ekran görüntülerindeki kişisel verileri karartın.

## Lisans

GPL-2.0-or-later.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com/
