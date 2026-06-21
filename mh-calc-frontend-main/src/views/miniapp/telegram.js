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
            setReady(true);

            const onTheme = () => setTheme({ ...tg.themeParams });
            tg.onEvent?.('themeChanged', onTheme);
            cleanup = () => tg.offEvent?.('themeChanged', onTheme);
        };

        attach();
        return () => cleanup();
    }, []);

    return { wa, initData, theme, ready };
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
