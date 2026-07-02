// F1 (P1-hardening): Sentry для edge-рантайма (middleware). Edge у нас не используется,
// но конфиг обязателен для полного покрытия instrumentation.
import * as Sentry from '@sentry/nextjs';
import { scrubSentryEvent } from './src/common/sentryScrub';

Sentry.init({
    dsn: process.env.NEXT_PUBLIC_SENTRY_DSN,
    release: process.env.NEXT_PUBLIC_SENTRY_RELEASE || undefined,
    environment: process.env.NEXT_PUBLIC_SENTRY_ENV || 'beta',
    tracesSampleRate: 0.1,
    sendDefaultPii: false,
    beforeSend: scrubSentryEvent,
});
