import { esc, slugifyTr, SITE, MONOREPO } from './lib/render.mjs';

export const SUPPORT_EMAIL = 'hello@erusoft.com';
export const COMPANY_URL = 'https://erusoft.com';
const ISSUES = `${MONOREPO}/issues`;
const mailto = subject => `mailto:${SUPPORT_EMAIL}${subject ? '?subject=' + encodeURIComponent(subject) : ''}`;
const jsonLd = data => `<script type="application/ld+json">${JSON.stringify(data).replace(/</g, '\\u003c')}</script>`;
const ORG = { '@type': 'Organization', name: 'Erusoft', url: COMPANY_URL, email: SUPPORT_EMAIL };

export const STATUS = { stable: 'Stabil', beta: 'Beta', 'coming-soon': 'Yakında' };
const WORDMARK = `<svg class="wordmark" viewBox="0 0 148 32" role="img" aria-label="erusoft wp"><text x="0" y="23" class="wm-a">erusoft</text><text x="89" y="23" class="wm-b">/wp</text></svg>`;

export const chip = s => `<span class="chip chip-${s}">${STATUS[s]}</span>`;

export function layout({ title, description, path, body, ld = null }) {
  const url = SITE + path;
  const full = esc(title);
  return `<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${full}</title>
<meta name="description" content="${esc(description)}">
<link rel="canonical" href="${url}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Erusoft WP">
<meta property="og:title" content="${full}">
<meta property="og:description" content="${esc(description)}">
<meta property="og:url" content="${url}">
<meta property="og:locale" content="tr_TR">
<meta name="twitter:card" content="summary">
<meta name="color-scheme" content="light dark">
<link rel="icon" href="/assets/logo.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&subset=latin-ext&display=swap">
<link rel="stylesheet" href="/assets/site.css">
${ld ? jsonLd(ld) : ''}
</head>
<body>
<a class="skip" href="#icerik">İçeriğe geç</a>
<header class="top">
  <div class="wrap top-in">
    <a class="brand" href="/" aria-label="Erusoft WP ana sayfa">${WORDMARK}</a>
    <nav class="top-nav" aria-label="Ana menü">
      <a href="/#katalog">Katalog</a>
      <a href="/destek/">Destek</a>
      <a href="${COMPANY_URL}" target="_blank" rel="noopener">erusoft.com</a>
      <a class="nav-gh" href="${MONOREPO}" target="_blank" rel="noopener">GitHub</a>
    </nav>
  </div>
</header>
<main id="icerik">
${body}
</main>
<footer class="foot">
  <div class="wrap foot-in">
    <div>
      <p class="foot-title">Erusoft WP</p>
      <p class="foot-note">WordPress ve WooCommerce için açık kaynak eklentiler. GPL-2.0-or-later. Bir <a href="${COMPANY_URL}" target="_blank" rel="noopener">Erusoft</a> projesidir.</p>
    </div>
    <div>
      <p class="foot-title">Destek</p>
      <ul>
        <li><a href="${mailto()}">${SUPPORT_EMAIL}</a></li>
        <li><a href="/destek/">Destek nasıl alınır?</a></li>
        <li><a href="${ISSUES}" target="_blank" rel="noopener">Hata bildir (GitHub)</a></li>
      </ul>
    </div>
    <div>
      <p class="foot-title">Diğer projeler</p>
      <ul>
        <li><a href="https://github.com/erayusta/kinetik" target="_blank" rel="noopener">KİNETİK</a></li>
        <li><a href="https://erusoft.com" target="_blank" rel="noopener">erusoft.com</a></li>
        <li><a href="${MONOREPO}" target="_blank" rel="noopener">GitHub'da kaynak kod</a></li>
      </ul>
    </div>
  </div>
</footer>
<script src="/assets/site.js" defer></script>
</body>
</html>
`;
}

export function card(e) {
  const soon = e.status === 'coming-soon';
  return `<li class="card ${soon ? 'is-soon' : ''}" data-s="${e.slug}" data-cat="${slugifyTr(e.category)}" data-status="${e.status}">
  <a class="card-link" href="/${e.slug}/">
    <span class="card-top">${chip(e.status)}<span class="card-cat">${esc(e.category)}</span></span>
    <h3>${esc(e.name)}</h3>
    <p class="card-desc">${esc(e.tagline_tr)}</p>
  </a>
</li>`;
}

export function indexPage(entries, cats) {
  const count = s => entries.filter(e => e.status === s).length;
  const statuses = [['stable', 'stabil', 'Stabil'], ['beta', 'beta', 'Beta'], ['coming-soon', 'yolda', 'Yakında']].filter(([s]) => count(s) > 0);
  const tally = statuses.map(([s, word]) => `<b>${count(s)}</b> ${word}`).join(' <i></i> ');
  const segs = statuses.map(([s, , label]) => `
      <button type="button" class="seg" data-status="${s}" aria-pressed="false">${label}</button>`).join('');
  const pills = cats.map(c => `<a class="pill" href="/kategori/${c.slug}/" data-cat="${c.slug}">${esc(c.name)} <span>${c.n}</span></a>`).join('');
  const body = `
<section class="hero">
  <div class="wrap">
    <h1>WordPress ve WooCommerce için açık kaynak eklentiler</h1>
    <p class="lead">Küçük, tek işe odaklı ve GPL lisanslı. İndirin, okuyun, değiştirin.</p>
    <form class="finder" role="search" onsubmit="return false">
      <label for="q" class="finder-label">wp plugin search</label>
      <input id="q" type="search" aria-label="Eklenti ara" placeholder="örn. iys, kupon, kargo" autocomplete="off" spellcheck="false">
    </form>
    <p class="tally">${tally}</p>
  </div>
</section>
<section class="wrap catalog" id="katalog">
  <div class="filters">
    <div class="statuses" role="group" aria-label="Durum">
      <button type="button" class="seg is-on" data-status="" aria-pressed="true">Tümü</button>${segs}
    </div>
    <nav class="pills" aria-label="Kategoriler">${pills}</nav>
  </div>
  <p class="empty" hidden>Eşleşen eklenti yok. Aramayı kısaltın veya filtreyi temizleyin.</p>
  <ul class="grid">
${entries.map(card).join('\n')}
  </ul>
</section>
<section class="band" id="neden">
  <div class="wrap">
    <h2>Neden Erusoft WP?</h2>
    <ul class="why">
      <li><h3>Tek iş, sade kod</h3><p>Her eklenti tek bir sorunu çözer. Bu depodaki eklenti ve temalarda reklam, "pro sürüme geç" ekranı ya da gereksiz modül yok; ayarlar Türkçe ve yerinde.</p></li>
      <li><h3>Açık kaynak, GPL</h3><p>Kodun tamamı GitHub'da. Okuyun, değiştirin, ticari sitelerinizde dahil dilediğiniz gibi kullanın.</p></li>
      <li><h3>Türkiye'deki mağazalar için</h3><p>İYS izinleri, il-ilçe-mahalle adresleri, yerel kargo firmaları, bankalar, yemek kartları ve pazaryerleri için hazır entegrasyonlar.</p></li>
      <li><h3>Telemetri yok</h3><p>Bu depodaki eklentiler ve temalar bize veri göndermez; telemetri ve lisans denetimi yoktur. Dış servise yalnızca sizin ayarladığınız entegrasyon bağlanır. İstisnalar (ör. Pluxee'nin kapatılabilir GitHub güncelleme denetimi) ürün sayfasında yazılıdır.</p></li>
    </ul>
  </div>
</section>
<section class="wrap faq" id="sss">
  <h2>Sık sorulanlar</h2>
  <details><summary>Eklentiler ücretsiz mi?</summary><p>Evet. Hepsi GPL-2.0-or-later lisanslıdır; kişisel ve ticari sitelerde ücretsiz kullanabilir, kodunu değiştirebilirsiniz.</p></details>
  <details><summary>Nasıl kurar ve güncellerim?</summary><p>Ürün sayfasından ZIP'i indirin (ayrı depoda tutulan ürünlerde GitHub'dan), WordPress yönetiminde Eklentiler → Yeni ekle → Eklenti yükle ile yükleyip etkinleştirin. Yeni sürüm çıktığında aynı ZIP'i yeniden yükleyin; WordPress mevcut sürümü değiştirmeyi önerir. Eklentiyi silmediğiniz sürece ayarlarınız korunur.</p></details>
  <details><summary>"Beta" ne demek?</summary><p>Beta eklentiler bir dış servise (banka, pazaryeri, kargo, kampanya izni) bağlanır ve o servisin canlı ortamında henüz geniş çapta denenmemiştir. Kod test edilmiştir, ama canlıya almadan önce servisin test ortamında denemenizi öneririz. Sınırlamalar her ürünün sayfasında yazılıdır.</p></details>
  <details><summary>"Yakında" ne demek?</summary><p>Planlanan ama henüz yayımlanmamış ürünlerdir; indirme bağlantısı yoktur. Önceliklendirmemizi istediğiniz biri varsa <a href="${mailto('Yakında: öncelik talebi')}">${SUPPORT_EMAIL}</a> adresine yazın.</p></details>
  <details><summary>Kurulum veya özel geliştirme desteği alabilir miyim?</summary><p>Evet. Kurulum, yapılandırma, mevcut bir eklentiyi işinize göre uyarlama veya yeni entegrasyon için <a href="${mailto('Destek talebi')}">${SUPPORT_EMAIL}</a> adresine yazın. Ayrıntılar <a href="/destek/">destek sayfasında</a>.</p></details>
</section>
<section class="cta">
  <div class="wrap cta-in">
    <div>
      <h2>Aradığınız eklenti burada yok mu?</h2>
      <p>Mağazanıza özel bir entegrasyon, kampanya kuralı ya da iş akışı mı gerekiyor? Anlatın, birlikte bakalım.</p>
    </div>
    <div class="cta-actions">
      <a class="btn btn-primary" href="${mailto('Özel geliştirme talebi')}">${SUPPORT_EMAIL}</a>
      <a class="btn btn-onink" href="${COMPANY_URL}" target="_blank" rel="noopener">erusoft.com</a>
    </div>
  </div>
</section>`;
  return layout({
    title: 'Erusoft WP — WordPress ve WooCommerce açık kaynak eklentileri',
    description: 'WordPress ve WooCommerce için GPL lisanslı, tek işe odaklı açık kaynak eklentiler kataloğu. Kurulum ve özel geliştirme desteği: hello@erusoft.com.',
    path: '/', body,
    ld: [
      { '@context': 'https://schema.org', ...ORG },
      { '@context': 'https://schema.org', '@type': 'WebSite', name: 'Erusoft WP', url: SITE + '/', publisher: ORG }
    ]
  });
}

export function categoryPage(cat, list, cats) {
  const pills = cats.map(c => `<a class="pill ${c.slug === cat.slug ? 'is-on' : ''}" href="/kategori/${c.slug}/">${esc(c.name)} <span>${c.n}</span></a>`).join('');
  const body = `
<section class="page-head"><div class="wrap">
  <p class="crumb"><a href="/">Katalog</a> / ${esc(cat.name)}</p>
  <h1>${esc(cat.name)}</h1>
  <p class="lead">${list.length} eklenti</p>
</div></section>
<section class="wrap catalog">
  <nav class="pills" aria-label="Kategoriler">${pills}</nav>
  <ul class="grid">
${list.map(card).join('\n')}
  </ul>
</section>`;
  return layout({
    title: `${cat.name} eklentileri — Erusoft WP`,
    description: `${cat.name} kategorisindeki açık kaynak WordPress ve WooCommerce eklentileri.`,
    path: `/kategori/${cat.slug}/`, body
  });
}

export function productPage(e, readmeHtml) {
  const soon = e.status === 'coming-soon';
  const hasZip = e.status !== 'coming-soon' && !!e.path;
  const ghUrl = e.repo || (e.path ? `${MONOREPO}/tree/main/${e.path}` : null);
  const req = [['WordPress', e.requires.wp + '+'], ['PHP', e.requires.php + '+']];
  if (e.requires.wc) req.splice(1, 0, ['WooCommerce', e.requires.wc + '+']);
  const side = [];
  if (hasZip) side.push(`<a class="btn btn-primary" href="/downloads/${e.slug}.zip" download>ZIP indir${e.version ? ` <small>v${esc(e.version)}</small>` : ''}</a>`);
  if (hasZip && e.type === 'mu-plugin') side.push(`<p class="side-note"><strong>Eklentiler → Yeni ekle ile yüklemeyin.</strong> ZIP'i açın ve <code>.php</code> dosyalarını doğrudan <code>wp-content/mu-plugins/</code> klasörüne (en üst düzeye, alt klasör olmadan) kopyalayın; etkinleştirme gerekmez.</p>`);
  if (hasZip && e.type === 'theme') side.push(`<p class="side-note"><strong>Görünüm → Temalar → Yeni ekle → Tema yükle</strong> ile ZIP'i yükleyip etkinleştirin.${e.requires.wc ? ' WooCommerce eklentisi gereklidir.' : ''}</p>`);
  if (soon) side.push(`<p class="soon-note">Yakında. Bu eklenti henüz yayımlanmadı.</p>`);
  else if (ghUrl) side.push(`<a class="btn ${hasZip ? 'btn-ghost' : 'btn-primary'}" href="${esc(ghUrl)}" target="_blank" rel="noopener">GitHub'da gör</a>`);
  const main = readmeHtml
    ? `<div class="prose">${readmeHtml}</div>`
    : `<div class="prose"><h2 id="ozellikler">Özellikler</h2><ul>${e.features_tr.map(f => `<li>${esc(f)}</li>`).join('')}</ul></div>`;
  const body = `
<section class="page-head"><div class="wrap">
  <p class="crumb"><a href="/">Katalog</a> / <a href="/kategori/${slugifyTr(e.category)}/">${esc(e.category)}</a></p>
  <h1>${esc(e.name)}</h1>
  <p class="lead">${esc(e.tagline_tr)}</p>
  <p class="lead-en" lang="en">${esc(e.tagline_en)}</p>
</div></section>
<div class="wrap product">
  <article>${main}</article>
  <aside class="side" aria-label="Eklenti bilgileri">
    <div class="side-card">
      <p class="side-status">${chip(e.status)}</p>
      ${side.join('\n')}
      <dl>
        ${e.version && !soon ? `<dt>Sürüm</dt><dd>${esc(e.version)}</dd>` : ''}
        <dt>Kategori</dt><dd><a href="/kategori/${slugifyTr(e.category)}/">${esc(e.category)}</a></dd>
        ${req.map(([k, v]) => `<dt>${k}</dt><dd>${esc(v)}</dd>`).join('')}
        ${e.integrations.length ? `<dt>Entegrasyonlar</dt><dd>${e.integrations.map(esc).join(', ')}</dd>` : ''}
        <dt>Lisans</dt><dd>GPL-2.0-or-later</dd>
      </dl>
    </div>
    <div class="side-card side-help">
      <p class="side-help-title">Yardım mı lazım?</p>
      <p>Kurulum, yapılandırma veya mağazanıza özel uyarlama için bize yazın.</p>
      <a class="btn btn-ghost" href="${mailto(e.name + ' hakkında')}">${SUPPORT_EMAIL}</a>
      <p class="side-help-more"><a href="/destek/">Destek nasıl alınır?</a>${soon ? '' : ` · <a href="${esc(e.repo && !e.path ? e.repo + '/issues' : ISSUES)}" target="_blank" rel="noopener">Hata bildir</a>`}</p>
    </div>
  </aside>
</div>`;
  const ld = {
    '@context': 'https://schema.org', '@type': 'SoftwareApplication',
    name: e.name, description: e.tagline_tr, url: `${SITE}/${e.slug}/`,
    applicationCategory: 'BusinessApplication', operatingSystem: 'WordPress',
    license: 'https://www.gnu.org/licenses/gpl-2.0.html', author: ORG
  };
  if (e.version && !soon) ld.softwareVersion = e.version;
  if (hasZip) { ld.downloadUrl = `${SITE}/downloads/${e.slug}.zip`; ld.offers = { '@type': 'Offer', price: '0', priceCurrency: 'TRY' }; }
  return layout({
    title: `${e.name} — Erusoft WP`,
    description: e.tagline_tr,
    path: `/${e.slug}/`, body, ld
  });
}

export function supportPage() {
  const body = `
<section class="page-head"><div class="wrap">
  <p class="crumb"><a href="/">Katalog</a> / Destek</p>
  <h1>Destek</h1>
  <p class="lead">Kurulumda takıldınız, bir hata buldunuz ya da mağazanıza özel bir şey mi gerekiyor? Doğru kanal aşağıda.</p>
</div></section>
<div class="wrap product">
  <article class="prose">
    <h2 id="belgeler">1. Önce ürün sayfasına bakın</h2>
    <p>Her ürün sayfasında <strong>Kurulum</strong>, <strong>Sınırlamalar</strong> ve <strong>SSS</strong> bölümleri, çoğunda <strong>Ayarlar</strong> da var. Sık karşılaşılan durumların çoğu orada yanıtlanıyor. Aramak için <a href="/#katalog">kataloğu</a> kullanın.</p>
    <h2 id="hata-bildirimi">2. Hata bildirin</h2>
    <p>Bir eklenti beklendiği gibi çalışmıyorsa <a href="${ISSUES}" target="_blank" rel="noopener">GitHub Issues</a> üzerinden bildirin. Hızlı çözüm için şunları yazın:</p>
    <ul>
      <li>Eklentinin adı ve sürümü</li>
      <li>WordPress, WooCommerce ve PHP sürümleri, kullandığınız tema</li>
      <li>Hatayı yeniden üreten adımlar; beklediğiniz ve gördüğünüz sonuç</li>
      <li>Varsa <code>wp-content/debug.log</code> içindeki ilgili satırlar</li>
    </ul>
    <h2 id="e-posta">3. Bize yazın</h2>
    <p>Kurulum ve yapılandırma yardımı, mevcut bir eklentiyi işinize göre uyarlama, yeni bir entegrasyon ya da size özel eklenti için <a href="${mailto('Destek talebi')}"><strong>${SUPPORT_EMAIL}</strong></a> adresine e-posta gönderin. Erusoft'u ve diğer çalışmalarımızı <a href="${COMPANY_URL}" target="_blank" rel="noopener">erusoft.com</a> adresinde bulabilirsiniz.</p>
    <h2 id="guvenlik">Güvenlik açığı bildirimi</h2>
    <p>Bir güvenlik açığı bulduysanız lütfen herkese açık issue açmayın. Ayrıntıları konu satırına "Güvenlik" yazarak <a href="${mailto('Güvenlik bildirimi')}">${SUPPORT_EMAIL}</a> adresine gönderin; düzeltme yayımlanana kadar bilgiyi gizli tutarız.</p>
    <h2 id="gizlilik">Lütfen göndermeyin</h2>
    <p>Şifre, API anahtarı, banka/pazaryeri bilgileri veya müşteri verisi (ad, telefon, TCKN, adres, sipariş dökümü) paylaşmayın. Ekran görüntülerindeki kişisel verileri karartın. Erişim gerekirse güvenli bir yolu birlikte belirleriz.</p>
  </article>
  <aside class="side" aria-label="İletişim">
    <div class="side-card">
      <p class="side-help-title">İletişim</p>
      <a class="btn btn-primary" href="${mailto('Destek talebi')}">${SUPPORT_EMAIL}</a>
      <a class="btn btn-ghost" href="${ISSUES}" target="_blank" rel="noopener">Hata bildir (GitHub)</a>
      <a class="btn btn-ghost" href="${COMPANY_URL}" target="_blank" rel="noopener">erusoft.com</a>
    </div>
  </aside>
</div>`;
  return layout({
    title: 'Destek — Erusoft WP',
    description: 'Erusoft WP eklentileri için destek: belgeler, hata bildirimi, kurulum ve özel geliştirme için hello@erusoft.com.',
    path: '/destek/', body,
    ld: { '@context': 'https://schema.org', '@type': 'ContactPage', url: SITE + '/destek/', about: ORG }
  });
}

export function notFoundPage() {
  return layout({
    title: 'Sayfa bulunamadı — Erusoft WP',
    description: 'Aradığınız sayfa bulunamadı.',
    path: '/404.html',
    body: `<section class="page-head"><div class="wrap"><h1>Sayfa bulunamadı</h1><p class="lead">Adres değişmiş ya da hiç var olmamış olabilir.</p><p><a class="btn btn-primary" href="/">Kataloğa dön</a></p><p class="nf-help">Aradığınızı bulamadıysanız <a href="${mailto('Sayfa bulunamadı')}">${SUPPORT_EMAIL}</a> adresine yazın.</p></div></section>`
  });
}
