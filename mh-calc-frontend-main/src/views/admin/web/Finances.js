'use client';
import React, { useEffect, useState } from 'react';
import { Table, InputNumber, Button, Space, Row, Col, Card, Statistic, Result, message } from 'antd';
import * as api from '@/views/admin/webApi';
import { usd } from './format';

/** Финансы: кошелёк выбранного партнёра + журнал проводок (ledger). owner/finance. */
const Finances = () => {
    const [memberId, setMemberId] = useState(null);
    const [wallet, setWallet] = useState(null);
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(false);
    const [forbidden, setForbidden] = useState(false);

    const loadLedger = async (mid) => {
        setLoading(true);
        const res = await api.fetchLedger(mid ? { member_id: mid } : {});
        if (api.isForbidden(res)) { setForbidden(true); setLoading(false); return; }
        setRows(res?.data?.data ?? []);
        setLoading(false);
    };

    useEffect(() => { loadLedger(null); /* eslint-disable-next-line */ }, []);

    const loadMember = async () => {
        if (!memberId) { setWallet(null); loadLedger(null); return; }
        const w = await api.fetchMemberWallet(undefined, memberId);
        if (w?.error) {
            message.error(w.error === 404 ? 'Партнёр не найден' : 'Ошибка');
            setWallet(null);
            setRows([]); // не показываем чужой ledger от прошлого запроса
            return;
        }
        setWallet(w?.data ?? null);
        loadLedger(memberId);
    };

    if (forbidden) return <Result status="403" title="Недостаточно прав" />;

    const columns = [
        { title: 'Время', dataIndex: 'created_at', render: (v) => (v ? new Date(v).toLocaleString('ru-RU') : '') },
        { title: 'Партнёр', dataIndex: 'member_id', render: (v) => (v ? `#${v}` : 'компания') },
        { title: 'Счёт', dataIndex: 'account_type' },
        { title: 'Сторона', dataIndex: 'direction' },
        { title: 'Сумма', dataIndex: 'amount_cents', render: usd },
        { title: 'Источник', render: (_, r) => `${r.source_type}${r.source_id ? ` #${r.source_id}` : ''}` },
    ];

    return (
        <Space direction="vertical" size={16} style={{ display: 'flex' }}>
            <Space>
                <InputNumber placeholder="ID партнёра" value={memberId} onChange={setMemberId} min={1} />
                <Button type="primary" onClick={loadMember}>Показать</Button>
                <Button onClick={() => { setMemberId(null); setWallet(null); loadLedger(null); }}>Сброс</Button>
            </Space>
            {wallet && (
                <Row gutter={16}>
                    <Col><Card size="small"><Statistic title="Доступно" value={usd(wallet.available_cents)} /></Card></Col>
                    <Col><Card size="small"><Statistic title="В холде" value={usd(wallet.held_cents)} /></Card></Col>
                    <Col><Card size="small"><Statistic title="Долг (clawback)" value={usd(wallet.clawback_debt_cents)} /></Card></Col>
                </Row>
            )}
            <Table rowKey="id" loading={loading} columns={columns} dataSource={rows} size="small" />
        </Space>
    );
};

export default Finances;
