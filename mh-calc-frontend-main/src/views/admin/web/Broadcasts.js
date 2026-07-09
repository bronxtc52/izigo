'use client';
import React, { useState } from 'react';
import { Card, Input, Select, Button, Space, message, Typography, Alert, Popconfirm } from 'antd';
import { useTranslation } from 'react-i18next';
import * as api from '@/views/admin/webApi';

/**
 * Рассылки (C1, Block C): owner/support. Текст (markdown) + выбор сегмента
 * (all / by_status / by_rank) + «Превью охвата» (dry-run) + «Отправить» (Popconfirm).
 * Текст уходит сырьём — нормализация в Telegram-HTML на бэке.
 */
const Broadcasts = () => {
    const { t } = useTranslation();
    const [segmentType, setSegmentType] = useState('all');
    const [segmentValue, setSegmentValue] = useState('');
    const [body, setBody] = useState('');
    const [preview, setPreview] = useState(null);
    const [loadingPreview, setLoadingPreview] = useState(false);
    const [sending, setSending] = useState(false);

    const needsValue = segmentType === 'by_status' || segmentType === 'by_rank';

    const onPreview = async () => {
        setLoadingPreview(true);
        setPreview(null);
        try {
            const res = await api.previewBroadcast(undefined, segmentType, needsValue ? segmentValue : null);
            setPreview(res);
        } catch (e) {
            message.error(e?.status === 403 ? t('notifications.bcForbidden') : t('notifications.bcPreviewFailed'));
        } finally {
            setLoadingPreview(false);
        }
    };

    const onSend = async () => {
        if (!body.trim()) { message.warning(t('notifications.bcBodyRequired')); return; }
        setSending(true);
        try {
            const res = await api.sendBroadcast(undefined, segmentType, needsValue ? segmentValue : null, body.trim());
            message.success(t('notifications.bcSent', { count: res?.recipients_count ?? 0 }));
            setBody('');
            setPreview(null);
        } catch (e) {
            message.error(e?.status === 403 ? t('notifications.bcForbidden') : t('notifications.bcSendFailed'));
        } finally {
            setSending(false);
        }
    };

    return (
        <Card title={t('notifications.bcTitle')} style={{ maxWidth: 720 }}>
            <Space direction="vertical" style={{ width: '100%' }} size="middle">
                <Space wrap>
                    <Select
                        value={segmentType}
                        style={{ width: 200 }}
                        onChange={(v) => { setSegmentType(v); setPreview(null); }}
                        options={[
                            { value: 'all', label: t('notifications.segAll') },
                            { value: 'by_status', label: t('notifications.segByStatus') },
                            { value: 'by_rank', label: t('notifications.segByRank') },
                        ]}
                    />
                    {segmentType === 'by_status' && (
                        <Select
                            value={segmentValue || undefined}
                            style={{ width: 180 }}
                            placeholder={t('notifications.segStatusPlaceholder')}
                            onChange={(v) => { setSegmentValue(v); setPreview(null); }}
                            options={[
                                { value: 'active', label: t('notifications.statusActive') },
                                { value: 'registered', label: t('notifications.statusRegistered') },
                            ]}
                        />
                    )}
                    {segmentType === 'by_rank' && (
                        <Input
                            value={segmentValue}
                            style={{ width: 180 }}
                            placeholder={t('notifications.segRankPlaceholder')}
                            onChange={(e) => { setSegmentValue(e.target.value.replace(/\D/g, '')); setPreview(null); }}
                        />
                    )}
                    <Button onClick={onPreview} loading={loadingPreview}>
                        {t('notifications.bcPreview')}
                    </Button>
                </Space>

                {preview && (
                    <Alert
                        type="info"
                        showIcon
                        message={t('notifications.bcReach', { count: preview.recipients_count ?? 0 })}
                    />
                )}

                <div>
                    <Typography.Text type="secondary">{t('notifications.bcBodyHint')}</Typography.Text>
                    <Input.TextArea
                        rows={6}
                        value={body}
                        maxLength={4000}
                        showCount
                        onChange={(e) => setBody(e.target.value)}
                        placeholder={t('notifications.bcBodyPlaceholder')}
                    />
                </div>

                <Popconfirm
                    title={t('notifications.bcConfirmTitle')}
                    description={
                        preview
                            ? t('notifications.bcConfirmDesc', { count: preview.recipients_count ?? 0 })
                            : t('notifications.bcConfirmNoPreview')
                    }
                    okText={t('notifications.bcConfirmOk')}
                    cancelText={t('notifications.bcConfirmCancel')}
                    onConfirm={onSend}
                    disabled={!body.trim() || (needsValue && !segmentValue)}
                >
                    <Button
                        type="primary"
                        loading={sending}
                        disabled={!body.trim() || (needsValue && !segmentValue)}
                    >
                        {t('notifications.bcSend')}
                    </Button>
                </Popconfirm>
            </Space>
        </Card>
    );
};

export default Broadcasts;
