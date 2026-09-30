#!/usr/bin/env node
// Drives "Don't suggest {shop}": from a dashboard suggestion, the dialog
// before it (Cancel keeps the shop), the toast's Undo, and the Hidden shops
// list in settings with Show again.
//
// Reuses the dashboard account: run `php .github/eye-verify/dashboard-suggestions-seed.php`
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
const checker = createChecker({ page, artifactsDir: ART, label: 'hidden-shops' });


{
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });

    if (! (await page.title()).includes('DipCatch')) {
        console.error(`Not a DipCatch page: ${await page.title()}`);
        process.exit(2);
    }

    await page.locator('input[name="email"]').fill(fixture.email);
    await page.locator('input[name="password"]').fill(fixture.password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL((url) => url.pathname.startsWith('/app'), { timeout: 15000 });
}

const section = () => page.locator('[data-test="suggested-shops"]');
const lidlRows = () => section().locator('[data-test="suggested-shop"]', { hasText: 'Lidl' });
const toast = () => page.locator('[data-flux-toast-dialog]', { hasText: 'won’t be suggested again' });

{
    await page.goto(`${BASE}/app`, { waitUntil: 'domcontentloaded' });
    await lidlRows().first().waitFor({ state: 'visible', timeout: 20000 });
    checker.check('the dashboard suggests Lidl', await lidlRows().count() === 1);

    await lidlRows().first().locator('[data-test="suggested-shop-more"]').click();
    const item = page.locator('[data-test="suggested-shop-hide"]:visible');
    checker.check('the menu offers Don’t suggest with the shop’s name', (await item.innerText()).includes('Don’t suggest Lidl'), await item.innerText());

    await item.click();
    const dialog = page.locator('[data-test="hide-shop-confirm"]');
    // The data-test sits on Flux's wrapper, which has no box of its own; wait on the heading.
    const shown = await dialog.getByText('Stop suggesting Lidl?').waitFor({ state: 'visible', timeout: 5000 }).then(() => true).catch(() => false);
    const question = (await dialog.innerText().catch(() => '')).replace(/\s+/g, ' ');
    checker.check('a dialog asks first, naming the shop and where to undo it', shown && question.includes('Stop suggesting Lidl?') && question.includes('Hidden shops'), question);
    await page.screenshot({ path: path.join(ART, 'hidden-shops-confirm.png') });

    await dialog.locator('[data-test="hide-shop-cancel"]').click();
    await dialog.getByText('Stop suggesting Lidl?').waitFor({ state: 'hidden', timeout: 5000 });
    checker.check('Cancel keeps the shop', await lidlRows().count() === 1 && await toast().count() === 0);

    await lidlRows().first().locator('[data-test="suggested-shop-more"]').click();
    await page.locator('[data-test="suggested-shop-hide"]:visible').click();
    await dialog.locator('[data-test="hide-shop-confirm-button"]').click();

    checker.check('a toast confirms it', await toast().waitFor({ state: 'visible', timeout: 5000 }).then(() => true).catch(() => false), await toast().innerText().catch(() => ''));
    checker.check('Lidl leaves the suggestions', await lidlRows().first().waitFor({ state: 'detached', timeout: 5000 }).then(() => true).catch(() => false));
    await page.screenshot({ path: path.join(ART, 'hidden-shops-toast.png') });

    await toast().getByRole('button', { name: 'Undo' }).click();
    checker.check('Undo brings Lidl back', await lidlRows().first().waitFor({ state: 'visible', timeout: 5000 }).then(() => true).catch(() => false));

    // Undo closes its toast once the request ends; hide again after that.
    await toast().waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
    await lidlRows().first().locator('[data-test="suggested-shop-more"]').click();
    await page.locator('[data-test="suggested-shop-hide"]:visible').click();
    await page.locator('[data-test="hide-shop-confirm-button"]:visible').click();
    await lidlRows().first().waitFor({ state: 'detached', timeout: 5000 });
}

{
    await page.goto(`${BASE}/settings/product-features`, { waitUntil: 'networkidle' });
    const list = page.locator('[data-test="hidden-shops"]');
    const text = (await list.innerText()).replace(/\s+/g, ' ');
    checker.check('settings lists the hidden shop', text.includes('Hidden shops') && text.includes('Lidl'), text);
    await list.screenshot({ path: path.join(ART, 'hidden-shops-settings.png') });

    await list.locator('[data-test="hidden-shop-show"]').first().click();
    checker.check('Show again empties the list', await list.locator('[data-test="hidden-shops-empty"]').waitFor({ state: 'visible', timeout: 5000 }).then(() => true).catch(() => false));

    await page.goto(`${BASE}/app`, { waitUntil: 'domcontentloaded' });
    checker.check('the dashboard suggests Lidl again', await lidlRows().first().waitFor({ state: 'visible', timeout: 20000 }).then(() => true).catch(() => false));
}

checker.check('no first-party failures over the whole run', issues.pageErrors.length === 0
    && issues.failedRequests.filter((request) => request.includes(new URL(BASE).host)).length === 0,
    [...issues.pageErrors, ...issues.failedRequests].join('; '));

await checker.summarize();
await browser.close();
