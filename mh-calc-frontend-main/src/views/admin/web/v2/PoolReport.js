'use client';
import React, { useEffect, useState } from 'react';
import { Table, Card, Space, Button, Descriptions, Tag, Typography, message, Empty, Popconfirm, Statistic, Row, Col, Result } from 'antd';
import { useTranslation } from 'react-i18next';
import * as api from './apiV2';
import { usd } from '../format';

// Отчёт 60%-калибровки (T11, flag mh_v2_pool). Реферальная — вне пула (MF-W3-3):
// gross==calibrated, factor не применяется. Суммы integer-центы; factor_bps → %.
const bpsPct = (bps) => `${((bps ?? 0) / 100).toFixed(2)}%`;
const dt = (iso) => (iso ? new Date(iso).toLocaleString('ru-RU') : '—');
const isOwner = () => { try { return api.getRoles().includes('owner'); } catch (e) { return false; } };

const PoolReport = () => {
    const { t } = useTranslation();
    const owner = isOwner();

    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [notAvailable, setNotAvailable] = useState(false);
    const [report, setReport] = useState(null);
    const [members, setMembers] = useState([]);
    const [busy, setBusy] = useState(false);

    const load = async () => {
        setLoading(true);
        const res = await api.listPoolPeriods();
        if (!res.ok) {
            // 403 = флаг mh_v2_pool OFF (движок пула не подключён) → пустое состояние, не ошибка.
            setNotAvailable(res.status === 403);
            setRows([]); setLoading(false); return;
        }
        setNotAvailable(false);
        setRows(res.data ?? []);
        setLoading(false);
    };

    useEffect(() => { load(); /* eslint-disable-next-line */ }, []);

    const openMonth = async (code) => {
        const [rep, mem] = await Promise.all([api.getPoolPeriod(code), api.getPoolMembers(code)]);
        if (!rep.ok) { message.error(rep.message || t('mhV2.pool.loadFailed')); return; }
        setReport(rep.data);
        setMembers(mem.ok ? (mem.data ?? []) : []);
    };

    const recalibrate = async (code) => {
        setBusy(true);
        const res = await api.recalibratePool(code);
        setBusy(false);
        if (!res.ok) { message.error(res.message || t('mhV2.pool.recalFailedCode', { code: res.status })); return; }
        message.success(t('mhV2.pool.recalibrated'));
        setReport(res.data);
        await load();
    };

    if (notAvailable) {
        return <Result icon={<span style={{ fontSize: 40 }}>⚙️</span>} title={t('mhV2.pool.engineOff')} subTitle={t('mhV2.pool.engineOffHint')} />;
    }

    const columns = [
        { title: t('mhV2.pool.colMonth'), dataIndex: 'month', render: (v) => <Typography.Text code>{v}</Typography.Text> },
        { title: t('mhV2.pool.colBase'), dataIndex: 'base_bv_cents', render: usd },
        { title: t('mhV2.pool.colCap'), dataIndex: 'pool_cap_cents', render: usd },
        { title: t('mhV2.pool.colTotalAfterCaps'), dataIndex: 'total_after_caps_cents', render: usd },
        { title: t('mhV2.pool.colFactor'), dataIndex: 'factor_bps', width: 100, render: (v) => <Tag color={v < 10000 ? 'orange' : 'green'}>{bpsPct(v)}</Tag> },
        { title: t('mhV2.pool.colRetained'), dataIndex: 'company_retained_cents', render: usd },
        { title: '', key: 'act', width: 90, render: (_, r) => <Button size="small" onClick={() => openMonth(r.month)}>{t('mhV2.pool.open')}</Button> },
    ];

    const memberColumns = [
        { title: t('mhV2.pool.colMember'), dataIndex: 'member_id', width: 90 },
        { title: t('mhV2.pool.colKind'), dataIndex: 'bonus_kind', width: 120, render: (v) => <Tag>{t(`mhV2.pool.kind.${v}`, v)}</Tag> },
        { title: t('mhV2.pool.colSource'), dataIndex: 'source_ref', ellipsis: true },
        { title: t('mhV2.pool.colAfterCaps'), dataIndex: 'amount_after_caps_cents', render: usd },
        { title: t('mhV2.pool.colCalibrated'), dataIndex: 'calibrated_cents', render: usd },
        { title: t('mhV2.pool.colRetainedRow'), dataIndex: 'retained_cents', render: usd },
    ];

    const bd = report?.breakdown;

    return (
        <Space direction="vertical" style={{ width: '100%' }} size="middle">
            <Button onClick={load}>{t('mhV2.refresh')}</Button>
            {rows.length === 0 && !loading && <Empty description={t('mhV2.pool.noCalibrations')} />}
            <Table rowKey="id" loading={loading} columns={columns} dataSource={rows} size="small" pagination={false} scroll={{ x: 'max-content' }} />

            {report && (
                <div style={{ borderTop: '1px solid #f0f0f0', paddingTop: 12 }}>
                    <Space style={{ marginBottom: 8 }} wrap>
                        <Typography.Title level={5} style={{ margin: 0 }}>{t('mhV2.pool.reportTitle')}: <Typography.Text code>{report.month}</Typography.Text></Typography.Title>
                        <Tag>run v{report.run_version}</Tag>
                        <Tag color={report.status === 'committed' ? 'green' : 'default'}>{report.status}</Tag>
                        {owner && (
                            <Popconfirm title={t('mhV2.pool.recalConfirm')} onConfirm={() => recalibrate(report.month)}>
                                <Button size="small" loading={busy}>{t('mhV2.pool.recalibrate')}</Button>
                            </Popconfirm>
                        )}
                    </Space>

                    <Row gutter={16} style={{ marginBottom: 12 }}>
                        <Col xs={12} md={6}><Card size="small"><Statistic title={t('mhV2.pool.colBase')} value={usd(report.base_bv_cents)} /></Card></Col>
                        <Col xs={12} md={6}><Card size="small"><Statistic title={`${t('mhV2.pool.colCap')} (${bpsPct(report.pool_rate_bps)})`} value={usd(report.pool_cap_cents)} /></Card></Col>
                        <Col xs={12} md={6}><Card size="small"><Statistic title={t('mhV2.pool.factor')} value={bpsPct(report.factor_bps)} valueStyle={{ color: report.factor_bps < 10000 ? '#d46b08' : '#389e0d' }} /></Card></Col>
                        <Col xs={12} md={6}><Card size="small"><Statistic title={t('mhV2.pool.colRetained')} value={usd(report.company_retained_cents)} /></Card></Col>
                    </Row>

                    <Descriptions size="small" bordered column={{ xs: 1, md: 2 }} title={t('mhV2.pool.breakdown')} style={{ marginBottom: 12 }}>
                        <Descriptions.Item label={`${t('mhV2.pool.kind.structure')} (${t('mhV2.pool.inPool')})`}>
                            {usd(bd?.structure?.after_caps_cents)} → <b>{usd(bd?.structure?.calibrated_cents)}</b> · {t('mhV2.pool.retained')}: {usd(bd?.structure?.retained_cents)}
                        </Descriptions.Item>
                        <Descriptions.Item label={`${t('mhV2.pool.kind.global')} (${t('mhV2.pool.inPool')})`}>
                            {usd(bd?.global?.after_caps_cents)} → <b>{usd(bd?.global?.calibrated_cents)}</b> · {t('mhV2.pool.retained')}: {usd(bd?.global?.retained_cents)}
                        </Descriptions.Item>
                        <Descriptions.Item label={`${t('mhV2.pool.kind.referral')} (${t('mhV2.pool.outOfPool')})`} span={2}>
                            {usd(bd?.referral?.gross_cents)} = <b>{usd(bd?.referral?.calibrated_cents)}</b> — {t('mhV2.pool.referralNote')}
                        </Descriptions.Item>
                    </Descriptions>

                    <Typography.Title level={5} style={{ marginBottom: 0 }}>{t('mhV2.pool.perMember')}</Typography.Title>
                    <Table rowKey={(r) => `${r.member_id}-${r.bonus_kind}-${r.source_ref}`} columns={memberColumns} dataSource={members} size="small" pagination={{ pageSize: 20 }} scroll={{ x: 'max-content' }} />
                </div>
            )}
        </Space>
    );
};

export default PoolReport;
