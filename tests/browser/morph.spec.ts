// JS-7 応答の差分適用と欄の値の順
import { expect, holdPosts, idle, test } from './support/fixtures';

const phase = (page: import('@playwright/test').Page, n: number) => expect(page.locator('#phase')).toHaveText(String(n));

test.beforeEach(async ({ page }) => {
  await page.goto('/morph');
});

test('JS-7: 送っていない欄は同じ要素のまま値が残る', async ({ page }) => {
  await page.locator('#kept').fill('mine');
  await page.locator('#kept').evaluate((el) => {
    (window as unknown as { __same: Element }).__same = el;
  });
  await page.locator('#next').click();
  await phase(page, 1);
  await expect(page.locator('#kept')).toHaveValue('mine');
  expect(await page.locator('#kept').evaluate((el) => (window as unknown as { __same: Element }).__same === el)).toBe(true);
});

test('JS-7: フォーカスと選択範囲が残り、Enter がまた効く', async ({ page }) => {
  const kept = page.locator('#kept');
  await kept.fill('abcdef');
  await kept.evaluate((el: HTMLInputElement) => el.setSelectionRange(2, 4));
  const hold = await holdPosts(page);
  await page.locator('#next').evaluate((el: HTMLButtonElement) => el.click());
  await hold.held();
  hold.release();
  await phase(page, 1);
  await expect(kept).toBeFocused();
  expect(await kept.evaluate((el: HTMLInputElement) => [el.selectionStart, el.selectionEnd])).toEqual([2, 4]);

  await page.locator('#next').focus();
  await page.keyboard.press('Enter');
  await phase(page, 2);
  await expect(page.locator('#next')).toBeFocused();
  await page.keyboard.press('Enter');
  await phase(page, 3);
});

test('JS-7: サーバーの新しい値は送っていない編集に勝つ', async ({ page }) => {
  await page.locator('#server').fill('edited');
  await page.locator('#next').click();
  await phase(page, 1);
  await expect(page.locator('#server')).toHaveValue('server-1');
});

test('JS-7: 送信中の編集はサーバーの新しい値に勝つ', async ({ page }) => {
  const hold = await holdPosts(page);
  await page.locator('#next').click();
  await hold.held();
  await page.locator('#server').fill('during');
  hold.release();
  await phase(page, 1);
  await expect(page.locator('#server')).toHaveValue('during');
});

test('JS-7: 送信したフォームの欄はページの POST と同じく描画の値に戻り、他の欄は残る', async ({ page }) => {
  await page.locator('#kept').fill('stays');
  await page.locator('#sent').fill('hello');
  await page.locator('#send').click();
  await expect(page.locator('#last')).toHaveText('hello');
  await expect(page.locator('#sent')).toHaveValue('');
  await expect(page.locator('#kept')).toHaveValue('stays');
});

test('JS-7: 送信中の編集は欄の消去に勝つ', async ({ page }) => {
  await page.locator('#sent').fill('hello');
  const hold = await holdPosts(page);
  await page.locator('#send').click();
  await hold.held();
  await page.locator('#sent').fill('hello again');
  hold.release();
  await expect(page.locator('#last')).toHaveText('hello');
  await idle(page);
  await expect(page.locator('#sent')).toHaveValue('hello again');
});

test('JS-7: checkbox と select も同じ順に従う', async ({ page }) => {
  await page.locator('#unsent-check').check();
  await page.locator('#unsent-select').selectOption('y');
  await page.locator('#next').click();
  await phase(page, 1);
  await expect(page.locator('#unsent-check')).toBeChecked();
  await expect(page.locator('#unsent-select')).toHaveValue('y');
  // サーバーが checked に変えた
  await expect(page.locator('#server-check')).toBeChecked();
});

test('JS-7: id のある行は並べ替えで値が付いていく', async ({ page }) => {
  await page.locator('#row-a input').fill('value-a');
  await page.locator('#row-b input').fill('value-b');
  await page.locator('#row-b input').focus();
  await page.locator('#row-b input').evaluate(() => {
    (document.getElementById('next') as HTMLButtonElement).click();
  });
  await phase(page, 1);
  expect(await page.locator('#ordered > li').evaluateAll((els) => els.map((el) => el.id))).toEqual(['row-b', 'row-a']);
  await expect(page.locator('#row-a input')).toHaveValue('value-a');
  await expect(page.locator('#row-b input')).toHaveValue('value-b');
  await expect(page.locator('#row-b input')).toBeFocused();
});

test('JS-7: 手前に要素が増えても減っても欄はそのまま', async ({ page }) => {
  const after = page.locator('.after-ahead');
  await after.fill('typed');
  await after.evaluate(() => (document.getElementById('next') as HTMLButtonElement).click());
  await phase(page, 1);
  await expect(page.locator('#ahead-marker')).toHaveCount(1);
  await expect(after).toHaveValue('typed');
  await expect(after).toBeFocused();
  await after.evaluate(() => (document.getElementById('next') as HTMLButtonElement).click());
  await phase(page, 2);
  await expect(page.locator('#ahead-marker')).toHaveCount(0);
  await expect(after).toHaveValue('typed');
  await expect(after).toBeFocused();
});

test('JS-7: フォーカスのある要素が消えると、代わりの要素に移る', async ({ page }) => {
  await page.locator('#vanish').focus();
  await page.keyboard.press('Enter');
  await phase(page, 1);
  await expect(page.locator('#vanish')).toHaveCount(0);
  await expect(page.locator('#took-place')).toBeFocused();
  await page.keyboard.press('Enter');
  await phase(page, 2);
});

test('JS-7: 応答の script は動かない', async ({ page }) => {
  await page.locator('#next').click();
  await phase(page, 1);
  // 応答の script は DOM に入る。動かないことに意味がある
  expect(await page.evaluate(() => [...document.scripts].some((s) => s.textContent?.includes('__scriptRan')))).toBe(true);
  expect(await page.evaluate(() => (window as unknown as { __scriptRan?: boolean }).__scriptRan)).toBeUndefined();
});
