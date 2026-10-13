import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';

export const CATEGORIES = ['Ödeme', 'Pazaryeri', 'Pazarlama & Kampanya', 'Sepet & Ödeme Akışı', 'Ürün & Katalog', 'Kargo & Teslimat', 'Fatura & Muhasebe', 'Müşteri & B2B', 'Raporlama & Analitik', 'SEO', 'Güvenlik', 'Performans', 'İçerik & AI', 'Entegrasyon (ERP/CRM)', 'Tema', 'Geliştirici Araçları'];

export const loadCatalog = p => JSON.parse(readFileSync(p, 'utf8'));

export function validateCatalog(entries, root = process.cwd()) {
  const errs = [];
  const seen = new Set();
  for (const e of entries) {
    const id = e.slug || '(no slug)';
    if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(e.slug || '')) errs.push(`${id}: slug must be ascii kebab-case`);
    if (seen.has(e.slug)) errs.push(`${id}: duplicate slug`);
    seen.add(e.slug);
    if (!e.name) errs.push(`${id}: name required`);
    if (!['plugin', 'mu-plugin', 'theme'].includes(e.type)) errs.push(`${id}: bad type`);
    if (!CATEGORIES.includes(e.category)) errs.push(`${id}: unknown category`);
    if (!e.tagline_tr || !e.tagline_en) errs.push(`${id}: taglines required`);
    else if (e.tagline_tr.length > 120 || e.tagline_en.length > 120) errs.push(`${id}: tagline longer than 120 chars`);
    if (!Array.isArray(e.features_tr) || e.features_tr.length < 2 || e.features_tr.length > 5) errs.push(`${id}: 2-5 features required`);
    if (!e.requires?.wp || !e.requires?.php) errs.push(`${id}: requires.wp and requires.php needed`);
    if (!['stable', 'beta', 'coming-soon'].includes(e.status)) errs.push(`${id}: bad status`);
    if (e.path && e.path.split('/').some(seg => seg === '..' || seg === '.')) errs.push(`${id}: path must not contain . or .. segments`);
    if (e.path && (e.type === 'theme') !== e.path.startsWith('themes/')) errs.push(`${id}: ${e.type === 'theme' ? 'themes must live' : 'only themes may live'} under themes/`);
    if (e.status !== 'coming-soon') {
      if (!e.path && !e.repo) errs.push(`${id}: stable/beta needs path or repo`);
      if (e.path && !existsSync(join(root, e.path, 'README.md'))) errs.push(`${id}: ${e.path}/README.md missing`);
      if (e.type === 'theme' && e.path && !existsSync(join(root, e.path, 'style.css'))) errs.push(`${id}: ${e.path}/style.css missing`);
    }
    for (const s of e.screenshots || []) if (!existsSync(join(root, s))) errs.push(`${id}: screenshot missing ${s}`);
  }
  return errs;
}
