import assert from 'node:assert/strict';
import { test } from 'node:test';
import { loadClient } from './load-client.mjs';

const {
    deferUrlMatchesName,
    isHtmlContentType,
    parseCacheTtlMs,
    parseReloadTargets,
    resolveDeferUrl,
    sameOriginRedirect,
} = loadClient();

const page = 'https://app.example/todos?x=1';

test('JS-16: 同一オリジンで prefix 配下の URL だけを許す', () => {
    assert.equal(resolveDeferUrl('/_defer/list?a=1', undefined, page), 'https://app.example/_defer/list?a=1');
    assert.equal(resolveDeferUrl('https://app.example/_defer/list', undefined, page), 'https://app.example/_defer/list');
    assert.equal(resolveDeferUrl('/api/_d/list', '/api/_d', page), 'https://app.example/api/_d/list');
    assert.equal(resolveDeferUrl('/api/_d/list', '/api/_d/', page), 'https://app.example/api/_d/list');
});

test('JS-16: 別オリジン、prefix の外、prefix に見えるだけのものを拒否する', () => {
    const rejected = [
        'https://evil.example/_defer/list',
        '//evil.example/_defer/list',
        '/\\evil.example/_defer/list',
        'http://app.example/_defer/list',
        'https://app.example:8443/_defer/list',
        'javascript:alert(1)',
        'data:text/html,x',
        '/other/list',
        '/_defer',
        '/_deferred/list',
        '/_defer/../admin',
        '/_defer/%2e%2e/admin',
    ];
    for (const url of rejected) {
        assert.equal(resolveDeferUrl(url, undefined, page), null, url);
    }
});

test('JS-16: 文字列でない prefix は既定にし、オリジン全体になる prefix は拒否する', () => {
    assert.equal(resolveDeferUrl('/_defer/a', {}, page), 'https://app.example/_defer/a');
    assert.equal(resolveDeferUrl('/_defer/a', '', page), 'https://app.example/_defer/a');
    assert.equal(resolveDeferUrl('/anything', '/', page), null);
    assert.equal(resolveDeferUrl('/_defer/a', '_defer', page), null);
});

test('JS-16: 応答は text/html だけを受ける', () => {
    assert.equal(isHtmlContentType('text/html'), true);
    assert.equal(isHtmlContentType('text/html; charset=UTF-8'), true);
    assert.equal(isHtmlContentType('TEXT/HTML;charset=utf-8'), true);
    for (const type of ['application/json', 'text/plain', 'text/htmlx', 'application/xhtml+xml', '', null]) {
        assert.equal(isHtmlContentType(type), false, String(type));
    }
});

test('JS-4: 同一オリジンの redirect だけを行き先にする', () => {
    assert.equal(sameOriginRedirect('https://app.example/todos', page), 'https://app.example/todos');
    assert.equal(sameOriginRedirect('https://evil.example/', page), null);
    assert.equal(sameOriginRedirect('http://app.example/', page), null);
    assert.equal(sameOriginRedirect('http://[bad', page), null);
});

test('JS-18: 名前は URL の最後のセグメントで引く', () => {
    assert.equal(deferUrlMatchesName('/_defer/list?a=1', 'list'), true);
    assert.equal(deferUrlMatchesName('/api/_d/list', 'list'), true);
    assert.equal(deferUrlMatchesName('/_defer/list', 'lis'), false);
    assert.equal(deferUrlMatchesName('/_defer/%ZZ', '%ZZ'), true);
});

test('JS-20: data-rosanna-reload-defer は空白かカンマで区切る', () => {
    assert.deepEqual(parseReloadTargets('a b,c ,  d'), ['a', 'b', 'c', 'd']);
    assert.deepEqual(parseReloadTargets(''), []);
    assert.deepEqual(parseReloadTargets(null), []);
});

test('JS-18: TTL は正の整数の秒だけを受ける', () => {
    assert.equal(parseCacheTtlMs('60'), 60000);
    for (const value of ['0', '-1', '1.5', 'abc', null]) {
        assert.equal(parseCacheTtlMs(value), 0, String(value));
    }
});
