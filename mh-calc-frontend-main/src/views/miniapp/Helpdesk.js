'use client';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { List, Button, Empty, Spin, Typography, Input, Modal, Tag, message } from 'antd';
import { useTranslation } from 'next-i18next';
import {
    mmTickets, mmTicketCreate, mmTicket, mmTicketMessage, mmTicketPoll,
} from './api';

const POLL_MS = 6000; // C2: polling треда 5–8с (Gate-A п.6)

const statusColor = {
    open: 'blue',
    in_progress: 'gold',
    resolved: 'green',
    closed: 'default',
};

/**
 * C2 (Block C) — поддержка в Mini App. Список своих тикетов → чат-тред по тикету с
 * polling (5–8с). Создание тикета. body сообщений — сырой текст (экранируем как
 * текст, не HTML). Скоуп «только свои» обеспечен бэкендом.
 */
const Helpdesk = ({ initData, pal, isDark }) => {
    const { t } = useTranslation();
    const [tickets, setTickets] = useState([]);
    const [loading, setLoading] = useState(true);
    const [active, setActive] = useState(null); // открытый тикет {id,subject,status}
    const [messages, setMessages] = useState([]);
    const [draft, setDraft] = useState('');
    const [sending, setSending] = useState(false);

    // Создание тикета
    const [createOpen, setCreateOpen] = useState(false);
    const [newSubject, setNewSubject] = useState('');
    const [newBody, setNewBody] = useState('');
    const [creating, setCreating] = useState(false);

    const cursorRef = useRef(0);
    const pollRef = useRef(null);

    const loadTickets = useCallback(async () => {
        setLoading(true);
        const res = await mmTickets(initData);
        const list = res?.data ?? [];
        setTickets(Array.isArray(list) ? list : []);
        setLoading(false);
    }, [initData]);

    useEffect(() => { if (initData) loadTickets(); }, [initData, loadTickets]);

    const stopPoll = useCallback(() => {
        if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null; }
    }, []);

    const pollOnce = useCallback(async (ticketId) => {
        const res = await mmTicketPoll(initData, ticketId, cursorRef.current);
        const data = res?.data;
        if (!data) return;
        if (Array.isArray(data.messages) && data.messages.length > 0) {
            setMessages((prev) => [...prev, ...data.messages]);
            cursorRef.current = data.cursor;
        }
        if (data.status) {
            setActive((prev) => (prev ? { ...prev, status: data.status } : prev));
        }
    }, [initData]);

    const openTicket = useCallback(async (ticket) => {
        stopPoll();
        setActive(ticket);
        setMessages([]);
        cursorRef.current = 0;
        const res = await mmTicket(initData, ticket.id);
        const list = res?.data?.messages ?? [];
        setMessages(Array.isArray(list) ? list : []);
        cursorRef.current = list.length ? list[list.length - 1].id : 0;
        // Запускаем polling треда.
        pollRef.current = setInterval(() => pollOnce(ticket.id), POLL_MS);
    }, [initData, pollOnce, stopPoll]);

    const closeThread = useCallback(() => {
        stopPoll();
        setActive(null);
        setMessages([]);
        cursorRef.current = 0;
        loadTickets();
    }, [stopPoll, loadTickets]);

    // Очистка интервала при размонтировании.
    useEffect(() => () => stopPoll(), [stopPoll]);

    const send = async () => {
        const body = draft.trim();
        if (!body || !active) return;
        setSending(true);
        try {
            const res = await mmTicketMessage(initData, active.id, body);
            if (res?.data) {
                setMessages((prev) => [...prev, res.data]);
                cursorRef.current = res.data.id;
                setDraft('');
            } else {
                message.error(t('helpdesk.sendFailed'));
            }
        } finally {
            setSending(false);
        }
    };

    const createTicket = async () => {
        const subject = newSubject.trim();
        const body = newBody.trim();
        if (!subject) { message.warning(t('helpdesk.subjectRequired')); return; }
        if (!body) { message.warning(t('helpdesk.bodyRequired')); return; }
        setCreating(true);
        try {
            const res = await mmTicketCreate(initData, subject, body);
            if (res?.data) {
                setCreateOpen(false);
                setNewSubject('');
                setNewBody('');
                await loadTickets();
                openTicket(res.data);
            } else {
                message.error(t('helpdesk.createFailed'));
            }
        } finally {
            setCreating(false);
        }
    };

    // --- Экран чата ---
    if (active) {
        return (
            <div style={{ padding: 8, display: 'flex', flexDirection: 'column', height: '100%' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                    <Button size="small" onClick={closeThread}>{t('helpdesk.back')}</Button>
                    <Tag color={statusColor[active.status] || 'default'}>{t(`helpdesk.status_${active.status}`)}</Tag>
                </div>
                <Typography.Text strong style={{ marginBottom: 8 }}>{active.subject}</Typography.Text>
                <div style={{ flex: 1, overflowY: 'auto', marginBottom: 8 }}>
                    {messages.map((m) => {
                        const mine = m.author_role === 'member';
                        return (
                            <div key={m.id} style={{ display: 'flex', justifyContent: mine ? 'flex-end' : 'flex-start', marginBottom: 6 }}>
                                <div style={{
                                    maxWidth: '80%',
                                    padding: '8px 12px',
                                    borderRadius: 12,
                                    whiteSpace: 'pre-wrap',
                                    wordBreak: 'break-word',
                                    background: mine ? (pal?.brand ?? '#7C3AED') : (pal?.ghostBg ?? (isDark ? '#1f2733' : '#f0f0f0')),
                                    color: mine ? '#fff' : pal?.fg,
                                }}>
                                    {m.body}
                                </div>
                            </div>
                        );
                    })}
                </div>
                {active.status !== 'closed' ? (
                    <div style={{ display: 'flex', gap: 8 }}>
                        <Input.TextArea
                            rows={2}
                            value={draft}
                            maxLength={4000}
                            placeholder={t('helpdesk.messagePlaceholder')}
                            onChange={(e) => setDraft(e.target.value)}
                        />
                        <Button type="primary" loading={sending} disabled={!draft.trim()} onClick={send}>
                            {t('helpdesk.send')}
                        </Button>
                    </div>
                ) : (
                    <Typography.Text type="secondary">{t('helpdesk.closedHint')}</Typography.Text>
                )}
            </div>
        );
    }

    // --- Список тикетов ---
    return (
        <div style={{ padding: 8 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                <Typography.Text strong>{t('helpdesk.title')}</Typography.Text>
                <Button size="small" type="primary" onClick={() => setCreateOpen(true)}>
                    {t('helpdesk.newTicket')}
                </Button>
            </div>

            {loading ? (
                <div style={{ textAlign: 'center', padding: 32 }}><Spin /></div>
            ) : tickets.length === 0 ? (
                <Empty description={t('helpdesk.empty')} style={{ marginTop: 32 }} />
            ) : (
                <List
                    dataSource={tickets}
                    renderItem={(tk) => (
                        <List.Item
                            onClick={() => openTicket(tk)}
                            style={{ cursor: 'pointer', borderRadius: 8, padding: '10px 12px', marginBottom: 6, border: 'none', background: pal?.ghostBg ?? (isDark ? '#1f2733' : '#f7f9fc') }}
                        >
                            <div style={{ width: '100%' }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                                    <span style={{ fontWeight: 600, color: pal?.fg }}>{tk.subject}</span>
                                    <Tag color={statusColor[tk.status] || 'default'}>{t(`helpdesk.status_${tk.status}`)}</Tag>
                                </div>
                                {tk.last_message_at ? (
                                    <div style={{ fontSize: 11, opacity: 0.6, marginTop: 4 }}>
                                        {new Date(tk.last_message_at).toLocaleString()}
                                    </div>
                                ) : null}
                            </div>
                        </List.Item>
                    )}
                />
            )}

            <Modal
                open={createOpen}
                title={t('helpdesk.newTicket')}
                okText={t('helpdesk.create')}
                cancelText={t('helpdesk.cancel')}
                confirmLoading={creating}
                onOk={createTicket}
                onCancel={() => setCreateOpen(false)}
            >
                <Input
                    value={newSubject}
                    maxLength={160}
                    placeholder={t('helpdesk.subjectPlaceholder')}
                    onChange={(e) => setNewSubject(e.target.value)}
                    style={{ marginBottom: 8 }}
                />
                <Input.TextArea
                    rows={4}
                    value={newBody}
                    maxLength={4000}
                    placeholder={t('helpdesk.bodyPlaceholder')}
                    onChange={(e) => setNewBody(e.target.value)}
                />
            </Modal>
        </div>
    );
};

export default Helpdesk;
