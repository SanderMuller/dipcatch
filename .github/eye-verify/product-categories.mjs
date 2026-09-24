#!/usr/bin/env node
// Drives the browser checks left open in specs/product-categories.md:
// the automatic-categories switch on a free and a Pro account, the grouped
// category select on the edit form, the list filter surviving a reload from
// the URL on a wide and a narrow screen, and the category badge on a narrow
// screen.
//
// Needs `php .github/eye-verify/product-categories-seed.php`; tear down with
// the same script and `--teardown`.
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { createChecker, capturePageIssues } from '../../.claude/skills/frontend-quality/scripts/lib.mjs';

const BASE = process.env.BASE ?? 'https://dipcatch.test';
const ART = path.resolve('.github/eye-verify');
const FIXTURE = process.env.FIXTURE_PATH ?? '/tmp/categories-eye-verify.json';

if (! fs.existsSync(FIXTURE)) {
    throw new Error(`Missing fixture at ${FIXTURE}. Seed the throwaway accounts first.`);
}

const fixture = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
const browser = await chromium.launch();
const flat = (text) => text.replace(/\s+/g, ' ').trim();
const allIssues = [];

async function signIn(email, viewport = { width: 1440, height: 1000 }) {
    const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport });
    const page = await ctx.newPage();
    allIssues.push(capturePageIssues(page));
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="password"]').fill(fixture.password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL((url) => url.pathname.startsWith('/app'), { timeout: 15000 });

    return page;
}

const isDisabled = (locator) => locator.evaluate((el) => el.disabled === true
    || el.getAttribute('aria-disabled') === 'true'
    || el.hasAttribute('disabled')
    || el.hasAttribute('data-disabled'));
const isChecked = (locator) => locator.evaluate((el) => el.getAttribute('aria-checked') === 'true'
    || el.hasAttribute('data-checked')
    || el.checked === true);
// Livewire answers a click with one POST; wait for it rather than for the URL,
// which changes before the new list is painted.
async function settle(page, action) {
    const answered = page.waitForResponse((response) => response.request().method() === 'POST' && response.url().includes('/livewire'), { timeout: 10000 });
    await action();
    await answered;
    await page.waitForLoadState('networkidle');
}
const cardTitles = async (page) => (await page.locator('[data-test="product-card"]').allInnerTexts()).map(flat);

const free = await signIn(fixture.freeEmail);
const checker = createChecker({ page: free, artifactsDir: ART, label: 'product-categories' });

// The switch on a free account: shown, disabled, and says why.
{
    await free.goto(`${BASE}/app/notifications`, { waitUntil: 'networkidle' });
    const toggle = free.locator('[data-test="auto-categories"]');
    checker.check('free: the switch is shown', (await toggle.count()) === 1);
    checker.check('free: the switch is disabled', await isDisabled(toggle));
    const field = toggle.locator('xpath=ancestor::*[@data-flux-field][1]');
    const fieldText = flat(await field.innerText());
    checker.check('free: the description says Pro sorts products', fieldText.includes('Pro sorts products for you. Your choice is kept, and it starts working when you upgrade.'), fieldText);
    checker.check('free: the Pro badge shows', await free.locator('[data-test="auto-categories-pro"]').isVisible());
    await toggle.click({ force: true, timeout: 2000 }).catch(() => {});
    checker.check('free: clicking the disabled switch changes nothing', ! (await isChecked(toggle)));
    await field.screenshot({ path: path.join(ART, 'product-categories-switch-free.png') });
}

// The edit form: every department is a group, every group has categories.
{
    await free.goto(`${BASE}/app/products/${fixture.bananasId}/edit`, { waitUntil: 'networkidle' });
    const select = free.locator('select[data-test="product-category"], [data-test="product-category"] select').first();
    const shape = await select.evaluate((el) => ({
        value: el.value,
        first: el.options[0]?.textContent.trim(),
        firstInGroup: el.options[0]?.parentElement.tagName,
        groups: [...el.querySelectorAll('optgroup')].map((g) => ({ label: g.label, options: g.querySelectorAll('option').length })),
    }));
    checker.check('edit: the select holds the saved category', shape.value === 'food.fresh_produce', shape.value);
    checker.check('edit: "No category" comes first, outside any group', shape.first === 'No category' && shape.firstInGroup === 'SELECT', JSON.stringify(shape.first));
    checker.check('edit: all 14 departments are groups', shape.groups.length === 14, String(shape.groups.length));
    checker.check('edit: every group has a label and a category', shape.groups.every((g) => g.label !== '' && g.options > 0), JSON.stringify(shape.groups));
    checker.check('edit: the food group comes first', shape.groups[0]?.label.toLowerCase().includes('food'), shape.groups[0]?.label);
}

// The list filter on a wide screen, set from the URL and kept on reload.
{
    await free.goto(`${BASE}/app/products?category=food.fresh_produce`, { waitUntil: 'networkidle' });
    let titles = await cardTitles(free);
    checker.check('wide: a category in the URL filters the list', titles.length === 1 && titles[0].includes('EV Cat Bananas'), JSON.stringify(titles));
    const current = flat(await free.locator('[data-test="product-category-nav"] [aria-current="true"]').first().innerText());
    checker.check('wide: the side list marks the category', current.includes('Fresh fruit & vegetables'), current);

    await free.reload({ waitUntil: 'networkidle' });
    titles = await cardTitles(free);
    checker.check('wide: the filter survives a reload', titles.length === 1 && titles[0].includes('EV Cat Bananas'), JSON.stringify(titles));

    await free.goto(`${BASE}/app/products?category=food`, { waitUntil: 'networkidle' });
    titles = await cardTitles(free);
    checker.check('wide: a department filters to its categories', titles.length === 3 && ! titles.some((t) => t.includes('Detergent') || t.includes('Loose')), JSON.stringify(titles));

    await free.goto(`${BASE}/app/products?category=none`, { waitUntil: 'networkidle' });
    titles = await cardTitles(free);
    checker.check('wide: "No category" lists only the uncategorised product', titles.length === 1 && titles[0].includes('EV Cat Loose Item'), JSON.stringify(titles));

    // A department heading unfolds its group; the first item in it picks the
    // whole department.
    const nav = free.locator('[data-test="product-category-nav"]');
    await nav.getByText('Household & cleaning', { exact: true }).click();
    checker.check('wide: a department heading unfolds without filtering', await nav.getByText('All household & cleaning', { exact: true }).isVisible());
    await settle(free, () => nav.getByText('All household & cleaning', { exact: true }).click());
    checker.check('wide: the URL names the department', new URL(free.url()).searchParams.get('category') === 'household', free.url());
    titles = await cardTitles(free);
    checker.check('wide: picking a department in the side list filters to it', titles.length === 1 && titles[0].includes('EV Cat Detergent'), JSON.stringify(titles));
    await settle(free, () => nav.getByText('Laundry', { exact: true }).click());
    checker.check('wide: the URL names the category', new URL(free.url()).searchParams.get('category') === 'household.laundry', free.url());
    titles = await cardTitles(free);
    checker.check('wide: picking a category in the side list updates the URL and the list', titles.length === 1 && titles[0].includes('EV Cat Detergent'), JSON.stringify(titles));
    await free.reload({ waitUntil: 'networkidle' });
    titles = await cardTitles(free);
    checker.check('wide: a picked category survives a reload', titles.length === 1 && titles[0].includes('EV Cat Detergent'), JSON.stringify(titles));
    await free.screenshot({ path: path.join(ART, 'product-categories-list-wide.png') });
}

const phone = await signIn(fixture.freeEmail, { width: 390, height: 844 });

// The list filter on a narrow screen: the select, not the side list.
{
    await phone.goto(`${BASE}/app/products?category=food.dairy_eggs`, { waitUntil: 'networkidle' });
    const select = phone.locator('select[data-test="product-category-filter"], [data-test="product-category-filter"] select').first();
    checker.check('narrow: the side list is hidden', ! (await phone.locator('[data-test="product-category-nav"]').isVisible()));
    checker.check('narrow: the select shows the category from the URL', (await select.inputValue()) === 'food.dairy_eggs');
    let titles = await cardTitles(phone);
    checker.check('narrow: the list is filtered', titles.length === 1 && titles[0].includes('EV Cat Milk'), JSON.stringify(titles));

    await settle(phone, () => select.selectOption('household.laundry'));
    checker.check('narrow: the URL names the picked category', new URL(phone.url()).searchParams.get('category') === 'household.laundry', phone.url());
    await phone.reload({ waitUntil: 'networkidle' });
    titles = await cardTitles(phone);
    checker.check('narrow: a picked category survives a reload', (await select.inputValue()) === 'household.laundry' && titles.length === 1 && titles[0].includes('EV Cat Detergent'), JSON.stringify(titles));

    await phone.goto(`${BASE}/app/products?category=food.meat_fish_veg`, { waitUntil: 'networkidle' });
    const cardBadge = phone.locator('[data-test="product-card"] [data-test="product-category-badge"]').first();
    const cardBox = await phone.locator('[data-test="product-card"]').first().boundingBox();
    const badgeBox = await cardBadge.boundingBox();
    checker.check('narrow: the card badge stays inside its card', badgeBox !== null && cardBox !== null && badgeBox.x + badgeBox.width <= cardBox.x + cardBox.width + 0.5, JSON.stringify({ badgeBox, cardBox }));
    checker.check('narrow: the list does not scroll sideways', ! (await phone.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)));
}

// The badge on the product page, narrow screen, long title.
{
    await phone.goto(`${BASE}/app/products/${fixture.longId}`, { waitUntil: 'networkidle' });
    const badge = phone.locator('[data-test="product-category-badge"] [data-flux-badge]').first();
    const box = await badge.boundingBox();
    const lineHeight = await badge.evaluate((el) => parseFloat(getComputedStyle(el).lineHeight) || 20);
    checker.check('narrow page: the badge stays on one line', box !== null && box.height <= lineHeight + 12, JSON.stringify({ box, lineHeight }));
    checker.check('narrow page: the badge fits the screen', box !== null && box.x >= 0 && box.x + box.width <= 390, JSON.stringify(box));
    const heading = await phone.locator('h1').boundingBox();
    checker.check('narrow page: the badge sits below the title, not inside it', box !== null && heading !== null && box.y >= heading.y + heading.height - 1, JSON.stringify({ box, heading }));
    checker.check('narrow page: the page does not scroll sideways', ! (await phone.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)));
    await phone.locator('h1').locator('xpath=ancestor::div[2]').screenshot({ path: path.join(ART, 'product-categories-badge-narrow.png') });

    await phone.locator('[data-test="product-category-badge"]').click();
    await phone.waitForURL((url) => url.pathname === '/app/products' && url.searchParams.get('category') === 'food.meat_fish_veg', { timeout: 10000 });
    await phone.waitForLoadState('networkidle');
    const titles = await cardTitles(phone);
    checker.check('narrow page: the badge links to the filtered list', titles.length === 1 && titles[0].includes('Plant-Based'), JSON.stringify(titles));
}

// The switch on a Pro account: enabled, and a saved choice round-trips.
{
    const pro = await signIn(fixture.proEmail);
    await pro.goto(`${BASE}/app/notifications`, { waitUntil: 'networkidle' });
    const toggle = pro.locator('[data-test="auto-categories"]');
    checker.check('pro: the switch is enabled', ! (await isDisabled(toggle)));
    const fieldText = flat(await toggle.locator('xpath=ancestor::*[@data-flux-field][1]').innerText());
    checker.check('pro: the description names new products', fieldText.includes('Products you add from now on.'), fieldText);
    checker.check('pro: the switch starts off', ! (await isChecked(toggle)));
    await toggle.click();
    await settle(pro, () => pro.getByRole('button', { name: 'Save settings' }).click());
    await pro.reload({ waitUntil: 'networkidle' });
    checker.check('pro: switching on survives a reload', await isChecked(pro.locator('[data-test="auto-categories"]')));
    await pro.locator('[data-test="auto-categories"]').click();
    await settle(pro, () => pro.getByRole('button', { name: 'Save settings' }).click());
    await pro.reload({ waitUntil: 'networkidle' });
    checker.check('pro: switching off survives a reload', ! (await isChecked(pro.locator('[data-test="auto-categories"]'))));
}

// Not driven here: the switch is absent when no TypeSafe key is configured.
// The local app has a key and its environment file is off limits to this
// harness; `NotificationPreferences` feature tests cover that case.
console.log('  NOT VERIFIED  switch absent with an empty TypeSafe key — needs the local key removed; covered by the Pest test');

checker.check('no page errors', allIssues.every((i) => i.pageErrors.length === 0), allIssues.flatMap((i) => i.pageErrors).join('; '));
checker.check('no console errors', allIssues.every((i) => i.consoleErrors.length === 0), allIssues.flatMap((i) => i.consoleErrors).join('; '));

await browser.close();
await checker.summarize();
