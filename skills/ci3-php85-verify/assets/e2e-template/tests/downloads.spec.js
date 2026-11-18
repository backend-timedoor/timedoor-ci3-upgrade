// Generated files (PDF reports, Excel/CSV exports) come back complete and with nothing appended.
//
// downloads.json entry: { "url": "/admin/report/pdf/123", "auth": "admin", "type": "application/pdf",
//                         "allowPhpWarnings": true, "knownIssue": "..." }
// allowPhpWarnings: legacy report code often logs warnings that do not change the file; set it only
// after checking the same warnings appear on the old PHP. Fatal and query errors still fail the test.
import { test, expect } from '../lib/fixtures.js';
import { authFile, readJson } from '../lib/env.js';

const downloads = readJson('downloads.json');

for (const d of downloads) {
  test.describe(d.url, () => {
    if (d.auth) test.use({ storageState: authFile(d.auth) });
    if (d.allowPhpWarnings) test.use({ failOnPhpWarnings: false });

    test(`download ${d.url}`, async ({ page }) => {
      test.setTimeout(180_000); // PDF rendering can be slow
      test.fail(Boolean(d.knownIssue), d.knownIssue);
      const res = await page.request.get(d.url, { timeout: 170_000 });
      expect(res.status()).toBe(200);
      if (d.type) expect(res.headers()['content-type']).toContain(d.type);
      const body = await res.body();
      expect(body.length).toBeGreaterThan(0);
      if ((d.type || '').includes('pdf')) {
        const text = body.toString('latin1');
        expect(text.startsWith('%PDF-')).toBe(true);
        // nothing may follow the PDF (a view or warning printed after streaming corrupts it)
        expect(text.trimEnd().endsWith('%%EOF')).toBe(true);
        expect(text).toMatch(/\/Type\s*\/Page\b/);
      }
    });
  });
}
