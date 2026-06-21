'use client';
import React, { useEffect, useState } from 'react';
import { Segmented, List, Tag, Button, Space, Spin, Modal, Input, message } from 'antd';
import {
    fetchWithdrawals, approveWithdrawal, rejectWithdrawal,
    markPaidWithdrawal, cancelWithdrawal, isForbidden,
} from './initDataApi';

const STATUS = {
    requested: { label: 'на рассмотрении', color: 'blue' },
    approved: { label: 'одобрена', color: 'cyan' },
    paid: { label: 'выплачена', color: 'green' },
    rejected: { label: 'отклонена', color: 'red' },
    cancelled: { label: 'отменена', color: 'default' },
};
const FILTERS = [
    { label: 'Ожидают', value: 'requested' },
    { label: 'Одобрены', value: 'approved' },
    { label: 'Все', value: '' },
];

/**
 * Очередь заявок на вывод для финансиста (Telegram Mini App). Действия по статусу:
 * requested → одобрить/отклонить; approved → выплачено/отменить. Доступ — owner/finance
 * (403 на backend → onUnauthorized не дёргаем, просто показываем пусто).
 */
const AdminWithdrawals = ({ creds, onUnauthorized }) => {
    const [status, setStatus] = useState('requested');
    const [items, setItems] = useState([]);
    const [loading, setLoading] = useState(true);
    const [busyId, setBusyId] = useState(null);
    const [rejectId, setRejectId] = useState(null);
    const [rejectReason, setRejectReason] = useState('');

    const load = async (st = status) => {
        setLoading(true);
        const res = await fetchWithdrawals(creds, st);
        if (res?.error === 401) { onUnauthorized?.(); return; }
        if (isForbidden(res)) { setItems([]); setLoading(false); return; }
        setItems(res?.data ?? []);
        setLoading(false);
    };

    useEffect(() => { load(status); /* eslint-disable-next-line */ }, [status]);

    const act = async (fn, id, okMsg) => {
        setBusyId(id);
        try {
            await fn();
            message.success(okMsg);
        } catch (e) {
            message.error(e?.status === 422 ? 'Недопустимое действие' : 'Не удалось выполнить');
        } finally {
            setBusyId(null);
            load(status);
        }
    };

    const submitReject = async () => {
        if (!rejectReason.trim()) { message.error('Укажите причину'); return; }
        const id = rejectId;
        setRejectId(null);
        await act(() => rejectWithdrawal(creds, id, rejectReason.trim()), id, 'Заявка отклонена');
        setRejectReason('');
    };

    if (loading) return <Spin style={{ display: 'block', margin: '40px auto' }} />;

    return (
        <div>
            <Segmented block value={status} onChange={setStatus} options={FILTERS} style={{ marginBottom: 12 }} />
            <List
                dataSource={items}
                locale={{ emptyText: 'Заявок нет' }}
                renderItem={(w) => (
                    <List.Item style={{ display: 'block' }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                            <span>
                                <b>${w.amount}</b>{' '}
                                <Tag color={(STATUS[w.status] ?? {}).color}>
                                    {(STATUS[w.status] ?? {}).label ?? w.status}
                                </Tag>
                            </span>
                            <span style={{ fontSize: 12, opacity: 0.6 }}>
                                #{w.member_id} · {w.requested_at ? new Date(w.requested_at).toLocaleDateString() : ''}
                            </span>
                        </div>
                        <div style={{ fontSize: 12, opacity: 0.75, margin: '4px 0' }}>{w.payout_details}</div>
                        {w.member_balance && (
                            <div style={{ fontSize: 12, opacity: 0.7 }}>
                                Баланс партнёра: доступно ${w.member_balance.available} · в холде ${w.member_balance.held}
                                {Number(w.member_balance.clawback_debt) > 0 && (
                                    <span style={{ color: '#cf1322' }}> · долг ${w.member_balance.clawback_debt}</span>
                                )}
                            </div>
                        )}
                        {w.reject_reason && (
                            <div style={{ fontSize: 12, color: '#cf1322' }}>Причина: {w.reject_reason}</div>
                        )}
                        <Space style={{ marginTop: 6 }}>
                            {w.status === 'requested' && (
                                <>
                                    <Button size="small" type="primary" loading={busyId === w.id}
                                        onClick={() => act(() => approveWithdrawal(creds, w.id), w.id, 'Одобрено')}>
                                        Одобрить
                                    </Button>
                                    <Button size="small" danger disabled={busyId === w.id}
                                        onClick={() => { setRejectId(w.id); setRejectReason(''); }}>
                                        Отклонить
                                    </Button>
                                </>
                            )}
                            {w.status === 'approved' && (
                                <>
                                    <Button size="small" type="primary" loading={busyId === w.id}
                                        onClick={() => act(() => markPaidWithdrawal(creds, w.id), w.id, 'Отмечено выплаченным')}>
                                        Выплачено
                                    </Button>
                                    <Button size="small" disabled={busyId === w.id}
                                        onClick={() => act(() => cancelWithdrawal(creds, w.id), w.id, 'Отменено')}>
                                        Отменить
                                    </Button>
                                </>
                            )}
                        </Space>
                    </List.Item>
                )}
            />
            <Modal
                title="Причина отклонения"
                open={rejectId != null}
                onOk={submitReject}
                onCancel={() => setRejectId(null)}
                okText="Отклонить"
                okButtonProps={{ danger: true }}
            >
                <Input.TextArea
                    rows={3} maxLength={1000} value={rejectReason}
                    onChange={(e) => setRejectReason(e.target.value)}
                    placeholder="Например: некорректные реквизиты"
                />
            </Modal>
        </div>
    );
};

export default AdminWithdrawals;
