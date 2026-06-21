import { test } from 'node:test';
import assert from 'node:assert/strict';
import { initSentry } from '../src/sentry.js';

test('initSentry без DSN не включается и не падает', () => {
    assert.equal(initSentry(''), false);
    assert.equal(initSentry(null), false);
    assert.equal(initSentry(undefined), false);
});
