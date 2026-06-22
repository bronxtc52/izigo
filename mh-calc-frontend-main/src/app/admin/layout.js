'use client';
import React, { useEffect, useState } from 'react';
import { ConfigProvider, Spin } from 'antd';
import { usePathname, useRouter } from 'next/navigation';
import { getToken } from '@/views/admin/webApi';

// Веб-админка (admin.izigo.adarasoft.com): гейт по Sanctum-токену. Без токена → /admin/login.
// Страница логина проходит без гейта (иначе цикл редиректов). antd-тема — локально для админки.
export default function AdminLayout({ children }) {
    const pathname = usePathname();
    const router = useRouter();
    const isLogin = pathname === '/admin/login';
    const [ready, setReady] = useState(false);

    useEffect(() => {
        if (isLogin) { setReady(true); return; }
        if (!getToken()) { router.replace('/admin/login'); return; }
        setReady(true);
    }, [isLogin, pathname, router]);

    return (
        <ConfigProvider theme={{ token: { colorPrimary: '#2f6fed', borderRadius: 8 } }}>
            {ready ? children : <Spin style={{ display: 'block', margin: '80px auto' }} />}
        </ConfigProvider>
    );
}
