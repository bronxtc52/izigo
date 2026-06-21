import { buildBot } from './bot.js';
import { loadBotToken, loadSentryDsn } from './config.js';
import { initSentry, Sentry } from './sentry.js';

// Точка входа воркера: Sentry (best-effort) → токен из Key Vault → grammY long-polling.
let sentryOn = false;
try {
    sentryOn = initSentry(await loadSentryDsn());

    const token = await loadBotToken();
    const bot = buildBot(token);

    const stop = async () => {
        await bot.stop();
        process.exit(0);
    };
    process.once('SIGINT', stop);
    process.once('SIGTERM', stop);

    await bot.start({
        onStart: (info) => console.log(`IziGo bot started: @${info.username} (sentry: ${sentryOn})`),
    });
} catch (err) {
    // Не логируем объект целиком (может содержать токен в URL) — только сообщение.
    console.error('Bot boot failed:', err?.message || 'unknown error');
    if (sentryOn) {
        Sentry.captureException(err);
        await Sentry.flush(2000).catch(() => {});
    }
    process.exit(1);
}
