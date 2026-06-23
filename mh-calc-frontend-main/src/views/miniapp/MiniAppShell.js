'use client';
import React, { useEffect, useMemo, useState } from 'react';
import {
    ConfigProvider, Card, Statistic, Button, Tag, List, Spin, Result, Progress,
    Input, InputNumber, Modal, Avatar, Divider, Flex, DatePicker, message,
    Segmented, Popconfirm,
} from 'antd';
import {
    WalletOutlined, TeamOutlined, TrophyOutlined, UserOutlined,
    ExportOutlined, CopyOutlined, ShoppingOutlined, SwapOutlined, ClockCircleOutlined,
} from '@ant-design/icons';
import { useTelegram, antdThemeFromTelegram, miniAppPalette } from './telegram';
import { tint, bonusTint, statusTint, roleTint, bonusDot, numFont, balanceFont } from './tokens';
import {
    mmMe, mmDashboard, mmRank, mmTree, mmWallet, mmWalletTx, mmWalletStatement, mmWithdrawals, mmWithdrawCreate,
    mmTopup, mmKyc, mmKycSubmit, mmAgreement, mmAgreementAccept, PACKAGES,
    mmCopartners, mmCopartnerCreate, mmCopartnerUpdate, mmCopartnerDelete,
    mmFeatureFlags, mmPersonalReferrals, mmChangeSponsor,
} from './api';
import MiniAppShop from './MiniAppShop';
import TonPayCheckout from './TonPayCheckout';
import SplashScreen from './SplashScreen';
import { visibleBlockCTabs, blockCTabRender } from './tabs/registry';

// Базовая проверка user-friendly TON-адреса (48 символов base64url, префикс EQ/UQ/kQ/0Q…).
// Backend трактует payout_details как TON-адрес получателя USDT (валидации формата на бэке нет).
const isTonAddress = (s) => /^[EUk0][Qf][A-Za-z0-9_-]{46}$/.test(String(s || '').trim());

const KYC_STATUS = {
    none: { label: 'не пройдена', kind: 'neutral' },
    pending: { label: 'на проверке', kind: 'amber' },
    approved: { label: 'подтверждена', kind: 'green' },
    rejected: { label: 'отклонена', kind: 'amber' },
};

const TYPE_LABEL = { binary: 'Бинар', referral: 'Реферал', leader: 'Лидер', rank: 'Ранг' };
const TX_SOURCE_LABEL = { accrual: 'Начисление', withdrawal: 'Вывод' };

// A2: экспорт выписки (массив движений) в CSV-файл, без сторонних либ.
const exportStatementCsv = (items) => {
    const esc = (v) => {
        const s = v == null ? '' : String(v);
        return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    };
    const head = 'date,type,amount_usd,source_id';
    const body = items.map((t) => [
        esc(t.created_at ?? ''),
        esc(TX_SOURCE_LABEL[t.source_type] ?? t.source_type),
        esc(t.amount),
        esc(t.source_id ?? ''),
    ].join(',')).join('\n');
    const blob = new Blob([`${head}\n${body}`], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'statement.csv';
    a.click();
    URL.revokeObjectURL(url);
};
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
    // Лид (ещё не купил пакет): спонсор/окно/смена. needReferral — валидный юзер без спонсора.
    const [leadInfo, setLeadInfo] = useState(null);
    const [needReferral, setNeedReferral] = useState(false);
    const [csOpen, setCsOpen] = useState(false);
    const [csRef, setCsRef] = useState('');
    const [csBusy, setCsBusy] = useState(false);
    // Личные рефералы (sponsor_id, любая глубина) — отдельно от бинар-дерева.
    const [personalReferrals, setPersonalReferrals] = useState([]);
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
    const [wdDetails, setWdDetails] = useState(''); // = TON-адрес получателя USDT (payout_details на бэке)
    const [wdSubmitting, setWdSubmitting] = useState(false);
    // F5: пополнение внутреннего USDT-баланса через тот же TON Pay checkout.
    const [topupOpen, setTopupOpen] = useState(false);
    const [topupAmount, setTopupAmount] = useState(null);
    const [topupSubmitting, setTopupSubmitting] = useState(false);
    const [topupInvoice, setTopupInvoice] = useState(null);
    // F6: KYC-статус партнёра.
    const [kyc, setKyc] = useState(null);
    const [kycSubmitting, setKycSubmitting] = useState(false);
    // B3: онбординг — пользовательское соглашение.
    const [agreement, setAgreement] = useState(null);
    const [agreeBusy, setAgreeBusy] = useState(false);
    // A2: выписка партнёра за период.
    const [stmtOpen, setStmtOpen] = useState(false);
    const [stmtRange, setStmtRange] = useState(null);
    const [stmtData, setStmtData] = useState(null);
    const [stmtLoading, setStmtLoading] = useState(false);
    // C6: со-партнёры / наследники (справочные данные профиля).
    const [copartners, setCopartners] = useState([]);
    const [cpOpen, setCpOpen] = useState(false);
    const [cpEditId, setCpEditId] = useState(null); // null = создание новой записи
    const [cpForm, setCpForm] = useState({ kind: 'copartner', full_name: '', phone: '', share_percent: null, note: '' });
    const [cpSaving, setCpSaving] = useState(false);
    // C3: карта активных фиче-флагов кабинета {key: true}. Deny-by-default — пустой
    // объект, пока не загрузилась (или при сбое) => все blockC-фичи скрыты. Базовый
    // интерфейс (income/shop/team/rank/profile) от флагов НЕ зависит.
    const [flags, setFlags] = useState({});

    const themeConfig = useMemo(() => antdThemeFromTelegram(theme, scheme), [theme, scheme]);
    const pal = useMemo(() => miniAppPalette(theme, scheme), [theme, scheme]);
    const isDark = pal.isDark;

    const load = async () => {
        setAuthError(false);
        setServerError(false);
        setNeedReferral(false);
        setLeadInfo(null);
        setLoading(true);

        // Сначала идентичность: участник / лид (ещё не купил) / никто (нужна реф-ссылка).
        const m = await mmMe(initData);
        if (m?.error === 401) { setAuthError(true); setLoading(false); return; }
        if (m?.error) { setServerError(true); setLoading(false); return; }
        const data = m?.data ?? {};

        if (data.is_lead) {
            // Лид: дерева/дохода/кошелька нет — отдельный экран «активируйте пакет».
            setLeadInfo(data);
            setMe(null);
            setLoading(false);
            return;
        }
        if (data.need_referral) {
            setNeedReferral(true);
            setMe(null);
            setLoading(false);
            return;
        }

        // Участник: профиль + критичные данные кабинета (их сбой = экран ошибки).
        setMe(data.member ?? null);
        setRefLink(data.ref_link ?? '');
        const [d, r, t] = await Promise.all([
            mmDashboard(initData), mmRank(initData), mmTree(initData),
        ]);
        const errors = [d, r, t].map((x) => x?.error).filter((e) => e !== undefined);
        if (errors.includes(401)) { setAuthError(true); setLoading(false); return; }
        if (errors.length) { setServerError(true); setLoading(false); return; }
        setDash(d?.data ?? null);
        setRank(r?.data ?? null);
        setTree(t?.data ?? null);
        // Кошелёк/выводы + KYC + соглашение + флаги + личные рефералы — опциональны: их сбой НЕ роняет кабинет.
        const [w, wtx, wd, k, ag, cp, ff, pr] = await Promise.all([
            mmWallet(initData), mmWalletTx(initData), mmWithdrawals(initData), mmKyc(initData), mmAgreement(initData),
            mmCopartners(initData), mmFeatureFlags(initData), mmPersonalReferrals(initData),
        ]);
        setWallet(w?.error ? null : (w?.data ?? null));
        setWalletTx(wtx?.error ? [] : (wtx?.data?.items ?? []));
        setWithdrawals(Array.isArray(wd?.data) ? wd.data : []);
        setKyc(k?.error ? null : (k?.data ?? null));
        setAgreement(ag?.error ? null : (ag?.data ?? null));
        setCopartners(Array.isArray(cp?.data) ? cp.data : []);
        setPersonalReferrals(Array.isArray(pr?.data) ? pr.data : []);
        // Deny-by-default: сбой/невалидный ответ (или не-объект/массив) => пустая карта => blockC-фичи скрыты.
        setFlags(ff?.error || !ff?.data || typeof ff.data !== 'object' || Array.isArray(ff.data) ? {} : ff.data);
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
        if (!isTonAddress(wdDetails)) { message.error('Укажите корректный TON-адрес (USDT)'); return; }
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

    // A2: загрузить выписку за выбранный период (пустой период = вся история).
    const onLoadStatement = async () => {
        setStmtLoading(true);
        const from = stmtRange?.[0] ? stmtRange[0].format('YYYY-MM-DD') : '';
        const to = stmtRange?.[1] ? stmtRange[1].format('YYYY-MM-DD') : '';
        const res = await mmWalletStatement(initData, from, to);
        setStmtLoading(false);
        if (res?.error) { message.error('Не удалось загрузить выписку'); return; }
        setStmtData(res?.data ?? null);
    };

    const openStatement = () => { setStmtData(null); setStmtRange(null); setStmtOpen(true); onLoadStatement(); };

    // B3: принять соглашение (онбординг-гейт). До акцепта кабинет заблокирован.
    const onAcceptAgreement = async () => {
        setAgreeBusy(true);
        const res = await mmAgreementAccept(initData);
        setAgreeBusy(false);
        if (res?.error) { message.error('Не удалось принять соглашение'); return; }
        setAgreement(res?.data ?? null);
        wa?.HapticFeedback?.notificationOccurred?.('success');
    };

    // F5: создать счёт на пополнение → открыть TON Pay checkout (без заказа, только зачисление).
    const onTopup = async () => {
        const amount = Number(topupAmount);
        if (!amount || amount <= 0) { message.error('Укажите сумму пополнения'); return; }
        setTopupSubmitting(true);
        const res = await mmTopup(initData, Math.round(amount * 100)); // доллары → центы
        setTopupSubmitting(false);
        if (res?.error) { message.error('Не удалось создать счёт на пополнение'); return; }
        wa?.HapticFeedback?.impactOccurred?.('light');
        setTopupOpen(false);
        setTopupAmount(null);
        setTopupInvoice(res?.data ?? null);
    };

    const onTopupPaid = () => { setTopupInvoice(null); load(); };

    // F6: подать на верификацию. Реальный сбор документов через Telegram Passport — Фаза 5
    // (NEEDS-LIVE-VERIFY); здесь intake-заявка переводит KYC в pending для ручного аппрува.
    const onKycSubmit = async () => {
        setKycSubmitting(true);
        const res = await mmKycSubmit(initData, [{ type: 'passport', source: 'mini_app_intake' }]);
        setKycSubmitting(false);
        if (res?.error) { message.error('Не удалось отправить заявку на верификацию'); return; }
        message.success('Заявка на верификацию отправлена');
        wa?.HapticFeedback?.notificationOccurred?.('success');
        setKyc(res?.data ?? null);
    };

    // C6: со-партнёры/наследники — справочные данные, на деньги/дерево не влияют.
    const loadCopartners = async () => {
        const res = await mmCopartners(initData);
        setCopartners(Array.isArray(res?.data) ? res.data : []);
    };

    const openCpCreate = () => {
        setCpEditId(null);
        setCpForm({ kind: 'copartner', full_name: '', phone: '', share_percent: null, note: '' });
        setCpOpen(true);
    };

    const openCpEdit = (c) => {
        setCpEditId(c.id);
        setCpForm({
            kind: c.kind ?? 'copartner',
            full_name: c.full_name ?? '',
            phone: c.phone ?? '',
            share_percent: c.share_percent != null ? Number(c.share_percent) : null,
            note: c.note ?? '',
        });
        setCpOpen(true);
    };

    const onCpSave = async () => {
        if (!cpForm.full_name.trim()) { message.error('Укажите ФИО'); return; }
        const payload = {
            kind: cpForm.kind,
            full_name: cpForm.full_name.trim(),
            phone: cpForm.phone?.trim() || null,
            share_percent: cpForm.share_percent != null ? cpForm.share_percent : null,
            note: cpForm.note?.trim() || null,
        };
        setCpSaving(true);
        const res = cpEditId
            ? await mmCopartnerUpdate(initData, cpEditId, payload)
            : await mmCopartnerCreate(initData, payload);
        setCpSaving(false);
        if (res?.error) { message.error('Не удалось сохранить запись'); return; }
        wa?.HapticFeedback?.notificationOccurred?.('success');
        setCpOpen(false);
        loadCopartners();
    };

    const onCpDelete = async (id) => {
        const res = await mmCopartnerDelete(initData, id);
        if (res?.error) { message.error('Не удалось удалить запись'); return; }
        loadCopartners();
    };

    const onCopyRef = () => {
        navigator.clipboard?.writeText(refLink).then(
            () => { message.success('Ссылка скопирована'); wa?.HapticFeedback?.notificationOccurred?.('success'); },
            () => message.error('Не удалось скопировать'),
        );
    };

    // Лид: сменить спонсора по ref-коду (доступно, пока окно не истекло и пакет не куплен).
    const onChangeSponsor = async () => {
        const code = csRef.trim();
        if (!code) { message.error('Введите реф-код спонсора'); return; }
        setCsBusy(true);
        const res = await mmChangeSponsor(initData, code);
        setCsBusy(false);
        if (res?.error || res?.status === 'error') {
            message.error('Не удалось сменить спонсора (проверьте код, срок не истёк)');
            return;
        }
        message.success('Спонсор обновлён');
        wa?.HapticFeedback?.notificationOccurred?.('success');
        setCsOpen(false);
        setCsRef('');
        setLeadInfo(res?.data ?? leadInfo);
    };

    // Остаток лид-окна в днях (для подсказки лиду).
    const leadDaysLeft = (() => {
        if (!leadInfo?.expires_at) return null;
        const ms = new Date(leadInfo.expires_at).getTime() - Date.now();
        return ms > 0 ? Math.ceil(ms / 86400000) : 0;
    })();

    const byType = dash?.by_type ?? {};
    const teamCount = useMemo(() => (tree ? countTeam(tree) : { total: 0, active: 0 }), [tree]);
    // «Личные» = по спонсорству (sponsor_id), любая глубина бинара — НЕ две прямые ноги дерева.
    const personalCount = me?.personal_count ?? 0;

    const TABS = [
        { key: 'income', label: 'Доход', icon: <WalletOutlined /> },
        { key: 'shop', label: 'Магазин', icon: <ShoppingOutlined /> },
        { key: 'team', label: 'Команда', icon: <TeamOutlined /> },
        { key: 'rank', label: 'Ранг', icon: <TrophyOutlined /> },
        { key: 'profile', label: 'Профиль', icon: <UserOutlined /> },
    ];
    // Админка вынесена в веб (admin.izigo.adarasoft.com) — в Mini App её больше нет.
    // Block C: вкладки фич подмешиваются из registry, отфильтрованные по фиче-флагам
    // (deny-by-default: пустая карта flags => флаговые вкладки скрыты, базовый таб-бар цел).
    const tabs = [...TABS, ...visibleBlockCTabs(flags)];
    // Block C: контекст шелла для render вкладок фич (чтобы не дублировать загрузку данных).
    const blockCCtx = { initData, pal, isDark, wa, me, dash, rank, tree, wallet, reload: load };

    const stateScreen = loading
        ? <Spin size="large" style={{ display: 'block', margin: '80px auto' }} />
        : authError
            ? <Result status="warning" title="Откройте через Telegram"
                subTitle="Mini App доступен только внутри Telegram (авторизация по initData)." />
            : serverError
                ? <Result status="error" title="Ошибка загрузки"
                    subTitle="Не удалось получить данные. Попробуйте позже."
                    extra={<Button type="primary" onClick={() => { setServerError(false); setLoading(true); load(); }}>Повторить</Button>} />
                : needReferral
                    ? <Result status="info" title="Нужна реферальная ссылка"
                        subTitle="Откройте приложение по приглашению партнёра, чтобы присоединиться к команде." />
                    : null;

    const SectionLabel = ({ children }) => (
        <div style={{ fontSize: 12, fontWeight: 700, color: pal.muted, margin: '2px 2px 8px' }}>{children}</div>
    );

    // Aurora-хелперы: градиент только на hero / балансе / CTA / прогрессе (PR2).
    const heroCardStyle = { background: pal.heroBg, border: `1px solid ${pal.heroBorder}`, boxShadow: pal.heroGlow };
    const gradBtnStyle = { background: pal.primBg, color: pal.primTxt, border: 'none', boxShadow: pal.primGlow };
    const balGradStyle = { ...balanceFont, fontWeight: 700, background: pal.balGrad, WebkitBackgroundClip: 'text', backgroundClip: 'text', color: 'transparent' };
    const progGrad = { '0%': pal.brand, '100%': isDark ? '#5EE3F5' : '#2563EB' };

    return (
        <ConfigProvider theme={themeConfig}>
            <div style={{ minHeight: '100vh', paddingBottom: stateScreen ? 0 : 74, background: pal.scrbg, color: pal.fg, ['--tree-border']: pal.border }}>
                {/* Aurora-сплэш запуска: висит, пока грузимся (loading=true до ready+данных), затем crossfade. */}
                <SplashScreen active={loading} pal={pal} />
                {stateScreen ?? (leadInfo ? (
                <div style={{ padding: 14, display: 'flex', flexDirection: 'column', gap: 12 }}>
                    {/* Экран ЛИДА: ещё не купил пакет → вне дерева. Может купить (промоушн в Member)
                        или сменить спонсора, пока не активировал. */}
                    <Card size="small" style={heroCardStyle} styles={{ body: { padding: 18 } }}>
                        <div style={{ ...numFont, fontSize: 18, fontWeight: 800 }}>Добро пожаловать 👋</div>
                        <div style={{ fontSize: 13.5, color: pal.muted, marginTop: 6 }}>
                            Активируйте любой пакет, чтобы вступить в команду и получить свою реферальную ссылку.
                        </div>
                        <Divider style={{ margin: '14px 0' }} />
                        <Flex justify="space-between" align="center" gap={8}>
                            <div style={{ minWidth: 0 }}>
                                <div style={{ fontSize: 11.5, color: pal.muted }}>Ваш спонсор</div>
                                <div style={{ ...numFont, fontWeight: 700, fontSize: 16 }}>{leadInfo.sponsor?.name ?? '—'}</div>
                                {leadInfo.sponsor?.ref_code && (
                                    <div style={{ fontSize: 11, color: pal.muted }}>код {leadInfo.sponsor.ref_code}</div>
                                )}
                            </div>
                            <Button icon={<SwapOutlined />} onClick={() => { setCsRef(''); setCsOpen(true); }}>Сменить</Button>
                        </Flex>
                        {leadDaysLeft != null && (
                            <div style={{ fontSize: 11.5, color: pal.muted, marginTop: 10 }}>
                                <ClockCircleOutlined /> Привязка к спонсору активна ещё {leadDaysLeft} дн. — сменить спонсора можно до первой покупки.
                            </div>
                        )}
                    </Card>

                    <MiniAppShop initData={initData} pal={pal} isDark={isDark} wa={wa}
                        leadMode onUnauthorized={() => setAuthError(true)} onAfterPaid={load} />

                    <Modal title="Сменить спонсора" open={csOpen} onOk={onChangeSponsor} confirmLoading={csBusy}
                        onCancel={() => setCsOpen(false)} okText="Сменить">
                        <div style={{ fontSize: 12, color: pal.muted, marginBottom: 8 }}>
                            Введите реф-код нового спонсора. Сменить можно, пока вы не активировали пакет.
                        </div>
                        <Input placeholder="Реф-код (например A1B2C3D4)" value={csRef} maxLength={16}
                            onChange={(e) => setCsRef(e.target.value.trim().toUpperCase())} />
                    </Modal>
                </div>
                ) : (
                <>
                <div style={{ padding: 14, display: 'flex', flexDirection: 'column', gap: 12 }}>

                    {tab === 'income' && (
                        <>
                            {/* Hero: всего начислено + доступно к выводу (Aurora: градиентный баланс + свечение) */}
                            <Card size="small" style={heroCardStyle} styles={{ body: { padding: 18 } }}>
                                <div style={{ fontSize: 11, color: pal.muted, fontWeight: 600, letterSpacing: '.1em', textTransform: 'uppercase' }}>Всего начислено</div>
                                <div style={{ ...balGradStyle, fontWeight: 800, fontSize: 36, lineHeight: 1.05, marginTop: 6 }}>
                                    ${dash?.total ?? '0.00'}
                                </div>
                                <Divider style={{ margin: '14px 0' }} />
                                <Flex justify="space-between" align="center">
                                    <div>
                                        <div style={{ fontSize: 11.5, color: pal.muted }}>Доступно к выводу</div>
                                        <div style={{ ...balanceFont, fontWeight: 700, fontSize: 20 }}>${wallet?.available ?? '0.00'}</div>
                                        {Number(wallet?.held ?? 0) > 0 && (
                                            <div style={{ fontSize: 11, color: pal.muted }}>в холде ${wallet.held}</div>
                                        )}
                                        {Number(wallet?.clawback_debt ?? 0) > 0 && (
                                            <div style={{ fontSize: 11, color: pal.error }}>к компенсации ${wallet.clawback_debt}</div>
                                        )}
                                    </div>
                                    <Flex vertical gap={8}>
                                        <Button type="primary" style={Number(wallet?.available ?? 0) <= 0 ? undefined : gradBtnStyle}
                                            disabled={Number(wallet?.available ?? 0) <= 0}
                                            onClick={() => setWdOpen(true)}>Вывести</Button>
                                        <Button onClick={() => { setTopupAmount(null); setTopupOpen(true); }}>Пополнить</Button>
                                    </Flex>
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
                                            <div style={{ ...balanceFont, fontWeight: 700, fontSize: 18, marginTop: 4 }}>${byType[k] ?? '0.00'}</div>
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
                                                <span style={{ ...balanceFont, color: pal.pos, fontWeight: 700 }}>+${l.amount}</span>
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
                                <Card
                                    size="small"
                                    title="История операций"
                                    extra={<Button size="small" type="link" onClick={openStatement}>Выписка</Button>}
                                >
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

                            {/* A2: выписка за период — фильтр, сводка, экспорт CSV */}
                            <Modal
                                open={stmtOpen}
                                onCancel={() => setStmtOpen(false)}
                                title="Выписка за период"
                                footer={null}
                                styles={{ body: { paddingTop: 8 } }}
                            >
                                <Flex gap={8} align="center" wrap style={{ marginBottom: 12 }}>
                                    <DatePicker.RangePicker
                                        value={stmtRange}
                                        onChange={setStmtRange}
                                        size="small"
                                        allowClear
                                    />
                                    <Button size="small" type="primary" loading={stmtLoading} onClick={onLoadStatement}>
                                        Показать
                                    </Button>
                                    <Button
                                        size="small"
                                        icon={<ExportOutlined />}
                                        disabled={!stmtData?.items?.length}
                                        onClick={() => exportStatementCsv(stmtData.items)}
                                    >
                                        CSV
                                    </Button>
                                </Flex>

                                {stmtData?.summary && (
                                    <Flex justify="space-between" style={{ marginBottom: 12 }}>
                                        <Statistic title="Поступило" value={`$${(stmtData.summary.credited_cents / 100).toFixed(2)}`}
                                            valueStyle={{ fontSize: 16, color: pal.success }} />
                                        <Statistic title="Списано" value={`$${(stmtData.summary.debited_cents / 100).toFixed(2)}`}
                                            valueStyle={{ fontSize: 16, color: pal.muted }} />
                                        <Statistic title="Итог" value={`$${(stmtData.summary.net_cents / 100).toFixed(2)}`}
                                            valueStyle={{ fontSize: 16 }} />
                                    </Flex>
                                )}

                                <List
                                    size="small"
                                    loading={stmtLoading}
                                    locale={{ emptyText: 'Нет движений за период' }}
                                    dataSource={stmtData?.items ?? []}
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
                            </Modal>
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
                            <Card size="small" title="Бинарная команда">
                                <div style={{ fontSize: 11.5, color: pal.muted, marginBottom: 8 }}>
                                    Структура размещения (2 ветки). Сюда по спилловеру встают и не ваши личные рефералы.
                                </div>
                                {tree?.name ? <TreeNode node={tree} depth={0} isDark={isDark} filter={teamFilter} /> : 'Команда пуста'}
                            </Card>

                            <Card size="small" title="Личные рефералы">
                                <div style={{ fontSize: 11.5, color: pal.muted, marginBottom: 8 }}>
                                    Кого вы лично пригласили (по реф-ссылке) и кто купил. Могут стоять на любой глубине бинара.
                                </div>
                                <List
                                    dataSource={personalReferrals}
                                    locale={{ emptyText: 'Личных рефералов пока нет' }}
                                    renderItem={(p) => {
                                        const t = statusTint(p.status, isDark);
                                        return (
                                            <List.Item>
                                                <Flex align="center" gap={9} style={{ flex: 1, minWidth: 0 }}>
                                                    <Avatar size={28} style={{ background: t.bg, color: t.color, fontSize: 11, fontWeight: 700 }}>
                                                        {initials(p.name)}
                                                    </Avatar>
                                                    <span style={{ fontSize: 13.5, fontWeight: 600, flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{p.name}</span>
                                                </Flex>
                                                <Flex gap={6} align="center">
                                                    {p.depth_from_me != null && (
                                                        <Tag style={{ background: tint('blue', isDark).bg, color: tint('blue', isDark).color, border: 'none', fontSize: 10.5 }}>
                                                            глубина {p.depth_from_me}
                                                        </Tag>
                                                    )}
                                                    <Tag style={{ background: t.bg, color: t.color, border: 'none', fontSize: 10.5, fontWeight: 600, marginInlineEnd: 0 }}>
                                                        {p.status === 'active' ? 'активен' : 'нов'}
                                                    </Tag>
                                                </Flex>
                                            </List.Item>
                                        );
                                    }}
                                />
                            </Card>
                        </>
                    )}

                    {tab === 'rank' && (
                        <>
                            <Card size="small" style={heroCardStyle}>
                                <Flex justify="space-between" align="center">
                                    <Flex align="center" gap={12}>
                                        <div style={{ width: 52, height: 52, borderRadius: 15, display: 'flex', alignItems: 'center', justifyContent: 'center', background: pal.heroBg, border: `1px solid ${pal.heroBorder}` }}>
                                            <TrophyOutlined style={{ fontSize: 24, color: pal.accent2 }} />
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
                                        <Flex justify="space-between" style={{ ...balanceFont, fontWeight: 700, marginBottom: 6 }}>
                                            <span>{rank.progress?.small_branch_pv ?? 0}</span>
                                            <span style={{ color: pal.muted }}>/ {rank.next.conditions.small_branch_pv}</span>
                                        </Flex>
                                        <Progress showInfo={false}
                                            percent={Math.min(100, Math.round(((rank.progress?.small_branch_pv ?? 0) / (rank.next.conditions.small_branch_pv || 1)) * 100))}
                                            strokeColor={progGrad} trailColor={pal.ghostBg} />
                                    </Card>
                                    <Card size="small" title="Приглашённые">
                                        <Flex justify="space-between" style={{ ...balanceFont, fontWeight: 700, marginBottom: 6 }}>
                                            <span>{rank.progress?.personal_count ?? 0}</span>
                                            <span style={{ color: pal.muted }}>/ {rank.next.conditions.personal_count}</span>
                                        </Flex>
                                        <Progress showInfo={false}
                                            percent={Math.min(100, Math.round(((rank.progress?.personal_count ?? 0) / (rank.next.conditions.personal_count || 1)) * 100))}
                                            strokeColor={progGrad} trailColor={pal.ghostBg} />
                                    </Card>
                                    <Card size="small" style={heroCardStyle}>
                                        <div style={{ fontWeight: 700, color: pal.fg, marginBottom: 4 }}>
                                            Ранг {rank.next.alias} открывает
                                        </div>
                                        <div style={{ fontSize: 12.5, color: pal.muted }}>＋ Лидерский бонус от объёма ветки</div>
                                        <div style={{ fontSize: 12.5, color: pal.muted }}>＋ Повышенные условия квалификации</div>
                                    </Card>
                                </>
                            )}
                        </>
                    )}

                    {tab === 'profile' && (
                        <>
                            <Card size="small" style={heroCardStyle}>
                                <Flex vertical align="center" gap={6}>
                                    <Avatar size={66} style={{ background: pal.primBg, color: pal.primTxt, fontSize: 22, fontWeight: 700, boxShadow: pal.primGlow }}>
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
                                <Flex justify="space-around" wrap="wrap" gap={8}>
                                    <Statistic title="Заработано" value={`$${dash?.total ?? '0.00'}`} />
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
                            <Card size="small" title="Верификация (KYC)">
                                {(() => {
                                    const s = kyc?.status ?? 'none';
                                    const k = KYC_STATUS[s] ?? KYC_STATUS.none;
                                    const t = tint(k.kind, isDark);
                                    return (
                                        <>
                                            <Flex justify="space-between" align="center">
                                                <span style={{ fontSize: 13 }}>Статус</span>
                                                <Tag style={{ background: t.bg, color: t.color, border: 'none', fontWeight: 600 }}>{k.label}</Tag>
                                            </Flex>
                                            {s === 'rejected' && kyc?.reject_reason && (
                                                <div style={{ fontSize: 11.5, color: pal.error, marginTop: 6 }}>Причина: {kyc.reject_reason}</div>
                                            )}
                                            {(s === 'none' || s === 'rejected') && (
                                                <Button type="primary" block style={{ marginTop: 10 }} loading={kycSubmitting}
                                                    onClick={onKycSubmit}>Пройти верификацию</Button>
                                            )}
                                            {s === 'pending' && (
                                                <div style={{ fontSize: 11.5, color: pal.muted, marginTop: 8 }}>
                                                    Заявка на проверке. Дождитесь решения — это требуется для вывода крупных сумм.
                                                </div>
                                            )}
                                        </>
                                    );
                                })()}
                            </Card>
                            {agreement && (
                                <Card size="small" title="Соглашение">
                                    <Flex justify="space-between" align="center">
                                        <span style={{ fontSize: 13 }}>Статус (версия {agreement.version})</span>
                                        {agreement.accepted ? (
                                            <Tag style={{ background: tint('success', isDark).bg, color: tint('success', isDark).color, border: 'none', fontWeight: 600 }}>Принято</Tag>
                                        ) : (
                                            <Button type="primary" size="small" loading={agreeBusy} onClick={onAcceptAgreement}>Принять</Button>
                                        )}
                                    </Flex>
                                </Card>
                            )}
                            {/* C6: совладельцы/наследники — показ гейтится фиче-флагом (deny-by-default) */}
                            {flags?.c6_copartners === true && (
                            <Card
                                size="small"
                                title="Совладельцы / Наследники"
                                extra={<Button size="small" type="link" onClick={openCpCreate}>Добавить</Button>}
                            >
                                <List
                                    dataSource={copartners}
                                    locale={{ emptyText: 'Записей пока нет' }}
                                    renderItem={(c) => (
                                        <List.Item
                                            actions={[
                                                <Button key="edit" size="small" type="link" onClick={() => openCpEdit(c)}>Изм.</Button>,
                                                <Popconfirm key="del" title="Удалить запись?" okText="Да" cancelText="Нет"
                                                    onConfirm={() => onCpDelete(c.id)}>
                                                    <Button size="small" type="link" danger>Удал.</Button>
                                                </Popconfirm>,
                                            ]}
                                        >
                                            <Flex vertical gap={2}>
                                                <span>
                                                    <Tag style={{ marginInlineEnd: 6 }}>
                                                        {c.kind === 'heir' ? 'Наследник' : 'Совладелец'}
                                                    </Tag>
                                                    <b style={{ fontSize: 13.5 }}>{c.full_name}</b>
                                                </span>
                                                <span style={{ fontSize: 11.5, color: pal.muted }}>
                                                    {[c.phone, c.share_percent != null ? `${c.share_percent}%` : null, c.note]
                                                        .filter(Boolean).join(' · ') || '—'}
                                                </span>
                                            </Flex>
                                        </List.Item>
                                    )}
                                />
                                <div style={{ fontSize: 11, color: pal.muted, marginTop: 6 }}>
                                    Справочная информация. Не влияет на начисления, выплаты и структуру.
                                </div>
                            </Card>
                            )}

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

                    {/* Block C: контент вкладок фич блока (по key из registry, гейт по флагам). */}
                    {blockCTabRender(tab, blockCCtx, flags)}

                </div>

                {/* Нижний таб-бар с иконками */}
                <div style={{
                    position: 'fixed', bottom: 0, left: 0, right: 0, height: 62,
                    display: 'flex', borderTop: `1px solid ${pal.border}`,
                    // Aurora: непрозрачный sheet, иначе glassy-surface в dark просвечивает контент под баром.
                    background: pal.sheet, boxShadow: pal.shadow,
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

                {/* Модалка вывода средств (on-chain USDT в сети TON) */}
                <Modal title="Вывод средств" open={wdOpen} onOk={onWithdraw} onCancel={() => setWdOpen(false)}
                    okText="Запросить вывод" confirmLoading={wdSubmitting}>
                    <div style={{ fontSize: 12, color: pal.muted, marginBottom: 8 }}>Доступно: ${wallet?.available ?? '0.00'}</div>
                    <InputNumber style={{ width: '100%', marginBottom: 8 }} min={0.01} step={0.01} precision={2}
                        prefix="$" placeholder="Сумма" value={wdAmount} onChange={setWdAmount} />
                    <Input.TextArea rows={2} maxLength={70} placeholder="TON-адрес для USDT (например EQ…)"
                        value={wdDetails} onChange={(e) => setWdDetails(e.target.value)} />
                    <div style={{ fontSize: 11, color: pal.muted, marginTop: 6 }}>
                        Выплата приходит в USDT на указанный TON-адрес. Проверьте адрес — ошибка необратима.
                    </div>
                </Modal>

                {/* Модалка пополнения внутреннего USDT-баланса (F5) */}
                <Modal title="Пополнение баланса" open={topupOpen} onOk={onTopup} onCancel={() => setTopupOpen(false)}
                    okText="Пополнить" confirmLoading={topupSubmitting}>
                    <div style={{ fontSize: 12, color: pal.muted, marginBottom: 8 }}>
                        Пополнение USDT через TON Pay. Баланс используется для автозаказов и покупок.
                    </div>
                    <InputNumber style={{ width: '100%' }} min={0.01} max={1000000} step={0.01} precision={2}
                        prefix="$" placeholder="Сумма" value={topupAmount} onChange={setTopupAmount} />
                </Modal>

                {/* Checkout пополнения — без заказа (order=null): только зачисление на баланс */}
                <TonPayCheckout open={!!topupInvoice} invoice={topupInvoice} order={null}
                    initData={initData} pal={pal} wa={wa}
                    onClose={() => setTopupInvoice(null)} onPaid={onTopupPaid} />

                {/* C6: форма со-партнёра/наследника (справочная запись профиля) */}
                <Modal
                    title={cpEditId ? 'Изменить запись' : 'Новая запись'}
                    open={cpOpen}
                    onOk={onCpSave}
                    onCancel={() => setCpOpen(false)}
                    okText="Сохранить"
                    confirmLoading={cpSaving}
                >
                    <Segmented
                        block
                        style={{ marginBottom: 12 }}
                        value={cpForm.kind}
                        onChange={(v) => setCpForm((f) => ({ ...f, kind: v }))}
                        options={[
                            { label: 'Совладелец', value: 'copartner' },
                            { label: 'Наследник', value: 'heir' },
                        ]}
                    />
                    <Input
                        style={{ marginBottom: 8 }}
                        placeholder="ФИО"
                        maxLength={160}
                        value={cpForm.full_name}
                        onChange={(e) => setCpForm((f) => ({ ...f, full_name: e.target.value }))}
                    />
                    <Input
                        style={{ marginBottom: 8 }}
                        placeholder="Телефон (необязательно)"
                        maxLength={32}
                        value={cpForm.phone}
                        onChange={(e) => setCpForm((f) => ({ ...f, phone: e.target.value }))}
                    />
                    <InputNumber
                        style={{ width: '100%', marginBottom: 8 }}
                        placeholder="Доля % (необязательно)"
                        min={0}
                        max={100}
                        step={1}
                        value={cpForm.share_percent}
                        onChange={(v) => setCpForm((f) => ({ ...f, share_percent: v }))}
                    />
                    <Input.TextArea
                        rows={2}
                        placeholder="Заметка (необязательно)"
                        maxLength={255}
                        value={cpForm.note}
                        onChange={(e) => setCpForm((f) => ({ ...f, note: e.target.value }))}
                    />
                    <div style={{ fontSize: 11, color: pal.muted, marginTop: 6 }}>
                        Справочные данные. Сумма долей не проверяется и ни на что не влияет.
                    </div>
                </Modal>

                {/* B3: онбординг-гейт — пока соглашение не принято, кабинет заблокирован */}
                <Modal
                    title="Пользовательское соглашение"
                    open={!!agreement && agreement.accepted === false}
                    closable={false}
                    maskClosable={false}
                    keyboard={false}
                    footer={[
                        <Button key="accept" type="primary" loading={agreeBusy} onClick={onAcceptAgreement}>
                            Принимаю
                        </Button>,
                    ]}
                >
                    <div style={{ maxHeight: '50vh', overflowY: 'auto', whiteSpace: 'pre-wrap', fontSize: 13, color: pal.muted }}>
                        {agreement?.text}
                    </div>
                </Modal>
                </>
                ))}
            </div>
        </ConfigProvider>
    );
};

export default MiniAppShell;
