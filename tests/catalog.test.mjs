import { test } from 'node:test';
import assert from 'node:assert/strict';
import { validateCatalog, loadCatalog } from '../tools/lib/catalog.mjs';
import { mkdtempSync, mkdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const ok = { slug: 'a-for-woocommerce', name: 'A for WooCommerce', type: 'plugin', category: 'Güvenlik', tagline_tr: 'x', tagline_en: 'x', features_tr: ['a', 'b'], requires: { wp: '6.5', php: '8.0' }, integrations: [], status: 'coming-soon', phase: 2, repo: null, path: null, screenshots: [], version: '1.0.0' };

test('valid entry passes', () => assert.deepEqual(validateCatalog([ok]), []));
test('non-ascii slug rejected', () => assert.match(validateCatalog([{ ...ok, slug: 'iys-ayar-İ' }])[0], /slug/));
test('duplicate slug rejected', () => assert.match(validateCatalog([ok, ok]).join(), /duplicate/));
test('stable needs path or repo', () => assert.match(validateCatalog([{ ...ok, status: 'stable' }])[0], /path or repo/));
test('stable path must exist with README', () => assert.match(validateCatalog([{ ...ok, status: 'stable', path: 'plugins/nope' }])[0], /README/));
test('unknown category rejected', () => assert.match(validateCatalog([{ ...ok, category: 'X' }])[0], /category/));
test('overlong tagline rejected', () => assert.match(validateCatalog([{ ...ok, tagline_tr: 'x'.repeat(121) }])[0], /tagline/));
test('real catalog valid', () => assert.deepEqual(validateCatalog(loadCatalog('catalog.json')), []));
test('merged products have a single entry', () => {
  const cat = loadCatalog('catalog.json');
  const names = cat.map(e => e.name);
  for (const gone of ['Turkish Address Selector for WooCommerce', 'Review Importer for Trendyol', 'Marketplace Stock Bridge for WooCommerce']) assert.ok(!names.includes(gone), gone);
  for (const kept of ['Turkish Address Fields for WooCommerce', 'Trendyol Reviews for WooCommerce', 'Marketplace Orders for WooCommerce', 'High-Traffic Fixes for WooCommerce']) assert.ok(names.includes(kept), kept);
});
test('individual pack mu-plugins have no own entry', () => {
  const names = loadCatalog('catalog.json').map(e => e.name);
  for (const n of ['Action Scheduler Sleep Tuning', 'AutomateWoo Redis Flush Fix', 'Cart Fragments Guard', 'Checkout Login Gate Event Guard', 'Conditional Category Rewrite Flush', 'Frontend Cloudflare Purge Blocker', 'Frontend Plugin Diet', 'Non-Blocking IndexNow', 'Order Status Webhook Topics', 'PixelYourSite Fragment Fix', 'Redis Flush Tracer']) assert.ok(!names.includes(n), n);
});

const themeEntry = { ...ok, slug: 'x-theme', name: 'X Theme', type: 'theme', category: 'Tema', status: 'stable', path: 'themes/x' };
const themeRoot = (style = true) => {
  const r = mkdtempSync(join(tmpdir(), 'cat-'));
  mkdirSync(join(r, 'themes/x'), { recursive: true });
  writeFileSync(join(r, 'themes/x/README.md'), '# x');
  if (style) writeFileSync(join(r, 'themes/x/style.css'), '/* Theme Name: X */');
  return r;
};
test('theme with style.css passes', () => assert.deepEqual(validateCatalog([themeEntry], themeRoot()), []));
test('stable theme needs style.css', () => assert.match(validateCatalog([themeEntry], themeRoot(false)).join(), /style\.css/));
test('theme path must start with themes/', () => assert.match(validateCatalog([{ ...themeEntry, path: 'plugins/x' }], themeRoot()).join(), /themes\//));
test('path with .. segments rejected', () => assert.match(validateCatalog([{ ...themeEntry, path: 'themes/../plugins/x' }], themeRoot()).join(), /\.\./));
test('plugin path must not start with themes/', () => assert.match(validateCatalog([{ ...themeEntry, type: 'plugin' }], themeRoot()).join(), /themes\//));
