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
 * Палитра Mini App (макет open-design, контраст WCAG AA). Светлая/тёмная выбираются
 * по теме Telegram. Используется как для antd-токенов, так и для кастомных элементов
 * (таб-бар, фон), чтобы цвета НЕ перебивали фон и текст не сливался.
 */
export function miniAppPalette(theme, scheme) {
    const isDark = pickDark(theme, scheme);
    return isDark
        ? {
            isDark: true,
            bg: '#0E1621', surface: '#18222E', surface2: '#152434',
            fg: '#EAF0F6', muted: '#93A4B6',
            // accent — для активных вкладок/ссылок; кнопки primary фиксированы (#2563EB, см. components).
            accent: '#5AA2F0', accent2: '#7FB3F5', onAccent: '#FFFFFF',
            tabInactive: '#6F7E8E',
            border: '#243140', success: '#3FBE84', warning: '#E0B050', error: '#F0635E',
            shadow: '0 2px 10px rgba(0,0,0,.4)', radius: 14,
        }
        : {
            isDark: false,
            bg: '#EEF1F5', surface: '#FFFFFF', surface2: '#F1F6FE',
            fg: '#14213A', muted: '#6B7785',
            accent: '#2563EB', accent2: '#2B86E8', onAccent: '#FFFFFF',
            tabInactive: '#98A2AE',
            border: '#E7ECF2', success: '#0E9E6E', warning: '#C77700', error: '#D33A36',
            shadow: '0 1px 3px rgba(0,0,0,.05)', radius: 14,
        };
}

// Системный шрифт Telegram для UI; крупные цифры/заголовки — Manrope (next/font, var --font-manrope).
const UI_FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,system-ui,sans-serif";
// Кнопки primary: фиксированный #2563EB + белый текст в ОБЕИХ темах (контраст 4.9:1, WCAG AA).
const PRIMARY_BTN = '#2563EB';

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
            colorBgElevated: p.surface,
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
            // Кнопка primary — фиксированный синий с белым текстом в обеих темах (контраст AA).
            Button: { colorPrimary: PRIMARY_BTN, colorPrimaryHover: '#1D4ED8', colorPrimaryActive: '#1E40AF', borderRadius: 11 },
            Card: { colorBgContainer: p.surface, colorBorderSecondary: p.border, borderRadiusLG: 16 },
            List: { colorBorder: p.border },
            Segmented: { borderRadius: 10 },
        },
    };
}
