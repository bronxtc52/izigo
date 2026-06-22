'use client';
import React, { useEffect, useState } from 'react';
import { Table, Input, Space, Tag, Result, Typography } from 'antd';
import * as api from '@/views/admin/webApi';

/** Аудит-лог админ-действий (только owner). Фильтр по action. */
const AuditLog = () => {
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [forbidden, setForbidden] = useState(false);
    const [action, setAction] = useState('');

    const load = async (a = action) => {
        setLoading(true);
        const res = await api.fetchAuditLog({ action: a });
        if (api.isForbidden(res)) { setForbidden(true); setLoading(false); return; }
        setRows(res?.data?.data ?? []);
        setLoading(false);
    };

    useEffect(() => { load(''); /* eslint-disable-next-line */ }, []);

    if (forbidden) return <Result status="403" title="Недостаточно прав" />;

    const columns = [
        { title: 'Время', dataIndex: 'created_at', render: (v) => (v ? new Date(v).toLocaleString('ru-RU') : '') },
        { title: 'Действие', dataIndex: 'action', render: (v) => <Tag>{v}</Tag> },
        { title: 'Объект', render: (_, r) => `${r.entity_type}${r.entity_id ? ` #${r.entity_id}` : ''}` },
        { title: 'Кто', dataIndex: 'actor_name', render: (v, r) => v || (r.actor_member_id ? `#${r.actor_member_id}` : '—') },
        {
            title: 'Изменение',
            render: (_, r) => (
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    {r.before || r.after ? JSON.stringify({ before: r.before, after: r.after }).slice(0, 120) : ''}
                </Typography.Text>
            ),
        },
    ];

    return (
        <Space direction="vertical" size={12} style={{ display: 'flex' }}>
            <Input.Search
                placeholder="Фильтр по действию (напр. plan.update)"
                allowClear
                style={{ maxWidth: 320 }}
                onSearch={(v) => { setAction(v); load(v); }}
            />
            <Table rowKey="id" loading={loading} columns={columns} dataSource={rows} size="small" />
        </Space>
    );
};

export default AuditLog;
