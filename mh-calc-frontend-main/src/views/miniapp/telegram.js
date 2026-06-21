'use client';
import { useEffect, useState } from 'react';

/**
 * Доступ к Telegram WebApp SDK: init/expand, initData (для авторизации backend),
 * themeParams (для темизации antd). Вне Telegram возвращает пустой initData.
 */
export function useTelegram() {
    const [wa, setWa] = useState(null);
    const [initData, setInitData] = useState('');
    const [theme, setTheme] = useState({});

    useEffect(() => {
        let cleanup = () => {};
        let tries = 0;

        const attach = () => {
            const tg = typeof window !== 'undefined' ? window.Telegram?.WebApp : null;
            if (!tg) {
                // SDK ещё не загрузился — короткий поллинг (скрипт грузится асинхронно).
                if (tries++ < 20) setTimeout(attach, 100);
                return;
            }
            tg.ready();
            tg.expand?.();
            setWa(tg);
            setInitData(tg.initData || '');
            setTheme(tg.themeParams || {});

            const onTheme = () => setTheme({ ...tg.themeParams });
            tg.onEvent?.('themeChanged', onTheme);
            cleanup = () => tg.offEvent?.('themeChanged', onTheme);
        };

        attach();
        return () => cleanup();
    }, []);

    return { wa, initData, theme };
}

/** themeParams Telegram → токены темы antd. */
export function antdThemeFromTelegram(theme) {
    return {
        token: {
            colorPrimary: theme?.button_color || '#2ea6ff',
            colorBgBase: theme?.bg_color || undefined,
            colorTextBase: theme?.text_color || undefined,
        },
    };
}
