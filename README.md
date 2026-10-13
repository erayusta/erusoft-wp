# Erusoft WP

WordPress ve WooCommerce için küçük, tek işe odaklı, GPL lisanslı açık kaynak eklentiler. Katalog ve indirmeler: **https://wordpress.erusoft.com**

Bu depo bir monorepo: her eklenti `plugins/` (veya tek dosyalık düzeltmeler için `mu-plugins/`) altında kendi klasöründe yaşar. `catalog.json` kataloğun tek kaynağıdır; site ve aşağıdaki tablo buradan üretilir.

## Eklentiler

<!-- catalog:start -->
| Eklenti | Kategori | Durum |
|---|---|---|
| [Order Invoice Upload for WooCommerce](https://wordpress.erusoft.com/order-invoice-upload-for-woocommerce/) — Sipariş ekranından e-fatura PDF'i yükleyin; müşteri güvenli bağlantıyla indirsin. | Fatura & Muhasebe | Stabil |
| [Order JSON Export for WooCommerce](https://wordpress.erusoft.com/order-json-export-for-woocommerce/) — Siparişleri belgelenmiş şemayla JSON olarak dışa aktarır: toplu işlem, tarih aralığı ve yetkili REST uç noktası. | Fatura & Muhasebe | Stabil |
| [Config SMTP Mailer for WordPress](https://wordpress.erusoft.com/config-smtp-mailer-for-wordpress/) — SMTP ayarlarını wp-config sabitlerinden okuyan hafif e-posta gönderici. | Geliştirici Araçları | Stabil |
| [Store Closure Switch for WooCommerce](https://wordpress.erusoft.com/store-closure-switch-for-woocommerce/) — Mağazayı tek anahtarla satışa kapatır: sepet ve ödeme 503 + Retry-After döner, yönetim açık kalır. | Geliştirici Araçları | Stabil |
| [Welcome Note for WordPress](https://wordpress.erusoft.com/welcome-note-for-wordpress/) — Rollere yönetim panelinde, isterseniz ziyaretçilere sitede kapatılabilir not gösterir. | Geliştirici Araçları | Stabil |
| [Coupon Brute-Force Guard for WooCommerce](https://wordpress.erusoft.com/coupon-brute-force-guard-for-woocommerce/) — Kupon deneme-yanılma saldırılarını IP bazlı hız sınırıyla durdurur. | Güvenlik | Stabil |
| [Order Blacklist for WooCommerce](https://wordpress.erusoft.com/order-blacklist-for-woocommerce/) — Sipariş verenleri e-posta, telefon, IP veya adrese göre kara listeye alır. | Güvenlik | Stabil |
| [Order Risk Score for WooCommerce](https://wordpress.erusoft.com/order-risk-score-for-woocommerce/) — Siparişlere ayarlanabilir sinyallerle risk puanı verir ve listede rozet gösterir. | Güvenlik | Stabil |
| [AI Post Generator for WordPress](https://wordpress.erusoft.com/ai-post-generator-for-wordpress/) — OpenAI, Anthropic veya Perplexity ile kendi anahtarınızla yazı taslağı üretir. | İçerik & AI | Stabil |
| [Content Automation for WordPress](https://wordpress.erusoft.com/content-automation-for-wordpress/) — Konu kuyruğunu zamanlanmış olarak yapay zekâ taslaklarına dönüştürür. | İçerik & AI | Stabil |
| [FAQ Accordion for WordPress](https://wordpress.erusoft.com/faq-accordion-for-wordpress/) — Sürüklenebilir sıkça sorulan sorular ve erişilebilir akordeon kısa kodu. | İçerik & AI | Stabil |
| [Heading Pagination for WordPress](https://wordpress.erusoft.com/heading-pagination-for-wordpress/) — Uzun yazıları H2 başlıklarına göre sayfalara böler. | İçerik & AI | Stabil |
| [Notice Boxes for WordPress](https://wordpress.erusoft.com/notice-boxes-for-wordpress/) — Bilgi, uyarı, başarı ve hata kutuları; kısa kod ve blok olarak. | İçerik & AI | Stabil |
| [Click & Collect for WooCommerce](https://wordpress.erusoft.com/click-collect-for-woocommerce/) — Mağazadan teslim (gel-al) kargo yöntemi ve teslim noktası seçimi sunar. | Kargo & Teslimat | Stabil |
| [Order SMS & Delivery Codes for İleti Merkezi](https://wordpress.erusoft.com/order-sms-delivery-codes-for-ileti-merkezi/) — Sipariş durumuna göre SMS gönderir ve teslimat kodu doğrular. | Kargo & Teslimat | Stabil |
| [Same-Day Delivery for WooCommerce](https://wordpress.erusoft.com/same-day-delivery-for-woocommerce/) — Aynı gün teslimat uygunluğunu ürün, ilçe ve kesim saatine göre belirler. | Kargo & Teslimat | Stabil |
| [Turkish Shipping Carriers for WooCommerce](https://wordpress.erusoft.com/turkish-shipping-carriers-for-woocommerce/) — Türkiye kargo firmaları için firma bazlı kargo ücretleri ve siparişte takip bağlantısı. | Kargo & Teslimat | Stabil |
| [Abandoned Checkout Call List for WooCommerce](https://wordpress.erusoft.com/abandoned-checkout-call-list-for-woocommerce/) — Yarım kalan ödemeleri yakalar ve arama listesi olarak sunar. | Müşteri & B2B | Stabil |
| [B2B Customer Pricing for WooCommerce](https://wordpress.erusoft.com/b2b-customer-pricing-for-woocommerce/) — Müşteriye ve müşteri grubuna özel fiyat ve iskonto kuralları. | Müşteri & B2B | Stabil |
| [Email Domain Roles for WordPress](https://wordpress.erusoft.com/email-domain-roles-for-wordpress/) — Kayıt olan kullanıcıya e-posta alan adına göre otomatik rol atar. | Müşteri & B2B | Stabil |
| [Price Lists for WooCommerce](https://wordpress.erusoft.com/price-lists-for-woocommerce/) — Fiyat listeleri tanımlayıp role veya kişiye atayın. | Müşteri & B2B | Stabil |
| [Role-Based Prices for WooCommerce](https://wordpress.erusoft.com/role-based-prices-for-woocommerce/) — Kullanıcı rollerine özel ürün fiyatları tanımlayın. | Müşteri & B2B | Stabil |
| [Store Locator for WordPress](https://wordpress.erusoft.com/store-locator-for-wordpress/) — Şube veya yetkili servis noktalarını harita ve liste olarak gösterir. | Müşteri & B2B | Stabil |
| [Wholesale Inquiry Box for WooCommerce](https://wordpress.erusoft.com/wholesale-inquiry-box-for-woocommerce/) — Ürün sayfasına toplu alım teklif formu ekler. | Müşteri & B2B | Stabil |
| [Edenred for WooCommerce](https://wordpress.erusoft.com/edenred-for-woocommerce/) — Edenred Ticket Restaurant ile WooCommerce'te ödeme alın. | Ödeme | Stabil |
| [MetropolCard for WooCommerce](https://wordpress.erusoft.com/metropolcard-for-woocommerce/) — MetropolCard ile ödeme sayfasından ayrılmadan SMS kodlu ödeme alın. | Ödeme | Stabil |
| [Pluxee for WooCommerce](https://wordpress.erusoft.com/pluxee-for-woocommerce/) — Pluxee yemek kartıyla WooCommerce'te ödeme alın. | Ödeme | Stabil |
| [Automatic Discounts for WooCommerce](https://wordpress.erusoft.com/automatic-discounts-for-woocommerce/) — Kupon gerektirmeyen ürün, kategori ve sepet bazlı otomatik indirimler. | Pazarlama & Kampanya | Stabil |
| [Bought Together Discounts for WooCommerce](https://wordpress.erusoft.com/bought-together-discounts-for-woocommerce/) — Birlikte alınan ürünlere sepette otomatik indirim uygular. | Pazarlama & Kampanya | Stabil |
| [Brand Coupon Banners for WooCommerce](https://wordpress.erusoft.com/brand-coupon-banners-for-woocommerce/) — Marka veya kategoriye bağlı kuponları tarihli banner olarak sergiler. | Pazarlama & Kampanya | Stabil |
| [Campaign Pages for WordPress](https://wordpress.erusoft.com/campaign-pages-for-wordpress/) — Kampanyaları arşiv sayfası ve aktif/yaklaşan/bitmiş durumlarıyla yayınlar. | Pazarlama & Kampanya | Stabil |
| [Cart Campaigns for WooCommerce](https://wordpress.erusoft.com/cart-campaigns-for-woocommerce/) — Sepet tutarı kademelerine bağlı kampanyalar ve ilerleme mesajı. | Pazarlama & Kampanya | Stabil |
| [Exit-Intent Coupons for WooCommerce](https://wordpress.erusoft.com/exit-intent-coupons-for-woocommerce/) — Sayfadan ayrılmak üzere olan ziyaretçiye sabit veya kişiye özel kupon sunar. | Pazarlama & Kampanya | Stabil |
| [Gift Line Guard for WooCommerce](https://wordpress.erusoft.com/gift-line-guard-for-woocommerce/) — Hediye satırlarının adedini kilitler ve fiyatını sıfırda tutar. | Pazarlama & Kampanya | Stabil |
| [Gift Product Rules for WooCommerce](https://wordpress.erusoft.com/gift-product-rules-for-woocommerce/) — Koşula bağlı hediye ürünü sepete otomatik ekler. | Pazarlama & Kampanya | Stabil |
| [SplitShare Affiliates for WooCommerce](https://wordpress.erusoft.com/splitshare-affiliates-for-woocommerce/) — Ortaklık programı: başvuru, kupon ve bağlantı takibi, komisyon ve ödeme defteri. | Pazarlama & Kampanya | Stabil |
| [Akakçe XML Feed for WooCommerce](https://wordpress.erusoft.com/akakce-xml-feed-for-woocommerce/) — Ürünlerinizi Akakçe'nin istediği XML biçiminde otomatik yayınlar. | Pazaryeri | Stabil |
| [Hepsiburada Integration for WooCommerce](https://wordpress.erusoft.com/hepsiburada-integration-for-woocommerce/) — Hepsiburada siparişlerini WooCommerce'e aktarır (eski sürüm; Marketplace Orders'a geçin). | Pazaryeri | Stabil |
| [Trendyol Integration for WooCommerce](https://wordpress.erusoft.com/trendyol-integration-for-woocommerce/) — Trendyol siparişlerini WooCommerce'e aktarır (eski sürüm; Marketplace Orders'a geçin). | Pazaryeri | Stabil |
| [Trendyol Reviews for WooCommerce](https://wordpress.erusoft.com/trendyol-reviews-for-woocommerce/) — Trendyol satıcı mağazanızın ürün yorumlarını eşleşen WooCommerce ürünlerine aktarır. | Pazaryeri | Stabil |
| [High-Traffic Fixes for WooCommerce](https://wordpress.erusoft.com/high-traffic-fixes-for-woocommerce/) — Yüksek trafikli WooCommerce mağazaları için on bir performans ve kararlılık düzeltmesi. | Performans | Stabil |
| [Local WebP Generator for WordPress](https://wordpress.erusoft.com/local-webp-generator-for-wordpress/) — Yüklenen görselleri sunucuda WebP'ye çevirir; orijinallere dokunmaz. | Performans | Stabil |
| [REST Response Cache for WooCommerce](https://wordpress.erusoft.com/rest-response-cache-for-woocommerce/) — İzin listesindeki herkese açık REST GET uçlarının yanıtlarını önbellekler. | Performans | Stabil |
| [Sort Order Cache Purge for WooCommerce](https://wordpress.erusoft.com/sort-order-cache-purge-for-woocommerce/) — Ürün sıralaması kaydedilince sayfa önbelleğini temizler. | Performans | Stabil |
| [Shipping Method Report for WooCommerce](https://wordpress.erusoft.com/shipping-method-report-for-woocommerce/) — Tarih aralığında kargo yöntemi ve teslim noktası başına sipariş raporu; CSV dışa aktarma. | Raporlama & Analitik | Stabil |
| [Category Archive H1 for WordPress](https://wordpress.erusoft.com/category-archive-h1-for-wordpress/) — Kategori arşivlerine düzenlenebilir H1 başlığı alanı ekler. | SEO | Stabil |
| [Category Base Rewrite Guard for WooCommerce](https://wordpress.erusoft.com/category-base-rewrite-guard-for-woocommerce/) — Tabansız kategori yönlendirme kuralları eksik kaydedilirse yeniden üretir, aksi halde hiçbir şey yapmaz. | SEO | Stabil |
| [Primary Category for WooCommerce](https://wordpress.erusoft.com/primary-category-for-woocommerce/) — Ürüne birincil kategori seçtirir; adres ve kırıntıda kullanılır. | SEO | Stabil |
| [SEO Meta REST Fields for WordPress](https://wordpress.erusoft.com/seo-meta-rest-fields-for-wordpress/) — SEO başlık, açıklama ve anahtar kelime alanlarını REST API'ye açar. | SEO | Stabil |
| [Catalog Mode & WhatsApp Orders for WooCommerce](https://wordpress.erusoft.com/catalog-mode-whatsapp-orders-for-woocommerce/) — Katalog modu ve ürün bilgisiyle dolu WhatsApp sipariş düğmesi. | Sepet & Ödeme Akışı | Stabil |
| [High-Value Cart Offer for WooCommerce](https://wordpress.erusoft.com/high-value-cart-offer-for-woocommerce/) — Yüksek tutarlı sepetlerde sepet özetiyle WhatsApp sohbeti açan teklif kutusu. | Sepet & Ödeme Akışı | Stabil |
| [Payment Method Discounts for WooCommerce](https://wordpress.erusoft.com/payment-method-discounts-for-woocommerce/) — Ödeme yöntemine göre indirim veya ek ücret uygular. | Sepet & Ödeme Akışı | Stabil |
| [Payment Restrictions by Category for WooCommerce](https://wordpress.erusoft.com/payment-restrictions-by-category-for-woocommerce/) — Seçili kategorilerdeki ürünlerde belirli ödeme yöntemlerini kapatır. | Sepet & Ödeme Akışı | Stabil |
| [Purchase Limits for WooCommerce](https://wordpress.erusoft.com/purchase-limits-for-woocommerce/) — Ürün, kategori ve sipariş bazında adet limiti ile müşteri başına dönemlik limit koyar. | Sepet & Ödeme Akışı | Stabil |
| [Turkish Address Fields for WooCommerce](https://wordpress.erusoft.com/turkish-address-fields-for-woocommerce/) — Klasik ödeme sayfasında il, ilçe, mahalle ve sokak seçimi; paketli adres verisi ve kapı numarası kontrolü. | Sepet & Ödeme Akışı | Stabil |
| [KİNETİK](https://wordpress.erusoft.com/kinetik/) — Türkiye için açık kaynak WooCommerce spor giyim teması: adres seçimi, beden seçici, ücretsiz kargo çubuğu. | Tema | Stabil |
| [Narin](https://wordpress.erusoft.com/narin/) — Küçük mağazalar için zarif WooCommerce teması: kemerli giriş, filtre çekmecesi, bölgeden okunan ücretsiz kargo çubuğu. | Tema | Stabil |
| [Ufuk](https://wordpress.erusoft.com/ufuk/) — Kurumsal siteler için sade ve hızlı tema: ana sayfa bölümleri Özelleştirici'den, açık/koyu mod, spam korumalı form. | Tema | Stabil |
| [Voltaj](https://wordpress.erusoft.com/voltaj/) — Teknik malzeme mağazaları için WooCommerce teması: kategori katalog paneli, özellik öncelikli kartlar, teklif formu. | Tema | Stabil |
| [Brand Product Sorting for WooCommerce](https://wordpress.erusoft.com/brand-product-sorting-for-woocommerce/) — Marka arşivindeki ürünleri sürükle-bırak ile sıralayın. | Ürün & Katalog | Stabil |
| [Bulk Product Visibility for WooCommerce](https://wordpress.erusoft.com/bulk-product-visibility-for-woocommerce/) — Ürünlerin görünürlüğünü kategoriye göre toplu veya tek tek yönetin. | Ürün & Katalog | Stabil |
| [Bundle Stock Sync for WooCommerce](https://wordpress.erusoft.com/bundle-stock-sync-for-woocommerce/) — Paket ürünlerin stoğunu bileşen SKU ve adetlerinden hesaplar, satış ve iadelerle senkron tutar. | Ürün & Katalog | Stabil |
| [Category Banner Slider for WooCommerce](https://wordpress.erusoft.com/category-banner-slider-for-woocommerce/) — Kategori sayfalarına cihaza göre ayrı görselli slider ekler. | Ürün & Katalog | Stabil |
| [Coming Soon Products for WooCommerce](https://wordpress.erusoft.com/coming-soon-products-for-woocommerce/) — Ürünü 'yakında' olarak işaretler; fiyat yerine mesaj gösterir ve satışı kapatır. | Ürün & Katalog | Stabil |
| [Hide Categories for WooCommerce](https://wordpress.erusoft.com/hide-categories-for-woocommerce/) — Seçili kategorileri listelerden, bileşenlerden ve aramadan gizler. | Ürün & Katalog | Stabil |
| [Out-of-Stock Last for WooCommerce](https://wordpress.erusoft.com/out-of-stock-last-for-woocommerce/) — Stokta olmayan ürünleri arşivlerde listenin sonuna taşır. | Ürün & Katalog | Stabil |
| [PDF Product Catalog for WooCommerce](https://wordpress.erusoft.com/pdf-product-catalog-for-woocommerce/) — Kategorilerden kapaklı, yazdırmaya hazır ürün kataloğu üretir; PDF olarak kaydedilir. | Ürün & Katalog | Stabil |
| [Product Ribbons for WooCommerce](https://wordpress.erusoft.com/product-ribbons-for-woocommerce/) — Ürün görsellerine kategori veya ürün bazlı renkli şerit/etiket ekler. | Ürün & Katalog | Stabil |
| [Product Status Notifications for WooCommerce](https://wordpress.erusoft.com/product-status-notifications-for-woocommerce/) — Ürün durumu, stok veya fiyat değişince e-posta bildirimi gönderir. | Ürün & Katalog | Stabil |
| [Smart Product Search for WooCommerce](https://wordpress.erusoft.com/smart-product-search-for-woocommerce/) — Türkçe karakterden bağımsız ürün arama ve canlı öneri listesi. | Ürün & Katalog | Stabil |
| [Supplier XML Importer for WooCommerce](https://wordpress.erusoft.com/supplier-xml-importer-for-woocommerce/) — Tedarikçi XML beslemelerinden ürün ve stok içe aktarır; eşleme, fiyat kuralları ve kuru çalıştırma. | Ürün & Katalog | Stabil |
| [USD Pricing for WooCommerce](https://wordpress.erusoft.com/usd-pricing-for-woocommerce/) — Ürünleri USD fiyatlandırıp TL fiyatı güncel kurla otomatik hesaplar. | Ürün & Katalog | Stabil |
| [Dopigo Integration for WooCommerce](https://wordpress.erusoft.com/dopigo-integration-for-woocommerce/) — Dopigo ile stok, fiyat ve sipariş senkronu kurar. | Entegrasyon (ERP/CRM) | Yakında |
| [e-Devlet Document Verification for WordPress](https://wordpress.erusoft.com/e-devlet-document-verification-for-wordpress/) — Yüklenen resmî belgeleri e-Devlet doğrulamasıyla kontrol eder. | Güvenlik | Yakında |
| [Site Content Generator](https://wordpress.erusoft.com/site-content-generator/) — Ayarlanabilir konu ve sağlayıcıyla site içeriği üretir. | İçerik & AI | Yakında |
| [Site Manager for WordPress](https://wordpress.erusoft.com/site-manager-for-wordpress/) — Slider, iletişim bilgileri, şema ve llms.txt için modüler site yönetimi. | İçerik & AI | Yakında |
| [Yurtiçi Kargo Integration for WooCommerce](https://wordpress.erusoft.com/yurtici-kargo-integration-for-woocommerce/) — Yurtiçi Kargo gönderi oluşturma, etiket ve teslimat takibini otomatikleştirir. | Kargo & Teslimat | Yakında |
| [Sales Team Commissions for WooCommerce](https://wordpress.erusoft.com/sales-team-commissions-for-woocommerce/) — Satış temsilcisi hiyerarşisi, komisyon kuralları ve ödeme akışı. | Müşteri & B2B | Yakında |
| [Bank Transfer Auto-Approval for Garanti BBVA](https://wordpress.erusoft.com/bank-transfer-auto-approval-for-garanti-bbva/) — Garanti BBVA hesap hareketlerinden havale ödemelerini otomatik onaylar. | Ödeme | Yakında |
| [Multi Virtual POS for WooCommerce](https://wordpress.erusoft.com/multi-virtual-pos-for-woocommerce/) — Birden çok bankanın sanal POS'unu kural motoruyla yöneten ödeme eklentisi. | Ödeme | Yakında |
| [VakıfBank Vinov Integration for WooCommerce](https://wordpress.erusoft.com/vakifbank-vinov-integration-for-woocommerce/) — VakıfBank Vinov ile OTP'li ödeme ve talimat yönetimi. | Ödeme | Yakında |
| [Campaign Engine for WooCommerce](https://wordpress.erusoft.com/campaign-engine-for-woocommerce/) — Koşul ve aksiyon tabanlı tam kampanya motoru ve sihirbazı. | Pazarlama & Kampanya | Yakında |
| [Checkout Consent Sync for İYS](https://wordpress.erusoft.com/checkout-consent-sync-for-iys/) — Ödeme sayfasında iletişim izni alır ve İYS ile senkronlar. | Pazarlama & Kampanya | Yakında |
| [Consent Manager for İYS](https://wordpress.erusoft.com/consent-manager-for-iys/) — İYS izin deposu, denetim kaydı ve hız sınırlı senkron kuyruğu. | Pazarlama & Kampanya | Yakında |
| [Conversion Boosters for WooCommerce](https://wordpress.erusoft.com/conversion-boosters-for-woocommerce/) — Ücretsiz kargo çubuğu, hoş geldin kuponu ve geri sayımlı kupon gibi dönüşüm araçları. | Pazarlama & Kampanya | Yakında |
| [Growth Automations for WooCommerce](https://wordpress.erusoft.com/growth-automations-for-woocommerce/) — Havale hatırlatma, terk edilen sepet, geri kazanım ve yorum isteği otomasyonları. | Pazarlama & Kampanya | Yakında |
| [Marketplace Orders for WooCommerce](https://wordpress.erusoft.com/marketplace-orders-for-woocommerce/) — Pazaryeri siparişlerini WooCommerce'e aktarır; stok ve fiyatı senkronlar. | Pazaryeri | Yakında |
| [Product Push for Trendyol](https://wordpress.erusoft.com/product-push-for-trendyol/) — WooCommerce ürünlerini Trendyol'a toplu gönderir. | Pazaryeri | Yakında |
| [Server-Side Refunds for GA4](https://wordpress.erusoft.com/server-side-refunds-for-ga4/) — İade ve iptalleri GA4'e sunucu tarafında bildirir. | Raporlama & Analitik | Yakında |
| [Server-Side Refunds for Matomo](https://wordpress.erusoft.com/server-side-refunds-for-matomo/) — İade ve iptalleri Matomo'ya sunucu tarafında bildirir. | Raporlama & Analitik | Yakında |
| [Custom Order Statuses for WooCommerce](https://wordpress.erusoft.com/custom-order-statuses-for-woocommerce/) — Panelden sipariş durumu ekleyin; renk, sıra, e-posta ve toplu işlem yönetin. | Sepet & Ödeme Akışı | Yakında |
| [Device Trade-In for WooCommerce](https://wordpress.erusoft.com/device-trade-in-for-woocommerce/) — Takas edilen ürün için yapılandırılabilir özelliklere göre indirim değeri hesaplar. | Sepet & Ödeme Akışı | Yakında |
| [Product Add-On Services for WooCommerce](https://wordpress.erusoft.com/product-add-on-services-for-woocommerce/) — Ürün veya kategoriye bağlı ek hizmet grupları (montaj, garanti vb.) tanımlayın. | Ürün & Katalog | Yakında |
| [T-Soft Migrator for WooCommerce](https://wordpress.erusoft.com/t-soft-migrator-for-woocommerce/) — T-Soft mağaza verisini WooCommerce'e taşıyan genel içe aktarma aracı. | Ürün & Katalog | Yakında |
<!-- catalog:end -->

## Kurulum

1. Eklentinin ZIP dosyasını [wordpress.erusoft.com](https://wordpress.erusoft.com) üzerinden indirin ya da ilgili klasörü bu depodan kopyalayın.
2. WordPress yönetiminde Eklentiler → Yeni ekle → Eklenti yükle ile ZIP'i yükleyin (mu-plugin'ler için dosyayı `wp-content/mu-plugins/` altına koyun).
3. Etkinleştirin ve eklentinin README'sindeki ayarları izleyin.

Gereksinimler: WordPress 6.5+, PHP 8.0+; WooCommerce eklentileri için WooCommerce 9.0+.

## Katkı

Hata bildirimi ve çekme istekleri memnuniyetle karşılanır. Kurallar için [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md) dosyasına bakın.

Siteyi yerelde üretmek için: `npm install`, `npm test`, `npm run build` (çıktı `site/dist/`).

## Lisans

GPL-2.0-or-later. Bkz. [LICENSE](LICENSE).

## Diğer projeler

- [KİNETİK](https://github.com/erayusta/kinetik)
- [erusoft.com](https://erusoft.com)

## English summary

Erusoft WP is an open-source monorepo of focused WordPress and WooCommerce plugins (GPL-2.0-or-later). Browse the catalogue and download ZIPs at https://wordpress.erusoft.com. See `docs/CONTRIBUTING.md` to contribute.
