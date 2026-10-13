#!/usr/bin/env node
// Builds the bundled address data of Turkish Address Fields from an NVİ-derived source tree:
//   <src>/il-ilce.json         {"06": {"name": "Ankara", "districts": [[1231, "Çankaya"], …]}, …}
//   <src>/mahalle/<ilçe>.json  [[mahalle id, "Mahalle adı"], …]
//   <src>/sokak/<ilçe>.json    {"<mahalle id>": ["Sokak adı", …], …}
// Output (plugins/turkish-address-fields-for-woocommerce/data/):
//   il-ilce.json (minified) and ilce/<ilçe id>.txt, one line per mahalle:
//   <mahalle id> TAB <mahalle adı> TAB <sokak>|<sokak>|…
//   Street suffix codes: ~ " Sk.", @ " Cd.", = " Küme Evler", $ " Sokağı", % " Blv.".
//
// Filter: street records whose name without the street-type suffix (e.g. "Sk.", "Sokağı", "Köy Sokağı")
// is purely numeric with 7+ digits are dropped: they are registry codes entered as names, not street names,
// and can look like ID numbers to the privacy gate.
//
// Duplicate mahalle names within one ilçe (the registry has 8 such pairs, e.g. two identical "X Köyü, Y Mevkii"
// records, mostly one of them without streets) are merged into one entry under the first id with the union of
// their streets: a customer cannot tell identical labels apart, and the checkout script and the server look
// neighbourhoods up by name, so names must be unique per ilçe.
//
// Usage: node tools/build-taf-data.mjs <src-dir>
// After a deliberate data update re-pin it for the privacy gate:
//   node tools/pin-data.mjs 'plugins/turkish-address-fields-for-woocommerce/data/ilce/*.txt'
import { readFileSync, writeFileSync, mkdirSync, rmSync, existsSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = join(root, 'plugins', 'turkish-address-fields-for-woocommerce', 'data');
const src = process.argv[2];
if (!src || !existsSync(join(src, 'il-ilce.json'))) { console.error('usage: node tools/build-taf-data.mjs <src-dir with il-ilce.json, mahalle/, sokak/>'); process.exit(2); }

const SUFFIX = [[' Sk.', '~'], [' Cd.', '@'], [' Küme Evler', '='], [' Sokağı', '$'], [' Blv.', '%']];
const CODE_CHARS = SUFFIX.map(([, c]) => c);
// Street-type phrase at the end, optionally led by "Köy" ("… Köy Sokağı", "… Köy Yolu" = village road).
const TYPE_WORDS = /\s+(?:Köy\s+)?(?:Sk\.|Cd\.|Küme Evler|Sokağı|Sokak|Blv\.|Bulvarı|Cadde|Caddesi|Çıkmazı|Çk\.|Yolu|Meydanı|Myd\.)$/u;
/** Purely numeric (7+ digits) once the street-type word is removed. */
export const isRegistryCode = name => /^\d{7,}$/.test(name.replace(TYPE_WORDS, '').trim());

const read = f => JSON.parse(readFileSync(f, 'utf8'));
const provinces = read(join(src, 'il-ilce.json'));
// Plate keys "01"…"81" in order (JS would hoist integer-like keys "10"…"81" before "01"…"09").
const plates = Object.keys(provinces).sort();
mkdirSync(OUT, { recursive: true });
rmSync(join(OUT, 'ilce'), { recursive: true, force: true });
mkdirSync(join(OUT, 'ilce'));
writeFileSync(join(OUT, 'il-ilce.json'), '{' + plates.map(k => JSON.stringify(k) + ':' + JSON.stringify(provinces[k])).join(',') + '}');

let files = 0, hoods = 0, streets = 0, dropped = 0, merged = 0, bytes = 0;
for (const p of plates.map(k => provinces[k])) {
  for (const [did] of p.districts) {
    const m = read(join(src, 'mahalle', `${did}.json`));
    const s = read(join(src, 'sokak', `${did}.json`));
    // Merge duplicate names: first id wins, streets are unioned in order.
    const byName = new Map();
    for (const [mid, name] of m) {
      const prev = byName.get(name);
      const streetsOf = s[String(mid)] || [];
      if (prev) { for (const st of streetsOf) if (!prev.streets.includes(st)) prev.streets.push(st); merged++; }
      else byName.set(name, { mid, streets: [...streetsOf] });
    }
    const lines = [...byName].map(([name, { mid, streets: raw }]) => {
      if (/[\t\n]/.test(name)) throw new Error(`bad mahalle name in ${did}`);
      const list = [];
      for (const st of raw) {
        if (/[|\t\n]/.test(st)) throw new Error(`bad street name in ${did}`);
        // A raw name ending in a suffix code character would decode wrongly.
        if (CODE_CHARS.includes(st.slice(-1))) throw new Error(`street name ends in a code character in ${did}`);
        if (isRegistryCode(st)) { dropped++; continue; }
        const hit = SUFFIX.find(([long]) => st.endsWith(long));
        list.push(hit ? st.slice(0, -hit[0].length) + hit[1] : st);
      }
      hoods++; streets += list.length;
      return `${mid}\t${name}\t${list.join('|')}`;
    });
    const text = lines.join('\n') + '\n';
    writeFileSync(join(OUT, 'ilce', `${did}.txt`), text);
    files++; bytes += Buffer.byteLength(text);
  }
}
console.log(`${files} districts, ${hoods} mahalle, ${streets} streets (${dropped} registry-code names dropped, ${merged} duplicate mahalle merged), ${(bytes / 1e6).toFixed(1)} MB`);
