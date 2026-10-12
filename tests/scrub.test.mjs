import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normalize, termHash, scanText } from '../tools/lib/scrub-core.mjs';
// Sample secrets / personal-data shapes are assembled at runtime so this file itself scans clean.
const Q = "'";
const DQ = '"';
const AT = '@';
const hashes = new Set([termHash('Acme Gıda'), termHash('acmegida.com.tr')]);
const rules = text => scanText(text, { hashes }).map(f => f.rule);

test('normalize folds Turkish', () => assert.equal(normalize('İŞÇİ Ğüö'), 'isci guo'));
test('hashed client term in PHP default', () => {
  const f = scanText("$defaults = ['brand' => 'ACME GIDA'];", { hashes });
  assert.equal(f[0].rule, 'client-term');
});
test('hashed domain', () => assert.equal(scanText('see https://acmegida.com.tr/x', { hashes })[0].rule, 'client-term'));
test('camelCase glue is split before hashed matching', () => {
  assert.equal(scanText('$acmeGidaShop = 1;', { hashes })[0]?.rule, 'client-term');
  assert.equal(scanText('class AcmeGıdaWidget {}', { hashes })[0]?.rule, 'client-term');
  assert.equal(scanText('class AcmeİşWidget {}', { hashes: new Set([termHash('acme is')]) })[0]?.rule, 'client-term');
  assert.equal(scanText('$acmeGidiShop = 1;', { hashes }).length, 0);
});
test('IBAN', () => assert.equal(scanText(['TR33', '0006', '1005', '1978', '6457', '8413', '26'].join(' '), { hashes })[0].rule, 'iban'));
test('TCKN only when checksum valid', () => {
  assert.equal(scanText('tc: ' + '1000000014' + '6', { hashes })[0]?.rule, 'tckn');
  assert.equal(scanText('id ' + '1234567890' + '1', { hashes }).length, 0);
});
test('email allowlist', () => {
  assert.equal(scanText(`a${AT}example.com info${AT}erusoft.com`, { hashes }).length, 0);
  assert.equal(scanText(`x${AT}gmail.com`, { hashes })[0].rule, 'email');
  assert.equal(scanText(`Co-Authored-By: Claude <noreply${AT}anthropic.com>`, { hashes }).length, 0);
  assert.equal(scanText(`mynoreply${AT}anthropic.com`, { hashes })[0].rule, 'email');
  assert.equal(scanText(`123+someone${AT}users.noreply.github.com`, { hashes })[0].rule, 'email');
});
test('ipv4 allowlist', () => {
  assert.equal(scanText('127.0.0.1 0.0.0.0', { hashes }).length, 0);
  assert.equal(scanText('host ' + ['10', '2', '3', '4'].join('.'), { hashes })[0].rule, 'ipv4');
});
test('secret assignment', () => {
  assert.deepEqual(rules(`$api_key = ${Q}abcd1234efgh${Q};`), ['secret-literal']);
  assert.deepEqual(rules(`$password = ${DQ}hunter2hunter${DQ};`), ['secret-literal']);
  assert.equal(scanText("$api_key = get_option('x');", { hashes }).length, 0);
  assert.equal(scanText("'password' => ''", { hashes }).length, 0);
});
test('define() secrets', () => {
  assert.deepEqual(rules(`define( ${Q}SMTP_PASS${Q}, ${Q}s3cr3tvalue${Q} );`), ['secret-literal']);
  assert.deepEqual(rules(`define(${DQ}API_TOKEN${DQ}, ${DQ}abcdef123456${DQ});`), ['secret-literal']);
  assert.deepEqual(rules(`define( ${Q}AUTH_SECRET_KEY${Q}, ${Q}q9w8e7r6${Q} );`), ['secret-literal']);
  assert.equal(scanText("define( 'SMTP_PASS', '' );", { hashes }).length, 0);
  assert.equal(scanText("define( 'SMTP_PASS', getenv('X') );", { hashes }).length, 0);
  assert.equal(scanText("define( 'MY_KEY', OTHER_CONSTANT );", { hashes }).length, 0);
  assert.equal(scanText("define( 'PLUGIN_VERSION', '1.2.3-beta' );", { hashes }).length, 0);
});
test('token shapes', () => {
  assert.equal(scanText('sk-' + 'ant-abc123def456ghi789', { hashes })[0].rule, 'token-shape');
  assert.equal(scanText('AKIA' + 'ABCDEFGHIJKLMNOP', { hashes })[0].rule, 'token-shape');
  assert.deepEqual(rules('k = ' + 'sk_' + 'live_' + 'a1B2c3D4e5F6g7H8'), ['token-shape']);
  assert.deepEqual(rules('k = ' + 'rk_' + 'live_' + 'a1B2c3D4e5F6g7H8'), ['token-shape']);
  assert.deepEqual(rules('k = ' + 'sk_' + 'test_' + 'a1B2c3D4e5F6g7H8'), ['token-shape']);
  assert.deepEqual(rules('-----BEGIN ' + 'RSA PRIVATE KEY-----'), ['token-shape']);
  assert.deepEqual(rules('-----BEGIN ' + 'EC PRIVATE KEY-----'), ['token-shape']);
  assert.deepEqual(rules('-----BEGIN ' + 'PRIVATE KEY-----'), ['token-shape']);
  assert.deepEqual(rules('-----BEGIN ' + 'OPENSSH PRIVATE KEY-----'), ['token-shape']);
  assert.deepEqual(rules('key=' + 'AIza' + 'x'.repeat(35)), ['token-shape']);
  assert.deepEqual(rules('xox' + 'b-1234567890-abcdefghij'), ['token-shape']);
  assert.deepEqual(rules('Authorization: ' + 'Bearer ' + 'abcdefghij0123456789xyz'), ['token-shape']);
  assert.equal(scanText("'Authorization' => 'Bearer ' . $token", { hashes }).length, 0);
  assert.equal(scanText('-----BEGIN PUBLIC KEY-----', { hashes }).length, 0);
});
test('phone numbers', () => {
  const positives = ['0' + '532 123 45 67', '+90' + ' 532 123 45 67', '0' + '(532) 123-45-67', '0' + '5321234567',
    '0' + '212 555 12 34', '+90' + ' (216) 555 12 34', '0' + '850 123 45 67', '0' + '800 123 45 67', '444' + ' 12 34',
    '+90' + '-532-123-45-67', '+90' + '.532 123 45 67', 'https://wa.me/' + '905321234567', 'https://api.whatsapp.com/send?phone=' + '905321234567'];
  for (const p of positives) assert.deepEqual(rules('Tel: ' + p), ['phone'], p);
  const negatives = ['version 5.321.234.56', 'price 532 123 45 67', 'id 05321234567890', 'sku 1532 123 45 67',
    'Requires at least: 6.5', '4440 12 34', 'total 4441234', 'ref 0712 555 12 34',
    'https://wa.me/' + '9053212345678', 'phone=' + '12345678901'];
  for (const n of negatives) assert.equal(scanText(n, { hashes }).filter(f => f.rule === 'phone').length, 0, n);
});
test('plain terms (local strict mode)', () => {
  assert.equal(scanText('made for Acme', { hashes: new Set(), plainTerms: ['acme'] })[0].rule, 'client-term');
});
test('plain terms: long terms match inside identifiers, short terms only as whole words', () => {
  const opts = { hashes: new Set(), plainTerms: ['acmegida', 'zx'] };
  assert.equal(scanText('$acmegidaShop = 1;', opts)[0].rule, 'client-term');
  assert.equal(scanText('function zx_init() {}', opts)[0].rule, 'client-term');
  assert.equal(scanText('fizz xylophone', opts).length, 0);
});
