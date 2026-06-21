'use client';
import React, { useEffect, useState } from 'react';
import { Card, Statistic, Button, Table, Spin, Row, Col, Tag, message, Space } from 'antd';
import { useGlobalContext } from '@/common/GlobalContext';
import { fetchDashboard, fetchMe, activatePackage, handleAuthError, PACKAGES } from './api';

const TYPE_LABEL = { binary: 'Бинарный', referral: 'Реферальный', leader: 'Лидерский', rank: 'Ранговый' };

const Dashboard = () => {
    const { userToken, lang, currency, setUserToken, setShowAuth } = useGlobalContext();
    const [dash, setDash] = useState(null);
    const [me, setMe] = useState(null);
    const [loading, setLoading] = useState(true);
    const [activating, setActivating] = useState(false);

    const onUnauthorized = () => {
        if (typeof window !== 'undefined') localStorage.removeItem('userToken');
        setUserToken(false);
        setShowAuth(true);
    };

    const load = async () => {
        const [d, m] = await Promise.all([
            fetchDashboard(userToken, lang, currency),
            fetchMe(userToken, lang, currency),
        ]);
        if (handleAuthError([d, m], onUnauthorized)) return;
        setDash(d?.data ?? null);
        setMe(m?.data?.member ?? null);
        setLoading(false);
    };

    useEffect(() => {
        if (userToken) load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [userToken]);

    const onActivate = async (packageId) => {
        setActivating(true);
        try {
            await activatePackage(userToken, packageId);
            message.success('Пакет активирован');
            await load();
        } catch (e) {
            message.error('Не удалось активировать пакет');
        } finally {
            setActivating(false);
        }
    };

    if (loading) return <Spin size="large" style={{ display: 'block', margin: '80px auto' }} />;

    const byType = dash?.by_type ?? {};
    const lines = dash?.lines ?? [];

    const columns = [
        { title: 'Тип', dataIndex: 'type', render: (t) => <Tag>{TYPE_LABEL[t] ?? t}</Tag> },
        { title: 'Сумма, $', dataIndex: 'amount' },
        {
            title: 'Логика расчёта',
            dataIndex: 'basis',
            render: (b) => {
                if (!b) return '—';
                const parts = [];
                if (b.level != null) parts.push(`уровень ${b.level}`);
                if (b.sourceId != null) parts.push(`от участника #${b.sourceId}`);
                return parts.join(', ') || '—';
            },
        },
        {
            title: 'Дата',
            dataIndex: 'calculated_at',
            render: (d) => (d ? new Date(d).toLocaleString() : '—'),
        },
    ];

    return (
        <div>
            <Row gutter={16} style={{ marginBottom: 24 }}>
                <Col xs={24} md={8}>
                    <Card>
                        <Statistic title="Всего начислено" value={dash?.total ?? '0.00'} prefix="$" />
                    </Card>
                </Col>
                {Object.entries(TYPE_LABEL).map(([key, label]) => (
                    <Col xs={12} md={4} key={key}>
                        <Card>
                            <Statistic title={label} value={byType[key] ?? '0.00'} prefix="$" valueStyle={{ fontSize: 16 }} />
                        </Card>
                    </Col>
                ))}
            </Row>

            <Card title="Активация пакета" style={{ marginBottom: 24 }}>
                <Space wrap>
                    {PACKAGES.map((p) => {
                        const active = me?.package_id === p.id;
                        return (
                            <Button
                                key={p.id}
                                type={active ? 'default' : 'primary'}
                                disabled={active || activating}
                                loading={activating}
                                onClick={() => onActivate(p.id)}
                            >
                                {p.name} — {p.pv} PV {active ? '(активен)' : `· $${p.price}`}
                            </Button>
                        );
                    })}
                </Space>
                {me?.status === 'registered' && (
                    <div style={{ marginTop: 12, color: '#999' }}>
                        Активируйте пакет, чтобы начать зарабатывать бонусы (мок-оплата).
                    </div>
                )}
            </Card>

            <Card title="История начислений">
                <Table
                    rowKey={(_, i) => i}
                    dataSource={lines}
                    columns={columns}
                    pagination={{ pageSize: 10 }}
                    locale={{ emptyText: 'Пока нет начислений' }}
                />
            </Card>
        </div>
    );
};

export default Dashboard;
