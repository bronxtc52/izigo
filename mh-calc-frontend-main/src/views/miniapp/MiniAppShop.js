'use client';
import React, { useEffect, useState } from 'react';
import { Card, Button, Tag, List, Spin, Segmented, Flex, Empty, Modal, Select, InputNumber, Popconfirm, message } from 'antd';
import { tint, numFont } from './tokens';
import { mmCatalog, mmOrders, mmCreateOrder, mmPayOrder, mmAutoship, mmAutoshipCreate, mmAutoshipAction } from './api';
import { usd } from './format';
import TonPayCheckout from './TonPayCheckout';

const ORDER_STATUS = {
    pending_payment: { label: 'ожидает оплаты', kind: 'amber' },
    paid: { label: 'оплачен', kind: 'blue' },
    processing: { label: 'в обработке', kind: 'blue' },
    shipped: { label: 'отправлен', kind: 'blue' },
    delivered: { label: 'доставлен', kind: 'green' },
    cancelled: { label: 'отменён', kind: 'neutral' },
    refunded: { label: 'возврат', kind: 'neutral' },
};

const AUTOSHIP_STATUS = {
    active: { label: 'активна', kind: 'green' },
    paused: { label: 'на паузе', kind: 'amber' },
    cancelled: { label: 'отменена', kind: 'neutral' },
};

/** Вкладка «Магазин»: каталог товаров (цена USDT + PV) → заказ → оплата TON Pay → мои заказы. */
export default function MiniAppShop({ initData, pal, isDark, wa, onUnauthorized }) {
    const [view, setView] = useState('catalog'); // catalog | orders | autoship
    const [catalog, setCatalog] = useState([]);
    const [orders, setOrders] = useState([]);
    const [autoship, setAutoship] = useState([]);
    const [loading, setLoading] = useState(true);
    const [buying, setBuying] = useState(0);
    const [paying, setPaying] = useState(0);
    const [checkout, setCheckout] = useState(null); // { invoice, order }
    // F4: создание автозаказа — выбор товара из каталога + интервал в днях.
    const [asOpen, setAsOpen] = useState(false);
    const [asProduct, setAsProduct] = useState(null);
    const [asInterval, setAsInterval] = useState(30);
    const [asSubmitting, setAsSubmitting] = useState(false);
    const [asBusy, setAsBusy] = useState(0); // id подписки в процессе действия

    const load = async () => {
        setLoading(true);
        const [c, o, a] = await Promise.all([mmCatalog(initData), mmOrders(initData), mmAutoship(initData)]);
        if (c?.error === 401 || o?.error === 401 || a?.error === 401) { onUnauthorized?.(); setLoading(false); return; }
        setCatalog(Array.isArray(c?.data) ? c.data : []);
        setOrders(Array.isArray(o?.data) ? o.data : []);
        setAutoship(Array.isArray(a?.data) ? a.data : []);
        setLoading(false);
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [initData]);

    const reloadOrders = async () => {
        const o = await mmOrders(initData);
        setOrders(Array.isArray(o?.data) ? o.data : []);
    };

    const reloadAutoship = async () => {
        const a = await mmAutoship(initData);
        setAutoship(Array.isArray(a?.data) ? a.data : []);
    };

    // Имя товара по product_id (для карточки подписки — backend отдаёт только product_id).
    const productName = (id) => catalog.find((p) => p.id === id)?.name ?? `товар #${id}`;

    const onCreateAutoship = async () => {
        if (!asProduct) { message.error('Выберите товар'); return; }
        if (!asInterval || asInterval < 1 || asInterval > 365) { message.error('Интервал — от 1 до 365 дней'); return; }
        setAsSubmitting(true);
        const res = await mmAutoshipCreate(initData, asProduct, asInterval);
        setAsSubmitting(false);
        if (res?.error) { message.error('Не удалось создать автозаказ'); return; }
        message.success('Автозаказ создан');
        wa?.HapticFeedback?.notificationOccurred?.('success');
        setAsOpen(false);
        setAsProduct(null);
        setAsInterval(30);
        reloadAutoship();
    };

    const onAutoshipAction = async (id, action) => {
        setAsBusy(id);
        const res = await mmAutoshipAction(initData, id, action);
        setAsBusy(0);
        if (res?.error) { message.error('Не удалось изменить автозаказ'); return; }
        await reloadAutoship();
    };

    const onBuy = async (product) => {
        setBuying(product.id);
        const created = await mmCreateOrder(initData, product.id, 1);
        const order = created?.data;
        if (created?.error || !order?.id) { setBuying(0); message.error('Не удалось создать заказ'); return; }
        const pay = await mmPayOrder(initData, order.id);
        setBuying(0);
        if (pay?.error) { message.error('Не удалось создать счёт на оплату'); return; }
        wa?.HapticFeedback?.impactOccurred?.('light');
        setCheckout({ invoice: pay?.data, order });
    };

    // Оплатить уже созданный (висящий) заказ из «Мои заказы».
    const onPayExisting = async (order) => {
        setPaying(order.id);
        const pay = await mmPayOrder(initData, order.id);
        setPaying(0);
        if (pay?.error) { message.error('Не удалось создать счёт на оплату'); return; }
        setCheckout({ invoice: pay?.data, order });
    };

    const onPaid = async () => {
        await reloadOrders();
        setView('orders');
    };

    if (loading) return <Spin size="large" style={{ display: 'block', margin: '60px auto' }} />;

    return (
        <>
            <Segmented block value={view} onChange={setView}
                options={[{ label: 'Каталог', value: 'catalog' }, { label: 'Мои заказы', value: 'orders' }, { label: 'Автозаказ', value: 'autoship' }]} />

            {view === 'catalog' && (
                catalog.length === 0
                    ? <Empty description="Каталог пуст" style={{ marginTop: 40 }} />
                    : catalog.map((p) => (
                        <Card key={p.id} size="small" styles={{ body: { padding: 14 } }}>
                            <Flex justify="space-between" align="flex-start" gap={10}>
                                <div style={{ minWidth: 0 }}>
                                    <div style={{ fontWeight: 700, fontSize: 15 }}>{p.name}</div>
                                    {p.description && (
                                        <div style={{ fontSize: 12, color: pal.muted, marginTop: 2 }}>{p.description}</div>
                                    )}
                                    <Flex gap={6} style={{ marginTop: 8 }}>
                                        <Tag style={{ ...numFont, background: tint('blue', isDark).bg, color: tint('blue', isDark).color, border: 'none', fontWeight: 600 }}>
                                            {p.pv} PV
                                        </Tag>
                                        {/* stock=null → безлимит; «нет в наличии» только при реальном 0/отрицательном */}
                                        {p.stock != null && Number(p.stock) <= 0 && (
                                            <Tag style={{ background: tint('neutral', isDark).bg, color: tint('neutral', isDark).color, border: 'none' }}>нет в наличии</Tag>
                                        )}
                                    </Flex>
                                </div>
                                <div style={{ textAlign: 'right' }}>
                                    <div style={{ ...numFont, fontWeight: 800, fontSize: 18 }}>${usd(p.price_usdt_cents)}</div>
                                    <Button type="primary" size="small" style={{ marginTop: 8 }}
                                        loading={buying === p.id}
                                        disabled={buying !== 0 || (p.stock != null && Number(p.stock) <= 0)}
                                        onClick={() => onBuy(p)}>Купить</Button>
                                </div>
                            </Flex>
                        </Card>
                    ))
            )}

            {view === 'orders' && (
                <Card size="small" title="Мои заказы">
                    <List
                        dataSource={orders}
                        locale={{ emptyText: 'Заказов пока нет' }}
                        renderItem={(o) => {
                            const s = ORDER_STATUS[o.status] ?? { label: o.status, kind: 'neutral' };
                            const t = tint(s.kind, isDark);
                            const names = (o.items ?? []).map((i) => `${i.name}${i.qty > 1 ? ` ×${i.qty}` : ''}`).join(', ');
                            return (
                                <List.Item style={{ display: 'block' }}>
                                    <Flex justify="space-between" align="center">
                                        <span style={{ fontWeight: 600, fontSize: 13.5 }}>#{o.id} {names}</span>
                                        <Tag style={{ background: t.bg, color: t.color, border: 'none', fontSize: 10.5, fontWeight: 600 }}>{s.label}</Tag>
                                    </Flex>
                                    <Flex justify="space-between" align="center" style={{ marginTop: 4 }}>
                                        <span style={{ fontSize: 11.5, color: pal.muted }}>
                                            {o.total_pv} PV{o.created_at ? ` · ${new Date(o.created_at).toLocaleDateString()}` : ''}
                                            {o.tracking_no ? ` · трек ${o.tracking_no}` : ''}
                                        </span>
                                        <span style={{ ...numFont, fontWeight: 700 }}>${usd(o.total_usdt_cents)}</span>
                                    </Flex>
                                    {o.status === 'pending_payment' && (
                                        <Button type="primary" size="small" block style={{ marginTop: 8 }}
                                            loading={paying === o.id} disabled={paying !== 0}
                                            onClick={() => onPayExisting(o)}>Оплатить</Button>
                                    )}
                                </List.Item>
                            );
                        }}
                    />
                </Card>
            )}

            {view === 'autoship' && (
                <>
                    <Button type="primary" block disabled={catalog.length === 0}
                        onClick={() => { setAsProduct(null); setAsInterval(30); setAsOpen(true); }}>
                        + Новый автозаказ
                    </Button>
                    <Card size="small" title="Мои автозаказы" style={{ marginTop: 12 }}>
                        <List
                            dataSource={autoship}
                            locale={{ emptyText: 'Автозаказов пока нет' }}
                            renderItem={(s) => {
                                const st = AUTOSHIP_STATUS[s.status] ?? { label: s.status, kind: 'neutral' };
                                const t = tint(st.kind, isDark);
                                return (
                                    <List.Item style={{ display: 'block' }}>
                                        <Flex justify="space-between" align="center">
                                            <span style={{ fontWeight: 600, fontSize: 13.5 }}>{productName(s.product_id)}</span>
                                            <Tag style={{ background: t.bg, color: t.color, border: 'none', fontSize: 10.5, fontWeight: 600 }}>{st.label}</Tag>
                                        </Flex>
                                        <div style={{ fontSize: 11.5, color: pal.muted, marginTop: 4 }}>
                                            каждые {s.interval_days} дн.
                                            {s.next_charge_at ? ` · следующее ${new Date(s.next_charge_at).toLocaleDateString()}` : ''}
                                            {s.retry_stage > 0 ? ` · повтор д.${s.retry_stage}` : ''}
                                        </div>
                                        <Flex gap={8} style={{ marginTop: 8 }}>
                                            {s.status === 'active' && (
                                                <Button size="small" loading={asBusy === s.id} disabled={asBusy !== 0}
                                                    onClick={() => onAutoshipAction(s.id, 'pause')}>Пауза</Button>
                                            )}
                                            {s.status === 'paused' && (
                                                <Button size="small" type="primary" loading={asBusy === s.id} disabled={asBusy !== 0}
                                                    onClick={() => onAutoshipAction(s.id, 'resume')}>Возобновить</Button>
                                            )}
                                            {s.status !== 'cancelled' && (
                                                <Popconfirm title="Отменить автозаказ?" okText="Отменить" cancelText="Нет"
                                                    onConfirm={() => onAutoshipAction(s.id, 'cancel')}>
                                                    <Button size="small" danger disabled={asBusy !== 0}>Отменить</Button>
                                                </Popconfirm>
                                            )}
                                        </Flex>
                                    </List.Item>
                                );
                            }}
                        />
                    </Card>
                </>
            )}

            <Modal title="Новый автозаказ" open={asOpen} onOk={onCreateAutoship} confirmLoading={asSubmitting}
                onCancel={() => setAsOpen(false)} okText="Создать">
                <div style={{ fontSize: 12, color: pal.muted, marginBottom: 6 }}>Товар</div>
                <Select style={{ width: '100%', marginBottom: 12 }} placeholder="Выберите товар" value={asProduct}
                    onChange={setAsProduct}
                    options={catalog.map((p) => ({ value: p.id, label: `${p.name} — $${usd(p.price_usdt_cents)}` }))} />
                <div style={{ fontSize: 12, color: pal.muted, marginBottom: 6 }}>Интервал, дней</div>
                <InputNumber style={{ width: '100%' }} min={1} max={365} value={asInterval} onChange={setAsInterval} />
                <div style={{ fontSize: 11.5, color: pal.muted, marginTop: 10 }}>
                    Списание — с внутреннего USDT-баланса. Пополните его во вкладке «Доход».
                </div>
            </Modal>

            <TonPayCheckout open={!!checkout} invoice={checkout?.invoice} order={checkout?.order}
                initData={initData} pal={pal} wa={wa}
                onClose={() => setCheckout(null)} onPaid={onPaid} />
        </>
    );
}
