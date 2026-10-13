// Content-pinned data manifest (tools/scrub-data-manifest.json): shared by tools/scrub.mjs and tools/pin-data.mjs.
//
// The manifest is accepted only in its canonical byte form, exactly as pin-data.mjs writes it. JSON.parse
// silently keeps the last of duplicate keys, so a hand-edited file could otherwise show one value to a
// reviewer and another to the gate. Re-serialising and comparing bytes rules that out (and any extra text).

/** Pinnable paths: data files inside a plugin or mu-plugin folder only. */
export const PINNABLE = /^(plugins|mu-plugins)\/[^/]+\/data\/[^/]+(\/[^/]+)*\.(txt|json)$/;
export const RULES_SKIPPED = ['client-term'];

/** Canonical text for a list of {path, sha256} entries. */
export function canonicalManifest(files) {
  const sorted = files
    .map(e => ({ path: e.path, sha256: e.sha256 }))
    .sort((a, b) => a.path.localeCompare(b.path, 'en', { numeric: true }));
  return JSON.stringify({ files: sorted, rules_skipped: RULES_SKIPPED }, null, 1) + '\n';
}

/**
 * Parse and check a manifest's raw text.
 * @returns {{files: {path:string, sha256:string}[], error: string}} error '' when valid; files [] when invalid.
 */
export function parseManifest(raw) {
  let m;
  try { m = JSON.parse(raw); } catch { return { files: [], error: 'not valid JSON' }; }
  if (!m || typeof m !== 'object' || !Array.isArray(m.files)) return { files: [], error: 'missing files[]' };
  if (JSON.stringify(m.rules_skipped) !== JSON.stringify(RULES_SKIPPED)) return { files: [], error: 'rules_skipped must be exactly ["client-term"]', rules: true };
  for (const e of m.files) {
    if (!e || typeof e.path !== 'string' || typeof e.sha256 !== 'string' || !/^[0-9a-f]{64}$/.test(e.sha256)) return { files: [], error: 'entry must be {path, sha256}' };
    if (!PINNABLE.test(e.path) || e.path.split('/').includes('..')) return { files: [], error: `path not pinnable: ${e.path}`, path: true };
  }
  if (new Set(m.files.map(e => e.path)).size !== m.files.length) return { files: [], error: 'duplicate path' };
  if (canonicalManifest(m.files) !== raw) return { files: [], error: 'not in canonical form (re-run tools/pin-data.mjs)' };
  return { files: m.files, error: '' };
}
