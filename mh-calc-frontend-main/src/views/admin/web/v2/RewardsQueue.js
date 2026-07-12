'use client';
import React, { useEffect, useState } from 'react';
import { Table, Tag, Space, Select, Button, Popconfirm, Modal, Input, message, Result, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import * as api from './apiV2';
import { usd } from '../format';

// Очередь квалификационных наград (T10, flag mh_v2_awards). Статусы MF-8:
// granted|on_hold|paid_out|forfeited. Mutation (mark-paid/hold/release/forfeit) —
// owner-only (кнопки скрыты не-owner; бэк = источник истины). Ранг навсегда: forfeit
// НЕ отзывает ранг и НЕ делает reversal — только помечает выплату несостоявшейся.
const STATUS_COLOR = { granted: 'blue', on_hold: 'orange', paid_out: 'green', forfeited: 'default' };
const dt = (iso) => (iso ? new Date(iso).toLocaleString('ru-RU') : '—');
const isOwner = () => { try { return api.getRoles().includes('owner'); } catch (e) { return false; } };

const RewardsQueue = () => {
    const { t } = useTranslation();
    const owner = isOwner();

    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [notAvailable, setNotAvailable] = useState(false);
    const [status, setStatus] = useState('');
    const [busyId, setBusyId] = useState(null);
    const [forfeitRow, setForfeitRow] = useState(null);
    const [forfeitReason, setForfeitReason] = useState('');

    const load = async () => {
        setLoading(true);
        const res = await api.listAwards(status);
        if (!res.ok) {
            setNotAvailable(res.status === 403); // флаг mh_v2_awards OFF → движок не подключён
            setRows([]); setLoading(false); return;
        }
        setNotAvailable(false);
        setRows(res.data ?? []);
        setLoading(false);
    };

    useEffect(() => { load(); /* eslint-disable-next-line */ }, [status]);

    const runAction = async (id, fn) => {
        setBusyId(id);
        const res = await fn();
        setBusyId(null);
        if (!res.ok) {
            // 409 — недопустимый переход/уже выплачено; сообщение бэка информативно.
            message.error(res.message || t('mhV2.rewards.actionFailedCode', { code: res.status }));
            return false;
        }
        message.success(t('mhV2.rewards.actionDone'));
        await load();
        return true;
    };

    const submitForfeit = async () => {
        if (!forfeitReason.trim()) { message.error(t('mhV2.rewards.reasonRequired')); return; }
        const ok = await runAction(forfeitRow.id, () => api.forfeitAward(forfeitRow.id, forfeitReason.trim()));
        if (ok) { setForfeitRow(null); setForfeitReason(''); }
    };

    if (notAvailable) {
        return <Result icon={<span style={{ fontSize: 40 }}>⚙️</span>} title={t('mhV2.rewards.engineOff')} subTitle={t('mhV2.rewards.engineOffHint')} />;
    }

    const actionsFor = (r) => {
        if (!owner) return <Typography.Text type="secondary">—</Typography.Text>;
        const b = busyId === r.id;
        if (r.status === 'granted') {
            return (
                <Space size={4} wrap>
                    <Popconfirm title={t('mhV2.rewards.markPaidConfirm', { amount: usd(r.amount_cents) })} onConfirm={() => runAction(r.id, () => api.markPaidAward(r.id))}>
                        <Button size="small" type="primary" loading={b}>{t('mhV2.rewards.markPaid')}</Button>
                    </Popconfirm>
                    <Popconfirm title={t('mhV2.rewards.holdConfirm')} onConfirm={() => runAction(r.id, () => api.holdAward(r.id))}>
                        <Button size="small" loading={b}>{t('mhV2.rewards.hold')}</Button>
                    </Popconfirm>
                    <Button size="small" danger loading={b} onClick={() => { setForfeitRow(r); setForfeitReason(''); }}>{t('mhV2.rewards.forfeit')}</Button>
                </Space>
            );
        }
        if (r.status === 'on_hold') {
            return (
                <Space size={4} wrap>
                    <Popconfirm title={t('mhV2.rewards.releaseConfirm')} onConfirm={() => runAction(r.id, () => api.releaseAward(r.id))}>
                        <Button size="small" loading={b}>{t('mhV2.rewards.release')}</Button>
                    </Popconfirm>
                    <Button size="small" danger loading={b} onClick={() => { setForfeitRow(r); setForfeitReason(''); }}>{t('mhV2.rewards.forfeit')}</Button>
                </Space>
            );
        }
        return <Typography.Text type="secondary">—</Typography.Text>;
    };

    const columns = [
        { title: 'ID', dataIndex: 'id', width: 60 },
        { title: t('mhV2.rewards.colMember'), dataIndex: 'member_id', width: 90 },
        { title: t('mhV2.rewards.colAward'), dataIndex: 'award_code', render: (v, r) => <span><Typography.Text code>{v}</Typography.Text>{r.stage_no ? ` #${r.stage_no}` : ''}</span> },
        { title: t('mhV2.rewards.colAmount'), dataIndex: 'amount_cents', width: 120, render: usd },
        { title: t('mhV2.rewards.colStatus'), dataIndex: 'status', width: 110, render: (s) => <Tag color={STATUS_COLOR[s] ?? 'default'}>{t(`mhV2.rewards.status.${s}`, s)}</Tag> },
        { title: t('mhV2.rewards.colGranted'), dataIndex: 'granted_at', width: 160, render: dt },
        { title: t('mhV2.rewards.colPaid'), dataIndex: 'paid_at', width: 160, render: dt },
        { title: t('mhV2.rewards.colNote'), dataIndex: 'note', ellipsis: true, render: (v) => v || '—' },
        { title: t('mhV2.rewards.colActions'), key: 'act', width: 240, render: (_, r) => actionsFor(r) },
    ];

    return (
        <Space direction="vertical" style={{ width: '100%' }} size="middle">
            <Space wrap>
                <Select allowClear placeholder={t('mhV2.rewards.filterStatus')} style={{ width: 180 }} value={status || undefined} onChange={(v) => setStatus(v ?? '')}
                    options={['granted', 'on_hold', 'paid_out', 'forfeited'].map((v) => ({ value: v, label: t(`mhV2.rewards.status.${v}`, v) }))} />
                <Button onClick={load}>{t('mhV2.refresh')}</Button>
            </Space>

            <Table rowKey="id" loading={loading} columns={columns} dataSource={rows} size="small" pagination={{ pageSize: 20 }} scroll={{ x: 'max-content' }} />

            <Modal
                open={!!forfeitRow}
                title={t('mhV2.rewards.forfeitTitle')}
                onCancel={() => setForfeitRow(null)}
                onOk={submitForfeit}
                okText={t('mhV2.rewards.forfeit')}
                okButtonProps={{ danger: true }}
            >
                <p>{t('mhV2.rewards.forfeitNote')}</p>
                <Input.TextArea rows={3} value={forfeitReason} onChange={(e) => setForfeitReason(e.target.value)} placeholder={t('mhV2.rewards.reasonPlaceholder')} maxLength={500} />
            </Modal>
        </Space>
    );
};

export default RewardsQueue;
