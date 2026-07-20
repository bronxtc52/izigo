import { test } from 'node:test';
import assert from 'node:assert/strict';
import { initSentry, Sentry } from '../src/sentry.js';

test('initSentry без DSN не включается и не падает', () => {
    assert.equal(initSentry(''), false);
    assert.equal(initSentry(null), false);
    assert.equal(initSentry(undefined), false);
});

test('initSentry с DSN инициализирует живой клиент (совместимость @sentry/node v10)', () => {
    // Фейк-DSN валидного формата: init не делает сетевых вызовов (события уходят
    // только на capture+flush), но создаёт клиент. Проверяем, что наш init-конфиг
    // (dsn/environment/release/tracesSampleRate) принят API v10 без исключения и
    // клиент реально поднят — это ловит breaking-change сигнатуры init при 8→10.
    assert.equal(initSentry('https://examplePublicKey@o0.ingest.sentry.io/0'), true);
    const client = Sentry.getClient();
    assert.ok(client, 'после init должен существовать активный Sentry-клиент');
    assert.equal(client.getOptions().environment, process.env.APP_ENV || 'production');
    // captureException не должен бросать под v10 при живом клиенте.
    assert.doesNotThrow(() => Sentry.captureException(new Error('smoke')));
});
