import assert from 'node:assert/strict';
import { test } from 'node:test';
import { loadClient, memoryStorage } from './load-client.mjs';

const { chooseFieldValue, createSnapshotMirror, mirrorPolicy } = loadClient();

test('VR-6: navigate と reload は写しを消し、戻る操作と bfcache は残す', () => {
    assert.equal(mirrorPolicy('navigate', false), 'clear');
    assert.equal(mirrorPolicy('reload', false), 'clear');
    assert.equal(mirrorPolicy('prerender', false), 'clear');
    assert.equal(mirrorPolicy(undefined, false), 'clear');
    assert.equal(mirrorPolicy('back_forward', false), 'keep');
    assert.equal(mirrorPolicy('navigate', true), 'keep');
});

test('JS-9: sessionStorage に写す', () => {
    const storage = memoryStorage();
    const mirror = createSnapshotMirror(() => storage);
    mirror.write('k', 'v');
    assert.equal(storage.getItem('k'), 'v');
    assert.equal(mirror.read('k'), 'v');
    mirror.remove('k');
    assert.equal(mirror.read('k'), null);
    assert.equal(storage.getItem('k'), null);
});

test('JS-10: sessionStorage へのアクセスが例外でもメモリで同じ確認をする', () => {
    const mirror = createSnapshotMirror(() => {
        throw new Error('SecurityError');
    });
    assert.equal(mirror.read('k'), null);
    mirror.write('k', 'v');
    assert.equal(mirror.read('k'), 'v');
    mirror.remove('k');
    assert.equal(mirror.read('k'), null);
});

test('JS-10: 書けないときは古い値を消してメモリの値を読む', () => {
    const storage = memoryStorage({ k: 'old' });
    storage.setItem = () => {
        throw new Error('QuotaExceededError');
    };
    const mirror = createSnapshotMirror(() => storage);
    mirror.write('k', 'new');
    assert.equal(storage.getItem('k'), null);
    assert.equal(mirror.read('k'), 'new');
});

test('JS-11: 接頭辞で全部消す。他のキーは残す', () => {
    const storage = memoryStorage({ 'rosanna:snapshot:/a#1': 'x', 'rosanna:snapshot:/b#2': 'y', other: 'z' });
    const mirror = createSnapshotMirror(() => storage);
    mirror.removeAll('rosanna:snapshot:');
    assert.deepEqual(storage.keys(), ['other']);
});

const base = {
    current: 'typed',
    renderedBefore: 'old',
    renderedAfter: 'old',
    sentValue: undefined,
    inSentForm: false,
    isSource: false,
    keepSent: false,
};

test('JS-7: 1 送信の間に編集された値は利用者のもの', () => {
    assert.equal(chooseFieldValue({ ...base, sentValue: 'before', inSentForm: true, renderedAfter: 'new' }), 'typed');
});

test('JS-7: 2 サーバーが新しい値を描画したらサーバーのもの', () => {
    assert.equal(chooseFieldValue({ ...base, renderedAfter: 'new' }), 'new');
    assert.equal(chooseFieldValue({ ...base, sentValue: 'typed', renderedAfter: 'new' }), 'new');
});

test('JS-7: 3 送信したフォームの欄は描画された値になる', () => {
    assert.equal(chooseFieldValue({ ...base, sentValue: 'typed', inSentForm: true }), 'old');
});

test('JS-7: 4 送っていない値は利用者のもの', () => {
    assert.equal(chooseFieldValue({ ...base, sentValue: 'typed' }), 'typed');
    assert.equal(chooseFieldValue(base), 'typed');
});

test('JS-8a: 古い action の描き直しでは、送信した欄を保つ', () => {
    const stale = { ...base, sentValue: 'typed', keepSent: true };
    assert.equal(chooseFieldValue({ ...stale, inSentForm: true, renderedAfter: 'new' }), 'typed');
    assert.equal(chooseFieldValue({ ...stale, inSentForm: true }), 'typed');
    assert.equal(chooseFieldValue({ ...stale, isSource: true, renderedAfter: 'new' }), 'typed');
});

test('JS-8a: 送信に関係のない欄は JS-7 の順に従う', () => {
    assert.equal(chooseFieldValue({ ...base, keepSent: true, renderedAfter: 'new' }), 'new');
});
