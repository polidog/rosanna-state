// JS-16〜JS-20 と RT-12.6
import { expect, idle, test, wrapper } from './support/fixtures';

const lsKeys = (page: import('@playwright/test').Page) => page.evaluate(() => Object.keys(localStorage));
type RosannaApi = { Rosanna: { reloadDefer(name?: string): number; clearDeferCache(name?: string): void } };

test('JS-16: 別オリジン、prefix の外、application/json を挿入しない', async ({ page }) => {
  const requested: string[] = [];
  page.on('request', (request) => requested.push(request.url()));
  const refused: string[] = [];
  page.on('console', (m) => {
    if (m.text().includes('refusing defer')) refused.push(m.text());
  });
  await page.goto('/defer');
  await expect(page.locator('#plain')).toHaveText('plain');
  await expect.poll(() => refused.length).toBe(3);

  await expect(page.locator('.evil')).toHaveCount(0);
  for (const id of ['#cross', '#outside', '#json']) {
    await expect(page.locator(`${id} i`), id).toHaveText('fallback');
  }
  // 別オリジンの URL は同じ名前の断片を返すが、取りに行かない
  expect(requested.filter((url) => url.includes('localhost') || url.endsWith('/other'))).toEqual([]);
});

test('JS-17、JS-18: フォームを含む断片は localStorage に入らず、他は入って再読み込みで使われる', async ({ page }) => {
  await page.goto('/defer');
  await expect(page.locator('#plain')).toHaveText('plain');
  await expect(page.locator('#with-form')).toHaveText('n=0');
  await expect(page.locator('#deferred-counter')).toHaveCount(1);
  const keys = await lsKeys(page);
  expect(keys).toContain('rosanna:defer:/_defer/plain');
  expect(keys).not.toContain('rosanna:defer:/_defer/with-form');
  expect(await page.evaluate(() => JSON.stringify(localStorage))).not.toContain('_rosanna_');

  const hits: string[] = [];
  page.on('request', (request) => hits.push(new URL(request.url()).pathname));
  await page.reload();
  await expect(page.locator('#plain')).toHaveText('plain');
  await expect(page.locator('#with-form')).toHaveText('n=0');
  expect(hits).not.toContain('/_defer/plain');
  expect(hits).toContain('/_defer/with-form');
});

test('JS-17: 古いタブが localStorage に残したトークン入りの断片を使わず消す', async ({ page }) => {
  await page.goto('/defer');
  await expect(page.locator('#with-form')).toHaveText('n=0');
  await page.evaluate(() => {
    localStorage.setItem('rosanna:defer:/_defer/with-form', JSON.stringify({ v: '1', html: '<form data-rosanna-form id="planted"></form>', storedAt: Date.now() }));
  });
  await page.reload();
  await expect(page.locator('#with-form')).toHaveText('n=0');
  await expect(page.locator('#planted')).toHaveCount(0);
  expect(await lsKeys(page)).not.toContain('rosanna:defer:/_defer/with-form');
});

test('JS-18: バージョンが変われば全部消す', async ({ page }) => {
  await page.goto('/defer');
  await expect(page.locator('#plain')).toHaveText('plain');
  await page.evaluate(() => localStorage.setItem('rosanna:defer:__version__', 'old'));
  const hit = page.waitForRequest('**/_defer/plain');
  await page.reload();
  await hit;
  await expect(page.locator('#plain')).toHaveText('plain');
});

test('JS-18: localStorage が使えなくても動く', async ({ page, context }) => {
  await context.addInitScript(() => {
    Object.defineProperty(window, 'localStorage', {
      get() {
        throw new DOMException('denied', 'SecurityError');
      },
    });
  });
  await page.goto('/defer');
  await expect(page.locator('#plain')).toHaveText('plain');
  await expect(page.locator('#with-form')).toHaveText('n=0');
});

test('clearDeferCache: 名前で localStorage から消す', async ({ page }) => {
  await page.goto('/defer');
  await expect(page.locator('#plain')).toHaveText('plain');
  await expect.poll(() => lsKeys(page)).toContain('rosanna:defer:/_defer/plain');
  await page.evaluate(() => (window as unknown as RosannaApi).Rosanna.clearDeferCache('plain'));
  expect(await lsKeys(page)).not.toContain('rosanna:defer:/_defer/plain');
});

test('JS-19: reloadDefer は前の取得を中断し、古い応答を捨てる', async ({ page }) => {
  await page.goto('/defer');
  await page.locator('[data-rosanna-defer-name="counter"][data-rosanna-defer-loaded]').waitFor({ state: 'attached' });
  let served = 0;
  const release: Array<() => void> = [];
  await page.route('**/_defer/counter', async (route) => {
    const n = ++served;
    if (n === 1) await new Promise<void>((resolve) => release.push(resolve));
    await route.fulfill({ status: 200, contentType: 'text/html', body: `<b class="answer">answer-${n}</b>` }).catch(() => undefined);
  });
  const reload = () => page.evaluate(() => (window as unknown as RosannaApi).Rosanna.reloadDefer('counter'));
  // 1 回目が届いてから次を呼ぶ。同じ tick で呼ぶと 1 回目はネットワークに届く前に中断されることがある
  expect(await reload()).toBe(1);
  await expect.poll(() => served).toBe(1);
  expect(await reload()).toBe(1);
  await expect(page.locator('.answer')).toHaveText('answer-2');
  release.forEach((resolve) => resolve());
  await page.waitForTimeout(100);
  await expect(page.locator('.answer')).toHaveText('answer-2');
});

test('JS-19: 同じ tick の reloadDefer でも最後の応答だけを置く', async ({ page }) => {
  await page.goto('/defer');
  await page.locator('[data-rosanna-defer-name="counter"][data-rosanna-defer-loaded]').waitFor({ state: 'attached' });
  let served = 0;
  await page.route('**/_defer/counter', async (route) => {
    const n = ++served;
    await route.fulfill({ status: 200, contentType: 'text/html', body: `<b class="answer">answer-${n}</b>` }).catch(() => undefined);
  });
  await page.evaluate(() => {
    const api = (window as unknown as RosannaApi).Rosanna;
    api.reloadDefer('counter');
    api.reloadDefer('counter');
  });
  await expect(page.locator('.answer')).toHaveCount(1);
  await page.waitForTimeout(100);
  await expect(page.locator('.answer')).toHaveText(`answer-${served}`);
});

test('JS-20: data-rosanna-reload-defer のクリックで取得し直す', async ({ page }) => {
  await page.goto('/defer');
  await expect(page.locator('#deferred-counter')).toHaveCount(1);
  await page.locator('#deferred-input').fill('old');
  const fetched = page.waitForRequest('**/_defer/counter');
  await page.locator('#reload-counter').click();
  await fetched;
  await expect(page.locator('#deferred-input')).toHaveValue('');
});

const recordReloadOrder = (page: import('@playwright/test').Page) => {
  const order: string[] = [];
  page.on('request', (r) => {
    if (r.method() === 'POST') order.push('post');
    else if (r.url().endsWith('/_defer/counter')) order.push('defer');
  });
  return order;
};

test('JS-20: action のボタンの data-rosanna-reload-defer は部分更新の成功の後に動く', async ({ page }) => {
  await page.goto('/defer');
  await expect(page.locator('#deferred-counter')).toHaveCount(1);
  // 最初の action はセッションを始めるので、ページの更新 (JS-21) の取得が混ざる。先にセッションを作って読み直す
  await wrapper(page, 'reloader').locator('.inc').click();
  await expect(wrapper(page, 'reloader').locator('.n')).toHaveText('1');
  await page.reload();
  await expect(page.locator('#deferred-counter')).toHaveCount(1);
  await expect(wrapper(page, 'reloader').locator('.n')).toHaveText('1');

  const order = recordReloadOrder(page);
  await wrapper(page, 'reloader').locator('.inc').click();
  await expect(wrapper(page, 'reloader').locator('.n')).toHaveText('2');
  await idle(page);
  expect(order).toEqual(['post', 'defer']);
});

test('JS-20: セッションを始める action でも部分更新の成功の後に動く', async ({ page }) => {
  await page.goto('/defer');
  await expect(page.locator('#deferred-counter')).toHaveCount(1);
  const order = recordReloadOrder(page);
  await wrapper(page, 'reloader').locator('.inc').click();
  await expect(wrapper(page, 'reloader').locator('.n')).toHaveText('1');
  await expect.poll(() => order.slice(0, 2)).toEqual(['post', 'defer']);
  await idle(page);
  expect(order.filter((kind) => kind === 'post')).toEqual(['post']);
});

test('HD-9: defer の中のコンポーネントの action はページに送られて部分更新される', async ({ page, posts }) => {
  await page.goto('/defer');
  const scope = page.locator('#deferred-counter');
  await scope.locator('.inc').click();
  await expect(scope.locator('.n')).toHaveText('1');
  await scope.locator('.inc').click();
  await expect(scope.locator('.n')).toHaveText('2');
  expect((await posts.list()).every((p) => p.partial && new URL(p.url).pathname === '/defer')).toBe(true);
  await idle(page);
  await page.reload();
  await expect(page.locator('#deferred-counter .n')).toHaveText('2');
});

for (const storage of ['memory', 'session', 'snapshot']) {
  test(`HD-9: 同じ ID を含む 2 つの defer で、後ろの defer の部分更新が前の defer の内容にならない (${storage})`, async ({ page }) => {
    await page.goto(`/defer-same/${storage}`);
    const a = page.locator('#same-a');
    const b = page.locator('#same-b');
    await expect(a.locator('.n')).toHaveText('a=0');
    await b.locator('.inc').click();
    await expect(b.locator('.n')).toHaveText('b=1');
    await b.locator('.inc').click();
    await expect(b.locator('.n')).toHaveText('b=2');
    await idle(page);
    // a の場所に b の内容が入らない。Session の状態はインスタンス ID ごとなので a の値は問わない
    await expect(page.locator('#same-a')).toHaveCount(1);
    await expect(page.locator('#same-b')).toHaveCount(1);
    await expect(a.locator('.n')).toHaveText(storage === 'session' ? /^a=\d+$/ : 'a=0');
  });
}

test('HD-9、JS-22: 同じ ID の Snapshot は、ページの更新の後もそれぞれの場所に戻る', async ({ page }) => {
  await page.goto('/defer-same-start/snapshot');
  const a = page.locator('#same-a .n');
  const b = page.locator('#same-b .n');
  await expect(a).toHaveText('a=0');
  await page.locator('#same-b .inc').click();
  await expect(b).toHaveText('b=1');
  await idle(page);

  const refreshed = page.waitForResponse((r) => r.request().method() === 'GET' && new URL(r.url()).pathname === '/defer-same-start/snapshot');
  await wrapper(page, 'starter').locator('.inc').click();
  await refreshed;
  await expect(wrapper(page, 'starter').locator('.n')).toHaveText('1');
  await idle(page);

  await expect(a).toHaveText('a=0');
  await expect(b).toHaveText('b=1');
});

test('HD-9、JS-21: 入れ子のインスタンスと ID が重なっても、ページの更新が別の場所に当たらない', async ({ page }) => {
  await page.goto('/defer-same-nested');
  await expect(page.locator('#same-outer #same-a .n')).toHaveText('a=0');

  const refreshed = page.waitForResponse((r) => r.request().method() === 'GET' && new URL(r.url()).pathname === '/defer-same-nested');
  await page.locator('#same-b .inc').click();
  await refreshed;
  await expect(page.locator('#same-b .n')).toHaveText('b=1');
  await idle(page);

  // Session の状態はインスタンス ID ごとなので a の値は問わない
  await expect(page.locator('#same-outer #same-a .n')).toHaveText(/^a=\d+$/);
  await expect(page.locator('#same-b')).toHaveCount(1);
});

test('VR-6: defer で後から現れる Snapshot も照合の対象になる', async ({ page, posts }) => {
  await page.goto('/defer');
  const scope = page.locator('#deferred-snapshot');
  await scope.locator('.inc').click();
  await expect(scope.locator('.n')).toHaveText('1');
  await page.goto('/other');
  await page.goBack();
  await expect(scope.locator('.n')).toHaveText('0');
  posts.clear();
  await scope.locator('.inc').click();
  await expect(scope.locator('.n')).toHaveText('1');
  expect((await posts.list()).map((p) => p.type)).toEqual(['restore']);
});

test('RT-12.6: 外部のスクリプトがなければインラインが defer を 1 回取得する。フォームは通常の POST', async ({ page, posts }) => {
  await page.goto('/defer?src=/missing.js');
  await expect(page.locator('#plain')).toHaveText('plain');
  await expect(page.locator('#with-form')).toHaveText('n=0');
  expect(await page.evaluate(() => typeof (window as unknown as RosannaApi).Rosanna.reloadDefer)).toBe('undefined');
  expect(await page.evaluate(() => Object.keys(localStorage).length)).toBe(0);
  await expect(page.locator('#cross i')).toHaveText('fallback');
  await expect(page.locator('#json i')).toHaveText('fallback');

  await wrapper(page, 'reloader').locator('.inc').click();
  await expect(wrapper(page, 'reloader').locator('.n')).toHaveText('1');
  expect((await posts.list())[0].partial).toBe(false);
});

test('RT-12.6: nonce の CSP の下で両方のスクリプトが動く', async ({ page }) => {
  const violations: string[] = [];
  page.on('console', (m) => {
    if (m.text().includes('Content Security Policy')) violations.push(m.text());
  });
  await page.goto('/defer?nonce=abc123');
  await page.waitForFunction(() => typeof (window as unknown as RosannaApi).Rosanna?.reloadDefer === 'function');
  await expect(page.locator('#plain')).toHaveText('plain');
  expect(violations).toEqual([]);

  await page.evaluate(() => localStorage.clear());
  await page.goto('/defer?nonce=abc123&src=/missing.js');
  await expect(page.locator('#with-form')).toHaveText('n=0');
  expect(violations).toEqual([]);
});
