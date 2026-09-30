#!/usr/bin/env node
// Drives "Add suggested shops to your products" on the dashboard: it loads
// after the page, sits beside "Worth a look" on a wide screen and under it on
// a phone, shows a card per product with the most likely match first, opens
// a shop's page in a new tab, and Add opens the product with the add-shop form.
//
// Needs the throwaway account: run `php .github/eye-verify/dashboard-suggestions-seed.php`
// first, and again with `--teardown` after.
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { createChecker, capturePageIssues } from '../../.claude/skills/frontend-quality/scripts/lib.mjs';

const BASE = process.env.BASE ?? 'https://dipcatch.test';
const ART = path.resolve('.github/eye-verify');
const FIXTURE = process.env.FIXTURE_PATH ?? '/tmp/dashboard-suggestions-eye-verify.json';

if (! fs.existsSync(FIXTURE)) {
    throw new Error(`Missing fixture at ${FIXTURE}. Seed the throwaway user first.`);
}

const fixture = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
const browser = await chromium.launch();
const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1100 } });
const page = await ctx.newPage();
const issues = capturePageIssues(page);
const checker = createChecker({ page, artifactsDir: ART, label: 'dashboard-suggestions' });

{
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    const title = await page.title();

    if (! title.includes('DipCatch')) {
        console.error(`Not a DipCatch page: ${title}`);
        process.exit(2);
    }

    await page.locator('input[name="email"]').fill(fixture.email);
    await page.locator('input[name="password"]').fill(fixture.password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL((url) => url.pathname.startsWith('/app'), { timeout: 15000 });
}

const section = () => page.locator('[data-test="suggested-shops"]');
const worth = () => page.locator('[data-test="worth-a-look"]');

{
    await page.goto(`${BASE}/app`, { waitUntil: 'domcontentloaded' });
    const rows = section().locator('[data-test="suggested-shop"]');
    const loaded = await rows.first().waitFor({ state: 'visible', timeout: 20000 }).then(() => true).catch(() => false);
    checker.check('the suggestions load after the page', loaded);

    const count = await rows.count();
    checker.check('several suggestions show, at most eight', count > 1 && count <= 8, String(count));

    const first = (await rows.first().innerText()).replace(/\s+/g, ' ');
    checker.check('the most likely match comes first, with its confidence', first.includes('koffiehenk.nl') && first.includes('99% match'), first);

    const card = (await section().locator('[data-test="suggested-product"]').first().innerText()).replace(/\s+/g, ' ');
    checker.check('the card shows the product and what it costs now', card.includes('Douwe Egberts') && card.includes('Now €18.99'), card);

    const texts = (await rows.allInnerTexts()).map((text) => text.replace(/\s+/g, ' '));
    checker.check('a shop Jev has not answered says Name match', texts.some((text) => text.includes('Name match')), texts.join(' | '));
    checker.check('no row suggests the shop already tracked', texts.every((text) => ! text.includes('eye-verify-shop.test')));

    const open = rows.first().locator('[data-test="suggested-shop-open"]');
    checker.check('Open goes to the shop page in a new tab', (await open.getAttribute('href')) === 'https://koffiehenk.nl/douwe-egberts-aroma-rood-bonen' && (await open.getAttribute('target')) === '_blank', await open.getAttribute('href'));
    checker.check('Open says what it does to a screen reader', (await open.getAttribute('aria-label')) === 'Open koffiehenk.nl in a new tab to check the product');

    const worthBox = await worth().boundingBox();
    const sectionBox = await section().boundingBox();
    checker.check('side by side on a wide screen, each about half', worthBox !== null && sectionBox !== null
        && Math.abs(worthBox.y - sectionBox.y) < 4
        && sectionBox.x > worthBox.x + worthBox.width
        && Math.abs(worthBox.width - sectionBox.width) < 4, JSON.stringify({ worthBox, sectionBox }));
    await page.screenshot({ path: path.join(ART, 'dashboard-suggestions-desktop.png') });

    await page.setViewportSize({ width: 390, height: 900 });
    const phoneWorth = await worth().boundingBox();
    const phoneSection = await section().boundingBox();
    checker.check('stacked on a phone, suggestions under Worth a look', phoneWorth !== null && phoneSection !== null && phoneSection.y > phoneWorth.y + phoneWorth.height - 1, JSON.stringify({ phoneWorth, phoneSection }));
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    checker.check('no sideways scroll on a phone', ! overflow);
    await section().screenshot({ path: path.join(ART, 'dashboard-suggestions-phone.png') });
    await page.setViewportSize({ width: 1440, height: 1100 });
}

{
    const add = section().locator('[data-test="suggested-shop-add"]').first();
    const name = await add.getAttribute('aria-label');
    checker.check('Add names the shop and the product for a screen reader', name === 'Add koffiehenk.nl to Douwe Egberts Aroma Rood koffiebonen 1 kg', name);
    await add.click();
    await page.waitForURL((url) => url.pathname.includes(fixture.coffeeId), { timeout: 15000 });
    checker.check('Add opens the product with the add-shop form', new URL(page.url()).searchParams.get('add-shop') === '1', page.url());
    const webRow = page.locator('[data-test="web-suggestion"]:visible').first();
    // The add-shop form holds its own copy of the list; the closed panel copy stays hidden.
    checker.check('the suggestion is there to add on the product page', await webRow.waitFor({ state: 'visible', timeout: 10000 }).then(() => true).catch(() => false));
}

checker.check('no first-party failures over the whole run', issues.pageErrors.length === 0
    && issues.failedRequests.filter((request) => request.includes(new URL(BASE).host)).length === 0,
    [...issues.pageErrors, ...issues.failedRequests].join('; '));

await checker.summarize();
await browser.close();
