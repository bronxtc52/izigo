import { NextResponse } from 'next/server';
import {
    COOKIE_NAME, seal, cookieOptions, backendBase, isAllowedAdminHost,
} from '../../_lib/adminSession.mjs';

// BFF-логин веб-админки (t1-admin-cookie-auth): принимает payload Telegram Login Widget,
// server-side меняет его на Sanctum-токен у бэка (с обязательным X-Admin-Proxy-Key —
// амендмент A-t1) и запечатывает токен в httpOnly-cookie. Клиенту токен НЕ отдаётся.
export const dynamic = 'force-dynamic';

export async function POST(request) {
    if (!isAllowedAdminHost(request.headers.get('host'))) {
        return NextResponse.json({ status: 'error' }, { status: 404 });
    }

    const base = backendBase();
    if (!base || !process.env.ADMIN_COOKIE_SECRET) {
        // Мисконфиг контейнера (нет секрета/базы бэка) — честная 500, не тихий пропуск.
        return NextResponse.json({ status: 'error', message: 'BFF не сконфигурирован' }, { status: 500 });
    }

    let payload = null;
    try {
        payload = await request.json();
    } catch (e) {
        payload = null;
    }
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
        return NextResponse.json({ status: 'error' }, { status: 400 });
    }

    let upstream;
    try {
        upstream = await fetch(`${base}/api/v1/auth/telegram-login`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Admin-Proxy-Key': process.env.ADMIN_PROXY_KEY || '',
            },
            body: JSON.stringify(payload),
            cache: 'no-store',
        });
    } catch (e) {
        return NextResponse.json({ status: 'error', message: 'Бэкенд недоступен' }, { status: 502 });
    }

    let data = null;
    try {
        data = await upstream.json();
    } catch (e) {
        data = null;
    }

    if (!upstream.ok || data?.status !== 'ok' || !data?.token) {
        // 401/403 бэка транслируем как есть; cookie не ставим, токен наружу не отдаём.
        const status = upstream.ok ? 502 : upstream.status;
        return NextResponse.json({ status: 'error', message: data?.message ?? null }, { status });
    }

    const res = NextResponse.json({ status: 'ok', member: data.member ?? null });
    res.cookies.set(COOKIE_NAME, seal(data.token), cookieOptions());
    return res;
}
