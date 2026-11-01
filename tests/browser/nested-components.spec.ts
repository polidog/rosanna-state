// RT-4.10、RT-5.3、RT-8.6、SN-32: 入れ子のコンポーネントの部分更新。JavaScript なしと同じ結果になること
import { expect, idle, test } from './support/fixtures';

test('RT-5.3: 未登録のクラスの Snapshot はセッションを使わず、値が増える', async ({ page, context }) => {
  await page.goto('/arg-class');
  await page.locator('#snapshot button').click();
  await expect(page.locator('#snapshot .n')).toHaveText('snapshot=1');
  await page.locator('#snapshot button').click();
  await expect(page.locator('#snapshot .n')).toHaveText('snapshot=2');
  await idle(page);
  expect((await context.cookies()).map((cookie) => cookie.name)).not.toContain('PHPSESSID');
});

test('RT-5.3: 未登録のクラスの Memory はセッションに書かない', async ({ page, context }) => {
  await page.goto('/arg-class');
  await page.locator('#memory button').click();
  await expect(page.locator('#memory .n')).toHaveText('memory=1');
  await idle(page);
  expect((await context.cookies()).map((cookie) => cookie.name)).not.toContain('PHPSESSID');
});

test.describe('JavaScript なし', () => {
  test.use({ javaScriptEnabled: false });

  test('RT-5.3: 未登録のクラスの Snapshot の値が増える', async ({ page, context }) => {
    await page.goto('/arg-class');
    await page.locator('#snapshot button').click();
    await expect(page.locator('#snapshot .n')).toHaveText('snapshot=1');
    await page.locator('#snapshot button').click();
    await expect(page.locator('#snapshot .n')).toHaveText('snapshot=2');
    expect((await context.cookies()).map((cookie) => cookie.name)).not.toContain('PHPSESSID');
  });

  test('SN-32: 親の状態で現れる子を操作しても親の状態を保つ', async ({ page }) => {
    await page.goto('/snapshot-ancestor');
    await page.locator('#to-b').click();
    await page.locator('#panel-inc').click();
    await expect(page.locator('#tab')).toHaveText('tab=b');
    await expect(page.locator('#panel')).toHaveText('panel=1 tab=b');
  });
});

test('RT-8.6: 親の更新の後も key のない子は自分の状態を持つ', async ({ page }) => {
  await page.goto('/class-children');
  const ids = await page.locator('[data-rosanna]').evaluateAll((els) => els.map((el) => el.getAttribute('data-rosanna')));

  await page.locator('#two .child-inc').click();
  await expect(page.locator('#two .child-n')).toHaveText('1');
  await expect(page.locator('#one .child-n')).toHaveText('0');

  await page.locator('#two .parent-inc').click();
  await expect(page.locator('#two .parent-n')).toHaveText('1');
  await expect(page.locator('#two .child-n')).toHaveText('1');

  await page.locator('#one .child-inc').click();
  await expect(page.locator('#one .child-n')).toHaveText('1');
  await expect(page.locator('#two .child-n')).toHaveText('1');

  await idle(page);
  expect(await page.locator('[data-rosanna]').evaluateAll((els) => els.map((el) => el.getAttribute('data-rosanna')))).toEqual(ids);
});

test('SN-32: 子の部分更新の応答が親のスナップショットの値で描かれる', async ({ page, posts }) => {
  await page.goto('/snapshot-ancestor');
  await page.locator('#to-b').click();
  await expect(page.locator('#panel')).toHaveText('panel=0 tab=b');

  await page.locator('#panel-inc').click();
  await expect(page.locator('#panel')).toHaveText('panel=1 tab=b');
  await expect(page.locator('#tab')).toHaveText('tab=b');

  await page.locator('#panel-inc').click();
  await expect(page.locator('#panel')).toHaveText('panel=2 tab=b');
  expect((await posts.list()).every((post) => post.partial)).toBe(true);
});

test('RT-4.10: セルの中のコンポーネントは表を壊さず、行ごとに更新される', async ({ page }) => {
  await page.goto('/table-cell');
  await page.locator('#row-1 button').click();
  await expect(page.locator('#row-1 .n')).toHaveText('1');
  await page.locator('#row-2 button').click();
  await expect(page.locator('#row-2 .n')).toHaveText('1');
  await expect(page.locator('#row-1 .n')).toHaveText('1');
  await expect(page.locator('table tr')).toHaveCount(2);
});
