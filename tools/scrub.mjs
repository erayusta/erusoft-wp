#!/usr/bin/env node
// Privacy gate: fails (exit 1) when any client term, personal data or secret is found.
//
// Usage: node tools/scrub.mjs [--strict] [--dir <path> [--exclude <relative-dir>]...]
//   default      scans git-tracked + untracked-not-ignored files (content and path), then the git
//                metadata of every ref: authors, committers, messages and tag messages
//   --dir <path> scans every file under <path> recursively (symlinks skipped, no git checks)
//   --exclude <d> (repeatable, --dir mode only) skips the relative directory <d> inside the scanned path
//   --strict     also requires the private plain-term list to be present
//
// Term sources (the term list is private and is NEVER committed, not even as hashes):
//   hashes:  $SCRUB_HASHES_FILE, else .private/scrub-hashes.txt  (one sha256 of a normalized term per line)
//   plain:   $SCRUB_TERMS_FILE, else .private/scrub-terms.txt    (default mode, --strict, or SCRUB_PLAIN=1)
//   With no term source at all the gate refuses to run (exit 2). SCRUB_ALLOW_NO_TERMS=1 disables that
//   check and exists ONLY for unit tests.
// CI: the workflow writes the hash list from a GitHub Actions secret into a temp file and sets
//   SCRUB_HASHES_FILE to it; the committer identity comes from a secret via SCRUB_ALLOW_IDENTITY.
// Identity allowlist: author/committer lines equal to an entry of $SCRUB_ALLOW_IDENTITY (newline or
//   comma separated) or, when that is unset, of .private/scrub-allow.txt are not reported.
// Exit codes: 0 clean, 1 findings, 2 configuration error.
import { readFileSync, existsSync, readdirSync, lstatSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join, relative, resolve } from 'node:path';
import { inflateSync } from 'node:zlib';
import { listTrackedFiles } from './lib/fsutil.mjs';
import { scanText } from './lib/scrub-core.mjs';

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const privateDir = join(repoRoot, '.private');
const fail = msg => { console.error(`scrub: ${msg}`); process.exit(2); };
const readLines = file => readFileSync(file, 'utf8').split('\n').map(s => s.trim()).filter(s => s && !s.startsWith('#'));

const args = process.argv.slice(2);
const strict = args.includes('--strict');
const dirIdx = args.indexOf('--dir');
const dirMode = dirIdx !== -1;
if (dirMode && !args[dirIdx + 1]) fail('--dir needs a path');
const excludes = args.flatMap((a, i) => a === '--exclude' ? [args[i + 1]] : []);
if (args.includes('--exclude') && (!dirMode || excludes.some(e => !e || e.startsWith('--')))) fail('--exclude needs a path and only works with --dir');
const scanRoot = dirMode ? resolve(args[dirIdx + 1]) : process.cwd();

const hashesFile = process.env.SCRUB_HASHES_FILE || join(privateDir, 'scrub-hashes.txt');
const hashes = new Set(existsSync(hashesFile) ? readLines(hashesFile).filter(s => /^[0-9a-f]{64}$/.test(s)) : []);
const termsFile = process.env.SCRUB_TERMS_FILE || join(privateDir, 'scrub-terms.txt');
if (strict && !existsSync(termsFile)) fail('--strict needs the plain term list (.private/scrub-terms.txt or $SCRUB_TERMS_FILE)');
const usePlain = !dirMode || strict || process.env.SCRUB_PLAIN === '1';
const plainTerms = usePlain && existsSync(termsFile) ? readLines(termsFile) : [];
if (!hashes.size && !plainTerms.length && process.env.SCRUB_ALLOW_NO_TERMS !== '1') {
  fail('no term source available (set SCRUB_HASHES_FILE or create .private/scrub-hashes.txt); refusing to report "clean"');
}
const allowFile = join(privateDir, 'scrub-allow.txt');
const identity = new Set((process.env.SCRUB_ALLOW_IDENTITY !== undefined
  ? process.env.SCRUB_ALLOW_IDENTITY.split(/[\n,]/) : existsSync(allowFile) ? readLines(allowFile) : [])
  .map(s => s.trim().replace(/^<|>$/g, '').toLowerCase()).filter(Boolean));

const ALLOW = new Set(['package-lock.json']);
const FORBIDDEN = /^(\.private|\.superpowers)(\/|$)/;
const ARCHIVE = /\.(zip|gz|tgz|rar|7z|tar|bz2|xz)$/i;
const IMG = /\.(png|jpe?g|webp|gif|tiff?)$/i;

let findings = 0;
const report = (where, f) => { findings++; console.log(`${where}:${f.line} ${f.rule} ${f.match}`); };
const scan = text => scanText(text, { hashes, plainTerms });

// Printable runs from UTF-8 and UTF-16LE (both byte alignments) decodes of a binary blob.
function strings(buf, min) {
  const re = new RegExp(`[^\\x00-\\x1f\\x7f-\\x9f\\ufffd]{${min},}`, 'gu');
  const decodes = [buf.toString('utf8'), buf.toString('utf16le'), buf.subarray(1).toString('utf16le')];
  return decodes.flatMap(s => s.match(re) || []).join('\n');
}
// Keep metadata fields apart so a keyword never glues onto the following text.
const joinParts = parts => Buffer.concat(parts.flatMap(p => [p, Buffer.from('\n')]));
const tryInflate = b => { try { return inflateSync(b); } catch { return b; } };

// PNG: text chunks (tEXt / zTXt / iTXt, inflated when compressed) and every other ancillary chunk; pixel data is skipped.
function pngMetadata(buf) {
  const parts = [];
  for (let o = 8; o + 8 <= buf.length;) {
    const len = buf.readUInt32BE(o);
    const type = buf.toString('latin1', o + 4, o + 8);
    if (!/^[A-Za-z]{4}$/.test(type) || o + 12 + len > buf.length) return null;
    const data = buf.subarray(o + 8, o + 8 + len);
    const k = data.indexOf(0);
    if (type === 'zTXt' && k >= 0) parts.push(data.subarray(0, k), tryInflate(data.subarray(k + 2)));
    else if (type === 'iTXt' && k >= 0) {
      const lang = data.indexOf(0, k + 3);
      const trans = lang >= 0 ? data.indexOf(0, lang + 1) : -1;
      const body = trans >= 0 ? data.subarray(trans + 1) : data.subarray(k + 3);
      parts.push(data.subarray(0, trans >= 0 ? trans : k), data[k + 1] === 1 ? tryInflate(body) : body);
    } else if (type !== 'IDAT') parts.push(data);
    o += 12 + len;
    if (type === 'IEND') break;
  }
  return joinParts(parts);
}
// JPEG: every marker segment before the scan data (EXIF, XMP, comments, ICC...).
function jpegMetadata(buf) {
  const parts = [];
  for (let o = 2; o + 4 <= buf.length;) {
    if (buf[o] !== 0xff) return null;
    const marker = buf[o + 1];
    if (marker === 0xda || marker === 0xd9) break;
    const len = buf.readUInt16BE(o + 2);
    parts.push(buf.subarray(o + 4, o + 2 + len));
    o += 2 + len;
  }
  return joinParts(parts);
}
function binaryText(buf) {
  const isPng = buf.length > 8 && buf.readUInt32BE(0) === 0x89504e47;
  const isJpeg = buf.length > 4 && buf[0] === 0xff && buf[1] === 0xd8;
  const meta = isPng ? pngMetadata(buf) : isJpeg ? jpegMetadata(buf) : null;
  return meta ? strings(meta, 4) : strings(buf, 4); // unparsable / other binaries: whole file
}
function decodeText(buf) {
  if (buf[0] === 0xff && buf[1] === 0xfe) return buf.subarray(2).toString('utf16le');
  if (buf[0] === 0xfe && buf[1] === 0xff) return Buffer.from(buf.subarray(2)).swap16().toString('utf16le');
  if (buf.includes(0)) return null;
  return buf.toString('utf8');
}

function walk(dir) {
  const out = [];
  for (const name of readdirSync(dir)) {
    if (name === '.git') continue;
    const p = join(dir, name);
    const st = lstatSync(p);
    if (st.isSymbolicLink()) continue;
    if (st.isDirectory()) out.push(...walk(p)); else if (st.isFile()) out.push(p);
  }
  return out;
}

// Default mode needs git (file list + metadata); outside a repository fail closed instead of skipping.
if (!dirMode) {
  try { execFileSync('git', ['rev-parse', '--git-dir'], { cwd: scanRoot, stdio: 'ignore' }); } catch {
    report('git', { line: 0, rule: 'git-unavailable', match: 'not a git repository (use --dir to scan a plain directory)' });
    console.error(`\nscrub: ${findings} finding(s) — fix before publishing.`);
    process.exit(1);
  }
}
const isExcluded = f => excludes.some(e => { const d = e.replace(/\/+$/, ''); return f === d || f.startsWith(d + '/'); });
const files = dirMode ? walk(scanRoot).map(p => relative(scanRoot, p)).filter(f => !isExcluded(f)) : listTrackedFiles(scanRoot);
for (const file of files) {
  if (ALLOW.has(file)) continue;
  // forbidden paths are reported by name only: their content may be the private term list itself
  if (FORBIDDEN.test(file)) { report(file, { line: 0, rule: 'forbidden-path', match: file.split('/')[0] }); continue; }
  const abs = join(scanRoot, file);
  if (!existsSync(abs)) continue; // in the index but deleted from disk
  if (lstatSync(abs).isSymbolicLink()) continue;
  for (const f of scan(file.replace(/[\/._-]/g, ' '))) report(file + ' (path)', f);
  if (ARCHIVE.test(file)) { report(file, { line: 0, rule: 'archive-not-allowed', match: file }); continue; }
  const buf = readFileSync(abs);
  const text = decodeText(buf);
  if (text !== null) { for (const f of scan(text)) report(file, f); continue; }
  const label = IMG.test(file) ? ' (metadata)' : ' (binary)';
  for (const f of scan(binaryText(buf))) report(file + label, { ...f, line: 0 });
}

if (!dirMode) {
  const git = gitArgs => { try { return execFileSync('git', gitArgs, { cwd: scanRoot, stdio: ['ignore', 'pipe', 'ignore'] }).toString(); } catch { return ''; } };
  const dropIdentity = text => text.split('\n').map(l => identity.has(l.trim().replace(/^<|>$/g, '').toLowerCase()) ? '' : l).join('\n');
  const log = git(['log', '--all', '--format=%an%n%ae%n%cn%n%ce%n%s%n%b']);
  for (const f of scan(dropIdentity(log))) report('git-log', f);
  const tags = git(['for-each-ref', 'refs/tags', '--format=%(taggername)%0a%(taggeremail)%0a%(contents)']);
  for (const f of scan(dropIdentity(tags))) report('git-tags', f);
}

if (findings) { console.error(`\nscrub: ${findings} finding(s) — fix before publishing.`); process.exit(1); }
console.log('scrub: clean');
