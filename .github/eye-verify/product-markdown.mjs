#!/usr/bin/env node
// Drives the markdown copies of a product: the owner's /app/products/{id}.md
// and the public /p/{slug}.md. Checks that a browser shows them as text, that
// the figures match the HTML pages, that access matches the pages, and that the
// public copy carries no private field.
//
// Needs the throwaway accounts: run `php .github/eye-verify/product-markdown-seed.php`
// first, and again with `--teardown` after. The fixture file it writes holds
// the e-mail and password and lives outside the repository.
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { createChecker, capturePageIssues } from '../../.claude/skills/frontend-quality/scripts/lib.mjs';

const BASE = process.env.BASE ?? 'https://dipcatch.test';
const ART = path.resolve('.github/eye-verify');
const FIXTURE = process.env.FIXTURE_PATH ?? '/tmp/markdown-eye-verify.json';

if (! fs.existsSync(FIXTURE)) {
    throw new Error(`Missing fixture at ${FIXTURE}. Seed the throwaway accounts first.`);
}

const fixture = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
const browser = await chromium.launch();
const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();
const issues = capturePageIssues(page);
const checker = createChecker({ page, artifactsDir: ART, label: 'product-markdown' });

/**
 * Open a URL the way a person would, and report whether the browser showed it
 * or started a download instead.
 */
async function open(url) {
    let downloaded = false;
    const onDownload = () => { downloaded = true; };
    page.on('download', onDownload);
    let response = null;

    try {
        response = await page.goto(url, { waitUntil: 'load' });
    } catch (error) {
        if (! String(error).includes('Download is starting')) {
            throw error;
        }
        downloaded = true;
    }

    page.off('download', onDownload);

    return { response, downloaded, text: downloaded ? '' : await page.locator('body').innerText() };
}

const ownerMd = `${BASE}/app/products/${fixture.productId}.md`;
const publicMd = `${BASE}/p/${fixture.slug}.md`;

// Guest first, before any session exists.
{
    const guest = await open(ownerMd);
    checker.check('guest: owner .md sends to the login page', page.url().includes('/login'), page.url());

    const title = await page.title();
    if (! title.includes('DipCatch')) {
        console.error(`Not a DipCatch page: ${title}`);
        process.exit(2);
    }
    void guest;
}

{
    const shared = await open(publicMd);
    checker.check('public .md answers 200 to a guest', shared.response?.status() === 200, String(shared.response?.status()));
    checker.check('public .md is shown, not downloaded', ! shared.downloaded);

    const headers = shared.response?.headers() ?? {};
    checker.check('public .md is text/markdown', (headers['content-type'] ?? '').startsWith('text/markdown'), headers['content-type']);
    checker.check('public .md is noindex', headers['x-robots-tag'] === 'noindex, nofollow', headers['x-robots-tag']);
    checker.check('public .md sends nosniff', headers['x-content-type-options'] === 'nosniff', headers['x-content-type-options']);

    const text = shared.text;
    checker.check('public .md: raw ampersand in the title', text.startsWith('# Beans & more'), text.slice(0, 40));
    checker.check('public .md: leads with the best value per kilo', text.includes('## Best value') && text.includes('€9.00 /kg at [ah.nl]') && text.includes('€9.00 for 1 kg'), text);
    checker.check('public .md: lowest-price note', text.includes('> Lowest price: €6.00 for 500 g at jumbo.com. That is 33% more per kilo than the best value.'), text);
    checker.check('public .md: shop count', text.includes('Compared across 3 shops tracked.'), text);
    checker.check('public .md: shops table per kilo first', text.includes('| Shop | Price per kilo | Pack price | Price read |'), text);
    checker.check('public .md: pipe in a URL is escaped', text.includes('variant=a\\|b'), text);
    checker.check('public .md: no shop note', ! text.includes('EVSECRET10'));
    checker.check('public .md: no alert rule', ! text.includes('drop') && ! text.includes('Alerts'));
    checker.check('public .md: no link into the app', ! text.includes('/app/'));
    await page.screenshot({ path: path.join(ART, 'product-markdown-public.png'), fullPage: true });

    await page.goto(`${BASE}/p/${fixture.slug}`, { waitUntil: 'load' });
    const html = await page.locator('main').innerText();
    checker.check('public HTML page shows the same figures', html.includes('€9.00 /kg') && html.includes('€6.00 for 500 g'), html.slice(0, 300));
}

{
    const missing = await open(`${BASE}/p/${'z'.repeat(32)}.md`);
    checker.check('public .md: unknown slug is 404', missing.response?.status() === 404, String(missing.response?.status()));
}

// Sign in as the owner.
{
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    await page.locator('input[name="email"]').fill(fixture.email);
    await page.locator('input[name="password"]').fill(fixture.password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL((url) => url.pathname.startsWith('/app'), { timeout: 15000 });
}

{
    const owned = await open(ownerMd);
    checker.check('owner .md answers 200', owned.response?.status() === 200, String(owned.response?.status()));
    checker.check('owner .md stays on its URL, not the login page', ! page.url().includes('/login'), page.url());
    checker.check('owner .md is shown, not downloaded', ! owned.downloaded);

    const headers = owned.response?.headers() ?? {};
    checker.check('owner .md is text/markdown', (headers['content-type'] ?? '').startsWith('text/markdown'), headers['content-type']);
    checker.check('owner .md is private, no-store', (headers['cache-control'] ?? '').includes('no-store') && (headers['cache-control'] ?? '').includes('private'), headers['cache-control']);

    const text = owned.text;
    checker.check('owner .md: raw ampersand in the title', text.startsWith('# Beans & more'), text.slice(0, 40));
    checker.check('owner .md: leads with the best value per kilo', text.includes('## Best value') && text.includes('€9.00 /kg at [ah.nl]'), text);
    checker.check('owner .md: lowest-price note with the gap', /> Lowest price: €6\.00 for [^\n]* at jumbo\.com\. That is \d+% more per kilo than the best value\./.test(text), text);
    checker.check('owner .md: alerts', text.includes('- €5.00 or less') && text.includes('- 15% drop'), text);
    checker.check('owner .md: shops table header', text.includes('| Shop | Price per kilo | Pack price | In stock | Price read |'), text);
    checker.check('owner .md: pipe in a URL is escaped', text.includes('variant=a\\|b'), text);
    checker.check('owner .md: link back to the page', text.includes(`/app/products/${fixture.productId}`), text);
    await page.screenshot({ path: path.join(ART, 'product-markdown-owner.png'), fullPage: true });

    await page.goto(`${BASE}/app/products/${fixture.productId}`, { waitUntil: 'networkidle' });
    const html = await page.locator('body').innerText();
    checker.check('owner HTML page still loads', html.includes('Beans & more') && html.includes('Best value'), page.url());
    checker.check('owner HTML page shows the same alert rules', html.includes('€5.00') && html.includes('15% drop'));
}

{
    const foreign = await open(`${BASE}/app/products/${fixture.foreignProductId}.md`);
    checker.check("another account's product .md is 403", foreign.response?.status() === 403, String(foreign.response?.status()));

    const bad = await open(`${BASE}/app/products/not-a-uuid.md`);
    checker.check('owner .md: a non-uuid id is 404', bad.response?.status() === 404, String(bad.response?.status()));
}

checker.check('no page errors', issues.pageErrors.length === 0, issues.pageErrors.join('; '));

await browser.close();
await checker.summarize();
