#!/usr/bin/env node
// Drives specs/price-changes-log.md in the browser: the collapsed "Show price
// changes" toggle under the product chart opens a list of lowest-price
// changes, each naming what DipCatch did, a caught wrong price first among
// them. Checked in light, dark and at 390 px; a failing request shows an error.
//
// Needs `php .github/eye-verify/price-changes-seed.php`; tear down with
// `php .github/eye-verify/price-changes-seed.php --teardown`.
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { createChecker, capturePageIssues } from '../../.claude/skills/frontend-quality/scripts/lib.mjs';

const BASE = process.env.BASE ?? 'https://dipcatch.test';
const ART = path.resolve('.github/eye-verify');
const FIXTURE = process.env.FIXTURE_PATH ?? '/tmp/price-changes-eye-verify.json';

if (! fs.existsSync(FIXTURE)) {
    throw new Error(`Missing fixture at ${FIXTURE}. Seed the throwaway user first.`);
}

const fixture = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
const browser = await chromium.launch();
const flat = (text) => text.replace(/\s+/g, ' ').trim();

async function session(options) {
    const ctx = await browser.newContext({ ignoreHTTPSErrors: true, ...options });
    const page = await ctx.newPage();
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    if (! (await page.title()).includes('DipCatch')) {
        console.error('Not a DipCatch page at ' + BASE);
        process.exit(2);
    }
    await page.locator('input[name="email"]').fill(fixture.email);
    await page.locator('input[name="password"]').fill(fixture.password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL((url) => url.pathname.startsWith('/app'), { timeout: 15000 });
    await page.goto(`${BASE}/app/products/${fixture.productId}`, { waitUntil: 'networkidle' });

    return { ctx, page };
}

async function open(page) {
    await page.getByRole('button', { name: 'Show price changes' }).click();
    await page.locator('#price-changes-list').waitFor();
}

{
    const { page } = await session({ viewport: { width: 1440, height: 1000 } });
    const issues = capturePageIssues(page);
    const checker = createChecker({ page, artifactsDir: ART, label: 'price-changes' });

    checker.check('the list starts collapsed', (await page.locator('#price-changes-list').count()) === 0);
    const toggle = page.getByRole('button', { name: 'Show price changes' });
    checker.check('the toggle says it is collapsed', (await toggle.getAttribute('aria-expanded')) === 'false');

    await open(page);
    const rows = (await page.locator('#price-changes-list li').allInnerTexts()).map(flat);
    checker.check('six changes, newest first', rows.length === 6 && rows[0].includes('€2.49') && rows[5].includes('€8.99'), rows.join(' | '));
    checker.check('the newest is waiting for its second reading', rows[0].includes('checking it again'), rows[0]);
    checker.check('the reached alert price is named', rows[1].includes('Reached your alert price'), rows[1]);
    checker.check('the return from the dip says nothing', ! /alert|Large drop/.test(rows[2]), rows[2]);
    checker.check('the dip says DipCatch caught a wrong price', rows[3].includes('€7.31') && rows[3].includes('€3.39') && rows[3].includes('the price was gone, so no alert') && rows[3].includes('koopjesdrogisterij.nl'), rows[3]);
    checker.check('the alert is named', rows[4].includes('Price alert'), rows[4]);
    checker.check('the toggle now says it is open', (await page.getByRole('button', { name: 'Hide price changes' }).getAttribute('aria-expanded')) === 'true');
    checker.check('no Show more for six rows', (await page.getByRole('button', { name: 'Show more' }).count()) === 0);
    await page.locator('[data-test="price-changes"]').screenshot({ path: path.join(ART, 'price-changes-light.png') });

    // The range follows the chart: 1M still holds every seeded change.
    await page.locator('[data-test="price-history-range"]').getByText('1M', { exact: true }).click();
    await page.waitForTimeout(800);
    checker.check('a range change keeps the list open', (await page.locator('#price-changes-list li').count()) === 6);

    // A failing request: the toggle must not leave a silent dead button.
    await page.getByRole('button', { name: 'Hide price changes' }).click();
    await page.waitForTimeout(500);
    await page.route('**/livewire*/update', (route) => route.fulfill({ status: 500, body: 'boom' }));
    await page.getByRole('button', { name: 'Show price changes' }).click();
    await page.waitForTimeout(1200);
    const errorShown = await page.evaluate(() => document.body.innerText.toLowerCase().includes('went wrong') || document.body.innerText.toLowerCase().includes('try again'));
    checker.check('a failing request shows an error', errorShown);
    await page.unroute('**/livewire*/update');
    await page.reload({ waitUntil: 'networkidle' });
    await open(page);
    checker.check('after the fault clears, the list opens again', (await page.locator('#price-changes-list li').count()) === 6);

    checker.check('no page errors', issues.pageErrors.length === 0, issues.pageErrors.join('; '));
    await checker.summarize();
}

{
    const { page } = await session({ viewport: { width: 1440, height: 1000 }, colorScheme: 'dark' });
    await page.evaluate(() => document.documentElement.classList.add('dark'));
    await open(page);
    await page.locator('[data-test="price-changes"]').screenshot({ path: path.join(ART, 'price-changes-dark.png') });
}

{
    const { page } = await session({ viewport: { width: 390, height: 844 } });
    await open(page);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    console.log(overflow ? 'FAIL the page scrolls sideways at 390 px' : 'PASS no sideways scroll at 390 px');
    await page.locator('[data-test="price-changes"]').screenshot({ path: path.join(ART, 'price-changes-390.png') });
}

await browser.close();
