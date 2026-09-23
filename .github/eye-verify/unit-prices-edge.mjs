#!/usr/bin/env node
// Drives the edge cases of the unit-price headline (specs/unit-prices-everywhere.md,
// Edge Cases): an excluded lowest-price shop, an estimated size, a trade-only
// price, a live bundle on the best value, a drop alerted in another basis, and a
// failed chart request.
//
// Needs `php .github/eye-verify/product-cards-seed.php` then
// `php .github/eye-verify/unit-prices-edge-seed.php`; tear down with
// `php .github/eye-verify/product-cards-seed.php --teardown`.
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { createChecker, capturePageIssues, withFailedRoute } from '../../.claude/skills/frontend-quality/scripts/lib.mjs';

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
const checker = createChecker({ page, artifactsDir: ART, label: 'unit-prices-edge' });
const flat = (text) => text.replace(/\s+/g, ' ').trim();

await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
await page.locator('input[name="email"]').fill(fixture.email);
await page.locator('input[name="password"]').fill(fixture.password);
await page.getByRole('button', { name: 'Log in' }).click();
await page.waitForURL((url) => url.pathname.startsWith('/app'), { timeout: 15000 });

{
    // Crisps: the lowest pack price is a box of twelve, outside the per-kilo comparison.
    await page.goto(`${BASE}/app/products/${fixture.crispsId}`, { waitUntil: 'networkidle' });
    const headline = flat(await page.locator('[data-test="headline-price"]').innerText());
    checker.check('crisps lead per kilo at the best value', headline.startsWith('Best value €5.38 /kg'), headline);
    checker.check('an excluded lowest price is noted without a percentage', /Lowest price: €1\.49 for 12 pieces at fitnesscandy\.nl ?\./.test(headline) && ! headline.includes('% more'), headline);
    checker.check('the lowest-price note is a note, not a warning', (await page.locator('[data-test="lowest-price-note"]').count()) === 1 && (await page.locator('[data-test="unit-price-warning"]').count()) === 0);
    const rows = await page.locator('[data-test="shop-price-cell"]').allInnerTexts();
    const boxRow = flat(rows.find((row) => row.includes('€1.49')) ?? '');
    checker.check('the excluded shop row gives its reason and pack, no unit figure', boxRow.includes('€1.49 for 12 pieces') && ! boxRow.includes('/piece') && ! boxRow.includes('/kg'), boxRow);
    await page.locator('[data-test="headline-price"]').screenshot({ path: path.join(ART, 'unit-prices-excluded-note.png') });
}

{
    // Pesto: a shop with no size inherits the one its siblings agree on.
    await page.goto(`${BASE}/app/products/${fixture.pestoId}`, { waitUntil: 'networkidle' });
    const rows = await page.locator('[data-test="shop-price-cell"]').allInnerTexts();
    const plusRow = flat(rows.find((row) => row.includes('€2.59')) ?? '');
    checker.check('an inherited size is marked estimated on the page', plusRow.includes('€13.63 /kg') && plusRow.includes('estimated') && plusRow.includes('€2.59 for 190 g'), plusRow);
    const headline = flat(await page.locator('[data-test="headline-price"]').innerText());
    checker.check('an estimated size never heads the page', headline.includes('ah.nl') && ! headline.includes('plus.nl'), headline);
}

{
    // Tablets: a trade-only price, a two-for deal on the best value, a pack-basis alert.
    await page.goto(`${BASE}/app/products/${fixture.tabletsId}`, { waitUntil: 'networkidle' });
    const headline = flat(await page.locator('[data-test="headline-price"]').innerText());
    checker.check('a trade-only price does not head the page', headline.startsWith('Best value €0.0275 /piece') && headline.includes('kruidvat.nl') && ! headline.includes('wholesale'), headline);
    checker.check('a live bundle strikes the regular unit price and names the deal', headline.includes('€0.0300 /piece') && headline.includes('2 for €43.98'), headline);
    const struck = flat(await page.locator('[data-test="headline-price"] dd').first().locator('del[title="Regular price"]').innerText().catch(() => ''));
    checker.check('the struck figure is the regular price per piece', struck === '€0.0300 /piece', struck);
    const rows = await page.locator('[data-test="shop-price-cell"]').allInnerTexts();
    const tradeRow = flat(rows.find((row) => row.includes('€9.99')) ?? '');
    checker.check('the trade-only row leads with its reason', tradeRow.length > 0 && ! tradeRow.startsWith('€'), tradeRow);
    await page.locator('[data-test="headline-price"]').screenshot({ path: path.join(ART, 'unit-prices-bundle-headline.png') });

    // Fault injection: a failed range change shows a toast, and the page recovers.
    const history = page.locator('[data-test="price-history"]');
    await withFailedRoute(page, '**/livewire*/update', async () => {
        await history.locator('[data-flux-select] button').first().click();
        await page.getByRole('option', { name: 'Last 30 days' }).click();
        const toast = await page.getByText('Could not complete that').waitFor({ state: 'visible', timeout: 8000 }).then(() => true).catch(() => false);
        checker.check('a failed chart request shows an error toast', toast);
    });
    checker.check('the chart is still on screen after the failure', await history.locator('[data-test="price-history-chart-unit"]').isVisible());
    await history.locator('[data-flux-select] button').first().click();
    await page.getByRole('option', { name: 'Last 90 days' }).click();
    const recovered = await page.waitForResponse((response) => response.url().includes('/livewire') && response.ok(), { timeout: 8000 }).then(() => true).catch(() => false);
    checker.check('the next range change succeeds', recovered && await history.locator('[data-test="price-history-chart-unit"]').isVisible());
}

{
    // The list card for tablets: the drop was alerted on pack prices.
    await page.goto(`${BASE}/app/products?sort=created_at&q=Tablets`, { waitUntil: 'networkidle' });
    const card = page.locator('li[wire\\:key^="product-"]').filter({ hasText: 'EV Vitamin Tablets' });
    const text = flat(await card.innerText());
    checker.check('a pack-basis drop shows no old figure beside a unit headline', text.includes('€0.0275 /piece') && ! text.includes('Was '), text);
    checker.check('the badge says which basis the drop was measured in', (await card.locator('[data-test="drop-badge"]').getAttribute('title')) === 'Measured per pack');
    checker.check('the trade-only shop is not first among the card rows', ! /wholesale\.test[^€]*€0\.0125/.test(text), text);
    // The host and the two prices share one row; they must not overlap.
    const overlaps = await card.locator('ul li').evaluateAll((rows) => rows.map((row) => {
        const [host, price] = row.children;
        if (! host || ! price) {
            return null;
        }
        const hostText = host.querySelector('a')?.getBoundingClientRect();
        return hostText && hostText.right > price.getBoundingClientRect().left + 0.5 ? row.innerText.replace(/\s+/g, ' ') : null;
    }).filter(Boolean));
    checker.check('card shop rows keep the host clear of the prices', overlaps.length === 0, overlaps.join(' | '));
    await card.screenshot({ path: path.join(ART, 'unit-prices-card-pack-drop.png') });
}

{
    // The public page applies the same rules.
    const shared = await ctx.newPage();
    const sharedIssues = capturePageIssues(shared);
    await shared.goto(`${BASE}/p/${fixture.tabletsSlug}`, { waitUntil: 'networkidle' });
    const body = flat(await shared.locator('main').innerText());
    checker.check('the public page is not headed by the trade-only price', body.includes('€0.0275 /piece') && body.includes('Best value: €21.99 for 800 pieces · 2 for €43.98'), body.slice(0, 400));
    const tradeRow = flat(await shared.locator('li').filter({ hasText: 'wholesale.test' }).innerText());
    checker.check('the public trade-only row has no unit figure and no badge', ! tradeRow.includes('/piece') && ! tradeRow.includes('Best value') && ! tradeRow.includes('Lowest price'), tradeRow);
    checker.check('the public page loads without errors', sharedIssues.pageErrors.length === 0, sharedIssues.pageErrors.join('; '));
    await shared.close();
}

checker.skip('the piece label in Dutch', 'no signed-in or public page switches locale; covered by PackSizeWordsTest and NotificationBellTest');
checker.skip('a notification queued before the change', 'needs a queued job from the previous release; covered by WebPushTest');
checker.check('no page errors on the signed-in pages', issues.pageErrors.length === 0, issues.pageErrors.join('; '));

await checker.summarize();
await browser.close();
