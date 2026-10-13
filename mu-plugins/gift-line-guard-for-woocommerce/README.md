# Gift Line Guard for WooCommerce

Sıfır fiyatlı hediye sepet satırlarının adedini kilitler ve fiyatını 0'da tutar. **Gift Product Rules ile birlikte kullanın.**

> **English:** A must-use plugin that protects zero-priced gift cart lines: quantity is locked to the first value seen, the price is forced to 0 and the order line is always free. Works with Gift Product Rules for WooCommerce (it reads the `_eru_gpr_gift` cart item key) and with any other gift plugin through the `eru_glg_is_gift_line` filter.

[![Lisans: GPL-2.0-or-later](https://img.shields.io/badge/lisans-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html) ![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg) ![WooCommerce 9.0+](https://img.shields.io/badge/WooCommerce-9.0%2B-96588a.svg) ![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)

> Ekran görüntüsü yoktur: eklentinin arayüzü veya ayar ekranı bulunmaz, yalnızca arka planda sepet toplamı hesaplanırken çalışır.

## Ne işe yarar

Hediye satırları genellikle fiyatı 0 yapılmış normal sepet satırlarıdır; bir tema, eklenti ya da elle hazırlanmış istek adedi artırırsa müşteri "bedava" ürünü istediği kadar alabilir. Bu mu-plugin bu satırları tanır ve her toplam hesaplamasında koruma altına alır. [Gift Product Rules](../../plugins/gift-product-rules-for-woocommerce/) ile birlikte kullanın; başka bir hediye eklentiniz varsa filtreyle satırları işaretleyin.

## Özellikler

- Hediye satırının adedi ilk görülen değerde kilitlenir; değiştirme girişimi geri alınır
- Fiyat her hesaplamada 0'a sabitlenir, sepet oturumdan yüklenirken de uygulanır
- Sepette adet kutusu yalnızca okunur değerle değiştirilir; blok sepette adet düzenlenemez
- Siparişe aktarılırken hediye kaleminin ara toplamı ve toplamı sıfırlanır
- Normal satırlara dokunmaz; aynı ürünün ücretli satırı tam fiyat kalır
- WooCommerce yoksa hiçbir şey yapmaz

## Kurulum

> **Eklentiler → Yeni ekle ile yüklemeyin.** ZIP'i bilgisayarınızda açın ve `gift-line-guard-for-woocommerce.php` dosyasını doğrudan `wp-content/mu-plugins/` klasörünün **en üst düzeyine** kopyalayın (klasör yoksa oluşturun). Etkinleştirme gerekmez, dosyayı silmek devre dışı bırakır.

## Ayarlar

Ayar ekranı yoktur; varsayılan davranış güvenlidir. Davranış filtrelerle değişir.

## Kancalar, filtreler ve veri alanları

| Ad | Tür | Açıklama |
|---|---|---|
| `_eru_gpr_gift` | sepet satırı anahtarı | Gift Product Rules'ın koyduğu işaret; bu anahtarı taşıyan satır hediyedir |
| `eru_glg_is_gift_line` | filtre | `($is, $item, $key)`; başka eklentilerin hediye satırlarını `true` döndürerek işaretleyin |
| `eru_glg_allowed_quantity` | filtre | `($qty, $item, $key)`; izin verilen adedi değiştirir |
| `_eru_glg_qty` | sepet satırı anahtarı | Kilitlenen adedin kaydı (koruma tarafından yazılır) |

```php
add_filter( 'eru_glg_is_gift_line', function ( $is, $item ) {
    return $is || ! empty( $item['my_gift_flag'] );
}, 10, 2 );
```

## Entegrasyonlar ve gereksinimler

WordPress 6.5+, PHP 8.0+, WooCommerce 9.0+. Gift Product Rules ile birlikte kullanın; onsuz yalnızca filtreyle işaretlenen satırları korur.

## Sınırlamalar

- Ana ürün silindiğinde hediyeyi kaldırmak hediye eklentisinin işidir (Gift Product Rules bunu yapar); bu koruma satırı silmez.
- İlk görülen adet izin verilen adet sayılır; adedi sonradan kasıtlı değiştirmek isteyen eklentiler `eru_glg_allowed_quantity` filtresini kullanmalıdır.
- mu-plugin olduğu için eklentiler ekranında etkinleştirme/devre dışı bırakma yoktur.
- WooCommerce, mu-plugin'leri HPOS (yüksek performanslı sipariş tabloları) uyumluluk listesinde denetlemez; bu yüzden bildirim gerekmez ve uyumluluk ekranında görünmez. Eklenti sipariş tablolarına doğrudan erişmez, yalnızca sepet ve sipariş kalemi API'lerini kullanır; HPOS açıkken de çalışır.

## SSS

**Gift Product Rules olmadan işe yarar mı?** Evet, ama hediye satırlarını `eru_glg_is_gift_line` filtresiyle siz işaretlemelisiniz.

**İkisini birlikte kullanmak çift işlem yapar mı?** Hayır; ikisi de aynı değeri (0 fiyat, kuralın adedi) uygular.

## Değişiklik geçmişi

Bkz. [CHANGELOG.md](CHANGELOG.md).

## Lisans

GPL-2.0-or-later.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com/
