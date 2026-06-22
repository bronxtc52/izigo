'use client';
import React, { useEffect, useState } from 'react';
import { Row, Col, Card, Statistic, Spin, Result } from 'antd';
import * as api from '@/views/admin/webApi';
import { usd } from './format';

/** Дашборд: KPI по сети и финансам компании. */
const Dashboard = () => {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [forbidden, setForbidden] = useState(false);

    useEffect(() => {
        (async () => {
            const res = await api.fetchDashboard();
            if (api.isForbidden(res)) { setForbidden(true); setLoading(false); return; }
            setData(res?.data ?? null);
            setLoading(false);
        })();
    }, []);

    if (loading) return <Spin style={{ display: 'block', margin: '60px auto' }} />;
    if (forbidden) return <Result status="403" title="Недостаточно прав" />;
    if (!data) return <Result status="warning" title="Нет данных" />;

    return (
        <Row gutter={[16, 16]}>
            <Col xs={12} md={6}><Card><Statistic title="Участников" value={data.members_total} /></Card></Col>
            <Col xs={12} md={6}><Card><Statistic title="Активных" value={data.members_active} /></Card></Col>
            <Col xs={12} md={6}><Card><Statistic title="Заявок на вывод" value={data.withdrawals_pending} /></Card></Col>
            <Col xs={12} md={6}><Card><Statistic title="В очереди на вывод" value={usd(data.withdrawals_pending_amount_cents)} /></Card></Col>
            <Col xs={12} md={8}><Card><Statistic title="Выручка (продажи)" value={usd(data.company_sales_revenue_cents)} /></Card></Col>
            <Col xs={12} md={8}><Card><Statistic title="Начислено бонусов" value={usd(data.company_commission_expense_cents)} /></Card></Col>
            <Col xs={12} md={8}><Card><Statistic title="Выплачено" value={usd(data.company_payouts_paid_cents)} /></Card></Col>
        </Row>
    );
};

export default Dashboard;
