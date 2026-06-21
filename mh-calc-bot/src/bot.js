import { Bot, InlineKeyboard } from 'grammy';
import { MINI_APP_URL } from './config.js';
import { welcome, help, openAppPrompt } from './messages.js';

/**
 * Сборка grammY-бота: онбординг (/start с deep-link payload), запуск Mini App,
 * помощь. Баланс/команда/реф-ссылка живут в Mini App. Исходящие уведомления
 * (реферал/бонус/ранг) шлёт backend напрямую — здесь только входящее.
 */
export function buildBot(token) {
    const bot = new Bot(token);

    const appKeyboard = () =>
        MINI_APP_URL ? new InlineKeyboard().webApp('Открыть IziGo', MINI_APP_URL) : undefined;

    bot.command('start', async (ctx) => {
        const invited = Boolean(ctx.match && String(ctx.match).trim());
        await ctx.reply(welcome(ctx.from?.first_name, invited), {
            parse_mode: 'HTML',
            reply_markup: appKeyboard(),
        });
    });

    bot.command('app', async (ctx) => {
        await ctx.reply(openAppPrompt(), { reply_markup: appKeyboard() });
    });

    bot.command('help', async (ctx) => {
        await ctx.reply(help(), { parse_mode: 'HTML', reply_markup: appKeyboard() });
    });

    // Меню команд в клиенте Telegram.
    bot.api.setMyCommands([
        { command: 'start', description: 'Начать / открыть приложение' },
        { command: 'app', description: 'Открыть кабинет' },
        { command: 'help', description: 'Помощь' },
    ]).catch(() => { /* best-effort: не валим бота из-за установки меню */ });

    // Постоянная menu-кнопка → запуск Mini App (передаёт initData). Best-effort.
    if (MINI_APP_URL) {
        bot.api.setChatMenuButton({
            menu_button: { type: 'web_app', text: 'IziGo', web_app: { url: MINI_APP_URL } },
        }).catch(() => { /* best-effort */ });
    }

    return bot;
}
