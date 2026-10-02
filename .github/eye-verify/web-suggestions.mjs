#!/usr/bin/env node
// Drives the web suggestions in the "Also sold at" panel: "Looking for more
// shops…" while discovery runs, the poll that shows a suggestion once it is
// proposed, its checked price, Hide, and both copies of the panel on the page.
//
// Needs the throwaway account: run `php .github/eye-verify/web-suggestions-seed.php`
// first, and again with `--teardown` after. The fixture file it writes holds
// the e-mail and password and lives outside the repository.
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { createChecker, capturePageIssues } from '../../.claude/skills/frontend-quality/scripts/lib.mjs';

const BASE = process.env.BASE ?? 'https://dipcatch.test';
const ART = path.resolve('.github/eye-verify');
const FIXTURE = process.env.FIXTURE_PATH ?? '/tmp/web-suggestions-eye-verify.json';

if (! fs.existsSync(FIXTURE)) {
    throw new Error(`Missing fixture at ${FIXTURE}. Seed the throwaway user first.`);
}

const fixture = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
const browser = await chromium.launch();
const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
const page = await ctx.newPage();
const issues = capturePageIssues(page);
const checker = createChecker({ page, artifactsDir: ART, label: 'web-suggestions' });
const firstParty = () => issues.pageErrors.length === 0
    && issues.failedRequests.filter((request) => request.includes(new URL(BASE).host)).length === 0;

{
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    const title = await page.title();

    if (! title.includes('DipCatch')) {
        console.error(`Not a DipCatch page: ${title}`);
        process.exit(2);
    }

    await page.locator('input[name="email"]').fill(fixture.email);
    await page.locator('input[name="password"]').fill(fixture.password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL((url) => url.pathname.startsWith('/app'), { timeout: 15000 });
}

const panel = () => page.locator('[wire\\:name="suggestions.shop-suggestions"]').first();

{
    await page.goto(`${BASE}/app/products/${fixture.productId}`, { waitUntil: 'networkidle' });
    const running = panel().locator('[data-test="web-discovery-running"]');
    checker.check('the panel says it is looking while discovery is queued', await running.isVisible(), await panel().innerText());
    checker.check('the panel does not say it has no suggestions', ! (await panel().innerText()).includes('No shop suggestions for this product'));
    checker.check('the panel polls often while discovery starts', (await panel().locator('[wire\\:poll\\.visible\\.3s]').count()) > 0);
    await page.screenshot({ path: path.join(ART, 'web-suggestions-looking.png') });
}

{
    // The jobs finish while the page is open: the poll must bring the row in.
    execFileSync('php', ['.github/eye-verify/web-suggestions-seed.php', '--propose'], { stdio: 'inherit' });
    const heading = panel().getByText('Also sold at');
    const appeared = await heading.waitFor({ state: 'visible', timeout: 25000 }).then(() => true).catch(() => false);
    checker.check('the proposed suggestion appears without a reload', appeared);
    // The seed stores the finding, then marks discovery done; a poll between
    // the two shows the row while still polling, so give it one more poll.
    const stopped = await page.waitForFunction(() => ! document.querySelector('[wire\\:name="suggestions.shop-suggestions"] [data-test="web-discovery-poll"]'), null, { timeout: 25000 }).then(() => true).catch(() => false);
    checker.check('the poll stops once discovery is done', stopped);

    await heading.click();
    const row = panel().locator('[data-test="web-suggestion"]').first();
    await row.waitFor({ state: 'visible', timeout: 5000 });
    const text = (await row.innerText()).replace(/\s+/g, ' ');
    checker.check('the row names the shop and the page title', text.includes('koffiehenk.nl') && text.includes('Douwe Egberts Aroma Rood 1 kilo bonen'), text);
    checker.check('the row gives the unit price and the checked price with its date', text.includes('€17.49 /kg') && text.includes('€17.49 for 1 kg when checked on'), text);
    checker.check('the row says it was checked by AI', text.includes('same product, checked by AI'), text);
    checker.check('Add and Hide are there', await row.locator('[data-test="web-suggestion-add"]').isVisible() && await row.locator('[data-test="web-suggestion-hide-menu"]').isVisible());
    await page.screenshot({ path: path.join(ART, 'web-suggestions-row.png') });

    await row.locator('[data-test="web-suggestion-hide-menu"]').click();
    await row.locator('[data-test="web-suggestion-hide"]').click();
    const gone = await panel().locator('[data-test="web-suggestion"]').waitFor({ state: 'detached', timeout: 5000 }).then(() => true).catch(() => false);
    checker.check('Hide removes the row', gone);
    // The other copy refreshes on the event the Hide dispatches.
    const copiesGone = await page.waitForFunction(() => document.querySelectorAll('[data-test="web-suggestion"]').length === 0, null, { timeout: 5000 }).then(() => true).catch(() => false);
    checker.check('no copy of the panel keeps the hidden row', copiesGone, String(await page.locator('[data-test="web-suggestion"]').count()));
}

checker.check('no first-party failures over the whole run', firstParty(), [...issues.pageErrors, ...issues.failedRequests].join('; '));

await checker.summarize();
await browser.close();
