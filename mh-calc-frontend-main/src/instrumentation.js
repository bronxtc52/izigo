// F1 (P1-hardening): серверная инициализация Sentry (Next 14: experimental.instrumentationHook).
import * as Sentry from '@sentry/nextjs';

export async function register() {
    if (process.env.NEXT_RUNTIME === 'nodejs') {
        await import('../sentry.server.config');
    }
    if (process.env.NEXT_RUNTIME === 'edge') {
        await import('../sentry.edge.config');
    }
}

// Хук onRequestError вызывается Next-ом начиная с Next 15 — на текущем Next 14 это no-op
// (форвард-совместимость под будущий апгрейд по P2-CVE). Сейчас серверные ошибки рендера
// доезжают до Sentry только обезличенным digest'ом через клиентский error.js.
export const onRequestError = Sentry.captureRequestError;
