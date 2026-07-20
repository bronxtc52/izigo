'use client';
import React, { useEffect, useRef, useState } from 'react';
import { Card, Typography, Alert, Spin } from 'antd';
import { useRouter } from 'next/navigation';
import { clearToken, setRoles } from '@/views/admin/webApi';

// Имя бота для Telegram Login Widget (НЕ токен). Build-arg NEXT_PUBLIC_TG_BOT_USERNAME;
// fallback — бот IziGo (из TWA deep-link). Виджету нужно имя без @.
const BOT_USERNAME = process.env.NEXT_PUBLIC_TG_BOT_USERNAME || 'Izigopro_mlm_bot';

/**
 * Вход в веб-админку через Telegram Login Widget: виджет отдаёт подписанные данные →
 * same-origin POST на Next BFF (/api/v1/auth/telegram-login) → httpOnly-cookie с
 * запечатанным Sanctum-токеном (t1: токен в JS/localStorage больше не попадает).
 * Доступ только участникам с ролями (403).
 */
export default function AdminLoginPage() {
    const router = useRouter();
    const widgetRef = useRef(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        // Глобальный колбэк виджета: получает объект user с подписью hash.
        window.onTelegramAuth = async (user) => {
            setBusy(true);
            setError('');
            try {
                const res = await fetch('/api/v1/auth/telegram-login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(user),
                });
                const data = await res.json();
                if (!res.ok || data?.status !== 'ok') {
                    setError(res.status === 403 ? 'Нет доступа: у аккаунта нет админ-ролей.' : 'Не удалось войти.');
                    setBusy(false);
                    return;
                }
                // Cookie уже установлена ответом BFF (httpOnly). Токена в ответе нет by design.
                // clearToken — одноразовая зачистка legacy izigo_admin_token из localStorage.
                clearToken();
                setRoles(data.member?.roles ?? []);
                router.replace('/admin');
            } catch (e) {
                setError('Сеть недоступна, попробуйте ещё раз.');
                setBusy(false);
            }
        };

        // Вставляем скрипт виджета (рендерит кнопку «Войти через Telegram»).
        const script = document.createElement('script');
        script.src = 'https://telegram.org/js/telegram-widget.js?22';
        script.async = true;
        script.setAttribute('data-telegram-login', BOT_USERNAME);
        script.setAttribute('data-size', 'large');
        script.setAttribute('data-radius', '8');
        script.setAttribute('data-onauth', 'onTelegramAuth(user)');
        script.setAttribute('data-request-access', 'write');
        const node = widgetRef.current;
        node?.appendChild(script);

        return () => {
            if (node) node.innerHTML = '';
            delete window.onTelegramAuth;
        };
    }, [router]);

    return (
        <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', background: '#f5f6f8' }}>
            <Card style={{ width: 360, textAlign: 'center' }}>
                <Typography.Title level={3} style={{ marginTop: 0 }}>IziGo · Админка</Typography.Title>
                <Typography.Paragraph type="secondary">
                    Войдите через Telegram. Доступ — только аккаунтам с админ-ролями.
                </Typography.Paragraph>
                {error && <Alert type="error" message={error} style={{ marginBottom: 12 }} showIcon />}
                {busy ? <Spin /> : <div ref={widgetRef} style={{ display: 'flex', justifyContent: 'center' }} />}
            </Card>
        </div>
    );
}
