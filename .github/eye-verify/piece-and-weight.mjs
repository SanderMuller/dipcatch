#!/usr/bin/env node
// Drives specs/piece-and-weight-pack-sizes.md in the browser: a product whose
// shops state a count and a weight compares every shop per kilo, a per-piece
// target reads per kilo, and a target the product can no longer compare in is
// paused, with a "Remove target" that keeps keyboard focus and can be undone.
//
// Needs `php .github/eye-verify/piece-and-weight-seed.php`; tear down with
// `php .github/eye-verify/piece-and-weight-seed.php --teardown`.
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { createChecker, capturePageIssues } from '../../.claude/skills/frontend-quality/scripts/lib.mjs';

const BASE = process.env.BASE ?? 'https://dipcatch.test';
const ART = path.resolve('.github/eye-verify');
const FIXTURE = process.env.FIXTURE_PATH ?? '/tmp/piece-weight-eye-verify.json';

if (! fs.existsSync(FIXTURE)) {
    throw new Error(`Missing fixture at ${FIXTURE}. Seed the throwaway user first.`);
}

const fixture = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
const browser = await chromium.launch();
const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
const page = await ctx.newPage();
const issues = capturePageIssues(page);
const checker = createChecker({ page, artifactsDir: ART, label: 'piece-and-weight' });
const flat = (text) => text.replace(/\s+/g, ' ').trim();

await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
if (! (await page.title()).includes('DipCatch')) {
    console.error('Not a DipCatch page at ' + BASE);
    process.exit(2);
}
await page.locator('input[name="email"]').fill(fixture.email);
await page.locator('input[name="password"]').fill(fixture.password);
await page.getByRole('button', { name: 'Log in' }).click();
await page.waitForURL((url) => url.pathname.startsWith('/app'), { timeout: 15000 });

{
    await page.goto(`${BASE}/app/products/${fixture.igloId}`, { waitUntil: 'networkidle' });
    const headline = flat(await page.locator('[data-test="headline-price"]').innerText());
    checker.check('the Iglo field leads per kilo, with Poiesz the best value', headline.includes('/kg') && headline.includes('poiesz'), headline);
    const rows = (await page.locator('[data-test="shop-price-cell"]').allInnerTexts()).map(flat);
    checker.check('every shop has a price per kilo, none "measured in a different unit"', rows.length === 6 && rows.every((row) => row.includes('/kg')) && ! rows.some((row) => row.includes('different unit')), rows.join(' | '));
    const rules = flat(await page.locator('[data-test="alert-rules"]').innerText());
    checker.check('the per-piece target reads per kilo', rules.includes('€8.93') && rules.includes('/kg'), rules);
    await page.locator('[data-test="headline-price"]').screenshot({ path: path.join(ART, 'piece-and-weight-headline.png') });
}

{
    await page.goto(`${BASE}/app/products/${fixture.pausedId}`, { waitUntil: 'networkidle' });
    const rules = flat(await page.locator('[data-test="alert-rules"]').innerText());
    checker.check('the product page names the paused target in its own unit', rules.includes('paused') && rules.includes('/kg'), rules);

    await page.goto(`${BASE}/app/products/${fixture.pausedId}/edit`, { waitUntil: 'networkidle' });
    const notice = page.locator('[data-test="suspended-unit-target"]');
    checker.check('the edit form shows the paused notice', (await notice.count()) === 1 && flat(await notice.innerText()).includes('is paused'), flat(await notice.innerText()));
    await notice.screenshot({ path: path.join(ART, 'piece-and-weight-paused.png') });

    const button = page.locator('[data-test="remove-unit-target"]');
    await button.focus();
    await page.keyboard.press('Enter');
    await page.waitForFunction(() => document.querySelector('[data-test="remove-unit-target"]')?.textContent?.includes('Keep target'));
    const focusedTest = await page.evaluate(() => document.activeElement?.getAttribute('data-test'));
    checker.check('Remove target keeps keyboard focus on its button', focusedTest === 'remove-unit-target', String(focusedTest));
    checker.check('the notice says saving removes the target', flat(await notice.innerText()).includes('will be removed when you save'), flat(await notice.innerText()));
    await notice.screenshot({ path: path.join(ART, 'piece-and-weight-remove.png') });

    await page.keyboard.press('Enter');
    await page.waitForFunction(() => document.querySelector('[data-test="remove-unit-target"]')?.textContent?.includes('Remove target'));
    checker.check('Keep target undoes it', flat(await notice.innerText()).includes('is paused'), flat(await notice.innerText()));

    // Remove, save, and the target is gone from the product page.
    await button.click();
    await page.waitForFunction(() => document.querySelector('[data-test="remove-unit-target"]')?.textContent?.includes('Keep target'));
    await page.getByRole('button', { name: 'Save changes' }).click();
    await page.waitForURL((url) => ! url.pathname.endsWith('/edit'), { timeout: 15000 });
    const after = flat(await page.locator('[data-test="alerts-card"]').innerText());
    checker.check('after saving, the paused target is gone', ! after.includes('paused'), after);
}

checker.skip('a failing save request', 'the edit form posts through Livewire; a forced 500 is covered by the existing EditProduct tests, not by this flow');
checker.skip('Jev confirming a second size', 'needs a live TypeSafe key; this local key answers 403');
checker.check('no page errors', issues.pageErrors.length === 0, issues.pageErrors.join('; '));

await checker.summarize();
await browser.close();
