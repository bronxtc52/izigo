'use client';
import React, { useEffect, useState } from 'react';
import { Card, Tag, Spin, Empty, Flex } from 'antd';
import { GiftOutlined, LockOutlined, CheckCircleFilled, DollarOutlined } from '@ant-design/icons';
import { useTranslation } from 'react-i18next';
import { mmPlanAwards } from '../api';
import { usd } from '../format';
import { numFont } from '../tokens';

/**
 * T14 — секция «Награды» таба «Мой план»: квалификационные награды по статусам
 * (сумма USD, статус), при скачке через ранги видны все пройденные ступени как earned
 * (DEC-040). Выплата вручную by design — кнопок нет, только статус. Источник состояния —
 * фактические entitlement'ы T10 (granted/on_hold/paid_out/forfeited) либо дериватив по
 * рангу (earned/locked). Названия/статусы — через i18n.
 */
const STATE_META = {
    locked: { color: undefined, icon: <LockOutlined /> },
    earned: { color: 'processing', icon: <CheckCircleFilled /> },
    granted: { color: 'success', icon: <GiftOutlined /> },
    on_hold: { color: 'warning', icon: <GiftOutlined /> },
    paid_out: { color: 'gold', icon: <DollarOutlined /> },
    forfeited: { color: 'default', icon: <LockOutlined /> },
};

const AwardsPanel = ({ initData, pal }) => {
    const { t } = useTranslation();
    const [items, setItems] = useState([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let alive = true;
        (async () => {
            setLoading(true);
            const res = await mmPlanAwards(initData);
            if (alive) { setItems(res?.data?.items ?? []); setLoading(false); }
        })();
        return () => { alive = false; };
    }, [initData]);

    const rankName = (code) => t(`planv2.status.${code}`, { defaultValue: code });

    if (loading) return <div style={{ textAlign: 'center', padding: 32 }}><Spin /></div>;
    if (items.length === 0) return <Empty description={t('planv2.no_awards')} style={{ marginTop: 32 }} />;

    return (
        <Flex vertical gap={8} style={{ padding: 4 }}>
            <div style={{ fontSize: 11.5, color: pal.muted, padding: '0 4px' }}>{t('planv2.awards_hint')}</div>
            {items.map((a) => {
                const meta = STATE_META[a.state] ?? STATE_META.locked;
                const dim = a.state === 'locked' || a.state === 'forfeited';
                const label = a.award_code === 'VICE_PRESIDENT'
                    ? `${rankName(a.status_code)} · ${t('planv2.stage')} ${a.stage_no}`
                    : rankName(a.status_code);
                return (
                    <Card key={`${a.award_code}:${a.stage_no}`} size="small" styles={{ body: { padding: 12 } }}>
                        <Flex justify="space-between" align="center" style={{ opacity: dim ? 0.6 : 1 }}>
                            <Flex align="center" gap={10}>
                                {meta.icon}
                                <div>
                                    <div style={{ ...numFont, fontWeight: 700 }}>{label}</div>
                                    <div style={{ fontSize: 13, color: pal.muted }}>${usd(a.amount_cents)}</div>
                                </div>
                            </Flex>
                            <Tag color={meta.color}>{t(`planv2.award_state.${a.state}`, { defaultValue: a.state })}</Tag>
                        </Flex>
                    </Card>
                );
            })}
        </Flex>
    );
};

export default AwardsPanel;
