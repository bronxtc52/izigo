import { test } from 'node:test';
import assert from 'node:assert/strict';
import { escapeHtml, welcome, help, openAppPrompt } from '../src/messages.js';

test('escapeHtml экранирует спецсимволы HTML', () => {
    assert.equal(escapeHtml('<b> & </b>'), '&lt;b&gt; &amp; &lt;/b&gt;');
    assert.equal(escapeHtml(null), '');
});

test('welcome экранирует имя пользователя (анти-инъекция в Telegram-HTML)', () => {
    const msg = welcome('<script>x</script>', false);
    assert.ok(msg.includes('&lt;script&gt;'));
    assert.ok(!msg.includes('<script>'));
});

test('welcome добавляет строку про приглашение при invited=true', () => {
    assert.ok(welcome('Аня', true).includes('приглашению'));
    assert.ok(!welcome('Аня', false).includes('приглашению'));
});

test('help и openAppPrompt непустые', () => {
    assert.ok(help().length > 0);
    assert.ok(openAppPrompt().length > 0);
});
