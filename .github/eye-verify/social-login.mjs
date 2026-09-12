// Needs both providers configured on the target host: the buttons are hidden
// until every credential a provider needs is set, so a host with an empty
// GOOGLE_CLIENT_ID fails on the first assertion rather than reporting a green
// run against a page that never had the buttons.
import { chromium } from 'playwright';

const BASE = process.env.BASE ?? 'https://dipcatch.test';
let pass = 0, fail = 0;
const check = (n, ok, d = '') => { ok ? (pass++, console.log(`PASS  ${n}`)) : (fail++, console.log(`FAIL  ${n} ${d}`)); };

const browser = await chromium.launch();

for (const scheme of ['light', 'dark']) {
    for (const [label, width, height] of [['desktop', 1280, 900], ['mobile', 390, 844]]) {
        const ctx = await browser.newContext({ viewport: { width, height }, colorScheme: scheme, ignoreHTTPSErrors: true });
        const page = await ctx.newPage();
        const errors = [];
        page.on('console', m => {
            if (m.type() === 'error' && ! m.text().includes('Failed to load resource')) {
                errors.push(m.text());
            }
        });

        for (const path of ['/login', '/register']) {
            const tag = `${scheme}/${label}${path}`;
            await page.goto(BASE + path, { waitUntil: 'networkidle' });

            const title = await page.title();
            if (! title.includes('DipCatch')) {
                console.log(`ABORT wrong host: title is ${title}`);
                process.exit(2);
            }

            const google = page.locator('[data-test="social-login-google"]');
            const apple = page.locator('[data-test="social-login-apple"]');

            check(`${tag} · Google button is visible`, await google.isVisible());
            check(`${tag} · Apple button is visible`, await apple.isVisible());

            // Painted, not merely in the DOM: a mark that collapses to zero
            // width is the failure a `toBeVisible` on the button would miss.
            const markSizes = await page.evaluate(() => [...document.querySelectorAll('[data-test^="social-login-"] svg')]
                .map(e => { const r = e.getBoundingClientRect(); return [r.width, r.height]; }));
            check(`${tag} · both brand marks have size`, markSizes.length === 2 && markSizes.every(([w, h]) => w > 8 && h > 8), JSON.stringify(markSizes));

            const hrefs = await page.evaluate(() => [...document.querySelectorAll('[data-test^="social-login-"]')].map(e => e.getAttribute('href')));
            check(`${tag} · buttons link at the redirect routes`, hrefs.length === 2 && hrefs[0].endsWith('/auth/google/redirect') && hrefs[1].endsWith('/auth/apple/redirect'), JSON.stringify(hrefs));

            // The whole point of the change: the providers sit above the email
            // form. Geometry, because DOM order alone can be undone by CSS.
            const order = await page.evaluate(() => {
                const top = (sel) => document.querySelector(sel)?.getBoundingClientRect().top ?? null;

                return {
                    google: top('[data-test="social-login-google"]'),
                    apple: top('[data-test="social-login-apple"]'),
                    email: top('input[name="email"]'),
                    passkey: top('[data-test="passkey-login-link"]'),
                };
            });

            check(`${tag} · Google sits above the email field`, order.google !== null && order.email !== null && order.google < order.email, JSON.stringify(order));
            check(`${tag} · Apple sits above the email field`, order.apple !== null && order.apple < order.email, JSON.stringify(order));

            if (path === '/login') {
                check(`${tag} · passkey link sits below the email field`, order.passkey !== null && order.passkey > order.email, JSON.stringify(order));

                const passkeyBox = await page.evaluate(() => {
                    const e = document.querySelector('[data-test="passkey-login-link"]');
                    if (! e) {
                        return null;
                    }

                    const r = e.getBoundingClientRect();
                    const row = e.parentElement.getBoundingClientRect();

                    return { w: r.width, row: row.width, h: r.height, size: getComputedStyle(e).fontSize };
                });

                // The hit area has to sit on the words. A block-level button
                // fills its row, so a tap anywhere on that line fires the
                // passkey prompt — which is what happened on a phone.
                check(`${tag} · passkey link is a small text link, not a full-width button`, passkeyBox !== null && passkeyBox.w < passkeyBox.row * 0.9 && passkeyBox.h < 40, JSON.stringify(passkeyBox));
            }

            const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
            check(`${tag} · no horizontal scroll`, overflow === false);

            if (scheme === 'light' && label === 'desktop') {
                await page.screenshot({ path: `.github/eye-verify/social-login${path.replace('/', '-')}.png`, fullPage: true });
            }
        }

        // Failure path: the callback comes back with a message on the login
        // page rather than a blank screen or a silent bounce to the dashboard.
        await page.goto(`${BASE}/auth/google/callback?error=access_denied`, { waitUntil: 'networkidle' });
        check(`${scheme}/${label} · a refused consent lands back on login`, page.url().endsWith('/login'), page.url());

        // On the error element, not the whole page: the login page always
        // contains the words "log in", so a body-text match passes whether or
        // not the error rendered.
        const errorText = await page.evaluate(() => [...document.querySelectorAll('[data-flux-error]')]
            .map(e => e.textContent.trim())
            .filter(t => t !== '')
            .join(' | '));
        check(`${scheme}/${label} · a refused consent shows a visible error on the page`, errorText.includes('sign-in did not finish'), errorText || '(no error element rendered)');

        check(`${scheme}/${label} · no JavaScript console errors`, errors.length === 0, errors.join(' | '));

        await ctx.close();
    }
}

await browser.close();
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
