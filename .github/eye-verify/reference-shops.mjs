import { chromium } from 'playwright';
import { createChecker, capturePageIssues } from '../../.claude/skills/frontend-quality/scripts/lib.mjs';

const BASE = 'https://dipcatch.test';
const PRODUCT = process.env.PRODUCT_ID;
const OUT = '.github/eye-verify';
// A host that does not resolve: the probe fails with a wall worth keeping a
// link behind, and no real shop is touched to prove it.
const DEAD_URL = 'https://this-host-does-not-resolve-dipcatch.test/p/1';

const browser = await chromium.launch();
// Desktop width: the shop table hides columns in a container query, and a
// narrow viewport would report a rendered row as invisible.
const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
const issues = capturePageIssues(page);
const checker = createChecker({ page, label: 'reference-shops' });

// Logged in inside the run: this app's session cookie is partitioned, so a
// saved storage state is not restored into a fresh context and every probe
// would quietly land on the login page while still reporting a clean 200.
await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
await page.fill('input[type=email]', process.env.EYE_EMAIL ?? 'demo@dipcatch.test');
await page.fill('input[type=password]', process.env.EYE_PASSWORD ?? 'password');
await page.click('button[type=submit]');
await page.waitForURL(/\/app/, { timeout: 20000 });

const productUrl = `${BASE}/app/products/${PRODUCT}`;
await page.goto(productUrl, { waitUntil: 'networkidle' });
checker.check('the run is signed in, not looking at the login page',
  !page.url().includes('/login'), `landed on ${page.url()}`);
checker.check('product page booted cleanly', issues.clean,
  [...issues.pageErrors, ...issues.consoleErrors, ...issues.failedRequests].join('; '));

// --- 1. the add form offers the link on a wall worth keeping one behind -----
// The form lives behind a disclosure on the product page.
await page.locator('summary').filter({ hasText: 'Add a shop' }).first().click();
const urlField = page.locator('#add-shop-url');
await urlField.waitFor({ state: 'visible', timeout: 15000 });
await urlField.fill(DEAD_URL);
await page.getByRole('button', { name: /Check price/i }).click();

const keepButton = page.getByRole('button', { name: /^Keep as a link$/ });
const offered = await keepButton.waitFor({ timeout: 30000 }).then(() => true).catch(() => false);
checker.check('an unreadable page offers "Keep as a link"', offered);
checker.check('the offer explains what a link is',
  await page.getByText(/never decides the cheapest or the best value/i).isVisible().catch(() => false));
await page.screenshot({ path: `${OUT}/reference-shops-offer.png`, fullPage: false });

// --- 2. keeping it writes the link and says what happened ------------------
await keepButton.click();
const toastText = page.getByText(/Kept as a link/i);
const toast = await toastText.waitFor({ timeout: 15000 }).then(() => true).catch(() => false);
checker.check('keeping a link confirms in words', toast);
// Captured while it is still on screen: a toast fades, and a shot taken after
// it does proves only that the page survived.
if (toast) {
  // The toast fades in; a shot taken on the first visible frame catches it
  // half-transparent and proves less than it looks.
  await page.waitForTimeout(700);
  const box = await toastText.first().boundingBox();
  checker.check('the confirmation is on screen, not buried down the page',
    box !== null && box.y >= 0 && box.y < 1000, box === null ? 'no box' : `y=${Math.round(box.y)}`);
  await page.screenshot({ path: `${OUT}/reference-shops-confirmation.png`, fullPage: false });
}
await page.screenshot({ path: `${OUT}/reference-shops-kept.png`, fullPage: false });

// --- 3. the product page shows a link as a link ----------------------------
await page.goto(productUrl, { waitUntil: 'networkidle' });
checker.check('a link is labelled, not priced',
  await page.getByText('Link only').first().isVisible().catch(() => false));
// Any visible match: the row is rendered twice, once for the narrow layout
// and once for the wide one, and only one of the two is on screen at a time.
const reason = page.getByText(/DipCatch cannot read this shop/i);
let reasonVisible = false;
for (let i = 0; i < await reason.count(); i++) {
  reasonVisible ||= await reason.nth(i).isVisible().catch(() => false);
}
checker.check('the row says why it holds no price', reasonVisible, `${await reason.count()} copies, none on screen`);

// A link must never reach either answer. The headline is where the product
// states its winner, so that is where a wrong one would show.
const headline = (await page.locator('main').innerText()).split('\n').slice(0, 20).join(' ');
checker.check('the link is not named as the winning shop',
  !/this-host-does-not-resolve/i.test(headline), headline.replace(/\s+/g, ' ').slice(0, 200));
checker.check('the product still states the price a tracked shop found',
  new RegExp(process.env.EXPECTED_WINNER ?? 'nothing').test(headline),
  headline.replace(/\s+/g, ' ').slice(0, 200));

checker.check('the link shows no price of its own',
  (await page.getByText('Link only').first().isVisible()));
await page.screenshot({ path: `${OUT}/reference-shops-product.png`, fullPage: true });

// --- 4. a wall that clears in seconds is not offered as a link -------------
await page.goto(productUrl, { waitUntil: 'networkidle' });
await page.locator('summary').filter({ hasText: 'Add a shop' }).first().click();
await urlField.waitFor({ state: 'visible', timeout: 15000 });
await urlField.fill('not-a-url');
await page.getByRole('button', { name: /Check price/i }).click();
await page.waitForTimeout(2500);
checker.check('an invalid URL is not offered as a link',
  !(await page.getByRole('button', { name: /^Keep as a link$/ }).isVisible().catch(() => false)));

// --- 5. the alert names the shops it cannot read --------------------------
await page.goto(`${BASE}/app`, { waitUntil: 'networkidle' });
await page.getByRole('button', { name: /notification/i }).first().click().catch(() => {});
const bellLine = page.getByText(/Also worth checking by hand/i);
const bellShown = await bellLine.first().waitFor({ timeout: 10000 }).then(() => true).catch(() => false);
checker.check('an alert names the shops DipCatch cannot read', bellShown);
checker.check('it names them without pricing them',
  bellShown && /koffiehenk\.nl/.test(await bellLine.first().innerText()));
if (bellShown) {
  await page.screenshot({ path: `${OUT}/reference-shops-alert.png` });
}

// The favicon service answers 404 for the host this run invents: every shop
// row asks it for an icon, and a host that does not exist has none. That is a
// fact about the fixture, not about the app — so the request log decides, and
// the console message it produces is discounted only while every failed
// request is one of those.
const otherFailures = issues.failedRequests.filter((r) => !/favicon/i.test(String(r)));
checker.check('nothing but the fixture favicon failed to load',
  otherFailures.length === 0, otherFailures.join('; '));
checker.check('no uncaught script errors', issues.pageErrors.length === 0,
  issues.pageErrors.join('; '));

await checker.summarize();
await browser.close();
