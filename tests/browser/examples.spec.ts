// examples/ のサンプルを、README に書いたとおり router.php で動かして確かめる
import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { expect, holdPosts, test as base } from './support/fixtures';
import { startPhpServer, type PhpServer } from './support/php-server';

const ROOT = resolve(__dirname, '../..');

const test = base.extend<{}, { server: PhpServer }>({
  server: [
    async ({}, use) => {
      execFileSync('php', ['bin/rosanna-state', 'compile', 'examples/10-psx/components', '--cache=examples/10-psx/var/cache/psx'], { cwd: ROOT });
      const server = await startPhpServer(resolve(ROOT, 'examples/router.php'), { ROSANNA_SNAPSHOT_SECRET: 'examples-test-secret-0123456789' });
      await use(server);
      await server.stop();
    },
    { scope: 'worker' },
  ],
});

test('一覧からすべてのサンプルに行ける', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('li a')).toHaveCount(12);
  await page.getByRole('link', { name: '01-counter' }).click();
  await expect(page.locator('h1')).toHaveText('Counter');
});

test('00-stateless: 状態を使わないページはセッションを始めず、入力はエスケープされる', async ({ page, context }) => {
  const response = await page.goto('/00-stateless');
  expect(response?.headers()['set-cookie']).toBeUndefined();
  expect(await context.cookies()).toEqual([]);
  expect(await page.content()).not.toContain('data-rosanna=');
  await expect(page.locator('tr')).toHaveCount(3);

  await page.getByRole('searchbox').fill('<b>t');
  await page.getByRole('button', { name: 'Search' }).click();
  await expect(page.getByText('Results for: <b>t')).toBeVisible();
  await expect(page.locator('p b')).toHaveCount(0);
  expect(await context.cookies()).toEqual([]);
});

for (const javaScriptEnabled of [true, false]) {
  test.describe(javaScriptEnabled ? 'JavaScript あり' : 'JavaScript なし', () => {
    test.use({ javaScriptEnabled });

    test('01-counter: 増減とリセットができ、リロードしても残る', async ({ page }) => {
      await page.goto('/01-counter');
      const button = (name: string) => page.getByRole('button', { name, exact: true });
      await button('+1').click();
      await expect(page.getByText('Count: 1')).toBeVisible();
      await button('+1').click();
      await expect(page.getByText('Count: 2')).toBeVisible();
      await button('-1').click();
      await expect(page.getByText('Count: 1')).toBeVisible();
      await page.reload();
      await expect(page.getByText('Count: 1')).toBeVisible();
      await button('Reset').click();
      await expect(page.getByText('Count: 0')).toBeVisible();
    });

    test('02-form: 不正な入力にエラーを出し、直すと通る', async ({ page }) => {
      await page.goto('/02-form');
      await expect(page.locator('.error')).toHaveCount(0);

      await page.getByLabel('Email').fill('ann@example.com');
      await page.getByRole('button', { name: 'Sign up' }).click();
      await expect(page.getByText('Enter your name.')).toBeVisible();
      await expect(page.getByText('Accept the terms to continue.')).toBeVisible();
      await expect(page.getByLabel('Email')).toHaveValue('ann@example.com');

      await page.getByLabel('Name').fill('Ann');
      await page.getByLabel('Plan').selectOption('team');
      await page.getByLabel('I accept the terms').check();
      await page.getByRole('button', { name: 'Sign up' }).click();
      await expect(page.locator('#result')).toHaveText('Welcome, Ann! You are on the Team plan.');
      await expect(page.locator('.error')).toHaveCount(0);
      await expect(page.getByLabel('Plan')).toHaveValue('team');
      await expect(page.getByLabel('I accept the terms')).toBeChecked();
    });

    test('03-cart: 行ごとに個数が変わり、合計はサーバーの価格で計算される', async ({ page }) => {
      await page.goto('/03-cart');
      const row = (id: string) => page.locator(`#cart-${id}`);
      await row('coffee').getByRole('button', { name: '+' }).click();
      await expect(row('coffee').locator('.quantity')).toHaveText('1');
      await row('mug').getByRole('button', { name: '+' }).click();
      await expect(row('mug').locator('.quantity')).toHaveText('1');
      await row('coffee').getByRole('button', { name: '+' }).click();
      await expect(row('coffee').locator('.quantity')).toHaveText('2');
      await expect(page.locator('#total')).toHaveText('Total: ¥3,900');
      await row('coffee').getByRole('button', { name: '-' }).click();
      await expect(page.locator('#total')).toHaveText('Total: ¥2,700');
      await page.getByRole('button', { name: 'Empty the cart' }).click();
      await expect(page.locator('#total')).toHaveText('Total: ¥0');
      await expect(row('coffee').getByRole('button', { name: '-' })).toBeDisabled();
    });

    test('04-class-component: key の違う 2 つのインスタンスは状態を分ける', async ({ page }) => {
      await page.goto('/04-class-component');
      const first = page.locator('.card').nth(0);
      const second = page.locator('.card').nth(1);
      await second.getByRole('button', { name: 'Usage' }).click();
      await expect(second.getByText('Register the class')).toBeVisible();
      await expect(first.getByText('Rosanna keeps component state')).toBeVisible();
    });

    test('06-shareable-url: 次のページが URL に載り、別の訪問者も同じページを開ける', async ({ page, browser, baseURL }) => {
      await page.goto('/06-shareable-url');
      await expect(page.locator('#page')).toHaveText('Page 1 of 3');
      await page.getByRole('button', { name: 'Next' }).click();
      await expect(page.locator('#page')).toHaveText('Page 2 of 3');
      await expect(page.getByText('Georgy Porgy')).toBeVisible();

      expect(page.url()).toContain('_rosanna_snapshot=');
      const stranger = await browser.newContext({ baseURL });
      const other = await stranger.newPage();
      await other.goto(page.url());
      await expect(other.locator('#page')).toHaveText('Page 2 of 3');
      await stranger.close();
    });

    test('08-guard: サインインするまで管理ページに入れない', async ({ page }) => {
      const denied = await page.goto('/08-guard/admin');
      expect(denied?.status()).toBe(403);
      expect((await page.goto('/08-guard/admin/'))?.status()).toBe(403);

      await page.goto('/08-guard');
      await expect(page.locator('#status')).toHaveText('Signed out.');
      await page.getByRole('button', { name: 'Sign in as admin' }).click();
      await expect(page.locator('#status')).toHaveText('Signed in.');

      await page.getByRole('link', { name: 'Open the admin page' }).click();
      await page.getByRole('button', { name: 'Run job' }).click();
      await expect(page.locator('#runs')).toHaveText('Job runs: 1');

      await page.goto('/08-guard');
      await page.getByRole('button', { name: 'Sign out' }).click();
      await expect(page.locator('#status')).toHaveText('Signed out.');
      expect((await page.goto('/08-guard/admin'))?.status()).toBe(403);
    });

    test('10-psx: 子コンポーネントを含めて更新される', async ({ page }) => {
      await page.goto('/10-psx');
      await expect(page.locator('#remaining')).toHaveText('3 left');
      for (const id of ['write', 'test', 'tag']) {
        await page.locator(`#task-${id}`).getByRole('button', { name: 'Done' }).click();
        await expect(page.locator(`#task-${id}`).getByRole('button', { name: 'Undo' })).toBeVisible();
      }
      await expect(page.locator('#remaining')).toHaveText('All done!');
      await page.locator('#task-test').getByRole('button', { name: 'Undo' }).click();
      await expect(page.locator('#remaining')).toHaveText('1 left');
    });

    test('11-todo: 同じタイトルを 2 回足すと 2 行になり、入力欄は空に戻り、リロードで増えない', async ({ page }) => {
      await page.goto('/11-todo');
      const title = page.getByLabel('Title');
      const rows = page.locator('#todos li');
      await expect(rows).toHaveCount(0);

      await title.fill('milk');
      await page.getByRole('button', { name: 'Add' }).click();
      await expect(rows).toHaveText(['milk']);
      await expect(title).toHaveValue('');

      await title.fill('milk');
      await page.getByRole('button', { name: 'Add' }).click();
      await expect(rows).toHaveText(['milk', 'milk']);
      await expect(title).toHaveValue('');

      await page.reload();
      await expect(rows).toHaveText(['milk', 'milk']);
      await expect(title).toHaveValue('');
    });

    test('11-todo: 不正な入力は保存せず打ったものを残し、直すと足されて空に戻る', async ({ page }) => {
      await page.goto('/11-todo');
      const title = page.getByLabel('Title');
      const rows = page.locator('#todos li');
      const tooLong = 'x'.repeat(41);

      await title.fill(tooLong);
      await page.getByRole('button', { name: 'Add' }).click();
      await expect(page.locator('.error')).toHaveText('Keep the title to 40 characters.');
      await expect(title).toHaveValue(tooLong);
      await expect(rows).toHaveCount(0);

      await page.reload();
      await expect(title).toHaveValue(tooLong);
      await expect(rows).toHaveCount(0);

      // required は空白だけの値を止めない。サーバーの検証が止める
      await title.fill('   ');
      await page.getByRole('button', { name: 'Add' }).click();
      await expect(page.locator('.error')).toHaveText('Enter a title.');
      await expect(rows).toHaveCount(0);

      await title.fill('milk');
      await page.getByRole('button', { name: 'Add' }).click();
      await expect(rows).toHaveText(['milk']);
      await expect(title).toHaveValue('');
      await expect(page.locator('.error')).toHaveCount(0);
    });

    test('11-todo: ダブルクリックでも 1 行', async ({ page }) => {
      await page.goto('/11-todo');
      await page.getByLabel('Title').fill('milk');
      await page.getByRole('button', { name: 'Add' }).dblclick();
      await expect(page.locator('#todos li')).toHaveText(['milk']);
      await page.waitForTimeout(300);
      await page.reload();
      await expect(page.locator('#todos li')).toHaveText(['milk']);
      await expect(page.locator('.error')).toHaveCount(0);
    });

    test('11-todo: 送信済みのフォームを同じ内容で送り直しても増えない', async ({ page, baseURL }) => {
      await page.goto('/11-todo');
      const posted = page.waitForRequest((request) => request.method() === 'POST');
      await page.getByLabel('Title').fill('milk');
      await page.getByRole('button', { name: 'Add' }).click();
      const sent = await posted;
      await expect(page.locator('#todos li')).toHaveText(['milk']);

      const replay = await page.request.post(sent.url(), {
        headers: {
          'content-type': sent.headers()['content-type'],
          origin: baseURL as string,
          ...(javaScriptEnabled ? { 'x-rosanna-partial': '1' } : {}),
        },
        data: sent.postDataBuffer() as Buffer,
        maxRedirects: 0,
      });
      if (javaScriptEnabled) {
        expect(replay.status()).toBe(200);
        expect(replay.headers()['x-rosanna-stale']).toBe('1');
      } else {
        expect(replay.status()).toBe(303);
      }

      await page.reload();
      await expect(page.locator('#todos li')).toHaveText(['milk']);
    });

    test('11-todo: 別のタブの古いフォームの送信は適用されず、最新が描かれる', async ({ page, context }) => {
      await page.goto('/11-todo');
      const other = await context.newPage();
      await other.goto('/11-todo');

      await page.getByLabel('Title').fill('from page');
      await page.getByRole('button', { name: 'Add' }).click();
      await expect(page.locator('#todos li')).toHaveText(['from page']);

      await other.getByLabel('Title').fill('from other');
      await other.getByRole('button', { name: 'Add' }).click();
      await expect(other.locator('#todos li')).toHaveText(['from page']);
      // rosanna.js は打ったものを残す。JavaScript なしは 303 の先の新しいページなので消える
      await expect(other.getByLabel('Title')).toHaveValue(javaScriptEnabled ? 'from other' : '');

      if (javaScriptEnabled) {
        await other.getByRole('button', { name: 'Add' }).click();
        await expect(other.locator('#todos li')).toHaveText(['from page', 'from other']);
        await expect(other.getByLabel('Title')).toHaveValue('');
      }
    });
  });
}

test('11-todo: 送信中に打った文字は消えない', async ({ page }) => {
  await page.goto('/11-todo');
  const title = page.getByLabel('Title');
  await title.fill('milk');
  const hold = await holdPosts(page);
  await page.getByRole('button', { name: 'Add' }).click();
  await hold.held();
  await title.fill('eggs');
  hold.release();
  await expect(page.locator('#todos li')).toHaveText(['milk']);
  await expect(title).toHaveValue('eggs');
});

test.describe('JavaScript なし', () => {
  test.use({ javaScriptEnabled: false });

  test('11-todo: 戻っても増えない。戻った先のフォームは新しく取得したものなので、次の送信は足される', async ({ page }) => {
    await page.goto('/11-todo');
    await page.getByLabel('Title').fill('milk');
    await page.getByRole('button', { name: 'Add' }).click();
    await expect(page.locator('#todos li')).toHaveText(['milk']);

    await page.goBack();
    await expect(page.locator('#todos li')).toHaveText(['milk']);
    await page.getByLabel('Title').fill('eggs');
    await page.getByRole('button', { name: 'Add' }).click();
    await expect(page.locator('#todos li')).toHaveText(['milk', 'eggs']);
  });
});

test('05-storage: 保存先ごとにリロード後の残り方が違う', async ({ page }) => {
  await page.goto('/05-storage');
  const card = (n: number) => page.locator('.card').nth(n);
  for (let n = 0; n < 4; n++) {
    await card(n).getByRole('button', { name: '+1' }).click();
    await expect(card(n).locator('.count')).toHaveText('1');
  }
  await page.reload();
  await expect(card(0).locator('.count')).toHaveText('1');
  await expect(card(1).locator('.count')).toHaveText('0');
  await expect(card(2).locator('.count')).toHaveText('0');
  await expect(card(3).locator('.count')).toHaveText('1');
});

test('07-live-search: 入力が止まると絞り込まれ、入力欄のフォーカスは残る', async ({ page }) => {
  await page.goto('/07-live-search');
  const input = page.getByLabel('Search');
  await input.pressSequentially('an');
  await expect(page.locator('#count')).toHaveText('3 found');
  await expect(page.locator('li')).toHaveText(['Banana', 'Mango', 'Orange']);
  await expect(input).toBeFocused();
  await input.pressSequentially('g');
  await expect(page.locator('li')).toHaveText(['Mango', 'Orange']);
  await expect(input).toHaveValue('ang');
});

test.describe('JavaScript なし', () => {
  test.use({ javaScriptEnabled: false });

  test('05-storage: Session と Snapshot は JavaScript がなくても増える', async ({ page }) => {
    await page.goto('/05-storage');
    const card = (n: number) => page.locator('.card').nth(n);
    await card(0).getByRole('button', { name: '+1' }).click();
    await expect(card(0).locator('.count')).toHaveText('1');
    await card(2).getByRole('button', { name: '+1' }).click();
    await expect(card(2).locator('.count')).toHaveText('1');
    await expect(card(0).locator('.count')).toHaveText('1');
  });

  test('07-live-search: ボタンで送れば絞り込める', async ({ page }) => {
    await page.goto('/07-live-search');
    await page.getByLabel('Search').fill('ch');
    await page.getByRole('button', { name: 'Go' }).click();
    await expect(page.locator('li')).toHaveText(['Cherry', 'Peach']);
  });

  test('09-defer: fallback だけが見える', async ({ page }) => {
    await page.goto('/09-defer');
    await expect(page.getByText('Loading the announcement...')).toBeVisible();
    await expect(page.getByText('Loading likes...')).toBeVisible();
  });
});

test('09-defer: 断片が読み込まれ、中のボタンも動く', async ({ page, request }) => {
  await page.goto('/09-defer');
  await expect(page.locator('#announcement')).toHaveText('Free shipping this week.');
  await expect(page.locator('#likes')).toHaveText('Rosanna: 0 likes');
  await page.getByRole('button', { name: 'Like' }).click();
  await expect(page.locator('#likes')).toHaveText('Rosanna: 1 likes');
  await page.reload();
  await expect(page.locator('#likes')).toHaveText('Rosanna: 1 likes');

  const announcement = await request.get('/_defer/example-announcement');
  expect(announcement.headers()['cache-control']).toBe('public, s-maxage=60');
  const likes = await request.get('/_defer/example-likes?title=x');
  expect(likes.headers()['cache-control']).toBe('private, no-store');
});
