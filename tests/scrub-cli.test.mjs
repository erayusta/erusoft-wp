import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, mkdirSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, dirname } from 'node:path';
import { spawnSync, execFileSync } from 'node:child_process';
import { deflateSync, crc32 } from 'node:zlib';
import { termHash } from '../tools/lib/scrub-core.mjs';

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
    env: { ...process.env, SCRUB_TERMS_FILE: join(tmpdir(), 'no-such-terms.txt'), SCRUB_ALLOW_IDENTITY: '', ...env },
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
