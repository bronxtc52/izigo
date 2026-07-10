// F1 (P1-hardening): серверная инициализация Sentry (Next 15: instrumentation.js стабилен,
// experimental-флаг больше не нужен).
import * as Sentry from '@sentry/nextjs';

export async function register() {
    if (process.env.NEXT_RUNTIME === 'nodejs') {
        await import('../sentry.server.config');
    }
    if (process.env.NEXT_RUNTIME === 'edge') {
        await import('../sentry.edge.config');
    }
}

// Хук onRequestError вызывается Next-ом начиная с Next 15 — с миграции 2026-07-10 он живой:
// серверные ошибки рендера уходят в Sentry напрямую (раньше — только digest через error.js).
export const onRequestError = Sentry.captureRequestError;
