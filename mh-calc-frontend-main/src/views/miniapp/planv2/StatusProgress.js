'use client';
import React, { useEffect, useState } from 'react';
import { Card, Progress, Tag, Spin, Empty, Flex } from 'antd';
import { CheckCircleFilled, LockOutlined, TrophyOutlined } from '@ant-design/icons';
import { useTranslation } from 'react-i18next';
import { mmPlanRankProgress } from '../api';
import { numFont, balanceFont } from '../tokens';

/**
 * T14 — секция «Статус» таба «Мой план» Mini App: визуальная лестница 12 статусов
 * («ранг навсегда» — без регресса), прогресс малой ветки PV к следующему статусу,
 * карточки 3 вариантов квалификации с чек-листом requirements/actuals (пометка «из
 * разных ветвей»), бейдж тира START/BUSINESS/ELITE. Все названия — через i18n
 * (бэкенд отдаёт только машинные коды: GOLD_MANAGER, ELITE, variant V1…).
 */
const StatusProgress = ({ initData, pal }) => {
    const { t } = useTranslation();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let alive = true;
        (async () => {
            setLoading(true);
            const res = await mmPlanRankProgress(initData);
            if (alive) {
                setData(res?.data ?? null);
                setLoading(false);
            }
        })();
        return () => { alive = false; };
    }, [initData]);

    const rankName = (code) => (code ? t(`planv2.status.${code}`, { defaultValue: code }) : t('planv2.status_none'));
    const tierName = (code) => (code ? t(`planv2.tier.${code}`, { defaultValue: code }) : '—');

    if (loading) return <div style={{ textAlign: 'center', padding: 32 }}><Spin /></div>;
    if (!data) return <Empty description={t('planv2.unavailable')} style={{ marginTop: 32 }} />;

    const { ladder = [], next, tier } = data;
    const pv = next?.small_branch_pv;
    const pvPct = pv?.required
        ? Math.min(100, Math.round((Number(pv.actual || 0) / (pv.required || 1)) * 100))
        : 0;

    return (
        <Flex vertical gap={10} style={{ padding: 4 }}>
            {/* Тир партнёра */}
            <Card size="small">
                <Flex justify="space-between" align="center">
                    <div>
                        <div style={{ fontSize: 11.5, color: pal.muted }}>{t('planv2.tier_label')}</div>
                        <div style={{ ...numFont, fontWeight: 800, fontSize: 18 }}>{tierName(tier?.code)}</div>
                    </div>
                    <div style={{ textAlign: 'right' }}>
                        <div style={{ fontSize: 11.5, color: pal.muted }}>{t('planv2.personal_pv')}</div>
                        <div style={{ ...balanceFont, fontWeight: 700 }}>{tier?.personal_pv ?? '0'}</div>
                    </div>
                </Flex>
                <Flex gap={6} wrap style={{ marginTop: 8 }}>
                    {(tier?.thresholds ?? []).map((th) => (
                        <Tag key={th.code} color={tier?.code === th.code ? 'gold' : undefined}>
                            {tierName(th.code)}: {th.min_pv}
                            {th.max_pv_exclusive ? `–${th.max_pv_exclusive - 1}` : '+'}
                        </Tag>
                    ))}
                </Flex>
            </Card>

            {/* Текущий / следующий статус */}
            <Card size="small">
                <Flex justify="space-between" align="center">
                    <Flex align="center" gap={10}>
                        <TrophyOutlined style={{ fontSize: 22, color: pal.accent2 ?? pal.accent }} />
                        <div>
                            <div style={{ fontSize: 11.5, color: pal.muted }}>{t('planv2.current_status')}</div>
                            <div style={{ ...numFont, fontWeight: 800, fontSize: 18 }}>{rankName(data.current_rank_code)}</div>
                        </div>
                    </Flex>
                    <div style={{ textAlign: 'right' }}>
                        <div style={{ fontSize: 11.5, color: pal.muted }}>{t('planv2.next_status')}</div>
                        <div style={{ fontWeight: 700, color: pal.accent }}>
                            {next ? `${rankName(next.rank_code)} ↗` : t('planv2.status_max')}
                        </div>
                    </div>
                </Flex>
            </Card>

            {/* Прогресс к следующему статусу */}
            {next && (
                <>
                    {pv && (
                        <Card size="small" title={t('planv2.small_branch_pv')}>
                            <Flex justify="space-between" style={{ ...balanceFont, fontWeight: 700, marginBottom: 6 }}>
                                <span>{pv.actual ?? '0'}</span>
                                <span style={{ color: pal.muted }}>/ {pv.required}</span>
                            </Flex>
                            <Progress showInfo={false} percent={pvPct} />
                        </Card>
                    )}
                    {next.referrals && (
                        <Card size="small" title={t('planv2.referrals_l1')}>
                            <Flex justify="space-between" style={{ ...balanceFont, fontWeight: 700 }}>
                                <span>{next.referrals.actual ?? 0}</span>
                                <span style={{ color: pal.muted }}>/ {next.referrals.required}</span>
                            </Flex>
                        </Card>
                    )}
                    {next.variants?.length > 0 && (
                        <Card size="small" title={t('planv2.qualification_variants')}>
                            <div style={{ fontSize: 11.5, color: pal.muted, marginBottom: 8 }}>
                                {t('planv2.variants_hint')}
                            </div>
                            <Flex vertical gap={8}>
                                {next.variants.map((v) => (
                                    <div key={v.code} style={{ border: `1px solid ${pal.border}`, borderRadius: 10, padding: 10 }}>
                                        <Flex justify="space-between" align="center">
                                            <span style={{ fontWeight: 700 }}>{t('planv2.variant')} {v.code}</span>
                                            {v.satisfied
                                                ? <Tag color="success">{t('planv2.satisfied')}</Tag>
                                                : <Tag>{(v.actual_slots ?? 0)} / {v.required_slots}</Tag>}
                                        </Flex>
                                        <div style={{ fontSize: 12.5, color: pal.muted, marginTop: 4 }}>
                                            {v.anchor_count > 0 && (
                                                <div>{t('planv2.need_anchor', { count: v.anchor_count, rank: rankName(v.anchor_rank) })}</div>
                                            )}
                                            {v.support_count > 0 && (
                                                <div>{t('planv2.need_support', { count: v.support_count, rank: rankName(v.support_rank) })}</div>
                                            )}
                                            {v.distinct_root_branches && (
                                                <div style={{ color: pal.accent }}>{t('planv2.distinct_branches')}</div>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </Flex>
                        </Card>
                    )}
                </>
            )}

            {/* Лестница 12 статусов */}
            <Card size="small" title={t('planv2.ladder')}>
                <Flex vertical gap={4}>
                    {ladder.map((s) => {
                        const icon = s.achieved
                            ? <CheckCircleFilled style={{ color: pal.accent }} />
                            : <LockOutlined style={{ color: pal.muted }} />;
                        return (
                            <Flex key={s.code} justify="space-between" align="center"
                                style={{
                                    padding: '6px 8px', borderRadius: 8,
                                    background: s.is_current ? (pal.ghostBg ?? pal.heroBg) : 'transparent',
                                    fontWeight: s.is_current ? 700 : 500,
                                    opacity: s.achieved || s.is_current ? 1 : 0.6,
                                }}>
                                <Flex align="center" gap={8}>
                                    {icon}
                                    <span>{rankName(s.code)}</span>
                                    {s.is_current && <Tag color="gold" style={{ marginInlineStart: 4 }}>{t('planv2.you_are_here')}</Tag>}
                                </Flex>
                                {s.small_branch_pv_min != null && (
                                    <span style={{ fontSize: 11.5, color: pal.muted }}>{s.small_branch_pv_min} PV</span>
                                )}
                            </Flex>
                        );
                    })}
                </Flex>
            </Card>
        </Flex>
    );
};

export default StatusProgress;
