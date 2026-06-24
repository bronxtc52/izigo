// Block C — registry вкладок Mini App (нижний таб-бар).
//
// Назначение: фичи C1 (inbox), C2 (tickets), C6 (profile/co-partners) добавляют свои
// вкладки, НЕ редактируя массив TABS и цепочку контента в MiniAppShell.js (горячий
// файл). Каждая фича создаёт рядом `<feature>.tab.js`, экспортирующий объект формата:
//
//   {
//     key:   'inbox',
//     label: 'Уведомления',
//     icon:  <BellOutlined />,
//     // render получает контекст шелла (initData, pal, isDark, wa, me, ...),
//     // чтобы не дублировать загрузку данных:
//     render: (ctx) => <FeatureTab {...ctx} />,
//   }
//
// и регистрирует его одной строкой ниже (внутри маркеров Block C). MiniAppShell
// рендерит контент таба из registry, когда активный key не совпал с базовыми
// вкладками (см. blockCTabRender). Пустой массив => таб-бар визуально не меняется.

import notificationsTab from './notifications.tab'; // C1 notifications — inbox
import helpdeskTab from './helpdesk.tab'; // C2 helpdesk — tickets
import assistantTab from './assistant.tab'; // AI assistant — knowledge base Q&A

// >>> Block C tabs
export const blockCTabs = [
    notificationsTab, // C1 notifications — inbox (колокольчик)
    helpdeskTab, // C2 helpdesk — поддержка (тикеты + чат)
    assistantTab, // AI-ассистент — вопросы по KB (за флагом ai_assistant)
    // C6 copartners — profile-extra
];
// <<< Block C tabs

/**
 * Видимые вкладки Блока C по карте фиче-флагов (deny-by-default). Вкладка показывается,
 * только если у неё нет поля `flag` ИЛИ флаг явно включён (flags[flag] === true).
 * Пустая/отсутствующая карта => все флаговые вкладки скрыты; базовый таб-бар Mini App
 * (income/shop/team/rank/profile) от флагов НЕ зависит.
 * @param {object} flags — карта ключ→true активных флагов кабинета
 */
export const visibleBlockCTabs = (flags = {}) =>
    blockCTabs.filter((t) => !t.flag || flags?.[t.flag] === true);

/**
 * Контент активной вкладки Блока C по её ключу, либо null если ключ не из Блока C
 * или вкладка скрыта флагом. MiniAppShell вызывает это для активного таба, не входящего
 * в базовый switch.
 * @param {string} key — активный tab key
 * @param {object} ctx — контекст шелла, прокидываемый в render
 * @param {object} flags — карта активных флагов (для гейтинга показа)
 */
export const blockCTabRender = (key, ctx, flags = {}) => {
    const tab = visibleBlockCTabs(flags).find((t) => t.key === key);
    return tab ? tab.render(ctx) : null;
};
