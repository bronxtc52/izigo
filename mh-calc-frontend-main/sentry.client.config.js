// F1 (P1-hardening): Sentry для браузерной части Mini App / админки. DSN — публичный
// по природе (запекается build-arg'ом NEXT_PUBLIC_SENTRY_DSN); без него init — no-op.
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
