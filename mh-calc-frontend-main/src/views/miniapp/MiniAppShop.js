'use client';
import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Card, Button, Tag, List, Spin, Segmented, Flex, Empty, Modal, Select, InputNumber, Popconfirm, message } from 'antd';
import { tint, numFont, balanceFont } from './tokens';
import { mmCatalog, mmOrders, mmCreateOrder, mmPayOrder, mmAutoship, mmAutoshipCreate, mmAutoshipAction } from './api';
import { usd } from './format';
import TonPayCheckout from './TonPayCheckout';

// Значения label — i18n-ключи (переводятся t() при рендере), kind — цветовой тон.
const ORDER_STATUS = {
    pending_payment: { label: 'miniapp.shop_order_pending_payment', kind: 'amber' },
    paid: { label: 'miniapp.shop_order_paid', kind: 'blue' },
    processing: { label: 'miniapp.shop_order_processing', kind: 'blue' },
    shipped: { label: 'miniapp.shop_order_shipped', kind: 'blue' },
    delivered: { label: 'miniapp.shop_order_delivered', kind: 'green' },
    cancelled: { label: 'miniapp.shop_order_cancelled', kind: 'neutral' },
    refunded: { label: 'miniapp.shop_order_refunded', kind: 'neutral' },
};

const AUTOSHIP_STATUS = {
    active: { label: 'miniapp.shop_as_active', kind: 'green' },
    paused: { label: 'miniapp.shop_as_paused', kind: 'amber' },
    cancelled: { label: 'miniapp.shop_as_cancelled', kind: 'neutral' },
};

/** Вкладка «Магазин»: каталог товаров (цена USDT + PV) → заказ → оплата TON Pay → мои заказы. */
export default function MiniAppShop({ initData, pal, isDark, wa, onUnauthorized, onAfterPaid, leadMode = false }) {
    const { t } = useTranslation();
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
        // Лид: только каталог (orders/autoship — member-only). Не дёргаем заведомо-404.
        if (leadMode) {
            const c = await mmCatalog(initData);
            if (c?.error === 401) { onUnauthorized?.(); setLoading(false); return; }
            setCatalog(Array.isArray(c?.data) ? c.data : []);
            setLoading(false);
            return;
        }
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
    const productName = (id) => catalog.find((p) => p.id === id)?.name ?? t('miniapp.shop_product_fallback', { id });

    const onCreateAutoship = async () => {
        if (!asProduct) { message.error(t('miniapp.shop_err_select_product')); return; }
        if (!asInterval || asInterval < 1 || asInterval > 365) { message.error(t('miniapp.shop_err_interval_range')); return; }
        setAsSubmitting(true);
        const res = await mmAutoshipCreate(initData, asProduct, asInterval);
        setAsSubmitting(false);
        if (res?.error) { message.error(t('miniapp.shop_err_create_as')); return; }
        message.success(t('miniapp.shop_ok_as_created'));
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
        if (res?.error) { message.error(t('miniapp.shop_err_update_as')); return; }
        await reloadAutoship();
    };

    const onBuy = async (product) => {
        setBuying(product.id);
        const created = await mmCreateOrder(initData, product.id, 1);
        const order = created?.data;
        if (created?.error || !order?.id) { setBuying(0); message.error(t('miniapp.shop_err_create_order')); return; }
        const pay = await mmPayOrder(initData, order.id);
        setBuying(0);
        if (pay?.error) { message.error(t('miniapp.shop_err_create_invoice')); return; }
        wa?.HapticFeedback?.impactOccurred?.('light');
        setCheckout({ invoice: pay?.data, order });
    };

    // Оплатить уже созданный (висящий) заказ из «Мои заказы».
    const onPayExisting = async (order) => {
        setPaying(order.id);
        const pay = await mmPayOrder(initData, order.id);
        setPaying(0);
        if (pay?.error) { message.error(t('miniapp.shop_err_create_invoice')); return; }
        setCheckout({ invoice: pay?.data, order });
    };

    const onPaid = async () => {
        await reloadOrders();
        setView('orders');
        // Лид → Member: после первой оплаты идентичность меняется на участника —
        // даём шеллу перезагрузить профиль (выйти из лид-экрана в полный кабинет).
        onAfterPaid?.();
    };

    // Aurora: градиентный CTA (Купить/Оплатить) и градиентная цена.
    const gradBtn = { background: pal.primBg, color: pal.primTxt, border: 'none', boxShadow: pal.primGlow };
    const priceGrad = { ...balanceFont, fontWeight: 800, background: pal.balGrad, WebkitBackgroundClip: 'text', backgroundClip: 'text', color: 'transparent' };

    if (loading) return <Spin size="large" style={{ display: 'block', margin: '60px auto' }} />;

    return (
        <>
            {/* Лид (ещё не купил) видит только каталог: «Мои заказы»/«Автозаказ» — member-only (404). */}
            {!leadMode && (
                <Segmented block value={view} onChange={setView}
                    options={[{ label: t('miniapp.shop_seg_catalog'), value: 'catalog' }, { label: t('miniapp.shop_seg_orders'), value: 'orders' }, { label: t('miniapp.shop_seg_autoship'), value: 'autoship' }]} />
            )}

            {view === 'catalog' && (
                catalog.length === 0
                    ? <Empty description={t('miniapp.shop_catalog_empty')} style={{ marginTop: 40 }} />
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
                                            <Tag style={{ background: tint('neutral', isDark).bg, color: tint('neutral', isDark).color, border: 'none' }}>{t('miniapp.shop_out_of_stock')}</Tag>
                                        )}
                                    </Flex>
                                </div>
                                <div style={{ textAlign: 'right' }}>
                                    <div style={{ ...priceGrad, fontSize: 18 }}>${usd(p.price_usdt_cents)}</div>
                                    <Button type="primary" size="small"
                                        style={{ marginTop: 8, ...(buying !== 0 || (p.stock != null && Number(p.stock) <= 0) ? {} : gradBtn) }}
                                        loading={buying === p.id}
                                        disabled={buying !== 0 || (p.stock != null && Number(p.stock) <= 0)}
                                        onClick={() => onBuy(p)}>{t('miniapp.shop_buy')}</Button>
                                </div>
                            </Flex>
                        </Card>
                    ))
            )}

            {view === 'orders' && (
                <Card size="small" title={t('miniapp.shop_seg_orders')}>
                    <List
                        dataSource={orders}
                        locale={{ emptyText: t('miniapp.shop_orders_empty') }}
                        renderItem={(o) => {
                            const s = ORDER_STATUS[o.status];
                            const tt = tint(s?.kind ?? 'neutral', isDark);
                            const names = (o.items ?? []).map((i) => `${i.name}${i.qty > 1 ? ` ×${i.qty}` : ''}`).join(', ');
                            return (
                                <List.Item style={{ display: 'block' }}>
                                    <Flex justify="space-between" align="center">
                                        <span style={{ fontWeight: 600, fontSize: 13.5 }}>#{o.id} {names}</span>
                                        <Tag style={{ background: tt.bg, color: tt.color, border: 'none', fontSize: 10.5, fontWeight: 600 }}>{s ? t(s.label) : o.status}</Tag>
                                    </Flex>
                                    <Flex justify="space-between" align="center" style={{ marginTop: 4 }}>
                                        <span style={{ fontSize: 11.5, color: pal.muted }}>
                                            {o.total_pv} PV{o.created_at ? ` · ${new Date(o.created_at).toLocaleDateString()}` : ''}
                                            {o.tracking_no ? ` · ${t('miniapp.shop_track_prefix')} ${o.tracking_no}` : ''}
                                        </span>
                                        <span style={{ ...balanceFont, fontWeight: 700 }}>${usd(o.total_usdt_cents)}</span>
                                    </Flex>
                                    {o.status === 'pending_payment' && (
                                        <Button type="primary" size="small" block
                                            style={{ marginTop: 8, ...(paying !== 0 ? {} : gradBtn) }}
                                            loading={paying === o.id} disabled={paying !== 0}
                                            onClick={() => onPayExisting(o)}>{t('miniapp.shop_pay')}</Button>
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
                        {t('miniapp.shop_new_as_btn')}
                    </Button>
                    <Card size="small" title={t('miniapp.shop_my_as')} style={{ marginTop: 12 }}>
                        <List
                            dataSource={autoship}
                            locale={{ emptyText: t('miniapp.shop_as_empty') }}
                            renderItem={(s) => {
                                const st = AUTOSHIP_STATUS[s.status];
                                const tt = tint(st?.kind ?? 'neutral', isDark);
                                return (
                                    <List.Item style={{ display: 'block' }}>
                                        <Flex justify="space-between" align="center">
                                            <span style={{ fontWeight: 600, fontSize: 13.5 }}>{productName(s.product_id)}</span>
                                            <Tag style={{ background: tt.bg, color: tt.color, border: 'none', fontSize: 10.5, fontWeight: 600 }}>{st ? t(st.label) : s.status}</Tag>
                                        </Flex>
                                        <div style={{ fontSize: 11.5, color: pal.muted, marginTop: 4 }}>
                                            {t('miniapp.shop_every_days', { n: s.interval_days })}
                                            {s.next_charge_at ? ` · ${t('miniapp.shop_next_prefix')} ${new Date(s.next_charge_at).toLocaleDateString()}` : ''}
                                            {s.retry_stage > 0 ? ` · ${t('miniapp.shop_retry_stage_prefix')}${s.retry_stage}` : ''}
                                        </div>
                                        <Flex gap={8} style={{ marginTop: 8 }}>
                                            {s.status === 'active' && (
                                                <Button size="small" loading={asBusy === s.id} disabled={asBusy !== 0}
                                                    onClick={() => onAutoshipAction(s.id, 'pause')}>{t('miniapp.shop_pause')}</Button>
                                            )}
                                            {s.status === 'paused' && (
                                                <Button size="small" type="primary" loading={asBusy === s.id} disabled={asBusy !== 0}
                                                    onClick={() => onAutoshipAction(s.id, 'resume')}>{t('miniapp.shop_resume')}</Button>
                                            )}
                                            {s.status !== 'cancelled' && (
                                                <Popconfirm title={t('miniapp.shop_cancel_as_confirm')} okText={t('miniapp.shop_cancel')} cancelText={t('miniapp.shop_no')}
                                                    onConfirm={() => onAutoshipAction(s.id, 'cancel')}>
                                                    <Button size="small" danger disabled={asBusy !== 0}>{t('miniapp.shop_cancel')}</Button>
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

            <Modal title={t('miniapp.shop_new_as_title')} open={asOpen} onOk={onCreateAutoship} confirmLoading={asSubmitting}
                onCancel={() => setAsOpen(false)} okText={t('miniapp.shop_create')}>
                <div style={{ fontSize: 12, color: pal.muted, marginBottom: 6 }}>{t('miniapp.shop_product')}</div>
                <Select style={{ width: '100%', marginBottom: 12 }} placeholder={t('miniapp.shop_select_product')} value={asProduct}
                    onChange={setAsProduct}
                    options={catalog.map((p) => ({ value: p.id, label: `${p.name} — $${usd(p.price_usdt_cents)}` }))} />
                <div style={{ fontSize: 12, color: pal.muted, marginBottom: 6 }}>{t('miniapp.shop_interval_days')}</div>
                <InputNumber style={{ width: '100%' }} min={1} max={365} value={asInterval} onChange={setAsInterval} />
                <div style={{ fontSize: 11.5, color: pal.muted, marginTop: 10 }}>
                    {t('miniapp.shop_as_charge_hint')}
                </div>
            </Modal>

            <TonPayCheckout open={!!checkout} invoice={checkout?.invoice} order={checkout?.order}
                initData={initData} pal={pal} wa={wa}
                onClose={() => setCheckout(null)} onPaid={onPaid} />
        </>
    );
}
