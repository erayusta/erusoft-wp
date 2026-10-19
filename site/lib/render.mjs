import { marked } from 'marked';
import { posix } from 'node:path';

export const SITE = 'https://wordpress.erusoft.com';
export const MONOREPO = 'https://github.com/erayusta/erusoft-wp';

const TR = { 'İ': 'i', 'I': 'i', 'ı': 'i', 'Ş': 's', 'ş': 's', 'Ğ': 'g', 'ğ': 'g', 'Ç': 'c', 'ç': 'c', 'Ö': 'o', 'ö': 'o', 'Ü': 'u', 'ü': 'u' };
export function slugifyTr(s) {
  return String(s).replace(/[İIıŞşĞğÇçÖöÜü]/g, c => TR[c]).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
}

export const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const attr = (tag, name) => (tag.match(new RegExp(`\\s${name}="([^"]*)"`, 'i')) || [])[1] ?? '';
const unescape = s => s.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&');
const plain = s => unescape(s.replace(/<[^>]*>/g, '')).replace(/\s+/g, ' ').trim();

/**
 * Screenshot markup in a raw HTML chunk: [{repoPath, alt, caption, width}], or null when the chunk
 * holds no <img> or any <img> points outside the product's docs/ files.
 */
function screenshots(html, productDir) {
  const cells = /<td\b/i.test(html) ? html.split(/<td\b[^>]*>/i).slice(1) : html.match(/<img\b[^>]*>/gi) || [];
  const shots = [];
  for (const cell of cells) {
    const tags = cell.match(/<img\b[^>]*>/gi) || [];
    for (const tag of tags) {
      const src = attr(tag, 'src');
      const repoPath = posix.normalize(posix.join(productDir, src));
      if (!src || /^[a-z][a-z0-9+.-]*:|^\/\//i.test(src) || !repoPath.startsWith('docs/') || !/\.(png|jpe?g|webp|gif)$/i.test(repoPath)) return null;
      const sub = tags.length === 1 && (cells.length === 1 ? html : cell).match(/<sub>([\s\S]*?)<\/sub>/i);
      const width = attr(tag, 'width');
      shots.push({ repoPath, alt: unescape(attr(tag, 'alt')), caption: sub ? plain(sub[1]) : '', width: /^\d{1,4}$/.test(width) ? width : '' });
    }
  }
  return shots.length ? shots : null;
}

/**
 * Render a product README to HTML. `size(repoPath)` → {w, h} (optional) lets screenshot galleries
 * give portrait (mobile) shots a narrower column.
 * Relative image links under docs/ are rewritten to ../docs/... and reported in `assets`
 * (repo-relative paths the caller must copy into dist). Other relative links point to GitHub.
 */
export function renderReadme(md, productDir, { size } = {}) {
  const assets = new Set();
  const body = md.replace(/^\s*#\s+.*\n/, '').replace(/<!--[\s\S]*?-->/g, ''); // page has its own h1
  const renderer = new marked.Renderer();
  const seen = new Map();
  let blank = 0;
  renderer.heading = (text, level, raw) => {
    let id = slugifyTr(raw) || `bolum-${++blank}`;
    const n = seen.get(id) || 0;
    seen.set(id, n + 1);
    if (n) id = `${id}-${n + 1}`;
    return `<h${level} id="${id}">${text}</h${level}>\n`;
  };
  // Raw HTML in READMEs is shown as text, never executed. The one exception is screenshot markup
  // (<img> of a docs/ file, optionally in a <table> with a <sub> caption): it is rebuilt from scratch.
  renderer.html = (html, block) => {
    const shots = screenshots(html, productDir);
    if (!shots) return esc(html);
    shots.forEach(s => assets.add(s.repoPath));
    const img = s => `<img src="../${esc(s.repoPath)}" alt="${esc(s.alt)}"${s.width ? ` width="${s.width}"` : ''} loading="lazy">`;
    if (!block) return shots.map(img).join(' ');
    // Landscape (desktop) and portrait (mobile) shots go in separate grids so no row is left half empty.
    const tall = s => { const d = size?.(s.repoPath); return Boolean(d && d.h > d.w * 1.2); };
    const fig = s => `<figure><a href="../${esc(s.repoPath)}" target="_blank" rel="noopener">${img(s)}</a>${s.caption ? `<figcaption>${esc(s.caption)}</figcaption>` : ''}</figure>`;
    const grid = (list, cls) => list.length ? `<div class="${cls}">${list.map(fig).join('')}</div>\n` : '';
    return grid(shots.filter(s => !tall(s)), 'shots') + grid(shots.filter(tall), 'shots shots-tall');
  };
  renderer.image = (href, title, text) => {
    if (/^https?:/.test(href)) return `<img src="${esc(href)}" alt="${esc(text)}" loading="lazy">`;
    const repoPath = posix.normalize(posix.join(productDir, href));
    if (repoPath.startsWith('docs/')) {
      assets.add(repoPath);
      return `<img src="../${esc(repoPath)}" alt="${esc(text)}" loading="lazy">`;
    }
    return esc(text);
  };
  renderer.link = (href, title, text) => {
    let url = href;
    if (/^https?:/.test(href)) {
      return `<a href="${esc(url)}" target="_blank" rel="noopener">${text}</a>`;
    }
    if (href.startsWith('#') || href.startsWith('mailto:')) return `<a href="${esc(href)}">${text}</a>`;
    url = `${MONOREPO}/blob/main/${posix.normalize(posix.join(productDir, href))}`;
    return `<a href="${esc(url)}" target="_blank" rel="noopener">${text}</a>`;
  };
  const html = marked.parse(body, { gfm: true, renderer, async: false });
  return { html, assets: [...assets] };
}

export function catalogTable(entries) {
  const chip = s => ({ stable: 'Stabil', beta: 'Beta', 'coming-soon': 'Yakında' }[s]);
  const order = { stable: 0, beta: 1, 'coming-soon': 2 };
  const rows = [...entries].sort((a, b) => order[a.status] - order[b.status] || a.category.localeCompare(b.category, 'tr') || a.name.localeCompare(b.name, 'tr'));
  const lines = ['| Eklenti | Kategori | Durum |', '|---|---|---|'];
  for (const e of rows) {
    lines.push(`| [${e.name}](${SITE}/${e.slug}/) — ${e.tagline_tr.replace(/\|/g, '\\|')} | ${e.category} | ${chip(e.status)} |`);
  }
  return lines.join('\n');
}
