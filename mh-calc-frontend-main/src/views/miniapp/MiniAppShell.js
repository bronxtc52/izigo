'use client';
import React, { useEffect, useMemo, useState } from 'react';
import { ConfigProvider, Card, Statistic, Button, Tag, List, Spin, Result, Progress, message } from 'antd';
import { useTelegram, antdThemeFromTelegram } from './telegram';
import { mmMe, mmDashboard, mmRank, mmTree, mmActivate, PACKAGES } from './api';

const TABS = [
    { key: 'income', label: 'Доход' },
    { key: 'team', label: 'Команда' },
    { key: 'rank', label: 'Ранг' },
    { key: 'profile', label: 'Профиль' },
];
const TYPE_LABEL = { binary: 'Бинар', referral: 'Реферал', leader: 'Лидер', rank: 'Ранг' };

/** Рекурсивный компактный список дерева команды (вместо d3 на узком экране). */
const TreeList = ({ node, depth = 0 }) => {
    if (!node) return null;
    return (
        <div style={{ paddingLeft: depth ? 14 : 0 }}>
            <div style={{ padding: '6px 0', borderBottom: '1px solid rgba(128,128,128,0.15)' }}>
                <span style={{ fontWeight: depth ? 400 : 600 }}>{node.name}</span>{' '}
                <Tag color={node.attributes?.status === 'active' ? 'green' : 'default'} style={{ marginLeft: 6 }}>
                    {node.attributes?.status === 'active' ? 'активен' : 'нов'}
                </Tag>
            </div>
            {(node.children || []).map((c, i) => <TreeList key={i} node={c} depth={depth + 1} />)}
        </div>
    );
};

const MiniAppShell = () => {
    const { initData, theme, wa } = useTelegram();
    const [tab, setTab] = useState('income');
    const [me, setMe] = useState(null);
    const [dash, setDash] = useState(null);
    const [rank, setRank] = useState(null);
    const [tree, setTree] = useState(null);
    const [loading, setLoading] = useState(true);
    const [authError, setAuthError] = useState(false);
    const [serverError, setServerError] = useState(false);
    const [activating, setActivating] = useState(false);

    const themeConfig = useMemo(() => antdThemeFromTelegram(theme), [theme]);

    const load = async () => {
        const [m, d, r, t] = await Promise.all([
            mmMe(initData), mmDashboard(initData), mmRank(initData), mmTree(initData),
        ]);
        const errors = [m, d, r, t].map((x) => x?.error).filter((e) => e !== undefined);
        if (errors.includes(401)) { setAuthError(true); setLoading(false); return; }
        if (errors.length) { setServerError(true); setLoading(false); return; }
        setMe(m?.data?.member ?? null);
        setDash(d?.data ?? null);
        setRank(r?.data ?? null);
        setTree(t?.data ?? null);
        setLoading(false);
    };

    useEffect(() => {
        // initData готов (внутри Telegram) → грузим. Пустой initData = открыто вне Telegram.
        if (initData) load();
        else { setLoading(false); setAuthError(true); }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [initData]);

    const onActivate = async (pkgId) => {
        setActivating(true);
        const res = await mmActivate(initData, pkgId);
        setActivating(false);
        if (res?.error) { message.error('Не удалось активировать'); return; }
        message.success('Пакет активирован');
        wa?.HapticFeedback?.notificationOccurred?.('success');
        load();
    };

    if (loading) return <Spin size="large" style={{ display: 'block', margin: '80px auto' }} />;
    if (authError) {
        return (
            <Result
                status="warning"
                title="Откройте через Telegram"
                subTitle="Mini App доступен только внутри Telegram (авторизация по initData)."
            />
        );
    }
    if (serverError) {
        return (
            <Result
                status="error"
                title="Ошибка загрузки"
                subTitle="Не удалось получить данные. Попробуйте позже."
                extra={<Button type="primary" onClick={() => { setServerError(false); setLoading(true); load(); }}>Повторить</Button>}
            />
        );
    }

    const byType = dash?.by_type ?? {};

    return (
        <ConfigProvider theme={themeConfig}>
            <div style={{ minHeight: '100vh', paddingBottom: 64 }}>
                <div style={{ padding: 12 }}>
                    {tab === 'income' && (
                        <>
                            <Card size="small" style={{ marginBottom: 12 }}>
                                <Statistic title="Всего начислено" value={dash?.total ?? '0.00'} prefix="$" />
                                <div style={{ marginTop: 8, display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                                    {Object.entries(TYPE_LABEL).map(([k, l]) => (
                                        <Tag key={k}>{l}: ${byType[k] ?? '0.00'}</Tag>
                                    ))}
                                </div>
                            </Card>
                            <Card size="small" title="Пакет" style={{ marginBottom: 12 }}>
                                <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                                    {PACKAGES.map((p) => {
                                        const active = me?.package_id === p.id;
                                        return (
                                            <Button key={p.id} block type={active ? 'default' : 'primary'}
                                                disabled={active || activating} loading={activating}
                                                onClick={() => onActivate(p.id)}>
                                                {p.name} · {p.pv} PV {active ? '(активен)' : `· $${p.price}`}
                                            </Button>
                                        );
                                    })}
                                </div>
                            </Card>
                            <Card size="small" title="Начисления">
                                <List
                                    dataSource={dash?.lines ?? []}
                                    locale={{ emptyText: 'Пока пусто' }}
                                    renderItem={(l) => (
                                        <List.Item>
                                            <span><Tag>{TYPE_LABEL[l.type] ?? l.type}</Tag></span>
                                            <span>${l.amount}</span>
                                        </List.Item>
                                    )}
                                />
                            </Card>
                        </>
                    )}

                    {tab === 'team' && (
                        <Card size="small" title="Моя команда">
                            {tree?.name ? <TreeList node={tree} /> : 'Команда пуста'}
                        </Card>
                    )}

                    {tab === 'rank' && (
                        <Card size="small" title="Прогресс рангов">
                            <div style={{ marginBottom: 8 }}>
                                Ранг: <Tag color="gold">{rank?.current?.alias ?? 'нет'}</Tag>
                                → <Tag color="blue">{rank?.next?.alias ?? 'макс.'}</Tag>
                            </div>
                            {rank?.next && (
                                <>
                                    <div>Малая ветка: {rank.progress?.small_branch_pv ?? 0} / {rank.next.conditions.small_branch_pv} PV</div>
                                    <Progress percent={Math.min(100, Math.round(((rank.progress?.small_branch_pv ?? 0) / (rank.next.conditions.small_branch_pv || 1)) * 100))} />
                                    <div>Приглашённые: {rank.progress?.personal_count ?? 0} / {rank.next.conditions.personal_count}</div>
                                    <Progress percent={Math.min(100, Math.round(((rank.progress?.personal_count ?? 0) / (rank.next.conditions.personal_count || 1)) * 100))} />
                                </>
                            )}
                        </Card>
                    )}

                    {tab === 'profile' && (
                        <Card size="small" title="Профиль">
                            <p>Имя: {me?.name}</p>
                            <p>Статус: <Tag color={me?.status === 'active' ? 'green' : 'default'}>{me?.status}</Tag></p>
                            <p>Ранг: {me?.rank?.alias ?? 'нет'}</p>
                            <p>Реф-код: <b>{me?.ref_code}</b></p>
                        </Card>
                    )}
                </div>

                {/* Нижний таб-бар */}
                <div style={{
                    position: 'fixed', bottom: 0, left: 0, right: 0, height: 56,
                    display: 'flex', borderTop: '1px solid rgba(128,128,128,0.2)',
                    background: theme?.bg_color || '#fff',
                }}>
                    {TABS.map((t) => (
                        <button key={t.key} onClick={() => setTab(t.key)}
                            style={{
                                flex: 1, border: 'none', background: 'transparent', cursor: 'pointer',
                                fontWeight: tab === t.key ? 700 : 400,
                                color: tab === t.key ? (theme?.button_color || '#2ea6ff') : (theme?.hint_color || '#888'),
                            }}>
                            {t.label}
                        </button>
                    ))}
                </div>
            </div>
        </ConfigProvider>
    );
};

export default MiniAppShell;
