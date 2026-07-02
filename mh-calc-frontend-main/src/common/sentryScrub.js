// F1 (P1-hardening): initData Telegram (tgWebAppData) содержит подписанные данные
// пользователя — в Sentry-события попадать не должен. Вычищаем из URL/строк запросов/
// хлебных крошек до отправки (beforeSend). sendDefaultPii и так false — это второй рубеж.
const SENSITIVE = /(tgWebAppData|initData|X-Telegram-Init-Data)=[^&#\s]+/gi;

const clean = (s) => (typeof s === 'string' ? s.replace(SENSITIVE, '$1=[filtered]') : s);

export function scrubSentryEvent(event) {
    if (event?.request) {
        event.request.url = clean(event.request.url);
        event.request.query_string = clean(event.request.query_string);
        if (event.request.headers) {
            delete event.request.headers['X-Telegram-Init-Data'];
            delete event.request.headers['x-telegram-init-data']; // Node-сторона держит заголовки lowercase
            if (event.request.headers.Referer) {
                event.request.headers.Referer = clean(event.request.headers.Referer);
            }
        }
    }
    for (const crumb of event?.breadcrumbs || []) {
        if (crumb?.data?.url) crumb.data.url = clean(crumb.data.url);
        // Навигационные крошки кладут URL в from/to — стартовый URL Mini App несёт #tgWebAppData.
        if (crumb?.data?.from) crumb.data.from = clean(crumb.data.from);
        if (crumb?.data?.to) crumb.data.to = clean(crumb.data.to);
        if (typeof crumb?.message === 'string') crumb.message = clean(crumb.message);
    }

    return event;
}
