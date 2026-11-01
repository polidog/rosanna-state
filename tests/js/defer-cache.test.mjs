import assert from 'node:assert/strict';
import { test } from 'node:test';
import { loadClient, memoryStorage } from './load-client.mjs';

const { DEFER_CACHE_VERSION, SESSION_BOUND_SELECTOR, createDeferStore, createLruCache } = loadClient();

const PREFIX = 'rosanna:defer:';

test('JS-17: localStorage に入れない断片の目印', () => {
    for (const selector of ['[data-rosanna-form]', '"_rosanna_csrf"', '"_rosanna_snapshot"', '[data-rosanna-snapshot]']) {
        assert.ok(SESSION_BOUND_SELECTOR.includes(selector), selector);
    }
});

test('JS-18: 書いて読める。キーは rosanna:defer:{url}', () => {
    const storage = memoryStorage();
    const store = createDeferStore(() => storage);
    store.write('/_defer/a', '<p>a</p>');
    assert.equal(store.read('/_defer/a'), '<p>a</p>');
    assert.deepEqual(store.urls(), ['/_defer/a']);
    assert.ok(storage.getItem(PREFIX + '/_defer/a'));
});

test('JS-18: 上限は 64 件で、古い順に捨てる', () => {
    const storage = memoryStorage();
    let clock = 0;
    const store = createDeferStore(() => storage, () => ++clock);
    for (let i = 0; i < 70; i++) store.write('/_defer/' + i, 'x');
    const urls = store.urls();
    assert.equal(urls.length, 64);
    assert.equal(urls.includes('/_defer/5'), false);
    assert.equal(urls.includes('/_defer/6'), true);
    assert.equal(urls.includes('/_defer/69'), true);
});

test('JS-18: バージョンが変われば全部消す', () => {
    const storage = memoryStorage({
        [PREFIX + '__version__']: 'old',
        [PREFIX + '/_defer/a']: JSON.stringify({ v: 'old', html: 'x', storedAt: 1 }),
        unrelated: 'keep',
    });
    const store = createDeferStore(() => storage);
    store.reconcile();
    assert.equal(store.read('/_defer/a'), null);
    assert.equal(storage.getItem(PREFIX + '/_defer/a'), null);
    assert.equal(storage.getItem(PREFIX + '__version__'), DEFER_CACHE_VERSION);
    assert.equal(storage.getItem('unrelated'), 'keep');
});

test('JS-18: 古い版のタブが後から書いた項目は読まずに消す', () => {
    const storage = memoryStorage({
        [PREFIX + '__version__']: DEFER_CACHE_VERSION,
        [PREFIX + '/_defer/a']: JSON.stringify({ v: 'old', html: 'x', storedAt: 1 }),
    });
    const store = createDeferStore(() => storage);
    store.reconcile();
    assert.equal(store.read('/_defer/a'), null);
    assert.equal(storage.getItem(PREFIX + '/_defer/a'), null);
});

test('JS-18: 壊れた項目は読まずに消す', () => {
    const storage = memoryStorage({ [PREFIX + '/_defer/a']: '{broken', [PREFIX + '/_defer/b']: '{"v":"1"}' });
    const store = createDeferStore(() => storage);
    assert.equal(store.read('/_defer/a'), null);
    assert.equal(store.read('/_defer/b'), null);
    assert.deepEqual(storage.keys().filter((key) => key.endsWith('__probe__')), []);
    assert.equal(storage.length, 0);
});

test('JS-18: TTL を過ぎた項目は捨てる。TTL がなければ期限はない', () => {
    const storage = memoryStorage();
    let time = 1000;
    const store = createDeferStore(() => storage, () => time);
    store.write('/_defer/a', 'x');
    time = 1000 + 60000;
    assert.equal(store.read('/_defer/a', 60000), 'x');
    assert.equal(store.read('/_defer/a'), 'x');
    time += 1;
    assert.equal(store.read('/_defer/a', 60000), null);
    assert.equal(store.read('/_defer/a'), null);
});

test('JS-18: 容量切れでは最も古いものを捨ててやり直す', () => {
    const storage = memoryStorage();
    let clock = 0;
    const store = createDeferStore(() => storage, () => ++clock);
    store.write('/_defer/a', 'a');
    store.write('/_defer/b', 'b');
    const setItem = storage.setItem;
    let failed = false;
    storage.setItem = (key, value) => {
        if (!failed && key.endsWith('/_defer/c')) {
            failed = true;
            throw new Error('QuotaExceededError');
        }
        setItem(key, value);
    };
    store.write('/_defer/c', 'c');
    assert.deepEqual(store.urls().sort(), ['/_defer/b', '/_defer/c']);
});

test('JS-18: localStorage がなければ何もせず、例外も出さない', () => {
    const stores = [
        createDeferStore(() => null),
        createDeferStore(() => {
            throw new Error('SecurityError');
        }),
    ];
    for (const store of stores) {
        store.reconcile();
        store.write('/_defer/a', 'x');
        assert.equal(store.read('/_defer/a'), null);
        store.remove('/_defer/a');
        store.clear();
        assert.deepEqual(store.urls(), []);
    }
});

test('clearDeferCache の土台: clear は名前空間だけを消し、版を付け直す', () => {
    const storage = memoryStorage({ unrelated: 'keep' });
    const store = createDeferStore(() => storage);
    store.write('/_defer/a', 'x');
    store.clear();
    assert.deepEqual(store.urls(), []);
    assert.equal(storage.getItem('unrelated'), 'keep');
    assert.equal(storage.getItem(PREFIX + '__version__'), DEFER_CACHE_VERSION);
});

test('JS-18: メモリの段は最近使っていないものから捨てる', () => {
    const cache = createLruCache(2);
    cache.set('a', 1);
    cache.set('b', 2);
    assert.equal(cache.get('a'), 1);
    cache.set('c', 3);
    assert.equal(cache.get('b'), undefined);
    assert.deepEqual(cache.keys().sort(), ['a', 'c']);
    cache.delete('a');
    assert.deepEqual(cache.keys(), ['c']);
    cache.clear();
    assert.deepEqual(cache.keys(), []);
});
