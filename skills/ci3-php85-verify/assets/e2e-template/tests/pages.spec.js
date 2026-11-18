// Every page in pages.json loads with the expected status and without PHP errors, 5xx or JS errors
// (checked by the appHealth fixture in lib/fixtures.js).
//
// pages.json entry: { "url": "/admin/orders", "auth": "admin", "status": 200, "selector": "table",
//                     "knownIssue": "why it fails today (test is expected to fail until fixed)" }
// Only url is required. auth = role name from lib/env.js ROLES, or omit for logged-out pages.
// List GET pages that only read data. Pages that save/delete/send do not belong here.
import { test, expect } from '../lib/fixtures.js';
import { authFile, readJson, ROLES } from '../lib/env.js';

const pages = readJson('pages.json');
const byRole = new Map();
for (const p of pages) {
  const key = p.auth || '';
  if (!byRole.has(key)) byRole.set(key, []);
  byRole.get(key).push(p);
}

for (const [role, list] of byRole) {
  test.describe(role ? `as ${role}` : 'logged out', () => {
    if (role) test.use({ storageState: authFile(role) });

    for (const p of list) {
      test(p.url, async ({ page }) => {
        test.fail(Boolean(p.knownIssue), p.knownIssue);
        const res = await page.goto(p.url);
        expect(res.status()).toBe(p.status ?? 200);
        // landing on the login page means the session was rejected, even if the status was 200
        if (role) await expect(page).not.toHaveURL(new RegExp(ROLES[role].loginUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '/?$'));
        if (p.selector) await expect(page.locator(p.selector).first()).toBeVisible();
      });
    }
  });
}
