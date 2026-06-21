'use client';
import React from 'react';
import { Layout, Menu } from 'antd';
import { usePathname, useRouter } from 'next/navigation';
import { useGlobalContext } from '@/common/GlobalContext';

const { Header, Sider, Content } = Layout;

const ITEMS = [
    { key: '/admin', label: 'Участники' },
    { key: '/admin/plan', label: 'Маркетинг-план' },
];

/**
 * Каркас админ-портала: левый сайдбар разделов + контент. Доступ ограничен
 * ролями на backend; здесь только навигация. Визуал — под макет open-design.
 */
const AdminLayout = ({ children }) => {
    const pathname = usePathname();
    const router = useRouter();
    const { setUserToken, setShowAuth } = useGlobalContext();

    const logout = () => {
        if (typeof window !== 'undefined') localStorage.removeItem('userToken');
        setUserToken(false);
        setShowAuth(true);
    };

    const selected = ITEMS.map((i) => i.key)
        .filter((k) => (k === '/admin' ? pathname === k || pathname.startsWith('/admin/members') : pathname.startsWith(k)))
        .slice(-1);

    const onClick = ({ key }) => {
        if (key === 'logout') return logout();
        if (key === 'cabinet') return router.push('/cabinet');
        router.push(key);
    };

    return (
        <Layout style={{ minHeight: '100vh' }}>
            <Sider breakpoint="lg" collapsedWidth="0" theme="light" style={{ borderRight: '1px solid #eee' }}>
                <div style={{ fontWeight: 700, fontSize: 18, padding: '16px 24px' }}>IziGo · Админ</div>
                <Menu
                    mode="inline"
                    selectedKeys={selected}
                    onClick={onClick}
                    items={[
                        ...ITEMS,
                        { type: 'divider' },
                        { key: 'cabinet', label: 'Кабинет' },
                        { key: 'logout', label: 'Выход', danger: true },
                    ]}
                />
            </Sider>
            <Layout>
                <Header style={{ background: '#fff', borderBottom: '1px solid #eee', paddingLeft: 24, fontWeight: 600 }}>
                    Администрирование
                </Header>
                <Content style={{ padding: 24 }}>{children}</Content>
            </Layout>
        </Layout>
    );
};

export default AdminLayout;
