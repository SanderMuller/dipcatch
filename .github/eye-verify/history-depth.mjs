import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';
import { createChecker, capturePageIssues } from '../../.claude/skills/frontend-quality/scripts/lib.mjs';

const BASE = 'https://dipcatch.test';
const ART = process.argv[2];
// Seed the two accounts first — see seed-history-fixture.php, which prints
// the product ids this script needs:
//   FIXTURE_PASSWORD=... php .github/eye-verify/seed-history-fixture.php
const PASSWORD = process.env.FIXTURE_PASSWORD ?? 'eye-verify-local-only';
const FREE = { email: 'eye-verify-free@dipcatch.test', product: process.env.FREE_PRODUCT };
const PRO = { email: 'eye-verify-pro@dipcatch.test', product: process.env.PRO_PRODUCT };

if (!FREE.product || !PRO.product) {
  console.error('Set FREE_PRODUCT and PRO_PRODUCT to the ids seed-history-fixture.php printed.');
  process.exit(2);
}
const GATE_OPEN = process.env.GATE_OPEN === '1';

const browser = await chromium.launch();
const checker = createChecker({ artifactsDir: ART, label: GATE_OPEN ? 'history-gate-open' : 'history' });

const DESKTOP = { width: 1280, height: 900 };
const PHONE = { width: 390, height: 844 };

// Cached between runs: the login route is rate limited, and two logins per
// run trips it when the harness is re-run a few times in a minute.
async function stateFor(account) {
  const cache = `${ART}/state-${account.email.replace(/\W/g, '-')}.json`;

  if (existsSync(cache)) {
    const state = JSON.parse(readFileSync(cache, 'utf8'));
    if (await stillSignedIn(state, account)) {
      return state;
    }
  }

  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: DESKTOP });
  const p = await ctx.newPage();
  await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
  await p.fill('input[name=email]', account.email);
  await p.fill('input[name=password]', PASSWORD);
  await p.click('button[type=submit]');
  await p.waitForURL(/\/app/, { timeout: 15000 }).catch(() => {
    throw new Error(`Could not sign in as ${account.email}. Seed the fixture first, and give the login rate limiter a minute if the harness has just run.`);
  });
  const state = await ctx.storageState();
  await ctx.close();
  writeFileSync(cache, JSON.stringify(state));

  return state;
}

async function stillSignedIn(state, account) {
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, storageState: state, viewport: DESKTOP });
  const p = await ctx.newPage();
  const resp = await p.goto(`${BASE}/app/products/${account.product}`, { waitUntil: 'domcontentloaded' });
  const signedIn = resp !== null && resp.status() === 200 && !p.url().includes('/login');
  await ctx.close();

  return signedIn;
}

// Relative luminance + WCAG contrast, from computed rgb() strings.
const probe = () => {
  // Chromium reports Tailwind 4 colours as oklch(...), which a regex cannot
  // read. Paint the colour on a 1x1 canvas and read the pixel back instead —
  // exact for any colour space the browser understands.
  const paint = document.createElement('canvas');
  paint.width = paint.height = 1;
  const paintCtx = paint.getContext('2d', { willReadFrequently: true });
  const rgb = (s) => {
    paintCtx.clearRect(0, 0, 1, 1);
    paintCtx.fillStyle = '#000000';
    paintCtx.fillStyle = s;
    paintCtx.fillRect(0, 0, 1, 1);
    const d = paintCtx.getImageData(0, 0, 1, 1).data;
    return [d[0], d[1], d[2]];
  };
  const lum = (c) => {
    const [r, g, b] = c.map((v) => {
      const s = v / 255;
      return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };
  const bgOf = (el) => {
    let n = el;
    while (n) {
      const bg = getComputedStyle(n).backgroundColor;
      const a = (bg.match(/[\d.]+/g) ?? [])[3];
      if (bg && bg !== 'transparent' && a !== '0') { return bg; }
      n = n.parentElement;
    }
    return getComputedStyle(document.body).backgroundColor;
  };
  const ratio = (fg, bg) => {
    const [a, b] = [lum(rgb(fg)), lum(rgb(bg))].sort((x, y) => y - x);
    return (a + 0.05) / (b + 0.05);
  };

  const notice = [...document.querySelectorAll('p, div, span')]
    .filter((e) => /Your plan shows the last \d+ days/.test(e.textContent ?? '') && e.children.length <= 2)
    .pop() ?? null;
  const link = notice?.querySelector('a') ?? null;
  const linkBox = link?.getBoundingClientRect() ?? null;
  const noticeBox = notice?.getBoundingClientRect() ?? null;
  const canvas = document.querySelector('canvas');
  const canvasBox = canvas?.getBoundingClientRect() ?? null;
  const select = document.querySelector('.fi-wi-chart select, select');
  // Filament bundles Chart.js as a module, so there is no window.Chart to
  // ask. The instance hangs off the widget's Alpine component instead.
  let chartRef = null;
  if (canvas && window.Alpine) {
    let n = canvas;
    while (n && chartRef === null) {
      try {
        const data = window.Alpine.$data(n);
        if (data && typeof data.getChart === 'function') { chartRef = data.getChart() ?? null; }
      } catch (e) { /* not an Alpine root */ }
      n = n.parentElement;
    }
  }

  return {
    dark: document.documentElement.classList.contains('dark'),
    bodyBg: getComputedStyle(document.body).backgroundColor,
    overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    noticeText: notice?.textContent.trim() ?? null,
    noticeColor: notice ? getComputedStyle(notice).color : null,
    noticeBg: notice ? bgOf(notice) : null,
    noticeContrast: notice ? ratio(getComputedStyle(notice).color, bgOf(notice)) : null,
    noticeInViewport: noticeBox ? noticeBox.right <= document.documentElement.clientWidth + 1 && noticeBox.width > 0 : false,
    linkText: link?.textContent.trim() ?? null,
    linkHref: link?.getAttribute('href') ?? null,
    linkContrast: link ? ratio(getComputedStyle(link).color, bgOf(link)) : null,
    linkDistinct: link && notice ? getComputedStyle(link).color !== getComputedStyle(notice).color : null,
    linkDisplay: link ? getComputedStyle(link).display : null,
    linkHeight: linkBox?.height ?? null,
    canvasVisible: !!canvasBox && canvasBox.width > 100 && canvasBox.height > 50,
    canvasWidth: canvasBox?.width ?? null,
    filterOptions: select ? [...select.options].map((o) => o.value + '=' + o.textContent.trim()) : null,
    xAxisHeight: chartRef?.scales?.x?.height ?? null,
    plotHeight: chartRef?.chartArea ? chartRef.chartArea.bottom - chartRef.chartArea.top : null,
    xTickSamples: chartRef?.scales?.x?.ticks?.map((t) => t.label) ?? null,
    scaleIds: chartRef?.scales ? Object.keys(chartRef.scales).sort() : null,
    datasetLabels: chartRef?.data?.datasets?.map((d) => d.label) ?? null,
  };
};

async function look({ state, account, viewport, colorScheme, isMobile, label }) {
  const ctx = await browser.newContext({
    ignoreHTTPSErrors: true, storageState: state, viewport, colorScheme,
    ...(isMobile ? { isMobile: true, hasTouch: true, deviceScaleFactor: 3 } : {}),
  });
  const p = await ctx.newPage();
  const issues = capturePageIssues(p);
  const resp = await p.goto(`${BASE}/app/products/${account.product}`, { waitUntil: 'networkidle' });
  await p.waitForTimeout(900);
  const v = await p.evaluate(probe);
  // Put the widget on screen before capturing — at phone height the notice
  // sits well below the fold, and a viewport shot would prove nothing.
  const widget = p.locator('section.fi-section').filter({ has: p.locator('canvas') }).first();
  const shot = await widget.count() > 0 ? widget : p.locator('body');
  await shot.scrollIntoViewIfNeeded().catch(() => {});
  await p.waitForTimeout(250);
  await shot.screenshot({ path: `${ART}/${label}.png` }).catch(async () => {
    await p.screenshot({ path: `${ART}/${label}.png` });
  });
  const first = issues.failedRequests.filter((r) => r.includes(BASE));
  await ctx.close();
  return { v, status: resp.status(), pageErrors: issues.pageErrors, firstPartyFailures: first };
}

const freeState = await stateFor(FREE);
const proState = await stateFor(PRO);
checker.check('S1 both fixture accounts log in and reach the app', true);

for (const [label, colorScheme] of [['light', 'light'], ['dark', 'dark']]) {
  const r = await look({ state: freeState, account: FREE, viewport: DESKTOP, colorScheme, label: `free-desktop-${label}` });
  const v = r.v;
  checker.check(`D-${label} 1 product page loads with no page errors or first-party failures`, r.status === 200 && r.pageErrors.length === 0 && r.firstPartyFailures.length === 0, `${r.status} ${r.pageErrors.join(';')} ${r.firstPartyFailures.join(';')}`);
  checker.check(`D-${label} 2 the requested colour scheme actually took`, colorScheme === 'dark' ? v.dark : !v.dark, `html.dark=${v.dark} bodyBg=${v.bodyBg}`);
  checker.check(`D-${label} 3 the free plan notice renders`, /Your plan shows the last 90 days\. Pro shows the full history\./.test(v.noticeText ?? ''), String(v.noticeText));
  checker.check(`D-${label} 4 notice text clears WCAG AA (4.5:1) against its own background`, (v.noticeContrast ?? 0) >= 4.5, `ratio=${(v.noticeContrast ?? 0).toFixed(2)} fg=${v.noticeColor} bg=${v.noticeBg}`);
  checker.check(`D-${label} 5b no empty per-unit axis when no shop states a pack size`, JSON.stringify(v.scaleIds) === JSON.stringify(['x', 'y']), `${JSON.stringify(v.scaleIds)} datasets=${JSON.stringify(v.datasetLabels)}`);
  checker.check(`D-${label} 5 the chart itself renders`, v.canvasVisible, JSON.stringify({ w: v.canvasWidth }));
  checker.check(`D-${label} 6 the range menu offers only the free windows`, JSON.stringify(v.filterOptions) === JSON.stringify(['30=Last 30 days', '90=Last 90 days']), JSON.stringify(v.filterOptions));
  if (GATE_OPEN) {
    checker.check(`D-${label} 7 the upgrade link renders when the shop is open`, v.linkText === 'Compare plans' && (v.linkHref ?? '').endsWith('/app/billing'), `${v.linkText} -> ${v.linkHref}`);
    checker.check(`D-${label} 8 the link clears WCAG AA against its background`, (v.linkContrast ?? 0) >= 4.5, `ratio=${(v.linkContrast ?? 0).toFixed(2)}`);
    checker.check(`D-${label} 8b the link is coloured apart from the sentence around it`, v.linkDistinct === true, `distinct=${v.linkDistinct}`);
  } else {
    checker.check(`D-${label} 7 no upgrade link while the shop is closed`, v.linkText === null, String(v.linkText));
  }
}

for (const [label, colorScheme] of [['light', 'light'], ['dark', 'dark']]) {
  const r = await look({ state: freeState, account: FREE, viewport: PHONE, colorScheme, isMobile: true, label: `free-phone-${label}` });
  const v = r.v;
  checker.check(`P-${label} 1 phone page loads clean`, r.status === 200 && r.pageErrors.length === 0 && r.firstPartyFailures.length === 0, `${r.status} ${r.pageErrors.join(';')}`);
  checker.check(`P-${label} 2 the viewport is really 390px wide`, v.clientWidth === 390, `clientWidth=${v.clientWidth}`);
  checker.check(`P-${label} 3 the colour scheme took`, colorScheme === 'dark' ? v.dark : !v.dark, `html.dark=${v.dark}`);
  checker.check(`P-${label} 4 no horizontal overflow at 390px`, !v.overflow, `scroll=${v.scrollWidth} client=${v.clientWidth}`);
  checker.check(`P-${label} 5 the notice renders inside the viewport, not clipped`, v.noticeInViewport, JSON.stringify(v.noticeText));
  checker.check(`P-${label} 6 notice text clears WCAG AA`, (v.noticeContrast ?? 0) >= 4.5, `ratio=${(v.noticeContrast ?? 0).toFixed(2)}`);
  checker.check(`P-${label} 7 the chart still renders and fits the phone`, v.canvasVisible && (v.canvasWidth ?? 999) <= 390, `w=${v.canvasWidth}`);
  checker.check(`P-${label} 9 x-axis ticks are dates, not full timestamps`, (v.xTickSamples ?? []).length > 0 && v.xTickSamples.every((t) => /^\d{4}-\d{2}-\d{2}$/.test(String(t))), JSON.stringify(v.xTickSamples));
  checker.check(`P-${label} 10 the axis labels leave the plot most of the height`, (v.xAxisHeight ?? 0) > 0 && v.xAxisHeight < (v.plotHeight ?? 0), `axis=${v.xAxisHeight} plot=${v.plotHeight}`);
  if (GATE_OPEN) {
    // WCAG 2.2 SC 2.5.8 exempts a link set inline in a sentence from the
    // 24px target, so this asserts the exemption holds rather than the size.
    checker.check(`P-${label} 8 the upgrade link is an inline link in the sentence, coloured apart from it`, v.linkText === 'Compare plans' && (v.linkDisplay ?? '').startsWith('inline') && v.linkDistinct === true, `display=${v.linkDisplay} distinct=${v.linkDistinct} h=${v.linkHeight}`);
  }
}

{
  const r = await look({ state: proState, account: PRO, viewport: DESKTOP, colorScheme: 'light', label: 'pro-desktop-light' });
  const v = r.v;
  checker.check('R1 a Pro account sees no plan notice at all', v.noticeText === null, String(v.noticeText));
  checker.check('R1b the per-unit axis comes back when a shop does state a pack size', JSON.stringify(v.scaleIds) === JSON.stringify(['unit', 'x', 'y']), `${JSON.stringify(v.scaleIds)} datasets=${JSON.stringify(v.datasetLabels)}`);
  checker.check('R2 a Pro account is offered the long ranges', JSON.stringify(v.filterOptions) === JSON.stringify(['30=Last 30 days', '90=Last 90 days', '365=Last 365 days', 'all=All time']), JSON.stringify(v.filterOptions));
  const m = await look({ state: proState, account: PRO, viewport: PHONE, colorScheme: 'light', isMobile: true, label: 'pro-phone-light' });
  checker.check('R3 the Pro range menu does not overflow the phone', !m.v.overflow && m.v.canvasVisible, `scroll=${m.v.scrollWidth}`);
}

// The options are sent once, at first render; a range change only replaces
// the datasets. So an axis that came and went with the range would leave
// Chart.js to invent an undeclared one.
{
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, storageState: proState, viewport: DESKTOP, colorScheme: 'light' });
  const p = await ctx.newPage();
  await p.goto(`${BASE}/app/products/${PRO.product}`, { waitUntil: 'networkidle' });
  await p.waitForTimeout(900);

  const scalesNow = async () => (await p.evaluate(probe)).scaleIds;
  const opening = await scalesNow();
  await p.selectOption('select', '30');
  await p.waitForTimeout(1500);
  const narrowed = await scalesNow();
  await p.selectOption('select', 'all');
  await p.waitForTimeout(1500);
  const widened = await scalesNow();
  await ctx.close();

  checker.check(
    'R4 the axes stay the same set across a range change',
    JSON.stringify(opening) === JSON.stringify(narrowed) && JSON.stringify(narrowed) === JSON.stringify(widened),
    `${JSON.stringify(opening)} -> ${JSON.stringify(narrowed)} -> ${JSON.stringify(widened)}`,
  );
}

const code = await checker.summarize();
await browser.close();
process.exit(code ? 1 : 0);
