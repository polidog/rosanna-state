// JS-4 redirect、JS-5 失敗を再送しない
import { count, expect, heard, idle, listen, own, snapshotVersion, test, wrapper } from './support/fixtures';

test('JS-4: 同一オリジンの redirect に移動する (host の 303)', async ({ page }) => {
  await page.goto('/away/same');
  await own(page, 'w', '.inc').click();
  await expect(page.locator('#other')).toHaveText('other');
  await expect(page).toHaveURL(/\/other$/);
});

test('JS-4: 別オリジンへの redirect に CORS が通らなければ、移動せず失敗として知らせる', async ({ page, baseURL }) => {
  await page.goto('/away/cross');
  const visited: string[] = [];
  page.on('framenavigated', (frame) => visited.push(frame.url()));
  await listen(page, 'rosanna:submit-error');
  await own(page, 'w', '.inc').click();
  await expect(wrapper(page, 'w')).toHaveAttribute('data-rosanna-error', '');
  expect((await heard(page, 'rosanna:submit-error'))[0].error).toContain('TypeError');
  expect(visited).toEqual([]);
  await expect(page).toHaveURL(new RegExp(`^${baseURL}/away/cross$`));
});

test('JS-4: 別オリジンへの redirect に CORS が通っても移動せず、警告してリロードする', async ({ page, baseURL }) => {
  await page.goto('/away/cross-cors');
  const visited: string[] = [];
  page.on('framenavigated', (frame) => visited.push(frame.url()));
  const warned = page.waitForEvent('console', (m) => m.text().includes('ignoring cross-origin redirect'));
  const crossed = page.waitForResponse((r) => r.url().endsWith('/away-target/cors') && r.request().method() === 'GET');
  await page.evaluate(() => {
    (window as unknown as { __before: boolean }).__before = true;
  });
  await own(page, 'w', '.inc').click();
  await crossed;
  await warned;
  // リロードされてページの変数が消える
  await page.waitForFunction(() => !(window as unknown as { __before?: boolean }).__before);
  expect(visited.length).toBeGreaterThan(0);
  expect(visited.every((url) => url.startsWith(baseURL as string))).toBe(true);
  await expect(page.locator('#stolen')).toHaveCount(0);
  await expect(page).toHaveURL(new RegExp(`^${baseURL}/away/cross-cors$`));
});

for (const [behavior, shown] of [['persistent', 1], ['session', 1]] as const) {
  test(`JS-4: Snapshot の ${behavior} はサーバーの 303 に従い、状態が残る`, async ({ page }) => {
    await page.goto(`/behavior/${behavior}`);
    await own(page, 'w', '.inc').click();
    await expect(count(page, 'w')).toHaveText(String(shown));
    await own(page, 'w', '.inc').click();
    await expect(count(page, 'w')).toHaveText(String(shown + 1));
    expect(await snapshotVersion(page, 'w')).toBe(2);
    await expect(page.url()).toMatch(new RegExp(`/behavior/${behavior}`));
  });
}

test.describe('JS-5', () => {
  test('実際の 500 (replay の例外) で再送も form.submit() もせず、イベントと属性で知らせる', async ({ page, context, posts, baseURL }) => {
    await page.goto('/boom');
    let navigated = 0;
    page.on('framenavigated', () => navigated++);
    await listen(page, 'rosanna:submit-error');
    await context.addCookies([{ name: 'fixture_explode', value: '1', url: baseURL as string }]);

    await own(page, 'w', '.inc').click();
    await expect(wrapper(page, 'w')).toHaveAttribute('data-rosanna-error', '');
    await idle(page);
    expect(posts.count()).toBe(1);
    expect(navigated).toBe(0);
    expect(await heard(page, 'rosanna:submit-error')).toEqual([expect.objectContaining({ status: 500 })]);
    await expect(page.locator('[aria-busy]')).toHaveCount(0);
  });

  test('例外 (通信の失敗) でも再送せず、error を渡す', async ({ page, posts }) => {
    await page.goto('/widget/session');
    let navigated = 0;
    page.on('framenavigated', () => navigated++);
    await listen(page, 'rosanna:submit-error');
    await page.route('**/widget/session', (route) => (route.request().method() === 'POST' ? route.abort() : route.fallback()));

    await own(page, 'w', '.inc').click();
    await expect(wrapper(page, 'w')).toHaveAttribute('data-rosanna-error', '');
    await idle(page);
    expect(posts.count()).toBe(1);
    expect(navigated).toBe(0);
    const [event] = await heard(page, 'rosanna:submit-error');
    expect(event.error).toContain('TypeError');
  });

  test('イベントをキャンセルすると属性が付かず、console にも出ない', async ({ page, context, baseURL }) => {
    await page.goto('/boom');
    await listen(page, 'rosanna:submit-error');
    await page.evaluate(() => document.addEventListener('rosanna:submit-error', (e) => e.preventDefault()));
    const warnings: string[] = [];
    page.on('console', (m) => {
      if (m.text().includes('partial submit')) warnings.push(m.text());
    });
    await context.addCookies([{ name: 'fixture_explode', value: '1', url: baseURL as string }]);
    await own(page, 'w', '.inc').click();
    await expect.poll(async () => (await heard(page, 'rosanna:submit-error')).length).toBe(1);
    await idle(page);
    await expect(wrapper(page, 'w')).not.toHaveAttribute('data-rosanna-error');
    expect(warnings).toEqual([]);
  });

  test('属性は次の送信で消える', async ({ page, context, baseURL }) => {
    await page.goto('/boom');
    await context.addCookies([{ name: 'fixture_explode', value: '1', url: baseURL as string }]);
    await own(page, 'w', '.inc').click();
    await expect(wrapper(page, 'w')).toHaveAttribute('data-rosanna-error', '');
    await context.clearCookies({ name: 'fixture_explode' });
    await page.reload();
    await expect(wrapper(page, 'w')).not.toHaveAttribute('data-rosanna-error');

    await context.addCookies([{ name: 'fixture_explode', value: '1', url: baseURL as string }]);
    await own(page, 'w', '.add10').click();
    await expect(wrapper(page, 'w')).toHaveAttribute('data-rosanna-error', '');
    await context.clearCookies({ name: 'fixture_explode' });
    await own(page, 'w', '.inc').click();
    await expect(wrapper(page, 'w')).not.toHaveAttribute('data-rosanna-error');
  });

  test('改ざんで 400 になった action も再送しない', async ({ page, posts }) => {
    await page.goto('/widget/session');
    await listen(page, 'rosanna:submit-error');
    await own(page, 'w', '.inc').evaluate((el: HTMLButtonElement) => {
      el.value = el.value.replace('\\"value\\":1', '\\"value\\":999');
    });
    await own(page, 'w', '.inc').click();
    await expect(wrapper(page, 'w')).toHaveAttribute('data-rosanna-error', '');
    await idle(page);
    expect(posts.count()).toBe(1);
    expect(await heard(page, 'rosanna:submit-error')).toEqual([expect.objectContaining({ status: 400 })]);
    await expect(count(page, 'w')).toHaveText('0');
  });
});
