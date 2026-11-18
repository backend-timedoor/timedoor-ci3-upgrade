import { defineConfig } from '@playwright/test';
import { BASE_URL } from './lib/env.js';

export default defineConfig({
  testDir: './tests',
  // One worker: every test reads the shared CodeIgniter log, and parallel requests would mix their errors
  workers: 1,
  timeout: 60_000,
  reporter: [['list'], ['html', { open: 'never' }]],
  globalSetup: './global-setup.js',
  globalTeardown: './global-teardown.js',
  use: {
    baseURL: BASE_URL,
    ignoreHTTPSErrors: true, // local self-signed certificates
    // Default: Playwright's Chromium (`npx playwright install chromium`).
    // To use an installed browser instead: E2E_CHANNEL=chrome or E2E_CHANNEL=msedge
    channel: process.env.E2E_CHANNEL || undefined,
    launchOptions: {
      // pages that ask for the camera/microphone get a fake device instead of a permission prompt
      args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'],
    },
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.js/ },
    { name: 'e2e', testMatch: /.*\.spec\.js/, dependencies: ['setup'] },
  ],
});
