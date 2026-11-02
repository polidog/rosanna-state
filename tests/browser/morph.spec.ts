// JS-7 応答の差分適用と欄の値の順
import type { Page } from '@playwright/test';
import { expect, holdPosts, idle, test } from './support/fixtures';

const phase = (page: Page, n: number) => expect(page.locator('#phase')).toHaveText(String(n));

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

// ボタンにフォーカスを移さずに押す。フォーカスは入力欄に残す
const press = (page: Page, selector: string) =>
  page.locator(selector).evaluate((el: HTMLButtonElement) => el.click());

const rowIds = (page: Page) => page.locator('#rows > [data-rosanna] > li').evaluateAll((els) => els.map((el) => el.id));

const draft = (page: Page, id: number) => page.locator(`#row-${id} .draft`);

for (const storage of ['session', 'snapshot']) {
  test.describe(`子のコンポーネントの行 (${storage})`, () => {
    test.beforeEach(async ({ page }) => {
      await page.goto(`/morph-rows/${storage}`);
    });

    test('JS-7: 親の並べ替えで、入力中の値とフォーカスが行に付いていく', async ({ page }) => {
      await draft(page, 1).fill('typed-1');
      await draft(page, 2).fill('typed-2');
      await draft(page, 3).fill('typed-3');
      await draft(page, 1).focus();
      await press(page, '#reverse');
      await expect.poll(() => rowIds(page)).toEqual(['row-3', 'row-2', 'row-1']);
      await idle(page);
      await expect(draft(page, 1)).toHaveValue('typed-1');
      await expect(draft(page, 2)).toHaveValue('typed-2');
      await expect(draft(page, 3)).toHaveValue('typed-3');
      await expect(draft(page, 1)).toBeFocused();
    });

    test('JS-7: 親の並べ替えでも、行は同じ要素のままで自分の状態を持つ', async ({ page }) => {
      await page.locator('#row-2 .inc').click();
      await expect(page.locator('#row-2 .n')).toHaveText('1');
      await idle(page);
      await draft(page, 2).evaluate((el) => {
        (window as unknown as { __same: Element }).__same = el;
      });
      await press(page, '#reverse');
      await expect.poll(() => rowIds(page)).toEqual(['row-3', 'row-2', 'row-1']);
      await idle(page);
      expect(await draft(page, 2).evaluate((el) => (window as unknown as { __same: Element }).__same === el)).toBe(true);
      await expect(page.locator('#row-2 .n')).toHaveText('1');

      await page.locator('#row-2 .inc').click();
      await expect(page.locator('#row-2 .n')).toHaveText('2');
      await expect(page.locator('#row-1 .n')).toHaveText('0');
    });

    test('JS-7: 行が消えても、後ろの行の値とフォーカスは残る', async ({ page }) => {
      await draft(page, 1).fill('typed-1');
      await draft(page, 3).fill('typed-3');
      await draft(page, 3).focus();
      await press(page, '#remove-middle');
      await expect.poll(() => rowIds(page)).toEqual(['row-1', 'row-3']);
      await idle(page);
      await expect(draft(page, 1)).toHaveValue('typed-1');
      await expect(draft(page, 3)).toHaveValue('typed-3');
      await expect(draft(page, 3)).toBeFocused();
    });

    test('JS-7: 先頭に行が増えても、値とフォーカスは残る', async ({ page }) => {
      await draft(page, 1).fill('typed-1');
      await draft(page, 2).fill('typed-2');
      await draft(page, 2).focus();
      await press(page, '#prepend');
      await expect.poll(() => rowIds(page)).toEqual(['row-4', 'row-1', 'row-2', 'row-3']);
      await idle(page);
      await expect(draft(page, 1)).toHaveValue('typed-1');
      await expect(draft(page, 2)).toHaveValue('typed-2');
      await expect(draft(page, 4)).toHaveValue('');
      await expect(draft(page, 2)).toBeFocused();
    });
  });
}

// セッションを始めた後の更新 (JS-21) が混ざらないよう、Snapshot で確かめる
test.describe('子のコンポーネントの行の移動', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/morph-rows/snapshot');
  });

  for (const id of [1, 2, 3]) {
    test(`JS-7: 並べ替えの間、フォーカスのある行 ${id} は動かず、変換も切れない`, async ({ page }) => {
      const input = draft(page, id);
      await input.fill('abcdef');
      await input.evaluate((el: HTMLInputElement) => el.setSelectionRange(2, 4));

      const cdp = await page.context().newCDPSession(page);
      await cdp.send('Input.imeSetComposition', { text: 'かな', selectionStart: 2, selectionEnd: 2 });

      await page.evaluate((focusedId) => {
        const log = { moved: 0, blurred: 0, compositionEnded: 0 };
        (window as unknown as { __moves: typeof log }).__moves = log;
        const wrapper = document.getElementById(`row-${focusedId}`)!.parentElement!;
        new MutationObserver((records) => {
          log.moved += records.filter((record) => [...record.addedNodes].includes(wrapper)).length;
        }).observe(document.getElementById('rows')!, { childList: true });
        document.addEventListener('focusout', () => log.blurred++, true);
        document.addEventListener('compositionend', () => log.compositionEnded++, true);
      }, id);

      await press(page, '#reverse');
      await expect.poll(() => rowIds(page)).toEqual(['row-3', 'row-2', 'row-1']);
      await idle(page);

      expect(await page.evaluate(() => (window as unknown as { __moves: unknown }).__moves)).toEqual({ moved: 0, blurred: 0, compositionEnded: 0 });
      await expect(input).toBeFocused();
      await expect(input).toHaveValue('abかなef');

      await cdp.send('Input.insertText', { text: '確定' });
      await expect(input).toHaveValue('ab確定ef');
    });
  }

  test('JS-7: 動いた行でも、送信中の編集とサーバーの新しい値の順は変わらない', async ({ page }) => {
    await draft(page, 1).fill('before');
    const hold = await holdPosts(page);
    await press(page, '#reverse');
    await hold.held();
    await draft(page, 1).fill('during');
    await draft(page, 3).fill('during-3');
    hold.release();
    await expect.poll(() => rowIds(page)).toEqual(['row-3', 'row-2', 'row-1']);
    await idle(page);
    await expect(draft(page, 1)).toHaveValue('during');
    await expect(draft(page, 3)).toHaveValue('during-3');
  });

  test('JS-6: 動いた行でもロックが効き、連打は捨てられる', async ({ page, posts }) => {
    await page.locator('#reverse').click();
    await expect.poll(() => rowIds(page)).toEqual(['row-3', 'row-2', 'row-1']);
    await idle(page);
    posts.clear();

    const hold = await holdPosts(page);
    await page.locator('#row-1 .inc').click();
    await hold.held();
    await expect(page.locator('[data-rosanna$="#row/1"]')).toHaveAttribute('aria-busy', 'true');
    await page.locator('#row-1 .inc').click();
    await page.waitForTimeout(50);
    expect(posts.count()).toBe(1);
    hold.release();
    await expect(page.locator('#row-1 .n')).toHaveText('1');
    await idle(page);
    expect(posts.count()).toBe(1);
  });
});

test.describe('同じインスタンス ID の行', () => {
  const sameDrafts = (page: Page) => page.locator('#rows .same .draft');

  test.beforeEach(async ({ page }) => {
    await page.goto('/morph-rows-same');
    await sameDrafts(page).nth(0).fill('first');
    await sameDrafts(page).nth(1).fill('second');
    await page.locator('#rows .other .draft').fill('other');
  });

  test('RT-6.8: 並べ替えでは出現順に対応し、どちらの行も値を持ち、操作できる', async ({ page }) => {
    await press(page, '#other-first');
    await expect(page.locator('#rows > [data-rosanna] > li').first()).toHaveClass('other');
    await idle(page);
    await expect(sameDrafts(page).nth(0)).toHaveValue('first');
    await expect(sameDrafts(page).nth(1)).toHaveValue('second');
    await expect(page.locator('#rows .other .draft')).toHaveValue('other');

    await page.locator('#rows .same .inc').nth(1).click();
    await expect(page.locator('#rows .same .n').nth(1)).toHaveText('1');
    await expect(sameDrafts(page).nth(1)).toHaveValue('second');
  });

  test('RT-6.8: 行が減ると後ろのものが消え、残る行はそのまま', async ({ page }) => {
    await page.locator('#rows .other .draft').focus();
    await press(page, '#one-same');
    await expect(page.locator('#rows .same')).toHaveCount(1);
    await idle(page);
    await expect(sameDrafts(page).nth(0)).toHaveValue('first');
    await expect(page.locator('#rows .other .draft')).toHaveValue('other');
    await expect(page.locator('#rows .other .draft')).toBeFocused();
  });
});
