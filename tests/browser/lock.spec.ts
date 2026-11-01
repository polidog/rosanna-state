// JS-6 送信中のロック
import { AFTER_DEBOUNCE_MS, count, expect, holdPosts, idle, own, test, wrapper } from './support/fixtures';

for (const storage of ['session', 'snapshot'] as const) {
  test(`JS-6 ${storage}: ダブルクリックで 1 回しか送らず、完了後のロックは外れる`, async ({ page, posts }) => {
    await page.goto(`/widget/${storage}`);
    const hold = await holdPosts(page);
    await own(page, 'w', '.inc').dblclick();
    await hold.held();
    await expect(wrapper(page, 'w')).toHaveAttribute('aria-busy', 'true');
    hold.release();
    await expect(count(page, 'w')).toHaveText('1');
    await idle(page);
    expect(posts.count()).toBe(1);

    await own(page, 'w', '.inc').click();
    await expect(count(page, 'w')).toHaveText('2');
  });

  test(`JS-6 ${storage}: 共有フォームの別のボタンは連打として扱わず、送信中なら捨て、完了後は送れる`, async ({ page, posts }) => {
    await page.goto(`/widget/${storage}`);
    const hold = await holdPosts(page);
    await own(page, 'w', '.inc').click();
    await hold.held();
    await own(page, 'w', '.add10').click();
    hold.release();
    await expect(count(page, 'w')).toHaveText('1');
    await idle(page);
    expect(posts.count()).toBe(1);

    await own(page, 'w', '.add10').click();
    await expect(count(page, 'w')).toHaveText('11');
    expect((await posts.list()).map((p) => p.payload.value)).toEqual([1, 11]);
  });

  test(`JS-6 ${storage}: 親と子は同時に送らない`, async ({ page, posts }) => {
    await page.goto(`/nest/${storage}`);
    const hold = await holdPosts(page);
    await own(page, 'parent', '.inc').click();
    await hold.held();
    await own(page, 'child', '.inc').click();
    await idle(page).catch(() => undefined);
    expect(posts.count()).toBe(1);
    hold.release();
    await expect(count(page, 'parent')).toHaveText('1');
    await idle(page);
    expect(posts.count()).toBe(1);
    await expect(count(page, 'child')).toHaveText('0');
  });

  test(`JS-6 ${storage}: 子の送信中は親も送らない`, async ({ page, posts }) => {
    await page.goto(`/nest/${storage}`);
    const hold = await holdPosts(page);
    await own(page, 'child', '.inc').click();
    await hold.held();
    await own(page, 'parent', '.inc').click();
    hold.release();
    await expect(count(page, 'child')).toHaveText('1');
    await idle(page);
    expect(posts.count()).toBe(1);
    await expect(count(page, 'parent')).toHaveText('0');
  });

  test(`JS-6 ${storage}: 兄弟は同時に送る`, async ({ page, posts }) => {
    await page.goto(`/siblings/${storage}`);
    if (storage === 'session') {
      // トークンのないフォームの Session の action は同時に送らない (JS-25)
      await own(page, 'a', '.add10').click();
      await expect(count(page, 'a')).toHaveText('10');
      await page.reload();
      await expect(count(page, 'a')).toHaveText('10');
      posts.clear();
    }
    const hold = await holdPosts(page);
    await own(page, 'a', '.inc').click();
    await own(page, 'b', '.inc').click();
    await hold.held(2);
    expect(posts.count()).toBe(2);
    hold.release();
    await expect(count(page, 'a')).toHaveText(storage === 'session' ? '11' : '1');
    await expect(count(page, 'b')).toHaveText('1');
  });
}

test('JS-6: 送信中の Enter は捨てられ、フォーカスは残る', async ({ page, posts }) => {
  await page.goto('/widget/session');
  const field = own(page, 'w', '.own .name-field');
  await field.fill('first');
  const hold = await holdPosts(page);
  await field.press('Enter');
  await hold.held();
  await field.press('Enter');
  await page.waitForTimeout(50);
  expect(posts.count()).toBe(1);
  hold.release();
  await expect(own(page, 'w', '.shown-name')).toHaveText('first');
  await idle(page);
  expect(posts.count()).toBe(1);
  await expect(field).toBeFocused();
});

test('JS-6: disabled をロックに使わないので、送信中もボタンはフォーカスを持ったまま', async ({ page }) => {
  await page.goto('/widget/session');
  const hold = await holdPosts(page);
  const button = own(page, 'w', '.inc');
  await button.focus();
  await button.press('Enter');
  await hold.held();
  await expect(button).toBeEnabled();
  await expect(button).toBeFocused();
  hold.release();
  await expect(count(page, 'w')).toHaveText('1');
  await expect(button).toBeFocused();
  await button.press('Enter');
  await expect(count(page, 'w')).toHaveText('2');
});

test('JS-6: 失敗でもロックが外れ、次の操作が動く', async ({ page, posts }) => {
  await page.goto('/widget/session');
  let fail = true;
  await page.route('**/widget/session', (route) => {
    if (route.request().method() === 'POST' && fail) {
      fail = false;
      return route.abort();
    }
    return route.fallback();
  });
  await own(page, 'w', '.inc').click();
  await expect(wrapper(page, 'w')).toHaveAttribute('data-rosanna-error', '');
  await expect(page.locator('[aria-busy]')).toHaveCount(0);
  await own(page, 'w', '.inc').click();
  await expect(count(page, 'w')).toHaveText('1');
  expect(posts.count()).toBe(2);
  await page.waitForTimeout(AFTER_DEBOUNCE_MS);
  expect(posts.count()).toBe(2);
});
