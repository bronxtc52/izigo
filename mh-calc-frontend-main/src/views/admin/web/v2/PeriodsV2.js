'use client';
import React, { useEffect, useState } from 'react';
import { Table, Tag, Space, Select, Button, Drawer, Typography, Descriptions, message, Result } from 'antd';
import { useTranslation } from 'react-i18next';
import * as api from './apiV2';

// Периоды — строго read-only в T13 (решение владельца, Дефолты Гейта A: закрытие —
// только джобы T04; изменение закрытых периодов запрещено, корректировки — контур T12).
const TYPE_COLOR = { half_month: 'blue', month: 'geekblue', quarter: 'purple' };
const STATUS_COLOR = { open: 'processing', closing: 'warning', closed: 'default' };
const dt = (iso) => (iso ? new Date(iso).toLocaleString('ru-RU') : '—');

const PeriodsV2 = () => {
    const { t } = useTranslation();
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [forbidden, setForbidden] = useState(false);
    const [type, setType] = useState('');
    const [status, setStatus] = useState('');
    const [detail, setDetail] = useState(null);
    const [detailLoading, setDetailLoading] = useState(false);

    const load = async () => {
        setLoading(true);
        const res = await api.listPeriods({ type: type || undefined, status: status || undefined });
        if (!res.ok) { if (res.status === 403) setForbidden(true); setRows([]); setLoading(false); return; }
        setRows(res.data ?? []);
        setLoading(false);
    };

    useEffect(() => { load(); /* eslint-disable-next-line */ }, [type, status]);

    const openDetail = async (id) => {
        setDetailLoading(true);
        setDetail({ loading: true });
        const res = await api.getPeriod(id);
        setDetailLoading(false);
        if (!res.ok) { message.error(t('mhV2.periods.loadFailed')); setDetail(null); return; }
        setDetail(res.data);
    };

    if (forbidden) return <Result status="403" title={t('mhV2.forbidden')} />;

    const columns = [
        { title: 'ID', dataIndex: 'id', width: 60 },
        { title: t('mhV2.periods.colCode'), dataIndex: 'code', render: (v) => <Typography.Text code>{v}</Typography.Text> },
        { title: t('mhV2.periods.colType'), dataIndex: 'period_type', width: 120, render: (v) => <Tag color={TYPE_COLOR[v]}>{t(`mhV2.periods.type.${v}`, v)}</Tag> },
        { title: t('mhV2.periods.colStart'), dataIndex: 'starts_at', width: 170, render: dt },
        { title: t('mhV2.periods.colEnd'), dataIndex: 'ends_at', width: 170, render: dt },
        { title: t('mhV2.periods.colStatus'), dataIndex: 'status', width: 110, render: (v) => <Tag color={STATUS_COLOR[v]}>{t(`mhV2.periods.status.${v}`, v)}</Tag> },
        { title: t('mhV2.periods.colPolicy'), dataIndex: 'policy_version_id', width: 90, render: (v) => v ?? '—' },
        { title: t('mhV2.periods.colRuns'), dataIndex: 'runs_count', width: 80 },
        { title: '', key: 'act', width: 90, render: (_, r) => <Button size="small" onClick={() => openDetail(r.id)}>{t('mhV2.periods.detail')}</Button> },
    ];

    return (
        <Space direction="vertical" style={{ width: '100%' }} size="middle">
            <Space wrap>
                <Select allowClear placeholder={t('mhV2.periods.filterType')} style={{ width: 160 }} value={type || undefined} onChange={(v) => setType(v ?? '')}
                    options={['half_month', 'month', 'quarter'].map((v) => ({ value: v, label: t(`mhV2.periods.type.${v}`, v) }))} />
                <Select allowClear placeholder={t('mhV2.periods.filterStatus')} style={{ width: 160 }} value={status || undefined} onChange={(v) => setStatus(v ?? '')}
                    options={['open', 'closing', 'closed'].map((v) => ({ value: v, label: t(`mhV2.periods.status.${v}`, v) }))} />
                <Button onClick={load}>{t('mhV2.refresh')}</Button>
            </Space>

            <Table rowKey="id" loading={loading} columns={columns} dataSource={rows} size="small" pagination={{ pageSize: 20 }} scroll={{ x: 'max-content' }} />

            <Drawer
                open={!!detail}
                onClose={() => setDetail(null)}
                width={640}
                title={detail && !detail.loading ? <span>{t('mhV2.periods.detailTitle')}: <Typography.Text code>{detail.code}</Typography.Text></span> : t('mhV2.periods.detailTitle')}
                loading={detailLoading}
            >
                {detail && !detail.loading && (
                    <Space direction="vertical" style={{ width: '100%' }}>
                        <Descriptions size="small" column={1} bordered>
                            <Descriptions.Item label={t('mhV2.periods.colType')}><Tag color={TYPE_COLOR[detail.period_type]}>{t(`mhV2.periods.type.${detail.period_type}`, detail.period_type)}</Tag></Descriptions.Item>
                            <Descriptions.Item label={t('mhV2.periods.colStatus')}><Tag color={STATUS_COLOR[detail.status]}>{t(`mhV2.periods.status.${detail.status}`, detail.status)}</Tag></Descriptions.Item>
                            <Descriptions.Item label={t('mhV2.periods.colStart')}>{dt(detail.starts_at)}</Descriptions.Item>
                            <Descriptions.Item label={t('mhV2.periods.colEnd')}>{dt(detail.ends_at)}</Descriptions.Item>
                            <Descriptions.Item label="TZ">{detail.timezone ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label={t('mhV2.periods.colPolicy')}>{detail.policy_version_id ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label={t('mhV2.periods.closedAt')}>{dt(detail.closed_at)}</Descriptions.Item>
                        </Descriptions>

                        <Typography.Title level={5} style={{ marginBottom: 0 }}>{t('mhV2.periods.runs')} ({(detail.runs ?? []).length})</Typography.Title>
                        {(detail.runs ?? []).length === 0 && <Typography.Text type="secondary">{t('mhV2.periods.noRuns')}</Typography.Text>}
                        {(detail.runs ?? []).map((run) => (
                            <Descriptions key={run.id} size="small" column={2} bordered title={<span>run #{run.run_no} <Tag>{run.mode}</Tag> <Tag color={run.status === 'ok' || run.status === 'done' || run.status === 'completed' ? 'green' : 'default'}>{run.status}</Tag></span>}>
                                <Descriptions.Item label={t('mhV2.periods.startedAt')}>{dt(run.started_at)}</Descriptions.Item>
                                <Descriptions.Item label={t('mhV2.periods.finishedAt')}>{dt(run.finished_at)}</Descriptions.Item>
                                <Descriptions.Item label={t('mhV2.periods.engine')}>{run.engine_version ?? '—'}</Descriptions.Item>
                                <Descriptions.Item label="hash">{(run.result_hash ?? '').slice(0, 12) || '—'}</Descriptions.Item>
                                {run.error && <Descriptions.Item label={t('mhV2.periods.error')} span={2}><Typography.Text type="danger">{run.error}</Typography.Text></Descriptions.Item>}
                                <Descriptions.Item label={t('mhV2.periods.steps')} span={2}>
                                    <pre style={{ whiteSpace: 'pre-wrap', margin: 0, fontSize: 11, maxHeight: 240, overflow: 'auto' }}>{JSON.stringify(run.step_results ?? {}, null, 2)}</pre>
                                </Descriptions.Item>
                                {run.snapshot && <Descriptions.Item label={t('mhV2.periods.snapshot')} span={2}>#{run.snapshot.id} · {(run.snapshot.payload_hash ?? '').slice(0, 12)} · {dt(run.snapshot.created_at)}</Descriptions.Item>}
                            </Descriptions>
                        ))}
                    </Space>
                )}
            </Drawer>
        </Space>
    );
};

export default PeriodsV2;
