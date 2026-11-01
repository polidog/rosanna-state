// #87 共有フォームと差分 (RT-4.7〜RT-4.9、AC-13〜AC-18) の実際の送信
import { expect, test } from './support/fixtures';

const on = (page: import('@playwright/test').Page, id: number) => page.getByRole('button', { name: `${id}:on`, exact: true });
const off = (page: import('@playwright/test').Page, id: number) => page.getByRole('button', { name: `${id}:off`, exact: true });

for (const storage of ['session', 'snapshot'] as const) {
  test.describe(`${storage}: JavaScript あり`, () => {
    test('行のボタンが 1 つの共有フォームを指し、差分で正しい行が変わる', async ({ page, posts }) => {
      await page.goto(`/list/${storage}`);
      const forms = await page.locator('button.toggle').evaluateAll((els) => [...new Set(els.map((el) => el.getAttribute('form')))]);
      expect(forms.length).toBe(1);

      await off(page, 5).click();
      await expect(on(page, 5)).toHaveCount(1);
      await off(page, 9).click();
      await expect(on(page, 9)).toHaveCount(1);
      await on(page, 5).click();
      await expect(off(page, 5)).toHaveCount(1);
      await expect(on(page, 9)).toHaveCount(1);
      await expect(page.locator('button.toggle', { hasText: ':on' })).toHaveCount(1);

      const sent = await posts.list();
      // 全体の値ではなく差分が運ばれる
      for (const p of sent) {
        expect(p.type).toBe('setState');
        expect(p.payload).toHaveProperty('patch');
        expect(p.payload).not.toHaveProperty('value');
        expect(JSON.stringify(p.payload).length).toBeLessThan(200);
      }
    });

    test('削除の後の添字のずれた行でも正しい行に当たる', async ({ page }) => {
      await page.goto(`/list/${storage}`);
      await page.getByRole('button', { name: 'remove 1', exact: true }).click();
      await expect(off(page, 1)).toHaveCount(0);
      await off(page, 2).click();
      await expect(on(page, 2)).toHaveCount(1);
      await expect(page.locator('button.toggle')).toHaveCount(11);
    });
  });

  test.describe(`${storage}: JavaScript なし`, () => {
    test.use({ javaScriptEnabled: false });

    test('行のボタンが通常の POST で届き、続けて押せる', async ({ page, posts }) => {
      await page.goto(`/list/${storage}`);
      await off(page, 3).click();
      await expect(on(page, 3)).toHaveCount(1);
      await off(page, 7).click();
      await expect(on(page, 7)).toHaveCount(1);
      await expect(on(page, 3)).toHaveCount(1);
      await on(page, 3).click();
      await expect(off(page, 3)).toHaveCount(1);
      await expect(on(page, 7)).toHaveCount(1);

      const sent = await posts.list();
      expect(sent.length).toBe(3);
      expect(sent.every((p) => !p.partial && 'patch' in p.payload)).toBe(true);
    });

    test('固定値のボタンと onSubmit のフォームが届く', async ({ page }) => {
      await page.goto(`/widget/${storage}`);
      await page.locator('.add10').click();
      await expect(page.locator('.n')).toHaveText('10');
      await page.locator('.inc').click();
      await expect(page.locator('.n')).toHaveText('11');
      await page.locator('.name-field').fill('nojs');
      await page.locator('.save').click();
      await expect(page.locator('.shown-name')).toHaveText('nojs');
      await expect(page.locator('.n')).toHaveText('11');
    });
  });
}

test.describe('Session の照合: JavaScript なし', () => {
  test.use({ javaScriptEnabled: false });

  test('VR-4: 古いタブのボタンは適用されず、303 で最新を描く', async ({ page, context }) => {
    await page.goto('/list/session');
    await off(page, 1).click();
    await expect(on(page, 1)).toHaveCount(1);
    const other = await context.newPage();
    await other.goto('/list/session');
    await off(page, 2).click();
    await expect(on(page, 2)).toHaveCount(1);

    await off(other, 3).click();
    await expect(on(other, 2)).toHaveCount(1);
    await expect(off(other, 3)).toHaveCount(1);
  });
});
