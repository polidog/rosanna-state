// HD-16、JS-21〜JS-25: 秘密鍵ありの構成で、最初の action がセッションを始めた後のページの更新
import type { Page } from '@playwright/test';
import { AFTER_DEBOUNCE_MS, count, expect, heard, holdPosts, idle, listen, mirrorVersion, own, snapshotVersion, test, wrapper } from './support/fixtures';

/** そのパスの GET を数える。fetch の更新も location.reload() も数に入る */
function countPageGets(page: Page, path: string) {
  const seen: string[] = [];
  page.on('request', (r) => {
    if (r.method() === 'GET' && new URL(r.url()).pathname === path) seen.push(r.resourceType());
  });
  return {
    total: () => seen.length,
    fetches: () => seen.filter((type) => type === 'fetch').length,
    reloads: () => seen.filter((type) => type === 'document').length,
  };
}

function waitSessionStarted(page: Page) {
  return page.waitForResponse((r) => r.request().method() === 'POST' && r.headers()['x-rosanna-session-started'] === '1');
}

async function refreshed(page: Page, path: string) {
  // restore で戻すラッパーのフォームは restore の応答まで古いので、全フォームが新しくなれば済んでいる
  await page.waitForResponse((r) => r.request().method() === 'GET' && new URL(r.url()).pathname === path && r.request().resourceType() === 'fetch');
  await page.waitForFunction(() => [...document.querySelectorAll('form[data-rosanna-form]')].every((f) => f.querySelector('input[name="_rosanna_csrf"]')));
  await idle(page);
}

const token = (page: Page) => page.locator('input[name="_rosanna_csrf"]').first();

test.describe('JS-21 セッションを始めた action の後', () => {
  test('兄弟を A、B の順に押すと B が適用され、エラーにならない', async ({ page, posts }) => {
    await page.goto('/siblings/session');
    await listen(page, 'rosanna:submit-error');
    const gets = countPageGets(page, '/siblings/session');
    await own(page, 'a', '.inc').click();
    await expect(count(page, 'a')).toHaveText('1');
    await expect.poll(() => gets.fetches()).toBe(1);
    await expect(wrapper(page, 'b').locator('input[name="_rosanna_csrf"]').first()).toHaveValue(/.+/);
    await own(page, 'b', '.inc').click();
    await expect(count(page, 'b')).toHaveText('1');
    expect(await heard(page, 'rosanna:submit-error')).toEqual([]);
    expect(posts.count()).toBe(2);
    expect(gets.total()).toBe(1);
    await page.reload();
    await expect(count(page, 'a')).toHaveText('1');
    await expect(count(page, 'b')).toHaveText('1');
  });

  test('更新の後は追加の GET が出ない', async ({ page }) => {
    await page.goto('/siblings/session');
    const done = refreshed(page, '/siblings/session');
    await own(page, 'a', '.inc').click();
    await done;
    const gets = countPageGets(page, '/siblings/session');
    await own(page, 'a', '.inc').click();
    await expect(count(page, 'a')).toHaveText('2');
    await own(page, 'b', '.inc').click();
    await expect(count(page, 'b')).toHaveText('1');
    await idle(page);
    expect(gets.total()).toBe(0);
  });

  test('入力中の欄の値とフォーカスが保たれ、hidden はサーバーに従う', async ({ page }) => {
    await page.goto('/siblings/session');
    await own(page, 'b', '.free').fill('typing');
    await own(page, 'b', '.own .name-field').fill('draft');
    await own(page, 'b', '.free').focus();
    await own(page, 'b', '.free').evaluate((el: HTMLInputElement) => el.setSelectionRange(2, 4));
    await page.evaluate(() => {
      (window as unknown as { __free: Element | null }).__free = document.activeElement;
    });
    const before = await own(page, 'b', '.inc').getAttribute('value');

    const done = refreshed(page, '/siblings/session');
    // フォーカスを奪わずに A を送る
    await own(page, 'a', '.inc').evaluate((el: HTMLButtonElement) => el.click());
    await done;

    await expect(count(page, 'a')).toHaveText('1');
    await expect(own(page, 'b', '.free')).toHaveValue('typing');
    await expect(own(page, 'b', '.own .name-field')).toHaveValue('draft');
    await expect(own(page, 'b', '.free')).toBeFocused();
    expect(await own(page, 'b', '.free').evaluate((el: HTMLInputElement) => [el.selectionStart, el.selectionEnd])).toEqual([2, 4]);
    // 同じ要素のまま
    expect(await page.evaluate(() => document.activeElement === (window as unknown as { __free: Element | null }).__free)).toBe(true);
    expect(await own(page, 'b', '.inc').getAttribute('value')).not.toBe(before);
    await expect(token(page)).toHaveValue(/.+/);
  });

  test('host が JS で変えたラッパーの外と、ラッパーの独自の属性には触らない', async ({ page }) => {
    await page.goto('/siblings/session');
    await page.evaluate(() => {
      const note = document.createElement('p');
      note.id = 'host-note';
      note.textContent = 'host';
      document.querySelector('main')!.prepend(note);
      document.title = 'host-title';
      document.querySelector('[data-rosanna$="#b"]')!.setAttribute('data-host', 'kept');
      (window as unknown as { __main: Element | null }).__main = document.querySelector('main');
    });
    const done = refreshed(page, '/siblings/session');
    await own(page, 'a', '.inc').click();
    await done;
    await expect(page.locator('#host-note')).toHaveText('host');
    await expect(page).toHaveTitle('host-title');
    await expect(wrapper(page, 'b')).toHaveAttribute('data-host', 'kept');
    expect(await page.evaluate(() => document.querySelector('main') === (window as unknown as { __main: Element | null }).__main)).toBe(true);
  });

  test('親子: 親、子の順に先にセッションを作らず押しても通り、子の状態が保たれる', async ({ page }) => {
    await page.goto('/nest/session');
    await own(page, 'child', '.inc').click();
    await expect(count(page, 'child')).toHaveText('1');
    await own(page, 'parent', '.inc').click();
    await expect(count(page, 'parent')).toHaveText('1');
    await expect(count(page, 'child')).toHaveText('1');
    await own(page, 'child', '.inc').click();
    await expect(count(page, 'child')).toHaveText('2');
    await expect(wrapper(page, 'child')).toHaveCount(1);
    await expect(page.locator('[data-rosanna$="#child"] [data-rosanna$="#child"]')).toHaveCount(0);
  });

  test('親から始めても子のフォームが新しくなる', async ({ page }) => {
    await page.goto('/nest/session');
    await own(page, 'parent', '.inc').click();
    await expect(count(page, 'parent')).toHaveText('1');
    await expect.poll(() => wrapper(page, 'child').locator('input[name="_rosanna_csrf"]').first().inputValue()).not.toBe('');
    await own(page, 'child', '.inc').click();
    await expect(count(page, 'child')).toHaveText('1');
  });

  test('RT-4.5: アプリの form の中のコンポーネント。外に置かれたフォームも新しくなる', async ({ page, posts }) => {
    await page.goto('/markup/session');
    await page.locator('#host-field').fill('typed');
    // アプリの form の外に置かれたフォームを使うコンポーネントから始める
    const done = refreshed(page, '/markup/session');
    await wrapper(page, 'in-form').locator('.inc').click();
    await done;
    await expect(wrapper(page, 'in-form').locator('.n')).toHaveText('1');

    // 外のフォームが 1 つだけで、新しいトークンを持ち、ボタンがそれを指す
    const button = wrapper(page, 'in-form').locator('.inc');
    const id = await button.getAttribute('form');
    await expect(page.locator(`form[id="${id}"] input[name="_rosanna_csrf"]`)).toHaveValue(/.+/);
    expect(await page.evaluate(() => [...document.querySelectorAll('form[data-rosanna-form]')]
      .filter((f) => !f.closest('[data-rosanna]') && !document.querySelector(`[form="${f.id}"]`)).length)).toBe(0);

    await button.click();
    await expect(wrapper(page, 'in-form').locator('.n')).toHaveText('2');
    await page.locator('#in-para').click();
    await expect(wrapper(page, 'cells').locator('.n')).toHaveText('1');
    expect((await posts.list()).every((p) => p.partial)).toBe(true);
    await expect(page.locator('#host-field')).toHaveValue('typed');
    await expect(page).toHaveURL(/\/markup\/session$/);
  });

  test('RT-4.5: 別のコンポーネントから始めても、アプリの form の中のボタンが通る', async ({ page }) => {
    await page.goto('/markup/session');
    const done = refreshed(page, '/markup/session');
    await page.locator('#in-para').click();
    await done;
    await wrapper(page, 'in-form').locator('.inc').click();
    await expect(wrapper(page, 'in-form').locator('.n')).toHaveText('1');
    await expect(page).toHaveURL(/\/markup\/session$/);
  });

  test('JS-4、JS-6: 最初の action が redirect で答えても、待っている値が届いてから移動する', async ({ page, posts }) => {
    await page.goto('/behavior/session');
    const hold = await holdPosts(page);
    await own(page, 'w', '.inc').click();
    await hold.held();
    await own(page, 'w', '.live').pressSequentially('z');
    await page.waitForTimeout(AFTER_DEBOUNCE_MS);
    expect(posts.count()).toBe(1);
    hold.release();
    await expect(own(page, 'w', '.shown-live')).toHaveText('z');
    await expect(count(page, 'w')).toHaveText('1');
    expect((await posts.list()).map((p) => p.type)).toEqual(['setState', 'setFields']);
  });
});

test.describe('JS-22 Snapshot の状態', () => {
  test('混在するページで Snapshot の状態が restore で戻り、写しと揃う', async ({ page, posts }) => {
    await page.goto('/mixed');
    await own(page, 'snap', '.inc').click();
    await expect(count(page, 'snap')).toHaveText('1');
    await own(page, 'snap', '.inc').click();
    await expect(count(page, 'snap')).toHaveText('2');
    await own(page, 'snap', '.own .name-field').fill('draft');
    const staleSig = await wrapper(page, 'snap').getAttribute('data-rosanna-restore-sig');

    posts.clear();
    const done = refreshed(page, '/mixed');
    await own(page, 's', '.inc').click();
    await done;
    await expect(count(page, 's')).toHaveText('1');
    await expect(count(page, 'snap')).toHaveText('2');
    expect(await snapshotVersion(page, 'snap')).toBe(2);
    await expect.poll(() => mirrorVersion(page, 'snap')).toBe(2);
    await expect(own(page, 'snap', '.own .name-field')).toHaveValue('draft');
    // 新しいセッションの署名で通っている
    const sent = await posts.list();
    expect(sent.map((p) => p.type)).toEqual(['setState', 'restore']);
    expect(sent[1].sig).not.toBe(staleSig);
    expect(sent[1].fields.get('_rosanna_csrf')).toBeTruthy();

    // 続けて押せて、JS-8 に止められない
    posts.clear();
    await own(page, 'snap', '.inc').click();
    await expect(count(page, 'snap')).toHaveText('3');
    expect((await posts.list()).map((p) => p.type)).toEqual(['setState']);
  });

  test('入力中の欄が初期値を経由して消えない (描画された値のある欄)', async ({ page }) => {
    await page.goto('/mixed');
    await own(page, 'snap', '.own .name-field').fill('saved');
    await own(page, 'snap', '.own .save').click();
    await expect(own(page, 'snap', '.shown-name')).toHaveText('saved');
    await own(page, 'snap', '.own .name-field').fill('draft');
    await own(page, 'snap', '.own .name-field').focus();

    const done = refreshed(page, '/mixed');
    await own(page, 's', '.inc').evaluate((el: HTMLButtonElement) => el.click());
    await done;
    await expect(own(page, 'snap', '.shown-name')).toHaveText('saved');
    await expect(own(page, 'snap', '.own .name-field')).toHaveValue('draft');
    await expect(own(page, 'snap', '.own .name-field')).toBeFocused();
  });

  test('親の restore が子の状態を運び、POST は 1 回で親子とも戻る', async ({ page, posts }) => {
    await page.goto('/mixed');
    await own(page, 'child', '.inc').click();
    await expect(count(page, 'child')).toHaveText('1');
    await own(page, 'parent', '.inc').click();
    await expect(count(page, 'parent')).toHaveText('1');
    posts.clear();
    const done = refreshed(page, '/mixed');
    await own(page, 's', '.inc').click();
    await done;
    await expect(count(page, 'parent')).toHaveText('1');
    await expect(count(page, 'child')).toHaveText('1');
    expect((await posts.list()).map((p) => p.type)).toEqual(['setState', 'restore']);
    await expect.poll(() => mirrorVersion(page, 'child')).toBe(1);
    await own(page, 'child', '.inc').click();
    await expect(count(page, 'child')).toHaveText('2');
  });

  test('状態が変わっていない Snapshot には restore を送らない', async ({ page, posts }) => {
    await page.goto('/mixed');
    const done = refreshed(page, '/mixed');
    await own(page, 's', '.inc').click();
    await done;
    expect((await posts.list()).map((p) => p.type)).toEqual(['setState']);
    await expect(count(page, 'snap')).toHaveText('0');
  });

  for (const kind of ['sessionStorage', 'localStorage'] as const) {
    test(`persist (${kind}): 保存値が初期値に上書きされず、リロードの後も戻る`, async ({ page, posts }) => {
      await page.goto(`/mixed/${kind}`);
      await own(page, 'snap', '.inc').click();
      await expect(count(page, 'snap')).toHaveText('1');
      const done = refreshed(page, `/mixed/${kind}`);
      await own(page, 's', '.inc').click();
      await done;
      await expect(count(page, 'snap')).toHaveText('1');
      posts.clear();
      await page.reload();
      await expect(count(page, 'snap')).toHaveText('1');
      await idle(page);
      expect((await posts.list()).map((p) => p.type)).toEqual(['restore']);
      await own(page, 'snap', '.inc').click();
      await expect(count(page, 'snap')).toHaveText('2');
    });
  }

  test('restore が通信で失敗しても、写しが残るので次のクリックが JS-8 で最新を描く', async ({ page, posts }) => {
    await page.goto('/mixed');
    await own(page, 'snap', '.inc').click();
    await expect(count(page, 'snap')).toHaveText('1');

    let refused = false;
    await page.route('**/mixed', async (route) => {
      const body = route.request().postData() ?? '';
      if (route.request().method() === 'POST' && !refused && body.includes('restore')) {
        refused = true;
        await route.abort();
        return;
      }
      await route.fallback();
    });
    const done = refreshed(page, '/mixed');
    await own(page, 's', '.inc').click();
    await done;
    await expect.poll(() => refused).toBe(true);
    await idle(page);
    await expect(count(page, 'snap')).toHaveText('0');
    expect(await mirrorVersion(page, 'snap')).toBe(1);

    posts.clear();
    await own(page, 'snap', '.inc').click();
    await expect(count(page, 'snap')).toHaveText('1');
    expect((await posts.list()).map((p) => p.type)).toEqual(['restore']);
  });
});

test.describe('JS-23 defer の中のフォーム', () => {
  const ready = async (page: Page) => {
    await expect(page.locator('#with-form')).toHaveCount(1);
    await expect(page.locator('#deferred-counter')).toHaveCount(1);
    await expect(page.locator('#deferred-snapshot')).toHaveCount(1);
    await expect(page.locator('#nested-defer')).toHaveCount(1);
    await expect(page.locator('#plain')).toHaveCount(1);
    await idle(page);
  };

  test('解決済みの defer のフォームが新しくなり、古いフォームが復活しない', async ({ page }) => {
    await page.goto('/defer-start');
    await ready(page);
    await listen(page, 'rosanna:submit-error');
    await page.locator('#deferred-input').fill('typed-in-defer');
    await page.locator('#deferred-snapshot .inc').click();
    await expect(page.locator('#deferred-snapshot .n')).toHaveText('1');

    const done = refreshed(page, '/defer-start');
    await wrapper(page, 'starter').locator('.inc').click();
    await done;
    await expect(wrapper(page, 'starter').locator('.n')).toHaveText('1');

    await expect(page.locator('#deferred-input')).toHaveValue('typed-in-defer');
    await expect(page.locator('#deferred-snapshot .n')).toHaveText('1');
    await page.locator('#deferred-counter .inc').click();
    await expect(page.locator('#deferred-counter .n')).toHaveText('1');
    await page.locator('#with-form').click();
    await expect(page.locator('#with-form')).toHaveText('n=1');
    await page.locator('#deferred-snapshot .inc').click();
    await expect(page.locator('#deferred-snapshot .n')).toHaveText('2');
    await page.locator('#nested-defer').click();
    await expect(page.locator('#nested-defer')).toHaveText('nested=1');
    expect(await heard(page, 'rosanna:submit-error')).toEqual([]);
  });

  test('ページ内のキャッシュが古いフォームを返さない。親の描き直しで defer が取り直されても通る', async ({ page }) => {
    await page.goto('/defer-start');
    await ready(page);
    await listen(page, 'rosanna:submit-error');
    const nestedDefer = () => page.waitForResponse((r) => r.url().endsWith('/_defer/plain2'));

    // 親の action がセッションを始める。応答のプレースホルダで子の defer が取り直される
    const fetched = nestedDefer();
    const done = refreshed(page, '/defer-start');
    await wrapper(page, 'host-widget').locator('> .host-widget > .inc').click();
    await done;
    await fetched;
    await idle(page);
    await expect(page.locator('#nested-defer')).toHaveCount(1);
    const id = await page.locator('#nested-defer').getAttribute('form');
    await expect(page.locator(`form[id="${id}"] input[name="_rosanna_csrf"]`)).toHaveValue(/.+/);

    await page.locator('#nested-defer').click();
    await expect(page.locator('#nested-defer')).toHaveText('nested=1');
    expect(await heard(page, 'rosanna:submit-error')).toEqual([]);
  });

  test('取得中の defer は取り直され、トークンなしの古い応答を使わない', async ({ page }) => {
    const release: Array<() => void> = [];
    let served = 0;
    await page.route('**/_defer/with-form', async (route) => {
      if (++served === 1) await new Promise<void>((resolve) => release.push(resolve));
      await route.fallback().catch(() => undefined);
    });
    await page.goto('/defer-start');
    await expect.poll(() => release.length).toBe(1);
    await expect(wrapper(page, 'starter').locator('.inc')).toBeVisible();
    await listen(page, 'rosanna:submit-error');

    await wrapper(page, 'starter').locator('.inc').click();
    await expect(wrapper(page, 'starter').locator('.n')).toHaveText('1');
    await expect.poll(() => served).toBeGreaterThanOrEqual(2);
    await expect(page.locator('#with-form')).toHaveCount(1);
    release.forEach((resolve) => resolve());
    await page.locator('#with-form').click();
    await expect(page.locator('#with-form')).toHaveText('n=1');
    expect(await heard(page, 'rosanna:submit-error')).toEqual([]);
  });

  test('JS-17: 更新の後もフォームを含む断片は localStorage に入らない', async ({ page }) => {
    await page.goto('/defer-start');
    await ready(page);
    const done = refreshed(page, '/defer-start');
    await wrapper(page, 'starter').locator('.inc').click();
    await done;
    expect(await page.evaluate(() => JSON.stringify(localStorage))).not.toContain('_rosanna_');
  });
});

test.describe('JS-21 更新の GET の失敗', () => {
  const cases: Array<[string, (url: string) => (route: import('@playwright/test').Route) => Promise<void>]> = [
    ['通信の失敗', () => (route) => route.abort()],
    ['500', () => (route) => route.fulfill({ status: 500, contentType: 'text/html', body: 'down' })],
    ['HTML でない応答', () => (route) => route.fulfill({ status: 200, contentType: 'application/json', body: '{}' })],
    ['別オリジンへの redirect', (url) => async (route) => {
      await route.fulfill({ status: 303, headers: { location: url.replace('127.0.0.1', 'localhost') + '/away-target/cors' } });
    }],
  ];

  for (const [name, respond] of cases) {
    test(`${name}: location.reload() に落ち、1 回で止まる`, async ({ page, baseURL }) => {
      await page.goto('/siblings/session');
      await page.evaluate(() => {
        (window as unknown as { __before: boolean }).__before = true;
      });
      const gets = countPageGets(page, '/siblings/session');
      await page.route('**/siblings/session', async (route) => {
        const request = route.request();
        if (request.method() === 'GET' && request.resourceType() === 'fetch') {
          await respond(baseURL as string)(route);
          return;
        }
        await route.fallback();
      });
      await own(page, 'a', '.inc').click();
      await page.waitForFunction(() => !(window as unknown as { __before?: boolean }).__before);
      await expect(count(page, 'a')).toHaveText('1');
      await page.waitForTimeout(300);
      expect(gets.fetches()).toBe(1);
      expect(gets.reloads()).toBe(1);
      // 読み込み直したページのフォームはそのまま通る
      await own(page, 'b', '.inc').click();
      await expect(count(page, 'b')).toHaveText('1');
      expect(gets.fetches()).toBe(1);
    });
  }
});

test.describe('JS-24 更新の間のクリック', () => {
  async function holdRefresh(page: Page) {
    const waiting: Array<() => void> = [];
    await page.route('**/*', async (route) => {
      const request = route.request();
      if (request.method() === 'GET' && request.resourceType() === 'fetch' && !request.url().includes('/_defer/')) {
        await new Promise<void>((resolve) => waiting.push(resolve));
      }
      await route.fallback();
    });
    return {
      held: () => expect.poll(() => waiting.length).toBe(1),
      release: () => waiting.splice(0).forEach((resolve) => resolve()),
    };
  }

  test('固定値の action は送らずに捨てる。古いフォームで 403 にならない', async ({ page, posts }) => {
    await page.goto('/siblings/session');
    await listen(page, 'rosanna:submit-error');
    const hold = await holdRefresh(page);
    await own(page, 'a', '.inc').click();
    await expect(count(page, 'a')).toHaveText('1');
    await hold.held();

    await own(page, 'b', '.inc').click();
    await idle(page);
    expect(posts.count()).toBe(1);

    hold.release();
    await expect(wrapper(page, 'b').locator('input[name="_rosanna_csrf"]').first()).toHaveValue(/.+/);
    await idle(page);
    expect(posts.count()).toBe(1);
    await expect(count(page, 'b')).toHaveText('0');
    expect(await heard(page, 'rosanna:submit-error')).toEqual([]);

    await own(page, 'b', '.inc').click();
    await expect(count(page, 'b')).toHaveText('1');
  });

  test('with-value は最新の値で待って、新しいフォームで送り直す', async ({ page, posts }) => {
    await page.goto('/siblings/session');
    await listen(page, 'rosanna:submit-error');
    const hold = await holdRefresh(page);
    await own(page, 'a', '.inc').click();
    await expect(count(page, 'a')).toHaveText('1');
    await hold.held();

    await own(page, 'b', '.live').pressSequentially('x');
    await page.waitForTimeout(AFTER_DEBOUNCE_MS);
    await own(page, 'b', '.live').pressSequentially('y');
    await page.waitForTimeout(AFTER_DEBOUNCE_MS);
    await own(page, 'b', '.own .name-field').fill('saved');
    await own(page, 'b', '.own .save').click();
    expect(posts.count()).toBe(1);

    hold.release();
    await expect(own(page, 'b', '.shown-live')).toHaveText('xy');
    await expect(own(page, 'b', '.shown-name')).toHaveText('saved');
    await idle(page);
    const sent = await posts.list();
    expect(sent.length).toBe(3);
    expect(sent.slice(1).every((p) => Boolean(p.fields.get('_rosanna_csrf')))).toBe(true);
    await expect(own(page, 'b', '.live')).toHaveValue('xy');
    expect(await heard(page, 'rosanna:submit-error')).toEqual([]);
  });

  test('送信中の Snapshot の応答を待ってから GET し、その状態を保つ', async ({ page, posts }) => {
    await page.goto('/mixed');
    const gets = countPageGets(page, '/mixed');
    const hold = await holdPosts(page);
    await own(page, 'snap', '.inc').click();
    await own(page, 's', '.inc').click();
    await hold.held(2);

    // Session の方だけ通す
    await page.unroute('**/*');
    await page.route('**/*', async (route) => {
      if (route.request().method() === 'POST' && (route.request().postData() ?? '').includes('snapshot')) {
        await page.waitForTimeout(400);
      }
      await route.fallback();
    });
    hold.release();
    await expect(count(page, 's')).toHaveText('1');
    await expect(count(page, 'snap')).toHaveText('1');
    await idle(page);
    await expect.poll(() => gets.fetches()).toBe(1);
    await idle(page);
    await expect(count(page, 'snap')).toHaveText('1');
    await own(page, 'snap', '.inc').click();
    await expect(count(page, 'snap')).toHaveText('2');
    expect(posts.count()).toBeGreaterThanOrEqual(3);
  });
});

test.describe('JS-25 Cookie のない Session の action の同時の送信', () => {
  test('固定値の 2 つ目は送らず、片方の更新もセッションも失わない', async ({ page, context, posts }) => {
    await page.goto('/siblings/session');
    const hold = await holdPosts(page);
    await own(page, 'a', '.inc').click();
    await hold.held();
    await own(page, 'b', '.inc').click();
    await page.waitForTimeout(50);
    expect(posts.count()).toBe(1);
    hold.release();
    await expect(count(page, 'a')).toHaveText('1');
    await idle(page);
    expect(posts.count()).toBe(1);
    await expect(count(page, 'b')).toHaveText('0');
    expect((await context.cookies()).filter((c) => c.name === 'PHPSESSID').length).toBe(1);

    await own(page, 'b', '.inc').click();
    await expect(count(page, 'b')).toHaveText('1');
    await page.reload();
    await expect(count(page, 'a')).toHaveText('1');
    await expect(count(page, 'b')).toHaveText('1');
  });

  test('with-value の 2 つ目は待って、最新の値で送る', async ({ page, posts }) => {
    await page.goto('/siblings/session');
    const hold = await holdPosts(page);
    await own(page, 'a', '.inc').click();
    await hold.held();
    await own(page, 'b', '.live').pressSequentially('v');
    await page.waitForTimeout(AFTER_DEBOUNCE_MS);
    expect(posts.count()).toBe(1);
    hold.release();
    await expect(own(page, 'b', '.shown-live')).toHaveText('v');
    await page.reload();
    await expect(count(page, 'a')).toHaveText('1');
    await expect(own(page, 'b', '.shown-live')).toHaveText('v');
  });

  test('最初の action が失敗すれば止めたものも解け、次の操作が動く', async ({ page }) => {
    await page.goto('/siblings/session');
    await listen(page, 'rosanna:submit-error');
    let fail = true;
    await page.route('**/siblings/session', (route) => {
      if (route.request().method() === 'POST' && fail) {
        fail = false;
        return route.abort();
      }
      return route.fallback();
    });
    await own(page, 'a', '.inc').click();
    await expect(wrapper(page, 'a')).toHaveAttribute('data-rosanna-error', '');
    await own(page, 'b', '.inc').click();
    await expect(count(page, 'b')).toHaveText('1');
  });

  test('クラスのコンポーネントは storageType がなくても Session として止める', async ({ page, posts }) => {
    await page.goto('/class-session');
    const first = page.locator('[data-rosanna$="#a"]');
    const second = page.locator('[data-rosanna$="#b"]');
    const hold = await holdPosts(page);
    await first.locator('button').click();
    await hold.held();
    await second.locator('button').click();
    await page.waitForTimeout(50);
    expect(posts.count()).toBe(1);
    hold.release();
    await expect(first.locator('.n')).toHaveText('1');
    await idle(page);
    await expect(second.locator('input[name="_rosanna_csrf"]').first()).toHaveValue(/.+/);
    await second.locator('button').click();
    await expect(second.locator('.n')).toHaveText('1');
    await page.reload();
    await expect(first.locator('.n')).toHaveText('1');
    await expect(second.locator('.n')).toHaveText('1');
  });

  test('混在: Snapshot の action は止めない', async ({ page, posts }) => {
    await page.goto('/mixed');
    const hold = await holdPosts(page);
    await own(page, 's', '.inc').click();
    await own(page, 'snap', '.inc').click();
    await hold.held(2);
    expect(posts.count()).toBe(2);
    hold.release();
    await expect(count(page, 's')).toHaveText('1');
    await expect(count(page, 'snap')).toHaveText('1');
  });
});

test.describe('通知の届かないフォーム (JS-26)', () => {
  /** Cookie のない 2 つのタブを開き、1 つ目でセッションを始める */
  async function twoTabs(page: Page, context: import('@playwright/test').BrowserContext, path = '/widget/session') {
    await page.goto(path);
    const other = await context.newPage();
    await other.goto(path);
    await own(page, 'w', '.inc').click();
    await expect(count(page, 'w')).toHaveText('1');
    return other;
  }

  test('2 つ目のタブの古いフォームが更新で回復し、rosanna:stale が出る', async ({ page, context, posts }) => {
    const other = await twoTabs(page, context);
    await listen(other, 'rosanna:stale');
    await listen(other, 'rosanna:submit-error');
    const gets = countPageGets(other, '/widget/session');
    posts.clear();

    await own(other, 'w', '.inc').click();
    await expect(count(other, 'w')).toHaveText('1');
    await expect(token(other)).toHaveValue(/.+/);
    expect(await heard(other, 'rosanna:stale')).toEqual([expect.objectContaining({ origin: 'server', status: 403 })]);
    expect(await heard(other, 'rosanna:submit-error')).toEqual([]);
    await expect(wrapper(other, 'w')).not.toHaveAttribute('data-rosanna-error');
    expect(posts.count()).toBe(1);
    expect(gets.fetches()).toBe(1);

    // 適用されていないので押し直すと通る
    await own(other, 'w', '.inc').click();
    await expect(count(other, 'w')).toHaveText('2');
    expect(gets.fetches()).toBe(1);
  });

  test('JS-8a: 送ろうとしたフォームの欄を保ち、保った値を送り直せる', async ({ page, context }) => {
    const other = await twoTabs(page, context);
    await own(other, 'w', '.own .name-field').fill('draft');
    await own(other, 'w', '.own .save').click();
    await expect(token(other)).toHaveValue(/.+/);
    await expect(own(other, 'w', '.own .name-field')).toHaveValue('draft');
    await expect(own(other, 'w', '.shown-name')).toHaveText('');

    await own(other, 'w', '.own .save').click();
    await expect(own(other, 'w', '.shown-name')).toHaveText('draft');
  });

  test('JS-8a: キャンセルすると欄を保たず JS-7 の順に従う。更新は止まらない', async ({ page, context }) => {
    const other = await twoTabs(page, context);
    await other.evaluate(() => document.addEventListener('rosanna:stale', (e) => e.preventDefault()));
    await own(other, 'w', '.own .name-field').fill('draft');
    await own(other, 'w', '.own .save').click();
    await expect(token(other)).toHaveValue(/.+/);
    await expect(own(other, 'w', '.own .name-field')).toHaveValue('');
    await expect(count(other, 'w')).toHaveText('1');
  });

  test('Snapshot の古いフォームも回復し、状態が保たれる', async ({ page, context, posts }) => {
    await page.goto('/mixed');
    const other = await context.newPage();
    await other.goto('/mixed');
    await own(other, 'snap', '.inc').click();
    await expect(count(other, 'snap')).toHaveText('1');
    await own(page, 's', '.inc').click();
    await expect(count(page, 's')).toHaveText('1');

    await listen(other, 'rosanna:stale');
    posts.clear();
    await own(other, 'snap', '.inc').click();
    await expect(token(other)).toHaveValue(/.+/);
    await expect.poll(async () => (await heard(other, 'rosanna:stale')).length).toBe(1);
    await idle(other);
    await expect(count(other, 'snap')).toHaveText('1');
    expect((await posts.list()).map((p) => p.type)).toEqual(['setState', 'restore']);
    await own(other, 'snap', '.inc').click();
    await expect(count(other, 'snap')).toHaveText('2');
  });

  test('JS-8 で止めた古いスナップショットの restore が 400 でも回復する', async ({ page, context, posts }) => {
    await page.goto('/mixed');
    const other = await context.newPage();
    await other.goto('/mixed');
    await own(other, 'snap', '.inc').click();
    await expect(count(other, 'snap')).toHaveText('1');
    const button = own(other, 'snap', '.inc');
    const before = await button.getAttribute('value') as string;
    await own(other, 'snap', '.inc').click();
    await expect(count(other, 'snap')).toHaveText('2');
    await own(page, 's', '.inc').click();
    await expect(count(page, 's')).toHaveText('1');

    // 古い action を置くと JS-8 が止めて restore を送る。その署名はセッションができる前のもの
    await button.evaluate((el: HTMLButtonElement, value) => {
      el.value = value;
    }, before);
    await listen(other, 'rosanna:submit-error');
    posts.clear();
    await button.click();
    await expect(token(other)).toHaveValue(/.+/);
    await idle(other);
    await expect(count(other, 'snap')).toHaveText('2');
    expect(await heard(other, 'rosanna:submit-error')).toEqual([]);
    await own(other, 'snap', '.inc').click();
    await expect(count(other, 'snap')).toHaveText('3');
  });

  test('更新の後でも同じ失敗なら rosanna:submit-error に落ちる', async ({ page, context }) => {
    const other = await twoTabs(page, context);
    await other.route('**/widget/session', (route) => (route.request().method() === 'POST'
      ? route.fulfill({ status: 403, contentType: 'text/plain', body: 'Forbidden' })
      : route.fallback()));
    await listen(other, 'rosanna:stale');
    await listen(other, 'rosanna:submit-error');
    const gets = countPageGets(other, '/widget/session');

    await own(other, 'w', '.inc').click();
    await expect(token(other)).toHaveValue(/.+/);
    expect(await heard(other, 'rosanna:submit-error')).toEqual([]);

    await own(other, 'w', '.inc').click();
    await expect(wrapper(other, 'w')).toHaveAttribute('data-rosanna-error', '');
    expect(await heard(other, 'rosanna:submit-error')).toEqual([expect.objectContaining({ status: 403 })]);
    expect((await heard(other, 'rosanna:stale')).length).toBe(1);
    expect(gets.fetches()).toBe(1);
  });

  test('セッションがまだなければ更新で直らないので、rosanna:stale を出さずエラーにする', async ({ page }) => {
    await page.goto('/widget/session');
    await listen(page, 'rosanna:stale');
    await listen(page, 'rosanna:submit-error');
    const gets = countPageGets(page, '/widget/session');
    const button = own(page, 'w', '.inc');
    await button.evaluate((el: HTMLButtonElement) => {
      el.value = el.value.replace('\\"value\\":1', '\\"value\\":999');
    });
    await button.click();
    await expect(wrapper(page, 'w')).toHaveAttribute('data-rosanna-error', '');
    expect(await heard(page, 'rosanna:stale')).toEqual([]);
    expect(await heard(page, 'rosanna:submit-error')).toEqual([expect.objectContaining({ status: 400 })]);
    expect(gets.fetches()).toBe(1);

    // 同じ失敗では GET を繰り返さない
    await button.click();
    await expect.poll(async () => (await heard(page, 'rosanna:submit-error')).length).toBe(2);
    expect(gets.fetches()).toBe(1);
  });

  test('トークンのあるフォームの本物の 403 は rosanna:submit-error で、更新が走らない', async ({ page }) => {
    await page.goto('/widget/session');
    const done = refreshed(page, '/widget/session');
    await own(page, 'w', '.inc').click();
    await done;
    await expect(token(page)).toHaveValue(/.+/);

    await listen(page, 'rosanna:stale');
    await listen(page, 'rosanna:submit-error');
    const gets = countPageGets(page, '/widget/session');
    await own(page, 'w', '.own .name-field').fill('kept');
    await page.locator('input[name="_rosanna_csrf"]').evaluateAll((els: HTMLInputElement[]) => els.forEach((el) => {
      el.value = 'tampered';
    }));
    await own(page, 'w', '.inc').click();
    await expect(wrapper(page, 'w')).toHaveAttribute('data-rosanna-error', '');
    expect(await heard(page, 'rosanna:submit-error')).toEqual([expect.objectContaining({ status: 403 })]);
    expect(await heard(page, 'rosanna:stale')).toEqual([]);
    await idle(page);
    expect(gets.total()).toBe(0);
    await expect(count(page, 'w')).toHaveText('1');
  });
});

test.describe('更新が走らない構成', () => {
  test('Snapshot だけのページ: ヘッダーも追加の GET も出ない', async ({ page, context }) => {
    await page.goto('/siblings/snapshot');
    const gets = countPageGets(page, '/siblings/snapshot');
    const answered = page.waitForResponse((r) => r.request().method() === 'POST');
    await own(page, 'a', '.inc').click();
    const response = await answered;
    await expect(count(page, 'a')).toHaveText('1');
    expect(response.headers()['x-rosanna-session-started']).toBeUndefined();
    await own(page, 'b', '.inc').click();
    await expect(count(page, 'b')).toHaveText('1');
    await idle(page);
    await page.waitForTimeout(100);
    expect(gets.total()).toBe(0);
    expect((await context.cookies()).filter((c) => c.name === 'PHPSESSID')).toEqual([]);
  });

  test('秘密鍵なし: 描画でセッションが始まるので、ヘッダーも追加の GET も出ない', async ({ page }) => {
    await page.goto('/nosecret');
    const gets = countPageGets(page, '/nosecret');
    const answered = page.waitForResponse((r) => r.request().method() === 'POST');
    await own(page, 'a', '.inc').click();
    const response = await answered;
    await expect(count(page, 'a')).toHaveText('1');
    expect(response.headers()['x-rosanna-session-started']).toBeUndefined();
    await own(page, 'b', '.inc').click();
    await expect(count(page, 'b')).toHaveText('1');
    await idle(page);
    await page.waitForTimeout(100);
    expect(gets.total()).toBe(0);
  });

  test('2 回目以降の action でヘッダーは出ない', async ({ page }) => {
    await page.goto('/widget/session');
    const started = waitSessionStarted(page);
    await own(page, 'w', '.inc').click();
    await started;
    await idle(page);
    const second = page.waitForResponse((r) => r.request().method() === 'POST');
    await own(page, 'w', '.inc').click();
    expect((await second).headers()['x-rosanna-session-started']).toBeUndefined();
    await expect(count(page, 'w')).toHaveText('2');
  });
});
