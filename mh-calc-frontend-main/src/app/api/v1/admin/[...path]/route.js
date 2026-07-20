import { NextResponse } from 'next/server';
import {
    COOKIE_NAME, unseal, clearCookieOptions, backendBase, isAllowedAdminHost, sameOriginViolation,
} from '../../_lib/adminSession.mjs';

// Generic BFF-прокси admin-неймспейса (t1-admin-cookie-auth): ТОЛЬКО
// /api/v1/admin/<path> → {BACKEND}/api/v1/admin/<path>. Распечатывает httpOnly-cookie →
// Authorization: Bearer server-side; тело ответа СТРИМИТСЯ (CSV-экспорт участника).
// Остальные пути бэка (/structure, /cabinet, ...) через прокси НЕ доступны — у них
// свои auth-потоки (initData), Mini App ходит на бэк напрямую.
export const dynamic = 'force-dynamic';

// Traversal-guard: сегмент пути — только безопасный алфавит; '..'/'.'/пустые/со
// слэшами и любой экзотикой не проходят (нормализация не нужна — не пропускаем вовсе).
const SEGMENT_RE = /^[A-Za-z0-9][A-Za-z0-9_.~-]*$/;

const unauthorized = () => {
    const res = NextResponse.json(
        { status: 'error', message: 'Требуется вход', need_login: true },
        { status: 401 },
    );
    // Токен отозван/протух/битая cookie → чистим cookie, UI уводит на /admin/login.
    res.cookies.set(COOKIE_NAME, '', clearCookieOptions());
    return res;
};

async function proxy(request, ctx) {
    if (!isAllowedAdminHost(request.headers.get('host'))) {
        return NextResponse.json({ status: 'error' }, { status: 404 });
    }

    const params = await ctx.params;
    const segments = Array.isArray(params?.path) ? params.path : [params?.path].filter(Boolean);
    if (
        segments.length === 0
        || segments.some((s) => typeof s !== 'string' || s === '.' || s === '..' || !SEGMENT_RE.test(s))
    ) {
        return NextResponse.json({ status: 'error' }, { status: 404 });
    }

    // CSRF defense-in-depth (поверх SameSite=Lax): не-GET только same-origin.
    if (sameOriginViolation(request)) {
        return NextResponse.json({ status: 'error', message: 'CSRF-проверка не пройдена' }, { status: 403 });
    }

    const token = unseal(request.cookies.get(COOKIE_NAME)?.value);
    if (!token) return unauthorized();

    const base = backendBase();
    if (!base) {
        return NextResponse.json({ status: 'error', message: 'BFF не сконфигурирован' }, { status: 500 });
    }

    const targetPath = segments.map(encodeURIComponent).join('/');
    const headers = {
        Authorization: `Bearer ${token}`,
        'X-Requested-With': 'XMLHttpRequest',
    };
    const contentType = request.headers.get('content-type');
    if (contentType) headers['Content-Type'] = contentType;
    const accept = request.headers.get('accept');
    if (accept) headers.Accept = accept;

    const method = request.method.toUpperCase();
    const init = { method, headers, cache: 'no-store' };
    if (method !== 'GET' && method !== 'HEAD') {
        // DELETE у нас тоже носит JSON-тело (revokeRole, deleteTranslationOverride).
        const body = await request.arrayBuffer();
        if (body && body.byteLength > 0) init.body = body;
    }

    let upstream;
    try {
        upstream = await fetch(`${base}/api/v1/admin/${targetPath}${request.nextUrl.search}`, init);
    } catch (e) {
        return NextResponse.json({ status: 'error', message: 'Бэкенд недоступен' }, { status: 502 });
    }

    if (upstream.status === 401) return unauthorized();

    const respHeaders = new Headers();
    for (const name of ['content-type', 'content-disposition', 'cache-control']) {
        const v = upstream.headers.get(name);
        if (v) respHeaders.set(name, v);
    }
    // Стримим тело как есть (JSON и CSV-экспорт одинаково), статус/заголовки транслируем.
    const res = new NextResponse(upstream.body, { status: upstream.status, headers: respHeaders });
    if (targetPath === 'auth/logout') {
        // Logout: бэк отозвал токен — дополнительно чистим cookie (webApi.logout не меняется).
        res.cookies.set(COOKIE_NAME, '', clearCookieOptions());
    }
    return res;
}

export {
    proxy as GET, proxy as POST, proxy as PUT, proxy as PATCH, proxy as DELETE,
};
