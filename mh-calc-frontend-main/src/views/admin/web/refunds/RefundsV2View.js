'use client';
import React, { useEffect, useState } from 'react';
import {
    Card, Table, Tag, Button, Space, Form, Input, InputNumber, Select, Typography, message, Result, Popconfirm,
} from 'antd';
import { ReloadOutlined, PlusOutlined, MinusCircleOutlined } from '@ant-design/icons';
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
    const kind = Form.useWatch('kind', form);

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
        const payload = {
            order_id: Number(values.order_id),
            kind: values.kind,
            reason: values.reason,
        };
        // MF-W5-3: частичный возврат обязан нести позиции (order_item_id + qty), иначе бэк
        // отклоняет его 422 «требует непустой список позиций». Full — все позиции целиком.
        if (values.kind === 'partial') {
            const lines = (values.lines || [])
                .filter((l) => l && l.order_item_id !== undefined && l.order_item_id !== null && l.order_item_id !== '')
                .map((l) => ({ order_item_id: Number(l.order_item_id), qty: Number(l.qty) }));
            if (lines.length === 0) {
                message.error('Для частичного возврата добавьте хотя бы одну позицию');
                return;
            }
            payload.lines = lines;
        }

        setSubmitting(true);
        const r = await api.createReturnV2(payload);
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
                <Form form={form} layout="vertical" onFinish={submitReturn}>
                    <Space wrap align="start">
                        <Form.Item
                            name="order_id"
                            label="ID заказа"
                            rules={[{ required: true, message: 'ID заказа' }]}
                        >
                            <Input placeholder="ID заказа" style={{ width: 130 }} />
                        </Form.Item>
                        <Form.Item name="kind" label="Тип" initialValue="full" rules={[{ required: true }]}>
                            <Select
                                style={{ width: 150 }}
                                options={[
                                    { value: 'full', label: 'Полный' },
                                    { value: 'partial', label: 'Частичный' },
                                ]}
                            />
                        </Form.Item>
                        <Form.Item name="reason" label="Причина" rules={[{ required: true, message: 'Причина' }]}>
                            <Input placeholder="Причина" style={{ width: 260 }} />
                        </Form.Item>
                    </Space>

                    {kind === 'partial' && (
                        <Form.Item label="Позиции возврата (order_item_id + кол-во)">
                            <Form.List name="lines">
                                {(fields, { add, remove }) => (
                                    <Space direction="vertical" style={{ width: '100%' }}>
                                        {fields.map((field) => (
                                            <Space key={field.key} align="baseline">
                                                <Form.Item
                                                    name={[field.name, 'order_item_id']}
                                                    rules={[{ required: true, message: 'order_item_id' }]}
                                                    style={{ marginBottom: 0 }}
                                                >
                                                    <InputNumber placeholder="order_item_id" min={1} style={{ width: 160 }} />
                                                </Form.Item>
                                                <Form.Item
                                                    name={[field.name, 'qty']}
                                                    rules={[{ required: true, message: 'кол-во' }]}
                                                    style={{ marginBottom: 0 }}
                                                >
                                                    <InputNumber placeholder="кол-во" min={1} style={{ width: 110 }} />
                                                </Form.Item>
                                                <MinusCircleOutlined onClick={() => remove(field.name)} />
                                            </Space>
                                        ))}
                                        <Button type="dashed" onClick={() => add()} icon={<PlusOutlined />}>
                                            Добавить позицию
                                        </Button>
                                    </Space>
                                )}
                            </Form.List>
                        </Form.Item>
                    )}

                    <Form.Item style={{ marginBottom: 0 }}>
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
