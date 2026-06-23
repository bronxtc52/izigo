'use client';
import React, { useCallback, useEffect, useState } from 'react';
import { List, Button, Empty, Spin, Typography } from 'antd';
import { useTranslation } from 'next-i18next';
import { mmNotifications, mmNotificationRead, mmNotificationReadAll } from './api';

/**
 * C1 (Block C) — inbox партнёра в Mini App. Список своих уведомлений; непрочитанные
 * выделены жирным. Тап по непрочитанному / «прочитать всё» отмечает прочтение.
 * body — серверный Telegram-HTML (уже экранирован на бэке), выводим как разметку.
 */
const NotificationInbox = ({ initData, pal, isDark, onUnreadChange }) => {
    const { t } = useTranslation();
    const [items, setItems] = useState([]);
    const [loading, setLoading] = useState(true);

    const load = useCallback(async () => {
        setLoading(true);
        const res = await mmNotifications(initData);
        const list = res?.data ?? [];
        setItems(Array.isArray(list) ? list : []);
        setLoading(false);
        if (onUnreadChange) onUnreadChange(list.filter((n) => !n.read).length);
    }, [initData, onUnreadChange]);

    useEffect(() => { if (initData) load(); }, [initData, load]);

    const markRead = async (id) => {
        const item = items.find((n) => n.id === id);
        if (!item || item.read) return;
        await mmNotificationRead(initData, id);
        const next = items.map((n) => (n.id === id ? { ...n, read: true } : n));
        setItems(next);
        if (onUnreadChange) onUnreadChange(next.filter((n) => !n.read).length);
    };

    const markAll = async () => {
        await mmNotificationReadAll(initData);
        const next = items.map((n) => ({ ...n, read: true }));
        setItems(next);
        if (onUnreadChange) onUnreadChange(0);
    };

    const unread = items.filter((n) => !n.read).length;

    if (loading) return <div style={{ textAlign: 'center', padding: 32 }}><Spin /></div>;
    if (items.length === 0) return <Empty description={t('notifications.empty')} style={{ marginTop: 32 }} />;

    return (
        <div style={{ padding: 8 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                <Typography.Text strong>{t('notifications.title')}</Typography.Text>
                <Button size="small" disabled={unread === 0} onClick={markAll}>
                    {t('notifications.markAll')}
                </Button>
            </div>
            <List
                dataSource={items}
                renderItem={(n) => (
                    <List.Item
                        onClick={() => markRead(n.id)}
                        style={{
                            cursor: n.read ? 'default' : 'pointer',
                            background: n.read ? 'transparent' : (pal?.ghostBg ?? (isDark ? '#1f2733' : '#eef5ff')),
                            borderRadius: 8,
                            padding: '10px 12px',
                            marginBottom: 6,
                            border: 'none',
                        }}
                    >
                        <div style={{ width: '100%' }}>
                            {n.title ? (
                                <div style={{ fontWeight: n.read ? 500 : 700, marginBottom: 2 }}>{n.title}</div>
                            ) : null}
                            <div
                                style={{ fontWeight: n.read ? 400 : 600, whiteSpace: 'pre-wrap', color: pal?.fg }}
                                // body — серверный Telegram-HTML, экранированный на бэке (htmlspecialchars).
                                dangerouslySetInnerHTML={{ __html: n.body || '' }}
                            />
                            {n.created_at ? (
                                <div style={{ fontSize: 11, opacity: 0.6, marginTop: 4 }}>
                                    {new Date(n.created_at).toLocaleString()}
                                </div>
                            ) : null}
                        </div>
                    </List.Item>
                )}
            />
        </div>
    );
};

export default NotificationInbox;
