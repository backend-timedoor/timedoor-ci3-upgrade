# e2e tests (Playwright)

Smoke tests for this CodeIgniter app: every listed page and download must work without PHP errors,
HTTP 5xx or JavaScript errors. Production mode hides PHP errors from the page, so each test also reads
what CodeIgniter wrote to `application/logs/` while it ran (needs `log_threshold` >= 1).

## Run

```
cd e2e
npm install
npx playwright install chromium     # once, or use an installed browser: E2E_CHANNEL=chrome / msedge
E2E_BASE_URL=https://myapp.test npm test
npm run report                      # HTML report of the last run
```
(On Windows PowerShell set variables with `$env:E2E_BASE_URL="https://myapp.test"`.)

## Configure

| File | What |
|---|---|
| `lib/env.js` | base URL, log folder, DB connection, login roles (URL, field selectors, credentials) |
| `pages.json` | read-only pages to open, per role (`auth`), with optional `status`, `selector`, `knownIssue` |
| `downloads.json` | PDF/Excel/CSV endpoints to fetch and check |
| `global-setup.js` / `global-teardown.js` | create and remove throwaway login accounts |

Settings via environment: `E2E_BASE_URL`, `E2E_LOG_DIR`, `E2E_DB_HOST/PORT/USER/PASS/NAME`,
`E2E_ADMIN_USER/PASS`, `E2E_CHANNEL`.

Never point it at production: global-setup may write to the database.

## Known issues
List every `knownIssue` here with the reason it is not fixed yet. Such a test is expected to fail;
when someone fixes the bug Playwright reports it as "unexpectedly passed": then remove the `knownIssue`.
