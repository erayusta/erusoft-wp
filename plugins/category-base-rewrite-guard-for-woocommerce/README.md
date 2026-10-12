# Category Base Rewrite Guard for WooCommerce

SEO eklentisi ürün kategori tabanını kaldırıyorsa (ör. Rank Math "ürün kategori tabanını kaldır") kategori adresleri (`/icecekler/`) yeniden yazma kurallarına anlık olarak üretilerek eklenir. Kurallar SEO eklentisi yüklü değilken (ör. `--skip-plugins` ile flush, bozuk bir bootstrap) eksik kaydedilirse tüm kategoriler 404 verir ve biri yeniden flush edene kadar öyle kalır. Bu eklenti eksik kuralları yeniden üretir. Kurallar tamsa hiçbir şey yapmaz. Arayüzü yoktur.

## Özellikler

- Eksik kategori kurallarını `rewrite_rules_array` filtresinde yeniden üretir (sayfa/yazı kurallarından önce)
- Kaydedilmiş kurallarda eksik varsa en çok saatte bir kontrol edip yumuşak flush ile onarır
- Rank Math ayarı kapalıysa karışmaz; ayarı geçersiz kılmaz
- "Üst kategori slug'larını kaldır" ayarını destekler (`/alt-kategori/` veya `/ust/alt-kategori/`)
- Bozuk üst-kategori döngülerine karşı korumalı

## Kurulum

1. `category-base-rewrite-guard-for-woocommerce` klasörünü `wp-content/plugins/` altına kopyalayın ve etkinleştirin.
2. İsteğe bağlı: tek dosyalı ve bağımsız olduğu için ana PHP dosyasını `wp-content/mu-plugins/` altına da kopyalayabilirsiniz; böylece eklenti listesini atlayan komutlarda da çalışır.

## Ayarlar

Ayar ekranı yoktur. Etkinlik, Rank Math'in `rank-math-options-general` seçeneğindeki `wc_remove_category_base` ve `wc_remove_category_parent_slugs` değerlerinden okunur.

## Kancalar ve filtreler

| Ad | Tür | Açıklama |
|---|---|---|
| `eru_cbrg_mode` | filtre | Koruma modu: `['flat' => bool]` veya kapatmak için `null`. Başka SEO eklentileri için kullanın |

Örnek:

```php
add_filter( 'eru_cbrg_mode', fn( $mode ) => array( 'flat' => false ) );
```

## Gereksinimler

WordPress 6.5+, PHP 8.0+, WooCommerce 9.0+.

## Sınırlamalar

- Varsayılan olarak yalnızca Rank Math ayarını tanır; diğer eklentiler için `eru_cbrg_mode` filtresi gerekir.
- Tabansız kategori adresleri kullanmıyorsanız (`eru_cbrg_mode` `null`) eklenti hiçbir şey yapmaz.
- Eklentiler (WooCommerce dahil) yüklenmeden çalışan komutlarda kategori taksonomisi bulunmadığı için kural üretilemez.
- Eklenti silindiğinde (Eklentiler → Sil) yalnızca kendi geçici verisi (`eru_cbrg_checked` transient'i) silinir; eklenti başka kalıcı veri saklamaz.

## SSS

**Kategorilerim 404 veriyor, bu eklenti düzeltir mi?** Sebep eksik yeniden yazma kurallarıysa evet; Ayarlar → Kalıcı bağlantılar ekranında Kaydet'e basmak da aynı işi tek seferlik görür.

## English summary

When an SEO plugin removes the product-category base (e.g. Rank Math), the base-less category rewrite rules are generated on the fly; if rules get saved incomplete (flush while the SEO plugin isn't loaded) every category 404s. This guard regenerates missing rules, self-heals stored rules at most once an hour, and stays idle when the rules are complete or the setting is off.

## Sürüm notları

Bkz. [CHANGELOG.md](CHANGELOG.md).

## Lisans

GPL-2.0-or-later. Diğer Erusoft eklentileri: https://wordpress.erusoft.com
