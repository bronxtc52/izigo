'use client';
import { useEffect, useState } from 'react';
import { theme as antdTheme } from 'antd';

/**
 * Доступ к Telegram WebApp SDK: init/expand, initData (для авторизации backend),
 * themeParams + colorScheme (для темизации). Вне Telegram возвращает пустой initData.
 */
export function useTelegram() {
    const [wa, setWa] = useState(null);
    const [initData, setInitData] = useState('');
    const [theme, setTheme] = useState({});
    const [scheme, setScheme] = useState(null); // 'light' | 'dark' | null
    // ready=true, когда SDK подключён ЛИБО поллинг исчерпан (вне Telegram). До этого
    // потребитель не должен решать «нет initData» — иначе ложный экран «Откройте через Telegram».
    const [ready, setReady] = useState(false);

    useEffect(() => {
        let cleanup = () => {};
        let tries = 0;

        const attach = () => {
            const tg = typeof window !== 'undefined' ? window.Telegram?.WebApp : null;
            if (!tg) {
                // SDK ещё не загрузился — поллинг (скрипт грузится асинхронно, afterInteractive).
                if (tries++ < 50) {
                    setTimeout(attach, 100);
                } else {
                    setReady(true); // SDK так и не появился → точно вне Telegram.
                }
                return;
            }
            tg.ready();
            tg.expand?.();
            setWa(tg);
            setInitData(tg.initData || '');
            setTheme(tg.themeParams || {});
            setScheme(tg.colorScheme || null);
            setReady(true);

            const onTheme = () => { setTheme({ ...tg.themeParams }); setScheme(tg.colorScheme || null); };
            tg.onEvent?.('themeChanged', onTheme);
            cleanup = () => tg.offEvent?.('themeChanged', onTheme);
        };

        attach();
        return () => cleanup();
    }, []);

    return { wa, initData, theme, scheme, ready };
}

/** Тёмная ли тема: по colorScheme Telegram, иначе по яркости bg_color, иначе светлая. */
function pickDark(theme, scheme) {
    if (scheme === 'dark') return true;
    if (scheme === 'light') return false;
    const hex = theme?.bg_color;
    if (typeof hex === 'string' && /^#?[0-9a-f]{6}$/i.test(hex.replace('#', ''))) {
        const h = hex.replace('#', '');
        const [r, g, b] = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16) / 255);
        // относительная яркость (sRGB) → тёмная, если < 0.5
        return 0.2126 * r + 0.7152 * g + 0.0722 * b < 0.5;
    }
    return false;
}

/**
 * Палитра Mini App — визуальный язык **Aurora** (crypto-premium), контраст WCAG AA.
 * Светлая/тёмная по теме Telegram. Источник истины для antd-токенов и кастомных элементов
 * (radial-фон, hero, таб-бар). Градиентные поля (scrbg/heroBg/balGrad/primBg…) — для акцентов
 * на hero / балансе / CTA / прогрессе; surface держим glassy в dark и solid в light.
 */
export function miniAppPalette(theme, scheme) {
    const isDark = pickDark(theme, scheme);
    return isDark
        ? {
            isDark: true,
            bg: '#090C16', surface: 'rgba(255,255,255,0.045)', surface2: 'rgba(255,255,255,0.06)',
            sheet: '#141A2B', fg: '#E9ECF8', muted: '#9AA3C7',
            // accent — активные вкладки/ссылки; CTA primary — градиент primBg (см. components/экраны).
            accent: '#8EE6FF', accent2: '#A78BFA', onAccent: '#0A0A16',
            tabInactive: '#5F6890',
            border: 'rgba(255,255,255,0.09)', success: '#6FE0AE', warning: '#E6C06A', error: '#F0635E',
            pos: '#6FE9FF', ghostBg: 'rgba(255,255,255,0.06)',
            // Aurora-градиенты и свечения.
            scrbg: 'radial-gradient(125% 70% at 50% -8%, #1B1547 0%, #0B0F1D 46%, #090C16 100%)',
            heroBg: 'linear-gradient(165deg, rgba(139,92,246,.22), rgba(34,211,238,.05) 60%, rgba(139,92,246,0))',
            heroBorder: 'rgba(139,92,246,.35)',
            heroGlow: '0 0 44px -12px rgba(124,58,237,.55) inset, 0 18px 40px -22px rgba(0,0,0,.7)',
            balGrad: 'linear-gradient(92deg,#C9B8FF,#6FE9FF)',
            primBg: 'linear-gradient(92deg,#A78BFA,#5EE3F5)', primTxt: '#0A0A16',
            primGlow: '0 8px 22px -8px rgba(124,58,237,.8)',
            scrim: 'rgba(5,7,14,.62)',
            shadow: '0 18px 40px -22px rgba(0,0,0,.7)', radius: 16,
        }
        : {
            isDark: false,
            bg: '#EFF1F9', surface: '#FFFFFF', surface2: '#F1EFFC',
            sheet: '#FFFFFF', fg: '#161A40', muted: '#6B7194',
            accent: '#6D28D9', accent2: '#7C3AED', onAccent: '#FFFFFF',
            tabInactive: '#9AA0BE',
            border: '#E7E4F6', success: '#0E7C5A', warning: '#9A6700', error: '#D33A36',
            pos: '#0E7C5A', ghostBg: '#F1EFFC',
            scrbg: 'radial-gradient(125% 70% at 50% -8%, #E9E4FF 0%, #F1F2FC 48%, #EFF1F9 100%)',
            heroBg: 'linear-gradient(165deg, rgba(124,58,237,.12), rgba(8,145,178,.07) 60%, rgba(124,58,237,0))',
            heroBorder: 'rgba(124,58,237,.24)',
            heroGlow: '0 12px 30px -16px rgba(124,58,237,.4)',
            balGrad: 'linear-gradient(92deg,#6D28D9,#0E7490)',
            primBg: 'linear-gradient(92deg,#7C3AED,#2563EB)', primTxt: '#FFFFFF',
            primGlow: '0 8px 20px -8px rgba(124,58,237,.5)',
            scrim: 'rgba(20,22,50,.4)',
            shadow: '0 12px 30px -18px rgba(80,60,120,.4)', radius: 16,
        };
}

// Системный шрифт Telegram для UI; крупные цифры/заголовки — Manrope (next/font, var --font-manrope).
const UI_FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,system-ui,sans-serif";
// Aurora: фиксированный фиолетовый primary + белый текст в ОБЕИХ темах (контраст AA). Градиентные
// CTA (hero/«Вывести»/«Оплатить») рендерим кастомно поверх primBg на экранах (PR2).
const PRIMARY_BTN = '#7C3AED';

/** themeParams/colorScheme Telegram → конфиг ConfigProvider antd (алгоритм + токены handoff). */
export function antdThemeFromTelegram(theme, scheme) {
    const p = miniAppPalette(theme, scheme);
    return {
        algorithm: p.isDark ? antdTheme.darkAlgorithm : antdTheme.defaultAlgorithm,
        token: {
            colorPrimary: p.accent,
            colorBgBase: p.bg,
            colorBgLayout: p.bg,
            colorBgContainer: p.surface,
            // Elevated (Modal/Drawer/Select-dropdown) — НЕпрозрачный sheet, иначе glassy-surface
            // в dark просвечивает контент под модалкой.
            colorBgElevated: p.sheet,
            colorText: p.fg,
            colorTextSecondary: p.muted,
            colorBorder: p.border,
            colorBorderSecondary: p.border,
            colorSuccess: p.success,
            colorWarning: p.warning,
            colorError: p.error,
            borderRadius: 16,
            fontFamily: UI_FONT,
        },
        components: {
            // Кнопка primary — фиксированный фиолетовый (Aurora) с белым текстом в обеих темах (AA).
            Button: { colorPrimary: PRIMARY_BTN, colorPrimaryHover: '#6D28D9', colorPrimaryActive: '#5B21B6', borderRadius: 11 },
            Card: { colorBgContainer: p.surface, colorBorderSecondary: p.border, borderRadiusLG: 16 },
            List: { colorBorder: p.border },
            Segmented: { borderRadius: 10 },
        },
    };
}
