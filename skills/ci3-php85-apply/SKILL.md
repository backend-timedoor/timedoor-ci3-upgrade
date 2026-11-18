---
name: ci3-php85-apply
description: Apply a PHP 8.5 upgrade to a CodeIgniter 3 project in a safe order - replace system/ with a bundled, pre-patched CodeIgniter 3.1.13, patch index.php, upgrade Dompdf/PHPMailer, and fix the app's own PHP 8 breakages - while staying runnable on the old PHP. Use after ci3-php85-audit, or whenever someone asks to do, fix, patch or execute the PHP 8 / 8.2 / 8.4 / 8.5 upgrade of a CodeIgniter 3 app, upgrade CI 3.1.x system to 3.1.13, fix AllowDynamicProperties / E_STRICT / DivisionByZeroError / Dompdf fatal errors in a CI project.
---

# CodeIgniter 3 -> PHP 8.5 apply

Make the fixes from the audit plan (run `ci3-php85-audit` first if there is no plan). Read
`references/fix-catalog.md` before editing: it has the before/after for every fix and the traps found in real upgrades.

Two principles shape everything below:
- **Stay runnable on the old PHP.** Then the code can be deployed before the server switches, and rollback is just
  switching PHP back. Every fix in the catalog works on PHP 7.1+ (check the project's oldest server).
- **Only PHP 8 changes.** Pre-existing bugs go in the docs, not in this change set, unless the user decides
  otherwise or a test cannot pass without the fix (then say so and keep it a separate commit).

## Order

1. **Branch and baseline.** Work on the audit's branch. Note the old PHP and check the app still works there,
   so later you can compare.

2. **Framework (`system/`).**
   - If the audit found local edits in `system/`, carry them over first (best: move them into `application/core/MY_*`).
   - Replace the folder: delete its contents, then copy `assets/codeigniter-3.1.13/system/` (stock 3.1.13, already
     patched for PHP 8.2-8.5, including `.htaccess`). Use the real folder name from `index.php` `$system_path`.
   - Confirm: `$PHP85 scripts/patch_ci_system.php <system-dir> --check` prints `patch complete`.
     If you used a fresh upstream copy instead, run the script without `--check` to apply the patch.
   - Apply the upgrade notes for each CI version step that touch `application/` (catalog 1.1).

3. **Front controller.** `$PHP85 scripts/patch_ci_system.php <system-dir> --index=<index.php>` removes `E_STRICT`.
   Do not replace the project's `index.php`.

4. **Libraries.** Upgrade what the audit flagged (catalog section 5). For Composer-managed copies, pin
   `config.platform.php` to the oldest PHP still in use so the lock file installs on both. Remove the old files from
   git, add runtime caches (Dompdf fonts) to `.gitignore`. Adjust the project's wrapper only if the API changed.

5. **Application fixes,** grouped by kind (catalog section 4): fatals first (removed functions, division by zero,
   `count(null)`), then behaviour changes, then deprecations. Fix the shared root cause once (the model that returns
   `false`, the helper used by many views) after grepping all callers. Do not hide errors with `@` or by lowering
   `error_reporting`: guard the read instead.

6. **Lint after each group** with `$PHP85 -d error_reporting=-1 -l <file>` (or the audit's `lint_all.php`). Do not
   move on with a failing lint. Lint with the old PHP too, if available, to keep it compatible.

7. **Document the framework patch** where the next developer will see it (README or the project's agent/dev notes):
   "system/ is CI 3.1.13 plus a PHP 8.2-8.5 patch; re-apply with patch_ci_system.php if system/ is replaced".

8. **Hand over to `ci3-php85-verify`.** Changes are not done until they are exercised under PHP 8.5.
   Commit only when the user asks; suggested split: framework, libraries, app fixes, docs.

## Behaviour changes to write down for the docs
Anything the user or their customers can notice: everyone logged out once after the PHP switch (session ID length),
PDFs that look slightly different, a page that now shows an error instead of silently wrong numbers, a raised
minimum PHP version. Keep a running list while applying; `ci3-php85-verify` puts it in the upgrade doc.

## Working safely
- Shell syntax differs per OS (bash, zsh, PowerShell, cmd). Use the session's shell and adapt commands. Call the
  PHP 8.5 binary by full path.
- When you start a test server, remember its PID and stop only that one. Never kill every `php` process by name:
  that also kills the user's web server and other projects.
- Never edit or print secrets in git-ignored config. If a config change is needed on the server, write it into the
  deploy checklist instead.
