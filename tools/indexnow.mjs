// Submits every URL in the live sitemap to IndexNow (Bing, Yandex, Seznam, Naver…).
// Run after a deploy: npm run indexnow
import { INDEXNOW_KEY } from '../site/build.mjs';

const SITE = 'https://wordpress.erusoft.com';
const host = new URL(SITE).host;

const keyRes = await fetch(`${SITE}/${INDEXNOW_KEY}.txt`);
if (!keyRes.ok || (await keyRes.text()).trim() !== INDEXNOW_KEY) {
  console.error(`indexnow: ${SITE}/${INDEXNOW_KEY}.txt is not live yet; deploy first`);
  process.exit(1);
}

const sitemap = await (await fetch(`${SITE}/sitemap.xml`)).text();
const urlList = [...sitemap.matchAll(/<loc>([^<]+)<\/loc>/g)].map(m => m[1]);

const res = await fetch('https://api.indexnow.org/indexnow', {
  method: 'POST',
  headers: { 'content-type': 'application/json; charset=utf-8' },
  body: JSON.stringify({ host, key: INDEXNOW_KEY, keyLocation: `${SITE}/${INDEXNOW_KEY}.txt`, urlList }),
});
console.log(`indexnow: ${urlList.length} URLs -> HTTP ${res.status}`);
if (!res.ok && res.status !== 202) process.exit(1);
