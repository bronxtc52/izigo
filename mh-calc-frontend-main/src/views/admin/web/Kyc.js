'use client';
import React, { useEffect, useState } from 'react';
import { Table, Segmented, Button, Space, Tag, Modal, Input, Result, message } from 'antd';
import * as api from '@/views/admin/webApi';

const STATUS = { pending: 'gold', approved: 'green', rejected: 'red' };
const FILTERS = [
    { label: 'Ожидают', value: 'pending' },
    { label: 'Одобрены', value: 'approved' },
    { label: 'Все', value: '' },
];

/** KYC-очередь: одобрение/отклонение заявок. owner/finance. */
const Kyc = () => {
    const [status, setStatus] = useState('pending');
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [forbidden, setForbidden] = useState(false);
    const [rejectId, setRejectId] = useState(null);
    const [reason, setReason] = useState('');

    const load = async (st = status) => {
        setLoading(true);
        const res = await api.fetchKyc(undefined, st);
        if (api.isForbidden(res)) { setForbidden(true); setLoading(false); return; }
        setRows(res?.data ?? []);
        setLoading(false);
    };
    useEffect(() => { load(status); /* eslint-disable-next-line */ }, [status]);

    const review = async (id, approve, rej = null) => {
        try { await api.reviewKyc(undefined, id, approve, rej); message.success('Готово'); load(status); }
        catch (e) { message.error('Не удалось'); }
    };

    if (forbidden) return <Result status="403" title="Недостаточно прав" />;

    const columns = [
        { title: 'ID', dataIndex: 'id' },
        { title: 'Партнёр', dataIndex: 'member_id', render: (v) => `#${v}` },
        { title: 'Статус', dataIndex: 'review_status', render: (v) => <Tag color={STATUS[v]}>{v}</Tag> },
        { title: 'Причина', dataIndex: 'reject_reason', render: (v) => v || '' },
        {
            title: '', render: (_, r) => r.review_status === 'pending' && (
                <Space>
                    <Button size="small" type="primary" onClick={() => review(r.id, true)}>Одобрить</Button>
                    <Button size="small" danger onClick={() => { setRejectId(r.id); setReason(''); }}>Отклонить</Button>
                </Space>
            ),
        },
    ];

    return (
        <Space direction="vertical" size={12} style={{ display: 'flex' }}>
            <Segmented value={status} onChange={setStatus} options={FILTERS} />
            <Table rowKey="id" loading={loading} columns={columns} dataSource={rows} size="small" />
            <Modal
                title="Причина отклонения" open={rejectId != null}
                onOk={() => { review(rejectId, false, reason.trim() || null); setRejectId(null); }}
                onCancel={() => setRejectId(null)} okText="Отклонить" okButtonProps={{ danger: true }}
            >
                <Input.TextArea rows={3} maxLength={1000} value={reason} onChange={(e) => setReason(e.target.value)} />
            </Modal>
        </Space>
    );
};

export default Kyc;
