# High-Traffic Fixes for WooCommerce

Yüksek trafikli WooCommerce mağazaları için on bir bağımsız performans ve kararlılık düzeltmesi. Her düzeltme tek bir PHP dosyasıdır (mu-plugin); sadece ihtiyacınız olanları kurarsınız.

## Kurulum

> **Eklentiler → Yeni ekle ile yüklemeyin.** Bu paket normal bir eklenti değildir; o ekrandan yüklenen ZIP hiçbir düzeltmeyi çalıştırmaz. ZIP'i bilgisayarınızda açın ve ihtiyacınız olan `.php` dosyalarını doğrudan `wp-content/mu-plugins/` klasörünün **en üst düzeyine** kopyalayın (FTP/SFTP veya dosya yöneticisiyle; klasör yoksa oluşturun).

Sadece ihtiyacınız olan dosyaları kopyalayın. mu-plugin dosyaları alt klasörlerden yüklenmez, dosyalar doğrudan `mu-plugins/` içinde durmalıdır. Etkinleştirme gerekmez, silmek devre dışı bırakır.

Her düzeltme `apply_filters( 'eru_htf_<düzeltme>_enabled', true )` ile kapatılabilir:

```php
add_filter( 'eru_htf_cloudflare_purge_frontend_enabled', '__return_false' );
```

Sabitler yalnızca `defined()` ile okunur; tanımlamazsanız güvenli varsayılanlar geçerlidir.

## Düzeltmeler

| Dosya | Kısaca |
|---|---|
| `eru-htf-action-scheduler-sleep.php` | Action Scheduler tur arası beklemesini 5 sn → 1 sn yapar |
| `eru-htf-automatewoo-redis-flush.php` | AutomateWoo grup temizliğini SCAN'siz yapar |
| `eru-htf-cart-fragments-guard.php` | Sepeti olmayan ziyaretçide `get_refreshed_fragments` isteğini önler |
| `eru-htf-checkout-login-gate-events.php` | Giriş duvarında yanlış `begin_checkout` / `InitiateCheckout` olaylarını engeller |
| `eru-htf-category-rewrite-flush.php` | Kategori düzenlemesinde gereksiz tam cache flush'ını önler |
| `eru-htf-cloudflare-purge-frontend.php` | Ön yüzde senkron Cloudflare purge çağrılarını keser |
| `eru-htf-plugin-diet.php` | Yalnız admin işi yapan eklentileri ön yüzde yüklemez |
| `eru-htf-indexnow-nonblocking.php` | IndexNow ping'ini yanıt beklemeden gönderir |
| `eru-htf-order-status-webhook-topics.php` | Durum geçişi bazlı webhook konuları ekler |
| `eru-htf-pixelyoursite-fragment-fix.php` | PixelYourSite fragment'indeki tanımsız JS fonksiyonu hatasını giderir |
| `eru-htf-redis-flush-tracer.php` | Redis FLUSHDB çağıranını kütüğe yazar (davranış değiştirmez) |

---

### 1. Action Scheduler Sleep (`eru-htf-action-scheduler-sleep.php`)

- **Sorun:** Yüksek trafikte Action Scheduler'ın async runner'ı her kuyruk turundan sonra 5 saniye uyur; bu sürede bir PHP-FPM worker'ı boşuna meşgul kalır ve yavaş istek kayıtlarında görünür.
- **Ne yapar:** `action_scheduler_async_request_sleep_seconds` değerini 1 saniyeye çeker. Süreyi sıfırlamaz: uyku, ardışık turların birbirini kovalamasını engelleyen bir frendir. Bu bir CPU düzeltmesi değildir, kazanç worker yuvasıdır.
- **Kapatma:** `add_filter( 'eru_htf_action_scheduler_sleep_enabled', '__return_false' );` Süre için: `define( 'ERU_HTF_AS_SLEEP_SECONDS', 2 );`
- **Ne zaman KULLANMAYIN:** Trafiğiniz düşükse kazanç ölçülemez; kuyruk işlemlerinin art arda hızla çalışması sunucunuzu yoruyorsa 5 sn'yi koruyun.

### 2. AutomateWoo Redis Flush (`eru-htf-automatewoo-redis-flush.php`)

- **Sorun:** AutomateWoo her sepet/müşteri değişiminde `flush_group('dashboard')` çağırır; Redis Object Cache bunu tüm anahtar uzayında SCAN ile yapar. Redis tek iş parçacıklı olduğundan büyük veritabanlarında tüm site bir süre bloke olur.
- **Ne yapar:** `automatewoo_cache_supports_flush_group` filtresine `false` döner; AutomateWoo tek bir `wp_cache_set()` ile sürüm önekini tazeler (O(1)). Eski anahtarlar TTL ile düşer.
- **Kapatma:** `add_filter( 'eru_htf_automatewoo_redis_flush_enabled', '__return_false' );`
- **Ne zaman KULLANMAYIN:** AutomateWoo yoksa gerek yok (zararsız). Kalıcı object cache kullanmıyorsanız etkisi yoktur.

### 3. Cart Fragments Guard (`eru-htf-cart-fragments-guard.php`)

- **Sorun:** `cart-fragments.js`, sessionStorage'da veri yoksa koşulsuz `/?wc-ajax=get_refreshed_fragments` isteği atar. Siteye ilk kez giren herkes, sepeti olmasa da, önbelleklenemeyen bir PHP isteği ödetir; kampanya anlarında worker'ları bitiren budur.
- **Ne yapar:** Script'i sunucuda kuyruktan çıkarır, yerine çerez kontrol eden küçük bir yükleyici basar. Sepet/oturum çerezi yoksa script yüklenmez, AJAX hiç doğmaz. Karar tarayıcıda verilir, çünkü sayfa tam sayfa önbelleğinden gelir. Sepet, ödeme ve hesabım sayfalarına dokunulmaz.
- **Kapatma:** `add_filter( 'eru_htf_cart_fragments_guard_enabled', '__return_false' );` veya `wp-config.php` içinde `define( 'ERU_HTF_CART_FRAGMENTS_GUARD', false );`
- **Ne zaman KULLANMAYIN:** Başlıktaki sepet sayısı, çerez olmadan da sunucudan taze veri bekliyorsa (özel temalar/eklentiler `wc_fragment_refresh` bekliyorsa) önce test edin.

### 4. Checkout Login Gate Events (`eru-htf-checkout-login-gate-events.php`)

- **Sorun:** Misafir ödeme kapalı ve ödemede kayıt kapalıysa, misafir ödeme sayfasında form yerine giriş/kayıt duvarını görür. Sayfa hâlâ checkout olduğundan GTM4WP `begin_checkout`, PixelYourSite `InitiateCheckout` olayını basar; GA4, Google Ads ve Meta hunisinin ödeme adımı şişer.
- **Ne yapar:** WooCommerce çekirdeğinin erken-çıkış koşulunu aynalayıp "giriş duvarı" durumunu tespit eder; bu durumda GTM4WP push'unu footer script'leri basılmadan önce söker ve PixelYourSite olayını `pys_validate_pixel_event` ile reddeder (sunucu tarafı CAPI kopyası da gitmez). Giriş yapmış kullanıcı etkilenmez.
- **Kapatma:** `add_filter( 'eru_htf_checkout_login_gate_events_enabled', '__return_false' );`
- **Ne zaman KULLANMAYIN:** Misafir ödeme açıksa ya da GTM4WP/PixelYourSite kullanmıyorsanız gerek yoktur (kendiliğinden pasif kalır).

### 5. Category Rewrite Flush (`eru-htf-category-rewrite-flush.php`)

- **Sorun:** Rank Math ürün kategorisi kaydedilince rewrite kurallarını yeniler; bu sırada `wp_cache_flush()` çağırır. Redis'te seçmeli flush tanımlı değilse bu ham `FLUSHDB`'dir: tüm object cache silinir, ardından soğuk cache fırtınası gelir.
- **Ne yapar:** Kategorinin rewrite kuralı yalnızca slug'ına ve ebeveynine bağlıdır. Ad, açıklama, görsel gibi düzenlemelerde Rank Math'in flush'ını başlatmaz. Emin olamadığı her durumda (slug/ebeveyn değişti, önceki hal yakalanamadı) Rank Math'in kendi callback'ini aynen çağırır. Oluşturma/silme kancalarına karışmaz.
- **Kapatma:** `add_filter( 'eru_htf_category_rewrite_flush_enabled', '__return_false' );`
- **Ne zaman KULLANMAYIN:** Rank Math WooCommerce modülünü kullanmıyorsanız etkisi yoktur. Kategori URL'lerini slug/ebeveyn dışında bir şeye bağlayan özel kod varsa kullanmayın.

### 6. Cloudflare Purge Frontend (`eru-htf-cloudflare-purge-frontend.php`)

- **Sorun:** Bir ürün cache'i temizlendiğinde (örn. ödemede stok düşünce) WP Rocket senkron olarak Cloudflare API'sine iki istek atar; müşteri ödeme ve teşekkür sayfasında bu ağ çağrılarını bekler.
- **Ne yapar:** Ön yüz ve REST isteklerinde `api.cloudflare.com`'a giden HTTP isteğini `pre_http_request` ile keser (boğaz noktası), ayrıca WP Rocket'ın Cloudflare abonelerini ve Cloudflare eklentisinin `transition_post_status` callback'ini ön yüzde kancadan çıkarır. Admin (admin-ajax dahil), cron, WP-CLI ve `edit_posts` yetkisine sahip giriş yapmış kullanıcıların REST kaydetmeleri (blok editör, WooCommerce yönetimi, eklenti REST kayıtları) muaftır, purge aynen çalışır; diğer REST isteklerinde ağ kesilir. Kancadan çıkarma katmanları (2 ve 3) kullanıcı henüz bilinmediğinden her REST URL'sini atlar.
- **Kapatma:** `add_filter( 'eru_htf_cloudflare_purge_frontend_enabled', '__return_false' );` Bu filtre bir mu-plugin içinde eklenmelidir (3. katman `plugins_loaded` sırasında okur; tema veya normal eklenti çok geç yüklenir).
- **Ne zaman KULLANMAYIN:** Ön yüzden (sipariş, entegrasyon) değişen sayfaların edge'de bayat kalmasına tahammülünüz yoksa ya da edge TTL'iniz uzunsa kullanmayın.

### 7. Plugin Diet (`eru-htf-plugin-diet.php`)

- **Sorun:** Yalnız admin/cron/CLI'da iş yapan eklentiler (içe/dışa aktarma, çeviri editörü, lisans yöneticisi, rapor, statik feed üretici) yine de her ön yüz isteğinde yüklenir.
- **Ne yapar:** Verdiğiniz eklentileri yalnızca ön yüz isteklerinde `active_plugins` okumasından çıkarır; dosyalara dokunmaz, kalıcı değildir. Bir yazma muhafızı, ön yüzde listeyi geri yazan kodun kısaltılmış listeyi veritabanına kalıcılaştırmasını engeller. Liste **varsayılan olarak boştur**; kendi kuralınızı verirsiniz:

```php
add_filter( 'eru_htf_plugin_diet_rules', static function ( $rules ) {
	return array_merge( $rules, array(
		'example-importer/example-importer.php',
		'example-translation-editor/example-translation-editor.php',
		'example-license-manager/example-license-manager.php',
	) );
} );
```

  Cron, WP-CLI, admin, REST, `xmlrpc.php`, `wp-login.php` ve import/export tetikleme parametreleri her zaman muaftır. İsteğe bağlı: sık çağrılan izleme uçları (PixelYourSite, GTM4WP) için REST/admin-ajax muafiyeti kalkar; `eru_htf_plugin_diet_tracking_endpoints` ve `eru_htf_plugin_diet_tracking_ajax_actions` filtreleriyle değiştirilebilir.
- **Kapatma:** `add_filter( 'eru_htf_plugin_diet_enabled', '__return_false' );`
- **Ne zaman KULLANMAYIN:** Ön yüzde kanca, kısa kod, REST rotası, sorgu/sıralama filtresi olan hiçbir eklentiyi listeye koymayın. Emin değilseniz önce ölçün: kazanç eklenti başına birkaç ila yüz ms'dir.

### 8. IndexNow Non-Blocking (`eru-htf-indexnow-nonblocking.php`)

- **Sorun:** Rank Math Instant Indexing, `api.indexnow.org`'a `wp_remote_post()` ile gider ve yanıtı bekler; dış servis yavaşlarsa PHP worker'ı o süre boyunca tutulur.
- **Ne yapar:** Yalnız IndexNow adresine giden isteklere `blocking => false` ve kısa bir timeout (varsayılan 2 sn) ekler.
- **Kapatma:** `add_filter( 'eru_htf_indexnow_nonblocking_enabled', '__return_false' );` Yalnız timeout kalsın isterseniz: `add_filter( 'eru_htf_indexnow_nonblocking_blocking', '__return_false' );` Timeout: `define( 'ERU_HTF_INDEXNOW_TIMEOUT', 3 );`
- **Ne zaman KULLANMAYIN:** Eklentinin "History" ekranını gönderim denetimi için kullanıyorsanız (yanıt görülemez); o durumda yalnız timeout'u bırakın.

### 9. Order Status Webhook Topics (`eru-htf-order-status-webhook-topics.php`)

- **Sorun:** `order.updated` konusu siparişin her kaydında, meta güncellemeleri dahil, tetiklenir; tek sipariş için onlarca webhook gelebilir.
- **Ne yapar:** `order.processing`, `order.completed`, `order.cancelled`, `order.refunded` konularını ekler. Bunlar WooCommerce durum kancalarına bağlıdır ve her durum geçişinde tam bir kez tetiklenir. Webhook ekranında ve REST API'de seçilebilir.
- **Kapatma:** `add_filter( 'eru_htf_order_status_webhook_topics_enabled', '__return_false' );`
- **Ne zaman KULLANMAYIN:** Alıcı sistem `order.updated` ile ara durumları veya meta değişimini de bekliyorsa; mevcut webhook'larınız etkilenmez, yeni konuları kullanmak isteğe bağlıdır.

### 10. PixelYourSite Fragment Fix (`eru-htf-pixelyoursite-fragment-fix.php`)

- **Sorun:** PixelYourSite Pro'nun `#pys_ajax_events` sepet fragment'i `pys_generate_token()` çağırır; sitenin yüklediği minify edilmiş `public.min.js` içinde bu fonksiyon global değildir. Paylaşılan olay kimliği boşsa `ReferenceError` oluşur ve script bloğunun geri kalanı (olay kimliği senkronu, pixel tetikleme) çalışmaz.
- **Ne yapar:** Fragment HTML'indeki çağrıyı sunucuda üretilmiş bir token ile değiştirir. Eklenti dosyalarına dokunmaz; çağrı kalıbı bulunmazsa fragment'e hiç dokunmaz.
- **Kapatma:** `add_filter( 'eru_htf_pixelyoursite_fragment_fix_enabled', '__return_false' );`
- **Ne zaman KULLANMAYIN:** PixelYourSite bu hatayı kendi sürümünde giderdiyse veya eklentiyi kullanmıyorsanız gerek yoktur (kendiliğinden pasif kalır).

### 11. Redis Flush Tracer (`eru-htf-redis-flush-tracer.php`)

- **Sorun:** Redis slowlog'unda `FLUSHDB` görürsünüz ama kimin tetiklediğini göremezsiniz.
- **Ne yapar:** Redis Object Cache drop-in'inin flush bitince çalıştırdığı `redis_object_cache_flush` kancasını dinler; bağlamı (admin/cron/ajax/rest/wp-cli/ön yüz), istek yolunu (sorgu dizesi hiç yazılmaz), kullanıcı kimliğini ve kısaltılmış backtrace'i bir kütüğe yazar. IP adresi yazılmaz. Hiçbir davranışı değiştirmez. Varsayılan kütük belge kökünün bir üstündedir (`eru-htf-redis-flush.log`); `ERU_HTF_REDIS_FLUSH_TRACER_LOG` ile değiştirilir. Dosya 5 MB'ı (`ERU_HTF_REDIS_FLUSH_TRACER_MAX`) geçince yazma durur. Yazılamıyorsa sessizce çıkar.
- **Kapatma:** `add_filter( 'eru_htf_redis_flush_tracer_enabled', '__return_false' );`
- **Ne zaman KULLANMAYIN:** Teşhis aracıdır; kaynağı bulduktan sonra dosyayı ve kütüğü silin. Redis Object Cache kullanmıyorsanız gerek yok.

---

## Gereksinimler

WordPress 6.5+, PHP 8.0+. WooCommerce gerekmez: WooCommerce'e veya üçüncü taraf bir eklentiye (Redis Object Cache, WP Rocket, Rank Math, AutomateWoo, PixelYourSite, GTM4WP…) bağlı dosya, o eklenti yoksa sessizce hiçbir şey yapmaz.

## English summary

Eleven standalone mu-plugin files that fix common performance and stability problems on busy WooCommerce stores: a shorter Action Scheduler pause, O(1) AutomateWoo cache flushes, a cookie-aware cart-fragments loader, no phantom `begin_checkout` events behind a checkout login wall, conditional category rewrite flushes, no synchronous Cloudflare purges on the front end, a plugin diet for admin-only plugins (rules are empty by default and supplied via the `eru_htf_plugin_diet_rules` filter), non-blocking IndexNow pings, status-based order webhook topics, a PixelYourSite fragment fix, and a Redis flush tracer. Do not upload it via Plugins → Add New: unzip it and copy only the `.php` files you need into the top level of `wp-content/mu-plugins/`. Every fix can be disabled with the `eru_htf_<fix>_enabled` filter, and every file does nothing if the plugin it targets is absent.

## Sürüm notları

Bkz. [CHANGELOG.md](CHANGELOG.md).

## Lisans

GPL-2.0-or-later. Geliştirici: [Erusoft](https://erusoft.com). Diğer Erusoft eklentileri: https://wordpress.erusoft.com
