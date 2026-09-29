#!/usr/bin/env node
// Drives the "confirming a large drop" note on the product page
// (specs/confirm-large-drops-before-alerting.md, phase `surface`): shown while
// one reading of a large drop waits for a second, gone once a second reading
// confirmed it, and readable on a phone.
//
// Needs `php .github/eye-verify/large-drop-confirming-seed.php`; tear down with
// the same script and `--teardown`.
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { createChecker, capturePageIssues } from '../../.claude/skills/frontend-quality/scripts/lib.mjs';

const BASE = process.env.BASE ?? 'https://dipcatch.test';
const ART = path.resolve('.github/eye-verify');
const FIXTURE = process.env.FIXTURE_PATH ?? '/tmp/large-drop-eye-verify.json';

if (! fs.existsSync(FIXTURE)) {
    throw new Error(`Missing fixture at ${FIXTURE}. Seed the throwaway user first.`);
}

const fixture = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
const browser = await chromium.launch();
const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
const page = await ctx.newPage();
const issues = capturePageIssues(page);
const checker = createChecker({ page, artifactsDir: ART, label: 'large-drop-confirming' });
const flat = (text) => text.replace(/\s+/g, ' ').trim();

await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
await page.locator('input[name="email"]').fill(fixture.email);
await page.locator('input[name="password"]').fill(fixture.password);
await page.getByRole('button', { name: 'Log in' }).click();
await page.waitForURL((url) => url.pathname.startsWith('/app'), { timeout: 15000 });

checker.check('seed: the confirmed product fired exactly one drop', fixture.confirmedEvents === 1, String(fixture.confirmedEvents));

{
    await page.goto(`${BASE}/app/products/${fixture.waitingId}`, { waitUntil: 'networkidle' });
    const note = page.locator('[data-test="confirming-drop"]');
    checker.check('waiting drop: the note is shown once', (await note.count()) === 1);
    const text = flat(await note.innerText());
    checker.check('waiting drop: the note says what happens next', text === 'Confirming a large drop. The alert follows once a second reading agrees.', text);
    checker.check('waiting drop: the note sits in the Alerts column', (await page.locator('[data-test="alert-rules"] ~ [data-test="confirming-drop"]').count()) === 1);
    checker.check('waiting drop: the headline still shows the new price', flat(await page.locator('[data-test="headline-price"]').innerText()).includes('€40.00'));
    const box = await note.boundingBox();
    checker.check('waiting drop: the note is visible', box !== null && box.height > 0 && box.width > 0);
    await page.locator('[data-test="headline-price"]').locator('..').screenshot({ path: path.join(ART, 'large-drop-confirming-desktop.png') });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload({ waitUntil: 'networkidle' });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    checker.check('phone: the page does not scroll sideways', ! overflow);
    const mobileBox = await note.boundingBox();
    checker.check('phone: the note fits the screen', mobileBox !== null && mobileBox.x >= 0 && mobileBox.x + mobileBox.width <= 390, JSON.stringify(mobileBox));
    await note.locator('xpath=ancestor::div[1]').screenshot({ path: path.join(ART, 'large-drop-confirming-phone.png') });
    await page.setViewportSize({ width: 1440, height: 1000 });
}

{
    await page.goto(`${BASE}/app/products/${fixture.confirmedId}`, { waitUntil: 'networkidle' });
    checker.check('confirmed drop: no note', (await page.locator('[data-test="confirming-drop"]').count()) === 0);
    checker.check('confirmed drop: the headline shows the new price', flat(await page.locator('[data-test="headline-price"]').innerText()).includes('€40.00'));
}

checker.check('no page errors', issues.pageErrors.length === 0, issues.pageErrors.join('; '));
checker.check('no console errors', issues.consoleErrors.length === 0, issues.consoleErrors.join('; '));

await browser.close();
await checker.summarize();
