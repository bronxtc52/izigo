'use client';
import React, { useEffect, useState, useCallback } from 'react';
import { Card, Tag, Spin, Empty, Flex, Button, Segmented, List } from 'antd';
import { WarningOutlined } from '@ant-design/icons';
import { useTranslation } from 'react-i18next';
import { mmPlanAccounts, mmPlanAccountLots, mmPlanAccountHistory } from '../api';
import { usd } from '../format';
import { numFont, balanceFont } from '../tokens';

const ACCOUNTS = ['os', 'ns', 'bs'];

/**
 * T14 — секция «Счета» таба «Мой план»: три карточки ОС/НС/БС (баланс USD, свойства
 * счёта), активные лоты со сроками сгорания (earliest-expiry-first, предупреждение
 * <30 дней), история движений с cursor-подгрузкой. Read-only; деньги — USD-центы,
 * форматируются usd(). Названия/тексты — через i18n.
 */
const AccountsPanel = ({ initData, pal }) => {
    const { t } = useTranslation();
    const [acc, setAcc] = useState(null);
    const [loading, setLoading] = useState(true);
    const [sel, setSel] = useState('os');
    const [lots, setLots] = useState([]);
    const [history, setHistory] = useState([]);
    const [cursor, setCursor] = useState(null);
    const [more, setMore] = useState(false);

    useEffect(() => {
        let alive = true;
        (async () => {
            setLoading(true);
            const res = await mmPlanAccounts(initData);
            if (alive) { setAcc(res?.data ?? null); setLoading(false); }
        })();
        return () => { alive = false; };
    }, [initData]);

    const loadDetail = useCallback(async (account) => {
        const lotsRes = account === 'ns' ? { data: { items: [] } } : await mmPlanAccountLots(initData, account);
        setLots(lotsRes?.data?.items ?? []);
        const hRes = await mmPlanAccountHistory(initData, account, null);
        setHistory(hRes?.data?.items ?? []);
        setCursor(hRes?.data?.next_cursor ?? null);
    }, [initData]);

    useEffect(() => { if (initData) loadDetail(sel); }, [initData, sel, loadDetail]);

    const loadMore = async () => {
        if (cursor == null) return;
        setMore(true);
        const hRes = await mmPlanAccountHistory(initData, sel, cursor);
        setHistory((prev) => [...prev, ...(hRes?.data?.items ?? [])]);
        setCursor(hRes?.data?.next_cursor ?? null);
        setMore(false);
    };

    if (loading) return <div style={{ textAlign: 'center', padding: 32 }}><Spin /></div>;
    if (!acc) return <Empty description={t('planv2.unavailable')} style={{ marginTop: 32 }} />;

    const cards = [
        { key: 'os', balance: acc.os_available_cents, note: t('planv2.os_note', { pct: acc.order_pay_limit_pct }) },
        { key: 'ns', balance: acc.ns_cents, note: t('planv2.ns_note', { date: fmtDate(acc.ns_next_transfer_at, t) }) },
        { key: 'bs', balance: acc.bs_available_cents, note: t('planv2.bs_note') },
    ];

    return (
        <Flex vertical gap={10} style={{ padding: 4 }}>
            {cards.map((c) => (
                <Card key={c.key} size="small">
                    <Flex justify="space-between" align="center">
                        <div>
                            <div style={{ ...numFont, fontWeight: 700 }}>{t(`planv2.account.${c.key}`)}</div>
                            <div style={{ fontSize: 11.5, color: pal.muted }}>{c.note}</div>
                        </div>
                        <div style={{ ...balanceFont, fontWeight: 800, fontSize: 20 }}>${usd(c.balance)}</div>
                    </Flex>
                </Card>
            ))}

            {(acc.upcoming_expirations?.length > 0) && (
                <Card size="small" title={t('planv2.upcoming_expirations')}>
                    <Flex vertical gap={4}>
                        {acc.upcoming_expirations.map((e, i) => (
                            <Flex key={i} justify="space-between">
                                <span>{t(`planv2.account.${e.account}`)}: ${usd(e.amount_cents)}</span>
                                <span style={{ color: pal.muted }}>{fmtDate(e.expires_at, t)}</span>
                            </Flex>
                        ))}
                    </Flex>
                </Card>
            )}

            <Segmented block value={sel} onChange={setSel}
                options={ACCOUNTS.map((a) => ({ value: a, label: t(`planv2.account_short.${a}`) }))} />

            {sel !== 'ns' && (
                <Card size="small" title={t('planv2.active_lots')}>
                    {lots.length === 0
                        ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={t('planv2.no_lots')} />
                        : (
                            <List size="small" dataSource={lots} renderItem={(lot) => (
                                <List.Item>
                                    <Flex justify="space-between" style={{ width: '100%' }} align="center">
                                        <span style={{ fontWeight: 600 }}>${usd(lot.available_cents)}</span>
                                        {lot.expires_at ? (
                                            <Tag color={lot.expiring_soon ? 'warning' : undefined}
                                                icon={lot.expiring_soon ? <WarningOutlined /> : undefined}>
                                                {t('planv2.expires')} {fmtDate(lot.expires_at, t)}
                                            </Tag>
                                        ) : (
                                            <Tag color="green">{t('planv2.no_expiry')}</Tag>
                                        )}
                                    </Flex>
                                </List.Item>
                            )} />
                        )}
                </Card>
            )}

            <Card size="small" title={t('planv2.history')}>
                {history.length === 0
                    ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={t('planv2.no_history')} />
                    : (
                        <>
                            <List size="small" dataSource={history} renderItem={(h) => (
                                <List.Item>
                                    <Flex justify="space-between" style={{ width: '100%' }} align="center">
                                        <span style={{ fontSize: 12.5, color: pal.muted }}>
                                            {t(`planv2.source.${h.source_type}`, { defaultValue: h.source_type ?? '—' })}
                                        </span>
                                        <span style={{ fontWeight: 700, color: h.amount_cents >= 0 ? pal.accent : pal.muted }}>
                                            {h.amount_cents >= 0 ? '+' : ''}${usd(Math.abs(h.amount_cents))}
                                        </span>
                                    </Flex>
                                </List.Item>
                            )} />
                            {cursor != null && (
                                <Button block size="small" loading={more} onClick={loadMore} style={{ marginTop: 8 }}>
                                    {t('planv2.load_more')}
                                </Button>
                            )}
                        </>
                    )}
            </Card>
        </Flex>
    );
};

const fmtDate = (iso, t) => {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleDateString(t('planv2.locale_tag', { defaultValue: 'ru-RU' }), {
            year: 'numeric', month: 'short', day: 'numeric',
        });
    } catch (e) {
        return iso.slice(0, 10);
    }
};

export default AccountsPanel;
