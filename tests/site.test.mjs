import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, writeFileSync, copyFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
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
