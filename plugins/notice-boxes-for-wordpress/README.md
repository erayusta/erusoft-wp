# Notice Boxes for WordPress

Bilgi, uyarı, başarı ve hata kutuları: 4 tür × 3 görünüm, kısa kod ve blok olarak.

> **English:** Info/warning/success/error boxes in three style presets (soft, solid, outline) as the `[eru_notice]` shortcode and a server-rendered Gutenberg block (`eru/notice-box`, no build step). Dark-mode aware.

[![Lisans: GPL-2.0-or-later](https://img.shields.io/badge/lisans-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html) ![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg) ![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)

![Ayarlar ve örnekler](../../docs/screenshots/notice-boxes-for-wordpress/1.png)
![Önyüz, masaüstü](../../docs/screenshots/notice-boxes-for-wordpress/2.png)
![Önyüz, mobil](../../docs/screenshots/notice-boxes-for-wordpress/3.png)

## Ne işe yarar

Yazı içinde dikkat çekmesi gereken notları şık kutular olarak gösterir. Aynı çıktıyı hem kısa kod hem blok üretir; koyu mod desteklidir.

## Özellikler

- 4 tür (bilgi, uyarı, başarı, hata) × 3 görünüm (yumuşak, dolgulu, çerçeveli)
- Kısa kod ve blok aynı sunucu tarafı çıktıyı kullanır
- Blok `block.json` + sade JavaScript ile kayıtlıdır (derleme adımı yok)
- Cihazın koyu moduna uyar; istenirse kapatılabilir
- Başlık kaçışlanır, içerik `wp_kses_post` ile temizlenir

## Kurulum

1. `notice-boxes-for-wordpress` klasörünü `wp-content/plugins/` altına kopyalayın (veya zip olarak yükleyin).
2. Eklentiler ekranından etkinleştirin.
3. Ayarları yapın (aşağıya bakın).

## Nasıl kullanılır

1. Eklentiyi etkinleştirin; Ayarlar → **Bilgi Kutuları** ekranında varsayılan türü ve görünümü seçin, "Örnekler" bölümünde 12 kombinasyona bakın.
2. Bir yazı ya da sayfada kısa kodu kullanın:

```
[eru_notice type="warning" title="Demo Uyarı" style="outline"]Demo Ürün stoğu sınırlıdır.[/eru_notice]
```

3. Blok düzenleyiciyi tercih ederseniz **Bilgi Kutusu** (`eru/notice-box`) bloğunu ekleyin; türü, başlığı ve görünümü blok ayarlarından seçip içeriği iç bloklara yazın.
4. Yazıyı önizleyin: seçtiğiniz türe uygun renk ve simgeyle, başlığı kalın bir satır olarak gösteren bir kutu görünür.
5. `type` ve `style` verilmezse Ayarlar ekranındaki varsayılanlar kullanılır; geçersiz bir değer yazılırsa tür `info`, görünüm `soft` olur.

## Ayarlar

Ayarlar → **Bilgi Kutuları**: varsayılan tür, varsayılan görünüm, "Kutularda simge göster" kutusu ve koyu mod ("Cihaz ayarını izle" veya "Her zaman açık renkler"). Aynı ekranda 12 kombinasyonun önizlemesi vardır. Seçenek: `eru_nb_settings`.

## Kısa kodlar, kancalar, filtreler

| Ad | Tür | Açıklama |
|---|---|---|
| `[eru_notice]` | kısa kod | `type` (`info`/`warning`/`success`/`error`), `title`, `style` (`soft`/`solid`/`outline`) |
| `eru/notice-box` | blok | Nitelikler: `type`, `title`, `variant`; iç bloklarla içerik |
| `eru_nb_settings` | seçenek | Eklenti ayarları |

## Entegrasyonlar ve gereksinimler

WordPress 6.5+, PHP 8.0+. Harici servis kullanmaz.


## Sınırlamalar

- Kutu içeriği blok olarak iç bloklardan oluşur; kısa kodda iç içe aynı kısa kod desteklenmez.
- Koyu mod yalnızca cihaz/tarayıcı ayarını izler; temanıza özel koyu mod sınıfı tanımlı değildir.

## SSS

**Eski kısa kodum yeni kutuyla uyumlu mu?** Kısa kod adı `[eru_notice]`'dir; öznitelikler yukarıdaki tabloda.

**Eklentiyi silersem ne olur?** Eklentiler → Sil yalnızca `eru_nb_settings` seçeneğini siler. Devre dışı bırakınca `[eru_notice]` kısa kodu yazıda düz metin olarak kalır ve blok "desteklenmeyen blok" görünür; içerik silinmez, eklentiyi yeniden etkinleştirince kutular geri gelir.

**Kutu içine HTML yazabilir miyim?** Evet, içerik `wp_kses_post` ile temizlenir: yazı düzenleyicide izin verilen etiketler kalır, betikler ve benzeri atılır. Başlıktaki etiketler temizlenir.

**Dışarıya istek yapıyor mu, ön yüzde ne yükler?** Hayır, harici servis yoktur. Kutu sayfada kullanıldığında yalnızca eklentinin küçük bir stil dosyası yüklenir.

**Koyu modu kapatabilir miyim?** Evet, Ayarlar → Bilgi Kutuları'nda koyu modu "Her zaman açık renkler" yapın.

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
