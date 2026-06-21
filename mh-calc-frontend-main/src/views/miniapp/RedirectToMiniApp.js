'use client';
import React, { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { Result, Button } from 'antd';

/**
 * Браузерные роуты /cabinet и /admin (вне Telegram) больше не самостоятельные
 * поверхности: платформа доступна ТОЛЬКО через Telegram Mini App. Этот экран
 * редиректит на /miniapp и подсказывает открыть приложение через Telegram.
 */
const RedirectToMiniApp = () => {
    const router = useRouter();

    useEffect(() => {
        router.replace('/miniapp');
    }, [router]);

    return (
        <Result
            status="info"
            title="Откройте через Telegram"
            subTitle="Кабинет и админка доступны только в Telegram Mini App (авторизация по initData)."
            extra={<Button type="primary" onClick={() => router.replace('/miniapp')}>Открыть Mini App</Button>}
        />
    );
};

export default RedirectToMiniApp;
