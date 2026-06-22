import { NextResponse } from 'next/server';

// Роутинг по хосту: один Next.js-апп обслуживает и Mini App (izigo.adarasoft.com),
// и веб-админку (admin.izigo.adarasoft.com).
//  - admin-хост: корень → /admin; /miniapp недоступен (→ /admin).
//  - обычный хост (прод izigo.*): /admin недоступен (→ /miniapp).
//  - localhost: пускаем всё (удобство локальной разработки).
export function middleware(request) {
    const host = request.headers.get('host') || '';
    const isAdminHost = host.startsWith('admin.');
    const isLocal = host.startsWith('localhost') || host.startsWith('127.0.0.1');
    const { pathname } = request.nextUrl;

    if (pathname.startsWith('/admin')) {
        if (isAdminHost || isLocal) return NextResponse.next();
        const url = request.nextUrl.clone();
        url.pathname = '/miniapp';
        return NextResponse.redirect(url);
    }

    if (isAdminHost) {
        if (pathname === '/' || pathname.startsWith('/miniapp')) {
            const url = request.nextUrl.clone();
            url.pathname = '/admin';
            return NextResponse.redirect(url);
        }
    }

    return NextResponse.next();
}

export const config = {
    matcher: ['/', '/admin/:path*', '/miniapp/:path*'],
};
