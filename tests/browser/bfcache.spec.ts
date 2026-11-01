// VR-6 本物の bfcache の復帰。Playwright は既定で --disable-back-forward-cache を付けるので、この場合だけ外して別に起動する
import { chromium, type Browser, type Page } from '@playwright/test';
import { count, expect, own, test } from './support/fixtures';

let browser: Browser;

test.beforeAll(async () => {
  const executablePath = process.env.ROSANNA_CHROMIUM_PATH;
  browser = await chromium.launch({
    // 既定の headless shell には bfcache がないので、新しいヘッドレスの Chromium を使う
    ...(executablePath ? { executablePath } : { channel: 'chromium' }),
    ignoreDefaultArgs: ['--disable-back-forward-cache'],
  });
});

test.afterAll(async () => {
  await browser.close();
});

// 復帰では load が来ないので commit まで待つ
const back = async (page: Page) => {
  await page.goBack({ waitUntil: 'commit' });
};

const observe = (page: Page) =>
  page.evaluate(() => {
    const w = window as unknown as { __shows?: boolean[] };
    w.__shows = [];
    window.addEventListener('pageshow', (e) => w.__shows!.push(e.persisted));
  });
const shows = (page: Page) => page.evaluate(() => (window as unknown as { __shows: boolean[] }).__shows);

test('VR-6: pageshow の persisted で復帰しても写しを残し、より新しい写しがあれば古いフォームを止めて restore で描く', async ({ baseURL }) => {
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();
  const posted: string[] = [];
  page.on('request', (request) => {
    if (request.method() === 'POST') posted.push(JSON.parse(JSON.parse(new TextDecoder().decode(request.postDataBuffer()!).match(/name="_rosanna_action"\r\n\r\n([^\r]*)/)![1]).action).type);
  });

  // 履歴の最初の項目。バージョン 1 のまま bfcache に入る
  await page.goto('/widget/snapshot');
  await observe(page);
  await own(page, 'w', '.inc').click();
  await expect(count(page, 'w')).toHaveText('1');
  await page.goto('/other');

  // 同じパスを navigate で開き直し、写しをバージョン 3 まで進める
  await page.goto('/widget/snapshot');
  for (const n of ['1', '2', '3']) {
    await own(page, 'w', '.inc').click();
    await expect(count(page, 'w')).toHaveText(n);
  }

  await back(page);
  await expect(page.locator('#other')).toHaveCount(1);
  await back(page);
  await expect(count(page, 'w')).toHaveText('1');

  // 本物の復帰である証拠: スクリプトの状態が残り、persisted で pageshow が来た
  expect(await shows(page)).toEqual([true]);

  posted.length = 0;
  await own(page, 'w', '.inc').click();
  await expect(count(page, 'w')).toHaveText('3');
  expect(posted).toEqual(['restore']);

  await context.close();
});

test('VR-6: 復帰の後も写しと揃っていれば止めない', async ({ baseURL }) => {
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();
  await page.goto('/widget/snapshot');
  await observe(page);
  await own(page, 'w', '.inc').click();
  await expect(count(page, 'w')).toHaveText('1');
  await page.goto('/other');
  await back(page);
  await expect(count(page, 'w')).toHaveText('1');
  expect(await shows(page)).toEqual([true]);

  await own(page, 'w', '.inc').click();
  await expect(count(page, 'w')).toHaveText('2');
  await context.close();
});
