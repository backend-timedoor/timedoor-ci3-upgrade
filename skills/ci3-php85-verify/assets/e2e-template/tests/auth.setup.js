// Logs in once per role from lib/env.js ROLES and saves the cookies for the other tests.
import { test as setup, expect } from '@playwright/test';
import { ROLES, authFile, readJson } from '../lib/env.js';

const used = new Set([...readJson('pages.json'), ...readJson('downloads.json')].map((p) => p.auth).filter(Boolean));

for (const role of used) {
  setup(`log in as ${role}`, async ({ page }) => {
    const r = ROLES[role];
    if (!r) throw new Error(`pages.json uses role "${role}" but lib/env.js ROLES has no such entry`);
    await page.goto(r.loginUrl);
    await page.fill(r.fields.username, r.username);
    await page.fill(r.fields.password, r.password);
    await page.click(r.submit);
    await expect(page).toHaveURL(r.success);
    await page.context().storageState({ path: authFile(role) });
  });
}
