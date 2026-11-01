import { defineConfig } from '@playwright/test';

// ローカルの Chromium を使うなら ROSANNA_CHROMIUM_PATH。CI では `npx playwright install chromium` する
const executablePath = process.env.ROSANNA_CHROMIUM_PATH;

export default defineConfig({
  testDir: 'tests/browser',
  outputDir: 'test-results',
  fullyParallel: true,
  forbidOnly: Boolean(process.env.CI),
  // 時々しか通らないテストは、テストか rosanna.js の競合を見つけている。再試行で隠さない
  retries: 0,
  reporter: process.env.CI ? [['github'], ['list']] : 'list',
  expect: { timeout: 10_000 },
  use: {
    browserName: 'chromium',
    trace: 'retain-on-failure',
    launchOptions: executablePath ? { executablePath } : {},
  },
});
