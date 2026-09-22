#!/usr/bin/env node
// Drives the product list cards: the drop price block, the paused state, the
// shop rows, filters, pagination, phone width and dark mode, on the product
// list and on the dashboard.
//
// Needs the throwaway account: run `php .github/eye-verify/product-cards-seed.php`
// first, and again with `--teardown` after. The fixture file it writes holds
// the e-mail and password and lives outside the repository.
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { createChecker, capturePageIssues } from '../../.claude/skills/frontend-quality/scripts/lib.mjs';

const BASE = process.env.BASE ?? 'https://dipcatch.test';
const ART = path.resolve('.github/eye-verify');
const FIXTURE = process.env.FIXTURE_PATH ?? '/tmp/tiles-eye-verify.json';

if (! fs.existsSync(FIXTURE)) {
    throw new Error(`Missing fixture at ${FIXTURE}. Seed the throwaway user first.`);
}

const fixture = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
const browser = await chromium.launch();
const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
const page = await ctx.newPage();
const issues = capturePageIssues(page);
const checker = createChecker({ page, artifactsDir: ART, label: 'product-cards' });

const card = (title) => page.locator('li[wire\\:key^="product-"]').filter({ hasText: title });
const firstParty = () => issues.pageErrors.length === 0
    && issues.failedRequests.filter((request) => request.includes(new URL(BASE).host)).length === 0;

{
    const response = await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    const title = await page.title();

    if (! title.includes('DipCatch')) {
        console.error(`Not a DipCatch page: ${title}`);
        process.exit(2);
    }

    checker.check('login page answers 200', response?.status() === 200, String(response?.status()));
    await page.locator('input[name="email"]').fill(fixture.email);
    await page.locator('input[name="password"]').fill(fixture.password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL((url) => url.pathname.startsWith('/app'), { timeout: 15000 });
}

{
    await page.goto(`${BASE}/app/products`, { waitUntil: 'networkidle' });
    checker.check('list boots without first-party failures', firstParty(), [...issues.pageErrors, ...issues.failedRequests].join('; '));
    checker.check('list renders cards, not a table', (await page.locator('[data-flux-table]').count()) === 0);
    checker.check('page one holds 30 cards', (await page.locator('li[wire\\:key^="product-"]').count()) === 30);

    const oil = card('EV Drop Olive Oil');
    const oilText = (await oil.innerText()).replace(/\s+/g, ' ');
    checker.check('drop card shows the best price now', oilText.includes('€11.95'), oilText);
    checker.check('drop card strikes through the price before the drop', (await oil.locator('del').first().innerText()).trim() === '€14.10');
    checker.check('struck price carries the alert "Was" label', (await oil.locator('del').first().getAttribute('title')) === 'Was €14.10');
    checker.check('drop card shows the drop percent', /−1[45]%/.test(await oil.locator('[data-test="drop-badge"]').innerText()));
    checker.check('drop price is orange while active', (await oil.locator('.text-orange-600').count()) > 0);
    checker.check('card shows its category', await oil.locator('[data-test="product-category-badge"]').isVisible());
    checker.check('card lists three shops and a more-link', (await oil.locator('ul li').count()) === 4 && oilText.includes('+1 more shop'), oilText);
    checker.check('cheapest shop is listed first', (await oil.locator('ul li').first().innerText()).includes('bol.com'));
    checker.check('shop with a promotion states its end', oilText.includes('ah.nl · until'), oilText);

    const ahRow = oil.locator('ul li').filter({ hasText: 'until' }).first();
    const hostBox = await ahRow.locator('a span span').first().boundingBox();
    const dealBox = await ahRow.locator('span.truncate').boundingBox();
    const hostMid = hostBox.y + (hostBox.height / 2);
    const dealMid = dealBox.y + (dealBox.height / 2);
    checker.check('shop host and deal period share a vertical centre', Math.abs(hostMid - dealMid) <= 1.5, `host ${hostMid.toFixed(1)} deal ${dealMid.toFixed(1)}`);
    await ahRow.screenshot({ path: path.join(ART, 'product-cards-shop-row.png') });

    const crisps = card('EV Unit Drop Crisps');
    const crispsText = (await crisps.innerText()).replace(/\s+/g, ' ');
    checker.check('a per-unit drop states its "Was" unit price', /Was €9\.99\s*\/\s*kg/i.test(crispsText) || crispsText.includes('Was €9.99'), crispsText);
    checker.check('best value names the other shop', crispsText.includes('Best value') && crispsText.includes('lidl.nl') && crispsText.includes('€5.38'), crispsText);

    const soap = card('EV Paused Soap');
    const soapText = (await soap.innerText()).replace(/\s+/g, ' ');
    checker.check('paused card labels its price as the last read', soapText.includes('Last price read') && soapText.includes('€4.79'), soapText);
    const pausedLabel = soap.locator('[data-test="paused-label"]');
    const labelBox = await pausedLabel.boundingBox();
    const imageBox = await soap.locator('img').first().boundingBox();
    checker.check('paused label sits in the top right of the image', labelBox !== null && imageBox !== null
        && labelBox.y - imageBox.y < 30 && (imageBox.x + imageBox.width) - (labelBox.x + labelBox.width) < 30, JSON.stringify({ labelBox, imageBox }));
    checker.check('paused label is not faded with the image', await pausedLabel.evaluate((el) => getComputedStyle(el).opacity === '1' && getComputedStyle(el.parentElement).opacity === '1'));
    checker.check('an active card has no paused label', (await card('EV Drop Olive Oil').locator('[data-test="paused-label"]').count()) === 0);
    checker.check('the list offers no pause or resume control', (await page.getByRole('button', { name: /Pause tracking|Resume/ }).count()) === 0);
    checker.check('paused card has a dashed border', (await soap.locator('article').getAttribute('class')).includes('border-dashed'));
    checker.check('paused card greys its image', (await soap.locator('.grayscale').count()) === 1);

    const pausedDrop = card('EV Paused Drop Coffee');
    checker.check('paused drop keeps the old price and percent', (await pausedDrop.locator('del').count()) === 1 && (await pausedDrop.locator('[data-test="drop-badge"]').count()) === 1);
    checker.check('paused drop price is not orange', (await pausedDrop.locator('.text-orange-600').count()) === 0);

    const empty = card('EV No Price Thing');
    const emptyText = (await empty.innerText()).replace(/\s+/g, ' ');
    checker.check('a card without a pack size has no lone dash under the price', ! /−1[45]% — /.test(oilText), oilText);
    checker.check('a product without a price shows a dash and no shops', emptyText.includes('—') && (await empty.locator('ul li').count()) === 0, emptyText);

    await page.screenshot({ path: path.join(ART, 'product-cards-desktop.png') });
}

{
    const coffee = card('EV Paused Drop Coffee');
    const box = await coffee.boundingBox();
    await page.mouse.click(box.x + box.width / 2, box.y + box.height * 0.25);
    await page.waitForURL((url) => /\/app\/products\/[^/]+$/.test(url.pathname), { timeout: 10000 }).catch(() => {});
    checker.check('a click on the card image opens the product', /\/app\/products\/[^/]+$/.test(new URL(page.url()).pathname) && await page.getByRole('heading', { name: 'EV Paused Drop Coffee' }).first().isVisible().catch(() => false), page.url());

    await page.goto(`${BASE}/app/products`, { waitUntil: 'networkidle' });
    const oil = card('EV Drop Olive Oil');
    const shopLink = oil.locator('ul a[target="_blank"]').first();
    const [popup] = await Promise.all([
        page.waitForEvent('popup', { timeout: 8000 }).catch(() => null),
        shopLink.click(),
    ]);
    checker.check('a shop link in the card still opens the shop', popup !== null && popup.url().includes('bol.com'), popup?.url() ?? 'no popup');
    await popup?.close();
    checker.check('the shop click did not open the product', new URL(page.url()).pathname === '/app/products', page.url());

    // A real pointer click at the badge: the stretched link covers it, which
    // is the point, so a locator click refuses it as intercepted.
    const badgeBox = await oil.locator('[data-test="drop-badge"]').boundingBox();
    await page.mouse.click(badgeBox.x + badgeBox.width / 2, badgeBox.y + badgeBox.height / 2);
    await page.waitForURL((url) => /\/app\/products\/[^/]+$/.test(url.pathname), { timeout: 10000 }).catch(() => {});
    checker.check('a click on the price opens the product', /\/app\/products\/[^/]+$/.test(new URL(page.url()).pathname), page.url());
    await page.goto(`${BASE}/app/products`, { waitUntil: 'networkidle' });
}

{
    // Keyboard: Tab from the sort select to the first card and check the focus
    // ring is drawn inside the card, where overflow-hidden cannot clip it.
    await page.locator('[data-test="product-sort"]').focus();
    let onCard = false;
    for (let i = 0; i < 10 && ! onCard; i++) {
        await page.keyboard.press('Tab');
        onCard = await page.evaluate(() => document.activeElement?.closest('[data-test="product-card"]') !== null);
    }
    const ring = await page.evaluate(() => {
        const style = getComputedStyle(document.activeElement, '::after');
        return { width: style.outlineWidth, offset: style.outlineOffset, style: style.outlineStyle };
    });
    checker.check('Tab reaches a card', onCard);
    checker.check('the focused card draws a ring inside its edge', ring.style !== 'none' && parseFloat(ring.width) > 0 && parseFloat(ring.offset) < 0, JSON.stringify(ring));
    const focused = page.locator('[data-test="product-card"]').filter({ has: page.locator(':focus') });
    await focused.screenshot({ path: path.join(ART, 'product-cards-focus.png') });
}

{
    await page.locator('[data-flux-radio-group-segmented]').getByRole('radio', { name: 'Paused' }).click();
    await page.waitForFunction(() => document.querySelectorAll('li[wire\\:key^="product-"]').length === 2, null, { timeout: 8000 }).catch(() => {});
    checker.check('Paused filter shows the two paused cards', (await page.locator('li[wire\\:key^="product-"]').count()) === 2);
    await page.getByRole('radio', { name: 'All' }).click();

    await page.getByPlaceholder('Search your products').fill('zzz-nothing');
    const emptyShown = await page.getByText('No product matches that search.').waitFor({ timeout: 8000 }).then(() => true).catch(() => false);
    checker.check('a search with no match shows the empty message', emptyShown);
    await page.getByPlaceholder('Search your products').fill('');
    await page.waitForFunction(() => document.querySelectorAll('li[wire\\:key^="product-"]').length === 30, null, { timeout: 8000 }).catch(() => {});

    await page.goto(`${BASE}/app/products?page=2`, { waitUntil: 'networkidle' });
    checker.check('page two holds the remaining six cards', (await page.locator('li[wire\\:key^="product-"]').count()) === 6);
}

{
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${BASE}/app/products`, { waitUntil: 'networkidle' });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    checker.check('phone width has no sideways scroll', ! overflow);
    const oil = card('EV Drop Olive Oil');
    const box = await oil.boundingBox();
    checker.check('phone width shows one card per row', box !== null && box.width > 300, String(box?.width));
    await oil.scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(ART, 'product-cards-mobile.png') });
}

{
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.evaluate(() => localStorage.setItem('flux.appearance', 'dark'));
    await page.goto(`${BASE}/app/products`, { waitUntil: 'networkidle' });
    checker.check('dark mode is applied', await page.evaluate(() => document.documentElement.classList.contains('dark')));
    await page.screenshot({ path: path.join(ART, 'product-cards-dark.png') });
    await page.evaluate(() => localStorage.removeItem('flux.appearance'));
}

{
    await page.goto(`${BASE}/app`, { waitUntil: 'networkidle' });
    const section = (name) => page.locator('div.mt-8').filter({ has: page.getByRole('heading', { name, level: 2 }) }).first();
    const recent = section('Recently tracked');
    const drops = section('Active drops');
    checker.check('dashboard recently tracked uses product cards', (await recent.locator('article').count()) === 5);
    checker.check('dashboard active drops uses product cards, the paused drop included', (await drops.locator('article').count()) === 3, String(await drops.locator('article').count()));
    checker.check('dashboard has no table left', (await page.locator('[data-flux-table]').count()) === 0);

    const oil = drops.locator('article').filter({ hasText: 'EV Drop Olive Oil' });
    const oilText = (await oil.innerText()).replace(/\s+/g, ' ');
    checker.check('dashboard drop card shows price, old price and percent', oilText.includes('€11.95') && oilText.includes('€14.10') && /−1[45]%/.test(oilText), oilText);
    checker.check('dashboard drop card says when the drop started', /Dropped .*ago/.test(oilText), oilText);
    checker.check('dashboard card leaves out the shop comparison', ! oilText.includes('jumbo.com') && ! oilText.includes('more shop'), oilText);
    checker.check('dashboard card names the cheapest shop', oilText.includes('bol.com'), oilText);

    const crisps = drops.locator('article').filter({ hasText: 'EV Unit Drop Crisps' });
    const crispsText = (await crisps.innerText()).replace(/\s+/g, ' ');
    checker.check('dashboard card leaves out unit price and best value', ! crispsText.includes('/kg Best value') && ! crispsText.includes('lidl.nl') && ! crispsText.includes('€8.45'), crispsText);

    const paused = recent.locator('article').filter({ hasText: 'EV Paused Soap' });
    checker.check('dashboard shows a paused product as paused', await paused.locator('[data-test="paused-label"]').isVisible());

    const [popup] = await Promise.all([
        page.waitForEvent('popup', { timeout: 8000 }).catch(() => null),
        oil.locator('a[target="_blank"]').first().click(),
    ]);
    checker.check('dashboard shop link opens the shop', popup !== null && popup.url().includes('bol.com'), popup?.url() ?? 'no popup');
    await popup?.close();

    const box = await oil.boundingBox();
    await page.mouse.click(box.x + box.width / 2, box.y + box.height * 0.25);
    await page.waitForURL((url) => /\/app\/products\/[^/]+$/.test(url.pathname), { timeout: 10000 }).catch(() => {});
    checker.check('dashboard card opens the product', /\/app\/products\/[^/]+$/.test(new URL(page.url()).pathname), page.url());

    await page.goto(`${BASE}/app`, { waitUntil: 'networkidle' });
    await page.screenshot({ path: path.join(ART, 'product-cards-dashboard.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${BASE}/app`, { waitUntil: 'networkidle' });
    checker.check('dashboard at phone width has no sideways scroll', ! await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth));
    await page.setViewportSize({ width: 1440, height: 1000 });
}

checker.check('no first-party failures over the whole run', firstParty(), [...issues.pageErrors, ...issues.failedRequests].join('; '));

await checker.summarize();
await browser.close();
