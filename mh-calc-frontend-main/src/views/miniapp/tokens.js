'use client';

// Семантические tint-палитры бейджей (handoff): насыщенный ТЕКСТ на мягком ФОНЕ,
// не цвет-по-цвету. Гарантируют контраст WCAG AA в светлой и тёмной теме.
// Формат значения: { color, bg }.

const TINTS = {
    blue: { light: { color: '#1D4ED8', bg: '#E8F0FE' }, dark: { color: '#7FB3F5', bg: '#1B2C44' } },
    green: { light: { color: '#0E7C5A', bg: '#E2F4EF' }, dark: { color: '#4FC79A', bg: '#16322A' } },
    purple: { light: { color: '#5B45C9', bg: '#EDEAFB' }, dark: { color: '#A99BF0', bg: '#272148' } },
    amber: { light: { color: '#9A6700', bg: '#FBEFD9' }, dark: { color: '#E0B050', bg: '#352B19' } },
    neutral: { light: { color: '#54606F', bg: '#EEF1F5' }, dark: { color: '#A9B6C4', bg: '#222D3A' } },
};

/** Базовый tint по «цвету» и теме. */
export const tint = (kind, isDark) => (TINTS[kind] ?? TINTS.neutral)[isDark ? 'dark' : 'light'];

// Маппинг доменных сущностей на цвета (handoff §Screens / §tint-палитры).
const BONUS_KIND = { binary: 'blue', referral: 'green', leader: 'purple', rank: 'amber' };
const ROLE_KIND = { owner: 'purple', finance: 'green', leader: 'blue', support: 'amber', partner: 'neutral' };

/** Tint для типа бонуса (binary/referral/leader/rank). */
export const bonusTint = (type, isDark) => tint(BONUS_KIND[type] ?? 'neutral', isDark);

/** Tint для роли (owner/finance/leader/support/partner). */
export const roleTint = (role, isDark) => tint(ROLE_KIND[role] ?? 'neutral', isDark);

/** Tint для статуса участника: активен=зелёный, иначе(нов)=синий. */
export const statusTint = (status, isDark) => tint(status === 'active' ? 'green' : 'blue', isDark);

// Цвета «точек» (dot) перед типом бонуса — насыщенные, по теме.
const BONUS_DOT = {
    binary: { light: '#2563EB', dark: '#5AA2F0' },
    referral: { light: '#0E9E6E', dark: '#3FBE84' },
    leader: { light: '#6D5BD0', dark: '#A99BF0' },
    rank: { light: '#C77700', dark: '#E0B050' },
};

/** Цвет точки бонуса по типу и теме. */
export const bonusDot = (type, isDark) => (BONUS_DOT[type] ?? BONUS_DOT.binary)[isDark ? 'dark' : 'light'];

/** Стиль Manrope для крупных цифр/заголовков (next/font переменная + табличные цифры). */
export const numFont = { fontFamily: "var(--font-manrope), -apple-system, system-ui, sans-serif", fontFeatureSettings: "'tnum'" };
