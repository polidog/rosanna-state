// JS-8、JS-8a、JS-10、VR-4、VR-6: バージョン照合
import { count, expect, heard, idle, listen, mirrorVersion, own, test, wrapper } from './support/fixtures';

const navigationType = (page: import('@playwright/test').Page) =>
  page.evaluate(() => (performance.getEntriesByType('navigation')[0] as PerformanceNavigationTiming).type);

async function clickTwice(page: import('@playwright/test').Page) {
  await own(page, 'w', '.inc').click();
  await expect(count(page, 'w')).toHaveText('1');
  await own(page, 'w', '.inc').click();
  await expect(count(page, 'w')).toHaveText('2');
}

test.describe('JS-8 Snapshot', () => {
  test('戻るボタンの後の古いフォームは送られず、restore で最新が描かれる', async ({ page, posts }) => {
    await page.goto('/widget/snapshot');
    await clickTwice(page);
    await page.goto('/other');
    await page.goBack();
    expect(await navigationType(page)).toBe('back_forward');
    await expect(count(page, 'w')).toHaveText('0');
    await listen(page, 'rosanna:stale');

    posts.clear();
    await own(page, 'w', '.inc').click();
    await expect(count(page, 'w')).toHaveText('2');
    const sent = await posts.list();
    expect(sent.map((p) => p.type)).toEqual(['restore']);
    expect(sent[0].sig).not.toBeNull();
    expect(sent[0].payload).toEqual({});
    expect(sent[0].version).toBeUndefined();
    expect(JSON.parse(String(sent[0].fields.get('_rosanna_snapshot'))).memo.version).toBe(2);
    expect(await heard(page, 'rosanna:stale')).toEqual([expect.objectContaining({ origin: 'client' })]);

    // 描き直しの後はふつうに続けられる
    posts.clear();
    await own(page, 'w', '.inc').click();
    await expect(count(page, 'w')).toHaveText('3');
    expect((await posts.list()).map((p) => p.type)).toEqual(['setState']);
  });

  test('JS-8a: 止めたとき、送ろうとしたフォームの欄を保つ', async ({ page }) => {
    await page.goto('/widget/snapshot');
    await clickTwice(page);
    await page.goto('/other');
    await page.goBack();
    await expect(count(page, 'w')).toHaveText('0');
    await own(page, 'w', '.own .name-field').fill('mine');
    await own(page, 'w', '.own .save').click();
    await expect(count(page, 'w')).toHaveText('2');
    await expect(own(page, 'w', '.own .name-field')).toHaveValue('mine');
    await expect(own(page, 'w', '.shown-name')).toHaveText('');

    // 保った値は描き直された新しいフォームで送れる
    await own(page, 'w', '.own .save').click();
    await expect(own(page, 'w', '.shown-name')).toHaveText('mine');
    await expect(count(page, 'w')).toHaveText('2');
  });

  test('別のタブの Snapshot は互いに影響しない', async ({ page, context, posts }) => {
    await page.goto('/widget/snapshot');
    const other = await context.newPage();
    await other.goto('/widget/snapshot');

    await clickTwice(page);
    await own(other, 'w', '.inc').click();
    await expect(count(other, 'w')).toHaveText('1');
    await own(page, 'w', '.inc').click();
    await expect(count(page, 'w')).toHaveText('3');
    await expect(count(other, 'w')).toHaveText('1');
    expect((await posts.list()).every((p) => p.type === 'setState')).toBe(true);
  });

  test('VR-10: 兄弟のインスタンスは止まらない', async ({ page, posts }) => {
    await page.goto('/siblings/snapshot');
    await own(page, 'a', '.inc').click();
    await expect(count(page, 'a')).toHaveText('1');
    await page.goto('/other');
    await page.goBack();
    await expect(count(page, 'a')).toHaveText('0');
    posts.clear();
    await own(page, 'b', '.inc').click();
    await expect(count(page, 'b')).toHaveText('1');
    expect((await posts.list()).map((p) => p.type)).toEqual(['setState']);
    await expect(count(page, 'a')).toHaveText('0');
  });
});

test.describe('VR-6 読み込みの種類', () => {
  test('reload: 写しを消して初期値から始め、最初のクリックが止まらない', async ({ page, posts }) => {
    await page.goto('/widget/snapshot');
    await clickTwice(page);
    expect(await mirrorVersion(page, 'w')).toBe(2);
    await page.reload();
    expect(await navigationType(page)).toBe('reload');
    await expect(count(page, 'w')).toHaveText('0');
    await expect.poll(() => mirrorVersion(page, 'w')).toBe(0);

    posts.clear();
    await own(page, 'w', '.inc').click();
    await expect(count(page, 'w')).toHaveText('1');
    expect((await posts.list()).map((p) => p.type)).toEqual(['setState']);
  });

  test('navigate: リンクなどで開き直しても写しを消す', async ({ page, posts }) => {
    await page.goto('/widget/snapshot');
    await clickTwice(page);
    await page.goto('/other');
    await page.goto('/widget/snapshot');
    expect(await navigationType(page)).toBe('navigate');
    await expect.poll(() => mirrorVersion(page, 'w')).toBe(0);

    posts.clear();
    await own(page, 'w', '.inc').click();
    await expect(count(page, 'w')).toHaveText('1');
    expect((await posts.list()).map((p) => p.type)).toEqual(['setState']);
  });

  test('back_forward: 写しを残し、ページの読み込みだけでは restore を送らない (SN-30)', async ({ page, posts }) => {
    await page.goto('/widget/snapshot');
    await clickTwice(page);
    await page.goto('/other');
    posts.clear();
    await page.goBack();
    await expect(count(page, 'w')).toHaveText('0');
    await idle(page);
    expect(await mirrorVersion(page, 'w')).toBe(2);
    expect(posts.count()).toBe(0);
  });

  test('forward でも back_forward として照合する', async ({ page, posts }) => {
    await page.goto('/other');
    await page.goto('/widget/snapshot');
    await clickTwice(page);
    await page.goBack();
    await page.goForward();
    expect(await navigationType(page)).toBe('back_forward');
    await expect(count(page, 'w')).toHaveText('0');
    posts.clear();
    await own(page, 'w', '.inc').click();
    await expect(count(page, 'w')).toHaveText('2');
    expect((await posts.list()).map((p) => p.type)).toEqual(['restore']);
  });
});

test.describe('JS-10 sessionStorage なし', () => {
  test.beforeEach(async ({ context }) => {
    await context.addInitScript(() => {
      Object.defineProperty(window, 'sessionStorage', {
        get() {
          throw new DOMException('denied', 'SecurityError');
        },
      });
    });
  });

  test('エラーにせず動く', async ({ page }) => {
    await page.goto('/widget/snapshot');
    await clickTwice(page);
    await own(page, 'w', '.own .name-field').fill('name');
    await own(page, 'w', '.own .save').click();
    await expect(own(page, 'w', '.shown-name')).toHaveText('name');
  });

  test('ページ内のメモリで同じ確認をする', async ({ page, posts }) => {
    await page.goto('/widget/snapshot');
    const button = own(page, 'w', '.inc');
    const staleValue = await button.getAttribute('value') as string;
    await clickTwice(page);
    // 本物の署名付きの古い action を置く
    await button.evaluate((el: HTMLButtonElement, value) => {
      el.value = value;
    }, staleValue);
    posts.clear();
    await button.click();
    await idle(page);
    expect((await posts.list()).map((p) => p.type)).toEqual(['restore']);
    await expect(count(page, 'w')).toHaveText('2');
  });

  test('戻る操作の後は確認できないので、古いフォームがそのまま送られる (VR-9)', async ({ page, posts }) => {
    await page.goto('/widget/snapshot');
    await clickTwice(page);
    await page.goto('/other');
    await page.goBack();
    await expect(count(page, 'w')).toHaveText('0');
    posts.clear();
    await own(page, 'w', '.inc').click();
    await expect(count(page, 'w')).toHaveText('1');
    expect((await posts.list()).map((p) => p.type)).toEqual(['setState']);
  });
});

test('JS-10: sessionStorage に書けないときもメモリで確認する', async ({ page, context, posts }) => {
  await context.addInitScript(() => {
    Storage.prototype.setItem = function () {
      throw new DOMException('full', 'QuotaExceededError');
    };
  });
  await page.goto('/widget/snapshot');
  const button = own(page, 'w', '.inc');
  const staleValue = await button.getAttribute('value') as string;
  await clickTwice(page);
  await button.evaluate((el: HTMLButtonElement, value) => {
    el.value = value;
  }, staleValue);
  posts.clear();
  await button.click();
  await idle(page);
  expect((await posts.list()).map((p) => p.type)).toEqual(['restore']);
});

test.describe('VR-4 Session の競合', () => {
  test('秘密鍵なし: 2 タブで開いてそれぞれ 1 回押すと、2 回目は適用されず、描き直された値が 1 のまま', async ({ page, context, posts }) => {
    await page.goto('/nosecret');
    const other = await context.newPage();
    await other.goto('/nosecret');

    await own(page, 'a', '.inc').click();
    await expect(count(page, 'a')).toHaveText('1');

    await listen(other, 'rosanna:stale');
    posts.clear();
    await own(other, 'a', '.inc').click();
    await expect(count(other, 'a')).toHaveText('1');
    expect(posts.count()).toBe(1);
    expect(await heard(other, 'rosanna:stale')).toEqual([expect.objectContaining({ origin: 'server', status: 200 })]);

    await page.reload();
    await expect(count(page, 'a')).toHaveText('1');
  });

  // タブ B のページはセッションができる前に描画されてトークンがない (ST-2、CS-5)。トークンなしを通さないのは CS-3 のとおりで
  // 403 になるが、適用されていない古い action として更新で回復する (JS-26)
  test('秘密鍵あり: Cookie のない 2 タブで始めると、2 つ目のタブは更新で最新になり、rosanna:stale が出る', async ({ page, context, posts }) => {
    await page.goto('/widget/session');
    const other = await context.newPage();
    await other.goto('/widget/session');

    await own(page, 'w', '.inc').click();
    await expect(count(page, 'w')).toHaveText('1');

    await listen(other, 'rosanna:stale');
    await listen(other, 'rosanna:submit-error');
    posts.clear();
    await own(other, 'w', '.inc').click();
    await expect(count(other, 'w')).toHaveText('1');
    await expect.poll(() => heard(other, 'rosanna:stale')).toEqual([expect.objectContaining({ origin: 'server', status: 403 })]);
    expect(await heard(other, 'rosanna:submit-error')).toEqual([]);
    await expect(wrapper(other, 'w')).not.toHaveAttribute('data-rosanna-error');
    expect(posts.count()).toBe(1);

    await own(other, 'w', '.inc').click();
    await expect(count(other, 'w')).toHaveText('2');
    await page.reload();
    await expect(count(page, 'w')).toHaveText('2');
  });

  test('2 タブでそれぞれ 1 回押すと、2 回目は適用されず、描き直された値が 1 のまま', async ({ page, context, posts }) => {
    await page.goto('/widget/session');
    // 先に 1 回書いてセッションを 2 つのタブで共有する
    await own(page, 'w', '.inc').click();
    await expect(count(page, 'w')).toHaveText('1');
    await page.reload();
    const other = await context.newPage();
    await other.goto('/widget/session');
    await expect(count(other, 'w')).toHaveText('1');

    await own(page, 'w', '.inc').click();
    await expect(count(page, 'w')).toHaveText('2');

    await listen(other, 'rosanna:stale');
    await listen(other, 'rosanna:submit-error');
    posts.clear();
    await own(other, 'w', '.inc').click();
    await expect(count(other, 'w')).toHaveText('2');
    expect(posts.count()).toBe(1);
    expect(await heard(other, 'rosanna:stale')).toEqual([expect.objectContaining({ origin: 'server', status: 200 })]);
    expect(await heard(other, 'rosanna:submit-error')).toEqual([]);
    await expect(wrapper(other, 'w')).not.toHaveAttribute('data-rosanna-error');

    await page.reload();
    await expect(count(page, 'w')).toHaveText('2');

    // 描き直しの後は同じタブで続けられる
    await own(other, 'w', '.inc').click();
    await expect(count(other, 'w')).toHaveText('3');
  });

  test('JS-8a: 古い action の描き直しでも入力が消えない。適用もされない', async ({ page, context }) => {
    await page.goto('/widget/session');
    await own(page, 'w', '.inc').click();
    await expect(count(page, 'w')).toHaveText('1');
    const other = await context.newPage();
    await other.goto('/widget/session');
    await own(page, 'w', '.inc').click();
    await expect(count(page, 'w')).toHaveText('2');

    await own(other, 'w', '.own .name-field').fill('draft');
    await own(other, 'w', '.own .save').click();
    await expect(count(other, 'w')).toHaveText('2');
    await expect(own(other, 'w', '.own .name-field')).toHaveValue('draft');
    await expect(own(other, 'w', '.shown-name')).toHaveText('');

    // 保った入力をもう一度送ると適用される
    await own(other, 'w', '.own .save').click();
    await expect(own(other, 'w', '.shown-name')).toHaveText('draft');
  });

  test('JS-8a: rosanna:stale をキャンセルすると欄を保たず JS-7 の順に従う。描き直しは止まらない', async ({ page, context }) => {
    await page.goto('/widget/session');
    await own(page, 'w', '.inc').click();
    await expect(count(page, 'w')).toHaveText('1');
    const other = await context.newPage();
    await other.goto('/widget/session');
    await own(page, 'w', '.inc').click();
    await expect(count(page, 'w')).toHaveText('2');

    await other.evaluate(() => document.addEventListener('rosanna:stale', (e) => e.preventDefault()));
    await own(other, 'w', '.own .name-field').fill('draft');
    await own(other, 'w', '.own .save').click();
    await expect(count(other, 'w')).toHaveText('2');
    await expect(own(other, 'w', '.own .name-field')).toHaveValue('');
  });

  test('#80: 古い一覧の更新が新しい一覧を巻き戻さない', async ({ page, context }) => {
    await page.goto('/list/session');
    const on = (p: typeof page, id: number) => p.getByRole('button', { name: `${id}:on`, exact: true });
    const off = (p: typeof page, id: number) => p.getByRole('button', { name: `${id}:off`, exact: true });
    await off(page, 1).click();
    await expect(on(page, 1)).toHaveCount(1);
    const other = await context.newPage();
    await other.goto('/list/session');
    await off(page, 2).click();
    await expect(on(page, 2)).toHaveCount(1);

    await off(other, 3).click();
    await expect(on(other, 2)).toHaveCount(1);
    await expect(on(other, 1)).toHaveCount(1);
    await expect(off(other, 3)).toHaveCount(1);

    await off(other, 3).click();
    await expect(on(other, 3)).toHaveCount(1);
    await page.reload();
    for (const id of [1, 2, 3]) await expect(on(page, id)).toHaveCount(1);
  });
});
