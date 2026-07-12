'use client';
import React, { useEffect, useState } from 'react';
import {
    Card, Table, Tag, Button, Space, Form, Input, Select, Typography, message, Result, Popconfirm,
} from 'antd';
import { ReloadOutlined } from '@ant-design/icons';
import * as api from './refundsApi';

/**
 * T12 (mh-full-plan): веб-админка возвратов/сторно V2. Owner создаёт возврат по
 * заказу (full/partial), видит reversal-chain и очередь корректирующих проводок
 * закрытых периодов (approve/reject/post). Возврат средств покупателю — ВНЕ системы;
 * здесь фиксируется факт + сторнируются внутренние начисления. Секция за флагом
 * mh_v2_refunds (deny-by-default). Суммы — integer USD-центы.
 */
const usd = (cents) => (typeof cents === 'number' ? `$${(cents / 100).toFixed(2)}` : '—');

const returnStatusColor = {
    draft: 'default',
    reversing: 'blue',
    reversed: 'green',
    needs_manual: 'gold',
    failed: 'red',
};
const corrStatusColor = {
    proposed: 'gold',
    approved: 'blue',
    posted: 'green',
    rejected: 'default',
};

const RefundsV2View = () => {
    const [returns, setReturns] = useState([]);
    const [corrections, setCorrections] = useState([]);
    const [loading, setLoading] = useState(true);
    const [forbidden, setForbidden] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [form] = Form.useForm();

    const load = async () => {
        setLoading(true);
        const [r, c] = await Promise.all([
            api.listReturnsV2(),
            api.listPeriodCorrectionsV2(),
        ]);
        if (r?.error === 403 || c?.error === 403) {
            setForbidden(true);
            setLoading(false);
            return;
        }
        setReturns(Array.isArray(r?.data) ? r.data : []);
        setCorrections(Array.isArray(c?.data) ? c.data : []);
        setLoading(false);
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const submitReturn = async (values) => {
        setSubmitting(true);
        const r = await api.createReturnV2({
            order_id: Number(values.order_id),
            kind: values.kind,
            reason: values.reason,
        });
        setSubmitting(false);
        if (r?.error) {
            message.error(`Ошибка возврата (код ${r.error})`);
            return;
        }
        message.success('Возврат оформлен');
        form.resetFields();
        load();
    };

    const correctionAction = async (fn, id, ok) => {
        const r = await fn(id);
        if (r?.error) {
            message.error(`Ошибка (код ${r.error})`);
            return;
        }
        message.success(ok);
        load();
    };

    if (forbidden) return <Result status="403" title="Недостаточно прав" />;

    const returnColumns = [
        { title: 'ID', dataIndex: 'id', key: 'id', width: 70 },
        { title: 'Заказ', dataIndex: 'order_id', key: 'order_id', width: 90 },
        { title: 'Участник', dataIndex: 'member_id', key: 'member_id', width: 100 },
        { title: 'Тип', dataIndex: 'kind', key: 'kind', width: 90 },
        {
            title: 'BV возврата',
            dataIndex: 'returned_bv_cents',
            key: 'bv',
            render: usd,
        },
        {
            title: 'Статус',
            dataIndex: 'status',
            key: 'status',
            render: (s) => <Tag color={returnStatusColor[s] || 'default'}>{s}</Tag>,
        },
        { title: 'Причина', dataIndex: 'reason', key: 'reason', ellipsis: true },
    ];

    const corrColumns = [
        { title: 'ID', dataIndex: 'id', key: 'id', width: 70 },
        { title: 'Период', dataIndex: 'period_id', key: 'period_id', width: 90 },
        { title: 'Участник', dataIndex: 'member_id', key: 'member_id', width: 100 },
        { title: 'Бонус', dataIndex: 'bonus_type', key: 'bonus_type', width: 110 },
        { title: 'Сумма', dataIndex: 'amount_cents', key: 'amount_cents', render: usd },
        {
            title: 'Статус',
            dataIndex: 'status',
            key: 'status',
            render: (s) => <Tag color={corrStatusColor[s] || 'default'}>{s}</Tag>,
        },
        {
            title: 'Действия',
            key: 'actions',
            render: (_, row) => (
                <Space>
                    {row.status === 'proposed' && (
                        <>
                            <Button
                                size="small"
                                type="primary"
                                onClick={() => correctionAction(api.approveCorrectionV2, row.id, 'Утверждено')}
                            >
                                Утвердить
                            </Button>
                            <Button
                                size="small"
                                danger
                                onClick={() => correctionAction(api.rejectCorrectionV2, row.id, 'Отклонено')}
                            >
                                Отклонить
                            </Button>
                        </>
                    )}
                    {row.status === 'approved' && (
                        <Popconfirm
                            title="Провести корректирующую проводку?"
                            onConfirm={() => correctionAction(api.postCorrectionV2, row.id, 'Проведено')}
                        >
                            <Button size="small" type="primary">Провести</Button>
                        </Popconfirm>
                    )}
                </Space>
            ),
        },
    ];

    return (
        <Space direction="vertical" size="large" style={{ width: '100%' }}>
            <Typography.Title level={4} style={{ margin: 0 }}>
                Возвраты и сторно (V2)
            </Typography.Title>
            <Typography.Paragraph type="secondary" style={{ margin: 0 }}>
                Возврат средств покупателю выполняется вне системы. Здесь фиксируется факт
                возврата и сторнируются внутренние начисления (реферальная — сразу; структурная/
                лидерская закрытых периодов — корректирующими проводками).
            </Typography.Paragraph>

            <Card title="Оформить возврат" size="small">
                <Form form={form} layout="inline" onFinish={submitReturn}>
                    <Form.Item name="order_id" rules={[{ required: true, message: 'ID заказа' }]}>
                        <Input placeholder="ID заказа" style={{ width: 130 }} />
                    </Form.Item>
                    <Form.Item name="kind" initialValue="full" rules={[{ required: true }]}>
                        <Select
                            style={{ width: 150 }}
                            options={[
                                { value: 'full', label: 'Полный' },
                                { value: 'partial', label: 'Частичный' },
                            ]}
                        />
                    </Form.Item>
                    <Form.Item name="reason" rules={[{ required: true, message: 'Причина' }]}>
                        <Input placeholder="Причина" style={{ width: 260 }} />
                    </Form.Item>
                    <Form.Item>
                        <Button type="primary" htmlType="submit" loading={submitting}>
                            Оформить
                        </Button>
                    </Form.Item>
                </Form>
            </Card>

            <Card
                title="Возвраты"
                size="small"
                extra={<Button icon={<ReloadOutlined />} onClick={load}>Обновить</Button>}
            >
                <Table
                    rowKey="id"
                    size="small"
                    loading={loading}
                    dataSource={returns}
                    columns={returnColumns}
                    pagination={{ pageSize: 20 }}
                />
            </Card>

            <Card title="Корректировки закрытых периодов" size="small">
                <Table
                    rowKey="id"
                    size="small"
                    loading={loading}
                    dataSource={corrections}
                    columns={corrColumns}
                    pagination={{ pageSize: 20 }}
                />
            </Card>
        </Space>
    );
};

export default RefundsV2View;
