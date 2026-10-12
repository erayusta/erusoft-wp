# Config SMTP Mailer for WordPress

`wp_mail()` gönderimini `wp-config.php` içindeki sabitlerden okunan SMTP ayarlarıyla yapar. Parola veritabanında değil, yapılandırma dosyanızda kalır. Sabitler tanımlı değilse eklenti hiçbir şey yapmaz (yerel geliştirme ortamında pasif kalır). Arayüzü yoktur.

## Özellikler

- SMTP ayarları yalnızca `wp-config.php` sabitleriyle; veritabanında parola yok
- `From` ve `Return-Path` (Sender) adreslerini hizalar (SPF/DKIM uyumu)
- HTML e-postalara düz metin alternatif gövde ekler (spam filtrelerinde puan kaybını azaltır)
- Sunucu sertifikası doğrulaması açıktır
- Sabit yoksa hiçbir şey yapmaz, dışarıya bağlantı kurmaz

## Kurulum

1. `config-smtp-mailer-for-wordpress` klasörünü `wp-content/plugins/` altına kopyalayın ve etkinleştirin.
2. `wp-config.php` dosyasına, "That's all, stop editing" satırından önce sabitleri ekleyin:

```php
define( 'ERU_SMTP_HOST', 'smtp.example.com' );
define( 'ERU_SMTP_PORT', 587 );            // 465 SSL, 587 TLS
define( 'ERU_SMTP_USER', 'noreply@example.com' );
define( 'ERU_SMTP_PASS', '***' );
define( 'ERU_SMTP_SECURE', 'tls' );        // isteğe bağlı: ssl | tls | none
define( 'ERU_SMTP_FROM', 'noreply@example.com' );  // isteğe bağlı
define( 'ERU_SMTP_FROM_NAME', 'Mağaza Adı' );      // isteğe bağlı
```

## Ayarlar

| Sabit | Zorunlu | Açıklama |
|---|---|---|
| `ERU_SMTP_HOST` | evet | SMTP sunucusu. Tanımlı değilse eklenti pasiftir. |
| `ERU_SMTP_PORT` | hayır | Varsayılan 587. |
| `ERU_SMTP_USER` / `ERU_SMTP_PASS` | hayır | Tanımlıysa kimlik doğrulama açılır. |
| `ERU_SMTP_SECURE` | hayır | `ssl`, `tls` veya `none`. Boşsa port 465 için `ssl`, diğerleri için `tls`. |
| `ERU_SMTP_FROM` | hayır | Gönderen adresi; yoksa `ERU_SMTP_USER` e-posta ise o kullanılır. |
| `ERU_SMTP_FROM_NAME` | hayır | Gönderen adı; yoksa WooCommerce e-posta gönderen adı veya site adı. |

## Kancalar ve filtreler

| Ad | Tür | Açıklama |
|---|---|---|
| `eru_smtp_config` | filtre | Yapılandırma dizisini değiştirir; `null` döndürmek eklentiyi o istek için pasifleştirir |
| `eru_smtp_add_alt_body` | filtre | `false` döndürerek düz metin alternatifini kapatır |

## Gereksinimler

WordPress 6.5+, PHP 8.0+. WooCommerce gerekmez.

## Sınırlamalar

- Ayar ekranı yoktur; yapılandırma yalnızca `wp-config.php` üzerindendir.
- Kendinden imzalı sertifikalı sunucular için `eru_smtp_config` filtresiyle veya `phpmailer_init` kancasıyla `SMTPOptions` eklemeniz gerekir.
- OAuth2 (ör. Gmail/Microsoft 365 modern kimlik doğrulama) desteklenmez.
- Veritabanına hiçbir şey yazmaz; kaldırdıktan sonra `wp-config.php` içindeki SMTP sabitlerini kendiniz silin.

## SSS

**Gönderim çalışmıyor, nasıl denerim?** `wp eval 'var_dump(wp_mail(get_option("admin_email"),"Deneme","Merhaba"));'` komutunu çalıştırın.

**Parolayı neden ayar ekranından girmiyorum?** Parolalar veritabanı yedeklerine ve dışa aktarımlara sızmasın diye.

## English summary

Sends `wp_mail()` through SMTP configured by `ERU_SMTP_*` constants in `wp-config.php`, aligns From/Return-Path and adds a plain-text alternative body to HTML mail. Does nothing when `ERU_SMTP_HOST` is not defined. No settings screen.

## Sürüm notları

Bkz. [CHANGELOG.md](CHANGELOG.md).

## Lisans

GPL-2.0-or-later. Diğer Erusoft eklentileri: https://wordpress.erusoft.com
