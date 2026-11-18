---
name: ci3-php85-verify
description: Verify and ship a PHP 8.5 upgrade of a CodeIgniter 3 project - lint, framework patch check, smoke-test every route under PHP 8.5 while reading CodeIgniter's log, compare with the old PHP, set up a reusable Playwright e2e suite, write the upgrade doc with a server deployment checklist. Use after ci3-php85-apply, or when someone asks to test, double-check, smoke test, write e2e tests for, document or deploy a CI3 PHP 8 upgrade, or asks why a CI page returns HTTP 500 after switching PHP.
---

# CodeIgniter 3 -> PHP 8.5 verify and ship

Lint passing proves little: PHP 8 breaks at runtime (TypeErrors, division by zero, warnings in the log, corrupted
downloads). Every page must run once under 8.5 while you watch CodeIgniter's log, because production mode shows a
blank 500 or nothing at all. Use a capable model for sorting failures: it needs judgement about what is new on 8.x.

Recipes for each stage: `references/test-recipes.md`. Doc template: `references/doc-template.md`.

## Stages

1. **Static checks.** `lint_all.php` from `ci3-php85-audit` must be clean on 8.5 (and on the old PHP if the
   project must keep running there). `ci3-php85-apply/scripts/patch_ci_system.php <system> --check --index=<index.php>`
   must print `patch complete`.

2. **Make errors visible.** CodeIgniter's log is the test oracle: `log_threshold` >= 1 and a writable `log_path`.
   If the project has it at 0, raise it locally for the run and restore it after (do not commit the change unless the
   user wants logging on). Note the log files' sizes, so you only read what the tests add.

3. **Serve under 8.5.** Prefer the user's own stack switched to 8.5 (Apache/nginx + PHP-FPM, Docker, or a local
   stack's PHP switcher). Otherwise PHP's built-in server with a router (recipe 2). Before blaming the upgrade for a
   500, check the database is up and the local schema is complete (recipe 7).

4. **Smoke every route.**
   - `$PHP85 scripts/list_routes.php <project-root>` lists every controller URL with its base class (which login
     guards it) and marks methods that need ids (`needs args`) or probably write (`WRITES?`).
   - Build a URL list: read-only pages, with real ids from the database for `needs args` entries. Leave out writes.
   - Log in (a throwaway account created in the local DB, or a test login the user gives you) and pass the session
     cookie: `$PHP85 scripts/smoke.php <base-url> urls.txt --cookie=<name>=<value> --log-dir=<logs> [--insecure]`.
     It prints status, size and the log errors each request caused.
   - Run the same list on the old PHP and diff. **New on 8.5** = fix now (back to apply). **Same on both** =
     pre-existing: record it, do not fix unless asked. Endpoints that expect POST data warn on GET on any PHP.

5. **Exercise what GET cannot reach,** on a database backup: forms (create, edit, delete) in the admin, file
   uploads, emails (local mail catcher; a real SMTP login check before deploy), PDF and Excel exports
   (`%PDF-` at the start, `%%EOF` at the end, nothing appended; compare the layout with the old PHP's output),
   cron/CLI controllers (`$PHP85 index.php <controller> <method>`).

6. **Offer a regression suite.** If the user wants repeatable tests, copy `assets/e2e-template/` to `<project>/e2e/`,
   fill `lib/env.js` (base URL, login roles), `pages.json` (from the smoke list) and `downloads.json`, add a throwaway
   account in `global-setup.js`, and run it. Every test fails on new PHP log errors, 5xx and JS errors. Mark
   pre-existing failures with `knownIssue` and list them in its README.

7. **Document.** Write `docs/PHP85_UPGRADE.md` from `references/doc-template.md`: server checklist, what changed,
   behaviour changes, pre-existing issues, how it was tested. Update the README/dev notes (PHP version, patched system/).

8. **Commit and push only when the user asks.** Suggested split: framework, libraries, app fixes, tests, docs.
   Never commit secrets, local log/session files, or `node_modules`.

## Report to the user
What passed (with numbers), what was not tested and needs a human (webcam, payments, real email, production data),
pre-existing problems found (security exposures first), behaviour changes users will notice, the server checklist
location, and any secret that appeared in the transcript and should be rotated.
