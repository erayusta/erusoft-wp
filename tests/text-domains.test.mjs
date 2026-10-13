import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const TOOL = resolve('tools/check-text-domains.php');

test('every translation call uses its plugin text domain as a string literal', () => {
  const r = spawnSync('php', [TOOL, 'plugins', 'mu-plugins'], { encoding: 'utf8' });
  assert.equal(r.status, 0, r.stdout + r.stderr);
});

test('class-constant, variable and wrong literal domains are reported', () => {
  const dir = mkdtempSync(join(tmpdir(), 'td-'));
  mkdirSync(join(dir, 'plugins', 'demo-plugin'), { recursive: true });
  writeFileSync(join(dir, 'plugins', 'demo-plugin', 'demo-plugin.php'),
    "<?php\n/* Text Domain: demo-plugin */\nclass D { const TD = 'demo-plugin';\n function f( $td ) {\n" +
    " __( 'a', self::TD ); esc_html__( 'b', $td ); _x( 'c', 'ctx', 'other' ); _n( 'd', 'e', 2, 'demo-plugin' );\n" +
    " sprintf( __( 'ok', 'demo-plugin' ), $td ); } }\n");
  const r = spawnSync('php', [TOOL, join(dir, 'plugins')], { encoding: 'utf8' });
  assert.equal(r.status, 1);
  assert.match(r.stdout, /NONLIT .*self::TD/);
  assert.match(r.stdout, /NONLIT .*\$td/);
  assert.match(r.stdout, /WRONG .*'other'/);
  assert.match(r.stdout, /total=3/);
});
