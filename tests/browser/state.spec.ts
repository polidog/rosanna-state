// JS-1、JS-2、JS-3、SN-12: 部分更新とスナップショットの運搬
import { count, expect, heard, idle, listen, own, snapshotVersion, test, wrapper } from './support/fixtures';

for (const storage of ['session', 'snapshot'] as const) {
  test.describe(`${storage}`, () => {
    test('JS-1: 共有フォームのボタンごとに正しい action が送られ、ページは移動しない', async ({ page, posts }) => {
      await page.goto(`/widget/${storage}`);
      let navigated = 0;
      page.on('framenavigated', () => navigated++);

      await own(page, 'w', '.add10').click();
      await expect(count(page, 'w')).toHaveText('10');
      await own(page, 'w', '.inc').click();
      await expect(count(page, 'w')).toHaveText('11');
      await own(page, 'w', '.add10').click();
      await expect(count(page, 'w')).toHaveText('21');

      const sent = await posts.list();
      expect(sent.map((p) => p.payload.value)).toEqual([10, 11, 21]);
      expect(sent.map((p) => p.version)).toEqual([0, 1, 2]);
      expect(sent.every((p) => p.partial)).toBe(true);
      expect(navigated).toBe(0);
    });

    test('JS-1: button 以外の要素でも送られる。中のリンクのクリックは行のものではない', async ({ page, posts }) => {
      await page.goto(`/widget/${storage}`);
      await own(page, 'w', '.row .inner').click();
      await idle(page);
      expect(posts.count()).toBe(0);

      await own(page, 'w', '.row').click({ position: { x: 2, y: 2 } });
      await expect(count(page, 'w')).toHaveText('100');
      expect((await posts.list())[0].payload.value).toBe(100);
    });

    test('JS-7: 部分更新の後、button の value と共有フォームが新しいものになる', async ({ page }) => {
      await page.goto(`/widget/${storage}`);
      const button = own(page, 'w', '.inc');
      const beforeValue = await button.getAttribute('value');
      const beforeForm = await button.getAttribute('form');

      await button.click();
      await expect(count(page, 'w')).toHaveText('1');

      expect(await button.getAttribute('value')).not.toBe(beforeValue);
      expect(JSON.parse(JSON.parse(await button.getAttribute('value') as string).action).payload.value).toBe(2);
      const form = await button.getAttribute('form');
      expect(form).not.toBe(beforeForm);
      await expect(page.locator(`form[id="${form}"]`)).toHaveCount(1);
      await expect(page.locator(`form[id="${beforeForm}"]`)).toHaveCount(0);
    });

    test('JS-1: ラッパーが見つからないフォームは通常の送信に落ちる', async ({ page, posts }) => {
      await page.goto(`/widget/${storage}`);
      await wrapper(page, 'w').evaluate((el) => el.setAttribute('data-rosanna', 'FC@gone#w'));
      const button = page.locator('.widget > .inc');
      await Promise.all([page.waitForNavigation(), button.click()]);
      const [sent] = await posts.list();
      expect(sent.partial).toBe(false);
      expect(sent.type).toBe('setState');
    });

    test('親の更新で子の状態が保たれ、ラッパーが入れ子にならない', async ({ page }) => {
      await page.goto(`/nest/${storage}`);
      await own(page, 'child', '.inc').click();
      await expect(count(page, 'child')).toHaveText('1');
      if (storage === 'session') {
        // 最初の action でできたセッションのトークンを親のフォームにも持たせる
        await page.reload();
        await expect(count(page, 'child')).toHaveText('1');
      }
      await own(page, 'parent', '.inc').click();
      await expect(count(page, 'parent')).toHaveText('1');
      await expect(count(page, 'child')).toHaveText('1');
      await own(page, 'child', '.inc').click();
      await expect(count(page, 'child')).toHaveText('2');
      await expect(page.locator('[data-rosanna$="#child"] [data-rosanna$="#child"]')).toHaveCount(0);
      await expect(wrapper(page, 'child')).toHaveCount(1);
    });

    test('RT-4.4、JS-2: アプリの form の中のコンポーネントは外のフォームで動き、新しいボタンは新しいフォームを指す', async ({ page, posts }) => {
      await page.goto(`/markup/${storage}`);
      const button = wrapper(page, 'in-form').locator('.inc');
      const n = wrapper(page, 'in-form').locator('.n');
      const form = await button.getAttribute('form');
      await expect(page.locator(`[data-rosanna$="#in-form"] form[id="${form}"]`)).toHaveCount(0);

      await page.locator('#host-field').fill('typed');
      await button.click();
      await expect(n).toHaveText('1');
      // 2 回目はフォームが DOM の上で host の form の中にある。Chromium はその submit を document まで泡立てない
      await button.click();
      await expect(n).toHaveText('2');
      await button.click();
      await expect(n).toHaveText('3');
      expect((await posts.list()).every((p) => p.partial)).toBe(true);
      await expect(page).toHaveURL(new RegExp(`/markup/${storage}$`));
      await expect(page.locator('#host-field')).toHaveValue('typed');

      await page.locator('#host-submit').click();
      await expect(page).toHaveURL(/\/other\?q=typed/);
    });

    test('RT-4.5: p と table の中のボタンで構造が崩れず、どちらも動く', async ({ page }) => {
      await page.goto(`/markup/${storage}`);
      await expect(page.locator('form form')).toHaveCount(0);
      await expect(page.locator('#body > #row')).toHaveCount(1);
      await expect(page.locator('#para > #in-para')).toHaveCount(1);
      const n = wrapper(page, 'cells').locator('.n');
      await page.locator('#in-para').click();
      await expect(n).toHaveText('1');
      await page.locator('#in-cell').click();
      await expect(n).toHaveText('2');
    });
  });
}

test.describe('Cookie のない訪問者の最初の action の後', () => {
  test('秘密鍵なし: 描画でセッションが始まるので、兄弟のフォームも通る', async ({ page }) => {
    await page.goto('/nosecret');
    await own(page, 'a', '.inc').click();
    await expect(count(page, 'a')).toHaveText('1');
    await own(page, 'b', '.inc').click();
    await expect(count(page, 'b')).toHaveText('1');
  });

  // 秘密鍵があると描画でセッションが始まらず (ST-2)、ページのフォームはトークンなしで sessionId が空の署名を持つ。
  // 最初の action の応答の後でページを更新するので、描き直されなかったフォームも CS-3 と SG-1 を通る (JS-21)
  test('秘密鍵あり: 兄弟のフォームもページの更新で新しくなり、再読み込みなしで通る', async ({ page, posts }) => {
    await page.goto('/siblings/session');
    await listen(page, 'rosanna:submit-error');
    await own(page, 'a', '.inc').click();
    await expect(count(page, 'a')).toHaveText('1');
    await expect(wrapper(page, 'b').locator('input[name="_rosanna_csrf"]').first()).toHaveValue(/.+/);
    await own(page, 'b', '.inc').click();
    await expect(count(page, 'b')).toHaveText('1');
    await expect(wrapper(page, 'b')).not.toHaveAttribute('data-rosanna-error');
    expect(await heard(page, 'rosanna:submit-error')).toEqual([]);
    expect(posts.count()).toBe(2);

    await page.reload();
    await expect(count(page, 'a')).toHaveText('1');
    await expect(count(page, 'b')).toHaveText('1');
  });
});

test('SN-12: 更新の hidden がラッパーに写り、DOM に残らない', async ({ page }) => {
  await page.goto('/widget/snapshot');
  await own(page, 'w', '.inc').click();
  await expect(count(page, 'w')).toHaveText('1');
  await own(page, 'w', '.inc').click();
  await expect(count(page, 'w')).toHaveText('2');
  expect(await snapshotVersion(page, 'w')).toBe(2);
  await expect(page.locator('[data-rosanna-snapshot-update]')).toHaveCount(0);
});

test('JS-3: 親の送信は子孫のスナップショットを運ぶ', async ({ page, posts }) => {
  await page.goto('/nest/snapshot');
  await own(page, 'child', '.inc').click();
  await expect(count(page, 'child')).toHaveText('1');
  posts.clear();
  await own(page, 'parent', '.inc').click();
  await expect(count(page, 'parent')).toHaveText('1');
  const [sent] = await posts.list();
  const carried = sent.fields.getAll('_rosanna_snapshots[]').map((v) => JSON.parse(String(v)));
  expect(carried.length).toBe(1);
  expect(carried[0].memo.version).toBe(1);
  expect(carried[0].state[0]).toBe(1);
});

test('Session の更新はリロードの後も残る。Snapshot は初期値に戻る (VR-6 の対)', async ({ page }) => {
  await page.goto('/widget/session');
  await own(page, 'w', '.inc').click();
  await expect(count(page, 'w')).toHaveText('1');
  await page.reload();
  await expect(count(page, 'w')).toHaveText('1');

  await page.goto('/widget/snapshot');
  await own(page, 'w', '.inc').click();
  await expect(count(page, 'w')).toHaveText('1');
  await page.reload();
  await expect(count(page, 'w')).toHaveText('0');
});
