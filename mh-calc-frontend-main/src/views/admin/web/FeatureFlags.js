'use client';
import React, { useEffect, useState } from 'react';
import { Table, Switch, Result, message, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import * as api from '@/views/admin/webApi';

/**
 * Фиче-флаги (C3, Block C): owner-only таблица рантайм-тоглов. key + описание + свитч.
 * Переключение сохраняется немедленно через webApi.setFeatureFlag (deny-by-default на бэке).
 */
const FeatureFlags = () => {
    const { t } = useTranslation();
    const isOwner = api.getRoles().includes('owner');
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(null);
    const [forbidden, setForbidden] = useState(false);

    const load = async () => {
        setLoading(true);
        const res = await api.fetchFeatureFlags(undefined);
        if (api.isForbidden(res)) { setForbidden(true); setLoading(false); return; }
        setRows(res?.data ?? []);
        setLoading(false);
    };

    useEffect(() => { load(); /* eslint-disable-next-line */ }, []);

    if (forbidden) return <Result status="403" title={t('featureFlags.forbidden')} />;

    const toggle = async (key, enabled) => {
        setSaving(key);
        try {
            const data = await api.setFeatureFlag(undefined, key, enabled);
            setRows(data ?? []);
            message.success(t('featureFlags.saved'));
        } catch (e) {
            message.error(e?.status === 403 ? t('featureFlags.ownerOnly') : t('featureFlags.saveFailed'));
            load();
        } finally {
            setSaving(null);
        }
    };

    const columns = [
        { title: t('featureFlags.colKey'), dataIndex: 'key', key: 'key', render: (v) => <Typography.Text code>{v}</Typography.Text> },
        { title: t('featureFlags.colDescription'), dataIndex: 'description', key: 'description', render: (v) => v || '—' },
        {
            title: t('featureFlags.colEnabled'),
            dataIndex: 'enabled',
            key: 'enabled',
            width: 120,
            render: (enabled, row) => (
                <Switch
                    checked={!!enabled}
                    disabled={!isOwner}
                    loading={saving === row.key}
                    onChange={(checked) => toggle(row.key, checked)}
                />
            ),
        },
    ];

    return (
        <Table
            rowKey="key"
            loading={loading}
            columns={columns}
            dataSource={rows}
            size="small"
            pagination={false}
        />
    );
};

export default FeatureFlags;
