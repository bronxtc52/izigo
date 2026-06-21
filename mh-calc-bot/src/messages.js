// Тексты бота (ru) для parse_mode=HTML. Динамические значения (имя) экранируются —
// глобальное правило: любой текст наружу в Telegram-HTML экранируем (<, >, &).

export function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

export function welcome(name, invited) {
    const hi = name ? `, <b>${escapeHtml(name)}</b>` : '';
    const inviteLine = invited
        ? '\n\nВы перешли по приглашению — откройте приложение, чтобы встать в команду спонсора.'
        : '';
    return (
        `Добро пожаловать в <b>IziGo</b>${hi}! 👋` +
        inviteLine +
        '\n\nЗдесь вы управляете своей сетью: доход, команда, ранги и активация пакета — всё в приложении.'
    );
}

export function help() {
    return (
        '<b>IziGo — команды</b>\n' +
        '/start — начать / открыть приложение\n' +
        '/app — открыть кабинет (Mini App)\n' +
        '/help — помощь\n\n' +
        'Баланс, дерево команды и реф-ссылка — внутри приложения.'
    );
}

export function openAppPrompt() {
    return 'Откройте кабинет IziGo:';
}
