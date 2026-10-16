# Sort Order Cache Purge for WooCommerce

Ürün sıralaması kaydedildiğinde sayfa önbelleğini temizler. Sıralamayı doğrudan SQL ile yazan eklentiler (ör. Rearrange WooCommerce Products) hiçbir WordPress kancası tetiklemediği için önbellek eklentileri yeni sırayı fark etmez; yeni sıra önbellek süresi dolana kadar görünmez. Bu eklenti o boşluğu kapatır. Arayüzü yoktur.

## Özellikler

- Sıralama kaydeden AJAX isteklerini algılar; kategoriye özel sıralamada yalnızca o arşivi, genel sıralamada tüm önbelleği temizler
- Parçalı (chunk) kaydetmede yalnızca son parçadan sonra temizler
- Ürünün `menu_order` değeri kaydedilirken değişirse de temizler
- LiteSpeed Cache için hazır (`litespeed_purge_all`, `litespeed_purge`); diğer önbellekler için kendi kancalarımızı dinleyin
- Yetki kapısı: yalnızca `manage_woocommerce` yetkili kullanıcıların istekleri tetikler (ziyaretçi önbelleği boşaltamaz)

## Kurulum

1. `sort-order-cache-purge-for-woocommerce` klasörünü `wp-content/plugins/` altına kopyalayın ve etkinleştirin.
2. Başka ayar gerekmez.

## Ayarlar

Ayar ekranı yoktur. İzlenen AJAX action'ları `eru_socp_actions` filtresiyle değiştirilir.

## Nasıl kullanılır

1. Sitenizde LiteSpeed Cache etkinse ek bir şey gerekmez; başka bir önbellek eklentisi kullanıyorsanız önce aşağıdaki "Kancalar ve filtreler" bölümündeki örnek gibi bir satırla kendi temizleme işlevinizi `eru_socp_purge_all` eylemine bağlayın.
2. Eklentiyi etkinleştirin.
3. Mağaza yöneticisi (`manage_woocommerce` yetkisi) olarak sıralama eklentinizin ekranını açın ve "Demo Kategori" içinde "Demo Ürün"ü listenin başına sürükleyip kaydedin.
4. Eklenti kaydetme AJAX isteğini algılar; kategoriye özel sıralamada yalnızca o kategori arşivinin, genel sıralamada tüm önbelleğin temizlenmesini ister.
5. Mağazayı çıkış yapmış bir tarayıcıda açın: yeni sıra önbellek süresinin dolmasını beklemeden görünür.

## Kancalar ve filtreler

| Ad | Tür | Açıklama |
|---|---|---|
| `eru_socp_actions` | filtre | İzlenen sıralama action'ları (`action => ['term' => alan]` veya `['all' => true]`) |
| `eru_socp_purge_all` | eylem | Tüm önbellek temizlenmeli; kendi önbellek eklentinizi buraya bağlayın |
| `eru_socp_purge_term` | eylem | Bir terim arşivi temizlenmeli (`$term_id`) |

Örnek (WP Rocket):

```php
add_action( 'eru_socp_purge_all', 'rocket_clean_domain' );
```

## Gereksinimler

WordPress 6.5+, PHP 8.0+, WooCommerce 9.0+.

## Sınırlamalar

- Yerleşik temizlik yalnızca LiteSpeed Cache içindir; diğerleri için yukarıdaki eylemlere bağlanın.
- Varsayılan action listesi Rearrange WooCommerce Products eklentisine göredir; başka bir sıralama eklentisi için `eru_socp_actions` ile ekleyin.
- Sıralamayı hiçbir kanca tetiklemeden doğrudan SQL ile yazan ve AJAX kullanmayan yöntemler algılanamaz.
- Veritabanına hiçbir şey yazmaz; kaldırıldığında silinecek ayar veya geçici veri yoktur.

## SSS

**Neden `shutdown` kancasında, negatif öncelikle?** LiteSpeed temizlik başlıklarını `shutdown` önceliği 0'da gönderir; sonra eklenen temizlik etiketi kaybolur.

**Eklentiyi devre dışı bırakınca veya silince ne olur?** Hiçbir şey kalmaz: eklenti seçenek, geçici veri, tablo veya meta yazmaz. Devre dışı bırakınca otomatik temizlik durur ve sıralama değişiklikleri önbellek süresi dolana kadar görünmez olur.

**HPOS ve bloklar?** HPOS uyumluluğu bildirilir; eklenti sipariş verisine ve sepet/ödeme ekranlarına dokunmaz, bu yüzden bu konular geçerli değildir.

**Hangi sıralama eklentilerini tanır?** Varsayılan action listesi "Rearrange WooCommerce Products" eklentisine göredir. Başka bir eklenti için kaydetme action'ını `eru_socp_actions` filtresiyle ekleyin.

**Dış servis çağırıyor mu?** Hayır; yalnızca yerel temizlik eylemlerini tetikler.

**Ürünü düzenleme ekranında sıra numarasını değiştirirsem?** Ürünün `menu_order` değeri kaydedilirken değişirse tüm önbellek temizlenir.

## English summary

Purges the page cache when product sort order is saved by plugins that write `menu_order` with raw SQL (no hooks fire). Watches sorting AJAX actions (filterable), purges one term archive or everything, handles chunked saves, requires `manage_woocommerce`. LiteSpeed built in; other caches via `eru_socp_purge_all` / `eru_socp_purge_term`.

## Sürüm notları

Bkz. [CHANGELOG.md](CHANGELOG.md).

## Destek

- Önce bu sayfadaki **SSS** ve **Sınırlamalar** bölümlerine göz atın; sık karşılaşılan durumlar orada.
- Hata mı buldunuz? [GitHub Issues](https://github.com/erayusta/erusoft-wp/issues) üzerinden bildirin. WordPress, WooCommerce, PHP ve eklenti sürümünü, adımları ve beklenen/gerçekleşen sonucu yazmanız işi hızlandırır.
- Kurulum, yapılandırma, özel geliştirme veya entegrasyon desteği için **[hello@erusoft.com](mailto:hello@erusoft.com)** adresine yazın. Erusoft hakkında: [erusoft.com](https://erusoft.com).
- Lütfen şifre, API anahtarı veya müşteri verisi (ad, telefon, TCKN, sipariş dökümü) göndermeyin; ekran görüntülerindeki kişisel verileri karartın.

## Lisans

GPL-2.0-or-later. Diğer Erusoft eklentileri: https://wordpress.erusoft.com
