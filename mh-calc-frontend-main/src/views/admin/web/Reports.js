'use client';
import React, { useEffect, useState } from 'react';
import { Tabs, Table, DatePicker, Button, Space, Row, Col, Card, Statistic, Result, Tag, Typography } from 'antd';
import * as api from '@/views/admin/webApi';
import { usd } from './format';

const { RangePicker } = DatePicker;

/** Период [from,to] → query-параметры YYYY-MM-DD (или пусто). */
const rangeParams = (range) =>
    range && range[0] && range[1]
        ? { from: range[0].format('YYYY-MM-DD'), to: range[1].format('YYYY-MM-DD') }
        : {};

/** Клиентский экспорт массива объектов в CSV-файл (без сторонних либ). */
const exportCsv = (filename, columns, rows) => {
    const esc = (v) => {
        const s = v == null ? '' : String(v);
        return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    };
    const head = columns.map((c) => esc(c.title)).join(',');
    const body = rows.map((r) => columns.map((c) => esc(c.value(r))).join(',')).join('\n');
    const blob = new Blob([`${head}\n${body}`], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
};

const Forbidden = () => <Result status="403" title="Недостаточно прав" />;

/** Балансы: партнёры с остатками кошелька + сводные итоги. owner/finance. */
const BalancesTab = () => {
    const [state, setState] = useState({ loading: true, rows: [], totals: null, forbidden: false });

    useEffect(() => {
        api.fetchReportBalances(undefined).then((res) => {
            if (api.isForbidden(res)) return setState({ loading: false, forbidden: true, rows: [], totals: null });
            setState({ loading: false, forbidden: false, rows: res?.data?.data ?? [], totals: res?.data?.totals ?? null });
        });
    }, []);

    if (state.forbidden) return <Forbidden />;

    const columns = [
        { title: 'ID', dataIndex: 'member_id', render: (v) => `#${v}` },
        { title: 'Имя', dataIndex: 'name' },
        { title: 'Статус', dataIndex: 'status' },
        { title: 'Доступно', dataIndex: 'available_cents', render: usd },
        { title: 'В холде', dataIndex: 'held_cents', render: usd },
        { title: 'Долг (clawback)', dataIndex: 'clawback_debt_cents', render: usd },
    ];

    const csv = () => exportCsv('balances.csv', [
        { title: 'member_id', value: (r) => r.member_id },
        { title: 'name', value: (r) => r.name },
        { title: 'status', value: (r) => r.status },
        { title: 'available_usd', value: (r) => (r.available_cents / 100).toFixed(2) },
        { title: 'held_usd', value: (r) => (r.held_cents / 100).toFixed(2) },
        { title: 'clawback_debt_usd', value: (r) => (r.clawback_debt_cents / 100).toFixed(2) },
    ], state.rows);

    return (
        <Space direction="vertical" size={16} style={{ display: 'flex' }}>
            {state.totals && (
                <Row gutter={16}>
                    <Col><Card size="small"><Statistic title="Доступно (всего)" value={usd(state.totals.available_cents)} /></Card></Col>
                    <Col><Card size="small"><Statistic title="В холде (всего)" value={usd(state.totals.held_cents)} /></Card></Col>
                    <Col><Card size="small"><Statistic title="Долг (всего)" value={usd(state.totals.clawback_debt_cents)} /></Card></Col>
                </Row>
            )}
            <Button onClick={csv} disabled={!state.rows.length}>Экспорт CSV</Button>
            <Table rowKey="member_id" loading={state.loading} columns={columns} dataSource={state.rows} size="small" />
        </Space>
    );
};

/** Пользователи: партнёры с рангом/пакетом/статусом + счётчики; фильтр по периоду регистрации. */
const UsersTab = () => {
    const [range, setRange] = useState(null);
    const [state, setState] = useState({ loading: true, rows: [], counts: null, forbidden: false });

    const load = () => {
        setState((s) => ({ ...s, loading: true }));
        api.fetchReportUsers(undefined, rangeParams(range)).then((res) => {
            if (api.isForbidden(res)) return setState({ loading: false, forbidden: true, rows: [], counts: null });
            setState({ loading: false, forbidden: false, rows: res?.data?.data ?? [], counts: res?.data?.counts ?? null });
        });
    };
    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    if (state.forbidden) return <Forbidden />;

    const columns = [
        { title: 'ID', dataIndex: 'member_id', render: (v) => `#${v}` },
        { title: 'Имя', dataIndex: 'name' },
        { title: 'Статус', dataIndex: 'status', render: (v) => <Tag color={v === 'active' ? 'green' : 'default'}>{v}</Tag> },
        { title: 'Ранг', dataIndex: 'rank', render: (v) => v ?? '—' },
        { title: 'Пакет', dataIndex: 'package', render: (v) => v ?? '—' },
        { title: 'Регистрация', dataIndex: 'created_at', render: (v) => (v ? new Date(v).toLocaleDateString('ru-RU') : '') },
    ];

    const csv = () => exportCsv('users.csv', [
        { title: 'member_id', value: (r) => r.member_id },
        { title: 'name', value: (r) => r.name },
        { title: 'status', value: (r) => r.status },
        { title: 'rank', value: (r) => r.rank ?? '' },
        { title: 'package', value: (r) => r.package ?? '' },
        { title: 'created_at', value: (r) => r.created_at ?? '' },
    ], state.rows);

    return (
        <Space direction="vertical" size={16} style={{ display: 'flex' }}>
            <Space wrap>
                <RangePicker value={range} onChange={setRange} />
                <Button type="primary" onClick={load}>Применить</Button>
                <Button onClick={() => { setRange(null); setTimeout(load, 0); }}>Сброс</Button>
                <Button onClick={csv} disabled={!state.rows.length}>Экспорт CSV</Button>
            </Space>
            {state.counts && (
                <Row gutter={16}>
                    <Col><Card size="small"><Statistic title="Всего" value={state.counts.total} /></Card></Col>
                    <Col><Card size="small"><Statistic title="Активных" value={state.counts.active} /></Card></Col>
                    <Col><Card size="small"><Statistic title="Зарегистрированных" value={state.counts.registered} /></Card></Col>
                </Row>
            )}
            <Table rowKey="member_id" loading={state.loading} columns={columns} dataSource={state.rows} size="small" />
        </Space>
    );
};

/** Продажи: оплаченные заказы за период — число, выручка, PV. */
const SalesTab = () => {
    const [range, setRange] = useState(null);
    const [state, setState] = useState({ loading: true, data: null, forbidden: false });

    const load = () => {
        setState((s) => ({ ...s, loading: true }));
        api.fetchReportSales(undefined, rangeParams(range)).then((res) => {
            if (api.isForbidden(res)) return setState({ loading: false, forbidden: true, data: null });
            setState({ loading: false, forbidden: false, data: res?.data ?? null });
        });
    };
    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    if (state.forbidden) return <Forbidden />;
    const d = state.data;

    return (
        <Space direction="vertical" size={16} style={{ display: 'flex' }}>
            <Space wrap>
                <RangePicker value={range} onChange={setRange} />
                <Button type="primary" onClick={load}>Применить</Button>
                <Button onClick={() => { setRange(null); setTimeout(load, 0); }}>Сброс</Button>
            </Space>
            <Row gutter={16}>
                <Col><Card size="small" loading={state.loading}><Statistic title="Заказов (оплачено)" value={d?.orders ?? 0} /></Card></Col>
                <Col><Card size="small" loading={state.loading}><Statistic title="Выручка" value={usd(d?.revenue_cents)} /></Card></Col>
                <Col><Card size="small" loading={state.loading}><Statistic title="PV" value={d?.pv ?? 0} /></Card></Col>
            </Row>
        </Space>
    );
};

/** Расход на бонусы: авторитетный итог из ledger + разбивка по типам (снимок сети). owner/finance. */
const BonusExpenseTab = () => {
    const [range, setRange] = useState(null);
    const [state, setState] = useState({ loading: true, data: null, forbidden: false });

    const load = () => {
        setState((s) => ({ ...s, loading: true }));
        api.fetchReportBonusExpense(undefined, rangeParams(range)).then((res) => {
            if (api.isForbidden(res)) return setState({ loading: false, forbidden: true, data: null });
            setState({ loading: false, forbidden: false, data: res?.data ?? null });
        });
    };
    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    if (state.forbidden) return <Forbidden />;
    const d = state.data;

    const columns = [
        { title: 'Тип бонуса', dataIndex: 'type' },
        { title: 'Сумма (снимок)', dataIndex: 'amount_cents', render: usd },
    ];

    return (
        <Space direction="vertical" size={16} style={{ display: 'flex' }}>
            <Space wrap>
                <RangePicker value={range} onChange={setRange} />
                <Button type="primary" onClick={load}>Применить</Button>
                <Button onClick={() => { setRange(null); setTimeout(load, 0); }}>Сброс</Button>
            </Space>
            <Card size="small" loading={state.loading}>
                <Statistic title="Расход на бонусы за период (ledger)" value={usd(d?.total_expense_cents)} />
            </Card>
            <Typography.Text type="secondary">
                Разбивка по типам — снимок текущего состояния сети (не привязан к периоду).
            </Typography.Text>
            <Table rowKey="type" loading={state.loading} columns={columns} dataSource={d?.by_type_snapshot ?? []} size="small" pagination={false} />
        </Space>
    );
};

/** Отчёты/аналитика (A1): read-only сводки. Доступ к вкладкам гейтит backend (403 → плашка). */
const Reports = () => (
    <Tabs
        items={[
            { key: 'balances', label: 'Балансы', children: <BalancesTab /> },
            { key: 'users', label: 'Пользователи', children: <UsersTab /> },
            { key: 'sales', label: 'Продажи', children: <SalesTab /> },
            { key: 'bonus', label: 'Расход на бонусы', children: <BonusExpenseTab /> },
        ]}
        destroyInactiveTabPane
    />
);

export default Reports;
