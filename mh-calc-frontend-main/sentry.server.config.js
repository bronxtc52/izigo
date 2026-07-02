// F1 (P1-hardening): Sentry для Node-рантайма Next.js (SSR/route handlers).
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
