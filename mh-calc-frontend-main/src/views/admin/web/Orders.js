'use client';
import React, { useEffect, useState } from 'react';
import { Table, Segmented, Button, Tag, Modal, Select, Input, Result, message } from 'antd';
import * as api from '@/views/admin/webApi';
import { usd } from './format';

const STATUS = {
    pending_payment: { label: 'ожидает оплаты', color: 'gold' },
    paid: { label: 'оплачен', color: 'blue' },
    processing: { label: 'в обработке', color: 'cyan' },
    shipped: { label: 'отправлен', color: 'geekblue' },
    delivered: { label: 'доставлен', color: 'green' },
    cancelled: { label: 'отменён', color: 'default' },
    refunded: { label: 'возврат', color: 'red' },
};
const FILTERS = [
    { label: 'Оплачены', value: 'paid' },
    { label: 'В обработке', value: 'processing' },
    { label: 'Отправлены', value: 'shipped' },
    { label: 'Все', value: '' },
];
// Backend setStatus принимает только фулфилмент + терминальные (не paid/pending_payment).
const NEXT_STATUS = [
    { value: 'processing', label: 'в обработке' },
    { value: 'shipped', label: 'отправлен' },
    { value: 'delivered', label: 'доставлен' },
    { value: 'cancelled', label: 'отменён' },
    { value: 'refunded', label: 'возврат' },
];

/** Заказы: фильтр по статусу + смена статуса/трек-номера. owner/support. */
const Orders = () => {
    const [status, setStatus] = useState('paid');
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [forbidden, setForbidden] = useState(false);
    const [editing, setEditing] = useState(null); // null | order
    const [newStatus, setNewStatus] = useState('processing');
    const [tracking, setTracking] = useState('');
    const [saving, setSaving] = useState(false);

    const load = async (st = status) => {
        setLoading(true);
        const res = await api.fetchOrders(undefined, st);
        if (api.isForbidden(res)) { setForbidden(true); setLoading(false); return; }
        setRows(res?.data ?? []);
        setLoading(false);
    };
    useEffect(() => { load(status); /* eslint-disable-next-line */ }, [status]);

    const openEdit = (o) => {
        setEditing(o);
        setNewStatus('processing');
        setTracking(o.tracking_no ?? '');
    };

    const submit = async () => {
        setSaving(true);
        try {
            await api.updateOrderStatus(undefined, editing.id, newStatus, tracking.trim() || null);
            message.success('Статус обновлён');
            setEditing(null);
            load(status);
        } catch (e) {
            message.error(e?.status === 404 ? 'Недопустимый переход (заказ не оплачен?)' : 'Не удалось обновить');
        } finally {
            setSaving(false);
        }
    };

    if (forbidden) return <Result status="403" title="Недостаточно прав" />;

    const columns = [
        { title: 'ID', dataIndex: 'id', render: (v) => `#${v}` },
        {
            title: 'Позиции', dataIndex: 'items',
            render: (items) => (items ?? []).map((i) => `${i.name}${i.qty > 1 ? ` ×${i.qty}` : ''}`).join(', '),
        },
        { title: 'Сумма', dataIndex: 'total_usdt_cents', render: usd },
        { title: 'PV', dataIndex: 'total_pv' },
        { title: 'Статус', dataIndex: 'status', render: (v) => <Tag color={(STATUS[v] ?? {}).color}>{(STATUS[v] ?? {}).label ?? v}</Tag> },
        { title: 'Трек', dataIndex: 'tracking_no', render: (v) => v || '' },
        {
            title: '', render: (_, o) => (
                ['paid', 'processing', 'shipped', 'delivered'].includes(o.status) && (
                    <Button size="small" onClick={() => openEdit(o)}>Сменить статус</Button>
                )
            ),
        },
    ];

    return (
        <div>
            <Segmented value={status} onChange={setStatus} options={FILTERS} style={{ marginBottom: 12 }} />
            <Table rowKey="id" loading={loading} columns={columns} dataSource={rows} size="small" />
            <Modal
                title={editing ? `Заказ #${editing.id}` : ''} open={editing != null}
                onOk={submit} confirmLoading={saving} onCancel={() => setEditing(null)} okText="Сохранить"
            >
                <div style={{ marginBottom: 6 }}>Новый статус</div>
                <Select style={{ width: '100%', marginBottom: 12 }} value={newStatus} onChange={setNewStatus} options={NEXT_STATUS} />
                <div style={{ marginBottom: 6 }}>Трек-номер (необязательно)</div>
                <Input maxLength={128} value={tracking} onChange={(e) => setTracking(e.target.value)} placeholder="Напр. RU123456789" />
            </Modal>
        </div>
    );
};

export default Orders;
