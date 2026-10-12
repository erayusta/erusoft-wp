import { test } from 'node:test';
import assert from 'node:assert/strict';
import { listTrackedFiles } from '../tools/lib/fsutil.mjs';
test('lists tracked files and never .private', () => {
  const files = listTrackedFiles(process.cwd());
  assert.ok(files.includes('package.json'));
  assert.ok(files.every(f => !f.startsWith('.private/')));
  assert.ok(files.every(f => !f.startsWith('node_modules/')));
});
