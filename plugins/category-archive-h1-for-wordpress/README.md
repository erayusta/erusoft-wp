# Category Archive H1 for WordPress

Kategori, etiket ve diğer sınıflandırma arşivleri için terim adından bağımsız bir H1 başlığı alanı ekler. Arşivde tam olarak bir H1 bulunmasını sağlar ve temanın mevcut H1'ini çoğaltmaz.

![Ayarlar](../../docs/screenshots/category-archive-h1-for-wordpress/1.png)

## Özellikler

- Etkin sınıflandırmaların terim ekle/düzenle ekranında "Arşiv H1 başlığı" alanı (varsayılan: kategoriler)
- Terim adı menülerde ve yazılarda değişmez
- Üç kip: yalnızca başlığı değiştir, otomatik (H1 yoksa ekle), tema H1'ini değiştir
- Başlık, `single_cat_title` / `single_tag_title` / `single_term_title` ile temaya iletilir
- Rank Math ve Yoast SEO başlığında terim adı yerine özel başlık (isteğe bağlı)
- Herhangi bir sınıflandırma arşivinde çalışır (WooCommerce gerekmez)

## Kurulum

1. `category-archive-h1-for-wordpress` klasörünü `wp-content/plugins/` altına kopyalayın (veya zip olarak yükleyin).
2. Eklentiler ekranından etkinleştirin.
3. Ayarlar → Arşiv H1 ekranından sınıflandırmaları seçin; terim düzenleme ekranında başlığı yazın.

## Ayarlar

Ayarlar → Arşiv H1: sınıflandırmalar (`eru_cah_taxonomies`), tema H1 kipi (`eru_cah_mode`: filter / auto / replace), SEO başlığı (`eru_cah_seo_title`). Başlık terim metası `eru_cah_heading`.

## Kancalar ve filtreler

| Ad | Tür | Açıklama |
|---|---|---|
| `eru_cah_heading` | filtre | Terimin arşiv başlığını değiştirir (`$heading`, `$term`); boş dize özel başlığı iptal eder |

## Gereksinimler

WordPress 6.5+, PHP 8.0+. WooCommerce gerekmez.

## Sınırlamalar

- Otomatik ve değiştirme kipleri sayfa çıktısını işler (çıktı tamponu); H1 yoksa `<main>` açılışının, o da yoksa `<body>` açılışının ardına eklenir.
- Temanın logosu H1 ise sayfada H1 var sayılır ve ek H1 eklenmez.
- Tema H1'i terim adını sabit yazıyorsa "Tema H1'ini değiştir" kipi gerekir.
- Eklenti silindiğinde (Eklentiler → Sil) yalnızca kendi ayarları (`eru_cah_*` seçenekleri) silinir; terimlere girilen başlıklar (`eru_cah_heading` terim metası) korunur.

## SSS

**Tema zaten H1 yazdırıyor, çift olur mu?** Hayır. Otomatik kip yalnızca sayfada H1 yoksa ekler.

**Başlık boşsa?** Terim adı kullanılır; hiç H1 yoksa terim adıyla bir H1 eklenir.

## English summary

Adds an editable H1 heading field to category, tag and other taxonomy archives. Ensures exactly one H1 on the archive and never duplicates a theme H1 (modes: filter only, auto, replace). Filter: `eru_cah_heading`. No WooCommerce required.

## Sürüm notları

Bkz. [CHANGELOG.md](CHANGELOG.md).

## Lisans

GPL-2.0-or-later. Diğer Erusoft eklentileri: https://wordpress.erusoft.com
