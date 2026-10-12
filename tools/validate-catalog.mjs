import { loadCatalog, validateCatalog } from './lib/catalog.mjs';

const entries = loadCatalog('catalog.json');
const errs = validateCatalog(entries);
if (errs.length) {
  console.error(errs.join('\n'));
  process.exit(1);
}
console.log(`catalog: ${entries.length} entries valid`);
