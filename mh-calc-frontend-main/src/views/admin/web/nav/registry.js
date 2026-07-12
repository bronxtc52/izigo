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
import refundsV2Nav from '../refunds/refunds-v2.nav';

// >>> Block C sections
export const blockCSections = [
    // C5 exports     — import exportsNav   from './exports.nav';     → exportsNav,
    monitoringNav, // C7 monitoring — outbox/планировщик (owner-only, read-only)
    featureFlagsNav, // C3 feature_flags (owner-only)
    helpdeskNav, // C2 helpdesk — тикеты поддержки (owner+support)
    notificationsNav, // C1 notifications — рассылки (owner+support)
    i18nNav, // C4 i18n — редактируемые переводы (owner-only)
    refundsV2Nav, // T12 mh-full-plan — возвраты/сторно V2 (owner+finance, flag mh_v2_refunds)
];
// <<< Block C sections

// Фильтр blockC-секций по карте фиче-флагов (deny-by-default). Секция показывается,
// только если у неё нет поля `flag` (напр. C3 «Фиче-флаги» — owner всегда) ИЛИ флаг
// явно включён (flags[flag] === true). Пустая/отсутствующая карта => все флаговые
// секции скрыты — базовое (до-Block-C) меню при этом не затрагивается (фильтруется
// только blockCSections, а не общий SECTIONS).
export const visibleBlockCSections = (flags = {}) =>
    blockCSections.filter((s) => !s.flag || flags?.[s.flag] === true);
