#!/usr/bin/env node
// Drives the "what else do you buy" strip on the dashboard: its dialog has a
// name, and keyboard focus lands on a control that is still there after the
// strip is hidden, shown again, or emptied by ticking its last idea.
//
// Needs the throwaway account: run `php .github/eye-verify/tracking-ideas-focus-seed.php`
// first, and again with `--teardown` after. The script re-seeds with `--one-left` itself.
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { createChecker, capturePageIssues } from '../../.claude/skills/frontend-quality/scripts/lib.mjs';

const BASE = process.env.BASE ?? 'https://dipcatch.test';
const ART = path.resolve('.github/eye-verify');
const FIXTURE = process.env.FIXTURE_PATH ?? '/tmp/tracking-ideas-focus-eye-verify.json';
const SEED = '.github/eye-verify/tracking-ideas-focus-seed.php';

if (! fs.existsSync(FIXTURE)) {
    throw new Error(`Missing fixture at ${FIXTURE}. Seed the throwaway user first.`);
}

const fixture = () => JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
const browser = await chromium.launch();
const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
const page = await ctx.newPage();
const issues = capturePageIssues(page);
const checker = createChecker({ page, artifactsDir: ART, label: 'tracking-ideas-focus' });

const focused = () => page.evaluate(() => {
    const el = document.activeElement;

    return el === null ? 'none' : `${el.tagName.toLowerCase()}|${el.getAttribute('data-test') ?? ''}|${el.textContent.trim().slice(0, 40)}`;
});
const dialogOpen = () => page.evaluate(() => document.querySelector('dialog[data-modal="tracking-ideas"]')?.open === true);
const settle = () => page.waitForTimeout(600);

{
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    const title = await page.title();

    if (! title.includes('DipCatch')) {
        console.error(`Not a DipCatch page: ${title}`);
        process.exit(2);
    }

    await page.locator('input[name="email"]').fill(fixture().email);
    await page.locator('input[name="password"]').fill(fixture().password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL((url) => url.pathname.startsWith('/app'), { timeout: 15000 });
}

const browse = () => page.locator('[data-test="tracking-ideas-browse"]');
const showLink = () => page.locator('[data-test="tracking-ideas-show"]');

// The dialog's name.
{
    await page.goto(`${BASE}/app`, { waitUntil: 'networkidle' });
    await browse().click();
    const named = page.getByRole('dialog', { name: 'What else do you buy again and again?' });
    checker.check('the dialog is named by its heading', await named.waitFor({ state: 'visible', timeout: 5000 }).then(() => true).catch(() => false));
    await page.screenshot({ path: path.join(ART, 'tracking-ideas-dialog.png') });
}

// "Hide this list" inside the open dialog.
{
    await page.getByRole('dialog').getByRole('button', { name: 'Hide this list' }).click();
    await showLink().waitFor({ state: 'visible', timeout: 10000 });
    await settle();
    checker.check('hiding from the dialog closes it', ! (await dialogOpen()));
    checker.check('hiding from the dialog moves focus to "Show ideas"', (await focused()).includes('tracking-ideas-show'), await focused());
}

// "Show ideas" with the keyboard.
{
    await page.keyboard.press('Enter');
    await browse().waitFor({ state: 'visible', timeout: 10000 });
    await settle();
    checker.check('showing again moves focus to "Browse ideas"', (await focused()).includes('tracking-ideas-browse'), await focused());
}

// The strip's own hide button.
{
    await page.getByRole('button', { name: 'Hide these ideas' }).click();
    await showLink().waitFor({ state: 'visible', timeout: 10000 });
    await settle();
    checker.check('the strip\'s hide button moves focus to "Show ideas"', (await focused()).includes('tracking-ideas-show'), await focused());
}

// Ticking the last idea empties the strip.
{
    execFileSync('php', [SEED, '--one-left'], { stdio: 'inherit' });
    await page.goto(`${BASE}/app`, { waitUntil: 'networkidle' });
    await browse().click();
    await page.getByRole('dialog').waitFor({ state: 'visible', timeout: 5000 });
    await page.locator(`input[name="tracking_idea_${fixture().lastIdea}"]`).check();
    await browse().waitFor({ state: 'detached', timeout: 10000 });
    await settle();
    checker.check('ticking the last idea closes the dialog', ! (await dialogOpen()));
    checker.check('ticking the last idea moves focus to the page heading', (await focused()).startsWith('h1|'), await focused());
}

checker.check('no first-party failures over the whole run', issues.pageErrors.length === 0
    && issues.failedRequests.filter((request) => request.includes(new URL(BASE).host)).length === 0,
    [...issues.pageErrors, ...issues.failedRequests].join('; '));

await checker.summarize();
await browser.close();
