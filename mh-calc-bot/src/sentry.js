import * as Sentry from '@sentry/node';

/**
 * Инициализация Sentry (self-host sentry.adarasoft.com — хост в самом DSN).
 * Best-effort: без DSN просто не включаем (не валим воркер). Возвращает true,
 * если инициализировано.
 */
export function initSentry(dsn) {
    if (!dsn) {
        return false;
    }
    Sentry.init({
        dsn,
        environment: process.env.APP_ENV || 'production',
        release: process.env.GIT_SHA || undefined,
        tracesSampleRate: 0.1,
    });
    return true;
}

export { Sentry };
