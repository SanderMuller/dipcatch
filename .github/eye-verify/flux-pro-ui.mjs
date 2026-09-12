#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { createChecker, capturePageIssues, withFailedRoute } from '../../.claude/skills/frontend-quality/scripts/lib.mjs';

const BASE = process.env.BASE ?? 'https://dipcatch.test';
const ART = path.resolve('.github/eye-verify');
const FIXTURE = process.env.FIXTURE_PATH ?? '/tmp/flux-pro-eye-verify.json';

function loadFixture() {
    if (! fs.existsSync(FIXTURE)) {
        throw new Error(`Missing fixture at ${FIXTURE}. Seed the throwaway user first.`);
    }

    return JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
}

function firstPartyClean(issues) {
    return issues.pageErrors.length === 0
        && ! issues.appRequestFailed
        && ! (issues.navStatus !== null && issues.navStatus >= 400)
        && issues.failedRequests.filter((request) => request.includes(new URL(BASE).host)).length === 0;
}

async function shot(page, name) {
    const file = path.join(ART, name);
    await page.screenshot({ path: file, animations: 'disabled' });

    return file;
}

const fixture = loadFixture();
fs.mkdirSync(ART, { recursive: true });

const browser = await chromium.launch();
const ctx = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width: 1280, height: 900 },
});
const page = await ctx.newPage();
const issues = capturePageIssues(page);
const checker = createChecker({ page, artifactsDir: ART, label: 'flux-pro-ui' });

{
    const response = await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    const headings = await page.locator('button[data-flux-accordion-heading] span').allTextContents();
    const trimmed = headings.map((text) => text.trim()).filter(Boolean);
    checker.check('homepage returns 200', response !== null && response.status() === 200, String(response?.status()));
    checker.check('homepage boots without first-party failures', firstPartyClean(issues), issues.failedRequests.join('; '));
    checker.check('FAQ uses Flux accordion headings', trimmed.length >= 6, JSON.stringify(trimmed));
    checker.check('FAQ still lists Which shops work?', trimmed.includes('Which shops work?'), JSON.stringify(trimmed));
    checker.check('homepage has no Chart.js canvas', (await page.locator('canvas').count()) === 0);

    await page.locator('button[data-flux-accordion-heading]').first().click();
    const opened = await page.locator('[data-flux-accordion-item]').first()
        .locator('[data-flux-accordion-content]')
        .waitFor({ state: 'visible', timeout: 5000 })
        .then(() => true)
        .catch(() => false);
    checker.check('FAQ accordion opens on click', opened);
    await shot(page, 'flux-pro-home-faq.png');
}

{
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    await page.locator('input[name="email"]').fill(fixture.email);
    await page.locator('input[name="password"]').fill(fixture.password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL((url) => url.pathname.startsWith('/app'), { timeout: 15000 });
    checker.check('throwaway account reaches /app after login', page.url().includes('/app'), page.url());
}

{
    await page.goto(`${BASE}/app`, { waitUntil: 'networkidle' });
    checker.check('dashboard heading is visible', await page.getByRole('heading', { name: 'Dashboard', level: 1 }).isVisible());
    checker.check('dashboard uses a Flux table', await page.locator('[data-flux-table], table').count().then((n) => n > 0));
    checker.check('dashboard savings chart is a Flux chart', await page.locator('ui-chart').count().then((n) => n > 0));
    checker.check('dashboard recent alerts use a Flux timeline', await page.locator('[data-flux-timeline]').count().then((n) => n > 0));
    await shot(page, 'flux-pro-dashboard.png');
}

{
    const search = page.getByRole('button', { name: 'Search' }).first();
    await search.click();
    const palette = page.locator('[data-flux-command], ui-select[data-flux-command]');
    await palette.waitFor({ state: 'visible', timeout: 8000 });
    checker.check('command palette opens from the search control', await palette.isVisible());
    const listsDashboard = await page.getByRole('option', { name: 'Dashboard' }).count().then((n) => n > 0);
    checker.check('command palette lists Dashboard', listsDashboard);
    await shot(page, 'flux-pro-command-palette.png');
    await page.keyboard.press('Escape');
    await palette.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
}

{
    await page.goto(`${BASE}/app/products`, { waitUntil: 'networkidle' });
    const list = page.locator('[data-flux-table]');
    checker.check('product list heading is visible', await page.getByRole('heading', { name: 'Products', level: 1 }).isVisible());
    checker.check('status filter is a segmented radio group', await page.getByRole('radio', { name: 'Paused' }).count().then((n) => n > 0));
    checker.check('product list shows the active fixture', await list.getByText('EyeVerify Arabica').first().isVisible());
    checker.check('product list shows the paused fixture before filtering', await list.getByText('EyeVerify Paused Soap').first().isVisible());
    await page.locator('[data-flux-radio-group-segmented]').getByRole('radio', { name: 'Paused' }).click();
    const activeGone = await list.getByText('EyeVerify Arabica').waitFor({ state: 'hidden', timeout: 8000 })
        .then(() => true)
        .catch(() => false);
    checker.check('Paused filter hides the active product', activeGone);
    await shot(page, 'flux-pro-product-list.png');
    await page.getByRole('radio', { name: 'All' }).click();
    await list.getByText('EyeVerify Arabica').first().waitFor({ state: 'visible', timeout: 8000 });
}

{
    await page.goto(`${BASE}/app/products/${fixture.productId}`, { waitUntil: 'networkidle' });
    checker.check('product breadcrumbs render', await page.locator('[data-flux-breadcrumbs]').count().then((n) => n > 0));
    checker.check('Active badge is visible', await page.getByText('Active', { exact: true }).count().then((n) => n > 0));
    checker.check('History tab is present', await page.getByRole('tab', { name: 'History' }).count().then((n) => n > 0));
    checker.check('product history uses a Flux chart', await page.locator('ui-chart').count().then((n) => n > 0));
    checker.check('product history has no Chart.js canvas', (await page.locator('canvas').count()) === 0);
    await shot(page, 'flux-pro-product-history.png');

    await page.getByRole('tab', { name: 'Shops' }).click();
    await page.getByRole('heading', { name: 'Tracked shops' }).waitFor({ state: 'visible', timeout: 8000 });
    checker.check('Shops tab shows tracked shops', await page.getByRole('heading', { name: 'Tracked shops' }).isVisible());
    await shot(page, 'flux-pro-product-shops.png');

    await page.getByText('Add a shop', { exact: true }).click();
    const addShopForm = await page.locator('#add-shop-url').waitFor({ state: 'visible', timeout: 8000 }).then(() => true).catch(() => false);
    checker.check('Add a shop disclosure opens the Flux form', addShopForm);
    await shot(page, 'flux-pro-add-shop.png');
    await page.locator('details').getByText('Cancel', { exact: true }).click();
    await page.locator('#add-shop-url').waitFor({ state: 'hidden', timeout: 8000 }).catch(() => {});

    await page.getByRole('button', { name: 'Sharing' }).click();
    const sharing = page.getByRole('dialog').filter({ hasText: 'Public sharing' });
    const sharingOpen = await sharing.waitFor({ state: 'visible', timeout: 8000 }).then(() => true).catch(() => false);
    const createLink = sharing.getByRole('button', { name: 'Create a public link' });
    if (sharingOpen && await createLink.isVisible().catch(() => false)) {
        await createLink.click();
    }
    const linkVisible = await sharing.locator('input[readonly]').first()
        .waitFor({ state: 'visible', timeout: 8000 })
        .then(() => true)
        .catch(() => false);
    checker.check('sharing modal exposes a copyable public link', linkVisible);
    await shot(page, 'flux-pro-share-copyable.png');
}

{
    await page.goto(`${BASE}/app/products/${fixture.productId}/edit`, { waitUntil: 'networkidle' });
    checker.check('edit page breadcrumbs render', await page.locator('[data-flux-breadcrumbs]').count().then((n) => n > 0));
    checker.check('currency field is a searchable listbox', await page.locator('ui-select, [data-flux-select]').count().then((n) => n > 0));
    await shot(page, 'flux-pro-edit-product.png');
}

{
    await page.goto(`${BASE}/app/notifications`, { waitUntil: 'networkidle' });
    checker.check('notification settings heading is visible', await page.getByRole('heading', { name: 'Notifications', level: 1 }).isVisible());
    checker.check('timezone uses a searchable listbox', await page.locator('ui-select, [data-flux-select]').count().then((n) => n > 0));
    await shot(page, 'flux-pro-notifications.png');
}

{
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${BASE}/app/products`, { waitUntil: 'networkidle' });
    checker.check('product list remains usable at a phone width', await page.getByRole('heading', { name: 'Products', level: 1 }).isVisible());
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 2);
    checker.check('product list does not overflow the phone viewport', ! overflow);
    await shot(page, 'flux-pro-product-list-mobile.png');
}

{
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`${BASE}/app/products/create`, { waitUntil: 'networkidle' });
    await withFailedRoute(page, '**/livewire/update', async () => {
        await page.locator('#create-product-url').fill('https://this-is-not-a-real-shop.example/product/1');
        await page.getByRole('button', { name: 'Fetch product' }).click();
        const errorShown = await page.getByText('Could not').waitFor({ timeout: 5000 }).then(() => true).catch(() => false)
            || await page.locator('[data-flux-callout]').waitFor({ timeout: 5000 }).then(() => true).catch(() => false);
        checker.check('create-from-url shows a visible error when Livewire update fails', errorShown);
    }, { status: 500, contentType: 'text/html', body: 'Injected failure' });
}

checker.skip('add-shop probe skeleton', 'needs a live shop URL; the loading skeleton is wire:loading only');
checker.skip('command palette keyboard shortcut', 'macOS cmd.k is not sent reliably in this headless run; the click path was driven');

await checker.summarize();
await browser.close();
