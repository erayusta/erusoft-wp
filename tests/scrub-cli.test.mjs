import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, mkdirSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, dirname } from 'node:path';
import { spawnSync, execFileSync } from 'node:child_process';
import { deflateSync, crc32 } from 'node:zlib';
import { createHash } from 'node:crypto';
import { termHash } from '../tools/lib/scrub-core.mjs';
import { canonicalManifest } from '../tools/lib/scrub-manifest.mjs';

const CLI = resolve('tools/scrub.mjs');
const tmp = () => mkdtempSync(join(tmpdir(), 'scrub-'));
// Invented client name only. SCRUB_TERMS_FILE points nowhere so the real private list never takes part.
function hashFile(...terms) {
  const hf = join(tmp(), 'h.txt');
  writeFileSync(hf, terms.map(termHash).join('\n') + '\n');
  return hf;
}
function run(args, { cwd = process.cwd(), env = {} } = {}) {
  return spawnSync('node', [CLI, ...args], {
    cwd, encoding: 'utf8',
    env: { ...process.env, SCRUB_TERMS_FILE: join(tmpdir(), 'no-such-terms.txt'), SCRUB_DATA_MANIFEST: join(tmpdir(), 'no-such-manifest.json'), SCRUB_ALLOW_IDENTITY: '', ...env },
  });
}
function pngChunk(type, data) {
  const len = Buffer.alloc(4); len.writeUInt32BE(data.length);
  const td = Buffer.concat([Buffer.from(type, 'latin1'), data]);
  const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(td));
  return Buffer.concat([len, td, crc]);
}
function png(...chunks) {
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(1, 0); ihdr.writeUInt32BE(1, 4); ihdr[8] = 8; ihdr[9] = 2;
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), pngChunk('IHDR', ihdr), ...chunks, pngChunk('IEND', Buffer.alloc(0))]);
}
const NUL = Buffer.from([0]);
const JANE = 'jane' + '@' + 'roe-mail.test'; // assembled so this file scans clean

test('finds hashed term in PNG tEXt chunk and PHP default', () => {
  const dir = tmp();
  const hf = hashFile('Acme Gida');
  const pngBuf = Buffer.concat([Buffer.from([137,80,78,71,13,10,26,10]), Buffer.from('....tEXtAuthor\0Acme Gida photographer....', 'latin1')]);
  writeFileSync(join(dir, 'shot.png'), pngBuf);
  writeFileSync(join(dir, 'p.php'), "<?php $d = ['footer' => 'Acme Gida'];");
  const r = run(['--dir', dir], { env: { SCRUB_HASHES_FILE: hf } });
  assert.equal(r.status, 1);
  assert.match(r.stdout, /shot\.png \(metadata\).*client-term/);
  assert.match(r.stdout, /p\.php:1 client-term/);
});

test('Turkish term in PNG tEXt (UTF-8), zTXt and compressed iTXt chunks', () => {
  const dir = tmp();
  const hf = hashFile('Acme Gıda');
  const text = Buffer.from('Acme Gıda studio', 'utf8');
  writeFileSync(join(dir, 'a.png'), png(pngChunk('tEXt', Buffer.concat([Buffer.from('Author'), NUL, text]))));
  writeFileSync(join(dir, 'b.png'), png(pngChunk('zTXt', Buffer.concat([Buffer.from('Comment'), NUL, NUL, deflateSync(text)]))));
  writeFileSync(join(dir, 'c.png'), png(pngChunk('iTXt', Buffer.concat([Buffer.from('Author'), NUL, Buffer.from([1, 0]), Buffer.from('tr'), NUL, NUL, deflateSync(text)]))));
  const r = run(['--dir', dir], { env: { SCRUB_HASHES_FILE: hf } });
  assert.equal(r.status, 1);
  for (const f of ['a.png', 'b.png', 'c.png']) assert.match(r.stdout, new RegExp(`${f.replace('.', '\\.')} \\(metadata\\):0 client-term`), f);
});

test('lowercase term right after a PNG chunk keyword (zTXt, iTXt translated keyword)', () => {
  const dir = tmp();
  const hf = hashFile('Acme Gıda');
  const text = Buffer.from('acme gıda', 'utf8');
  writeFileSync(join(dir, 'z.png'), png(pngChunk('zTXt', Buffer.concat([Buffer.from('Comment'), NUL, NUL, deflateSync(text)]))));
  writeFileSync(join(dir, 'i.png'), png(pngChunk('iTXt', Buffer.concat([Buffer.from('Author'), NUL, Buffer.from([1, 0]), Buffer.from('tr'), NUL, Buffer.from('yazar'), NUL, deflateSync(text)]))));
  const r = run(['--dir', dir], { env: { SCRUB_HASHES_FILE: hf } });
  assert.equal(r.status, 1);
  assert.match(r.stdout, /^z\.png \(metadata\):0 client-term/m);
  assert.match(r.stdout, /^i\.png \(metadata\):0 client-term/m);
});

test('short (4-char) term in an unparsed binary', () => {
  const dir = tmp();
  writeFileSync(join(dir, 'pic.gif'), Buffer.concat([Buffer.from('GIF89a'), NUL, NUL, Buffer.from('Acme'), NUL, NUL]));
  const r = run(['--dir', dir], { env: { SCRUB_HASHES_FILE: hashFile('Acme') } });
  assert.equal(r.status, 1);
  assert.match(r.stdout, /^pic\.gif \(metadata\):0 client-term/m);
});

test('default mode outside a git repository fails closed', () => {
  const dir = tmp();
  writeFileSync(join(dir, 'a.txt'), 'hello\n');
  const r = run([], { cwd: dir, env: { SCRUB_HASHES_FILE: hashFile('Acme Gida'), GIT_CEILING_DIRECTORIES: dirname(dir) } });
  assert.equal(r.status, 1);
  assert.match(r.stdout, /git-unavailable/);
});

test('dotfiles, UTF-16 text, NUL binaries and archives are all checked', () => {
  const dir = tmp();
  const hf = hashFile('Acme Gida');
  writeFileSync(join(dir, '.env'), 'BRAND=AcmeGida\n');
  writeFileSync(join(dir, 'notes.txt'), Buffer.concat([Buffer.from([0xff, 0xfe]), Buffer.from('hello\nfor Acme Gida\n', 'utf16le')]));
  writeFileSync(join(dir, 'blob.bin'), Buffer.concat([Buffer.from([0, 1, 2, 0]), Buffer.from('xx Acme Gıda yy'), NUL]));
  writeFileSync(join(dir, 'wide.dat'), Buffer.concat([NUL, Buffer.from('Acme Gida', 'utf16le')]));
  writeFileSync(join(dir, 'bundle.zip'), Buffer.from('PK\x03\x04', 'latin1'));
  const r = run(['--dir', dir], { env: { SCRUB_HASHES_FILE: hf } });
  assert.equal(r.status, 1);
  assert.match(r.stdout, /^\.env:1 client-term/m);
  assert.match(r.stdout, /^notes\.txt:2 client-term/m);
  assert.match(r.stdout, /^blob\.bin \(binary\):0 client-term/m);
  assert.match(r.stdout, /^wide\.dat \(binary\):0 client-term/m);
  assert.match(r.stdout, /^bundle\.zip:0 archive-not-allowed/m);
});

test('file paths, forbidden directories; symlinks are skipped', () => {
  const dir = tmp();
  const outside = tmp();
  const hf = hashFile('Acme Gida');
  writeFileSync(join(dir, 'acme-gida-notes.txt'), 'nothing here\n');
  mkdirSync(join(dir, '.private'));
  writeFileSync(join(dir, '.private', 'x.txt'), 'nothing\n');
  writeFileSync(join(outside, 'secret.txt'), 'Acme Gida\n');
  symlinkSync(join(outside, 'secret.txt'), join(dir, 'link.txt'));
  const r = run(['--dir', dir], { env: { SCRUB_HASHES_FILE: hf } });
  assert.equal(r.status, 1);
  assert.match(r.stdout, /^acme-gida-notes\.txt \(path\):1 client-term/m);
  assert.match(r.stdout, /^\.private\/x\.txt:0 forbidden-path/m);
  assert.doesNotMatch(r.stdout, /link\.txt/);
});

test('exit 2 when no term source is available, unless explicitly allowed', () => {
  const dir = tmp();
  writeFileSync(join(dir, 'a.txt'), 'hello\n');
  const empty = join(tmp(), 'empty.txt'); writeFileSync(empty, '# nothing\n');
  const r = run(['--dir', dir], { env: { SCRUB_HASHES_FILE: empty } });
  assert.equal(r.status, 2);
  assert.match(r.stderr, /no term source/);
  assert.equal(run(['--dir', dir], { env: { SCRUB_HASHES_FILE: empty, SCRUB_ALLOW_NO_TERMS: '1' } }).status, 0);
});

test('--strict exits 2 when the plain term list is missing', () => {
  const dir = tmp();
  writeFileSync(join(dir, 'a.txt'), 'hello\n');
  const r = run(['--strict', '--dir', dir], { env: { SCRUB_HASHES_FILE: hashFile('Acme Gida') } });
  assert.equal(r.status, 2);
  assert.match(r.stderr, /--strict/);
});

test('git metadata: commit messages, tags and identities', () => {
  const repo = tmp();
  const hf = hashFile('Acme Gida');
  const git = (...a) => execFileSync('git', ['-c', 'user.name=Jane Roe', '-c', `user.email=${JANE}`,
    '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false', ...a], { cwd: repo, stdio: 'pipe' });
  git('init', '-q');
  writeFileSync(join(repo, 'a.txt'), 'hello\n');
  git('add', 'a.txt');
  git('commit', '-q', '-m', 'chore: built for Acme Gida');
  git('tag', '-a', 'v1', '-m', 'release AcmeGida edition');
  const env = { SCRUB_HASHES_FILE: hf };
  const r = run([], { cwd: repo, env });
  assert.equal(r.status, 1);
  assert.match(r.stdout, /^git-log:\d+ client-term/m);
  assert.match(r.stdout, /^git-tags:\d+ client-term/m);
  assert.match(r.stdout, /^git-log:\d+ email/m);
  const allowed = run([], { cwd: repo, env: { ...env, SCRUB_ALLOW_IDENTITY: `Jane Roe\n${JANE}` } });
  assert.doesNotMatch(allowed.stdout, /email/);
  assert.match(allowed.stdout, /^git-log:\d+ client-term/m);
});

test('--exclude skips a relative directory in --dir mode only', () => {
  const dir = tmp();
  mkdirSync(join(dir, 'downloads'));
  writeFileSync(join(dir, 'downloads', 'x.txt'), 'Acme Gida');
  writeFileSync(join(dir, 'ok.txt'), 'hello');
  const env = { SCRUB_HASHES_FILE: hashFile('Acme Gida') };
  assert.equal(run(['--dir', dir], { env }).status, 1);
  assert.equal(run(['--dir', dir, '--exclude', 'downloads'], { env }).status, 0);
  writeFileSync(join(dir, 'bad.txt'), 'Acme Gida');
  assert.equal(run(['--dir', dir, '--exclude', 'downloads'], { env }).status, 1);
  assert.equal(run(['--exclude', 'downloads'], { env }).status, 2);
});

// ---- content-pinned data (tools/scrub-data-manifest.json) ----
const sha = s => createHash('sha256').update(s).digest('hex');
const DATA_PATH = 'plugins/demo-plugin/data/ilce/1.txt';
function pinnedSetup(pinnedContent, actualContent = pinnedContent, layout = DATA_PATH) {
  const dir = tmp();
  mkdirSync(join(dir, dirname(layout)), { recursive: true });
  writeFileSync(join(dir, layout), actualContent);
  const mf = join(tmp(), 'manifest.json');
  writeFileSync(mf, canonicalManifest([{ path: DATA_PATH, sha256: sha(pinnedContent) }]));
  return { dir, env: { SCRUB_HASHES_FILE: hashFile('Acme Gida'), SCRUB_DATA_MANIFEST: mf } };
}
const STREETS = '40001\tDemo Mah.\tAcme Gida~|Örnek~\n';

test('pinned data: client term in an unchanged pinned file is not reported', () => {
  const { dir, env } = pinnedSetup(STREETS);
  const r = run(['--dir', dir], { env });
  assert.equal(r.status, 0, r.stdout);
});

test('pinned data: same path with changed content is fully scanned', () => {
  const { dir, env } = pinnedSetup(STREETS, STREETS + '40002\tYeni Mah.\t\n');
  const r = run(['--dir', dir], { env });
  assert.equal(r.status, 1);
  assert.match(r.stdout, /1\.txt:1 client-term/);
});

test('pinned data: pattern rules (e-mail, IBAN) still run on a pinned file', () => {
  const iban = 'T' + 'R12 0006 2000 0000 0000 0000 00';
  const content = STREETS + JANE + '\n' + iban + '\n';
  const { dir, env } = pinnedSetup(content);
  const r = run(['--dir', dir], { env });
  assert.equal(r.status, 1);
  assert.match(r.stdout, /1\.txt:2 email/);
  assert.match(r.stdout, /1\.txt:3 iban/);
  assert.doesNotMatch(r.stdout, /client-term/);
});

test('pinned data: same content at an unpinned path is reported', () => {
  const { dir, env } = pinnedSetup(STREETS, STREETS, 'plugins/demo-plugin/data/other/1.txt');
  const r = run(['--dir', dir], { env });
  assert.equal(r.status, 1);
  assert.match(r.stdout, /client-term/);
});

test('pinned data: extracted ZIP layout matches by plugin-relative suffix + hash', () => {
  const { dir, env } = pinnedSetup(STREETS, STREETS, 'demo-plugin/demo-plugin/data/ilce/1.txt');
  assert.equal(run(['--dir', dir], { env }).status, 0);
  const changed = pinnedSetup(STREETS, STREETS + 'x\n', 'demo-plugin/demo-plugin/data/ilce/1.txt');
  assert.equal(run(['--dir', changed.dir], { env: changed.env }).status, 1);
});

test('pinned data: manifest may only skip the client-term rule', () => {
  const { dir, env } = pinnedSetup(STREETS);
  writeFileSync(env.SCRUB_DATA_MANIFEST, JSON.stringify({ files: [{ path: DATA_PATH, sha256: sha(STREETS) }], rules_skipped: ['client-term', 'email'] }));
  assert.equal(run(['--dir', dir], { env }).status, 2);
});

test('manifest: duplicate keys (smuggled entry) are rejected and pin nothing', () => {
  const { dir, env } = pinnedSetup(STREETS);
  const canon = canonicalManifest([{ path: DATA_PATH, sha256: sha(STREETS) }]);
  // A reviewer sees an empty list; JSON.parse keeps the second "files" key.
  writeFileSync(env.SCRUB_DATA_MANIFEST, canon.replace('{\n "files": [', '{\n "files": [],\n "files": ['));
  const r = run(['--dir', dir], { env });
  assert.equal(r.status, 1);
  assert.match(r.stdout, /manifest-invalid/);
  assert.match(r.stdout, /1\.txt:1 client-term/);
});

test('manifest: extra text or whitespace is rejected; canonical form is accepted', () => {
  const { dir, env } = pinnedSetup(STREETS);
  const canon = canonicalManifest([{ path: DATA_PATH, sha256: sha(STREETS) }]);
  writeFileSync(env.SCRUB_DATA_MANIFEST, canon + '\n');
  assert.match(run(['--dir', dir], { env }).stdout, /manifest-invalid/);
  writeFileSync(env.SCRUB_DATA_MANIFEST, canon.replace('"files"', '"files" '));
  assert.match(run(['--dir', dir], { env }).stdout, /manifest-invalid/);
  writeFileSync(env.SCRUB_DATA_MANIFEST, canon);
  assert.equal(run(['--dir', dir], { env }).status, 0);
});

test('manifest: only plugins/<slug>/data/… paths may be pinned', () => {
  const { dir, env } = pinnedSetup(STREETS);
  writeFileSync(env.SCRUB_DATA_MANIFEST, canonicalManifest([{ path: 'tools/notes.txt', sha256: sha(STREETS) }]));
  const r = run(['--dir', dir], { env });
  assert.equal(r.status, 2);
  assert.match(r.stderr, /not pinnable/);
});

test('pinned data: ZIP suffix matching is not used in default (repository) mode', () => {
  const repo = tmp();
  execFileSync('git', ['init', '-q'], { cwd: repo });
  mkdirSync(join(repo, 'demo-plugin/data/ilce'), { recursive: true });
  writeFileSync(join(repo, 'demo-plugin/data/ilce/1.txt'), STREETS);
  const mf = join(tmp(), 'manifest.json');
  writeFileSync(mf, canonicalManifest([{ path: DATA_PATH, sha256: sha(STREETS) }]));
  const r = run([], { cwd: repo, env: { SCRUB_HASHES_FILE: hashFile('Acme Gida'), SCRUB_DATA_MANIFEST: mf } });
  assert.equal(r.status, 1);
  assert.match(r.stdout, /demo-plugin\/data\/ilce\/1\.txt:1 client-term/);
});

test('manifest: an entry whose path is not a repository file is reported (repository mode)', () => {
  const repo = tmp();
  execFileSync('git', ['init', '-q'], { cwd: repo });
  mkdirSync(join(repo, dirname(DATA_PATH)), { recursive: true });
  writeFileSync(join(repo, DATA_PATH), STREETS);
  const gone = 'plugins/demo-plugin/data/ilce/2.txt';
  const mf = join(tmp(), 'manifest.json');
  writeFileSync(mf, canonicalManifest([{ path: DATA_PATH, sha256: sha(STREETS) }, { path: gone, sha256: sha('x') }]));
  const r = run([], { cwd: repo, env: { SCRUB_HASHES_FILE: hashFile('Acme Gida'), SCRUB_DATA_MANIFEST: mf } });
  assert.equal(r.status, 1);
  assert.match(r.stdout, /manifest-stale.*2\.txt/);
  assert.doesNotMatch(r.stdout, /1\.txt.*manifest-stale/);
});
