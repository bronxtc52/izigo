'use client';

// F2 (P1-hardening): boundary последней инстанции — ловит ошибки самого корневого layout.
// Рендерится ВНЕ всех провайдеров (заменяет <html>), поэтому всё self-contained:
// статичный двуязычный текст, инлайновые Aurora-цвета, жёсткая перезагрузка страницы.
import { useEffect } from 'react';
import * as Sentry from '@sentry/nextjs';

export default function GlobalError({ error }) {
    useEffect(() => {
        Sentry.captureException(error);
    }, [error]);

    return (
        <html lang="ru">
            <body style={{
                margin: 0, minHeight: '100vh', display: 'flex', flexDirection: 'column',
                alignItems: 'center', justifyContent: 'center', gap: 16, padding: 24,
                textAlign: 'center', background: '#0F1115', color: '#F2F3F7',
                fontFamily: "-apple-system, 'Segoe UI', Roboto, sans-serif",
            }}>
                <div style={{ fontSize: 40 }}>⚠️</div>
                <div style={{ fontSize: 17, fontWeight: 700 }}>
                    Что-то пошло не так
                    <span style={{ display: 'block', fontSize: 13, fontWeight: 400, opacity: 0.65, marginTop: 4 }}>
                        Something went wrong
                    </span>
                </div>
                <div style={{ fontSize: 13, opacity: 0.65, maxWidth: 340 }}>
                    Ошибка уже отправлена команде. Попробуйте перезагрузить приложение.
                    <span style={{ display: 'block', marginTop: 2 }}>
                        The error has been reported. Try reloading the app.
                    </span>
                </div>
                <button
                    onClick={() => window.location.reload()}
                    style={{
                        padding: '10px 28px', borderRadius: 12, border: 'none', cursor: 'pointer',
                        color: '#fff', fontSize: 14, fontWeight: 600,
                        background: 'linear-gradient(135deg, #7C3AED 0%, #8EE6FF 130%)',
                        boxShadow: '0 4px 18px rgba(124, 58, 237, 0.45)',
                    }}
                >
                    Перезагрузить · Reload
                </button>
            </body>
        </html>
    );
}
