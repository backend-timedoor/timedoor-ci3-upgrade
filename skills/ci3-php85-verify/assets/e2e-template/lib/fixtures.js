// Test fixture that fails a test when the app misbehaves while it runs:
// - new ERROR lines in the CodeIgniter log (production mode hides PHP errors from the page)
// - same-origin HTTP responses with status >= 500
// - uncaught JavaScript errors on the page
import fs from 'node:fs';
import path from 'node:path';
import { test as base, expect } from '@playwright/test';
import { BASE_URL, LOG_DIR } from './env.js';

function logSizes() {
  const sizes = {};
  for (const f of fs.readdirSync(LOG_DIR)) {
    if (f.startsWith('log-')) sizes[f] = fs.statSync(path.join(LOG_DIR, f)).size;
  }
  return sizes;
}

function newLogErrors(before) {
  const lines = [];
  for (const [f, size] of Object.entries(logSizes())) {
    const start = before[f] ?? 0;
    if (size <= start) continue;
    const fd = fs.openSync(path.join(LOG_DIR, f), 'r');
    const buf = Buffer.alloc(size - start);
    fs.readSync(fd, buf, 0, buf.length, start);
    fs.closeSync(fd);
    lines.push(...buf.toString('utf8').split(/\r?\n/).filter((l) => l.startsWith('ERROR')));
  }
  return lines;
}

const NON_FATAL = /Severity: (Warning|Notice|Deprecated|Runtime Notice|User Warning|User Notice) -->/;

export const test = base.extend({
  // Set false (test.use) where legacy code is known to log warnings that do not change the result
  failOnPhpWarnings: [true, { option: true }],

  appHealth: [
    async ({ page, failOnPhpWarnings }, use) => {
      const origin = new URL(BASE_URL).origin;
      const serverErrors = [];
      const jsErrors = [];
      page.on('response', (r) => {
        if (r.url().startsWith(origin) && r.status() >= 500) serverErrors.push(`${r.status()} ${r.url()}`);
      });
      page.on('pageerror', (e) => jsErrors.push(e.message));

      const before = logSizes();
      await use();

      // Give in-flight AJAX (DataTables etc.) a moment to settle before judging
      await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
      const phpErrors = newLogErrors(before).filter((l) => failOnPhpWarnings || !NON_FATAL.test(l));

      expect.soft(phpErrors, 'PHP errors in CodeIgniter log').toEqual([]);
      expect.soft(serverErrors, 'HTTP 5xx responses').toEqual([]);
      expect.soft(jsErrors, 'Uncaught JavaScript errors').toEqual([]);
    },
    { auto: true },
  ],
});

export { expect };
