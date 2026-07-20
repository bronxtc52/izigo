'use client';
import React, { useCallback, useEffect, useState } from 'react';
import { Tabs, Table, Tag, Result, Switch, Space, Button, Tooltip, message } from 'antd';
import * as api from '@/views/admin/webApi';
import { usd } from './format';

const PaymentsTab = () => {
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [forbidden, setForbidden] = useState(false);
    const [pollProblemOnly, setPollProblemOnly] = useState(false);
    const [recheckingId, setRecheckingId] = useState(null);

    const load = useCallback(async (problemOnly) => {
        setLoading(true);
        const res = await api.fetchPayments(undefined, problemOnly ? { poll_problem: 1 } : {});
        if (api.isForbidden(res)) { setForbidden(true); setLoading(false); return; }
        setRows(res?.data?.data ?? []);
        setLoading(false);
    }, []);

    useEffect(() => { load(pollProblemOnly); }, [load, pollProblemOnly]);

    if (forbidden) return <Result status="403" title="Недостаточно прав" />;

    // Recheck (RBAC owner/finance): 403 показываем сообщением, список не ломаем.
    const recheck = async (id) => {
        setRecheckingId(id);
        try {
            const data = await api.recheckPayment(undefined, id);
            message.success(`Платёж #${id}: опрос — ${data?.poll ?? '?'}, статус — ${data?.payment_status ?? '?'}`);
        } catch (e) {
            message.error(e?.status === 403 ? 'Недостаточно прав (owner/finance)' : `Ошибка recheck (${e?.status ?? '—'})`);
        }
        setRecheckingId(null);
        load(pollProblemOnly);
    };

    const columns = [
        { title: 'ID', dataIndex: 'id' },
        { title: 'Партнёр', dataIndex: 'member_id', render: (v) => `#${v}` },
        { title: 'Назначение', dataIndex: 'purpose' },
        { title: 'Сумма', dataIndex: 'amount_cents', render: usd },
        { title: 'Статус', dataIndex: 'status', render: (v) => <Tag>{v}</Tag> },
        { title: 'Заказ', dataIndex: 'order_id', render: (v) => (v ? `#${v}` : '—') },
        { title: 'Ref', dataIndex: 'external_ref' },
        {
            title: 'Опрос',
            dataIndex: 'last_poll_result',
            render: (v, row) => (row.poll_problem ? (
                <Tooltip title={`Ошибок подряд: ${row.poll_error_streak}; последний опрос: ${row.last_polled_at ? new Date(row.last_polled_at).toLocaleString('ru-RU') : '—'}`}>
                    <Tag color="warning">проблемный ({row.poll_error_streak})</Tag>
                </Tooltip>
            ) : (v ?? '—')),
        },
        {
            title: '',
            key: 'actions',
            render: (_, row) => (['pending', 'expired'].includes(row.status) ? (
                <Button size="small" loading={recheckingId === row.id} onClick={() => recheck(row.id)}>
                    Recheck
                </Button>
            ) : null),
        },
    ];
    return (
        <Space direction="vertical" style={{ width: '100%' }}>
            <Space>
                <Switch checked={pollProblemOnly} onChange={setPollProblemOnly} />
                <span>Только проблемный опрос</span>
            </Space>
            <Table rowKey="id" loading={loading} columns={columns} dataSource={rows} size="small" />
        </Space>
    );
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
