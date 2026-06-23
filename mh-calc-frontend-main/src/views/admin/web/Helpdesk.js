'use client';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Card, List, Tag, Select, Button, Input, Space, Typography, Empty, Spin, message } from 'antd';
import { useTranslation } from 'next-i18next';
import * as api from '@/views/admin/webApi';

const POLL_MS = 6000; // C2: polling треда оператора 5–8с (Gate-A п.6)

const statusColor = {
    open: 'blue',
    in_progress: 'gold',
    resolved: 'green',
    closed: 'default',
};

/**
 * Поддержка (C2, Block C): owner/support. Очередь тикетов (фильтр status/assigned) +
 * чат-панель оператора (ответ, смена статуса, взять на себя), polling треда.
 * body сообщений — сырой текст, выводим как текст (не HTML). RBAC — на бэкенде.
 */
const Helpdesk = () => {
    const { t } = useTranslation();
    const [tickets, setTickets] = useState([]);
    const [loading, setLoading] = useState(true);
    const [statusFilter, setStatusFilter] = useState('');
    const [assignedFilter, setAssignedFilter] = useState('');

    const [active, setActive] = useState(null);
    const [messages, setMessages] = useState([]);
    const [draft, setDraft] = useState('');
    const [sending, setSending] = useState(false);

    const cursorRef = useRef(0);
    const pollRef = useRef(null);

    const loadTickets = useCallback(async () => {
        setLoading(true);
        const res = await api.fetchTickets(undefined, statusFilter, assignedFilter);
        if (res?.error === 403) message.error(t('helpdesk.forbidden'));
        const list = res?.data ?? [];
        setTickets(Array.isArray(list) ? list : []);
        setLoading(false);
    }, [statusFilter, assignedFilter, t]);

    useEffect(() => { loadTickets(); }, [loadTickets]);

    const stopPoll = useCallback(() => {
        if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null; }
    }, []);

    useEffect(() => () => stopPoll(), [stopPoll]);

    const refreshThread = useCallback(async (ticketId) => {
        const res = await api.fetchTicket(undefined, ticketId);
        const data = res?.data;
        if (!data) return;
        const list = data.messages ?? [];
        setMessages(Array.isArray(list) ? list : []);
        cursorRef.current = list.length ? list[list.length - 1].id : 0;
        setActive(data.ticket);
    }, []);

    const openTicket = useCallback(async (ticket) => {
        stopPoll();
        setActive(ticket);
        setMessages([]);
        cursorRef.current = 0;
        await refreshThread(ticket.id);
        pollRef.current = setInterval(() => refreshThread(ticket.id), POLL_MS);
    }, [refreshThread, stopPoll]);

    const closeThread = useCallback(() => {
        stopPoll();
        setActive(null);
        setMessages([]);
        cursorRef.current = 0;
        loadTickets();
    }, [stopPoll, loadTickets]);

    const reply = async () => {
        const body = draft.trim();
        if (!body || !active) return;
        setSending(true);
        try {
            const res = await api.replyTicket(undefined, active.id, body);
            if (res?.data) {
                setMessages((prev) => [...prev, res.data]);
                setDraft('');
                refreshThread(active.id);
            } else {
                message.error(res?.error === 403 ? t('helpdesk.forbidden') : t('helpdesk.sendFailed'));
            }
        } finally {
            setSending(false);
        }
    };

    const changeStatus = async (status) => {
        if (!active) return;
        const res = await api.setTicketStatus(undefined, active.id, status);
        if (res?.data) {
            setActive(res.data);
            message.success(t('helpdesk.statusChanged'));
        } else {
            message.error(t('helpdesk.statusFailed'));
        }
    };

    const takeSelf = async () => {
        if (!active) return;
        const res = await api.assignTicket(undefined, active.id);
        if (res?.data) {
            setActive(res.data);
            message.success(t('helpdesk.assignedSelf'));
        } else {
            message.error(t('helpdesk.assignFailed'));
        }
    };

    const filters = (
        <Space wrap style={{ marginBottom: 12 }}>
            <Select
                value={statusFilter}
                style={{ width: 170 }}
                onChange={setStatusFilter}
                options={[
                    { value: '', label: t('helpdesk.filterAllStatuses') },
                    { value: 'open', label: t('helpdesk.status_open') },
                    { value: 'in_progress', label: t('helpdesk.status_in_progress') },
                    { value: 'resolved', label: t('helpdesk.status_resolved') },
                    { value: 'closed', label: t('helpdesk.status_closed') },
                ]}
            />
            <Select
                value={assignedFilter}
                style={{ width: 170 }}
                onChange={setAssignedFilter}
                options={[
                    { value: '', label: t('helpdesk.filterAllAssigned') },
                    { value: 'mine', label: t('helpdesk.filterMine') },
                    { value: 'unassigned', label: t('helpdesk.filterUnassigned') },
                ]}
            />
            <Button onClick={loadTickets}>{t('helpdesk.refresh')}</Button>
        </Space>
    );

    return (
        <div style={{ display: 'flex', gap: 16, alignItems: 'flex-start', flexWrap: 'wrap' }}>
            <Card title={t('helpdesk.queueTitle')} style={{ flex: '1 1 360px', minWidth: 320, maxWidth: 480 }}>
                {filters}
                {loading ? (
                    <div style={{ textAlign: 'center', padding: 24 }}><Spin /></div>
                ) : tickets.length === 0 ? (
                    <Empty description={t('helpdesk.empty')} />
                ) : (
                    <List
                        dataSource={tickets}
                        renderItem={(tk) => (
                            <List.Item
                                onClick={() => openTicket(tk)}
                                style={{ cursor: 'pointer', background: active?.id === tk.id ? '#e6f4ff' : 'transparent', borderRadius: 6, padding: '8px 10px' }}
                            >
                                <div style={{ width: '100%' }}>
                                    <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                                        <span style={{ fontWeight: 600 }}>{tk.subject}</span>
                                        <Tag color={statusColor[tk.status] || 'default'}>{t(`helpdesk.status_${tk.status}`)}</Tag>
                                    </div>
                                    <div style={{ fontSize: 12, opacity: 0.7 }}>
                                        {tk.member_name || tk.member_username || `#${tk.member_id}`}
                                        {tk.assigned_to ? ` · ${t('helpdesk.assigned')}` : ` · ${t('helpdesk.unassignedShort')}`}
                                    </div>
                                </div>
                            </List.Item>
                        )}
                    />
                )}
            </Card>

            <Card
                title={active ? active.subject : t('helpdesk.selectTicket')}
                style={{ flex: '2 1 420px', minWidth: 360 }}
                extra={active ? (
                    <Space>
                        <Tag color={statusColor[active.status] || 'default'}>{t(`helpdesk.status_${active.status}`)}</Tag>
                        <Button size="small" onClick={closeThread}>{t('helpdesk.close')}</Button>
                    </Space>
                ) : null}
            >
                {!active ? (
                    <Empty description={t('helpdesk.selectHint')} />
                ) : (
                    <Space direction="vertical" style={{ width: '100%' }} size="middle">
                        <Space wrap>
                            <Button size="small" onClick={takeSelf}>{t('helpdesk.takeSelf')}</Button>
                            <Select
                                size="small"
                                value={active.status}
                                style={{ width: 160 }}
                                onChange={changeStatus}
                                options={[
                                    { value: 'open', label: t('helpdesk.status_open') },
                                    { value: 'in_progress', label: t('helpdesk.status_in_progress') },
                                    { value: 'resolved', label: t('helpdesk.status_resolved') },
                                    { value: 'closed', label: t('helpdesk.status_closed') },
                                ]}
                            />
                        </Space>

                        <div style={{ maxHeight: 380, overflowY: 'auto' }}>
                            {messages.map((m) => {
                                const op = m.author_role === 'operator';
                                return (
                                    <div key={m.id} style={{ display: 'flex', justifyContent: op ? 'flex-end' : 'flex-start', marginBottom: 6 }}>
                                        <div style={{
                                            maxWidth: '80%',
                                            padding: '8px 12px',
                                            borderRadius: 12,
                                            whiteSpace: 'pre-wrap',
                                            wordBreak: 'break-word',
                                            background: op ? '#dbeafe' : '#f0f0f0',
                                            color: op ? '#0b3d91' : '#333',
                                        }}>
                                            <div style={{ fontSize: 11, opacity: 0.6, marginBottom: 2 }}>
                                                {op ? t('helpdesk.operator') : t('helpdesk.member')}
                                            </div>
                                            {m.body}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>

                        <div style={{ display: 'flex', gap: 8 }}>
                            <Input.TextArea
                                rows={2}
                                value={draft}
                                maxLength={4000}
                                placeholder={t('helpdesk.replyPlaceholder')}
                                onChange={(e) => setDraft(e.target.value)}
                            />
                            <Button type="primary" loading={sending} disabled={!draft.trim()} onClick={reply}>
                                {t('helpdesk.send')}
                            </Button>
                        </div>
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>{t('helpdesk.replyHint')}</Typography.Text>
                    </Space>
                )}
            </Card>
        </div>
    );
};

export default Helpdesk;
