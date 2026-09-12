## Public Repository — Everything You Push Is Published

`SanderMuller/dipcatch` is a public GitHub repository. A later commit, a force-push or a rename does not unpublish what a push already published. Treat every commit, branch name and PR body as permanent and world-readable, and remember that `.gitignore` names files, not classes of file — it is not a safety net.

- Never commit a secret — an API key, token, password, private key, signed URL, internal hostname or connection string. `.env.example` is the only `.env*` file that belongs in the repository, and it carries defaults and placeholders, never a working credential.
- A secret that reached `origin` is leaked, not fixable in place. Stop, tell the user to rotate the credential, and remove it from the code after that.
- Keep personal data out of the repository. Tests, factories, seeders, screenshots and documentation use fictional names and e-mail addresses. Strip session cookies, auth tokens and anything that identifies a person from a captured page fixture under `tests/Fixtures/` before you commit it. A real shop URL or public product page is fine.
- A browser-harness run under `.github/eye-verify/` leaves more than screenshots. Never commit its storage state, trace, HAR or network dump — a storage state holds a live session cookie.
- Commit messages, PR and issue text, code comments and TODOs are public too. Keep an internal host, a private URL and a real customer name out of them. The local `.test` host and a package this repository already depends on are fine.
- Keep an agent working file out of the commit — a plan, a review brief, a scratch note. `/internal` is gitignored and holds them.
- Give a workflow under `.github/workflows/` no more `permissions:` than its jobs need, and give a new ignore in `.github/zizmor.yml` a comment that states why.
