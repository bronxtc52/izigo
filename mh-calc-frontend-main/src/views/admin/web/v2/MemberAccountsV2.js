'use client';
import React, { useState } from 'react';
import { Input, Table, Card, Row, Col, Statistic, Tag, Space, Typography, message, Empty, List } from 'antd';
import { useTranslation } from 'react-i18next';
import * as api from './apiV2';
import { fetchMembers } from '@/views/admin/webApi';
import { usd } from '../format';

// Счета партнёра ОС/НС/БС + лоты (read-only, T02). Все суммы — integer-центы, рендер
// через format.usd. Лоты — earliest-expiry-first; бейджи «истекает ≤30д» / «сгорел».
const dt = (iso) => (iso ? new Date(iso).toLocaleDateString('ru-RU') : '—');
const DAY = 24 * 60 * 60 * 1000;

const lotBadge = (lot, t) => {
    if (lot.status && lot.status !== 'active') return <Tag>{t(`mhV2.accounts.lotStatus.${lot.status}`, lot.status)}</Tag>;
    if ((lot.available_cents ?? 0) <= 0) return <Tag>{t('mhV2.accounts.spent')}</Tag>;
    if (lot.expires_at) {
        const left = (new Date(lot.expires_at).getTime() - Date.now()) / DAY;
        if (left < 0) return <Tag color="red">{t('mhV2.accounts.expired')}</Tag>;
        if (left <= 30) return <Tag color="orange">{t('mhV2.accounts.expiringSoon')}</Tag>;
    }
    return <Tag color="green">{t('mhV2.accounts.active')}</Tag>;
};

const MemberAccountsV2 = () => {
    const { t } = useTranslation();
    const [options, setOptions] = useState([]);
    const [searching, setSearching] = useState(false);
    const [current, setCurrent] = useState(null); // {id, name}
    const [accounts, setAccounts] = useState(null);
    const [lots, setLots] = useState([]);
    const [loading, setLoading] = useState(false);

    const search = async (q) => {
        if (!q || q.trim().length < 1) { setOptions([]); return; }
        setSearching(true);
        const res = await fetchMembers(undefined, { search: q.trim() });
        setSearching(false);
        const list = res?.data ?? [];
        setOptions(list.slice(0, 20).map((m) => ({ value: String(m.id), label: `#${m.id} · ${m.name ?? m.username ?? '—'}` })));
    };

    const loadMember = async (id) => {
        if (!id) return;
        setLoading(true);
        setCurrent({ id });
        const [acc, lt] = await Promise.all([api.memberAccounts(id), api.memberLots(id)]);
        setLoading(false);
        if (!acc.ok || !lt.ok) {
            message.error(t('mhV2.accounts.loadFailed'));
            setAccounts(null); setLots([]);
            return;
        }
        setAccounts(acc.data);
        setLots(lt.data?.items ?? []);
    };

    const lotColumns = [
        { title: 'ID', dataIndex: 'id', width: 60 },
        { title: t('mhV2.accounts.colAccount'), dataIndex: 'account', width: 70, render: (v) => <Tag>{(v ?? '').toUpperCase()}</Tag> },
        { title: t('mhV2.accounts.colAvailable'), dataIndex: 'available_cents', width: 120, render: (v) => usd(v) },
        { title: t('mhV2.accounts.colAmount'), dataIndex: 'amount_cents', width: 120, render: (v) => usd(v) },
        { title: t('mhV2.accounts.colEarned'), dataIndex: 'earned_at', width: 110, render: dt },
        { title: t('mhV2.accounts.colExpires'), dataIndex: 'expires_at', width: 130, render: (v) => (v ? dt(v) : <Typography.Text type="secondary">{t('mhV2.accounts.noExpiry')}</Typography.Text>) },
        { title: t('mhV2.accounts.colSource'), dataIndex: 'source_type', ellipsis: true, render: (v) => <Typography.Text style={{ fontSize: 12 }}>{v ?? '—'}</Typography.Text> },
        { title: '', key: 'badge', width: 120, render: (_, r) => lotBadge(r, t) },
    ];

    return (
        <Space direction="vertical" style={{ width: '100%' }} size="middle">
            <Input.Search
                placeholder={t('mhV2.accounts.searchPlaceholder')}
                enterButton
                allowClear
                loading={searching}
                onSearch={(v) => search(v)}
                onChange={(e) => { if (!e.target.value) setOptions([]); }}
                style={{ maxWidth: 480 }}
            />
            {options.length > 0 && !accounts && (
                <List
                    size="small"
                    bordered
                    style={{ maxWidth: 480 }}
                    dataSource={options}
                    renderItem={(o) => (
                        <List.Item style={{ cursor: 'pointer' }} onClick={() => { setOptions([]); loadMember(o.value); }}>
                            {o.label}
                        </List.Item>
                    )}
                />
            )}

            {!current && <Empty description={t('mhV2.accounts.pickMember')} />}

            {current && (
                <>
                    <Typography.Text strong>{t('mhV2.accounts.member')} #{current.id}</Typography.Text>
                    <Row gutter={16}>
                        <Col xs={24} md={8}>
                            <Card size="small" loading={loading} title={<span>{t('mhV2.accounts.os')} <Typography.Text type="secondary" style={{ fontSize: 12 }}>({t('mhV2.accounts.osHint')})</Typography.Text></span>}>
                                <Statistic title={t('mhV2.accounts.available')} value={usd(accounts?.os_available_cents)} />
                                <Statistic title={t('mhV2.accounts.held')} value={usd(accounts?.os_held_cents)} valueStyle={{ fontSize: 16, color: '#8c8c8c' }} />
                            </Card>
                        </Col>
                        <Col xs={24} md={8}>
                            <Card size="small" loading={loading} title={<span>{t('mhV2.accounts.ns')} <Typography.Text type="secondary" style={{ fontSize: 12 }}>({t('mhV2.accounts.nsHint')})</Typography.Text></span>}>
                                <Statistic title={t('mhV2.accounts.balance')} value={usd(accounts?.ns_cents)} />
                            </Card>
                        </Col>
                        <Col xs={24} md={8}>
                            <Card size="small" loading={loading} title={<span>{t('mhV2.accounts.bs')} <Typography.Text type="secondary" style={{ fontSize: 12 }}>({t('mhV2.accounts.bsHint')})</Typography.Text></span>}>
                                <Statistic title={t('mhV2.accounts.available')} value={usd(accounts?.bs_available_cents)} />
                                <Statistic title={t('mhV2.accounts.held')} value={usd(accounts?.bs_held_cents)} valueStyle={{ fontSize: 16, color: '#8c8c8c' }} />
                            </Card>
                        </Col>
                    </Row>

                    {(accounts?.upcoming_expirations ?? []).length > 0 && (
                        <Card size="small" title={t('mhV2.accounts.upcoming')}>
                            <Space direction="vertical" style={{ width: '100%' }}>
                                {accounts.upcoming_expirations.map((u, i) => (
                                    <div key={i}>
                                        <Tag>{(u.account ?? '').toUpperCase()}</Tag> {usd(u.amount_cents)} — {dt(u.expires_at)}
                                    </div>
                                ))}
                            </Space>
                        </Card>
                    )}

                    <Typography.Title level={5} style={{ marginBottom: 0 }}>{t('mhV2.accounts.lots')}</Typography.Title>
                    <Table rowKey="id" loading={loading} columns={lotColumns} dataSource={lots} size="small" pagination={{ pageSize: 20 }} scroll={{ x: 'max-content' }} />
                </>
            )}
        </Space>
    );
};

export default MemberAccountsV2;
