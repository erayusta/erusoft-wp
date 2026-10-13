# Voltaj — tasarım notları

Voltaj, teknik ürün ve elektrik malzemesi satan mağazalar için bir WooCommerce temasıdır: kablo, aydınlatma,
anahtar-priz, el aletleri gibi ürünlerin kodu, stok durumu ve teknik özellikleriyle arandığı kataloglar.
Ziyaretçi çoğu zaman ne aradığını bilir; tasarım bu yüzden aramayı, kategori ağacını ve özellik tablosunu öne alır,
süslemeyi geri planda tutar.

## Renkler

Koyu grafit zemin üzerinde tek bir "elektrik" vurgusu (camgöbeği).

| Belirteç | Değer | Kullanım |
|---|---|---|
| `--vj-base` | `#1B1F24` grafit | Başlık çubuğu, giriş bandı, alt bilgi, ana metin |
| `--vj-accent` | `#22D3E6` camgöbeği | Birincil düğme zemini, odak halkası, etkin durumlar |
| `--vj-accent-ink` | vurgudan türetilir (varsayılanla `#07555E`) | Açık zeminde bağlantı ve vurgulu metin |
| `--vj-bg` | `#F2F4F5` | Sayfa zemini |
| `--vj-surface` | `#FFFFFF` | Kartlar, paneller, tablolar |
| `--vj-muted` | `#4A525C` | İkincil metin |
| `--vj-line` | `#D5DADF` | Çizgiler, kenarlıklar |
| `--vj-ok` / `--vj-warn` / `--vj-bad` | `#1E7B4F` / `#8A5300` / `#B3261E` | Stokta / ön sipariş / tükendi |

AA kontrast çiftleri (WCAG 2.1, normal metin için en az 4.5:1):

| Ön plan | Zemin | Oran |
|---|---|---|
| `#1B1F24` | `#FFFFFF` | 16.56 |
| `#1B1F24` | `#F2F4F5` | 15.01 |
| `#1B1F24` (düğme yazısı) | `#22D3E6` | 9.09 |
| `#22D3E6` (vurgu) | `#1B1F24` | 9.09 |
| `#FFFFFF` | `#1B1F24` | 16.56 |
| `#AEB6BF` (koyu zeminde ikincil) | `#1B1F24` | 8.08 |
| `#07555E` | `#FFFFFF` | 8.47 |
| `#07555E` | `#F2F4F5` | 7.70 |
| `#4A525C` | `#FFFFFF` | 7.92 |
| `#1E7B4F` / `#8A5300` / `#B3261E` | `#FFFFFF` | 5.25 / 6.33 / 6.54 |

Özelleştirici'den vurgu ve grafit rengi değiştirilebilir. Vurgu üzerinde her zaman grafit yazı kullanıldığından
vurgu için açık, parlak bir ton seçilmelidir; açık zemindeki bağlantı rengi vurgudan otomatik koyulaştırılır.

## Yazı tipleri

- **Archivo** (SIL OFL 1.1, değişken ağırlık): başlıklar ve gövde. Geniş, sağlam bir grotesk; başlıklarda 700–800.
- **JetBrains Mono** (SIL OFL 1.1, değişken ağırlık): ürün kodu (SKU), teknik değerler, fiyatlar, adet ve sayaçlar.

İkisi de latin + latin-ext WOFF2 olarak temayla gelir (`fonts/`, `fonts/OFL.txt`); dış istek yapılmaz.
Özelleştirici'den kapatılırsa sistem yazı tipleri kullanılır.

## Başlık (header)

Tek, yapışkan, koyu bir çubuk: **logo – "Kategoriler" düğmesi – tam genişlikte arama – teklif / hesap / sepet**.
"Kategoriler" düğmesi çubuğun hemen altında açılan iki bölmeli bir katalog paneli açar: solda ana kategoriler,
sağda seçili kategorinin alt kategorileri ve onların alt kırılımları. Panel tıklama ve klavyeyle açılır
(Enter/Space, ok tuşları), Escape kapatır ve odak düğmeye döner. Mobilde aynı panel soldan açılan bir çekmeceye,
kategoriler de akordeona dönüşür; arama çubuğun ikinci satırına iner. Başlığın üstünde isteğe bağlı ince bir
duyuru şeridi vardır (boşsa gösterilmez).

## Ana sayfa bölüm sırası

1. **Arama bandı:** grafit zemin, başlık, kısa metin ve büyük ürün arama alanı (isteğe bağlı görsel sağda).
2. **Kategori ızgarası:** ana kategoriler; kare görsel, ad ve ürün sayısı (mono).
3. **Öne çıkan ürünler:** WooCommerce'te "öne çıkan" işaretli ürünler; yoksa en yeniler.
4. **Marka şeridi:** seçilen marka taksonomisinin terimleri (logo veya ad).
5. **Bilgi kartları:** Özelleştirici'de yazılan maddeler (ör. teslimat, fatura, teknik destek). Boşsa gösterilmez.
6. **Blog:** son üç yazı.

Her bölüm Özelleştirici'den kapatılabilir.

## Ürün kartı (özellik öncelikli)

Beyaz zemin, ince çerçeve, köşe yarıçapı 4 px. Yukarıdan aşağı:

1. Ürün görseli, beyaz zemin üzerinde boşluklu ve kırpılmadan (`contain`).
2. Mono yazıyla **ürün kodu** ve renkli noktayla **stok durumu** (stokta / ön sipariş / tükendi).
3. Ürün adı (en fazla üç satır).
4. **Öne çıkan özellikler:** ürünün görünür niteliklerinden ilk üçü, "etiket: değer" satırları.
5. Alt satır: mono fiyat (indirimde eski fiyat üstü çizili ve küçük bir "−%N" etiketi) ve kare sepet düğmesi.

Fiyatı girilmemiş ürünlerde fiyat yerine "Fiyat teklifle belirlenir" yazar ve düğme teklif formuna gider.
Görsel alanı yalnızca ürün fotoğrafını gösterir; indirim bilgisi fiyatın yanındaki küçük etikettedir.

## Ürün sayfası

- Üstte iki sütun: **solda galeri**, **sağda** başlık, kod/marka satırı, kısa açıklama, **özellik tablosu**
  (öne çıkan nitelikler) ve çerçeveli bir **satın alma kutusu** (fiyat, stok, adet, sepete ekle, toplu alım için teklif bağlantısı).
- Altta tam genişlikte **sekmeler** (açıklama, ek bilgi, değerlendirmeler), sonra ilgili ürünler ve "Son baktıklarınız".
- Mobilde satın alma kutusu ekrandan çıkınca altta sabit bir satın alma çubuğu görünür.
- WooCommerce'in bütün çekirdek kancaları çalışır; düzen yalnızca kancalar ve CSS ile kurulur.

Tema geri sayım ya da sahte stok/ziyaretçi sayacı gibi yanıltıcı öğeler kullanmaz; gösterilen stok ve fiyat bilgisi WooCommerce verisinden gelir.

## Diğer ilkeler

- Erişilebilirlik: atlama bağlantısı, görünür odak halkası (`:focus-visible`), `prefers-reduced-motion` desteği,
  açılır menülerin tamamı klavyeyle kullanılabilir.
- CSS alanlara bölünür ve yalnızca gereken sayfada yüklenir (`assets/css/`).
- WooCommerce kapalıyken tema mağaza öğeleri olmadan çalışır.
