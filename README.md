# ci3-upgrade

Claude Code plugin to upgrade CodeIgniter 3 apps to PHP 8.5, while keeping them runnable on the old PHP during the upgrade.

## Install

```text
/plugin marketplace add https://github.com/backend-timedoor/timedoor-ci3-upgrade
/plugin install ci3-upgrade@timedoor-ci3-upgrade
```

Local testing:

```text
/plugin marketplace add /path/to/timedoor-ci3-upgrade
/plugin install ci3-upgrade@timedoor-ci3-upgrade
```

If you used to have these skills in `~/.claude/skills/ci3-php85-*`, delete those folders so each skill is listed only once.

## Use

Run them in this order, inside the CI3 project (`system/` + `application/`, `CI_VERSION` 3.x):

| Step | Command | What it does |
|------|---------|--------------|
| 1 | `/ci3-upgrade:ci3-php85-audit` | Creates the `upgrade/php85` branch, lints and scans under PHP 8.5, writes a project-specific plan. Changes no code. |
| 2 | `/ci3-upgrade:ci3-php85-apply` | Replaces `system/` with the bundled pre-patched CodeIgniter 3.1.13, patches `index.php`, upgrades Dompdf/PHPMailer, fixes the app's own PHP 8 breakages. |
| 3 | `/ci3-upgrade:ci3-php85-verify` | Lint, framework patch check, smoke-tests every route on PHP 8.5 while reading the CI log, compares with the old PHP, sets up a Playwright e2e suite, writes the upgrade doc with a server deployment checklist. |

## Requirements

- git
- A PHP 8.5 CLI binary and the project's current PHP binary (Laragon, XAMPP, Herd, Homebrew, ...). The skills use full paths; the default `php` on PATH may be another version.
- Node + npm, only for the optional Playwright e2e suite

## Safety

- All upgrade work happens on a new branch. No push unless you ask.
- The audit changes no code; it writes a plan first.
- `system/` is replaced only with the bundled CodeIgniter 3.1.13 (see `skills/ci3-php85-apply/assets/codeigniter-3.1.13/SOURCE.md`).

## Layout

```
.claude-plugin/plugin.json
.claude-plugin/marketplace.json
skills/ci3-php85-audit/
skills/ci3-php85-apply/          (assets/codeigniter-3.1.13, MIT license kept)
skills/ci3-php85-verify/         (assets/e2e-template: Playwright)
```
