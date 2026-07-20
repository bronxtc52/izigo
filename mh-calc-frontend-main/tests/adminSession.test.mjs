// Юнит-тесты seal/unseal BFF-сессии админки (t1) — node:test, без новых зависимостей.
// Запуск: npm run test:unit (в CI не гоняется — CI фронта = lint+build; тест локальный).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    seal, unseal, cookieOptions, isAllowedAdminHost,
} from '../src/app/api/v1/_lib/adminSession.mjs';

const SECRET = 'unit-test-secret';

test('seal → unseal возвращает исходный токен', () => {
    const token = '12|AbCdEf0123456789';
    assert.equal(unseal(seal(token, SECRET), SECRET), token);
});

test('seal недетерминирован (случайный IV), но оба значения распечатываются', () => {
    const a = seal('tok', SECRET);
    const b = seal('tok', SECRET);
    assert.notEqual(a, b);
    assert.equal(unseal(a, SECRET), 'tok');
    assert.equal(unseal(b, SECRET), 'tok');
});

test('tamper любого байта → null (GCM-tag не сходится)', () => {
    const sealed = seal('secret-token', SECRET);
    const raw = Buffer.from(sealed, 'base64url');
    raw[raw.length - 1] ^= 0xff;
    assert.equal(unseal(raw.toString('base64url'), SECRET), null);
});

test('чужой ключ → null', () => {
    assert.equal(unseal(seal('tok', SECRET), 'other-secret'), null);
});

test('мусор/пусто/обрезок → null, без исключений', () => {
    assert.equal(unseal('', SECRET), null);
    assert.equal(unseal(null, SECRET), null);
    assert.equal(unseal('not-base64url-!!!', SECRET), null);
    assert.equal(unseal(Buffer.from('short').toString('base64url'), SECRET), null);
});

test('seal без секрета кидает (мисконфиг не проглатываем)', () => {
    assert.throws(() => seal('tok', ''));
});

test('cookie: httpOnly, SameSite=Lax, Path=/api/v1, TTL в синхроне с WEB_ADMIN_TOKEN_TTL_MINUTES', () => {
    const opts = cookieOptions();
    assert.equal(opts.httpOnly, true);
    assert.equal(opts.sameSite, 'lax');
    assert.equal(opts.path, '/api/v1');
    assert.equal(opts.maxAge, 720 * 60); // дефолт 12ч (env не задан в юнит-прогоне)
});

test('host-guard: admin.* и localhost — да, прод-хост Mini App — нет', () => {
    assert.equal(isAllowedAdminHost('admin.izigo.adarasoft.com'), true);
    assert.equal(isAllowedAdminHost('localhost:3000'), true);
    assert.equal(isAllowedAdminHost('127.0.0.1:3000'), true);
    assert.equal(isAllowedAdminHost('izigo.adarasoft.com'), false);
    assert.equal(isAllowedAdminHost(''), false);
});
