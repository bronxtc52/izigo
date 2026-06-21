'use client';
import React, { useEffect, useState } from 'react';
import dynamic from 'next/dynamic';
import { Card, Descriptions, Tag, Select, Button, Space, Spin, Result, message } from 'antd';
import { useGlobalContext } from '@/common/GlobalContext';
import { fetchMember, assignRole, revokeRole, isForbidden, isUnauthorized, ROLES } from './api';

const Tree = dynamic(() => import('react-d3-tree'), { ssr: false });

const MemberCard = ({ id }) => {
    const { userToken, setUserToken, setShowAuth } = useGlobalContext();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [forbidden, setForbidden] = useState(false);
    const [role, setRole] = useState('support');
    const [saving, setSaving] = useState(false);

    const onUnauthorized = () => {
        if (typeof window !== 'undefined') localStorage.removeItem('userToken');
        setUserToken(false);
        setShowAuth(true);
    };

    const load = async () => {
        setLoading(true);
        const res = await fetchMember(userToken, id);
        if (isUnauthorized(res)) { onUnauthorized(); return; }
        if (isForbidden(res)) { setForbidden(true); setLoading(false); return; }
        setData(res?.data ?? null);
        setLoading(false);
    };

    useEffect(() => {
        if (userToken) load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [userToken, id]);

    const onAssign = async () => {
        setSaving(true);
        try {
            await assignRole(userToken, id, role);
            message.success('Роль назначена');
            await load();
        } catch (e) {
            message.error(e?.status === 403 ? 'Только владелец может назначать роли' : 'Не удалось назначить роль');
        } finally {
            setSaving(false);
        }
    };

    const onRevoke = async (r) => {
        try {
            await revokeRole(userToken, id, r);
            message.success('Роль снята');
            await load();
        } catch (e) {
            message.error('Не удалось снять роль');
        }
    };

    if (forbidden) return <Result status="403" title="Нет доступа" />;
    if (loading) return <Spin style={{ display: 'block', margin: '40px auto' }} />;
    if (!data) return <Result status="404" title="Участник не найден" />;

    const m = data.member;
    const branch = data.branch;

    return (
        <Space direction="vertical" size={16} style={{ display: 'flex' }}>
            <Card title={`Участник #${m.id}`}>
                <Descriptions column={2}>
                    <Descriptions.Item label="Имя">{m.name ?? '—'}</Descriptions.Item>
                    <Descriptions.Item label="Email">{m.email ?? '—'}</Descriptions.Item>
                    <Descriptions.Item label="Статус"><Tag color={m.status === 'active' ? 'green' : 'default'}>{m.status}</Tag></Descriptions.Item>
                    <Descriptions.Item label="Ранг">{m.rank ?? '—'}</Descriptions.Item>
                    <Descriptions.Item label="Пакет">{m.package_id ?? '—'}</Descriptions.Item>
                    <Descriptions.Item label="Спонсор">{m.sponsor_id ?? '—'}</Descriptions.Item>
                    <Descriptions.Item label="Реф-код">{m.ref_code}</Descriptions.Item>
                    <Descriptions.Item label="Роли">
                        {(m.roles ?? []).length
                            ? m.roles.map((r) => <Tag key={r} closable onClose={(e) => { e.preventDefault(); onRevoke(r); }}>{r}</Tag>)
                            : '—'}
                    </Descriptions.Item>
                </Descriptions>
            </Card>

            <Card title="Назначить роль" size="small">
                <Space>
                    <Select value={role} onChange={setRole} options={ROLES} style={{ width: 180 }} />
                    <Button type="primary" loading={saving} onClick={onAssign}>Назначить</Button>
                </Space>
            </Card>

            <Card title="Ветка участника" bodyStyle={{ height: 460, padding: 0 }}>
                {branch?.name ? (
                    <div style={{ width: '100%', height: '100%' }}>
                        <Tree data={branch} orientation="vertical" collapsible={false} translate={{ x: 350, y: 60 }} pathFunc="straight" />
                    </div>
                ) : null}
            </Card>
        </Space>
    );
};

export default MemberCard;
