// RT-4.1、JS-1 の data-rosanna-on-<event>
import { expect, idle, test, AFTER_DEBOUNCE_MS } from './support/fixtures';

test.beforeEach(async ({ page }) => {
  await page.goto('/events');
});

for (const [name, selector] of [['button', '#by-button'], ['span', '#by-span'], ['link', '#by-link']] as const) {
  test(`JS-1: ${name} の onClick が届き、ページは移動しない`, async ({ page }) => {
    await page.locator(selector).click();
    await expect(page.locator('#clicks')).toHaveText('1');
    await expect(page).toHaveURL(/\/events$/);
  });
}

test('行の中のボタンは自分の action だけを送る。行の中のリンクは行を動かさない', async ({ page, posts }) => {
  await page.locator('#in-row').click();
  await expect(page.locator('#clicks')).toHaveText('1');
  await idle(page);
  await expect(page.locator('#row-hits')).toHaveText('0');
  expect(posts.count()).toBe(1);

  await page.locator('#plain-in-row').click();
  await idle(page);
  expect(posts.count()).toBe(1);
  await page.locator('#clickable-row').click({ position: { x: 300, y: 2 } });
  await expect(page.locator('#row-hits')).toHaveText('1');
});

test('select の onChange が値を届け、表示が揃う', async ({ page }) => {
  await page.locator('#pick').selectOption('blue');
  await expect(page.locator('#color')).toHaveText('blue');
  await expect(page.locator('#pick')).toHaveValue('blue');
});

test('checkbox の onChange は、チェックで値を届け、外すと空にする', async ({ page }) => {
  await page.locator('#check').check();
  await expect(page.locator('#agree')).toHaveText('yes');
  await expect(page.locator('#check')).toBeChecked();
  await page.locator('#check').uncheck();
  await expect(page.locator('#agree')).toHaveText('empty');
  await expect(page.locator('#check')).not.toBeChecked();
});

test('テキストの onChange は blur で届き、Tab で移ったフォーカスが残る', async ({ page }) => {
  await page.locator('#text').fill('title');
  await page.locator('#text').press('Tab');
  await expect(page.locator('#title')).toHaveText('title');
  await expect(page.locator('#next-field')).toBeFocused();
});

test('onSubmit のフォームが全部の欄を届ける', async ({ page }) => {
  await page.locator('#first').fill('one');
  await page.locator('#second').fill('two');
  await page.locator('#multi-send').click();
  await expect(page.locator('#fields')).toHaveText('one|two');
  await expect(page).toHaveURL(/\/events$/);
});

test('onInput は入力の切れ目を待って 1 回送り、フォーカスとキャレットが残る', async ({ page, posts }) => {
  const typed = page.locator('#typed');
  await typed.pressSequentially('hello', { delay: 10 });
  await page.waitForTimeout(100);
  expect(posts.count()).toBe(0);
  await expect(page.locator('#title')).toHaveText('hello');
  expect(posts.count()).toBe(1);
  await expect(typed).toBeFocused();
  expect(await typed.evaluate((el: HTMLInputElement) => el.selectionStart)).toBe(5);
});

test('IME の変換中は送らず、確定の後に最後の文字列を送る', async ({ page, posts, context }) => {
  const typed = page.locator('#typed');
  await typed.focus();
  const cdp = await context.newCDPSession(page);
  await cdp.send('Input.imeSetComposition', { text: 'に', selectionStart: 1, selectionEnd: 1 });
  await cdp.send('Input.imeSetComposition', { text: 'にほ', selectionStart: 2, selectionEnd: 2 });
  await page.waitForTimeout(AFTER_DEBOUNCE_MS);
  expect(posts.count()).toBe(0);
  await cdp.send('Input.insertText', { text: '日本' });
  await expect(page.locator('#title')).toHaveText('日本');
  await idle(page);
  expect((await posts.list()).map((p) => p.fields.get('typed'))).toEqual(['日本']);
});
