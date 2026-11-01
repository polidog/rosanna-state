// 完了の条件: GET → 操作 → POST → 部分更新を、最小構成 (examples/01-counter) で 1 本通す
import { resolve } from 'node:path';
import { expect, test as base } from './support/fixtures';
import { startPhpServer, type PhpServer } from './support/php-server';

const test = base.extend<{}, { server: PhpServer }>({
  server: [
    async ({}, use) => {
      const server = await startPhpServer(resolve(__dirname, '../../examples/router.php'), { ROSANNA_SNAPSHOT_SECRET: 'minimal-flow-secret-0123456789' });
      await use(server);
      await server.stop();
    },
    { scope: 'worker' },
  ],
});

test('GET で描画し、クリックの POST の応答で部分更新され、ページは移動しない', async ({ page, posts }) => {
  // GET: 初期値がラッパーの中に描画されている
  const first = await page.goto('/01-counter');
  expect(first?.status()).toBe(200);
  await expect(page.getByText('Count: 0')).toBeVisible();
  await expect(page.locator('[data-rosanna$="#example-counter"]')).toHaveCount(1);
  expect(posts.count()).toBe(0);

  // 全体の読み込みが起きると消える印
  await page.evaluate(() => {
    (window as unknown as { __sameDocument: boolean }).__sameDocument = true;
  });
  let navigated = 0;
  page.on('framenavigated', () => navigated++);

  // 操作 → POST
  const answered = page.waitForResponse((response) => response.request().method() === 'POST');
  await page.getByRole('button', { name: '+1', exact: true }).click();
  const response = await answered;
  expect(response.status()).toBe(200);
  expect(response.url()).toMatch(/\/01-counter$/);

  // 応答は部分更新の断片で、ページ全体ではない
  const fragment = await response.text();
  expect(fragment).toContain('Count: 1');
  expect(fragment).not.toContain('<html');
  expect(fragment).not.toContain('All examples');

  // 部分更新: 値が変わり、同じ文書のまま
  await expect(page.getByText('Count: 1')).toBeVisible();
  expect(navigated).toBe(0);
  expect(await page.evaluate(() => (window as unknown as { __sameDocument?: boolean }).__sameDocument)).toBe(true);

  // 送られたのは署名付きの action 1 件で、部分更新を求めている
  const [sent, ...rest] = await posts.list();
  expect(rest).toEqual([]);
  expect(sent.partial).toBe(true);
  expect(sent.payload.value).toBe(1);
  expect(sent.sig).toEqual(expect.any(String));

  // 差し替わったボタンの次の action は、新しい値から作られている
  await page.getByRole('button', { name: '+1', exact: true }).click();
  await expect(page.getByText('Count: 2')).toBeVisible();
  expect((await posts.list()).map((post) => post.payload.value)).toEqual([1, 2]);
  expect(navigated).toBe(0);

  // サーバーに残っている
  await page.reload();
  await expect(page.getByText('Count: 2')).toBeVisible();
});
