#!/usr/bin/env node
// Captures the real app, state by state, for the "What's new" videos. Each
// flow writes one full-page PNG per state and a steps.json into
// tools/video/public/captures/<flow>/: the `screens` moment in the video
// template reads it, zooms to each step's `focus` and clicks at its `click`.
// Both are read off the page, so the video cannot drift from the app.
//
//   php tools/changelog-media/seed.php video
//   node tools/changelog-media/capture-screens.mjs [flow ...]
//   php tools/changelog-media/seed.php video --teardown
//
// The captures are gitignored: run this again after a UI change, then render.
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

const BASE = process.env.BASE ?? 'https://dipcatch.test';
const OUT = path.resolve('tools/video/public/captures');
const PHOTOS = path.resolve('tools/changelog-media/photos');
const FIXTURE = process.env.FIXTURE_PATH ?? '/tmp/changelog-media-video.json';

if (! fs.existsSync(FIXTURE)) {
    throw new Error(`Missing fixture at ${FIXTURE}. Run php tools/changelog-media/seed.php video first.`);
}

const fixture = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));

/** The page box of one or more locators, grown by `pad` on every side. */
const focusOf = async (page, locators, pad = 24) => {
    const scroll = await page.evaluate(() => ({ x: window.scrollX, y: window.scrollY }));
    const boxes = [];

    for (const locator of locators) {
        const box = await locator.boundingBox();

        if (box === null) {
            throw new Error(`focus: ${locator} is not on the page`);
        }

        boxes.push({ x: box.x + scroll.x, y: box.y + scroll.y, w: box.width, h: box.height });
    }

    const x = Math.min(...boxes.map((b) => b.x)) - pad;
    const y = Math.min(...boxes.map((b) => b.y)) - pad;

    return {
        x: Math.round(Math.max(0, x)),
        y: Math.round(Math.max(0, y)),
        w: Math.round(Math.max(...boxes.map((b) => b.x + b.w)) + pad - Math.max(0, x)),
        h: Math.round(Math.max(...boxes.map((b) => b.y + b.h)) + pad - Math.max(0, y)),
    };
};

/** The page point at the centre of a control. */
const clickOf = async (page, locator) => {
    const box = await locator.boundingBox();
    const scroll = await page.evaluate(() => ({ x: window.scrollX, y: window.scrollY }));

    return { x: Math.round(box.x + box.width / 2 + scroll.x), y: Math.round(box.y + box.height / 2 + scroll.y) };
};

const settle = async (page) => {
    await page.waitForLoadState('networkidle');
    // Lazy images below the fold never load until scrolled to: load them all,
    // and never wait on one for more than a few seconds.
    await page.evaluate(() => Promise.race([
        Promise.all([...document.images].map((img) => {
            img.loading = 'eager';

            return img.complete ? null : new Promise((resolve) => { img.onload = img.onerror = resolve; });
        })),
        new Promise((resolve) => setTimeout(resolve, 4000)),
    ]));
    // Livewire morphs and Flux transitions.
    await page.waitForTimeout(700);
};

const card = (page, title) => page.locator('[data-test="product-card"]').filter({ hasText: title }).first();

/**
 * In this order on purpose: a flow that writes to the list (tick, add) runs
 * after the flows that read it. Re-seed before the next run.
 *
 * flow => { viewport, steps(page, shot) }. `shot(focus, click?, highlight?)`
 * captures the current state; `click` is the control the next state comes
 * from, and `highlight` is the part that changed, which the video rings.
 */
const flows = {
    // Skipping a shop moves its products to their next best shop.
    'shopping-list-skip': {
        // Narrow, so the list's text stays readable once the video scales it down.
        viewport: { width: 560, height: 900 },
        steps: async (page, shot) => {
            await page.goto(`${BASE}/app/shopping-list`);
            await settle(page);
            const pills = page.locator('[data-test="shopping-list-shops"]');
            const groups = page.locator('[data-test="shopping-list-group"]');
            const jumbo = page.locator('[data-test="shopping-list-shop-toggle"]').filter({ hasText: 'jumbo.com' });
            // The first group gains the skipped shop's products.
            await shot(await focusOf(page, [pills, groups.first()]), await clickOf(page, jumbo));
            await jumbo.click();
            await page.waitForFunction(() => document.querySelectorAll('[data-test="shopping-list-group"]').length === 2);
            await settle(page);
            const moved = groups.first().locator('[data-test="shopping-list-item"]').filter({ hasText: 'Coffee beans' });
            await shot(await focusOf(page, [pills, groups.first()]), undefined, await focusOf(page, [moved], 4));
        },
    },
    // Picking a shop in the sidebar brings the "Best buys here" switch.
    'best-buys-shop': {
        // 1024 wide: three cards to a row, and the switch wraps under the
        // search, so the switch and a row of cards fit one readable frame.
        viewport: { width: 1024, height: 900 },
        steps: async (page, shot) => {
            await page.goto(`${BASE}/app/products`);
            await settle(page);
            const shops = page.locator('[data-test="product-shop-nav"]');
            const cards = page.locator('[data-test="product-card"]');
            const ah = shops.getByText('ah.nl', { exact: true });
            await shot(await focusOf(page, [shops, cards.first()]), await clickOf(page, ah));
            await ah.click();
            await page.locator('[data-test="product-best-buy-filter"]').waitFor();
            await settle(page);
            const filter = page.locator('[data-test="product-best-buy-filter"]');
            const toolbar = page.locator('[data-test="product-discount-filter"]');
            await shot(await focusOf(page, [toolbar, filter, cards.first(), cards.nth(2)]), undefined, await focusOf(page, [filter.locator('xpath=..')], 6));
        },
    },
    // The switch keeps the products whose best buy is at that shop.
    'best-buys-switch': {
        viewport: { width: 1024, height: 900 },
        steps: async (page, shot) => {
            await page.goto(`${BASE}/app/products?shop=ah.nl`);
            await settle(page);
            const filter = page.locator('[data-test="product-best-buy-filter"]');
            const cards = page.locator('[data-test="product-card"]');
            // The whole toolbar, not a sliver of its first row.
            const toolbar = page.locator('[data-test="product-discount-filter"]');
            // The data-test sits on the switch itself.
            await shot(await focusOf(page, [toolbar, filter, cards.first(), cards.nth(2)]), await clickOf(page, filter));
            const before = await cards.count();
            await filter.click();
            await page.waitForFunction((n) => document.querySelectorAll('[data-test="product-card"]').length < n, before);
            await settle(page);
            const row = Math.min(3, await cards.count()) - 1;
            await shot(await focusOf(page, [toolbar, filter, cards.first(), cards.nth(row)]), undefined, await focusOf(page, [cards.first(), cards.nth(row)], 6));
        },
    },
    // Crossing an item off in the shop.
    'shopping-list-tick': {
        viewport: { width: 560, height: 900 },
        steps: async (page, shot) => {
            await page.goto(`${BASE}/app/shopping-list`);
            await settle(page);
            const group = page.locator('[data-test="shopping-list-group"]').first();
            const box = group.locator('[data-test="shopping-list-item"] input[type="checkbox"]').first();
            await shot(await focusOf(page, [page.locator('#shopping-list-heading'), group]), await clickOf(page, box));
            await box.click();
            await page.waitForTimeout(300);
            await settle(page);
            const crossed = group.locator('[data-test="shopping-list-item"]').filter({ has: page.locator('input:checked') });
            await shot(await focusOf(page, [page.locator('#shopping-list-heading'), group]), undefined, await focusOf(page, [crossed], 4));
        },
    },
    // The list icon on a card that is not on the list yet.
    'shopping-list-add': {
        viewport: { width: 1200, height: 900 },
        steps: async (page, shot) => {
            await page.goto(`${BASE}/app/products`);
            await settle(page);
            const litter = card(page, 'Cat litter');
            const vitamins = card(page, 'Vitamin D');
            await litter.scrollIntoViewIfNeeded();
            const toggle = litter.locator('[data-test="card-list-toggle"]');
            const focus = await focusOf(page, [litter, vitamins]);
            await shot(focus, await clickOf(page, toggle));
            await toggle.click();
            await page.waitForFunction(() => [...document.querySelectorAll('[data-test="card-list-toggle"]')].some((button) => button.getAttribute('aria-label')?.startsWith('Remove Cat litter')));
            await settle(page);
            await shot(await focusOf(page, [litter, vitamins]), undefined, await focusOf(page, [toggle], 4));
        },
    },
};

const wanted = process.argv.slice(2);
const browser = await chromium.launch();
// Fail a step in seconds, not after Playwright's default half minute each.
const TIMEOUT = 20000;
let failed = false;

for (const [name, flow] of Object.entries(flows)) {
    if (wanted.length > 0 && ! wanted.includes(name)) {
        continue;
    }

    // A fresh context per flow: each starts from the seeded state, not from
    // what the flow before it clicked. Re-seed between runs of the same flow.
    const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: flow.viewport, deviceScaleFactor: 2 });
    await ctx.addInitScript(() => {
        window.localStorage.setItem('flux.appearance', 'light');
        document.addEventListener('DOMContentLoaded', () => {
            const style = document.createElement('style');
            style.textContent = '.phpdebugbar, [data-flux-toast-group] { display: none !important; } * { caret-color: transparent !important; }';
            document.head.append(style);
        });
    });
    await ctx.route('https://product-photos.changelog-media.test/**', (route) => {
        const file = path.join(PHOTOS, path.basename(new URL(route.request().url()).pathname));
        route.fulfill(fs.existsSync(file) ? { path: file, contentType: 'image/png' } : { status: 404 });
    });

    const page = await ctx.newPage();
    page.setDefaultTimeout(TIMEOUT);

    try {
        await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });

        if (! (await page.title()).includes('DipCatch')) {
            throw new Error(`not a DipCatch page: ${await page.title()}`);
        }

        await page.locator('input[name="email"]').fill(fixture.email);
        await page.locator('input[name="password"]').fill(fixture.password);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL((url) => url.pathname.startsWith('/app'), { timeout: 15000 });

        // Written aside and swapped in at the end, so a failed run keeps the last good capture.
        const final = path.join(OUT, name);
        const dir = `${final}.tmp`;
        fs.rmSync(dir, { recursive: true, force: true });
        fs.mkdirSync(dir, { recursive: true });
        const steps = [];

        await flow.steps(page, async (focus, click, highlight) => {
            // No hover state left from the click: a tooltip or popover would cover the result.
            await page.mouse.move(1, 1);
            await page.waitForTimeout(400);
            const file = `step-${steps.length}.png`;
            await page.screenshot({ path: path.join(dir, file), fullPage: true });
            const size = await page.evaluate(() => ({ width: document.documentElement.scrollWidth, height: document.documentElement.scrollHeight }));
            steps.push({ src: `captures/${name}/${file}`, ...size, focus, ...(click ? { click } : {}), ...(highlight ? { highlight } : {}) });
        });

        fs.writeFileSync(path.join(dir, 'steps.json'), JSON.stringify({ steps }, null, 2) + '\n');
        fs.rmSync(final, { recursive: true, force: true });
        fs.renameSync(dir, final);
        console.log(`OK   ${name}: ${steps.length} steps -> ${path.relative(process.cwd(), final)}`);
    } catch (error) {
        failed = true;
        console.log(`FAIL ${name}: ${error.message.split('\n')[0]}`);
    }

    await ctx.close();
}

await browser.close();
process.exit(failed ? 1 : 0);
