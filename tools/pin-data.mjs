#!/usr/bin/env node
// Pins public data files for the privacy gate: writes their sha256 into tools/scrub-data-manifest.json.
// A pinned file skips ONLY the client-term rule of tools/scrub.mjs while its content is unchanged; every
// pattern rule (IBAN, TCKN, e-mail, IP, phone, secrets) still runs. Pin only public, generated data
// (e.g. official address registries) after reviewing it, never code, docs or anything client-derived.
//
// Usage: node tools/pin-data.mjs <glob> [<glob>…]
//   A glob is a repo-relative path whose LAST segment may contain * (e.g. plugins/x/data/ilce/*.txt).
//   Entries under the globs' directories are replaced by the current files; other entries are kept.
import { readFileSync, writeFileSync, existsSync, readdirSync, statSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { dirname, join, resolve, relative, posix } from 'node:path';
import { fileURLToPath } from 'node:url';
import { PINNABLE, canonicalManifest, parseManifest } from './lib/scrub-manifest.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const MANIFEST = join(root, 'tools', 'scrub-data-manifest.json');
const globs = process.argv.slice(2);
if (!globs.length) { console.error('usage: node tools/pin-data.mjs <glob> [<glob>…]'); process.exit(2); }

let files = [];
if (existsSync(MANIFEST)) {
  const res = parseManifest(readFileSync(MANIFEST, 'utf8'));
  if (res.error) { console.error(`pin-data: existing manifest rejected (${res.error}); fix or delete it first`); process.exit(2); }
  files = res.files;
}

for (const g of globs) {
  const dir = posix.dirname(g);
  const base = posix.basename(g);
  if (dir.includes('*')) { console.error(`pin-data: * only allowed in the last segment: ${g}`); process.exit(2); }
  const abs = join(root, dir);
  if (!existsSync(abs) || !statSync(abs).isDirectory()) { console.error(`pin-data: no such directory: ${dir}`); process.exit(2); }
  const re = new RegExp('^' + base.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '[^/]*') + '$');
  files = files.filter(e => !(posix.dirname(e.path) === dir && re.test(posix.basename(e.path))));
  const hits = readdirSync(abs).filter(n => re.test(n) && statSync(join(abs, n)).isFile());
  if (!hits.length) { console.error(`pin-data: nothing matches ${g}`); process.exit(2); }
  for (const n of hits) {
    const path = relative(root, join(abs, n)).split('\\').join('/');
    if (!PINNABLE.test(path)) { console.error(`pin-data: only plugins/<slug>/data/… or mu-plugins/<slug>/data/… .txt/.json files can be pinned: ${path}`); process.exit(2); }
    files.push({ path, sha256: createHash('sha256').update(readFileSync(join(abs, n))).digest('hex') });
  }
  console.log(`pinned ${hits.length} file(s) for ${g}`);
}

files = files.filter(e => existsSync(join(root, e.path)));
writeFileSync(MANIFEST, canonicalManifest(files));
console.log(`${relative(root, MANIFEST)}: ${files.length} file(s)`);
