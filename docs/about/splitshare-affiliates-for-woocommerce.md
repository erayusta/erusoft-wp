# SplitShare Affiliates for WooCommerce

## Ne işe yarar

WooCommerce mağazanız için influencer ve içerik üreticilerine yönelik bir ortaklık programı kurar. Her satıştan sabit bir pay ayrılır; ortak, kendi kampanya kuponlarını Hesabım içinden oluştururken bu payın ne kadarını takipçi indirimine, ne kadarını kendi komisyonuna ayıracağına karar verir. Mağazanın maliyeti ortağın tercihinden bağımsız olarak aynı kalır.

## Özellikler

- Başvuru formu (`[ssa_apply]` kısa kodu) ve yönetimde onay/ret akışı: WooCommerce → Affiliates.
- Her ortağa gerçek bir WooCommerce kuponu ve `?ref=KOD` bağlantısı verilir; bağlantı çerezle izlenir (süre ayardan değişir).
- Kupon ve bağlantı ile atama birlikte çalışır; kupon önceliklidir. Ortağın kendi siparişleri sayılmaz.
- Komisyon kalem bazında hesaplanır: kupon kapsamındaki kalemlerde pay eksi kupon indirimi, kuponsuz bağlantı siparişlerinde ayrı bir bağlantı oranı uygulanır.
- Kategoriye göre pay grupları, hariç tutulan kategoriler, kampanya dönemleri ve ürün hızlandırıcıları.
- Geri dönen müşteri çarpanı, sipariş başına üst sınır, asgari sepet tutarı.
- Bekleme süresi, iptal ve kısmi iadede yeniden hesaplama; ödeme sonrası iadeler sonraki hakedişten düşülür.
- Aylık hakediş dönemleri, asgari ödeme eşiği, devreden bakiye ve banka transferi için CSV dışa aktarma.
- Ortak paneli (Hesabım): özet, satışlar, kazançlar ve banka bilgileri, kuponlar (oluşturma, duraklatma, silme), bağlantı oluşturucu ve içerik kiti.
- Başarı basamakları, rapor ekranları ve WooCommerce e-posta ayarlarından yönetilen bildirim e-postaları (başvuru, onay, ret, ödeme).
- HPOS ve blok tabanlı ödeme sayfası uyumluluğu bildirilir; arayüz çeviriye hazırdır, Türkçe çeviri dahildir.

## Kurulum

1. GitHub deposunun Releases bölümünden ya da "Code → Download ZIP" ile ZIP dosyasını indirin.
2. Eklentiler → Yeni ekle → Eklenti yükle ile yükleyip etkinleştirin. WooCommerce etkin olmalıdır.
3. **WooCommerce → Ayarlar → Affiliates** sekmesinde program adını, payı, grupları, eşikleri, ödeme kurallarını ve panel adreslerini belirleyin.
4. Bir sayfaya `[ssa_apply]` kısa kodunu koyup ayarlarda başvuru sayfası olarak seçin.
5. Sayfa önbelleği kullanıyorsanız `?ref=` içeren isteklerin önbelleğe alınmaması için kural ekleyin.

## Gereksinimler

- WordPress 6.5 veya üzeri, WooCommerce 9.0 veya üzeri, PHP 8.0 veya üzeri (katalog kaydı). Eklenti üstbilgisi daha düşük asgari değerler bildirir (WordPress 6.0, WooCommerce 7.0, PHP 7.4).

## Sınırlamalar

- Ödemeleri eklenti göndermez: hakedişleri siz havale/EFT ile öder, ardından yönetimde "ödendi" olarak işaretlersiniz.
- Varsayılan ayarlar belirli bir program kurgusuna göre gelir; kendi oranlarınızı, eşiklerinizi ve metinlerinizi gözden geçirmeden yayına almayın.
- Veri tabloları, eklenti silindiğinde yalnızca `wp-config.php` içinde `SSA_REMOVE_DATA` sabiti `true` ise kaldırılır.
- Ortak ve komisyon verileri kişisel veri içerir; KVKK yükümlülükleri sizdedir.

## SSS

**Komisyon ile indirim nasıl bölünür?** Ayrılan payın tamamı komisyon ve takipçi indirimi toplamıdır. Ortak kuponun indirimini artırırsa komisyonu aynı oranda azalır.

**Kuponsuz gelen siparişler komisyon getirir mi?** Evet; bağlantıyla gelen ve kupon kullanmayan siparişlere ayarlanabilir bir bağlantı oranı uygulanır.

**İade olursa ne olur?** Komisyon bekleme süresi dolmadan iptal veya iade gelirse iptal edilir ya da yeniden hesaplanır; ödeme yapıldıktan sonra gelen iade sonraki hakedişten düşülür.

**Geliştiriciler için kanca var mı?** Evet. `ssa_partner_approved`, `ssa_payout_paid` gibi eylemler ve `ssa_commission_context`, `ssa_email_paragraphs` süzgeçleri bulunur.

## Kaynak kod

[github.com/erayusta/splitshare-affiliates-for-woocommerce](https://github.com/erayusta/splitshare-affiliates-for-woocommerce)

## Destek

- Hata mı buldunuz? Eklentinin GitHub deposunda issue açın; WordPress, WooCommerce, PHP ve eklenti sürümünü, adımları ve beklenen/gerçekleşen sonucu yazın.
- Kurulum, yapılandırma, özel geliştirme veya entegrasyon desteği için **[hello@erusoft.com](mailto:hello@erusoft.com)** adresine yazın. Erusoft hakkında: [erusoft.com](https://erusoft.com).
- Lütfen şifre, API anahtarı veya müşteri verisi (ad, telefon, TCKN, sipariş dökümü) göndermeyin; ekran görüntülerindeki kişisel verileri karartın.
