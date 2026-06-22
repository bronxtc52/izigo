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
import notificationsNav from './notifications.nav';
import helpdeskNav from './helpdesk.nav';
import monitoringNav from './monitoring.nav';
import i18nNav from './i18n.nav';

// >>> Block C sections
export const blockCSections = [
    // C5 exports     — import exportsNav   from './exports.nav';     → exportsNav,
    monitoringNav, // C7 monitoring — outbox/планировщик (owner-only, read-only)
    featureFlagsNav, // C3 feature_flags (owner-only)
    helpdeskNav, // C2 helpdesk — тикеты поддержки (owner+support)
    notificationsNav, // C1 notifications — рассылки (owner+support)
    i18nNav, // C4 i18n — редактируемые переводы (owner-only)
];
// <<< Block C sections
