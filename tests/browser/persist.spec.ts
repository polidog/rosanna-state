// JS-12〜JS-15、VR-6: persist
import { count, expect, idle, own, test } from './support/fixtures';

const saved = (page: import('@playwright/test').Page, store: 'sessionStorage' | 'localStorage') =>
  page.locator('[data-rosanna$="#p"]').evaluate((el, name) => window[name].getItem(`rosanna:snapshot:${location.pathname}#${el.getAttribute('data-rosanna')}`), store);

/**
 * JS-13 保存値が消えたことを見る。sessionStorage では保存値と照合の写し (VR-6) が同じ鍵なので、
 * discardSaved() が今のページの値 (バージョン 0) を書き戻す。どちらも次の読み込みで restore を送らない。
 */
const discarded = async (page: import('@playwright/test').Page, store: 'sessionStorage' | 'localStorage') => {
  const value = await saved(page, store);
  return store === 'localStorage' ? value === null : value !== null && JSON.parse(value).memo.version === 0;
};

for (const kind of ['sessionStorage', 'localStorage'] as const) {
  test.describe(kind, () => {
    test('JS-12: リロードの後に保存値を restore で描く。署名はページのもの', async ({ page, posts }) => {
      await page.goto(`/persist/${kind}`);
      const sig = await page.locator('[data-rosanna$="#p"]').getAttribute('data-rosanna-restore-sig');
      await own(page, 'p', '.inc').click();
      await expect(count(page, 'p')).toHaveText('1');
      posts.clear();
      await page.reload();
      await expect(count(page, 'p')).toHaveText('1');
      const sent = await posts.list();
      expect(sent.map((p) => p.type)).toEqual(['restore']);
      expect(sent[0].sig).toBe(sig);
      await own(page, 'p', '.inc').click();
      await expect(count(page, 'p')).toHaveText('2');
    });

    test('JS-13: 鍵の変更の後で保存値が消え、再試行しない', async ({ page, context, posts, baseURL }) => {
      await page.goto(`/persist/${kind}`);
      await own(page, 'p', '.inc').click();
      await expect(count(page, 'p')).toHaveText('1');
      expect(await saved(page, kind)).not.toBeNull();

      await context.addCookies([{ name: 'fixture_secret', value: 'rotated-fixture-secret', url: baseURL as string }]);
      posts.clear();
      const rejected = page.waitForResponse((r) => r.request().method() === 'POST');
      await page.reload();
      expect((await rejected).status()).toBe(400);
      await idle(page);
      await expect(count(page, 'p')).toHaveText('0');
      expect(posts.count()).toBe(1);
      await expect.poll(() => discarded(page, kind)).toBe(true);

      posts.clear();
      await page.reload();
      await idle(page);
      expect(posts.count()).toBe(0);

      // 写しも戻っているので最初のクリックが止まらない
      await own(page, 'p', '.inc').click();
      await expect(count(page, 'p')).toHaveText('1');
      expect((await posts.list()).map((p) => p.type)).toEqual(['setState']);
    });

    test('JS-13: 5xx でも捨て、通信の失敗では残す', async ({ page }) => {
      await page.goto(`/persist/${kind}`);
      await own(page, 'p', '.inc').click();
      await expect(count(page, 'p')).toHaveText('1');
      const value = await saved(page, kind);

      await page.route('**/persist/*', (route) => (route.request().method() === 'POST' ? route.abort() : route.fallback()));
      const failed = page.waitForEvent('requestfailed');
      await page.reload();
      await failed;
      await idle(page);
      expect(await saved(page, kind)).toBe(value);
      await page.unroute('**/persist/*');

      await page.route('**/persist/*', (route) => (route.request().method() === 'POST' ? route.fulfill({ status: 503, body: 'down' }) : route.fallback()));
      const answered = page.waitForResponse((r) => r.request().method() === 'POST');
      await page.reload();
      await answered;
      await expect.poll(() => discarded(page, kind)).toBe(true);
    });
  });
}

test('JS-13: redirect で答えたら捨てる', async ({ page }) => {
  await page.goto('/persist/sessionStorage');
  await own(page, 'p', '.inc').click();
  await expect(count(page, 'p')).toHaveText('1');
  await page.route('**/persist/*', (route) => (route.request().method() === 'POST' ? route.fulfill({ status: 303, headers: { location: '/other' } }) : route.fallback()));
  const answered = page.waitForResponse('**/other');
  await page.reload();
  await answered;
  await expect.poll(() => discarded(page, 'sessionStorage')).toBe(true);
  await expect(page).toHaveURL(/\/persist\/sessionStorage$/);
});

test('JS-15: localStorage はタブをまたぎ、写しは sessionStorage にある。forgetSnapshots で消える', async ({ page, context }) => {
  await page.goto('/persist/localStorage');
  await own(page, 'p', '.inc').click();
  await expect(count(page, 'p')).toHaveText('1');
  expect(await saved(page, 'localStorage')).not.toBeNull();
  expect(await saved(page, 'sessionStorage')).not.toBeNull();

  const tab = await context.newPage();
  await tab.goto('/persist/localStorage');
  await expect(count(tab, 'p')).toHaveText('1');
  await tab.evaluate(() => (window as unknown as { Rosanna: { forgetSnapshots(): void } }).Rosanna.forgetSnapshots());
  expect(await saved(tab, 'localStorage')).toBeNull();
  expect(await saved(tab, 'sessionStorage')).toBeNull();
});

test('JS-15: sessionStorage はタブをまたがない', async ({ page, context, posts }) => {
  await page.goto('/persist/sessionStorage');
  await own(page, 'p', '.inc').click();
  await expect(count(page, 'p')).toHaveText('1');
  posts.clear();
  const tab = await context.newPage();
  await tab.goto('/persist/sessionStorage');
  await idle(tab);
  await expect(count(tab, 'p')).toHaveText('0');
  expect(posts.count()).toBe(0);
});

test('JS-13: 同じ値ならページの読み込みで restore を送らない', async ({ page, posts }) => {
  await page.goto('/persist/sessionStorage');
  await idle(page);
  await page.reload();
  await idle(page);
  expect(posts.count()).toBe(0);
});
