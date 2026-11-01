// JS-6 data-rosanna-with-value は完了後に最新の値で 1 回送り直す
import { AFTER_DEBOUNCE_MS, count, expect, holdPosts, idle, own, test } from './support/fixtures';

for (const storage of ['session', 'snapshot'] as const) {
  test(`JS-6 ${storage}: 入力の欄は最後の値で 1 回送り直し、フォーカスが残る`, async ({ page, posts }) => {
    await page.goto(`/widget/${storage}`);
    const live = own(page, 'w', '.live');
    const hold = await holdPosts(page);

    await live.pressSequentially('a');
    await hold.held();
    await live.pressSequentially('b');
    await page.waitForTimeout(AFTER_DEBOUNCE_MS);
    await live.pressSequentially('c');
    await page.waitForTimeout(AFTER_DEBOUNCE_MS);
    expect(posts.count()).toBe(1);

    hold.release();
    await expect(own(page, 'w', '.shown-live')).toHaveText('abc');
    await idle(page);
    const sent = await posts.list();
    expect(sent.map((p) => p.fields.get('live'))).toEqual(['a', 'abc']);
    await expect(live).toBeFocused();
    await expect(live).toHaveValue('abc');
  });

  test(`JS-6 ${storage}: 同じ値のフォームの再送信は 1 回`, async ({ page, posts }) => {
    await page.goto(`/widget/${storage}`);
    const field = own(page, 'w', '.own .name-field');
    await field.fill('same');
    const hold = await holdPosts(page);
    await own(page, 'w', '.own .save').click();
    await hold.held();
    await own(page, 'w', '.own .save').click();
    hold.release();
    await expect(own(page, 'w', '.shown-name')).toHaveText('same');
    await idle(page);
    expect(posts.count()).toBe(1);
  });

  test(`JS-6 ${storage}: 途中で編集したフォームは新しい値を送る`, async ({ page, posts }) => {
    await page.goto(`/widget/${storage}`);
    const field = own(page, 'w', '.own .name-field');
    await field.fill('one');
    const hold = await holdPosts(page);
    await own(page, 'w', '.own .save').click();
    await hold.held();
    await field.fill('two');
    await own(page, 'w', '.own .save').click();
    hold.release();
    await expect(own(page, 'w', '.shown-name')).toHaveText('two');
    await idle(page);
    expect((await posts.list()).map((p) => p.fields.get('name'))).toEqual(['one', 'two']);
  });
}

test('JS-6: 子の値は親を待ち、親の応答の後のスナップショットで送る', async ({ page, posts }) => {
  await page.goto('/nest/snapshot');
  const hold = await holdPosts(page);
  await own(page, 'parent', '.inc').click();
  await hold.held();
  await own(page, 'child', '.live').pressSequentially('x');
  await page.waitForTimeout(AFTER_DEBOUNCE_MS);
  expect(posts.count()).toBe(1);
  hold.release();
  await expect(own(page, 'child', '.shown-live')).toHaveText('x');
  await expect(count(page, 'parent')).toHaveText('1');
  await idle(page);
  const sent = await posts.list();
  expect(sent.length).toBe(2);
  expect(sent[1].fields.get('live')).toBe('x');
});

test('JS-6: 親の値は子を待ち、子の応答を反映したスナップショットを運ぶ', async ({ page, posts }) => {
  await page.goto('/nest/snapshot');
  const hold = await holdPosts(page);
  await own(page, 'child', '.inc').click();
  await hold.held();
  await own(page, 'parent', '.live').pressSequentially('y');
  await page.waitForTimeout(AFTER_DEBOUNCE_MS);
  expect(posts.count()).toBe(1);
  hold.release();
  await expect(own(page, 'parent', '.shown-live')).toHaveText('y');
  await expect(count(page, 'child')).toHaveText('1');
  await idle(page);
  const sent = await posts.list();
  expect(sent.length).toBe(2);
  const carried = sent[1].fields.getAll('_rosanna_snapshots[]').map((v) => JSON.parse(String(v)));
  expect(carried[0].state[0]).toBe(1);
});

test('JS-4、JS-6: redirect で答えるコンポーネントでも、待っている値が届いてから移動する', async ({ page, posts }) => {
  await page.goto('/behavior/session');
  // 先にセッションを作ってトークンのあるページにする (最初の action の redirect の後は借りるトークンがない)
  await own(page, 'w', '.add10').click();
  await expect(count(page, 'w')).toHaveText('10');
  posts.clear();
  const hold = await holdPosts(page);
  await own(page, 'w', '.inc').click();
  await hold.held();
  await own(page, 'w', '.live').pressSequentially('z');
  await page.waitForTimeout(AFTER_DEBOUNCE_MS);
  expect(posts.count()).toBe(1);
  hold.release();
  await expect(own(page, 'w', '.shown-live')).toHaveText('z');
  expect((await posts.list()).map((p) => p.type)).toEqual(['setState', 'setFields']);
});
