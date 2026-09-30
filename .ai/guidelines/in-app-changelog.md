## In-App Changelog — Check Before Every Push to Main

Before you push to `main`, run the `changelog` skill over the commits you are about to push. It decides whether they earn an entry in `config/changelog.php`, the "What's new" page users read, and writes the entry if they do.

- Add an entry only for a change a user notices: a new feature, a new or dropped shop, a Pro change, or a fix to something users hit. Skip refactors, tests, tooling, review follow-ups and small polish. Most pushes add nothing.
- Write every entry in three passes: draft, simplify, then the `humanizer` skill. English only.
- Commit the entry with the change, or as its own commit in the same push.
