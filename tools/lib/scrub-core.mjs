import { createHash } from 'node:crypto';

const FOLD = { 'İ': 'i', 'I': 'i', 'ı': 'i', 'Ş': 's', 'ş': 's', 'Ğ': 'g', 'ğ': 'g', 'Ç': 'c', 'ç': 'c', 'Ö': 'o', 'ö': 'o', 'Ü': 'u', 'ü': 'u' };

/** Lowercase, fold Turkish letters to ASCII, collapse every non-alphanumeric run to one space. */
export function normalize(s) {
  return String(s).replace(/[İIıŞşĞğÇçÖöÜü]/g, c => FOLD[c]).toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
}

// Fold Turkish letters but keep their case, so camelCase boundaries survive ("AcmeGıdaShop", "Acmeİş").
const CASE_FOLD = { 'İ': 'I', 'ı': 'i', 'Ş': 'S', 'ş': 's', 'Ğ': 'G', 'ğ': 'g', 'Ç': 'C', 'ç': 'c', 'Ö': 'O', 'ö': 'o', 'Ü': 'U', 'ü': 'u' };
/** Tokens used for term matching: camelCase glue is split first, then normalize(). */
function tokenize(line) {
  const split = String(line).replace(/[İıŞşĞğÇçÖöÜü]/g, c => CASE_FOLD[c]).replace(/([a-z0-9])([A-Z])/g, '$1 $2');
  return normalize(split).split(' ').filter(Boolean);
}

const sha256 = s => createHash('sha256').update(s).digest('hex');

/** sha256 hex of the normalized term with spaces removed ("Acme Gıda" === "acme-gida" === "ACMEGIDA"). */
export function termHash(term) { return sha256(normalize(term).replace(/ /g, '')); }

// noreply@anthropic.com: the Co-Authored-By commit trailer. The developer's own identity is allowed by the CLI, never here.
const EMAIL_OK = /(@(example\.(com|org|net)|erusoft\.com)|^noreply@anthropic\.com)$/i;
const IP_OK = new Set(['127.0.0.1', '0.0.0.0', '255.255.255.255']);

// Turkish phone numbers: a leading 0 or +90 (or the 444 prefix) and the usual grouping are required,
// so versions, prices and long IDs do not match.
const PHONE = [
  /(?<![\d+])(?:\+90[\s.-]*|0\s*)\(?(?:5\d{2}|[2-4]\d{2}|8[05]0)\)?[\s-]*\d{3}[\s-]*\d{2}[\s-]*\d{2}(?!\d)/g,
  /(?<!\d)444[\s-]\d{2}[\s-]\d{2}(?!\d)/g,
  /(?:wa\.me\/|[?&]phone=)90\d{10}(?!\d)/g, // WhatsApp click-to-chat links
];
const TOKENS = [
  /\b(sk-[A-Za-z0-9-]{16,}|AKIA[0-9A-Z]{16}|eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}|gh[pousr]_[A-Za-z0-9]{30,})\b/g,
  /\b[sr]k_(?:live|test)_[A-Za-z0-9]{10,}/g, // Stripe
  /-----BEGIN (?:RSA |EC |DSA |OPENSSH |ENCRYPTED )?PRIVATE KEY-----/g,
  /\bAIza[0-9A-Za-z_-]{35}/g, // Google API key
  /\bxox[bpas]-[A-Za-z0-9-]{10,}/g, // Slack
  /Authorization:\s*Bearer\s+[A-Za-z0-9._~+\/=-]{20,}/gi,
];

function tcknValid(d) {
  if (!/^[1-9]\d{10}$/.test(d)) return false;
  const n = [...d].map(Number);
  const d10 = ((n[0] + n[2] + n[4] + n[6] + n[8]) * 7 - (n[1] + n[3] + n[5] + n[7])) % 10;
  const d11 = n.slice(0, 10).reduce((a, b) => a + b, 0) % 10;
  return ((d10 + 10) % 10) === n[9] && d11 === n[10];
}

/**
 * Scan text line by line.
 * @param {string} text
 * @param {{hashes: Set<string>, plainTerms?: string[], skipTerms?: boolean}} opts
 *   skipTerms: skip ONLY the client-term rule (hashed and plain lists); every pattern rule still runs.
 *   Used for content-pinned public data files (tools/scrub-data-manifest.json).
 * @returns {{rule: string, match: string, line: number}[]}
 */
export function scanText(text, { hashes, plainTerms = [], skipTerms = false }) {
  const out = [];
  const lines = String(text).split(/\r?\n/);
  const plain = plainTerms.map(normalize).filter(Boolean);
  lines.forEach((line, i) => {
    const ln = i + 1;
    const push = (rule, match) => out.push({ rule, match, line: ln });
    const toks = skipTerms ? [] : tokenize(line);
    // n-grams of up to 4 tokens, joined without spaces, compared against the hashed / plain term lists
    const grams = new Set();
    for (let a = 0; a < toks.length; a++) for (let k = 1; k <= 4 && a + k <= toks.length; k++) {
      const joined = toks.slice(a, a + k).join('');
      grams.add(joined);
      if (hashes.has(sha256(joined))) push('client-term', toks.slice(a, a + k).join(' '));
    }
    // plain terms: whole n-gram match; terms of 6+ chars also match inside a single token
    // (a brand glued into an identifier). Short terms never substring-match ("zx" vs "fizz xylophone").
    for (const t of plain) {
      const sq = t.replace(/ /g, '');
      if (grams.has(sq) || (sq.length >= 6 && toks.some(tok => tok.includes(sq)))) push('client-term', t);
    }
    for (const m of line.matchAll(/\bTR\d{2}(?:\s?\d{4}){5}\s?\d{2}\b/gi)) push('iban', m[0]);
    for (const m of line.matchAll(/\b\d{11}\b/g)) if (tcknValid(m[0])) push('tckn', m[0]);
    for (const m of line.matchAll(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi)) if (!EMAIL_OK.test(m[0])) push('email', m[0]);
    for (const m of line.matchAll(/\b(?:\d{1,3}\.){3}\d{1,3}\b/g)) if (!IP_OK.has(m[0]) && m[0].split('.').every(x => +x <= 255)) push('ipv4', m[0]);
    for (const re of PHONE) for (const m of line.matchAll(re)) push('phone', m[0]);
    for (const m of line.matchAll(/(pass(word)?|secret|token|api[_-]?key|client[_-]?secret)['"]?\s*(=>|=|:)\s*['"]([^'"]{6,})['"]/gi)) push('secret-literal', m[0]);
    for (const m of line.matchAll(/define\s*\(\s*['"][A-Z0-9_]*(?:PASS|SECRET|KEY|TOKEN)[A-Z0-9_]*['"]\s*,\s*['"]([^'"]{6,})['"]/gi)) push('secret-literal', m[0]);
    for (const re of TOKENS) for (const m of line.matchAll(re)) push('token-shape', m[0]);
  });
  // de-duplicate identical rule+line+match
  return out.filter((f, i) => out.findIndex(g => g.rule === f.rule && g.line === f.line && g.match === f.match) === i);
}
