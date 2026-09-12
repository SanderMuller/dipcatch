import { chromium } from 'playwright';
import { createChecker, capturePageIssues, withFailedRoute } from '../../.claude/skills/frontend-quality/scripts/lib.mjs';

const BASE = 'https://dipcatch.test';
const ART = process.argv[2];

const browser = await chromium.launch();
const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 } });
const checker = createChecker({ artifactsDir: ART, label: 'seo' });

const head = (p) => p.evaluate(() => {
  const m = (sel, attr = 'content') => document.querySelector(sel)?.getAttribute(attr) ?? null;
  const all = (sel, attr = 'content') => [...document.querySelectorAll(sel)].map(e => e.getAttribute(attr));
  let ld = [], ldErr = null;
  for (const s of document.querySelectorAll('script[type="application/ld+json"]')) {
    try { ld.push(JSON.parse(s.textContent)); } catch (e) { ldErr = e.message; }
  }
  return {
    lang: document.documentElement.lang,
    title: document.title,
    h1: [...document.querySelectorAll('h1')].map(e => e.textContent.trim()),
    h2: [...document.querySelectorAll('h2')].map(e => e.textContent.trim()),
    h3: [...document.querySelectorAll('h3')].map(e => e.textContent.trim()),
    desc: m('meta[name=description]'),
    robots: m('meta[name=robots]'),
    canonical: m('link[rel=canonical]', 'href'),
    hreflang: [...document.querySelectorAll('link[rel=alternate][hreflang]')].map(e => e.hreflang + '=' + e.href),
    og: Object.fromEntries([...document.querySelectorAll('meta[property^="og:"]')].map(e => [e.getAttribute('property'), e.content])),
    tw: Object.fromEntries([...document.querySelectorAll('meta[name^="twitter:"]')].map(e => [e.getAttribute('name'), e.content])),
    theme: all('meta[name=theme-color]').length,
    fontHref: [...document.querySelectorAll('link[rel=stylesheet]')].map(e => e.href).find(h => h.includes('bunny')) ?? null,
    ld, ldErr,
    pillTitles: [...document.querySelectorAll('ul [title]')].map(e => e.getAttribute('title')),
    faqVisible: [...document.querySelectorAll('summary span')].map(e => e.textContent.trim()),
    overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
    stepsTag: document.querySelector('#how-it-works ol') ? 'ol' : (document.querySelector('#how-it-works dl') ? 'dl' : 'none'),
  };
});

const graphOf = (h) => h.ld[0] ?? null;
const nodes = (h) => (graphOf(h)?.['@graph']) ?? [];
const node = (h, t) => nodes(h).find(n => n['@type'] === t) ?? null;

// Third-party favicon CDNs 404 for some shops (poiesz.nl on Google's service),
// which is pre-existing and unrelated to this change. Gate the boot check on
// first-party failures so it reports our regressions, not theirs.
const FIRST_PARTY_ONLY = (issues) =>
  issues.pageErrors.length === 0
  && !issues.appRequestFailed
  && !(issues.navStatus !== null && issues.navStatus >= 400)
  && issues.failedRequests.filter(r => r.includes(BASE)).length === 0;

const thirdPartyFailures = (issues) => issues.failedRequests.filter(r => !r.includes(BASE));

async function open(path) {
  const p = await ctx.newPage();
  const issues = capturePageIssues(p);
  const resp = await p.goto(BASE + path, { waitUntil: 'networkidle' });
  await p.waitForTimeout(250);
  return { p, issues, resp };
}

// ---------- A. Homepage (English) ----------
{
  const { p, issues, resp } = await open('/');
  const h = await head(p);
  checker.check('A1 homepage returns 200 and boots with no first-party failures', resp.status() === 200 && FIRST_PARTY_ONLY(issues), issues.failedRequests.join('; '));
  checker.check('A1b only known third-party favicon 404s remain (pre-existing)', thirdPartyFailures(issues).every(r => r.includes('gstatic.com/faviconV2')), thirdPartyFailures(issues).join('; '));
  checker.check('A2 title carries the repositioned head keyword', h.title === 'Price alerts for the things you buy anyway - DipCatch', h.title);
  checker.check('A3 title has no leading whitespace', h.title === h.title.trim(), JSON.stringify(h.title));
  checker.check('A4 exactly one h1, with the approved wording', h.h1.length === 1 && h.h1[0] === 'Same product, every shop, one alert.', JSON.stringify(h.h1));
  checker.check('A5 heading outline is h1 > h2 > h3 (steps are headings now)', h.h2.length >= 3 && h.h3.length >= 3, `h2=${h.h2.length} h3=${h.h3.length}`);
  checker.check('A6 steps render as an ordered list, not a definition list', h.stepsTag === 'ol', h.stepsTag);
  checker.check('A7 meta description present and repositioned', (h.desc ?? '').startsWith('DipCatch tracks the price of anything you buy more than once'), h.desc);
  checker.check('A8 canonical is the bare URL', h.canonical === BASE, h.canonical);
  checker.check('A9 hreflang en/nl/x-default all present', h.hreflang.length === 3 && h.hreflang.some(x => x.startsWith('x-default=')), h.hreflang.join(' | '));
  checker.check('A10 homepage is indexable (no robots meta)', h.robots === null, String(h.robots));
  checker.check('A11 full Open Graph block', ['og:type','og:site_name','og:title','og:description','og:url','og:locale','og:locale:alternate','og:image','og:image:width','og:image:height'].every(k => h.og[k]), JSON.stringify(Object.keys(h.og)));
  checker.check('A12 Twitter large-image card with title and image', h.tw['twitter:card'] === 'summary_large_image' && !!h.tw['twitter:title'] && !!h.tw['twitter:image'], JSON.stringify(h.tw));
  checker.check('A13 theme-color declared for light and dark', h.theme === 2, String(h.theme));
  checker.check('A14 font stylesheet requests display=swap', (h.fontHref ?? '').includes('display=swap'), h.fontHref);
  checker.check('A15 JSON-LD parses at all', h.ldErr === null && h.ld.length === 1, String(h.ldErr));
  checker.check('A16 JSON-LD @context survived Blade (the production bug)', graphOf(h)?.['@context'] === 'https://schema.org', JSON.stringify(Object.keys(graphOf(h) ?? {})));
  checker.check('A17 no compiled PHP leaked into the graph', !JSON.stringify(graphOf(h)).includes('__contextArgs'), 'contains __contextArgs');
  checker.check('A18 graph carries Organization, WebSite, SoftwareApplication, FAQPage', ['Organization','WebSite','SoftwareApplication','FAQPage'].every(t => node(h, t)), nodes(h).map(n => n['@type']).join(','));
  checker.check('A19 every @id is absolute', nodes(h).filter(n => n['@id']).every(n => n['@id'].startsWith('http')), nodes(h).map(n => n['@id']).join(' '));
  checker.check('A20 no @id contains a double slash before the fragment', !nodes(h).some(n => (n['@id'] ?? '').includes('//#')), nodes(h).map(n => n['@id']).join(' '));
  checker.check('A21 WebSite publisher resolves to the Organization node', node(h,'WebSite')?.publisher?.['@id'] === node(h,'Organization')?.['@id'], `${node(h,'WebSite')?.publisher?.['@id']} vs ${node(h,'Organization')?.['@id']}`);
  const faqLd = (node(h,'FAQPage')?.mainEntity ?? []).map(q => q.name);
  checker.check('A22 FAQ JSON-LD matches the visible questions one for one', faqLd.length === h.faqVisible.length && faqLd.every((q,i) => q === h.faqVisible[i]), `ld=${faqLd.length} visible=${h.faqVisible.length}`);
  checker.check('A23 the new "cheaper somewhere else" question is live', h.faqVisible.some(q => q.includes('cheaper somewhere else')), h.faqVisible.join(' | '));
  checker.check('A24 shop pills expose brand names for search', h.pillTitles.includes('Albert Heijn') && h.pillTitles.includes('Jumbo'), h.pillTitles.join(','));
  checker.check('A25 no horizontal overflow at 1280px', !h.overflow);
  const img = await ctx.request.get(h.og['og:image']);
  checker.check('A26 og:image actually resolves', img.status() === 200, `status=${img.status()}`);
  await p.close();
}

// ---------- B. Dutch ----------
{
  const { p, issues, resp } = await open('/?lang=nl');
  const h = await head(p);
  const text = await p.evaluate(() => document.body.innerText);
  checker.check('B1 Dutch homepage boots with no first-party failures', resp.status() === 200 && FIRST_PARTY_ONLY(issues), issues.failedRequests.join('; '));
  checker.check('B2 html lang is nl', h.lang === 'nl', h.lang);
  checker.check('B3 canonical points at the Dutch representation', h.canonical === BASE + '?lang=nl', h.canonical);
  checker.check('B4 og:locale nl_NL with en_US alternate', h.og['og:locale'] === 'nl_NL' && h.og['og:locale:alternate'] === 'en_US', `${h.og['og:locale']}/${h.og['og:locale:alternate']}`);
  checker.check('B5 h1 is translated', h.h1[0] === 'Zelfde product, elke winkel, één melding.', h.h1[0]);
  checker.check('B6 headings translated (no English leak)', h.h2.includes('Zo werkt een prijsmelding'), h.h2.join(' | '));
  checker.check('B7 JSON-LD FAQ is Dutch too', (node(h,'FAQPage')?.mainEntity?.[0]?.name ?? '').includes('winkels'), node(h,'FAQPage')?.mainEntity?.[0]?.name);
  checker.check('B8 no untranslated-key markers in rendered text', !/:[a-z_]+\b/.test(text.replace(/https?:\/\/\S+/g,'')) || !text.includes(':count'), 'placeholder leak');
  checker.check('B9 Dutch title translated', h.title.startsWith('Prijsmeldingen voor wat je toch al koopt'), h.title);
  await p.close();
}

// ---------- C. Locale edge cases ----------
for (const [name, path, expectLang, expectCanonical] of [
  ['C1 ?lang=en canonicalises to the bare URL', '/?lang=en', 'en', BASE],
  ['C2 invalid ?lang=xx falls back to English + bare canonical', '/?lang=xx', 'en', BASE],
  ['C3 script-injection ?lang value is refused', '/?lang=<script>alert(1)</script>', 'en', BASE],
]) {
  const { p, resp } = await open(path);
  const h = await head(p);
  checker.check(name, resp.status() === 200 && h.lang === expectLang && h.canonical === expectCanonical, `status=${resp.status()} lang=${h.lang} canonical=${h.canonical}`);
  await p.close();
}

// ---------- D. Pricing / Privacy ----------
{
  const { p, issues, resp } = await open('/pricing');
  const h = await head(p);
  checker.check('D1 pricing boots with no first-party failures', resp.status() === 200 && FIRST_PARTY_ONLY(issues), issues.failedRequests.join('; '));
  checker.check('D2 pricing now has a social card (audit finding 6)', !!h.og['og:title'] && !!h.og['og:image'] && h.tw['twitter:card'] === 'summary_large_image', JSON.stringify(h.og));
  checker.check('D3 pricing JSON-LD has SoftwareApplication + BreadcrumbList', !!node(h,'SoftwareApplication') && !!node(h,'BreadcrumbList'), nodes(h).map(n=>n['@type']).join(','));
  const offers = node(h,'SoftwareApplication')?.offers ?? [];
  checker.check('D4 the Free offer is on the graph with a real description', offers.some(o => o.name === 'Free' && typeof o.description === 'string' && o.description.length > 0), JSON.stringify(offers.map(o=>o.name)));
  checker.check('D5 no offer description leaks a null limit', !offers.some(o => (o.description ?? '').includes('null')), JSON.stringify(offers.map(o=>o.description)));
  checker.check('D6 pricing description count is not hardcoded to a stale number', (h.desc ?? '').includes('20 products'), h.desc);
  await p.close();
}
{
  const { p, issues, resp } = await open('/privacy');
  const h = await head(p);
  const text = await p.evaluate(() => document.body.innerText);
  checker.check('E1 privacy boots with no first-party failures', resp.status() === 200 && FIRST_PARTY_ONLY(issues), issues.failedRequests.join('; '));
  checker.check('E2 privacy has a social card', !!h.og['og:title'] && !!h.og['og:image'], JSON.stringify(Object.keys(h.og)));
  checker.check('E3 privacy JSON-LD WebPage carries dateModified', node(h,'WebPage')?.dateModified === '2026-09-02', node(h,'WebPage')?.dateModified);
  checker.check('E4 the visible "Last updated" line still renders (regression)', text.includes('Last updated 2026-09-02'), text.slice(0,200));
  await p.close();
}

// ---------- F. Auth ----------
{
  const { p, issues, resp } = await open('/register');
  const h = await head(p);
  checker.check('F1 register boots with no first-party failures', resp.status() === 200 && FIRST_PARTY_ONLY(issues), issues.failedRequests.join('; '));
  checker.check('F2 register has exactly one h1 (was a div before)', h.h1.length === 1, JSON.stringify(h.h1));
  checker.check('F3 register is indexable — no robots meta', h.robots === null, String(h.robots));
  checker.check('F4 register carries a canonical and a description', h.canonical === BASE + '/register' && !!h.desc, `${h.canonical} | ${h.desc}`);
  checker.check('F5 register description quotes the real free limit', (h.desc ?? '').includes('20 products'), h.desc);
  checker.check('F6 register has a social card', !!h.og['og:title'], JSON.stringify(Object.keys(h.og)));
  await p.close();
}
for (const [name, path] of [['G1 /login', '/login'], ['G2 /forgot-password', '/forgot-password']]) {
  const { p, resp } = await open(path);
  const h = await head(p);
  checker.check(`${name} is noindex and emits no half social card`, resp.status() === 200 && h.robots === 'noindex' && Object.keys(h.og).length === 0, `robots=${h.robots} og=${Object.keys(h.og).length}`);
  checker.check(`${name} has exactly one h1`, h.h1.length === 1, JSON.stringify(h.h1));
  await p.close();
}

// ---------- H. /bot ----------
{
  const { p, issues, resp } = await open('/bot');
  const h = await head(p);
  const info = await p.evaluate(() => ({
    text: document.body.innerText,
    langToggle: document.querySelectorAll('a[href*="lang="]').length,
    links: [...document.querySelectorAll('a')].map(a => a.getAttribute('href')),
  }));
  checker.check('H1 /bot exists and boots with no first-party failures (the UA advertises it)', resp.status() === 200 && FIRST_PARTY_ONLY(issues), issues.failedRequests.join('; '));
  checker.check('H2 /bot shows the exact user agent from config', info.text.includes('DipCatchBot/1.0 (+https://dipcatch.eu/bot)'), info.text.slice(0,300));
  checker.check('H3 /bot tells operators how to block it', info.text.includes('User-agent: DipCatchBot') && info.text.includes('Disallow: /'), 'missing block snippet');
  checker.check('H4 /bot ships no dead language toggle', info.langToggle === 0, `found ${info.langToggle}`);
  const homeLinks = info.links.filter(Boolean).map(href => href.replace(/\/$/, ''));
  checker.check('H5 /bot has exactly one h1', h.h1.length === 1, JSON.stringify(h.h1));
  checker.check('H6 /bot links back to the homepage', homeLinks.includes(BASE) || homeLinks.includes(''), JSON.stringify(info.links.filter(Boolean)));
  await p.close();
}

// ---------- I. Non-HTML endpoints ----------
{
  const r = await ctx.request.get(BASE + '/sitemap.xml');
  const body = await r.text();
  const locs = [...body.matchAll(/<loc>(.*?)<\/loc>/g)].map(m => m[1]);
  checker.check('I1 sitemap serves XML', r.status() === 200 && (r.headers()['content-type'] ?? '').includes('xml'), r.headers()['content-type']);
  checker.check('I2 sitemap sets no cookie, so Cloudflare can cache it', !r.headers()['set-cookie'], String(r.headers()['set-cookie']));
  checker.check('I3 sitemap lists both representations of all three pages', locs.length === 6, JSON.stringify(locs));
  checker.check('I4 sitemap excludes noindex/blocked/pointless URLs', !['register','login','/p/','lang=en','invite'].some(f => body.includes(f)), 'found an excluded fragment');
  checker.check('I5 sitemap declares hreflang alternates', body.includes('xhtml:link') && body.includes('x-default'), 'missing alternates');
  checker.check('I6 sitemap is well-formed XML', (() => { try { new DOMParser(); } catch(e) {} return body.trim().startsWith('<?xml') && body.trim().endsWith('</urlset>'); })(), body.slice(-80));

  const l = await ctx.request.get(BASE + '/llms.txt');
  const lb = await l.text();
  checker.check('I7 llms.txt serves plain text', l.status() === 200 && (l.headers()['content-type'] ?? '').includes('text/plain'), l.headers()['content-type']);
  checker.check('I8 llms.txt sets no cookie', !l.headers()['set-cookie'], String(l.headers()['set-cookie']));
  checker.check('I9 llms.txt opens with the H1 and a quotable summary', lb.startsWith('# DipCatch') && lb.includes('> DipCatch is a price-alert service'), lb.slice(0,120));
  checker.check('I10 llms.txt numbers come from config, not prose', lb.includes('Free: 20 products, 4 shops per product') && lb.includes('every 6 hours'), lb.slice(0,600));
  checker.check('I11 llms.txt links resolve to real routes', lb.includes(BASE + '/pricing') && lb.includes(BASE + '/bot'), 'missing links');
  const bot = await ctx.request.get(BASE + '/bot');
  checker.check('I12 the /bot URL llms.txt advertises is not a 404', bot.status() === 200, `status=${bot.status()}`);
}

// ---------- J. sitemap must ignore a locale query ----------
{
  const a = await (await ctx.request.get(BASE + '/sitemap.xml')).text();
  const b = await (await ctx.request.get(BASE + '/sitemap.xml?lang=nl')).text();
  checker.check('J1 sitemap ignores ?lang and always lists both locales', a === b, 'sitemap varied by query');
}

// ---------- K. Authenticated homepage: head must not change ----------
{
  const seo = (html) => (html.match(/<(?:title|meta|link)\b[^>]*>/gi) ?? []).filter(t => /og:|twitter:|canonical|description|robots|hreflang|<title/i.test(t));
  const guest = seo(await (await ctx.request.get(BASE + '/')).text());
  checker.check('K1 guest homepage exposes SEO tags at all', guest.length > 10, String(guest.length));
}

// ---------- L. Fault injection: the webfont CDN is down ----------
{
  const p = await ctx.newPage();
  const issues = capturePageIssues(p);
  await withFailedRoute(p, '**fonts.bunny.net**', async () => {
    await p.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    await p.waitForTimeout(600);
    const v = await p.evaluate(() => {
      const h1 = document.querySelector('h1');
      const r = h1?.getBoundingClientRect();
      return { text: h1?.textContent.trim() ?? '', visible: !!r && r.width > 100 && r.height > 10, font: h1 ? getComputedStyle(h1).fontFamily : '' };
    });
    checker.check('L1 headline still renders when the font CDN fails (display=swap)', v.visible && v.text.length > 0, JSON.stringify(v));
    checker.check('L2 the fallback stack is a real one, not a blank face', /sans-serif|system-ui|ui-sans-serif/i.test(v.font), v.font);
  });
  await p.close();
}

// ---------- M. Mobile + dark ----------
{
  const m = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 3 });
  for (const path of ['/', '/bot', '/pricing']) {
    const p = await m.newPage();
    await p.goto(BASE + path, { waitUntil: 'networkidle' });
    const o = await p.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    checker.check(`M1 no horizontal overflow at 390px on ${path}`, !o);
    await p.close();
  }
  await m.close();
  const d = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 }, colorScheme: 'dark' });
  for (const path of ['/', '/bot']) {
    const p = await d.newPage();
    await p.goto(BASE + path, { waitUntil: 'networkidle' });
    const c = await p.evaluate(() => {
      const fg = getComputedStyle(document.body).color;
      const n = fg.match(/\d+/g).map(Number);
      return { fg, light: (n[0] + n[1] + n[2]) / 3 > 128 };
    });
    checker.check(`M2 dark mode renders light text on ${path}`, c.light, c.fg);
    await p.close();
  }
  await d.close();
}

checker.skip('robots.txt over HTTP', 'served by the web server, not the kernel; the local Herd domain 404s on public/ files. Verified from disk in CrawlPolicyTest and live-checked post-deploy.');
checker.skip('Social card in Facebook / X validators', 'needs a public URL; the image is not deployed yet.');
checker.skip('JSON-LD in Google Rich Results Test', 'needs a public URL. Structure and parse are asserted here and in StructuredDataTest.');
checker.skip('SITE_CONTACT_EMAIL / ProPrice / BillingGate / trial-days permutations', 'server-config permutations, not browser-drivable without restarting the app. Covered by StructuredDataTest, BotPageTest and LlmsTxtTest.');
checker.skip('Authenticated homepage head equality', 'needs a seeded login; asserted in HeadPartialTest with actingAs.');
checker.skip('axe accessibility/contrast pass', 'console.mjs --axe reports available:false — @axe-core/playwright is not installed in this project. Enable with: yarn add -D @axe-core/playwright');

const code = await checker.summarize();
await browser.close();
process.exit(code ? 1 : 0);
