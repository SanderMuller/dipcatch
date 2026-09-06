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

Each script prints one PASS/FAIL line per assertion and exits non-zero on any
failure. They target `https://dipcatch.test` by default; set `BASE` to point
elsewhere. Every script checks the page title first and exits 2 if it is not a
DipCatch page — pointing at the wrong host makes absence-shaped assertions pass
against nothing, which is how a green run lies.

The `.png` files are the captured evidence, refreshed whenever a script runs.
