# Katkı rehberi / Contributing

## Gizlilik kapısı (privacy gate)

Her commit ve yayın öncesinde `npm run scrub` çalıştırılır. Kapı; müşteri/marka adlarını, kişisel verileri
(e-posta, telefon, TCKN, IBAN, IP) ve gizli anahtarları arar; bulursa çıkış kodu 1 döner.

- Terim listesi **gizlidir** ve repoya hiçbir biçimde (hash olarak da) girmez.
  - Yerelde: `.private/scrub-hashes.txt` (normalize edilmiş terimlerin sha256 değerleri) ve
    `.private/scrub-terms.txt` (düz liste, yalnızca yerel `--strict` modu için).
  - CI'da: iş akışı hash listesini bir GitHub Actions secret'ından geçici bir dosyaya yazar ve
    `SCRUB_HASHES_FILE` ortam değişkenini o dosyaya ayarlar. Commit kimliği de secret'tan
    `SCRUB_ALLOW_IDENTITY` ile verilir.
- Terim kaynağı yoksa kapı "temiz" demez, çıkış kodu 2 ile durur. `SCRUB_ALLOW_NO_TERMS=1` yalnızca birim
  testleri içindir.
- Geliştiricinin kendi commit kimliği yalnızca `.private/scrub-allow.txt` veya `SCRUB_ALLOW_IDENTITY` ile
  izinlidir; kodda kimlik tutulmaz.
- Arşiv dosyaları (`.zip`, `.tar.gz`…) ve `.private/`, `.superpowers/` altındaki yollar repoya giremez.
- Test verilerinde yalnızca uydurma adlar kullanın (ör. "Acme Gıda"); örnek e-posta/IBAN/anahtar
  değerlerini testte parçalardan birleştirin ki dosyanın kendisi temiz taransın.

**English summary:** run `npm run scrub` before every commit. The client term list is private and never
committed (not even hashed); CI injects it via the `SCRUB_HASHES_FILE` secret-backed temp file. With no term
source the gate exits 2. Archives and `.private/` / `.superpowers/` paths are rejected.

## Yerel demo sitesi ve duman testi (demo site + smoke test)

Demo site depo dışındadır: `$HOME/code/erusoft-wp-demo` (tr_TR WordPress + WooCommerce, 6 uydurma ürün
"Demo Ürün 1..6", kupon `DEMO10`, giden e-postaları yakalayan yerel mu-plugin). Laravel Herd klasörü
`http://erusoft-wp-demo.test` adresinde sunar. Yol ve adres `ERUSOFT_WP_DEMO` ve `ERUSOFT_WP_DEMO_URL`
ortam değişkenleriyle değiştirilebilir. WP-CLI için: `export WP_CLI_PHP_ARGS='-d error_reporting=24575'`.

```bash
tools/link-demo.sh                       # plugins/* ve mu-plugins/high-traffic-fixes/*.php dosyalarını demoya bağlar
tools/smoke.sh <slug>                    # etkinleştir, dene, devre dışı bırak
tools/smoke.sh <slug> --no-wc            # aynısı, WooCommerce kapalıyken
tools/smoke.sh --mu [--no-wc]            # bağlı mu-plugin'leri dener (etkinleştirme yok)
```

Betik `WP_DEBUG_LOG` açar, ön yüz sayfalarını (`/`, `/?s=demo`, WooCommerce açıksa mağaza/sepet/ödeme/hesabım)
çağırır, yönetici bağlamında `admin_menu` ve `admin_init` kancalarını tetikler, cron'u çalıştırır ve
`debug.log` içinde phar dışındaki tüm PHP uyarıları (Warning/Notice/Deprecated/Fatal/Parse), veritabanı hatası, beklenmeyen HTTP durumu (ön yüz 200; ödeme 200/302) veya yönetici kancası hatası varsa
`SMOKE FAIL` ile çıkış kodu 1 verir; temizse `SMOKE OK` ve 0.

**English summary:** the demo site lives outside the repo (`$ERUSOFT_WP_DEMO`, default
`~/code/erusoft-wp-demo`). `tools/link-demo.sh` symlinks plugins and mu-plugins into it;
`tools/smoke.sh <slug> [--no-wc]` or `--mu [--no-wc]` exercises them and fails on any PHP diagnostic
from wp-content, an unexpected HTTP status, a database error or a failing admin hook.

## CI secrets

CI needs two repository secrets (Settings > Secrets and variables > Actions). Neither value is ever committed.

- `SCRUB_HASHES`: the private hash list, newline separated sha256 lines. Set it from the local file:
  `gh secret set SCRUB_HASHES < .private/scrub-hashes.txt`. An empty or missing secret makes the workflows fail.
- `SCRUB_ALLOW_IDENTITY`: the committer identity lines (exact `Name <email>` lines, newline separated) allowed in
  git history, same content as `.private/scrub-allow.txt`: `gh secret set SCRUB_ALLOW_IDENTITY < .private/scrub-allow.txt`.

Pull requests from forks: GitHub does not pass secrets to them, so the `ci` workflow skips the secret
materialisation, `npm run scrub` and `tools/scrub-dist.sh` steps for fork PRs and prints a notice instead (lint,
validation, tests and the build still run). Before merging a fork PR, a maintainer checks out the branch and runs
`npm run scrub`, `npm run build` and `bash tools/scrub-dist.sh` locally (or merges it into a same-repository branch
so CI runs the full gate). Pushes and pull requests from branches of this repository always run every gate.

**Türkçe özet:** CI iki gizli değişkene ihtiyaç duyar: `SCRUB_HASHES` ve `SCRUB_ALLOW_IDENTITY`; ikisi de depoya
girmez. Fork'tan gelen pull request'lerde secret olmadığı için gizlilik kapısı adımları atlanır ve bir uyarı
yazılır; birleştirmeden önce bakımcı `npm run scrub`, `npm run build` ve `bash tools/scrub-dist.sh` komutlarını
yerelde çalıştırır.
