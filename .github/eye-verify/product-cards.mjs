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
    // Newest first, so the fixture's key products share page one; the default
    // sort is checked on its own below.
    await page.goto(`${BASE}/app/products?sort=created_at`, { waitUntil: 'networkidle' });
    checker.check('list boots without first-party failures', firstParty(), [...issues.pageErrors, ...issues.failedRequests].join('; '));
    checker.check('list renders cards, not a table', (await page.locator('[data-flux-table]').count()) === 0);
    checker.check('page one holds 24 cards', (await page.locator('li[wire\\:key^="product-"]').count()) === 24);

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
    checker.check('card leads with the best value per kilo and its pack', crispsText.startsWith('EV Unit Drop Crisps €5.38 /kg') && crispsText.includes('€1.99 for 370 g'), crispsText);
    checker.check('card notes the lowest pack price at the other shop', crispsText.includes('Lowest price €1.69 for 200 g at ah.nl'), crispsText);
    checker.check('card lists the best-value shop first, by price per kilo', /lidl\.nl €5\.38 \/kg €1\.99 ah\.nl €8\.45 \/kg €1\.69/.test(crispsText), crispsText);

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
    await page.waitForFunction(() => document.querySelectorAll('li[wire\\:key^="product-"]').length === 24, null, { timeout: 8000 }).catch(() => {});

    await page.goto(`${BASE}/app/products?page=2`, { waitUntil: 'networkidle' });
    checker.check('page two holds the remaining twelve cards', (await page.locator('li[wire\\:key^="product-"]').count()) === 12);
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

    // Secondary text on a dark card must stay readable: WCAG AA, 4.5:1.
    const lowContrast = await page.evaluate(() => {
        const luminance = (rgb) => {
            const [r, g, b] = rgb.map((v) => {
                const c = v / 255;

                return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
            });

            return 0.2126 * r + 0.7152 * g + 0.0722 * b;
        };
        // Tailwind 4 writes colours as oklch(); a canvas turns any colour into sRGB.
        const canvas = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
        // A translucent colour is painted over the one beneath it, as the page does.
        const parse = (color, beneath = 'rgb(0, 0, 0)') => {
            canvas.clearRect(0, 0, 1, 1);
            canvas.fillStyle = beneath;
            canvas.fillRect(0, 0, 1, 1);
            canvas.fillStyle = color;
            canvas.fillRect(0, 0, 1, 1);

            return [...canvas.getImageData(0, 0, 1, 1).data].slice(0, 3);
        };
        const cardColor = getComputedStyle(document.querySelector('[data-test="product-card"]')).backgroundColor;
        const cardBackground = parse(cardColor);
        const failing = [];

        document.querySelectorAll('[data-test="product-card"] :is(p, span, div, del, a)').forEach((element) => {
            const own = [...element.childNodes].some((node) => node.nodeType === 3 && node.textContent.trim() !== '');

            if (! own || element.closest('[data-test="paused-label"]') || element.closest('.opacity-60')) {
                return;
            }

            let host = element;
            let background = cardBackground;

            while (host && ! host.matches('[data-test="product-card"]')) {
                const own = getComputedStyle(host).backgroundColor;

                if (own !== 'rgba(0, 0, 0, 0)' && own !== 'transparent') {
                    background = parse(own, cardColor);
                    break;
                }

                host = host.parentElement;
            }

            const [light, dark] = [luminance(parse(getComputedStyle(element).color)), luminance(background)].sort((a, b) => b - a);
            const ratio = (light + 0.05) / (dark + 0.05);

            if (ratio < 4.5) {
                failing.push(`${element.textContent.trim().slice(0, 30)} (${ratio.toFixed(1)})`);
            }
        });

        return failing;
    });
    checker.check('text on an active dark card meets 4.5:1', lowContrast.length === 0, lowContrast.slice(0, 8).join('; '));

    await page.goto(`${BASE}/app`, { waitUntil: 'networkidle' });
    await page.screenshot({ path: path.join(ART, 'product-cards-dashboard-dark.png'), fullPage: true });
    await page.evaluate(() => localStorage.removeItem('flux.appearance'));
}

{
    // Categories: a list beside the products on a wide screen, one
    // collapsible group per department.
    await page.goto(`${BASE}/app/products?sort=created_at`, { waitUntil: 'networkidle' });
    const nav = page.locator('[data-test="product-category-nav"]');
    const cardCount = () => page.locator('li[wire\\:key^="product-"]').count();
    checker.check('the category list shows beside the products', await nav.isVisible());
    checker.check('the category dropdown is hidden on a wide screen', ! await page.locator('[data-test="product-category-filter"]').isVisible());
    const gridBox = await page.locator('ul[role="list"]').first().boundingBox();
    const firstCard = await page.locator('li[wire\\:key^="product-"]').first().boundingBox();
    checker.check('the products show four across', gridBox !== null && firstCard !== null && Math.round(gridBox.width / firstCard.width) === 4, JSON.stringify({ grid: gridBox?.width, card: firstCard?.width }));
    checker.check('all categories is current at first', (await nav.getByRole('button', { name: 'All categories' }).getAttribute('data-current')) !== null);
    checker.check('a department starts collapsed', ! await nav.getByRole('button', { name: 'Dairy & eggs' }).isVisible().catch(() => false));

    await nav.getByRole('button', { name: 'Household & cleaning', exact: true }).click();
    await nav.getByRole('button', { name: 'Laundry' }).click();
    // The URL changes before the list re-renders, so wait on the cards.
    await page.waitForFunction(() => document.querySelectorAll('li[wire\\:key^="product-"]').length === 8, null, { timeout: 8000 }).catch(() => {});
    const laundryCount = await cardCount();
    checker.check('a category in the list filters the products', laundryCount === 8 && new URL(page.url()).searchParams.get('category') === 'household.laundry', JSON.stringify({ count: laundryCount, url: page.url() }));
    checker.check('the picked category is current', (await nav.getByRole('button', { name: 'Laundry' }).getAttribute('data-current')) !== null);
    checker.check('the picked category tells a screen reader it is current', (await nav.getByRole('button', { name: 'Laundry' }).getAttribute('aria-current')) === 'true');
    checker.check('the group of the picked category stays open', await nav.getByRole('button', { name: 'Laundry' }).isVisible());

    await nav.getByRole('button', { name: 'All household & cleaning' }).click();
    await page.waitForFunction(() => document.querySelectorAll('li[wire\\:key^="product-"]').length === 16, null, { timeout: 8000 }).catch(() => {});
    checker.check('a department item filters its whole department', (await cardCount()) === 16, String(await cardCount()));
    await page.screenshot({ path: path.join(ART, 'product-cards-categories.png') });

    await nav.getByRole('button', { name: 'All categories' }).click();
    await page.waitForFunction(() => document.querySelectorAll('li[wire\\:key^="product-"]').length === 24, null, { timeout: 8000 }).catch(() => {});
    checker.check('all categories clears the filter', (await cardCount()) === 24, String(await cardCount()));
}

{
    // The controls line up with the columns below them.
    await page.goto(`${BASE}/app/products?sort=created_at`, { waitUntil: 'networkidle' });
    const searchBox = await page.getByPlaceholder('Search your products').boundingBox();
    const gridBox = await page.locator('ul[role="list"]').first().boundingBox();
    const trackBox = await page.getByRole('link', { name: 'Track a product' }).boundingBox();
    const navBox = await page.locator('[data-test="product-category-nav"]').boundingBox();
    const sortBox = await page.locator('[data-test="product-sort"]').first().boundingBox();
    checker.check('the search starts at the left edge of the products', Math.abs(searchBox.x - gridBox.x) <= 2, `${searchBox.x} vs ${gridBox.x}`);
    checker.check('the controls end at the right edge of the products', Math.abs((sortBox.x + sortBox.width) - (gridBox.x + gridBox.width)) <= 2, `${sortBox.x + sortBox.width} vs ${gridBox.x + gridBox.width}`);
    checker.check('the add button sits above the categories', Math.abs(trackBox.x - navBox.x) <= 2, `${trackBox.x} vs ${navBox.x}`);

    // Only discounts: an active drop, or a deal at the cheapest shop.
    await page.locator('[data-test="product-discount-filter"]').click();
    await page.waitForFunction(() => document.querySelectorAll('li[wire\\:key^="product-"]').length === 3, null, { timeout: 8000 }).catch(() => {});
    const discounted = await page.locator('li[wire\\:key^="product-"] a[wire\\:navigate]').allTextContents().then((all) => all.map((t) => t.trim()).filter((t) => t.startsWith('EV ')));
    checker.check('only discounts keeps the products with a discount', JSON.stringify(discounted.sort()) === JSON.stringify(['EV Drop Olive Oil', 'EV Paused Drop Coffee', 'EV Unit Drop Crisps']), JSON.stringify(discounted));
    checker.check('only discounts goes into the URL', new URL(page.url()).searchParams.get('discounted') === 'true', page.url());
    await page.screenshot({ path: path.join(ART, 'product-cards-discounts.png') });
    await page.locator('[data-test="product-discount-filter"]').click();
    await page.waitForFunction(() => document.querySelectorAll('li[wire\\:key^="product-"]').length === 24, null, { timeout: 8000 }).catch(() => {});
    checker.check('turning it off shows every product again', (await page.locator('li[wire\\:key^="product-"]').count()) === 24);
}

{
    // Product page: a best price that costs more per unit than the best value
    // warns, and every alert rule shows.
    await page.goto(`${BASE}/app/products?sort=created_at`, { waitUntil: 'networkidle' });
    await card('EV Unit Drop Crisps').locator('a[wire\\:navigate]').first().click();
    await page.waitForURL((url) => /\/app\/products\/[^/]+$/.test(url.pathname), { timeout: 10000 });
    await page.waitForLoadState('networkidle');
    const warning = page.locator('[data-test="unit-price-warning"]');
    checker.check('a much dearer unit price shows a red warning', await warning.isVisible() && (await warning.getAttribute('data-severity')) === 'high', (await warning.textContent().catch(() => ''))?.trim());
    const rules = (await page.locator('[data-test="alert-rules"]').locator('..').innerText()).replace(/\s+/g, ' ');
    checker.check('the alert card lists every alert rule', rules.includes('drop') && ! rules.includes('Any drop'), rules);
    await page.locator('[data-test="unit-price-warning"]').locator('xpath=ancestor::*[contains(@class, "@container")][1]').screenshot({ path: path.join(ART, 'product-cards-unit-warning.png') });
}

{
    // Edit form: the per-unit target is set in pack terms. Pick a pack, pick a
    // level against the usual price or name a pack price, and both prices show.
    await page.goto(`${BASE}/app/products?sort=created_at`, { waitUntil: 'networkidle' });
    await card('EV Unit Drop Crisps').locator('a[wire\\:navigate]').first().click();
    await page.waitForURL((url) => /\/app\/products\/[^/]+$/.test(url.pathname), { timeout: 10000 });
    const productUrl = page.url();
    await page.goto(`${productUrl}/edit`, { waitUntil: 'networkidle' });
    const target = page.locator('[data-test="unit-target"]');
    const stored = async () => parseFloat(await page.evaluate(() => Livewire.all().find((x) => x.name === 'products.edit-product')?.$wire.unitPriceTarget ?? 'NaN'));
    const targetText = async () => (await target.innerText()).replace(/\s+/g, ' ');
    checker.check('the price alert leads the alerts', await target.isVisible() && (await targetText()).startsWith('Price alert') && (await targetText()).includes('Which pack do you buy?'), await targetText());
    checker.check('the levels read the low from the price chart', (await targetText()).includes('Lowest on the chart'), await targetText());

    const other = page.locator('[data-test="other-alerts"]');
    checker.check('other alerts open when one is set, and name it', (await other.getAttribute('open')) !== null && (await other.locator('summary').innerText()).includes('10% drop'), await other.locator('summary').innerText());

    // The switch sits with the drop it replaces, and swaps it for a price alert.
    const switcher = page.locator('[data-test="price-alert-switch"]');
    const switchText = (await switcher.innerText().catch(() => '')).replace(/\s+/g, ' ');
    checker.check('the drop alert offers a switch beside it', (await other.locator('[data-test="price-alert-switch"]').count()) === 1 && switchText.includes('Instead of your 10% drop alert') && switchText.includes('€4.84 per kilo'), switchText);
    await other.screenshot({ path: path.join(ART, 'product-cards-unit-suggestion.png') });
    await switcher.getByRole('button', { name: 'Switch to a price alert' }).click();
    await switcher.waitFor({ state: 'detached', timeout: 8000 }).catch(() => {});
    const dropsCleared = (await other.getByLabel('Alert me when it drops by (%)').inputValue()) === '' && (await other.getByLabel('Alert me when it drops by (amount)').inputValue()) === '';
    const ownPrice = await target.locator('[data-test="unit-target-pack-price"]').inputValue();
    checker.check('the switch fills the own-price box for the chosen pack', ownPrice === (5.3783 * 0.9 * 0.37).toFixed(2) || ownPrice === '1.79', ownPrice);
    checker.check('the switch sets the price alert and clears the drop', Math.abs(await stored() - 5.38 * 0.9) < 0.01 && dropsCleared && await switcher.count() === 0, String(await stored()));
    await target.getByRole('button', { name: 'Remove' }).click();

    await target.getByRole('radio', { name: /Back at the low on the chart/ }).click();
    checker.check('a level sets the lowest per-kilo price', Math.abs(await stored() - 1.89 / 0.37) < 0.01, String(await stored()));

    const levelPrices = await target.locator('[role="radio"] .font-semibold').allTextContents();
    const levelValues = levelPrices.map((text) => parseFloat(text.replace(/[^0-9.]/g, '')));
    checker.check('the levels run from easiest to hardest to reach', levelValues.every((value, index) => index === 0 || value < levelValues[index - 1]), JSON.stringify(levelPrices));

    await target.getByRole('button', { name: /^200 g/ }).click();
    const context = (await target.locator('[data-test="unit-target-context"]').innerText()).replace(/\s+/g, ' ');
    checker.check('the two figures in the context line stand out', (await target.locator('[data-test="unit-target-context"] .rounded-md').count()) === 2);
    checker.check('the context names the best rate and what it means for the chosen pack', context.includes('Today’s best: €5.38 per kilo at lidl.nl') && context.includes('€1.08 for your 200 g'), context);
    const cheaper = target.locator('[data-test="unit-target-cheaper-pack"]');
    const cheaperShown = await cheaper.waitFor({ state: 'visible', timeout: 3000 }).then(() => true).catch(() => false);
    checker.check('a dearer pack says the best pack is cheaper per kilo', cheaperShown && (await cheaper.innerText()).includes('36% cheaper per kilo'), await cheaper.innerText().catch(() => ''));
    await target.locator('[data-test="unit-target-pack-price"]').fill('0.80');
    checker.check('a price for the 200 g bag becomes a per-kilo target', Math.abs(await stored() - 4) < 0.0001, String(await stored()));
    const summary = (await target.locator('[data-test="unit-target-summary"]').innerText()).replace(/\s+/g, ' ');
    checker.check('the summary states the alert in both prices', summary.includes('€0.80 for 200 g') && summary.includes('€4.00 per kilo') && summary.includes('any shop'), summary);
    checker.check('the summary is announced to screen readers', (await target.locator('[data-test="unit-target-summary"]').getAttribute('aria-live')) === 'polite');
    await target.screenshot({ path: path.join(ART, 'product-cards-unit-target.png') });

    await cheaper.getByRole('button').click();
    const cheaperGone = await cheaper.waitFor({ state: 'hidden', timeout: 3000 }).then(() => true).catch(() => false);
    checker.check('the note switches to the cheaper pack', (await target.getByRole('button', { name: /^370 g/ }).getAttribute('aria-pressed')) === 'true' && cheaperGone);

    await target.getByRole('button', { name: 'Remove' }).click();
    const cleared = await target.locator('[data-test="unit-target-summary"]').waitFor({ state: 'hidden', timeout: 3000 }).then(() => true).catch(() => false);
    checker.check('remove clears the target', Number.isNaN(await stored()) && cleared, String(await stored()));
    await target.getByRole('button', { name: /^200 g/ }).click();
    await target.locator('[data-test="unit-target-pack-price"]').fill('0.80');

    await page.getByRole('button', { name: 'Save changes' }).click();
    await page.waitForURL((url) => url.href === productUrl, { timeout: 10000 }).catch(() => {});
    const rules = (await page.locator('[data-test="alert-rules"]').locator('..').innerText().catch(() => '')).replace(/\s+/g, ' ');
    checker.check('saving stores the per-kilo target', rules.includes('€4.00/kg'), rules);
}

{
    // Sort: the URL wins, then the sort this browser last chose, then the
    // biggest drop.
    const sortSelect = page.locator('select[data-test="product-sort"], [data-test="product-sort"] select').first();
    const sortParam = () => new URL(page.url()).searchParams.get('sort');
    const titles = () => page.locator('li[wire\\:key^="product-"] a[wire\\:navigate]').allTextContents().then((all) => all.map((t) => t.trim()));
    const settle = () => page.waitForLoadState('networkidle');

    await page.evaluate(() => localStorage.removeItem('dipcatch.products.sort'));
    await page.goto(`${BASE}/app/products`, { waitUntil: 'networkidle' });
    checker.check('the list sorts by the biggest drop by default', await sortSelect.inputValue() === 'biggest_drop' && sortParam() === null);
    checker.check('the default puts the biggest drop first', (await titles())[0] === 'EV Unit Drop Crisps', JSON.stringify((await titles()).slice(0, 3)));

    await sortSelect.selectOption('created_at');
    await page.waitForFunction(() => new URL(location.href).searchParams.get('sort') === 'created_at', null, { timeout: 8000 }).catch(() => {});
    await settle();
    checker.check('a chosen sort goes into the URL', sortParam() === 'created_at', page.url());
    checker.check('a chosen sort is remembered', await page.evaluate(() => localStorage.getItem('dipcatch.products.sort')) === 'created_at');

    await page.goto(`${BASE}/app/products`, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => new URL(location.href).searchParams.get('sort') === 'created_at', null, { timeout: 8000 }).catch(() => {});
    await settle();
    checker.check('without a sort in the URL the remembered sort comes back', await sortSelect.inputValue() === 'created_at' && (await titles())[0] === 'EV Drop Olive Oil', JSON.stringify({ value: await sortSelect.inputValue(), first: (await titles())[0] }));

    await page.goto(`${BASE}/app/products?sort=title`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);
    checker.check('a sort in the URL wins over the remembered one', await sortSelect.inputValue() === 'title' && sortParam() === 'title');

    await page.evaluate(() => localStorage.setItem('dipcatch.products.sort', 'user_id'));
    await page.goto(`${BASE}/app/products`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);
    checker.check('a remembered sort the list does not offer is ignored', await sortSelect.inputValue() === 'biggest_drop' && sortParam() === null);

    await page.evaluate(() => localStorage.setItem('dipcatch.products.sort', 'created_at'));
    await page.goto(`${BASE}/app/products`, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => new URL(location.href).searchParams.get('sort') === 'created_at', null, { timeout: 8000 }).catch(() => {});
    await settle();
    await sortSelect.selectOption('biggest_drop');
    await page.waitForFunction(() => ! new URL(location.href).searchParams.has('sort'), null, { timeout: 8000 }).catch(() => {});
    await settle();
    checker.check('choosing the default again is remembered too', await page.evaluate(() => localStorage.getItem('dipcatch.products.sort')) === 'biggest_drop');
    await page.goto(`${BASE}/app/products`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);
    checker.check('after choosing the default, a plain visit stays on it', await sortSelect.inputValue() === 'biggest_drop' && sortParam() === null);
    await page.evaluate(() => localStorage.removeItem('dipcatch.products.sort'));
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
    checker.check('dashboard card leads with the best value and links its shop', crispsText.includes('€5.38 /kg') && crispsText.includes('€1.99 for 370 g') && crispsText.includes('lidl.nl'), crispsText);
    checker.check('dashboard card leaves the lowest-price comparison to the list', ! crispsText.includes('Lowest price') && ! crispsText.includes('€8.45'), crispsText);

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

{
    // The price chart: best value per unit first, the pack price one switch away.
    await page.goto(`${BASE}/app/products/${fixture.tabletsId}`, { waitUntil: 'networkidle' });
    const history = page.locator('[data-test="price-history"]');
    const unitChart = history.locator('[data-test="price-history-chart-unit"]');
    const packChart = history.locator('[data-test="price-history-chart-price"]');
    const basis = history.locator('[data-test="price-history-basis"]');
    const yTicks = async (chart) => (await chart.locator('svg text').allTextContents()).filter((text) => text.includes('€'));

    await unitChart.locator('svg text').first().waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
    checker.check('the chart opens on best value', await history.getByRole('heading', { name: 'Best value over time' }).isVisible());
    checker.check('the chart names the unit it plots', await history.getByText('Price per piece, at the shop that is the best value.').isVisible());
    checker.check('the best value option starts selected', (await basis.getByRole('radio', { name: 'Best value' }).getAttribute('aria-checked')) === 'true');
    checker.check('only the per-unit chart shows', await unitChart.isVisible() && ! await packChart.isVisible());
    const unitTicks = await yTicks(unitChart);
    checker.check('per-tablet ticks are cents, each one distinct', unitTicks.length > 1 && new Set(unitTicks).size === unitTicks.length && unitTicks.every((tick) => /^€0\.\d{2,4}$/.test(tick.trim())), unitTicks.join(' | '));

    await history.locator('[aria-label="About lowest price"]').hover();
    const tip = page.getByText('That can be a small pack that costs more per piece than a bigger one.');
    checker.check('the info button explains what best price can hide', await tip.waitFor({ state: 'visible', timeout: 4000 }).then(() => true).catch(() => false));

    await unitChart.scrollIntoViewIfNeeded();
    const unitBox = await unitChart.locator('svg').first().boundingBox();
    await page.mouse.move(unitBox.x + unitBox.width * 0.6, unitBox.y + unitBox.height / 2);
    await page.mouse.move(unitBox.x + unitBox.width * 0.7, unitBox.y + unitBox.height / 2, { steps: 5 });
    await page.waitForFunction(() => /Best value per piece\s*€/.test(document.body.innerText), null, { timeout: 4000 }).catch(() => {});
    const unitTip = (await page.evaluate(() => document.body.innerText)).replace(/\s+/g, ' ').match(/Best value per piece.{0,40}/)?.[0] ?? '';
    checker.check('the per-unit tooltip leads with the unit price and keeps the pack price', /Best value per piece €0\.02\d{2}/.test(unitTip) && /Lowest price €1[23]\.\d{2}/.test(unitTip), unitTip);
    await page.screenshot({ path: path.join(ART, 'price-history-best-value.png') });

    await basis.getByRole('radio', { name: 'Lowest price' }).click();
    await packChart.waitFor({ state: 'visible', timeout: 4000 }).catch(() => {});
    checker.check('the switch shows the pack price chart', await packChart.isVisible() && ! await unitChart.isVisible());
    checker.check('the heading follows the switch', await history.getByRole('heading', { name: 'Lowest price over time' }).isVisible()
        && ! await history.getByRole('heading', { name: 'Best value over time' }).isVisible());
    await packChart.locator('svg text').first().waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
    const packTicks = await yTicks(packChart);
    checker.check('pack ticks are whole-cent prices', packTicks.length > 0 && packTicks.every((tick) => /€\d+\.\d{2}$/.test(tick.trim())), packTicks.join(' | '));
    await page.screenshot({ path: path.join(ART, 'price-history-best-price.png') });

    // The figures above the chart: best value per tablet, its pack, and the
    // lowest pack price as a note with the gap per tablet.
    const headline = page.locator('[data-test="headline-price"]');
    const headlineText = (await headline.innerText()).replace(/\s+/g, ' ');
    checker.check('the page leads with the best value per piece', headlineText.startsWith('Best value €0.0275 /piece'), headlineText);
    checker.check('the headline names the pack and the shop', headlineText.includes('€21.99 for 800 pieces') && headlineText.includes('kruidvat.nl'), headlineText);
    checker.check('the lowest pack price is a note with the gap per piece', /Lowest price: €12\.99 for 400 pieces at ah\.nl ?\. That is 18% more per piece than the best value\./.test(headlineText), headlineText);
    checker.check('the top row has two tiles, not three', (await page.locator('dl > div').count()) === 2);
    const firstCell = (await page.locator('[data-test="shop-price-cell"]').first().innerText()).replace(/\s+/g, ' ');
    checker.check('each shop row leads with its price per piece', /^€0\.0\d{3} \/piece €\d+\.\d{2} for \d+ pieces/.test(firstCell), firstCell);
    await headline.screenshot({ path: path.join(ART, 'product-page-best-value.png') });

    // A range change re-renders the card; the chosen basis must survive it.
    await history.locator('[data-flux-select] button').first().click();
    await page.getByRole('option', { name: 'Last 30 days' }).click();
    await page.waitForResponse((response) => response.url().includes('/livewire'), { timeout: 8000 }).catch(() => {});
    await page.waitForTimeout(500);
    checker.check('the basis survives a range change', await packChart.isVisible() && ! await unitChart.isVisible());

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${BASE}/app/products/${fixture.tabletsId}`, { waitUntil: 'networkidle' });
    checker.check('product page at phone width has no sideways scroll', ! await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth));
    await history.scrollIntoViewIfNeeded();
    await history.screenshot({ path: path.join(ART, 'price-history-phone.png') });
    await page.setViewportSize({ width: 1440, height: 1000 });

    // Per kilo, prices over one euro: two decimals on the axis.
    await page.goto(`${BASE}/app/products/${fixture.crispsId}`, { waitUntil: 'networkidle' });
    await unitChart.locator('svg text').first().waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
    checker.check('a per-kilo chart opens on best value', await history.getByText('Price per kilo, at the shop that is the best value.').isVisible());
    const kiloTicks = await yTicks(unitChart);
    checker.check('per-kilo ticks carry two decimals', kiloTicks.length > 0 && kiloTicks.every((tick) => /€\d+\.\d{2}$/.test(tick.trim())), kiloTicks.join(' | '));
}

{
    // The bell leads a per-unit alert with its unit price, then the pack.
    await page.goto(`${BASE}/app`, { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Notifications' }).first().click();
    const bellFigure = page.locator('[data-test="bell-unit-figure"]').first();
    const bellShown = await bellFigure.waitFor({ state: 'visible', timeout: 8000 }).then(() => true).catch(() => false);
    const bellText = bellShown ? (await bellFigure.locator('xpath=..').innerText()).replace(/\s+/g, ' ') : '';
    checker.check('the bell leads with the unit price and names the pack', bellText.includes('€0.0275 /piece · kruidvat.nl') && bellText.includes('€21.99 for 800 pieces'), bellText);
    await page.screenshot({ path: path.join(ART, 'bell-unit-first.png') });
    await page.keyboard.press('Escape');
}

{
    // The public page follows the owner page's rules and leads per unit.
    const publicPage = await ctx.newPage();
    await publicPage.goto(`${BASE}/p/${fixture.tabletsSlug}`, { waitUntil: 'networkidle' });
    const publicText = (await publicPage.locator('header').innerText()).replace(/\s+/g, ' ');
    checker.check('the public page leads with the best value per piece', publicText.includes('€0.0275 /piece') && publicText.includes('Best value: €21.99 for 800 pieces at kruidvat.nl'), publicText);
    checker.check('the public page notes the lowest pack price', publicText.includes('Lowest price: €12.99 for 400 pieces at ah.nl. That is 18% more per piece.'), publicText);
    const og = await publicPage.locator('meta[property="og:description"]').getAttribute('content');
    checker.check('the public meta description states the unit price', og === 'Tracked on DipCatch: best value €0.0275 /piece at kruidvat.nl (€21.99 for 800 pieces)', og ?? '');
    checker.check('the public chart is per piece', await publicPage.getByText('Price per piece (last 90 days)').isVisible());
    await publicPage.screenshot({ path: path.join(ART, 'public-page-best-value.png'), fullPage: true });
    await publicPage.close();
}

checker.check('no first-party failures over the whole run', firstParty(), [...issues.pageErrors, ...issues.failedRequests].join('; '));

await checker.summarize();
await browser.close();
