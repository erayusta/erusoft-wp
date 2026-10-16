import { readFileSync, writeFileSync, mkdirSync, rmSync, cpSync, existsSync, mkdtempSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { loadCatalog, validateCatalog } from '../tools/lib/catalog.mjs';
import { normalize } from '../tools/lib/scrub-core.mjs';
import { renderReadme, slugifyTr, catalogTable, SITE } from './lib/render.mjs';
import { indexPage, categoryPage, productPage, supportPage, notFoundPage } from './templates.mjs';

export { slugifyTr };

const here = dirname(fileURLToPath(import.meta.url));
const ZIP_DENY = /(^|\/)(tests|node_modules|\.git|\.github|\.DS_Store|\.editorconfig|\.gitattributes|phpunit\.xml[^/]*|composer\.(json|lock)|[^/]*\.log)(\/|$)/;
const THEME_DENY = /^\/demo(\/|$)/i;
const order = { stable: 0, beta: 1, 'coming-soon': 2 };

const write = (out, rel, data) => {
  const f = join(out, rel);
  mkdirSync(dirname(f), { recursive: true });
  writeFileSync(f, data);
};

function zipProduct(root, out, e) {
  const stage = mkdtempSync(join(tmpdir(), 'erusoft-zip-'));
  try {
    cpSync(join(root, e.path), join(stage, e.slug), {
      recursive: true,
      filter: src => { const rel = src.slice(join(root, e.path).length); return !ZIP_DENY.test(rel) && !(e.type === 'theme' && THEME_DENY.test(rel)); }
    });
    mkdirSync(join(out, 'downloads'), { recursive: true });
    const zipPath = resolve(out, 'downloads', e.slug + '.zip');
    rmSync(zipPath, { force: true });
    execFileSync('zip', ['-qrX', zipPath, e.slug], { cwd: stage });
  } finally {
    rmSync(stage, { recursive: true, force: true });
  }
}

export async function build({ root = process.cwd(), out = 'site/dist' } = {}) {
  root = resolve(root);
  out = resolve(root, out);
  const entries = loadCatalog(join(root, 'catalog.json'));
  const errs = validateCatalog(entries, root);
  if (errs.length) throw new Error('catalog invalid:\n' + errs.join('\n'));
  rmSync(out, { recursive: true, force: true });
  mkdirSync(out, { recursive: true });

  const sorted = [...entries].sort((a, b) => order[a.status] - order[b.status] || a.name.localeCompare(b.name, 'tr'));
  const catNames = [...new Set(entries.map(e => e.category))].sort((a, b) => a.localeCompare(b, 'tr'));
  const cats = catNames.map(name => ({ name, slug: slugifyTr(name), n: entries.filter(e => e.category === name).length }));

  let pages = 0, zips = 0;
  const urls = ['/'];
  write(out, 'index.html', indexPage(sorted, cats)); pages++;

  const docAssets = new Set();
  for (const e of sorted) {
    let html = null;
    // Released entries whose code lives in another repo get their page text from docs/about/<slug>.md.
    const [readme, dir] = e.path ? [join(e.path, 'README.md'), e.path] : [join('docs/about', e.slug + '.md'), 'docs/about'];
    if (existsSync(join(root, readme))) {
      const r = renderReadme(readFileSync(join(root, readme), 'utf8'), dir);
      html = r.html;
      r.assets.forEach(a => docAssets.add(a));
    }
    write(out, `${e.slug}/index.html`, productPage(e, html)); pages++;
    urls.push(`/${e.slug}/`);
    if (e.status !== 'coming-soon' && e.path) { zipProduct(root, out, e); zips++; }
  }
  for (const a of docAssets) {
    if (existsSync(join(root, a))) cpSync(join(root, a), join(out, a));
  }
  for (const c of cats) {
    write(out, `kategori/${c.slug}/index.html`, categoryPage(c, sorted.filter(e => e.category === c.name), cats)); pages++;
    urls.push(`/kategori/${c.slug}/`);
  }
  write(out, 'destek/index.html', supportPage()); pages++;
  urls.push('/destek/');
  write(out, '404.html', notFoundPage()); pages++;

  write(out, 'search.json', JSON.stringify(entries.map(e => ({
    s: e.slug,
    t: normalize([e.name, e.tagline_tr, e.tagline_en, ...e.features_tr, e.category, ...e.integrations].join(' '))
  }))));
  cpSync(join(root, 'catalog.json'), join(out, 'catalog.json'));
  cpSync(join(root, 'CNAME'), join(out, 'CNAME'));
  cpSync(join(here, 'assets'), join(out, 'assets'), { recursive: true });
  write(out, 'robots.txt', `User-agent: *\nAllow: /\n\nSitemap: ${SITE}/sitemap.xml\n`);
  write(out, 'sitemap.xml', `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls.map(u => `  <url><loc>${SITE}${u}</loc></url>`).join('\n')}\n</urlset>\n`);
  return { pages, zips };
}

export function updateReadme(root = process.cwd()) {
  const f = join(root, 'README.md');
  const entries = loadCatalog(join(root, 'catalog.json'));
  const src = readFileSync(f, 'utf8');
  const re = /<!-- catalog:start -->[\s\S]*?<!-- catalog:end -->/;
  if (!re.test(src)) throw new Error('README.md has no catalog markers');
  writeFileSync(f, src.replace(re, `<!-- catalog:start -->\n${catalogTable(entries)}\n<!-- catalog:end -->`));
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  if (process.argv.includes('--readme')) { updateReadme(); console.log('README.md catalogue table updated'); }
  else { const r = await build(); console.log(`built ${r.pages} pages, ${r.zips} zips -> site/dist`); }
}
