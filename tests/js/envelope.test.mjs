import assert from 'node:assert/strict';
import { test } from 'node:test';
import { loadClient } from './load-client.mjs';

const {
    buildRestoreEnvelope,
    envelopeVersion,
    isEnvelopeStale,
    parseEnvelope,
    snapshotKey,
    snapshotVersion,
} = loadClient();

const envelope = (action, sig = 'abc') => JSON.stringify({ action: JSON.stringify(action), sig });
const snapshot = (version) => JSON.stringify({ memo: { name: 'C', key: 'k', version }, state: {}, checksum: 'x' });

test('AC-20: エンベロープの action は文字列のまま保ち、中身も読める', () => {
    const actionJson = '{"type":"setState","payload":{"index":0,"value":1},"version":3}';
    const parsed = parseEnvelope(JSON.stringify({ action: actionJson, sig: 's' }));
    assert.equal(parsed.actionJson, actionJson);
    assert.equal(parsed.sig, 's');
    assert.equal(parsed.action.version, 3);
});

test('JS-8: JSON にならない値やエンベロープでない値は解釈しない', () => {
    for (const value of ['rosanna-action:abc', 'abc', '', '[1]', '1', 'null', '{"action":1}', '{"action":"not json"}', '{"action":"[1]"}']) {
        assert.equal(parseEnvelope(value), null, value);
        assert.equal(envelopeVersion(value), null, value);
    }
    assert.equal(parseEnvelope(null), null);
});

test('JS-8: 版はエンベロープの中の action から読む。外側の version は見ない', () => {
    assert.equal(envelopeVersion(envelope({ type: 'setState', version: 7 })), 7);
    assert.equal(envelopeVersion(JSON.stringify({ action: '{"type":"setState"}', version: 7 })), null);
    assert.equal(envelopeVersion(envelope({ type: 'setState', version: '7' })), null);
    assert.equal(envelopeVersion(envelope({ type: 'setState', version: null })), null);
});

test('JS-8: 写しの memo.version より古いフォームだけを止める', () => {
    const action = (version) => envelope({ type: 'setState', version });
    assert.equal(isEnvelopeStale(action(1), snapshot(2)), true);
    assert.equal(isEnvelopeStale(action(2), snapshot(2)), false);
    assert.equal(isEnvelopeStale(action(3), snapshot(2)), false);
});

test('JS-8: 比べられないものは止めない', () => {
    assert.equal(isEnvelopeStale('rosanna-action:abc', snapshot(2)), false);
    assert.equal(isEnvelopeStale(envelope({ type: 'setState' }), snapshot(2)), false);
    assert.equal(isEnvelopeStale(envelope({ type: 'setState', version: 1 }), 'broken'), false);
    assert.equal(isEnvelopeStale(envelope({ type: 'setState', version: 1 }), '{"memo":{"version":"2"}}'), false);
    assert.equal(isEnvelopeStale(envelope({ type: 'setState', version: 1 }), '{"memo":null}'), false);
});

test('SN-1: スナップショットの版を読む', () => {
    assert.equal(snapshotVersion(snapshot(0)), 0);
    assert.equal(snapshotVersion(snapshot(4)), 4);
    assert.equal(snapshotVersion('{}'), null);
    assert.equal(snapshotVersion(null), null);
});

test('AC-21、AC-8: restore のエンベロープは同じ形で、payload が空、sig が restore の署名', () => {
    const parsed = parseEnvelope(buildRestoreEnvelope('Counter#1', 'restore-sig'));
    assert.equal(parsed.sig, 'restore-sig');
    assert.deepEqual(parsed.action, {
        type: 'restore',
        payload: {},
        componentId: 'Counter#1',
        storageType: 'snapshot',
    });
    assert.equal(typeof JSON.parse(buildRestoreEnvelope('a#b', 's')).action, 'string');
});

test('AC-21: 署名がないときは null を入れる。サーバーが拒否して JS-13 で捨てられる', () => {
    assert.equal(JSON.parse(buildRestoreEnvelope('a#b', null)).sig, null);
});

test('JS-9: 写しのキー', () => {
    assert.equal(snapshotKey('/todos', 'Counter#1'), 'rosanna:snapshot:/todos#Counter#1');
});

test('JS-25: Session の action の判定', () => {
    const { isSessionEnvelope } = loadClient();
    const envelope = (action) => JSON.stringify({ action: JSON.stringify(action), sig: 'x' });

    assert.equal(isSessionEnvelope(envelope({ type: 'setState', storageType: 'session' })), true);
    assert.equal(isSessionEnvelope(envelope({ type: 'setState', storageType: 'snapshot' })), false);
    assert.equal(isSessionEnvelope(envelope({ type: 'setState', storageType: 'memory' })), false);
    // RT-5.3 クラスの action は storageType を持たない。Snapshot のラッパーでなければ Session (AC-11)
    assert.equal(isSessionEnvelope(envelope({ type: 'setState', storageType: null })), true);
    assert.equal(isSessionEnvelope(envelope({ type: 'setState', storageType: null }), true), false);
    // JS-8 と同じく JSON にならない値は判定しない
    for (const value of ['rosanna-action:abc', '[1]', '', null]) {
        assert.equal(isSessionEnvelope(value), false, String(value));
    }
});

test('JS-22: ページの更新の後に restore で戻すか', () => {
    const { needsRestoreAfterRefresh } = loadClient();
    const snapshot = (version, state = []) => JSON.stringify({ memo: { name: 'n', key: 'k', version }, state });

    assert.equal(needsRestoreAfterRefresh(snapshot(2), snapshot(0)), true);
    assert.equal(needsRestoreAfterRefresh(snapshot(2, [1]), snapshot(2, [9])), true);
    assert.equal(needsRestoreAfterRefresh(snapshot(2), snapshot(2)), false);
    // 操作されていない。描画の値を正とする
    assert.equal(needsRestoreAfterRefresh(snapshot(0, [1]), snapshot(0, [2])), false);
    // サーバーが新しい状態を持っている
    assert.equal(needsRestoreAfterRefresh(snapshot(1), snapshot(2)), false);
    assert.equal(needsRestoreAfterRefresh(undefined, snapshot(0)), false);
    assert.equal(needsRestoreAfterRefresh(snapshot(1), null), false);
    assert.equal(needsRestoreAfterRefresh('broken', snapshot(0)), false);
});
