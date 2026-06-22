'use client';
import React, { useEffect, useState } from 'react';
import dynamic from 'next/dynamic';
import { Card, Descriptions, Tag, Select, Button, Space, Spin, Result, message } from 'antd';
import * as tokenApi from './api';

const Tree = dynamic(() => import('react-d3-tree'), { ssr: false });

/**
 * Карточка участника + назначение/снятие ролей. Источник авторизации — через пропсы
 * (creds + api), см. MembersList. По умолчанию работает на token-API ./api.
 *
 * C5 (Block C): PII-блок (маска по умолчанию + reveal owner-only + экспорт) показывается
 * ТОЛЬКО в веб-админке — когда передан piiApi (webApi). В Mini App пропа нет → блока нет.
 * canReveal гейтит кнопку reveal под owner; это лишь UX — реальная защита на бэкенде.
 */
const MemberCard = ({ id, creds, api = tokenApi, onUnauthorized = () => {}, piiApi = null, canReveal = false }) => {
    const { fetchMember, assignRole, revokeRole, isForbidden, isUnauthorized, ROLES } = api;

    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [forbidden, setForbidden] = useState(false);
    const [role, setRole] = useState('support');
    const [saving, setSaving] = useState(false);

    // --- C5 PII state ---
    const [pii, setPii] = useState(null);          // маскированная сводка PII
    const [revealed, setRevealed] = useState(null); // сырые значения после reveal (owner)
    const [revealing, setRevealing] = useState(false);
    const [exporting, setExporting] = useState(false);

    const loadPii = async () => {
        if (!piiApi) return;
        const res = await piiApi.fetchMemberPii(creds, id);
        if (piiApi.isUnauthorized(res)) { onUnauthorized(); return; }
        if (piiApi.isForbidden(res)) { setPii(null); return; }
        setPii(res?.data ?? null);
    };

    const onReveal = async () => {
        setRevealing(true);
        try {
            const real = await piiApi.revealMemberPii(creds, id);
            setRevealed(real);
            message.success('PII раскрыто (записано в аудит)');
        } catch (e) {
            message.error(e?.status === 403 ? 'Только владелец может раскрывать PII' : 'Не удалось раскрыть PII');
        } finally {
            setRevealing(false);
        }
    };

    const onExport = async (format) => {
        setExporting(true);
        try {
            // canReveal === owner → полный экспорт; иначе бэкенд принудительно маскирует.
            const res = await piiApi.exportMember(creds, id, format, !canReveal);
            if (res?.error === 403) { message.error('Недостаточно прав для экспорта'); return; }
            if (res?.error) { message.error('Не удалось экспортировать'); return; }
            message.success(`Экспорт ${format.toUpperCase()} готов`);
        } finally {
            setExporting(false);
        }
    };

    const load = async () => {
        setLoading(true);
        const res = await fetchMember(creds, id);
        if (isUnauthorized(res)) { onUnauthorized(); return; }
        if (isForbidden(res)) { setForbidden(true); setLoading(false); return; }
        setData(res?.data ?? null);
        setLoading(false);
    };

    useEffect(() => {
        if (creds) { load(); loadPii(); }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [creds, id]);

    const onAssign = async () => {
        setSaving(true);
        try {
            await assignRole(creds, id, role);
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
            await revokeRole(creds, id, r);
            message.success('Роль снята');
            await load();
        } catch (e) {
            message.error('Не удалось снять роль');
        }
    };

    if (forbidden) return <Result status="403" title="Недостаточно прав" />;
    if (loading) return <Spin style={{ display: 'block', margin: '40px auto' }} />;
    if (!data) return <Result status="404" title="Участник не найден" />;

    const m = data.member;
    const branch = data.branch;

    return (
        <Space direction="vertical" size={16} style={{ display: 'flex' }}>
            <Card title={`Участник #${m.id}`}>
                <Descriptions column={2}>
                    <Descriptions.Item label="Имя">{m.name ?? '—'}</Descriptions.Item>
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

            {piiApi ? (
                <Card title="Персональные данные (PII)" size="small"
                    extra={(
                        <Space>
                            <Button size="small" loading={exporting} onClick={() => onExport('csv')}>Экспорт CSV</Button>
                            <Button size="small" loading={exporting} onClick={() => onExport('json')}>Экспорт JSON</Button>
                        </Space>
                    )}
                >
                    <Descriptions column={1} size="small">
                        <Descriptions.Item label="Telegram username">
                            {revealed?.telegram_username ?? pii?.telegram_username ?? '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label="Реквизиты выплаты (TON)">
                            {revealed?.payout_details ?? pii?.payout_details ?? '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label="KYC статус">
                            {revealed?.kyc_status ?? pii?.kyc_status ?? '—'}
                        </Descriptions.Item>
                    </Descriptions>
                    {canReveal ? (
                        <Button type="primary" ghost loading={revealing} disabled={!!revealed} onClick={onReveal}>
                            {revealed ? 'Раскрыто' : 'Показать реальные значения'}
                        </Button>
                    ) : (
                        <Tag color="default">Данные замаскированы — раскрытие доступно только владельцу</Tag>
                    )}
                </Card>
            ) : null}

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
