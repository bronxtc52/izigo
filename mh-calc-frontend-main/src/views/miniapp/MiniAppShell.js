'use client';
import React, { useEffect, useMemo, useState } from 'react';
import {
    ConfigProvider, Card, Statistic, Button, Tag, List, Spin, Result, Progress,
    Input, InputNumber, Modal, Avatar, Divider, Flex, message,
} from 'antd';
import {
    WalletOutlined, TeamOutlined, TrophyOutlined, UserOutlined,
    ExportOutlined, CopyOutlined, ShoppingOutlined,
} from '@ant-design/icons';
import { useTelegram, antdThemeFromTelegram, miniAppPalette } from './telegram';
import { tint, bonusTint, statusTint, roleTint, bonusDot, numFont } from './tokens';
import { mmMe, mmDashboard, mmRank, mmTree, mmWallet, mmWalletTx, mmWithdrawals, mmWithdrawCreate, PACKAGES } from './api';
import MiniAppShop from './MiniAppShop';

const TYPE_LABEL = { binary: 'Бинар', referral: 'Реферал', leader: 'Лидер', rank: 'Ранг' };
const TX_SOURCE_LABEL = { accrual: 'Начисление', withdrawal: 'Вывод' };
const WD_STATUS = {
    requested: { label: 'на рассмотрении', kind: 'blue' },
    approved: { label: 'одобрена', kind: 'blue' },
    paid: { label: 'выплачена', kind: 'green' },
    rejected: { label: 'отклонена', kind: 'amber' },
    cancelled: { label: 'отменена', kind: 'neutral' },
};

const initials = (name) => (name || '?').trim().split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();

/** Рекурсивный счётчик команды по дереву (всего узлов кроме корня + активных). */
const countTeam = (node) => {
    let total = 0;
    let active = 0;
    for (const c of node?.children ?? []) {
        total += 1;
        if (c.attributes?.status === 'active') active += 1;
        const sub = countTeam(c);
        total += sub.total;
        active += sub.active;
    }
    return { total, active };
};

/** Узел дерева команды: аватар-инициалы (tint по статусу) + имя + статус-бейдж, вложенность бордером. */
const TreeNode = ({ node, depth, isDark, filter }) => {
    if (!node) return null;
    const st = node.attributes?.status;
    const visible = filter === 'all' || (filter === 'active' && st === 'active') || (filter === 'new' && st !== 'active');
    const t = statusTint(st, isDark);
    const children = (node.children || []).filter(Boolean);
    return (
        <div style={depth ? { marginLeft: 15, borderLeft: `1.5px solid var(--tree-border)`, paddingLeft: 13 } : undefined}>
            {(depth === 0 || visible) && (
                <Flex align="center" gap={9} style={{ padding: '7px 0' }}>
                    <Avatar size={28} style={{ background: t.bg, color: t.color, fontSize: 11, fontWeight: 700 }}>
                        {initials(node.name)}
                    </Avatar>
                    <span style={{ fontSize: 13.5, fontWeight: depth ? 500 : 700, flex: 1 }}>{node.name}</span>
                    <Tag style={{ background: t.bg, color: t.color, border: 'none', fontSize: 10.5, fontWeight: 600, marginInlineEnd: 0 }}>
                        {st === 'active' ? 'активен' : 'нов'}
                    </Tag>
                </Flex>
            )}
            {children.map((c, i) => <TreeNode key={c.attributes?.id ?? c.name ?? i} node={c} depth={depth + 1} isDark={isDark} filter={filter} />)}
        </div>
    );
};

const MiniAppShell = () => {
    const { initData, theme, wa, ready, scheme } = useTelegram();
    const [tab, setTab] = useState('income');
    const [me, setMe] = useState(null);
    const [refLink, setRefLink] = useState('');
    const [dash, setDash] = useState(null);
    const [rank, setRank] = useState(null);
    const [tree, setTree] = useState(null);
    const [wallet, setWallet] = useState(null);
    const [walletTx, setWalletTx] = useState([]);
    const [withdrawals, setWithdrawals] = useState([]);
    const [teamFilter, setTeamFilter] = useState('all');
    const [loading, setLoading] = useState(true);
    const [authError, setAuthError] = useState(false);
    const [serverError, setServerError] = useState(false);
    const [wdOpen, setWdOpen] = useState(false);
    const [wdAmount, setWdAmount] = useState(null);
    const [wdDetails, setWdDetails] = useState('');
    const [wdSubmitting, setWdSubmitting] = useState(false);

    const themeConfig = useMemo(() => antdThemeFromTelegram(theme, scheme), [theme, scheme]);
    const pal = useMemo(() => miniAppPalette(theme, scheme), [theme, scheme]);
    const isDark = pal.isDark;

    const load = async () => {
        setAuthError(false);
        setServerError(false);
        setLoading(true);
        // Критичные данные кабинета: их сбой = экран ошибки/«Откройте через Telegram».
        const [m, d, r, t] = await Promise.all([
            mmMe(initData), mmDashboard(initData), mmRank(initData), mmTree(initData),
        ]);
        const errors = [m, d, r, t].map((x) => x?.error).filter((e) => e !== undefined);
        if (errors.includes(401)) { setAuthError(true); setLoading(false); return; }
        if (errors.length) { setServerError(true); setLoading(false); return; }
        setMe(m?.data?.member ?? null);
        setRefLink(m?.data?.ref_link ?? '');
        setDash(d?.data ?? null);
        setRank(r?.data ?? null);
        setTree(t?.data ?? null);
        // Кошелёк/выводы (Фаза 3) — опциональны: их сбой НЕ роняет кабинет, просто пустые секции.
        const [w, wtx, wd] = await Promise.all([
            mmWallet(initData), mmWalletTx(initData), mmWithdrawals(initData),
        ]);
        setWallet(w?.error ? null : (w?.data ?? null));
        setWalletTx(wtx?.error ? [] : (wtx?.data?.items ?? []));
        setWithdrawals(Array.isArray(wd?.data) ? wd.data : []);
        setLoading(false);
    };

    useEffect(() => {
        if (!ready) return;
        if (initData) load();
        else { setLoading(false); setAuthError(true); }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ready, initData]);

    const onWithdraw = async () => {
        const amount = Number(wdAmount);
        if (!amount || amount <= 0) { message.error('Укажите сумму вывода'); return; }
        if (!wdDetails.trim()) { message.error('Укажите реквизиты'); return; }
        setWdSubmitting(true);
        const res = await mmWithdrawCreate(initData, amount.toFixed(2), wdDetails.trim());
        setWdSubmitting(false);
        if (res?.error) { message.error('Не удалось создать заявку (проверьте сумму)'); return; }
        message.success('Заявка на вывод создана');
        wa?.HapticFeedback?.notificationOccurred?.('success');
        setWdAmount(null);
        setWdDetails('');
        setWdOpen(false);
        load();
    };

    const onCopyRef = () => {
        navigator.clipboard?.writeText(refLink).then(
            () => { message.success('Ссылка скопирована'); wa?.HapticFeedback?.notificationOccurred?.('success'); },
            () => message.error('Не удалось скопировать'),
        );
    };

    const byType = dash?.by_type ?? {};
    const teamCount = useMemo(() => (tree ? countTeam(tree) : { total: 0, active: 0 }), [tree]);
    const personalCount = (tree?.children ?? []).length;

    const TABS = [
        { key: 'income', label: 'Доход', icon: <WalletOutlined /> },
        { key: 'shop', label: 'Магазин', icon: <ShoppingOutlined /> },
        { key: 'team', label: 'Команда', icon: <TeamOutlined /> },
        { key: 'rank', label: 'Ранг', icon: <TrophyOutlined /> },
        { key: 'profile', label: 'Профиль', icon: <UserOutlined /> },
    ];
    // Админка вынесена в веб (admin.izigo.adarasoft.com) — в Mini App её больше нет.
    const tabs = TABS;

    const stateScreen = loading
        ? <Spin size="large" style={{ display: 'block', margin: '80px auto' }} />
        : authError
            ? <Result status="warning" title="Откройте через Telegram"
                subTitle="Mini App доступен только внутри Telegram (авторизация по initData)." />
            : serverError
                ? <Result status="error" title="Ошибка загрузки"
                    subTitle="Не удалось получить данные. Попробуйте позже."
                    extra={<Button type="primary" onClick={() => { setServerError(false); setLoading(true); load(); }}>Повторить</Button>} />
                : null;

    const SectionLabel = ({ children }) => (
        <div style={{ fontSize: 12, fontWeight: 700, color: pal.muted, margin: '2px 2px 8px' }}>{children}</div>
    );

    return (
        <ConfigProvider theme={themeConfig}>
            <div style={{ minHeight: '100vh', paddingBottom: stateScreen ? 0 : 74, background: pal.bg, color: pal.fg, ['--tree-border']: pal.border }}>
                {stateScreen ?? (
                <>
                <div style={{ padding: 14, display: 'flex', flexDirection: 'column', gap: 12 }}>

                    {tab === 'income' && (
                        <>
                            {/* Hero: всего начислено + доступно к выводу */}
                            <Card size="small" styles={{ body: { padding: 18 } }}>
                                <div style={{ fontSize: 12, color: pal.muted, fontWeight: 600 }}>Всего начислено</div>
                                <div style={{ ...numFont, fontWeight: 800, fontSize: 33, lineHeight: 1.1, marginTop: 2 }}>
                                    ${dash?.total ?? '0.00'}
                                </div>
                                <Divider style={{ margin: '14px 0' }} />
                                <Flex justify="space-between" align="center">
                                    <div>
                                        <div style={{ fontSize: 11.5, color: pal.muted }}>Доступно к выводу</div>
                                        <div style={{ ...numFont, fontWeight: 700, fontSize: 20 }}>${wallet?.available ?? '0.00'}</div>
                                        {Number(wallet?.held ?? 0) > 0 && (
                                            <div style={{ fontSize: 11, color: pal.muted }}>в холде ${wallet.held}</div>
                                        )}
                                        {Number(wallet?.clawback_debt ?? 0) > 0 && (
                                            <div style={{ fontSize: 11, color: pal.error }}>к компенсации ${wallet.clawback_debt}</div>
                                        )}
                                    </div>
                                    <Button type="primary" disabled={Number(wallet?.available ?? 0) <= 0}
                                        onClick={() => setWdOpen(true)}>Вывести</Button>
                                </Flex>
                            </Card>

                            {/* Бонусы по типам — 2×2 */}
                            <div>
                                <SectionLabel>Бонусы по типам</SectionLabel>
                                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
                                    {['binary', 'referral', 'leader', 'rank'].map((k) => (
                                        <Card key={k} size="small" styles={{ body: { padding: 12 } }}>
                                            <Flex align="center" gap={7}>
                                                <span style={{ width: 8, height: 8, borderRadius: '50%', background: bonusDot(k, isDark) }} />
                                                <span style={{ fontSize: 12, color: pal.muted }}>{TYPE_LABEL[k]}</span>
                                            </Flex>
                                            <div style={{ ...numFont, fontWeight: 700, fontSize: 18, marginTop: 4 }}>${byType[k] ?? '0.00'}</div>
                                        </Card>
                                    ))}
                                </div>
                            </div>

                            {/* Пакет — активация ТОЛЬКО через покупку в Магазине (без бесплатной активации) */}
                            <Card size="small" title="Пакет">
                                {me?.package_id ? (
                                    <Flex justify="space-between" align="center" gap={8}>
                                        <span style={{ fontSize: 13.5 }}>
                                            Текущий: <b>{PACKAGES.find((p) => p.id === me.package_id)?.name ?? `#${me.package_id}`}</b>
                                        </span>
                                        <Button onClick={() => setTab('shop')}>Сменить в Магазине</Button>
                                    </Flex>
                                ) : (
                                    <Flex justify="space-between" align="center" gap={8}>
                                        <span style={{ fontSize: 13, color: pal.muted }}>Пакет не активирован</span>
                                        <Button type="primary" onClick={() => setTab('shop')}>Купить в Магазине</Button>
                                    </Flex>
                                )}
                            </Card>

                            {/* Последние начисления */}
                            <Card size="small" title="Последние начисления">
                                <List
                                    dataSource={dash?.lines ?? []}
                                    locale={{ emptyText: 'Пока пусто' }}
                                    renderItem={(l) => {
                                        const t = bonusTint(l.type, isDark);
                                        return (
                                            <List.Item>
                                                <Flex align="center" gap={8}>
                                                    <span style={{ width: 8, height: 8, borderRadius: '50%', background: bonusDot(l.type, isDark) }} />
                                                    <Tag style={{ background: t.bg, color: t.color, border: 'none', fontWeight: 600 }}>
                                                        {TYPE_LABEL[l.type] ?? l.type}
                                                    </Tag>
                                                </Flex>
                                                <span style={{ ...numFont, color: pal.success, fontWeight: 700 }}>+${l.amount}</span>
                                            </List.Item>
                                        );
                                    }}
                                />
                            </Card>

                            {/* Заявки на вывод */}
                            {withdrawals.length > 0 && (
                                <Card size="small" title="Мои заявки">
                                    <List
                                        dataSource={withdrawals}
                                        renderItem={(w) => {
                                            const s = WD_STATUS[w.status] ?? { label: w.status, kind: 'neutral' };
                                            const t = tint(s.kind, isDark);
                                            return (
                                                <List.Item>
                                                    <span>
                                                        <span style={{ ...numFont, fontWeight: 700 }}>${w.amount}</span>{' '}
                                                        <Tag style={{ background: t.bg, color: t.color, border: 'none', fontSize: 10.5, fontWeight: 600 }}>{s.label}</Tag>
                                                    </span>
                                                    <span style={{ fontSize: 11, color: pal.muted }}>
                                                        {w.requested_at ? new Date(w.requested_at).toLocaleDateString() : ''}
                                                    </span>
                                                </List.Item>
                                            );
                                        }}
                                    />
                                </Card>
                            )}

                            {/* История движений по доступному балансу */}
                            {walletTx.length > 0 && (
                                <Card size="small" title="История операций">
                                    <List
                                        dataSource={walletTx}
                                        renderItem={(tx) => {
                                            const neg = String(tx.amount).startsWith('-');
                                            return (
                                                <List.Item>
                                                    <span style={{ color: pal.muted, fontSize: 12 }}>
                                                        {TX_SOURCE_LABEL[tx.source_type] ?? tx.source_type}
                                                        {tx.created_at ? ` · ${new Date(tx.created_at).toLocaleDateString()}` : ''}
                                                    </span>
                                                    <span style={{ ...numFont, color: neg ? pal.muted : pal.success, fontWeight: 700 }}>
                                                        ${tx.amount}
                                                    </span>
                                                </List.Item>
                                            );
                                        }}
                                    />
                                </Card>
                            )}
                        </>
                    )}

                    {tab === 'shop' && (
                        <MiniAppShop initData={initData} pal={pal} isDark={isDark} wa={wa}
                            onUnauthorized={() => setAuthError(true)} />
                    )}

                    {tab === 'team' && (
                        <>
                            <Card size="small" styles={{ body: { padding: 14 } }}>
                                <Flex justify="space-around" align="center">
                                    <Statistic title="В команде" value={teamCount.total} />
                                    <Divider type="vertical" style={{ height: 36 }} />
                                    <Statistic title="Активных" value={teamCount.active} valueStyle={{ color: pal.success }} />
                                    <Divider type="vertical" style={{ height: 36 }} />
                                    <Statistic title="Личных" value={personalCount} />
                                </Flex>
                            </Card>
                            <div style={{ display: 'flex', gap: 8 }}>
                                {[['all', 'Все'], ['active', 'Активные'], ['new', 'Новые']].map(([k, lbl]) => (
                                    <Button key={k} size="small" type={teamFilter === k ? 'primary' : 'default'}
                                        onClick={() => setTeamFilter(k)} style={{ flex: 1 }}>{lbl}</Button>
                                ))}
                            </div>
                            <Card size="small" title="Моя команда">
                                {tree?.name ? <TreeNode node={tree} depth={0} isDark={isDark} filter={teamFilter} /> : 'Команда пуста'}
                            </Card>
                        </>
                    )}

                    {tab === 'rank' && (
                        <>
                            <Card size="small">
                                <Flex justify="space-between" align="center">
                                    <Flex align="center" gap={12}>
                                        <div style={{ width: 52, height: 52, borderRadius: 14, display: 'flex', alignItems: 'center', justifyContent: 'center', background: roleTint('owner', isDark).bg }}>
                                            <TrophyOutlined style={{ fontSize: 24, color: roleTint('owner', isDark).color }} />
                                        </div>
                                        <div>
                                            <div style={{ fontSize: 11.5, color: pal.muted }}>Текущий ранг</div>
                                            <div style={{ ...numFont, fontWeight: 800, fontSize: 22 }}>{rank?.current?.alias ?? 'нет'}</div>
                                        </div>
                                    </Flex>
                                    <div style={{ textAlign: 'right' }}>
                                        <div style={{ fontSize: 11.5, color: pal.muted }}>далее</div>
                                        <div style={{ fontWeight: 700, color: pal.accent }}>{rank?.next?.alias ?? 'макс.'} ↗</div>
                                    </div>
                                </Flex>
                            </Card>
                            {rank?.next && (
                                <>
                                    <Card size="small" title="Малая ветка PV">
                                        <Flex justify="space-between" style={{ ...numFont, fontWeight: 700, marginBottom: 6 }}>
                                            <span>{rank.progress?.small_branch_pv ?? 0}</span>
                                            <span style={{ color: pal.muted }}>/ {rank.next.conditions.small_branch_pv}</span>
                                        </Flex>
                                        <Progress showInfo={false}
                                            percent={Math.min(100, Math.round(((rank.progress?.small_branch_pv ?? 0) / (rank.next.conditions.small_branch_pv || 1)) * 100))}
                                            strokeColor={bonusDot('binary', isDark)} trailColor={pal.bg} />
                                    </Card>
                                    <Card size="small" title="Приглашённые">
                                        <Flex justify="space-between" style={{ ...numFont, fontWeight: 700, marginBottom: 6 }}>
                                            <span>{rank.progress?.personal_count ?? 0}</span>
                                            <span style={{ color: pal.muted }}>/ {rank.next.conditions.personal_count}</span>
                                        </Flex>
                                        <Progress showInfo={false}
                                            percent={Math.min(100, Math.round(((rank.progress?.personal_count ?? 0) / (rank.next.conditions.personal_count || 1)) * 100))}
                                            strokeColor={bonusDot('referral', isDark)} trailColor={pal.bg} />
                                    </Card>
                                    <Card size="small" style={{ background: roleTint('leader', isDark).bg, borderColor: 'transparent' }}>
                                        <div style={{ fontWeight: 700, color: roleTint('leader', isDark).color, marginBottom: 4 }}>
                                            Ранг {rank.next.alias} открывает
                                        </div>
                                        <div style={{ fontSize: 12.5, color: pal.fg }}>＋ Лидерский бонус от объёма ветки</div>
                                        <div style={{ fontSize: 12.5, color: pal.fg }}>＋ Повышенные условия квалификации</div>
                                    </Card>
                                </>
                            )}
                        </>
                    )}

                    {tab === 'profile' && (
                        <>
                            <Card size="small">
                                <Flex vertical align="center" gap={6}>
                                    <Avatar size={66} style={{ background: pal.accent, color: '#fff', fontSize: 22, fontWeight: 700 }}>
                                        {initials(me?.name)}
                                    </Avatar>
                                    <div style={{ ...numFont, fontWeight: 800, fontSize: 19 }}>{me?.name ?? '—'}</div>
                                    <Flex gap={6}>
                                        <Tag style={{ background: statusTint(me?.status, isDark).bg, color: statusTint(me?.status, isDark).color, border: 'none', fontWeight: 600 }}>
                                            ● {me?.status === 'active' ? 'Активен' : 'Новый'}
                                        </Tag>
                                        {me?.rank?.alias && (
                                            <Tag style={{ background: roleTint('owner', isDark).bg, color: roleTint('owner', isDark).color, border: 'none', fontWeight: 600 }}>
                                                {me.rank.alias}
                                            </Tag>
                                        )}
                                    </Flex>
                                </Flex>
                                <Divider style={{ margin: '14px 0' }} />
                                <Flex justify="space-around">
                                    <Statistic title="Приглашено" value={personalCount} />
                                    <Statistic title="В команде" value={teamCount.total} />
                                    <Statistic title="ID" value={me?.id ?? '—'} formatter={(v) => `#${v}`} />
                                </Flex>
                            </Card>
                            <Card size="small" title="Реферальная ссылка">
                                <div style={{ ...numFont, fontWeight: 800, fontSize: 18, marginBottom: 8 }}>{me?.ref_code ?? '—'}</div>
                                <Input readOnly value={refLink} style={{ marginBottom: 8 }} />
                                <Flex gap={8}>
                                    <Button type="primary" icon={<CopyOutlined />} onClick={onCopyRef} style={{ flex: 1 }}>Копировать ссылку</Button>
                                    {wa?.openTelegramLink && (
                                        <Button icon={<ExportOutlined />} onClick={() => wa.openTelegramLink(`https://t.me/share/url?url=${encodeURIComponent(refLink)}`)} />
                                    )}
                                </Flex>
                            </Card>
                            <Card size="small" title="Настройки">
                                <List>
                                    <List.Item>Ранг <span style={{ color: pal.muted }}>{me?.rank?.alias ?? 'нет'} ›</span></List.Item>
                                    <List.Item>Статус <span style={{ color: pal.muted }}>{me?.status} ›</span></List.Item>
                                    <List.Item>
                                        <span style={{ color: pal.error, cursor: 'pointer' }} onClick={() => wa?.close?.()}>Закрыть</span>
                                    </List.Item>
                                </List>
                            </Card>
                        </>
                    )}

                </div>

                {/* Нижний таб-бар с иконками */}
                <div style={{
                    position: 'fixed', bottom: 0, left: 0, right: 0, height: 62,
                    display: 'flex', borderTop: `1px solid ${pal.border}`,
                    background: pal.surface, boxShadow: pal.shadow,
                }}>
                    {tabs.map((t) => {
                        const on = tab === t.key;
                        return (
                            <button key={t.key} onClick={() => setTab(t.key)}
                                style={{
                                    flex: 1, border: 'none', background: 'transparent', cursor: 'pointer',
                                    display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 3,
                                    fontSize: 10.5, fontWeight: on ? 700 : 500,
                                    color: on ? pal.accent : pal.tabInactive,
                                }}>
                                <span style={{ fontSize: 18, color: on ? pal.accent : pal.tabInactive }}>{t.icon}</span>
                                {t.label}
                            </button>
                        );
                    })}
                </div>

                {/* Модалка вывода средств */}
                <Modal title="Вывод средств" open={wdOpen} onOk={onWithdraw} onCancel={() => setWdOpen(false)}
                    okText="Запросить вывод" confirmLoading={wdSubmitting}>
                    <div style={{ fontSize: 12, color: pal.muted, marginBottom: 8 }}>Доступно: ${wallet?.available ?? '0.00'}</div>
                    <InputNumber style={{ width: '100%', marginBottom: 8 }} min={0.01} step={0.01} precision={2}
                        prefix="$" placeholder="Сумма" value={wdAmount} onChange={setWdAmount} />
                    <Input.TextArea rows={2} maxLength={1000} placeholder="Реквизиты (банк/крипто-кошелёк)"
                        value={wdDetails} onChange={(e) => setWdDetails(e.target.value)} />
                </Modal>
                </>
                )}
            </div>
        </ConfigProvider>
    );
};

export default MiniAppShell;
