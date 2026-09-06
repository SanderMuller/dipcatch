# Eye-verify drive scripts

Browser checks that assert what static analysis cannot see: whether an element
is painted, whether it takes up space, and whether it stays out of the Markdown
twin Cloudflare serves to assistants.

```bash
yarn install
npx playwright install chromium   # browser binaries live outside node_modules
node .github/eye-verify/markup-cleanup.mjs
node .github/eye-verify/use-cases.mjs
```

### Scripts that need a signed-in admin

`users-screen.mjs` and `users-comp-flow.mjs` drive the admin panel, so they need
a `storageState` file at `.github/eye-verify/admin-state.json`. It is not in the
repository — it holds a live session cookie. Create a throwaway admin, log in
with Playwright, save the state, and delete the account afterwards. Never commit
that file or the script that logs in.

`users-comp-flow.mjs` also mutates a real account, so point `TARGET_EMAIL` at a
throwaway one you create for the run and delete afterwards.

Each script prints one PASS/FAIL line per assertion and exits non-zero on any
failure. They target `https://dipcatch.test` by default; set `BASE` to point
elsewhere. Every script checks the page title first and exits 2 if it is not a
DipCatch page — pointing at the wrong host makes absence-shaped assertions pass
against nothing, which is how a green run lies.

The `.png` files are the captured evidence, refreshed whenever a script runs.
