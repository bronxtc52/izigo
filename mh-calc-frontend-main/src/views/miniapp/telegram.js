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
            bg: '#0E1621', surface: '#17212B', surface2: '#1C2A38',
            fg: '#F4F6F8', muted: '#92A2B4',
            accent: '#4F9BEF', accent2: '#62A8F2', onAccent: '#07121F',
            border: '#243240', success: '#4FC07A', warning: '#E0A93D', error: '#F2686C',
            shadow: '0 2px 10px rgba(0,0,0,.4)', radius: 14,
        }
        : {
            isDark: false,
            bg: '#EFF1F5', surface: '#FFFFFF', surface2: '#F5F7FA',
            fg: '#14181F', muted: '#5E6B7B',
            accent: '#1F6FD4', accent2: '#2B86E8', onAccent: '#FFFFFF',
            border: '#E4E8ED', success: '#1A9E55', warning: '#B97708', error: '#D23B40',
            shadow: '0 2px 8px rgba(20,30,55,.06)', radius: 14,
        };
}

/** themeParams/colorScheme Telegram → конфиг ConfigProvider antd (алгоритм + токены). */
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
            borderRadius: p.radius,
            fontFamily: "'Inter',-apple-system,BlinkMacSystemFont,system-ui,sans-serif",
        },
        components: {
            Card: { colorBgContainer: p.surface, colorBorderSecondary: p.border },
            List: { colorBorder: p.border },
        },
    };
}
