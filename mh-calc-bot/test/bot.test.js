import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildBot } from '../src/bot.js';

test('buildBot конструируется без MINI_APP_URL и не падает', () => {
    // MINI_APP_URL не задан в env теста → кнопка не показывается, но бот строится.
    const bot = buildBot('123456:FAKE');
    assert.equal(typeof bot, 'object');
    assert.equal(typeof bot.handleUpdate, 'function');
});
