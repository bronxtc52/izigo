import { withSentryConfig } from '@sentry/nextjs';

/** @type {import('next').NextConfig} */

const nextConfig = {
    output: 'standalone', // для Docker/ACA (Фаза 0)
    poweredByHeader: false, // не светим X-Powered-By: Next.js (G2 hardening)
    // Next 15: instrumentation.js стабилен — experimental.instrumentationHook удалён из Next,
    // src/instrumentation.js (серверный Sentry) продолжает работать без флага.
    // Убран и блок i18n: это Pages Router-опция, App Router её игнорировал (мёртвый конфиг,
    // /ru и раньше отдавал 404) — реальная локализация живёт в react-i18next (src/common/i18n.js).
    // Security-заголовки (G2 hardening). Применяются ко всем маршрутам.
    // ⚠️ СОЗНАТЕЛЬНО НЕ ставим X-Frame-Options / frame-ancestors / строгую CSP:
    // izigo.adarasoft.com — это Telegram Mini App, грузится ВНУТРИ Telegram (iframe/webview)
    // и использует TonConnect (попапы кошельков, внешние домены). Глухой DENY или узкая CSP
    // сломают запуск Mini App и оплату TON. Приоритет — не сломать embedding+оплату.
    // Заголовки ниже безопасны для iframe-встраивания и не ограничивают источники.
    // CSP отложена осознанно: её ввод требует отдельной выверки со списком TonConnect/
    // Telegram/аналитики-доменов и Report-Only обкатки, иначе риск обрушить оплату.
    async headers() {
        return [
            {
                source: '/:path*',
                headers: [
                    { key: 'X-Content-Type-Options', value: 'nosniff' },
                    { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
                    {
                        key: 'Strict-Transport-Security',
                        value: 'max-age=63072000; includeSubDomains',
                    },
                ],
            },
        ];
    },
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
