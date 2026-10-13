# Welcome Note for WordPress

Seçtiğiniz rollere yönetim panelinde kapatılabilir bir not, isterseniz sitede duyuru çubuğu gösterir.

> **English:** A dismissible welcome note for chosen roles in the admin area and an optional front-end announcement bar. Dismissal is remembered per user (user meta + nonce'd AJAX); changing the text shows it again.

[![Lisans: GPL-2.0-or-later](https://img.shields.io/badge/lisans-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html) ![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg) ![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)

![Ayarlar](../../docs/screenshots/welcome-note-for-wordpress/1.png)
![Yönetim panelinde not](../../docs/screenshots/welcome-note-for-wordpress/2.png)
![Önyüz çubuğu, masaüstü](../../docs/screenshots/welcome-note-for-wordpress/3.png)
![Önyüz çubuğu, mobil](../../docs/screenshots/welcome-note-for-wordpress/4.png)

## Ne işe yarar

Ekibinize ya da ziyaretçilere kısa bir not göstermenin sade yolu: metin ve bağlantıyı ayarlardan yazarsınız, hangi rollerin göreceğini seçersiniz. Kullanıcı notu kapatınca bir daha görmez; metni değiştirirseniz yeniden görünür.

## Özellikler

- Yönetim panelinde seçili rollere bilgi/başarı/uyarı görünümlü not
- İsteğe bağlı site duyuru çubuğu (üstte veya altta)
- `{name}` ve `{site}` yer tutucuları
- Kapatma kullanıcı bazlıdır (kullanıcı meta + nonce'lu AJAX); misafirlerde tarayıcıda hatırlanır
- Metin boşsa eklenti hiçbir çıktı üretmez

## Kurulum

1. `welcome-note-for-wordpress` klasörünü `wp-content/plugins/` altına kopyalayın (veya zip olarak yükleyin).
2. Eklentiler ekranından etkinleştirin.
3. Ayarları yapın (aşağıya bakın).

## Ayarlar

Ayarlar → **Hoş geldin notu**: metin, bağlantı adresi ve yazısı, görünüm, roller, ön yüzde gösterim ve konum. Seçenek: `eru_wn_settings`; kapatma kaydı kullanıcı metası `eru_wn_dismissed`.

## Kısa kodlar, kancalar, filtreler

| Ad | Tür | Açıklama |
|---|---|---|
| `eru_wn_settings` | seçenek | Eklenti ayarları |
| `eru_wn_dismissed` | kullanıcı meta | Kapatılan notun anahtarı |
| `wp_ajax_eru_wn_dismiss` | AJAX | Nonce'lu kapatma isteği |

## Entegrasyonlar ve gereksinimler

WordPress 6.5+, PHP 8.0+. Harici servis kullanmaz.


## Sınırlamalar

- Tek bir not vardır; çoklu not ya da zamanlama yoktur.
- Ön yüz çubuğu rol bazlı değil, tüm ziyaretçiler içindir; rol seçimi yalnızca yönetim paneli notunu etkiler.

## SSS

**Notu herkes için yeniden gösterebilir miyim?** Evet: metni ya da bağlantıyı değiştirmeniz yeterlidir.

## Değişiklik geçmişi

Bkz. [CHANGELOG.md](CHANGELOG.md).

## Lisans

GPL-2.0-or-later.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com/
