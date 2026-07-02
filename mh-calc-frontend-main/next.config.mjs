import { withSentryConfig } from '@sentry/nextjs';

/** @type {import('next').NextConfig} */

const nextConfig = {
    output: 'standalone', // для Docker/ACA (Фаза 0)
    experimental: {
        instrumentationHook: true, // Next 14: включает src/instrumentation.js (Sentry server)
    },
    i18n: {
        defaultLocale: 'kk',
        locales: ['kk', 'ru', 'mn', 'uz', 'ky', 'az'],
        localeDetection: false
    }
};

// F1 (P1-hardening): Sentry поверх Next-конфига. Sourcemaps на self-hosted — best-effort:
// без SENTRY_AUTH_TOKEN аплоад полностью выключен и билд НЕ падает; silent — плагин не
// шумит и не роняет сборку на ошибках аплоада.
export default withSentryConfig(nextConfig, {
    org: 'sentry',
    project: 'izigo-frontend',
    sentryUrl: process.env.SENTRY_URL || 'https://sentry.adarasoft.com',
    authToken: process.env.SENTRY_AUTH_TOKEN,
    silent: true,
    telemetry: false,
    sourcemaps: { disable: !process.env.SENTRY_AUTH_TOKEN },
    disableLogger: true,
});
