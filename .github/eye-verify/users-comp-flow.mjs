import { chromium } from 'playwright';
const B = 'https://dipcatch.test';
let pass = 0, fail = 0;
const check = (n, ok, d = '') => { ok ? (pass++, console.log(`PASS  ${n}`)) : (fail++, console.log(`FAIL  ${n} ${d}`)); };

const b = await chromium.launch();
const c = await b.newContext({ viewport: { width: 1280, height: 900 }, ignoreHTTPSErrors: true, storageState: '.github/eye-verify/admin-state.json' });
const p = await c.newPage();

const rowFor = (email) => p.locator('tr').filter({ hasText: email });

await p.goto(`${B}/admin/users`, { waitUntil: 'networkidle' });
// The throwaway account this run drives. Create it, run, then delete it.
const email = process.env.TARGET_EMAIL ?? 'eye-verify+temp@dipcatch.test';

check('starts uncomped', (await rowFor(email).innerText()).includes('Free'));

await rowFor(email).getByRole('button', { name: /^Comp$/ }).click();
await p.waitForTimeout(500);
await p.getByLabel('Why').fill('Eye verify run');
await p.getByRole('button', { name: 'Submit' }).click();
await p.waitForTimeout(1200);

let row = await rowFor(email).innerText();
check('shows Pro after comping', row.includes('Pro'), row.replace(/\s+/g, ' '));
check('names the comp as the reason', row.includes('Comp'), row.replace(/\s+/g, ' '));
await p.screenshot({ path: '.github/eye-verify/users-comped-row.png' });

// Round-trip: the state must survive a reload, not just the Livewire response.
await p.reload({ waitUntil: 'networkidle' });
row = await rowFor(email).innerText();
check('survives a reload', row.includes('Pro') && row.includes('Eye verify run'), row.replace(/\s+/g, ' '));

// And back again.
await rowFor(email).getByRole('button', { name: /End comp/ }).click();
await p.waitForTimeout(400);
await p.getByRole('button', { name: /Confirm|End comp/ }).last().click();
await p.waitForTimeout(1200);
await p.reload({ waitUntil: 'networkidle' });
row = await rowFor(email).innerText();
check('drops back to Free when the comp ends', row.includes('Free') && ! row.includes('Eye verify run'), row.replace(/\s+/g, ' '));

await b.close();
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
