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

    // Прокидываем текущий путь в заголовок — root layout по нему исключает аналитику
    // на /admin даже на общем хосте/localhost (host-проверки на admin.* мало для dev).
    const passThrough = () => {
        const requestHeaders = new Headers(request.headers);
        requestHeaders.set('x-pathname', pathname);
        return NextResponse.next({ request: { headers: requestHeaders } });
    };

    // BFF-прокси админки (t1): /api/v1/* существует только для admin-хоста (и localhost).
    // Mini App ходит на бэк напрямую по абсолютному URL — коллизии нет, но глушим
    // поверхность: на прочих хостах эти пути отдают 404 (не редирект — это API, не страница).
    if (pathname.startsWith('/api/v1')) {
        if (isAdminHost || isLocal) return passThrough();
        return new NextResponse(null, { status: 404 });
    }

    if (pathname.startsWith('/admin')) {
        if (isAdminHost || isLocal) return passThrough();
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

    return passThrough();
}

export const config = {
    matcher: ['/', '/admin/:path*', '/miniapp/:path*', '/api/v1/:path*'],
};
