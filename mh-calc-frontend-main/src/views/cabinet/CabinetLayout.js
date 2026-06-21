'use client';
import React from 'react';
import { Layout, Menu } from 'antd';
import { usePathname, useRouter } from 'next/navigation';
import { useGlobalContext } from '@/common/GlobalContext';

const { Header, Content } = Layout;

const ITEMS = [
    { key: '/cabinet', label: 'Доход' },
    { key: '/cabinet/tree', label: 'Команда' },
    { key: '/cabinet/rank', label: 'Ранг' },
    { key: '/cabinet/profile', label: 'Профиль' },
];

/**
 * Каркас кабинета партнёра: верхнее меню разделов + выход + ссылка на калькулятор.
 */
const CabinetLayout = ({ children }) => {
    const pathname = usePathname();
    const router = useRouter();
    const { setUserToken, setShowAuth } = useGlobalContext();

    const logout = () => {
        if (typeof window !== 'undefined') localStorage.removeItem('userToken');
        setUserToken(false);
        setShowAuth(true);
    };

    const selected = ITEMS.map((i) => i.key)
        .filter((k) => (k === '/cabinet' ? pathname === k : pathname.startsWith(k)))
        .slice(-1);

    const menuItems = [
        ...ITEMS,
        { key: 'calculator', label: 'Калькулятор' },
        { key: 'logout', label: 'Выход', danger: true },
    ];

    const onClick = ({ key }) => {
        if (key === 'logout') return logout();
        if (key === 'calculator') return router.push('/');
        router.push(key);
    };

    return (
        <Layout style={{ minHeight: '100vh' }}>
            <Header style={{ display: 'flex', alignItems: 'center', background: '#fff', borderBottom: '1px solid #eee' }}>
                <div style={{ fontWeight: 700, marginRight: 24, fontSize: 18 }}>IziGo</div>
                <Menu
                    mode="horizontal"
                    selectedKeys={selected}
                    items={menuItems}
                    onClick={onClick}
                    style={{ flex: 1, borderBottom: 'none' }}
                />
            </Header>
            <Content style={{ padding: 24, maxWidth: 1100, margin: '0 auto', width: '100%' }}>
                {children}
            </Content>
        </Layout>
    );
};

export default CabinetLayout;
