'use client';

// F2 (P1-hardening): error boundary для всех сегментов под корневым layout.
// Ошибка рендера уходит в Sentry, пользователь видит экран с кнопкой перезапуска,
// а не белую страницу. Текст статичный двуязычный: boundary может сработать
// до/вне инициализации i18n. Цвета — Aurora (инлайн: провайдеры темы недоступны).
import { useEffect } from 'react';
import * as Sentry from '@sentry/nextjs';

export default function Error({ error, reset }) {
    useEffect(() => {
        Sentry.captureException(error);
    }, [error]);

    return (
        <div style={{
            minHeight: '60vh', display: 'flex', flexDirection: 'column',
            alignItems: 'center', justifyContent: 'center', gap: 16,
            padding: 24, textAlign: 'center', fontFamily: 'inherit',
        }}>
            <div style={{ fontSize: 40 }}>⚠️</div>
            <div style={{ fontSize: 17, fontWeight: 700 }}>
                Что-то пошло не так
                <span style={{ display: 'block', fontSize: 13, fontWeight: 400, opacity: 0.65, marginTop: 4 }}>
                    Something went wrong
                </span>
            </div>
            <div style={{ fontSize: 13, opacity: 0.65, maxWidth: 340 }}>
                Ошибка уже отправлена команде. Попробуйте перезагрузить экран.
                <span style={{ display: 'block', marginTop: 2 }}>
                    The error has been reported. Try reloading the screen.
                </span>
            </div>
            <button
                onClick={() => reset()}
                style={{
                    padding: '10px 28px', borderRadius: 12, border: 'none', cursor: 'pointer',
                    color: '#fff', fontSize: 14, fontWeight: 600,
                    background: 'linear-gradient(135deg, #7C3AED 0%, #8EE6FF 130%)',
                    boxShadow: '0 4px 18px rgba(124, 58, 237, 0.45)',
                }}
            >
                Перезагрузить · Reload
            </button>
        </div>
    );
}
