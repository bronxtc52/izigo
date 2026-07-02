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

// Ошибки React Server Components / route handlers → Sentry.
export const onRequestError = Sentry.captureRequestError;
