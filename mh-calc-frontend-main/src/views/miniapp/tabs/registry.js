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

// >>> Block C tabs
export const blockCTabs = [
    // C1 notifications — inbox; C2 helpdesk — tickets; C6 copartners — profile-extra
    // import inboxTab from './notifications.tab'; → inboxTab,
];
// <<< Block C tabs

/**
 * Контент активной вкладки Блока C по её ключу, либо null если ключ не из Блока C.
 * MiniAppShell вызывает это для активного таба, не входящего в базовый switch.
 * @param {string} key — активный tab key
 * @param {object} ctx — контекст шелла, прокидываемый в render
 */
export const blockCTabRender = (key, ctx) => {
    const tab = blockCTabs.find((t) => t.key === key);
    return tab ? tab.render(ctx) : null;
};
