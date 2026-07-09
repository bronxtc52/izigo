'use client';
import React, { useEffect, useState } from 'react';
import { Row, Col, Card, Statistic, Table, Tag, Button, Result, Space, Typography, Alert } from 'antd';
import { ReloadOutlined } from '@ant-design/icons';
import { useTranslation } from 'react-i18next';
import * as api from '@/views/admin/webApi';

/**
 * Мониторинг (C7, Block C): owner-only READ-ONLY панель здоровья фонового конвейера
 * уведомлений. Фон проекта = планировщик (НЕ async-очередь), поэтому смотрим
 * notification_outbox (C1) + здоровье диспетчера; failed_jobs — справочно.
 * Никаких write-действий — только «Обновить».
 */
const statusColor = {
    pending: 'gold',
    sending: 'blue',
    sent: 'green',
    failed: 'red',
    skipped: 'default',
};

const fmtTime = (iso) => (iso ? new Date(iso).toLocaleString('ru-RU') : '—');

const Monitoring = () => {
    const { t } = useTranslation();
    const [summary, setSummary] = useState(null);
    const [problems, setProblems] = useState([]);
    const [loading, setLoading] = useState(true);
    const [forbidden, setForbidden] = useState(false);

    const load = async () => {
        setLoading(true);
        const [s, p] = await Promise.all([
            api.fetchMonitoringOutbox(undefined),
            api.fetchMonitoringProblems(undefined),
        ]);
        if (api.isForbidden(s) || api.isForbidden(p)) {
            setForbidden(true);
            setLoading(false);
            return;
        }
        setSummary(s?.data ?? null);
        setProblems(p?.data ?? []);
        setLoading(false);
    };

    useEffect(() => { load(); /* eslint-disable-next-line */ }, []);

    if (forbidden) return <Result status="403" title={t('monitoring.forbidden')} />;

    const counts = summary?.counts ?? {};
    const stuck = summary?.stuck ?? {};
    const scheduler = summary?.scheduler ?? {};
    const failedJobs = summary?.failed_jobs ?? {};

    const columns = [
        { title: 'ID', dataIndex: 'id', key: 'id', width: 70 },
        { title: t('monitoring.colKind'), dataIndex: 'kind', key: 'kind' },
        {
            title: t('monitoring.colStatus'),
            dataIndex: 'status',
            key: 'status',
            render: (s) => <Tag color={statusColor[s] || 'default'}>{s}</Tag>,
        },
        {
            title: t('monitoring.colAttempts'),
            key: 'attempts',
            render: (_, r) => `${r.attempts}/${r.max_attempts}`,
        },
        { title: t('monitoring.colLastError'), dataIndex: 'last_error', key: 'last_error', render: (v) => v || '—' },
        { title: t('monitoring.colCreatedAt'), dataIndex: 'created_at', key: 'created_at', render: fmtTime },
    ];

    return (
        <Space direction="vertical" size="middle" style={{ width: '100%' }}>
            <Row justify="space-between" align="middle">
                <Col><Typography.Title level={4} style={{ margin: 0 }}>{t('monitoring.title')}</Typography.Title></Col>
                <Col>
                    <Button icon={<ReloadOutlined />} onClick={load} loading={loading}>
                        {t('monitoring.refresh')}
                    </Button>
                </Col>
            </Row>

            <Row gutter={[12, 12]}>
                <Col xs={12} sm={8} md={4}><Card size="small" loading={loading}><Statistic title={t('monitoring.pending')} value={counts.pending ?? 0} /></Card></Col>
                <Col xs={12} sm={8} md={4}><Card size="small" loading={loading}><Statistic title={t('monitoring.sending')} value={counts.sending ?? 0} /></Card></Col>
                <Col xs={12} sm={8} md={4}><Card size="small" loading={loading}><Statistic title={t('monitoring.sent')} value={counts.sent ?? 0} /></Card></Col>
                <Col xs={12} sm={8} md={4}><Card size="small" loading={loading}><Statistic title={t('monitoring.failed')} value={counts.failed ?? 0} valueStyle={(counts.failed ?? 0) > 0 ? { color: '#cf1322' } : undefined} /></Card></Col>
                <Col xs={12} sm={8} md={4}><Card size="small" loading={loading}><Statistic title={t('monitoring.skipped')} value={counts.skipped ?? 0} /></Card></Col>
                <Col xs={12} sm={8} md={4}><Card size="small" loading={loading}><Statistic title={t('monitoring.stuck')} value={stuck.count ?? 0} valueStyle={(stuck.count ?? 0) > 0 ? { color: '#cf1322' } : undefined} suffix={stuck.threshold_minutes ? `(>${stuck.threshold_minutes}m)` : undefined} /></Card></Col>
            </Row>

            <Card size="small" title={t('monitoring.scheduler')} loading={loading}>
                <Space direction="vertical">
                    <Typography.Text>{t('monitoring.lastProcessed')}: <b>{fmtTime(scheduler.last_processed_at)}</b></Typography.Text>
                    <Typography.Text>{t('monitoring.lastSent')}: <b>{fmtTime(scheduler.last_sent_at)}</b></Typography.Text>
                    <Typography.Text>{t('monitoring.pendingDue')}: <b>{scheduler.pending_due ?? 0}</b></Typography.Text>
                </Space>
            </Card>

            <Alert
                type="info"
                showIcon
                message={`${t('monitoring.failedJobs')}: ${failedJobs.count ?? 0}`}
                description={t('monitoring.failedJobsNote')}
            />

            <Card size="small" title={t('monitoring.problemsTitle')}>
                <Table
                    rowKey="id"
                    loading={loading}
                    columns={columns}
                    dataSource={problems}
                    size="small"
                    pagination={false}
                    locale={{ emptyText: t('monitoring.empty') }}
                />
            </Card>
        </Space>
    );
};

export default Monitoring;
