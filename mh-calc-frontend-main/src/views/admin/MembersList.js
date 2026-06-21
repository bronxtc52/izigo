'use client';
import React, { useEffect, useState } from 'react';
import { Card, Table, Input, Select, Space, Tag, Result, Spin } from 'antd';
import * as tokenApi from './api';

const STATUS = [
    { value: '', label: 'Все статусы' },
    { value: 'registered', label: 'Зарегистрирован' },
    { value: 'active', label: 'Активен' },
];

/**
 * Список участников. Источник авторизации задаётся пропсами:
 *  - creds: токен (CalculatorAuthToken) ИЛИ initData (Telegram) — первый аргумент API;
 *  - api: модуль API (token-based ./api по умолчанию или initData-обёртка);
 *  - onUnauthorized: что делать при 401 (по умолчанию — ничего, экран решает выше);
 *  - onOpenMember(id): переход к карточке участника.
 */
const MembersList = ({ creds, api = tokenApi, onUnauthorized = () => {}, onOpenMember }) => {
    const { fetchMembers, isForbidden, isUnauthorized } = api;

    const [rows, setRows] = useState([]);
    const [total, setTotal] = useState(0);
    const [loading, setLoading] = useState(true);
    const [forbidden, setForbidden] = useState(false);
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');

    const load = async (params) => {
        setLoading(true);
        const res = await fetchMembers(creds, params);
        if (isUnauthorized(res)) { onUnauthorized(); return; }
        if (isForbidden(res)) {
            setForbidden(true);
            setLoading(false);
            return;
        }
        setRows(res?.data?.data ?? []);
        setTotal(res?.data?.total ?? 0);
        setLoading(false);
    };

    useEffect(() => {
        if (creds) load({ search, status });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [creds, status]);

    if (forbidden) return <Result status="403" title="Недостаточно прав" subTitle="Требуется роль администратора." />;

    const columns = [
        { title: 'ID', dataIndex: 'id', width: 70 },
        { title: 'Имя', dataIndex: 'name' },
        {
            title: 'Статус',
            dataIndex: 'status',
            render: (s) => <Tag color={s === 'active' ? 'green' : 'default'}>{s}</Tag>,
        },
        { title: 'Ранг', dataIndex: 'rank', render: (r) => r ?? '—' },
        { title: 'Пакет', dataIndex: 'package_id', render: (p) => p ?? '—' },
        { title: 'Спонсор', dataIndex: 'sponsor_id', render: (s) => s ?? '—' },
        {
            title: 'Дата',
            dataIndex: 'created_at',
            render: (d) => (d ? new Date(d).toLocaleDateString() : '—'),
        },
    ];

    return (
        <Card title="Участники">
            <Space style={{ marginBottom: 16 }} wrap>
                <Input.Search
                    placeholder="Поиск по имени"
                    allowClear
                    style={{ width: 280 }}
                    onSearch={(v) => { setSearch(v); load({ search: v, status }); }}
                />
                <Select options={STATUS} value={status} style={{ width: 200 }} onChange={setStatus} />
            </Space>
            {loading ? (
                <Spin style={{ display: 'block', margin: '40px auto' }} />
            ) : (
                <Table
                    rowKey="id"
                    dataSource={rows}
                    columns={columns}
                    pagination={{ total, pageSize: 25 }}
                    onRow={(r) => ({ onClick: () => onOpenMember?.(r.id), style: { cursor: 'pointer' } })}
                    locale={{ emptyText: 'Нет участников' }}
                />
            )}
        </Card>
    );
};

export default MembersList;
