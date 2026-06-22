// Block C — registry секций веб-админки.
//
// Назначение: 7 фич Блока C добавляют свои пункты меню админки, НЕ редактируя общий
// WebAdminShell.js (горячий файл, источник merge-конфликтов). Каждая фича создаёт
// рядом файл `<feature>.nav.js`, экспортирующий объект-секцию того же формата, что и
// SECTIONS в WebAdminShell.js:
//
//   { key, label, roles: ['owner', ...], render: () => <FeatureScreen /> }
//
// и регистрирует его, добавив одну строку в массив ниже (внутри маркеров Block C,
// чтобы merge-train разрешал конфликты тривиально). Порядок ролей соблюдает RBAC
// бэка (owner проходит всегда). Пустой массив => меню админки визуально не меняется.

import featureFlagsNav from './feature_flags.nav';

// >>> Block C sections
export const blockCSections = [
    // C7 monitoring  — import monitoringNav from './monitoring.nav'; → monitoringNav,
    // C5 exports     — import exportsNav   from './exports.nav';     → exportsNav,
    featureFlagsNav, // C3 feature_flags (owner-only)
    // C2 helpdesk    — import helpdeskNav  from './helpdesk.nav';    → helpdeskNav,
    // C1 notifications — рассылки owner+support
    // (раскомментировать импорт сверху файла при добавлении секции)
];
// <<< Block C sections
