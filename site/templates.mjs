import { esc, slugifyTr, SITE, MONOREPO } from './lib/render.mjs';

export const STATUS = { stable: 'Stabil', beta: 'Beta', 'coming-soon': 'Yakında' };
const WORDMARK = `<svg class="wordmark" viewBox="0 0 148 32" role="img" aria-label="erusoft wp"><text x="0" y="23" class="wm-a">erusoft</text><text x="89" y="23" class="wm-b">/wp</text></svg>`;

export const chip = s => `<span class="chip chip-${s}">${STATUS[s]}</span>`;

export function layout({ title, description, path, body, depth = 0 }) {
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
</head>
<body>
<a class="skip" href="#icerik">İçeriğe geç</a>
<header class="top">
  <div class="wrap top-in">
    <a class="brand" href="/" aria-label="Erusoft WP ana sayfa">${WORDMARK}</a>
    <nav class="top-nav" aria-label="Ana menü">
      <a href="/#katalog">Katalog</a>
      <a href="${MONOREPO}" target="_blank" rel="noopener">GitHub</a>
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
      <p class="foot-note">WordPress ve WooCommerce için açık kaynak eklentiler. GPL-2.0-or-later.</p>
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
</section>`;
  return layout({
    title: 'Erusoft WP — WordPress ve WooCommerce açık kaynak eklentileri',
    description: 'WordPress ve WooCommerce için GPL lisanslı, tek işe odaklı açık kaynak eklentiler kataloğu.',
    path: '/', body
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
  </aside>
</div>`;
  return layout({
    title: `${e.name} — Erusoft WP`,
    description: e.tagline_tr,
    path: `/${e.slug}/`, body
  });
}

export function notFoundPage() {
  return layout({
    title: 'Sayfa bulunamadı — Erusoft WP',
    description: 'Aradığınız sayfa bulunamadı.',
    path: '/404.html',
    body: `<section class="page-head"><div class="wrap"><h1>Sayfa bulunamadı</h1><p class="lead">Adres değişmiş ya da hiç var olmamış olabilir.</p><p><a class="btn btn-primary" href="/">Kataloğa dön</a></p></div></section>`
  });
}
