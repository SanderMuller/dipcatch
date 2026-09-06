import { chromium } from 'playwright';

const BASE = process.env.BASE ?? 'https://dipcatch.test';
const SLUGS = ['groceries', 'pet-food', 'coffee', 'filters'];
let pass = 0, fail = 0;
const check = (n, ok, d = '') => { ok ? (pass++, console.log(`PASS  ${n}`)) : (fail++, console.log(`FAIL  ${n} ${d}`)); };

const browser = await chromium.launch();

for (const scheme of ['light', 'dark']) {
    for (const [label, width, height] of [['desktop', 1280, 900], ['mobile', 390, 844]]) {
        for (const lang of ['en', 'nl']) {
            const ctx = await browser.newContext({ viewport: { width, height }, colorScheme: scheme, ignoreHTTPSErrors: true });
            const page = await ctx.newPage();
            const errors = [];
            page.on('console', m => { if (m.type() === 'error' && ! m.text().includes('Failed to load resource')) errors.push(m.text()); });

            for (const slug of SLUGS) {
                const url = `${BASE}/price-alerts/${slug}${lang === 'nl' ? '?lang=nl' : ''}`;
                await page.goto(url, { waitUntil: 'networkidle' });
                const tag = `${scheme}/${label}/${lang}/${slug}`;

                const h1 = await page.locator('h1').allInnerTexts();
                check(`${tag} · exactly one h1, non-empty`, h1.length === 1 && h1[0].trim().length > 0, JSON.stringify(h1));

                const htmlLang = await page.evaluate(() => document.documentElement.lang);
                check(`${tag} · html lang is ${lang}`, htmlLang === lang, htmlLang);

                const toggle = await page.evaluate(() => document.querySelector('a[hreflang="nl"]')?.getAttribute('href') ?? null);
                check(`${tag} · language toggle points at this page`, toggle !== null && toggle.includes(`/price-alerts/${slug}`) && ! toggle.includes('view='), String(toggle));

                const sections = await page.locator('main h2').allInnerTexts();
                check(`${tag} · has example, shops and FAQ headings`, sections.length >= 3, JSON.stringify(sections));

                const shopPills = await page.evaluate(() => [...document.querySelectorAll('main li span[style*="background-image"]')].filter(e => e.getBoundingClientRect().width > 0).length);
                check(`${tag} · shop pills render`, shopPills > 0, String(shopPills));

                const cta = await page.evaluate(() => [...document.querySelectorAll('a[href$="/register"]')].length);
                check(`${tag} · register CTA present`, cta >= 1, String(cta));

                const scroll = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
                check(`${tag} · no horizontal scroll`, scroll === false);

                const current = await page.evaluate(() => document.querySelectorAll('[aria-current="page"]').length);
                check(`${tag} · at most one current nav link`, current <= 1, String(current));
            }

            check(`${scheme}/${label}/${lang} · no JavaScript console errors`, errors.length === 0, errors.join(' | '));

            if (scheme === 'light' && lang === 'en') {
                await page.goto(`${BASE}/price-alerts/groceries`, { waitUntil: 'networkidle' });
                await page.screenshot({ path: `.github/eye-verify/use-case-${label}.png`, fullPage: true });
            }
            await ctx.close();
        }
    }
}

await browser.close();
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
