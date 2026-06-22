'use client';
import React, { useMemo, useState } from 'react';
import { Layout, Menu, Button, Typography, Grid } from 'antd';
import { useRouter } from 'next/navigation';
import * as webApi from '@/views/admin/webApi';
import AdminWithdrawals from '@/views/admin/AdminWithdrawals';
import Dashboard from './Dashboard';
import Users from './Users';
import Genealogy from './Genealogy';
import MarketingPlan from './MarketingPlan';
import Finances from './Finances';
import Reports from './Reports';
import Operations from './Operations';
import Products from './Products';
import Orders from './Orders';
import Kyc from './Kyc';
import AuditLog from './AuditLog';

const SECTIONS = [
    { key: 'dashboard', label: 'Дашборд', roles: ['owner', 'finance', 'support'], render: () => <Dashboard /> },
    { key: 'users', label: 'Пользователи', roles: ['owner', 'finance', 'support', 'leader'], render: () => <Users /> },
    { key: 'genealogy', label: 'Генеалогия', roles: ['owner', 'finance', 'support'], render: () => <Genealogy /> },
    { key: 'plan', label: 'Маркетинг-план', roles: ['owner', 'finance', 'support'], render: () => <MarketingPlan /> },
    { key: 'withdrawals', label: 'Выплаты', roles: ['owner', 'finance'], render: () => <AdminWithdrawals creds={webApi.getToken()} api={webApi} /> },
    { key: 'finances', label: 'Финансы', roles: ['owner', 'finance'], render: () => <Finances /> },
    { key: 'reports', label: 'Отчёты', roles: ['owner', 'finance', 'support'], render: () => <Reports /> },
    { key: 'operations', label: 'Операции', roles: ['owner', 'finance', 'support'], render: () => <Operations /> },
    { key: 'products', label: 'Продукты', roles: ['owner', 'support'], render: () => <Products /> },
    { key: 'orders', label: 'Заказы', roles: ['owner', 'support'], render: () => <Orders /> },
    { key: 'kyc', label: 'KYC', roles: ['owner', 'finance'], render: () => <Kyc /> },
    { key: 'audit', label: 'Аудит', roles: ['owner'], render: () => <AuditLog /> },
];

/** Каркас веб-админки: сайдбар (по ролям), хедер с выходом, активная секция. */
const WebAdminShell = () => {
    const router = useRouter();
    const screens = Grid.useBreakpoint();
    const roles = useMemo(() => webApi.getRoles(), []);
    const allowed = useMemo(
        () => SECTIONS.filter((s) => roles.includes('owner') || s.roles.some((r) => roles.includes(r))),
        [roles],
    );
    const [active, setActive] = useState(allowed[0]?.key ?? 'dashboard');

    const logout = () => { webApi.clearToken(); router.replace('/admin/login'); };
    const section = allowed.find((s) => s.key === active) ?? allowed[0];

    return (
        <Layout style={{ minHeight: '100vh' }}>
            <Layout.Sider breakpoint="lg" collapsedWidth={0} width={210} theme="light" style={{ borderRight: '1px solid #f0f0f0' }}>
                <div style={{ padding: 16, fontWeight: 700, fontSize: 18 }}>IziGo</div>
                <Menu
                    mode="inline"
                    selectedKeys={[active]}
                    onClick={(e) => setActive(e.key)}
                    items={allowed.map((s) => ({ key: s.key, label: s.label }))}
                />
            </Layout.Sider>
            <Layout>
                <Layout.Header style={{ background: '#fff', borderBottom: '1px solid #f0f0f0', display: 'flex', alignItems: 'center', justifyContent: 'space-between', paddingInline: 16 }}>
                    <Typography.Text strong>{section?.label}</Typography.Text>
                    <Button onClick={logout}>Выйти</Button>
                </Layout.Header>
                <Layout.Content style={{ padding: screens.md ? 24 : 12 }}>
                    {section?.render()}
                </Layout.Content>
            </Layout>
        </Layout>
    );
};

export default WebAdminShell;
