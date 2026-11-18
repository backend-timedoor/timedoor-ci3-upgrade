---
name: ci3-php85-audit
description: Audit a CodeIgniter 3 project (system/ + application/ folders, CI_Controller, CI_VERSION 3.x) for a PHP 8.x / 8.5 upgrade and write a project-specific plan before any code changes. Use this first whenever someone wants to upgrade, migrate, modernize or check PHP 8, 8.2, 8.4 or 8.5 compatibility of a CodeIgniter 3 / CI3 app, mentions a PHP CVE forcing a PHP upgrade on a CI site, or asks "can this CI project run on PHP 8", even if they do not say "audit". Next steps are ci3-php85-apply and ci3-php85-verify.
---

# CodeIgniter 3 -> PHP 8.5 audit

Goal: know everything that will break before touching code, and plan fixes that fit *this* project.
Planning first matters because PHP 8 fatals hide behind each other: the site dies on the first one, so
running it cannot reveal the rest. Every CI3 project differs (CI version, folder layout, local edits to
`system/`, bundled PDF/mail libraries, hand-written helpers), so the plan comes from what the scripts and
your reading find, not from a fixed list.

Chain: **ci3-php85-audit** (this) -> `ci3-php85-apply` (make the fixes) -> `ci3-php85-verify` (test, document, ship).

## Steps

1. **Confirm the project type and make a branch.**
   - Find the front controller (`index.php` with `$system_path` / `$application_folder`) and `system/core/CodeIgniter.php`.
   - CI 3.x is in scope. CI 2.x needs a CI 3 migration first; CI 4 is a different framework. Say so and stop.
   - Note uncommitted changes and create a new branch (for example `upgrade/php85`) before anything changes.
     Do not commit during the work unless the user asks.

2. **Find the PHP binaries.** You need PHP 8.5 for lint and tests, and ideally the project's *current* PHP to
   compare against (that is how you tell an upgrade bug from an old one).
   - Check `php -v`, then other installs: `which -a php` / `where php`, version-named binaries (`php8.5`,
     `php85`), Homebrew (`/opt/homebrew/opt/php@8.5/bin/php`), local stacks (Laragon, XAMPP, MAMP, Herd,
     WAMP keep one folder per version), or Docker: `docker run --rm -v "$PWD":/app -w /app php:8.5-cli php ...`.
   - Call them `$PHP85` and `$PHPOLD` below and use full paths: the default `php` is often another version.
   - If 8.5 is missing, ask the user to install it. Do not install system software without asking.

3. **Lint everything under 8.5:** `$PHP85 scripts/lint_all.php <project-root>`. It runs `php -l` with all errors on, for every
   `.php` file except `vendor/`, `node_modules/`, logs, cache and uploads (add `--with-vendor` for hand-copied
   libraries). Compile-time fatals and deprecations show here.

4. **Scan:** `$PHP85 scripts/scan.php <project-root>` (add `--max=200` for full lists). It prints:
   - layout, CI version, whether `system/` already carries the PHP 8.2 patch, missing `.htaccess` in `system/`/`application/`;
   - config values that matter (session driver and save path, `log_threshold`, DB driver, hooks);
   - bundled libraries with versions (Dompdf, PHPMailer, mPDF, TCPDF, PHPExcel, Adminer, file managers...) and Composer packages;
   - risky code patterns grouped by PHP version, and **dynamic property candidates** (classes writing undeclared
     `$this->x`, after following the parent chain; classes extending `CI_Controller`/`CI_Model` are covered by the system patch).
   Treat every hit as a candidate: open the line and decide. Expect noise in views (JavaScript) and minified files.

5. **Read what scripts cannot judge.**
   - **Local edits to `system/`:** download the stock zip of the project's CI version
     (`https://github.com/bcit-ci/CodeIgniter/archive/refs/tags/<version>.zip`) and diff `system/`. Local edits must be
     carried over or moved to `application/core/MY_*` classes.
   - `application/core` and `application/libraries` `MY_*` classes that override CI internals.
   - Division/modulo hits: which can really be zero (empty answers, empty carts, zero stock)? Those are PHP 8 fatals.
   - `count()` hits on values that can be `null`/`false` (model methods that return `false` when nothing is found).
   - Controllers that stream a file (PDF, Excel) and keep printing afterwards.
   - Git-ignored config (database credentials, `.env`): never print or commit secrets. If a secret lands in the
     transcript, tell the user to rotate it.

6. **Write the plan** in `docs/PHP85_UPGRADE_PLAN.md` (or in chat if the user prefers), using the layout below.
   Ask the user which pre-existing bugs, if any, to fix. Default: list them, do not fix them. An upgrade that only
   contains PHP 8 changes is easy to review and roll back.

## Plan layout

```
# PHP 8.5 upgrade plan: <project>
Current: CI <x>, PHP <y> (server) | Target: PHP 8.5, still runnable on <oldest PHP in use>
Branch: <name>

## 1. Framework (system/)  -> upgrade to 3.1.13 + PHP 8.2-8.5 patch; local system edits found: <none | list>
## 2. Fatals on PHP 8 (file:line, why, fix)
## 3. Behaviour changes (TypeError/ValueError/DivisionByZero, warnings that used to be notices)
## 4. Deprecations 8.1-8.5
## 5. Libraries (name, current version, target version, API or output changes to expect)
## 6. Pre-existing problems found, not planned (security exposures first)
## 7. Risks and decisions for the user (e.g. return INF vs 0 on division by zero, everyone logged out once)
## 8. Effort estimate and what can only be tested by hand (webcam, payment, email, cron)
```

## What usually breaks in a CI3 project (orientation, not a checklist to apply blindly)

- **Framework:** CI <= 3.1.11 fails on PHP 8.1+ (session handler signatures). Stock 3.1.13 still logs 8.2-8.4
  deprecations: dynamic properties on core classes, `E_STRICT`, `session.sid_length`, implicit nullable, `${}`.
- **Front controller:** `E_STRICT` in `index.php` `error_reporting()` calls.
- **PDF and mail libraries:** Dompdf < 2 and PHPMailer 5 are the most common hard fatals. Dompdf 3 also renders
  slightly differently (numeric font weights, fixed footers).
- **App code:** division by zero on empty data, `count(null)`, null into string functions, views that expect
  variables only some controllers pass, removed functions (`each`, `create_function`, `money_format`,
  legacy `implode` argument order).
- **Tools left in the web root** (Adminer, file managers, old upload handlers): they break on PHP 8 and are a
  security exposure. Report them separately.

The detailed fixes are in `ci3-php85-apply/references/fix-catalog.md`.
