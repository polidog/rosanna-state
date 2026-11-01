// 01 の決定の記録の 8: <set> と <animate> で href を javascript: に変えられるか
import { expect, test } from './support/fixtures';

const pwned = (page: import('@playwright/test').Page) => page.evaluate(() => (window as unknown as { __pwned?: boolean }).__pwned ?? false);

test('対照: 同じクリックで javascript: の href は実際に実行される', async ({ page }) => {
  await page.goto('/svg/control');
  await page.locator('#target').click();
  await expect.poll(() => pwned(page)).toBe(true);
});

test('対照: 無害な値のアニメーションは残り、クリックで移動する', async ({ page }) => {
  await page.goto('/svg/safe');
  await page.locator('#target').click();
  await expect(page).toHaveURL(/\/other$/);
});

const CASES = [
  'set-to', 'set-to-tab', 'set-uppercase-tag', 'set-lowercase-name', 'set-xlink', 'set-over-safe-href', 'set-targets-by-href',
  'animate-values', 'animate-second-value', 'animate-to', 'animate-from', 'animate-by', 'animate-xlink',
];

for (const name of CASES) {
  test(`RT-3.11: ${name} はスクリプトを実行しない`, async ({ page }) => {
    await page.goto(`/svg/${name}`);
    // アニメーションが始まり、animate-second-value の後ろの値に切り替わるまで待つ
    await page.waitForTimeout(1_100);
    const scriptValue = await page.locator('set, animate').evaluateAll((els) =>
      els.flatMap((el) => [...el.attributes]).filter((a) => /script:/i.test(a.value.replace(/\s/g, ''))).map((a) => a.name));
    expect(scriptValue).toEqual([]);
    await page.locator('#target').click();
    await page.waitForTimeout(100);
    expect(await pwned(page).catch(() => false)).toBe(false);
  });
}
