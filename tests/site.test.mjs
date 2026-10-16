import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, writeFileSync, copyFileSync, existsSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { build, slugifyTr, updateReadme } from '../site/build.mjs';
import { renderReadme } from '../site/lib/render.mjs';
import { indexPage } from '../site/templates.mjs';
test('slugifyTr', () => assert.equal(slugifyTr('İçerik & AI'), 'icerik-ai'));
test('builds every catalogue page', async () => {
  const out = mkdtempSync(join(tmpdir(), 'site-'));
  const r = await build({ root: process.cwd(), out });
  const cat = JSON.parse(readFileSync('catalog.json', 'utf8'));
  for (const e of cat) assert.ok(existsSync(join(out, e.slug, 'index.html')), e.slug);
  const soon = cat.find(e => e.status === 'coming-soon');
  const html = readFileSync(join(out, soon.slug, 'index.html'), 'utf8');
  assert.match(html, /Yakında/);
  assert.doesNotMatch(html, /downloads\//);
  assert.doesNotMatch(html, /<img/);
  const stable = cat.find(e => e.status === 'stable' && e.path);
  assert.ok(existsSync(join(out, 'downloads', stable.slug + '.zip')));
  assert.equal(readFileSync(join(out, 'CNAME'), 'utf8').trim(), 'wordpress.erusoft.com');
  assert.ok(r.pages >= cat.length);
});
test('link-only entry has GitHub button and no ZIP', async () => {
  const out = mkdtempSync(join(tmpdir(), 'site-'));
  await build({ root: process.cwd(), out });
  const cat = JSON.parse(readFileSync('catalog.json', 'utf8'));
  const e = cat.find(x => x.status === 'stable' && x.repo && !x.path);
  const html = readFileSync(join(out, e.slug, 'index.html'), 'utf8');
  assert.match(html, /GitHub'da gör/);
  assert.doesNotMatch(html, /downloads\//);
  assert.ok(!existsSync(join(out, 'downloads', e.slug + '.zip')));
});
test('README images are copied and rewritten', async () => {
  const out = mkdtempSync(join(tmpdir(), 'site-'));
  await build({ root: process.cwd(), out });
  const slug = 'product-ribbons-for-woocommerce';
  const html = readFileSync(join(out, slug, 'index.html'), 'utf8');
  const m = html.match(/<img src="\.\.\/(docs\/[^"]+)"/);
  assert.ok(m, 'image rewritten');
  assert.ok(existsSync(join(out, m[1])));
});
test('search index folds Turkish', async () => {
  const out = mkdtempSync(join(tmpdir(), 'site-'));
  await build({ root: process.cwd(), out });
  const idx = JSON.parse(readFileSync(join(out, 'search.json'), 'utf8'));
  assert.ok(idx.some(x => x.t.includes('iys')), 'İYS must be searchable as iys');
});

test('raw HTML in a README is escaped, not executed', () => {
  const { html } = renderReadme('# T\n\nHi <script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\ntext <b onclick=x>y</b>', 'plugins/x');
  assert.doesNotMatch(html, /<script|<img|<b /);
  assert.match(html, /&lt;script&gt;/);
});
test('headings get unique ids; empty headings get bolum-N', () => {
  const { html } = renderReadme('# T\n\n## SSS\n\n## SSS\n\n## ???\n', 'plugins/x');
  assert.match(html, /id="sss"/);
  assert.match(html, /id="sss-2"/);
  assert.match(html, /id="bolum-1"/);
});
test('relative non-image links point to the GitHub blob URL', () => {
  const { html } = renderReadme('# T\n\n[x](CHANGELOG.md) [y](https://example.org)', 'plugins/foo');
  assert.match(html, /href="https:\/\/github\.com\/[^"]+\/blob\/main\/plugins\/foo\/CHANGELOG\.md" target="_blank" rel="noopener"/);
  assert.match(html, /href="https:\/\/example\.org" target="_blank" rel="noopener"/);
});
test('404, sitemap, robots exist; every released entry with a path gets a ZIP; mu-plugin note shown', async () => {
  const out = mkdtempSync(join(tmpdir(), 'site-'));
  await build({ root: process.cwd(), out });
  for (const f of ['404.html', 'sitemap.xml', 'robots.txt', 'catalog.json']) assert.ok(existsSync(join(out, f)), f);
  assert.match(readFileSync(join(out, 'sitemap.xml'), 'utf8'), /<loc>https:\/\/wordpress\.erusoft\.com\/<\/loc>/);
  const cat = JSON.parse(readFileSync('catalog.json', 'utf8'));
  const released = cat.filter(e => e.status !== 'coming-soon' && e.path);
  assert.ok(released.length > 0);
  for (const e of released) assert.ok(existsSync(join(out, 'downloads', e.slug + '.zip')), e.slug);
  const mu = cat.find(e => e.type === 'mu-plugin' && e.path);
  const muHtml = readFileSync(join(out, mu.slug, 'index.html'), 'utf8');
  assert.match(muHtml, /wp-content\/mu-plugins\//);
  assert.match(muHtml, /Eklentiler → Yeni ekle ile yüklemeyin/);
});
test('status tally and filter buttons hide zero-count statuses', () => {
  const entry = (slug, status) => ({ slug, name: slug, category: 'Test', tagline_tr: 't', status });
  const html = indexPage([entry('a', 'stable'), entry('b', 'coming-soon')], []);
  assert.doesNotMatch(html, /data-status="beta"/);
  assert.doesNotMatch(html, /<b>0<\/b>/);
  assert.match(html, /data-status="stable"/);
  assert.match(html, /data-status="coming-soon"/);
  const withBeta = indexPage([entry('a', 'stable'), entry('c', 'beta')], []);
  assert.match(withBeta, /<b>1<\/b> beta/);
  assert.match(withBeta, /data-status="beta"/);
  assert.doesNotMatch(withBeta, /data-status="coming-soon"/);
});
test('--readme replaces only the marked region', () => {
  const root = mkdtempSync(join(tmpdir(), 'rd-'));
  copyFileSync('catalog.json', join(root, 'catalog.json'));
  writeFileSync(join(root, 'README.md'), 'before\n<!-- catalog:start -->\nOLD\n<!-- catalog:end -->\nafter\n');
  updateReadme(root);
  const md = readFileSync(join(root, 'README.md'), 'utf8');
  assert.match(md, /^before\n<!-- catalog:start -->\n\| Eklenti/);
  assert.match(md, /<!-- catalog:end -->\nafter\n$/);
  assert.doesNotMatch(md, /OLD/);
  writeFileSync(join(root, 'README.md'), 'no markers');
  assert.throws(() => updateReadme(root), /markers/);
});

test('theme ZIP excludes top-level demo/ only; product page explains theme install', async () => {
  const root = mkdtempSync(join(tmpdir(), 'theme-root-'));
  const t = join(root, 'themes', 'demo-theme');
  for (const d of ['demo/images', 'template-parts/demo']) mkdirSync(join(t, d), { recursive: true });
  writeFileSync(join(t, 'style.css'), '/* Theme Name: Demo */');
  writeFileSync(join(t, 'index.php'), '<?php\n');
  writeFileSync(join(t, 'README.md'), '# Demo');
  writeFileSync(join(t, 'demo/setup.php'), '<?php\n');
  writeFileSync(join(t, 'demo/images/a.jpg'), 'x');
  writeFileSync(join(t, 'template-parts/demo/x.php'), '<?php\n');
  const pl = join(root, 'plugins', 'demo-plugin');
  mkdirSync(join(pl, 'demo'), { recursive: true });
  writeFileSync(join(pl, 'demo-plugin.php'), '<?php\n');
  writeFileSync(join(pl, 'README.md'), '# Demo plugin');
  writeFileSync(join(pl, 'demo/data.php'), '<?php\n');
  copyFileSync('CNAME', join(root, 'CNAME'));
  writeFileSync(join(root, 'catalog.json'), JSON.stringify([{ slug: 'demo-theme', name: 'Demo Theme', type: 'theme', category: 'Tema', tagline_tr: 'x', tagline_en: 'x', features_tr: ['a', 'b'], requires: { wp: '6.5', php: '8.0', wc: '9.0' }, integrations: [], status: 'stable', phase: 4, repo: null, path: 'themes/demo-theme', screenshots: [], version: '1.0.0' },
    { slug: 'demo-plugin', name: 'Demo Plugin', type: 'plugin', category: 'Güvenlik', tagline_tr: 'x', tagline_en: 'x', features_tr: ['a', 'b'], requires: { wp: '6.5', php: '8.0' }, integrations: [], status: 'stable', phase: 4, repo: null, path: 'plugins/demo-plugin', screenshots: [], version: '1.0.0' }]));
  const out = join(root, 'dist');
  await build({ root, out });
  const list = execFileSync('unzip', ['-Z1', join(out, 'downloads', 'demo-theme.zip')], { encoding: 'utf8' }).split('\n');
  assert.ok(list.includes('demo-theme/style.css'));
  assert.ok(list.includes('demo-theme/template-parts/demo/x.php'));
  assert.ok(!list.some(l => l.startsWith('demo-theme/demo/') || l === 'demo-theme/demo'), list.join());
  const plist = execFileSync('unzip', ['-Z1', join(out, 'downloads', 'demo-plugin.zip')], { encoding: 'utf8' }).split('\n');
  assert.ok(plist.includes('demo-plugin/demo/data.php'), 'plugins keep their demo/ folder');
  const html = readFileSync(join(out, 'demo-theme', 'index.html'), 'utf8');
  assert.match(html, /Görünüm → Temalar/);
  assert.match(html, /WooCommerce eklentisi gereklidir/);
});

test('support page, support e-mail and erusoft.com links are on every page type', async () => {
  const out = mkdtempSync(join(tmpdir(), 'site-'));
  await build({ root: process.cwd(), out });
  const support = readFileSync(join(out, 'destek', 'index.html'), 'utf8');
  assert.match(support, /href="mailto:hello@erusoft\.com\?subject=/);
  assert.match(support, /github\.com\/erayusta\/erusoft-wp\/issues/);
  assert.match(readFileSync(join(out, 'sitemap.xml'), 'utf8'), /<loc>https:\/\/wordpress\.erusoft\.com\/destek\/<\/loc>/);
  const cat = JSON.parse(readFileSync('catalog.json', 'utf8'));
  for (const e of cat) {
    const html = readFileSync(join(out, e.slug, 'index.html'), 'utf8');
    assert.match(html, /href="mailto:hello@erusoft\.com\?subject=[^"]+"/, e.slug);
    assert.match(html, /href="https:\/\/erusoft\.com"/, e.slug);
    const ld = JSON.parse(html.match(/<script type="application\/ld\+json">(.*?)<\/script>/)[1]);
    assert.equal(ld.name, e.name);
  }
  const home = readFileSync(join(out, 'index.html'), 'utf8');
  assert.match(home, /href="\/destek\/"/);
  assert.match(home, /id="sss"/);
});
test('JSON-LD cannot break out of its script tag', async () => {
  const { productPage } = await import('../site/templates.mjs');
  const html = productPage({ slug: 'x', name: 'X </script><script>alert(1)</script>', type: 'plugin', category: 'C', tagline_tr: 't', tagline_en: 't', features_tr: [], requires: { wp: '6.5', php: '8.0' }, integrations: [], status: 'stable', path: 'plugins/x', version: '1.0.0' }, null);
  assert.doesNotMatch(html.match(/<script type="application\/ld\+json">[\s\S]*?<\/script>/)[0].slice(35, -9), /<\/script>/);
});
test('link-only released entries render docs/about/<slug>.md', async () => {
  const out = mkdtempSync(join(tmpdir(), 'site-'));
  await build({ root: process.cwd(), out });
  const cat = JSON.parse(readFileSync('catalog.json', 'utf8'));
  for (const e of cat.filter(x => !x.path && x.status !== 'coming-soon')) {
    assert.ok(existsSync(join('docs', 'about', e.slug + '.md')), e.slug);
    const html = readFileSync(join(out, e.slug, 'index.html'), 'utf8');
    assert.match(html, /<h2 id="destek">/, e.slug);
  }
});
