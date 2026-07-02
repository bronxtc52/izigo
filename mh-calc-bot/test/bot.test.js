import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Bot, BotError } from 'grammy';
import { buildBot } from '../src/bot.js';

test('buildBot конструируется без MINI_APP_URL и не падает', () => {
    // MINI_APP_URL не задан в env теста → кнопка не показывается, но бот строится.
    const bot = buildBot('123456:FAKE', { setupTelegramUi: false });
    assert.equal(typeof bot, 'object');
    assert.equal(typeof bot.handleUpdate, 'function');
});

// P1-hardening (O3): глобальный error boundary. Дефолтный errorHandler grammY
// ПЕРЕБРАСЫВАЕТ ошибку — long-polling loop умирает. bot.catch обязан её поглотить
// (лог + Sentry best-effort), чтобы бот пережил исключение в хендлере апдейта.

const fakeError = () => new BotError(new Error('handler boom'), { update: { update_id: 42 } });

test('bot.catch поглощает ошибку апдейта — поллинг живёт дальше', async () => {
    const bot = buildBot('123456:FAKE', { setupTelegramUi: false });

    await assert.doesNotReject(async () => bot.errorHandler(fakeError()));
});

test('контроль: без bot.catch дефолтный обработчик перебрасывает', async () => {
    const bare = new Bot('123456:FAKE');

    await assert.rejects(async () => bare.errorHandler(fakeError()));
});
