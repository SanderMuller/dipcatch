#!/usr/bin/env node
// Captures the screenshots on the "What's new" page into public/changelog/.
// Each shot is the feature's own element plus PADDING of the page around it,
// so it does not sit cramped against the edge. Everything else on the page is
// hidden first, so the padding shows the page background, not a neighbour.
//
//   php tools/changelog-media/seed.php screenshots
//   node tools/changelog-media/capture.mjs [name ...]
//   php tools/changelog-media/seed.php screenshots --teardown
//
// Pass shot names to capture only those. Look at every PNG before you commit
// it: the files are published with the repository.
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

const BASE = process.env.BASE ?? 'https://dipcatch.test';
const OUT = path.resolve('public/changelog');
const PHOTOS = path.resolve('tools/changelog-media/photos');
const FIXTURE = process.env.FIXTURE_PATH ?? '/tmp/changelog-media-screenshots.json';
const PADDING = 40;

if (! fs.existsSync(FIXTURE)) {
    throw new Error(`Missing fixture at ${FIXTURE}. Run php tools/changelog-media/seed.php screenshots first.`);
}

const fixture = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));

/**
 * name => how to reach the element, and an optional viewport width for it.
 */
const shots = {
    'suggested-shops': async (page) => {
        // Under the lg breakpoint the section takes the full width, so the
        // card text is not cut short.
        await page.setViewportSize({ width: 1000, height: 1000 });
        await page.goto(`${BASE}/app`, { waitUntil: 'domcontentloaded' });
        const section = page.locator('[data-test="suggested-shops"]');
        await section.locator('[data-test="suggested-shop"]').first().waitFor({ state: 'visible', timeout: 20000 });

        return { element: section };
    },
    'tracking-ideas': async (page) => {
        await page.goto(`${BASE}/app`, { waitUntil: 'networkidle' });

        return { element: page.locator('[data-test="tracking-ideas"]') };
    },
    'also-sold-at': async (page) => {
        // Narrower, so the one row does not stretch across an empty card.
        await page.setViewportSize({ width: 900, height: 1000 });
        await page.goto(`${BASE}/app/products/${fixture.coffeeId}`, { waitUntil: 'networkidle' });
        const panel = page.locator('[data-flux-accordion-item]:visible').filter({ hasText: 'Also sold at' }).first();
        const heading = panel.locator('[data-flux-accordion-heading]').first();

        if (! (await panel.locator('[data-test="web-suggestion"]').first().isVisible())) {
            await heading.click();
        }

        await panel.locator('[data-test="web-suggestion"]').first().waitFor({ state: 'visible', timeout: 10000 });

        return { element: panel, frame: panel.locator('xpath=ancestor::*[@data-flux-card][1]') };
    },
    'price-changes': async (page) => {
        // The chart and the list under it: each change and what DipCatch did fit one line.
        await page.setViewportSize({ width: 1000, height: 1000 });
        await page.goto(`${BASE}/app/products/${fixture.tabletsId}`, { waitUntil: 'networkidle' });
        const list = page.locator('[data-test="price-changes"]');
        await list.getByRole('button', { name: 'Show price changes' }).click();
        await list.locator('[data-test="price-change-action"]').first().waitFor({ state: 'visible', timeout: 10000 });

        return { element: list.locator('xpath=ancestor::*[@data-flux-card][1]') };
    },
    'search-shops-categories': async (page) => {
        await page.goto(`${BASE}/app`, { waitUntil: 'networkidle' });
        await page.locator('[data-test="app-search-bar"]').click();
        const dialog = page.locator('dialog[open]').first();
        await dialog.locator('input').first().fill('pet');
        await dialog.locator('[data-test="command-group-categories"]').waitFor({ state: 'visible', timeout: 10000 });
        await dialog.locator('[data-test="command-product"] img').first().waitFor({ state: 'visible', timeout: 10000 });
        await page.waitForTimeout(800);

        return { element: dialog };
    },
    'header-search': async (page) => {
        await page.goto(`${BASE}/app`, { waitUntil: 'networkidle' });
        await page.locator('[data-test="app-search-bar"]').click();
        const dialog = page.locator('dialog[open]').first();
        await dialog.locator('input').first().fill('settings');
        await page.waitForTimeout(600);

        return { element: dialog };
    },
};

const wanted = process.argv.slice(2);
const browser = await chromium.launch();
const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 1000 }, deviceScaleFactor: 2 });

// Light mode, and nothing on the page that is not the product: no debug bar.
await ctx.addInitScript(() => {
    window.localStorage.setItem('flux.appearance', 'light');
    document.addEventListener('DOMContentLoaded', () => {
        const style = document.createElement('style');
        style.textContent = '.phpdebugbar, [data-flux-toast-group] { display: none !important; }';
        document.head.append(style);
    });
});

await ctx.route('https://product-photos.changelog-media.test/**', (route) => {
    const file = path.join(PHOTOS, path.basename(new URL(route.request().url()).pathname));
    route.fulfill(fs.existsSync(file) ? { path: file, contentType: 'image/png' } : { status: 404 });
});

const page = await ctx.newPage();

await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });

if (! (await page.title()).includes('DipCatch')) {
    console.error(`Not a DipCatch page: ${await page.title()}`);
    process.exit(2);
}

await page.locator('input[name="email"]').fill(fixture.email);
await page.locator('input[name="password"]').fill(fixture.password);
await page.getByRole('button', { name: 'Log in' }).click();
await page.waitForURL((url) => url.pathname.startsWith('/app'), { timeout: 15000 });

fs.mkdirSync(OUT, { recursive: true });
let failed = false;

for (const [name, reach] of Object.entries(shots)) {
    if (wanted.length > 0 && ! wanted.includes(name)) {
        continue;
    }

    try {
        await page.setViewportSize({ width: 1280, height: 1000 });
        const { element, frame = null } = await reach(page);
        await element.evaluate((target, padding) => {
            // Take every sibling along the path to <body> out of the page, so
            // the space around the shot is empty page, not a neighbour. The
            // body padding keeps that space when the element is full width.
            for (let node = target; node.parentElement !== null; node = node.parentElement) {
                for (const sibling of node.parentElement.children) {
                    if (sibling !== node && sibling.tagName !== 'SCRIPT' && sibling.tagName !== 'STYLE') {
                        sibling.style.setProperty('display', 'none', 'important');
                    }
                }
            }

            document.body.style.padding = `${padding}px`;
            window.scrollTo(0, 0);

            // A modal's backdrop dims the page it covers; there is no page left.
            if (target.tagName === 'DIALOG') {
                target.classList.add('changelog-media-shot');
                const style = document.createElement('style');
                style.textContent = 'dialog.changelog-media-shot::backdrop { background: transparent !important; backdrop-filter: none !important; }';
                document.head.append(style);
            }
        }, PADDING);
        await page.waitForTimeout(400);

        // `frame`: a card around the element, so the shot shows the card's
        // edge rather than cutting through it.
        const box = await (frame ?? element).boundingBox();

        if (box === null) {
            throw new Error('the element is not on the page');
        }

        const scroll = await page.evaluate(() => ({ x: window.scrollX, y: window.scrollY }));
        const x = Math.max(0, box.x + scroll.x - PADDING);
        const y = Math.max(0, box.y + scroll.y - PADDING);
        const clip = { x, y, width: box.width + 2 * PADDING, height: box.y + scroll.y + box.height + PADDING - y };

        const file = path.join(OUT, `${name}.png`);
        await page.screenshot({ path: file, clip, fullPage: true });
        console.log(`OK   ${name} -> ${path.relative(process.cwd(), file)} (${Math.round(clip.width)}x${Math.round(clip.height)})`);
    } catch (error) {
        failed = true;
        console.log(`FAIL ${name}: ${error.message}`);
    }
}

await browser.close();
process.exit(failed ? 1 : 0);
