'use client';
import React, { useEffect, useState } from 'react';
import { Tabs, Table, Tag, Result } from 'antd';
import * as api from '@/views/admin/webApi';

const usd = (cents) => `$${((cents ?? 0) / 100).toLocaleString('ru-RU', { minimumFractionDigits: 2 })}`;

const PaymentsTab = () => {
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [forbidden, setForbidden] = useState(false);

    useEffect(() => {
        (async () => {
            const res = await api.fetchPayments();
            if (api.isForbidden(res)) { setForbidden(true); setLoading(false); return; }
            setRows(res?.data?.data ?? []);
            setLoading(false);
        })();
    }, []);

    if (forbidden) return <Result status="403" title="Недостаточно прав" />;

    const columns = [
        { title: 'ID', dataIndex: 'id' },
        { title: 'Партнёр', dataIndex: 'member_id', render: (v) => `#${v}` },
        { title: 'Назначение', dataIndex: 'purpose' },
        { title: 'Сумма', dataIndex: 'amount_cents', render: usd },
        { title: 'Статус', dataIndex: 'status', render: (v) => <Tag>{v}</Tag> },
        { title: 'Заказ', dataIndex: 'order_id', render: (v) => (v ? `#${v}` : '—') },
        { title: 'Ref', dataIndex: 'external_ref' },
    ];
    return <Table rowKey="id" loading={loading} columns={columns} dataSource={rows} size="small" />;
};

const AutoshipTab = () => {
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [forbidden, setForbidden] = useState(false);

    useEffect(() => {
        (async () => {
            const res = await api.fetchAutoship();
            if (api.isForbidden(res)) { setForbidden(true); setLoading(false); return; }
            setRows(res?.data?.data ?? []);
            setLoading(false);
        })();
    }, []);

    if (forbidden) return <Result status="403" title="Недостаточно прав" />;

    const columns = [
        { title: 'ID', dataIndex: 'id' },
        { title: 'Партнёр', dataIndex: 'member_id', render: (v) => `#${v}` },
        { title: 'Продукт', dataIndex: 'product_id' },
        { title: 'Интервал, дн', dataIndex: 'interval_days' },
        { title: 'След. списание', dataIndex: 'next_charge_at', render: (v) => (v ? new Date(v).toLocaleDateString('ru-RU') : '') },
        { title: 'Статус', dataIndex: 'status', render: (v) => <Tag>{v}</Tag> },
    ];
    return <Table rowKey="id" loading={loading} columns={columns} dataSource={rows} size="small" />;
};

/** Операции: платежи (приём) и autoship-подписки. */
const Operations = () => (
    <Tabs
        items={[
            { key: 'payments', label: 'Платежи', children: <PaymentsTab /> },
            { key: 'autoship', label: 'Autoship', children: <AutoshipTab /> },
        ]}
    />
);

export default Operations;
