import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, copyFileSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { canonicalManifest, parseManifest } from '../tools/lib/scrub-manifest.mjs';

// pin-data.mjs writes next to itself: every test runs a copy inside a throw-away tree.
function sandbox() {
  const root = mkdtempSync(join(tmpdir(), 'pin-'));
  mkdirSync(join(root, 'tools/lib'), { recursive: true });
  copyFileSync(resolve('tools/pin-data.mjs'), join(root, 'tools/pin-data.mjs'));
  copyFileSync(resolve('tools/lib/scrub-manifest.mjs'), join(root, 'tools/lib/scrub-manifest.mjs'));
  mkdirSync(join(root, 'plugins/demo/data'), { recursive: true });
  writeFileSync(join(root, 'plugins/demo/data/a.txt'), 'Demo Mah.\n');
  mkdirSync(join(root, 'plugins/demo/includes'), { recursive: true });
  writeFileSync(join(root, 'plugins/demo/includes/x.txt'), 'kod\n');
  return root;
}
const pin = (root, ...args) => spawnSync('node', [join(root, 'tools/pin-data.mjs'), ...args], { cwd: root, encoding: 'utf8' });
const sha = s => createHash('sha256').update(s).digest('hex');

test('pin-data: no arguments → usage, exit 2', () => {
  const r = pin(sandbox());
  assert.equal(r.status, 2);
  assert.match(r.stderr, /usage/);
});

test('pin-data: * outside the last segment → exit 2', () => {
  const r = pin(sandbox(), 'plugins/*/data/a.txt');
  assert.equal(r.status, 2);
  assert.match(r.stderr, /only allowed in the last segment/);
});

test('pin-data: missing directory → exit 2', () => {
  const r = pin(sandbox(), 'plugins/yok/data/*.txt');
  assert.equal(r.status, 2);
  assert.match(r.stderr, /no such directory/);
});

test('pin-data: nothing matches → exit 2', () => {
  const r = pin(sandbox(), 'plugins/demo/data/*.json');
  assert.equal(r.status, 2);
  assert.match(r.stderr, /nothing matches/);
});

test('pin-data: a file outside <slug>/data cannot be pinned → exit 2', () => {
  const r = pin(sandbox(), 'plugins/demo/includes/*.txt');
  assert.equal(r.status, 2);
  assert.match(r.stderr, /can be pinned/);
});

test('pin-data: an invalid existing manifest is not overwritten → exit 2', () => {
  const root = sandbox();
  writeFileSync(join(root, 'tools/scrub-data-manifest.json'), '{"files": []}');
  const r = pin(root, 'plugins/demo/data/*.txt');
  assert.equal(r.status, 2);
  assert.match(r.stderr, /existing manifest rejected/);
  assert.equal(readFileSync(join(root, 'tools/scrub-data-manifest.json'), 'utf8'), '{"files": []}');
});

test('pin-data: success writes the canonical manifest, exit 0', () => {
  const root = sandbox();
  const r = pin(root, 'plugins/demo/data/*.txt');
  assert.equal(r.status, 0, r.stderr);
  assert.equal(readFileSync(join(root, 'tools/scrub-data-manifest.json'), 'utf8'), canonicalManifest([{ path: 'plugins/demo/data/a.txt', sha256: sha('Demo Mah.\n') }]));
});

test('manifest: sha256 must be a string (an array that stringifies to hex is rejected)', () => {
  const raw = canonicalManifest([{ path: 'plugins/demo/data/a.txt', sha256: [sha('x')] }]);
  assert.notEqual(parseManifest(raw).error, '');
});
