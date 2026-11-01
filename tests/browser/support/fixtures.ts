import { test as base, expect, type Locator, type Page, type Request } from '@playwright/test';
import { startPhpServer, type PhpServer } from './php-server';

export { expect };

export interface SentAction {
  readonly url: string;
  readonly partial: boolean;
  readonly type: string;
  readonly payload: Record<string, unknown>;
  readonly version: number | undefined;
  readonly sig: string | null;
  readonly fields: FormData;
}

export interface Posts {
  /** 送られた POST の数。読むだけなので negative の確認に使う */
  count(): number;
  list(): Promise<SentAction[]>;
  /** ここまでの記録を捨てる */
  clear(): void;
}

async function parsePost(request: Request): Promise<SentAction> {
  const buffer = request.postDataBuffer();
  const contentType = request.headers()['content-type'] ?? '';
  const fields = await new Response(buffer, { headers: { 'content-type': contentType } }).formData();
  const envelope = JSON.parse(String(fields.get('_rosanna_action')));
  const action = JSON.parse(envelope.action);

  return {
    url: request.url(),
    partial: request.headers()['x-rosanna-partial'] === '1',
    type: action.type,
    payload: action.payload,
    version: action.version,
    sig: envelope.sig,
    fields,
  };
}

interface TestFixtures {
  posts: Posts;
  noPageErrors: void;
  noPhpErrors: void;
}

export const test = base.extend<TestFixtures, { server: PhpServer }>({
  server: [
    async ({}, use) => {
      const server = await startPhpServer();
      await use(server);
      await server.stop();
    },
    { scope: 'worker' },
  ],

  baseURL: async ({ server }, use) => {
    await use(server.url);
  },

  // 同じコンテキストの別のタブの送信も数える
  posts: async ({ context }, use) => {
    let requests: Request[] = [];
    context.on('request', (request) => {
      if (request.method() === 'POST') requests.push(request);
    });
    await use({
      count: () => requests.length,
      list: () => Promise.all(requests.map(parsePost)),
      clear: () => {
        requests = [];
      },
    });
  },

  noPageErrors: [
    async ({ context }, use) => {
      const errors: string[] = [];
      context.on('weberror', (error) => errors.push(error.error().message));
      await use();
      expect(errors).toEqual([]);
    },
    { auto: true },
  ],

  // fixture-boom は JS-5 が意図して投げる例外
  noPhpErrors: [
    async ({ server }, use) => {
      const before = server.log().length;
      await use();
      const problems = server.log().slice(before).split('\n')
        .filter((line) => /PHP (Fatal|Warning|Parse|Deprecated|Notice)/.test(line) && !line.includes('fixture-boom'));
      expect(problems).toEqual([]);
    },
    { auto: true },
  ],
});

export const wrapper = (page: Page, key: string): Locator => page.locator(`[data-rosanna$="#${key}"]`);

/** ネストしたときに子の要素に当たらないよう、そのインスタンスの直下だけを見る */
export const own = (page: Page, key: string, selector: string): Locator =>
  page.locator(`[data-rosanna$="#${key}"] > .widget > ${selector}`);

export const count = (page: Page, key: string): Locator => own(page, key, '.n');

/**
 * ページの POST をサーバーに届ける前に止める。サーバーを sleep させず、送信中の状態を確実に作る。
 */
export async function holdPosts(page: Page) {
  const waiting: Array<() => void> = [];
  let open = false;
  await page.route('**/*', async (route) => {
    if (route.request().method() !== 'POST' || open) {
      await route.fallback();
      return;
    }
    await new Promise<void>((resolve) => waiting.push(resolve));
    await route.fallback();
  });
  return {
    /** 止まっている分を通し、以後は止めない */
    release() {
      open = true;
      waiting.splice(0).forEach((resolve) => resolve());
    },
    /** 止まっている分だけを通す。以後の POST はまた止まる */
    releaseWaiting() {
      waiting.splice(0).forEach((resolve) => resolve());
    },
    /** n 件が止まるまで待つ */
    async held(n = 1) {
      await expect.poll(() => waiting.length).toBe(n);
    },
  };
}

/** 送信中のものがなくなるまで待つ。その後に動くものを見逃さないよう、タスクを 1 つ譲る */
export async function idle(page: Page): Promise<void> {
  await expect(page.locator('[aria-busy]')).toHaveCount(0);
  await page.waitForTimeout(50);
}

/** data-rosanna-with-value の入力の待ち時間 (300ms) を越えて待つ */
export const AFTER_DEBOUNCE_MS = 450;

export const snapshotVersion = (page: Page, key: string): Promise<number> =>
  wrapper(page, key).evaluate((el) => JSON.parse(el.getAttribute('data-rosanna-snapshot') as string).memo.version);

export const mirrorVersion = (page: Page, key: string): Promise<number | null> =>
  wrapper(page, key).evaluate((el) => {
    const stored = sessionStorage.getItem(`rosanna:snapshot:${location.pathname}#${el.getAttribute('data-rosanna')}`);
    return stored === null ? null : JSON.parse(stored).memo.version;
  });

export async function listen(page: Page, name: string): Promise<void> {
  await page.evaluate((event) => {
    const store = ((window as unknown as Record<string, unknown>).__events ??= {}) as Record<string, unknown[]>;
    store[event] = [];
    document.addEventListener(event, (e) => {
      const detail = (e as CustomEvent).detail ?? {};
      store[event].push({ origin: detail.origin, status: detail.response?.status, error: detail.error ? String(detail.error) : undefined });
    });
  }, name);
}

export const heard = (page: Page, name: string): Promise<Array<Record<string, unknown>>> =>
  page.evaluate((event) => ((window as unknown as { __events: Record<string, Array<Record<string, unknown>>> }).__events)[event], name);
