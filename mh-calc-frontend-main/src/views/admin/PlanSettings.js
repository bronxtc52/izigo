'use client';
import React, { useEffect, useState } from 'react';
import { Card, Radio, Button, Table, Spin, Result, message, Space, Typography } from 'antd';
import { useGlobalContext } from '@/common/GlobalContext';
import { fetchPlanSettings, updatePlanSettings, isForbidden, isUnauthorized } from './api';

const PlanSettings = () => {
    const { userToken, setUserToken, setShowAuth } = useGlobalContext();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [forbidden, setForbidden] = useState(false);
    const [mode, setMode] = useState('auto');
    const [saving, setSaving] = useState(false);

    const onUnauthorized = () => {
        if (typeof window !== 'undefined') localStorage.removeItem('userToken');
        setUserToken(false);
        setShowAuth(true);
    };

    const load = async () => {
        const res = await fetchPlanSettings(userToken);
        if (isUnauthorized(res)) { onUnauthorized(); return; }
        if (isForbidden(res)) { setForbidden(true); setLoading(false); return; }
        setData(res?.data ?? null);
        setMode(res?.data?.placement_mode ?? 'auto');
        setLoading(false);
    };

    useEffect(() => {
        if (userToken) load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [userToken]);

    const save = async () => {
        setSaving(true);
        try {
            const res = await updatePlanSettings(userToken, { placement_mode: mode });
            setData(res);
            message.success('Сохранено');
        } catch (e) {
            message.error(e?.status === 403 ? 'Только владелец может менять план' : 'Не удалось сохранить');
        } finally {
            setSaving(false);
        }
    };

    if (forbidden) return <Result status="403" title="Нет доступа" />;
    if (loading) return <Spin style={{ display: 'block', margin: '40px auto' }} />;

    const rankColumns = [
        { title: 'Ранг', dataIndex: 'alias' },
        { title: 'Малая ветка, PV', dataIndex: 'small_branch_pv' },
        { title: 'Лично приглашённых', dataIndex: 'personal_count' },
    ];

    return (
        <Space direction="vertical" size={16} style={{ display: 'flex' }}>
            <Card title="Режим размещения">
                <Radio.Group value={mode} onChange={(e) => setMode(e.target.value)}>
                    <Radio value="auto">Авто-спилловер (слабая нога)</Radio>
                    <Radio value="manual">Ручной выбор слота</Radio>
                </Radio.Group>
                <div style={{ marginTop: 16 }}>
                    <Button type="primary" loading={saving} onClick={save}>Сохранить</Button>
                </div>
            </Card>

            <Card title="Пороги рангов">
                <Typography.Paragraph type="secondary">
                    Текущие условия квалификации (редактирование процентов/порогов — в развитии).
                </Typography.Paragraph>
                <Table rowKey="id" dataSource={data?.ranks ?? []} columns={rankColumns} pagination={false} />
            </Card>
        </Space>
    );
};

export default PlanSettings;
