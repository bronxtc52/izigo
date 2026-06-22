'use client';
import React, { useEffect, useState } from 'react';
import { Table, Button, Space, Tag, Modal, Form, Input, InputNumber, Switch, Result, message, Popconfirm } from 'antd';
import * as api from '@/views/admin/webApi';
import { usd } from './format';

/** Управление каталогом: список, создание, редактирование, архивация. owner/support. */
const Products = () => {
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [forbidden, setForbidden] = useState(false);
    const [editing, setEditing] = useState(null); // null | {} (new) | product
    const [form] = Form.useForm();

    const load = async () => {
        setLoading(true);
        const res = await api.fetchProducts();
        if (api.isForbidden(res)) { setForbidden(true); setLoading(false); return; }
        setRows(res?.data ?? []);
        setLoading(false);
    };
    useEffect(() => { load(); }, []);

    const openEdit = (p) => {
        setEditing(p);
        form.setFieldsValue(p?.id ? p : { is_active: true, pv: 0, sort: 0, price_usdt_cents: 0 });
    };

    const submit = async () => {
        const values = await form.validateFields();
        try {
            if (editing?.id) await api.updateProduct(undefined, editing.id, values);
            else await api.createProduct(undefined, values);
            message.success('Сохранено');
            setEditing(null);
            load();
        } catch (e) {
            message.error(e?.status === 422 ? 'Проверьте поля (sku уникален, тариф существует)' : 'Не удалось сохранить');
        }
    };

    const archive = async (id) => {
        try { await api.deleteProduct(undefined, id); message.success('В архив'); load(); }
        catch (e) { message.error('Не удалось'); }
    };

    if (forbidden) return <Result status="403" title="Недостаточно прав" />;

    const columns = [
        { title: 'ID', dataIndex: 'id' },
        { title: 'Название', dataIndex: 'name' },
        { title: 'SKU', dataIndex: 'sku' },
        { title: 'Цена', dataIndex: 'price_usdt_cents', render: usd },
        { title: 'PV', dataIndex: 'pv' },
        { title: 'Тариф', dataIndex: 'package_id' },
        { title: 'Активен', dataIndex: 'is_active', render: (v) => (v ? <Tag color="green">да</Tag> : <Tag>архив</Tag>) },
        {
            title: '', render: (_, p) => (
                <Space>
                    <Button size="small" onClick={() => openEdit(p)}>Изменить</Button>
                    {p.is_active && (
                        <Popconfirm title="В архив?" onConfirm={() => archive(p.id)}>
                            <Button size="small" danger>Архив</Button>
                        </Popconfirm>
                    )}
                </Space>
            ),
        },
    ];

    return (
        <Space direction="vertical" size={12} style={{ display: 'flex' }}>
            <Button type="primary" onClick={() => openEdit({})}>+ Товар</Button>
            <Table rowKey="id" loading={loading} columns={columns} dataSource={rows} size="small" />
            <Modal
                title={editing?.id ? 'Редактировать товар' : 'Новый товар'}
                open={editing != null}
                onOk={submit}
                onCancel={() => setEditing(null)}
                okText="Сохранить"
            >
                <Form form={form} layout="vertical">
                    <Form.Item name="name" label="Название" rules={[{ required: true }]}><Input /></Form.Item>
                    <Form.Item name="sku" label="SKU" rules={[{ required: true }]}><Input /></Form.Item>
                    <Form.Item name="price_usdt_cents" label="Цена (центы USDT)" rules={[{ required: true }]}><InputNumber min={0} style={{ width: '100%' }} /></Form.Item>
                    <Form.Item name="pv" label="PV (отображаемый)"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item>
                    <Form.Item name="package_id" label="Тариф (package_id)" rules={[{ required: true }]}><InputNumber min={1} style={{ width: '100%' }} /></Form.Item>
                    <Form.Item name="sort" label="Сортировка"><InputNumber style={{ width: '100%' }} /></Form.Item>
                    <Form.Item name="is_active" label="Активен" valuePropName="checked"><Switch /></Form.Item>
                </Form>
            </Modal>
        </Space>
    );
};

export default Products;
