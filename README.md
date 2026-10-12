# Erusoft WP

WordPress ve WooCommerce için küçük, tek işe odaklı, GPL lisanslı açık kaynak eklentiler. Katalog ve indirmeler: **https://wordpress.erusoft.com**

Bu depo bir monorepo: her eklenti `plugins/` (veya tek dosyalık düzeltmeler için `mu-plugins/`) altında kendi klasöründe yaşar. `catalog.json` kataloğun tek kaynağıdır; site ve aşağıdaki tablo buradan üretilir.

## Eklentiler

<!-- catalog:start -->
| Eklenti | Kategori | Durum |
|---|---|---|
| [Order Invoice Upload for WooCommerce](https://wordpress.erusoft.com/order-invoice-upload-for-woocommerce/) — Sipariş ekranından e-fatura PDF'i yükleyin; müşteri güvenli bağlantıyla indirsin. | Fatura & Muhasebe | Stabil |
| [Config SMTP Mailer for WordPress](https://wordpress.erusoft.com/config-smtp-mailer-for-wordpress/) — SMTP ayarlarını wp-config sabitlerinden okuyan hafif e-posta gönderici. | Geliştirici Araçları | Stabil |
| [Store Closure Switch for WooCommerce](https://wordpress.erusoft.com/store-closure-switch-for-woocommerce/) — Mağazayı tek anahtarla satışa kapatır: sepet ve ödeme 503 + Retry-After döner, yönetim açık kalır. | Geliştirici Araçları | Stabil |
| [Coupon Brute-Force Guard for WooCommerce](https://wordpress.erusoft.com/coupon-brute-force-guard-for-woocommerce/) — Kupon deneme-yanılma saldırılarını IP bazlı hız sınırıyla durdurur. | Güvenlik | Stabil |
| [Order Risk Score for WooCommerce](https://wordpress.erusoft.com/order-risk-score-for-woocommerce/) — Siparişlere ayarlanabilir sinyallerle risk puanı verir ve listede rozet gösterir. | Güvenlik | Stabil |
| [Email Domain Roles for WordPress](https://wordpress.erusoft.com/email-domain-roles-for-wordpress/) — Kayıt olan kullanıcıya e-posta alan adına göre otomatik rol atar. | Müşteri & B2B | Stabil |
| [Store Locator for WordPress](https://wordpress.erusoft.com/store-locator-for-wordpress/) — Şube veya yetkili servis noktalarını harita ve liste olarak gösterir. | Müşteri & B2B | Stabil |
| [Edenred for WooCommerce](https://wordpress.erusoft.com/edenred-for-woocommerce/) — Edenred Ticket Restaurant ile WooCommerce'te ödeme alın. | Ödeme | Stabil |
| [MetropolCard for WooCommerce](https://wordpress.erusoft.com/metropolcard-for-woocommerce/) — MetropolCard ile ödeme sayfasından ayrılmadan SMS kodlu ödeme alın. | Ödeme | Stabil |
| [Pluxee for WooCommerce](https://wordpress.erusoft.com/pluxee-for-woocommerce/) — Pluxee yemek kartıyla WooCommerce'te ödeme alın. | Ödeme | Stabil |
| [Brand Coupon Banners for WooCommerce](https://wordpress.erusoft.com/brand-coupon-banners-for-woocommerce/) — Marka veya kategoriye bağlı kuponları tarihli banner olarak sergiler. | Pazarlama & Kampanya | Stabil |
| [SplitShare Affiliates for WooCommerce](https://wordpress.erusoft.com/splitshare-affiliates-for-woocommerce/) — Ortaklık programı: başvuru, kupon ve bağlantı takibi, komisyon ve ödeme defteri. | Pazarlama & Kampanya | Stabil |
| [Akakçe XML Feed for WooCommerce](https://wordpress.erusoft.com/akakce-xml-feed-for-woocommerce/) — Ürünlerinizi Akakçe'nin istediği XML biçiminde otomatik yayınlar. | Pazaryeri | Stabil |
| [Hepsiburada Integration for WooCommerce](https://wordpress.erusoft.com/hepsiburada-integration-for-woocommerce/) — Hepsiburada siparişlerini WooCommerce'e aktarır (eski sürüm; Marketplace Orders'a geçin). | Pazaryeri | Stabil |
| [Trendyol Integration for WooCommerce](https://wordpress.erusoft.com/trendyol-integration-for-woocommerce/) — Trendyol siparişlerini WooCommerce'e aktarır (eski sürüm; Marketplace Orders'a geçin). | Pazaryeri | Stabil |
| [Trendyol Reviews for WooCommerce](https://wordpress.erusoft.com/trendyol-reviews-for-woocommerce/) — Trendyol satıcı mağazanızın ürün yorumlarını eşleşen WooCommerce ürünlerine aktarır. | Pazaryeri | Stabil |
| [High-Traffic Fixes for WooCommerce](https://wordpress.erusoft.com/high-traffic-fixes-for-woocommerce/) — Yüksek trafikli WooCommerce mağazaları için on bir performans ve kararlılık düzeltmesi. | Performans | Stabil |
| [REST Response Cache for WooCommerce](https://wordpress.erusoft.com/rest-response-cache-for-woocommerce/) — İzin listesindeki herkese açık REST GET uçlarının yanıtlarını önbellekler. | Performans | Stabil |
| [Sort Order Cache Purge for WooCommerce](https://wordpress.erusoft.com/sort-order-cache-purge-for-woocommerce/) — Ürün sıralaması kaydedilince sayfa önbelleğini temizler. | Performans | Stabil |
| [Category Archive H1 for WordPress](https://wordpress.erusoft.com/category-archive-h1-for-wordpress/) — Kategori arşivlerine düzenlenebilir H1 başlığı alanı ekler. | SEO | Stabil |
| [Category Base Rewrite Guard for WooCommerce](https://wordpress.erusoft.com/category-base-rewrite-guard-for-woocommerce/) — Tabansız kategori yönlendirme kuralları eksik kaydedilirse yeniden üretir, aksi halde hiçbir şey yapmaz. | SEO | Stabil |
| [SEO Meta REST Fields for WordPress](https://wordpress.erusoft.com/seo-meta-rest-fields-for-wordpress/) — SEO başlık, açıklama ve anahtar kelime alanlarını REST API'ye açar. | SEO | Stabil |
| [KİNETİK](https://wordpress.erusoft.com/kinetik/) — Türkiye için açık kaynak WooCommerce spor giyim teması: adres seçimi, beden seçici, ücretsiz kargo çubuğu. | Tema | Stabil |
| [Product Ribbons for WooCommerce](https://wordpress.erusoft.com/product-ribbons-for-woocommerce/) — Ürün görsellerine kategori veya ürün bazlı renkli şerit/etiket ekler. | Ürün & Katalog | Stabil |
| [Dopigo Integration for WooCommerce](https://wordpress.erusoft.com/dopigo-integration-for-woocommerce/) — Dopigo ile stok, fiyat ve sipariş senkronu kurar. | Entegrasyon (ERP/CRM) | Yakında |
| [Order JSON Export for WooCommerce](https://wordpress.erusoft.com/order-json-export-for-woocommerce/) — Siparişleri KDV kırılımlı JSON dosyası olarak zamanlanmış dışa aktarır. | Fatura & Muhasebe | Yakında |
| [Welcome Note for WordPress](https://wordpress.erusoft.com/welcome-note-for-wordpress/) — Panoya kullanıcıyı ve site adını anan kısa bir karşılama notu ekler. | Geliştirici Araçları | Yakında |
| [e-Devlet Document Verification for WordPress](https://wordpress.erusoft.com/e-devlet-document-verification-for-wordpress/) — Yüklenen resmî belgeleri e-Devlet doğrulamasıyla kontrol eder. | Güvenlik | Yakında |
| [Order Blacklist for WooCommerce](https://wordpress.erusoft.com/order-blacklist-for-woocommerce/) — Sipariş verenleri e-posta, telefon, IP veya adrese göre kara listeye alır. | Güvenlik | Yakında |
| [AI Post Generator for WordPress](https://wordpress.erusoft.com/ai-post-generator-for-wordpress/) — Yapay zekâyla taslak yazı üretir ve kapak görseli ekler. | İçerik & AI | Yakında |
| [Content Automation for WordPress](https://wordpress.erusoft.com/content-automation-for-wordpress/) — Kategori ve anahtar kelimelerden bölümlü yapay zekâ içeriği üretir. | İçerik & AI | Yakında |
| [FAQ Accordion for WordPress](https://wordpress.erusoft.com/faq-accordion-for-wordpress/) — Sürüklenebilir sıkça sorulan sorular ve erişilebilir akordeon kısa kodu. | İçerik & AI | Yakında |
| [Heading Pagination for WordPress](https://wordpress.erusoft.com/heading-pagination-for-wordpress/) — Uzun yazıları H2 başlıklarına göre sayfalara böler. | İçerik & AI | Yakında |
| [Notice Boxes for WordPress](https://wordpress.erusoft.com/notice-boxes-for-wordpress/) — Bilgi, uyarı, başarı ve hata kutuları için kısa kod ve blok. | İçerik & AI | Yakında |
| [Site Content Generator](https://wordpress.erusoft.com/site-content-generator/) — Ayarlanabilir konu ve sağlayıcıyla site içeriği üretir. | İçerik & AI | Yakında |
| [Site Manager for WordPress](https://wordpress.erusoft.com/site-manager-for-wordpress/) — Slider, iletişim bilgileri, şema ve llms.txt için modüler site yönetimi. | İçerik & AI | Yakında |
| [Click & Collect for WooCommerce](https://wordpress.erusoft.com/click-collect-for-woocommerce/) — Mağazadan teslim (gel-al) kargo yöntemi ve teslim noktası seçimi sunar. | Kargo & Teslimat | Yakında |
| [Order SMS & Delivery Codes for İleti Merkezi](https://wordpress.erusoft.com/order-sms-delivery-codes-for-ileti-merkezi/) — Sipariş durumuna göre SMS gönderir ve teslimat kodu doğrular. | Kargo & Teslimat | Yakında |
| [Same-Day Delivery for WooCommerce](https://wordpress.erusoft.com/same-day-delivery-for-woocommerce/) — Aynı gün teslimat uygunluğunu ürün, ilçe ve kesim saatine göre belirler. | Kargo & Teslimat | Yakında |
| [Turkish Shipping Carriers for WooCommerce](https://wordpress.erusoft.com/turkish-shipping-carriers-for-woocommerce/) — Birden çok kargo firması için etiket, takip ve kural motoru sunar. | Kargo & Teslimat | Yakında |
| [Yurtiçi Kargo Integration for WooCommerce](https://wordpress.erusoft.com/yurtici-kargo-integration-for-woocommerce/) — Yurtiçi Kargo gönderi oluşturma, etiket ve teslimat takibini otomatikleştirir. | Kargo & Teslimat | Yakında |
| [Abandoned Checkout Call List for WooCommerce](https://wordpress.erusoft.com/abandoned-checkout-call-list-for-woocommerce/) — Yarım kalan ödemeleri yakalar ve arama listesi olarak sunar. | Müşteri & B2B | Yakında |
| [B2B Customer Pricing for WooCommerce](https://wordpress.erusoft.com/b2b-customer-pricing-for-woocommerce/) — Müşteri segmentlerine göre B2B fiyat grupları ve otomatik üyelik. | Müşteri & B2B | Yakında |
| [Price Lists for WooCommerce](https://wordpress.erusoft.com/price-lists-for-woocommerce/) — Kullanıcı rollerine göre fiyat listeleri tanımlayın. | Müşteri & B2B | Yakında |
| [Role-Based Prices for WooCommerce](https://wordpress.erusoft.com/role-based-prices-for-woocommerce/) — Kullanıcı rollerine özel ürün fiyatları tanımlayın. | Müşteri & B2B | Yakında |
| [Sales Team Commissions for WooCommerce](https://wordpress.erusoft.com/sales-team-commissions-for-woocommerce/) — Satış temsilcisi hiyerarşisi, komisyon kuralları ve ödeme akışı. | Müşteri & B2B | Yakında |
| [Wholesale Inquiry Box for WooCommerce](https://wordpress.erusoft.com/wholesale-inquiry-box-for-woocommerce/) — Ürün sayfasına WhatsApp veya e-posta ile toptan fiyat sorma kutusu ekler. | Müşteri & B2B | Yakında |
| [Bank Transfer Auto-Approval for Garanti BBVA](https://wordpress.erusoft.com/bank-transfer-auto-approval-for-garanti-bbva/) — Garanti BBVA hesap hareketlerinden havale ödemelerini otomatik onaylar. | Ödeme | Yakında |
| [Multi Virtual POS for WooCommerce](https://wordpress.erusoft.com/multi-virtual-pos-for-woocommerce/) — Birden çok bankanın sanal POS'unu kural motoruyla yöneten ödeme eklentisi. | Ödeme | Yakında |
| [VakıfBank Vinov Integration for WooCommerce](https://wordpress.erusoft.com/vakifbank-vinov-integration-for-woocommerce/) — VakıfBank Vinov ile OTP'li ödeme ve talimat yönetimi. | Ödeme | Yakında |
| [Automatic Discounts for WooCommerce](https://wordpress.erusoft.com/automatic-discounts-for-woocommerce/) — Kupon gerektirmeyen ürün, kategori ve sepet bazlı otomatik indirimler. | Pazarlama & Kampanya | Yakında |
| [Bought Together Discounts for WooCommerce](https://wordpress.erusoft.com/bought-together-discounts-for-woocommerce/) — Birlikte alınan ürünlere sepette otomatik indirim uygular. | Pazarlama & Kampanya | Yakında |
| [Campaign Engine for WooCommerce](https://wordpress.erusoft.com/campaign-engine-for-woocommerce/) — Koşul ve aksiyon tabanlı tam kampanya motoru ve sihirbazı. | Pazarlama & Kampanya | Yakında |
| [Campaign Pages for WordPress](https://wordpress.erusoft.com/campaign-pages-for-wordpress/) — Kampanyaları arşiv sayfası ve aktif/yaklaşan/bitmiş durumlarıyla yayınlar. | Pazarlama & Kampanya | Yakında |
| [Cart Campaigns for WooCommerce](https://wordpress.erusoft.com/cart-campaigns-for-woocommerce/) — Sepet tutarı eşiklerine bağlı kampanyaları kural olarak tanımlayın. | Pazarlama & Kampanya | Yakında |
| [Checkout Consent Sync for İYS](https://wordpress.erusoft.com/checkout-consent-sync-for-iys/) — Ödeme sayfasında iletişim izni alır ve İYS ile senkronlar. | Pazarlama & Kampanya | Yakında |
| [Consent Manager for İYS](https://wordpress.erusoft.com/consent-manager-for-iys/) — İYS izin deposu, denetim kaydı ve hız sınırlı senkron kuyruğu. | Pazarlama & Kampanya | Yakında |
| [Conversion Boosters for WooCommerce](https://wordpress.erusoft.com/conversion-boosters-for-woocommerce/) — Ücretsiz kargo çubuğu, hoş geldin kuponu ve geri sayımlı kupon gibi dönüşüm araçları. | Pazarlama & Kampanya | Yakında |
| [Exit-Intent Coupons for WooCommerce](https://wordpress.erusoft.com/exit-intent-coupons-for-woocommerce/) — Sayfadan ayrılmak üzere olan ziyaretçiye bütçe sınırlı kupon sunar. | Pazarlama & Kampanya | Yakında |
| [Gift Line Guard for WooCommerce](https://wordpress.erusoft.com/gift-line-guard-for-woocommerce/) — Hediye satırlarının adedini ve silinmesini otomatik denetler. | Pazarlama & Kampanya | Yakında |
| [Gift Product Rules for WooCommerce](https://wordpress.erusoft.com/gift-product-rules-for-woocommerce/) — Koşula bağlı hediye ürünü sepete otomatik ekler. | Pazarlama & Kampanya | Yakında |
| [Growth Automations for WooCommerce](https://wordpress.erusoft.com/growth-automations-for-woocommerce/) — Havale hatırlatma, terk edilen sepet, geri kazanım ve yorum isteği otomasyonları. | Pazarlama & Kampanya | Yakında |
| [Marketplace Orders for WooCommerce](https://wordpress.erusoft.com/marketplace-orders-for-woocommerce/) — Pazaryeri siparişlerini WooCommerce'e aktarır; stok ve fiyatı senkronlar. | Pazaryeri | Yakında |
| [Product Push for Trendyol](https://wordpress.erusoft.com/product-push-for-trendyol/) — WooCommerce ürünlerini Trendyol'a toplu gönderir. | Pazaryeri | Yakında |
| [Local WebP Generator for WordPress](https://wordpress.erusoft.com/local-webp-generator-for-wordpress/) — Yüklenen görselleri sunucuda WebP'ye çevirir; orijinallere dokunmaz. | Performans | Yakında |
| [Server-Side Refunds for GA4](https://wordpress.erusoft.com/server-side-refunds-for-ga4/) — İade ve iptalleri GA4'e sunucu tarafında bildirir. | Raporlama & Analitik | Yakında |
| [Server-Side Refunds for Matomo](https://wordpress.erusoft.com/server-side-refunds-for-matomo/) — İade ve iptalleri Matomo'ya sunucu tarafında bildirir. | Raporlama & Analitik | Yakında |
| [Shipping Method Report for WooCommerce](https://wordpress.erusoft.com/shipping-method-report-for-woocommerce/) — Kargo yöntemine göre siparişleri haftalık e-posta raporu olarak gönderir. | Raporlama & Analitik | Yakında |
| [Primary Category for WooCommerce](https://wordpress.erusoft.com/primary-category-for-woocommerce/) — Ürüne birincil kategori seçtirir; kırıntı ve şemada kullanılır. | SEO | Yakında |
| [Catalog Mode & WhatsApp Orders for WooCommerce](https://wordpress.erusoft.com/catalog-mode-whatsapp-orders-for-woocommerce/) — Fiyat ve satın almayı gizleyip talepleri WhatsApp veya e-posta ile toplar. | Sepet & Ödeme Akışı | Yakında |
| [Custom Order Statuses for WooCommerce](https://wordpress.erusoft.com/custom-order-statuses-for-woocommerce/) — Panelden sipariş durumu ekleyin; renk, sıra, e-posta ve toplu işlem yönetin. | Sepet & Ödeme Akışı | Yakında |
| [Device Trade-In for WooCommerce](https://wordpress.erusoft.com/device-trade-in-for-woocommerce/) — Takas edilen ürün için yapılandırılabilir özelliklere göre indirim değeri hesaplar. | Sepet & Ödeme Akışı | Yakında |
| [High-Value Cart Offer for WooCommerce](https://wordpress.erusoft.com/high-value-cart-offer-for-woocommerce/) — Yüksek tutarlı sepetlere özel teklif bandı ve WhatsApp butonu gösterir. | Sepet & Ödeme Akışı | Yakında |
| [Payment Method Discounts for WooCommerce](https://wordpress.erusoft.com/payment-method-discounts-for-woocommerce/) — Ödeme yöntemine göre indirim veya ek ücret uygular. | Sepet & Ödeme Akışı | Yakında |
| [Payment Restrictions by Category for WooCommerce](https://wordpress.erusoft.com/payment-restrictions-by-category-for-woocommerce/) — Seçili kategorilerdeki ürünlerde belirli ödeme yöntemlerini kapatır. | Sepet & Ödeme Akışı | Yakında |
| [Purchase Limits for WooCommerce](https://wordpress.erusoft.com/purchase-limits-for-woocommerce/) — Kategori gruplarına ve müşteri türüne göre satın alma limitleri koyar. | Sepet & Ödeme Akışı | Yakında |
| [Turkish Address Fields for WooCommerce](https://wordpress.erusoft.com/turkish-address-fields-for-woocommerce/) — İl, ilçe, mahalle ve sokak seçimli Türkiye adres alanları ve adres doğrulaması. | Sepet & Ödeme Akışı | Yakında |
| [Narin](https://wordpress.erusoft.com/narin/) — Zarif tipografi ve cam efektli bileşenlerle şık bir WooCommerce teması. | Tema | Yakında |
| [Oje](https://wordpress.erusoft.com/oje/) — Hero ve kategori bölümleri, güven bandıyla yumuşak görünümlü WooCommerce teması. | Tema | Yakında |
| [Ufuk](https://wordpress.erusoft.com/ufuk/) — Kurumsal siteler için sade ve hızlı WordPress teması. | Tema | Yakında |
| [Voltaj](https://wordpress.erusoft.com/voltaj/) — Marka, iletişim ve teklif isteği sayfa şablonlarıyla WooCommerce teması. | Tema | Yakında |
| [Brand Product Sorting for WooCommerce](https://wordpress.erusoft.com/brand-product-sorting-for-woocommerce/) — Marka arşivindeki ürünleri sürükle-bırak ile sıralayın. | Ürün & Katalog | Yakında |
| [Bulk Product Visibility for WooCommerce](https://wordpress.erusoft.com/bulk-product-visibility-for-woocommerce/) — Ürünlerin görünürlüğünü kategoriye göre toplu veya tek tek yönetin. | Ürün & Katalog | Yakında |
| [Bundle Stock Sync for WooCommerce](https://wordpress.erusoft.com/bundle-stock-sync-for-woocommerce/) — Paket ürünlerin stok durumunu bileşen değişimlerinde tutarlı tutar. | Ürün & Katalog | Yakında |
| [Category Banner Slider for WooCommerce](https://wordpress.erusoft.com/category-banner-slider-for-woocommerce/) — Kategori sayfalarına cihaza göre ayrı görselli slider ekler. | Ürün & Katalog | Yakında |
| [Coming Soon Products for WooCommerce](https://wordpress.erusoft.com/coming-soon-products-for-woocommerce/) — Ürünü 'yakında' olarak işaretler; fiyat yerine mesaj gösterir ve satışı kapatır. | Ürün & Katalog | Yakında |
| [Hide Categories for WooCommerce](https://wordpress.erusoft.com/hide-categories-for-woocommerce/) — Seçili kategorileri bileşenlerde ve listelerde gizler. | Ürün & Katalog | Yakında |
| [Out-of-Stock Last for WooCommerce](https://wordpress.erusoft.com/out-of-stock-last-for-woocommerce/) — Stokta olmayan ürünleri arşivlerde listenin sonuna taşır. | Ürün & Katalog | Yakında |
| [PDF Product Catalog for WooCommerce](https://wordpress.erusoft.com/pdf-product-catalog-for-woocommerce/) — Ürün kataloğunuzu kategori gruplu, şablonlu PDF olarak üretir. | Ürün & Katalog | Yakında |
| [Product Add-On Services for WooCommerce](https://wordpress.erusoft.com/product-add-on-services-for-woocommerce/) — Ürün veya kategoriye bağlı ek hizmet grupları (montaj, garanti vb.) tanımlayın. | Ürün & Katalog | Yakında |
| [Product Status Notifications for WooCommerce](https://wordpress.erusoft.com/product-status-notifications-for-woocommerce/) — Ürün durumu değişince belirlenen alıcılara e-posta gönderir. | Ürün & Katalog | Yakında |
| [Smart Product Search for WooCommerce](https://wordpress.erusoft.com/smart-product-search-for-woocommerce/) — Puanlamalı sıralama ve marka-kategori önerileriyle akıllı ürün araması. | Ürün & Katalog | Yakında |
| [Supplier XML Importer for WooCommerce](https://wordpress.erusoft.com/supplier-xml-importer-for-woocommerce/) — Tedarikçi XML'lerinden ürün ve stok içe aktarır; fiyat kuralları uygular. | Ürün & Katalog | Yakında |
| [T-Soft Migrator for WooCommerce](https://wordpress.erusoft.com/t-soft-migrator-for-woocommerce/) — T-Soft mağaza verisini WooCommerce'e taşıyan genel içe aktarma aracı. | Ürün & Katalog | Yakında |
| [USD Pricing for WooCommerce](https://wordpress.erusoft.com/usd-pricing-for-woocommerce/) — Ürünleri USD fiyatlandırıp TL fiyatı güncel kurla otomatik hesaplar. | Ürün & Katalog | Yakında |
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
