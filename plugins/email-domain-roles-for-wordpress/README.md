# Email Domain Roles for WordPress

Yeni kayıt olan kullanıcıya e-posta alan adına göre otomatik rol atar. Eşleme tablosu Ayarlar → E-posta Alan Adı Rolleri ekranından yönetilir ve varsayılan olarak boştur.

![Ayarlar](../../docs/screenshots/email-domain-roles-for-wordpress/1.png)

## Özellikler

- Alan adı → rol eşleme tablosu (varsayılan boş)
- `*.example.org` ile alt alan adı eşleşmesi
- İsteğe bağlı "eşleşme yoksa" rolü (varsayılan: rolü değiştirme)
- Yönetici bilinçli olarak seçtiği (varsayılan dışı) rollere dokunmaz
- `manage_options`, `promote_users`, `edit_users`, `create_users`, `unfiltered_html`, `install_plugins`, `edit_theme_options`, `manage_woocommerce` gibi yetkili yeteneklere sahip roller listelenmez ve atanamaz
- Bir yönetici kullanıcıyı elle eklerken (kullanıcı oluşturma yetkisiyle) rol değiştirilmez; yalnızca kayıt formu, WooCommerce hesap kaydı ve benzeri otomatik kayıtlar eşlenir

## Kurulum

1. `email-domain-roles-for-wordpress` klasörünü `wp-content/plugins/` altına kopyalayın (veya zip olarak yükleyin).
2. Eklentiler ekranından etkinleştirin.
3. Ayarlar → **E-posta Alan Adı Rolleri** ekranından eşlemeleri ekleyin.

## Ayarlar

Ayarlar → E-posta Alan Adı Rolleri: eşleme tablosu (`eru_edr_mappings`, boş) ve eşleşme yoksa rol (`eru_edr_fallback_role`, boş = değiştirme).

## Kancalar ve filtreler

| Ad | Tür | Açıklama |
|---|---|---|
| `eru_edr_allowed_roles` | filtre | Atanabilir roller (rol anahtarı => ad) |
| `eru_edr_replaceable_roles` | filtre | Üzerine yazılabilen mevcut roller (varsayılan: WordPress varsayılan rolü ve `customer`) |
| `eru_edr_assign_for_admin_created` | filtre | `true` dönerse yönetici tarafından eklenen kullanıcılar da eşlenir (varsayılan `false`) |
| `eru_edr_role_assigned` | eylem | Rol atandıktan sonra (`$user_id`, `$role`, `$email`) |

## Gereksinimler

WordPress 6.5+, PHP 8.0+. WooCommerce gerekmez.

## Sınırlamalar

- Kayıt sırasındaki e-posta adresi doğrulanmamıştır; birine yetki verilecek bir alan adı eşlemesi yaparsanız e-posta doğrulaması (ör. onay bağlantısı) kullanmanız önerilir.
- Yalnızca kullanıcı oluşturulurken çalışır; sonradan e-posta değiştirmek rolü etkilemez.
- Eklenti silindiğinde (Eklentiler → Sil) yalnızca kendi ayarları (`eru_edr_*` seçenekleri) silinir; kullanıcılara daha önce atanmış roller değişmez.

## SSS

**Kullanıcıyı yönetici yapabilir miyim?** Hayır, yönetim ve yetki yükseltme yeteneği olan roller güvenlik nedeniyle atanamaz (`eru_edr_allowed_roles` filtresiyle değiştirilebilir).

**Yönetici ekranından seçtiğim rol ezilir mi?** Varsayılan rol dışında bir rol seçtiyseniz ezilmez.

## English summary

Assigns a role to newly registered users based on their e-mail domain. The domain-to-role table (Settings > E-posta Alan Adı Rolleri) is empty by default; wildcard `*.example.org` is supported and roles with administrative or privilege-escalation capabilities (manage_options, promote_users, edit_users, create_users, unfiltered_html, install_plugins, edit_theme_options, manage_woocommerce) are excluded.

## Sürüm notları

Bkz. [CHANGELOG.md](CHANGELOG.md).

## Lisans

GPL-2.0-or-later. Diğer Erusoft eklentileri: https://wordpress.erusoft.com
