import assert from 'node:assert/strict';
import { test } from 'node:test';
import { loadClient } from './load-client.mjs';

const { isHtmlContentType, sameOriginRedirect } = loadClient();

const page = 'https://app.example/todos?x=1';

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

