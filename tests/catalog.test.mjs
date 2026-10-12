import { test } from 'node:test';
import assert from 'node:assert/strict';
import { validateCatalog, loadCatalog } from '../tools/lib/catalog.mjs';

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
