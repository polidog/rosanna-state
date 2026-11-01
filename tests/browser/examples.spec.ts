// examples/ のサンプルを、README に書いたとおり router.php で動かして確かめる
import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { expect, test as base } from './support/fixtures';
import { startPhpServer, type PhpServer } from './support/php-server';

const ROOT = resolve(__dirname, '../..');

const test = base.extend<{}, { server: PhpServer }>({
  server: [
    async ({}, use) => {
      execFileSync('php', ['bin/rosanna', 'compile', 'examples/10-psx/components', '--cache=examples/10-psx/var/cache/psx'], { cwd: ROOT });
      const server = await startPhpServer(resolve(ROOT, 'examples/router.php'), { ROSANNA_SNAPSHOT_SECRET: 'examples-test-secret-0123456789' });
      await use(server);
      await server.stop();
    },
    { scope: 'worker' },
  ],
});

test('一覧からすべてのサンプルに行ける', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('li a')).toHaveCount(10);
  await page.getByRole('link', { name: '01-counter' }).click();
  await expect(page.locator('h1')).toHaveText('Counter');
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
  });
}

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
