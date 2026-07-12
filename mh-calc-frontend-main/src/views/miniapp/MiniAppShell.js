'use client';
import React, { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    ConfigProvider, Card, Statistic, Button, Tag, List, Spin, Result, Progress,
    Input, InputNumber, Modal, Avatar, Divider, Flex, DatePicker, message,
    Segmented, Popconfirm,
} from 'antd';
import {
    WalletOutlined, TeamOutlined, TrophyOutlined, UserOutlined,
    ExportOutlined, CopyOutlined, ShoppingOutlined, SwapOutlined, ClockCircleOutlined,
} from '@ant-design/icons';
import { Address } from '@ton/core';
import { useTelegram, antdThemeFromTelegram, miniAppPalette } from './telegram';
import { tint, bonusTint, statusTint, roleTint, bonusDot, numFont, balanceFont } from './tokens';
import {
    mmMe, mmDashboard, mmRank, mmTree, mmWallet, mmWalletTx, mmWalletStatement, mmWithdrawals, mmWithdrawCreate,
    mmTopup, mmKyc, mmKycSubmit, mmAgreement, mmAgreementAccept, mmSetLanguage, PACKAGES,
    mmCopartners, mmCopartnerCreate, mmCopartnerUpdate, mmCopartnerDelete,
    mmFeatureFlags, mmPersonalReferrals, mmChangeSponsor,
} from './api';
import MiniAppShop from './MiniAppShop';
import TonPayCheckout from './TonPayCheckout';
import SplashScreen from './SplashScreen';
import { legalText } from './legalTexts';
import { visibleBlockCTabs, blockCTabRender } from './tabs/registry';

// Языки Mini App: RU (основной) + EN. Переключатель — в профиле/настройках. Выбор хранится
// в localStorage (мгновенный кэш) и персистится на бэк (members.language) best-effort.
const MINIAPP_LANGS = ['ru', 'en'];
const LANG_STORAGE_KEY = 'miniapp_lang';
const normalizeLang = (l) => (MINIAPP_LANGS.includes(l) ? l : null);

// Проверка user-friendly TON-адреса через @ton/core (F3, P1-hardening): и формат, и
// CRC16-чексумма, и отклонение testnet-адресов — опечатка ловится ДО создания заявки.
// Бэк валидирует тем же алгоритмом (общие тест-векторы сгенерированы этой же библиотекой).
const isTonAddress = (s) => {
    try {
        return !Address.parseFriendly(String(s || '').trim()).isTestOnly;
    } catch {
        return false;
    }
};

// Значения label — i18n-ключи (переводятся через t() в месте рендера), kind — цветовой тон.
const KYC_STATUS = {
    none: { label: 'miniapp.kyc_none', kind: 'neutral' },
    pending: { label: 'miniapp.kyc_pending', kind: 'amber' },
    approved: { label: 'miniapp.kyc_approved', kind: 'green' },
    rejected: { label: 'miniapp.kyc_rejected', kind: 'amber' },
};

const TYPE_LABEL = { binary: 'miniapp.bonus_binary', referral: 'miniapp.bonus_referral', leader: 'miniapp.bonus_leader', rank: 'miniapp.bonus_rank' };
const TX_SOURCE_LABEL = { accrual: 'miniapp.tx_accrual', withdrawal: 'miniapp.tx_withdrawal' };

// A2: экспорт выписки (массив движений) в CSV-файл, без сторонних либ. tr — функция перевода.
const exportStatementCsv = (items, tr) => {
    const esc = (v) => {
        const s = v == null ? '' : String(v);
        return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    };
    const head = 'date,type,amount_usd,source_id';
    const body = items.map((row) => [
        esc(row.created_at ?? ''),
        esc(TX_SOURCE_LABEL[row.source_type] ? tr(TX_SOURCE_LABEL[row.source_type]) : row.source_type),
        esc(row.amount),
        esc(row.source_id ?? ''),
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
    requested: { label: 'miniapp.wd_requested', kind: 'blue' },
    approved: { label: 'miniapp.wd_approved', kind: 'blue' },
    paid: { label: 'miniapp.wd_paid', kind: 'green' },
    rejected: { label: 'miniapp.wd_rejected', kind: 'amber' },
    cancelled: { label: 'miniapp.wd_cancelled', kind: 'neutral' },
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
const TreeNode = ({ node, depth, isDark, filter, t }) => {
    if (!node) return null;
    const st = node.attributes?.status;
    const visible = filter === 'all' || (filter === 'active' && st === 'active') || (filter === 'new' && st !== 'active');
    const tn = statusTint(st, isDark);
    const children = (node.children || []).filter(Boolean);
    return (
        <div style={depth ? { marginLeft: 15, borderLeft: `1.5px solid var(--tree-border)`, paddingLeft: 13 } : undefined}>
            {(depth === 0 || visible) && (
                <Flex align="center" gap={9} style={{ padding: '7px 0' }}>
                    <Avatar size={28} style={{ background: tn.bg, color: tn.color, fontSize: 11, fontWeight: 700 }}>
                        {initials(node.name)}
                    </Avatar>
                    <span style={{ fontSize: 13.5, fontWeight: depth ? 500 : 700, flex: 1 }}>{node.name}</span>
                    <Tag style={{ background: tn.bg, color: tn.color, border: 'none', fontSize: 10.5, fontWeight: 600, marginInlineEnd: 0 }}>
                        {st === 'active' ? t('miniapp.status_active') : t('miniapp.status_new')}
                    </Tag>
                </Flex>
            )}
            {children.map((c, i) => <TreeNode key={c.attributes?.id ?? c.name ?? i} node={c} depth={depth + 1} isDark={isDark} filter={filter} t={t} />)}
        </div>
    );
};

const MiniAppShell = () => {
    const { t, i18n } = useTranslation();
    const { initData, theme, wa, ready, scheme } = useTelegram();
    const [tab, setTab] = useState('income');
    const [me, setMe] = useState(null);
    const [refLink, setRefLink] = useState('');
    // Язык интерфейса Mini App (RU/EN). Инициализируется из localStorage → Telegram → ru.
    const [lang, setLang] = useState('ru');
    // Юр-документ для модалки настроек: 'privacy' | 'terms' | null.
    const [legalDoc, setLegalDoc] = useState(null);
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

    // Применить язык: i18next + локальный стейт + кэш в localStorage. persist=true ещё и
    // best-effort пишет на бэк (members.language) — нефатально при сбое.
    const applyLang = (l, persist = false) => {
        const next = normalizeLang(l) || 'ru';
        i18n.changeLanguage(next);
        setLang(next);
        if (typeof window !== 'undefined') {
            try { localStorage.setItem(LANG_STORAGE_KEY, next); } catch (e) { /* private mode */ }
        }
        if (persist && initData) { mmSetLanguage(initData, next); }
    };

    // Инициализация языка при первом монтировании: localStorage → Telegram language_code → ru.
    useEffect(() => {
        let stored = null;
        if (typeof window !== 'undefined') {
            try { stored = localStorage.getItem(LANG_STORAGE_KEY); } catch (e) { /* private mode */ }
        }
        const tg = wa?.initDataUnsafe?.user?.language_code;
        const fromTg = tg && String(tg).toLowerCase().startsWith('en') ? 'en' : 'ru';
        applyLang(normalizeLang(stored) || fromTg);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [wa]);

    // Mini App поддерживает только RU/EN. Витринный GlobalContext (родитель) на маунте
    // восстанавливает свой язык (напр. kk) и может перебить наш выбор — возвращаем в RU/EN.
    useEffect(() => {
        if (!MINIAPP_LANGS.includes(i18n.language)) {
            i18n.changeLanguage(lang);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [i18n.language, lang]);

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
        // Персист-язык с бэка применяем ТОЛЬКО если у пользователя нет локального выбора.
        const stored = (typeof window !== 'undefined') ? (() => { try { return localStorage.getItem(LANG_STORAGE_KEY); } catch (e) { return null; } })() : null;
        if (!normalizeLang(stored) && normalizeLang(data.member?.language)) {
            applyLang(data.member.language);
        }
        const [d, r, tr] = await Promise.all([
            mmDashboard(initData), mmRank(initData), mmTree(initData),
        ]);
        const errors = [d, r, tr].map((x) => x?.error).filter((e) => e !== undefined);
        if (errors.includes(401)) { setAuthError(true); setLoading(false); return; }
        if (errors.length) { setServerError(true); setLoading(false); return; }
        setDash(d?.data ?? null);
        setRank(r?.data ?? null);
        setTree(tr?.data ?? null);
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
        if (!amount || amount <= 0) { message.error(t('miniapp.err_enter_amount')); return; }
        if (!isTonAddress(wdDetails)) { message.error(t('miniapp.err_invalid_ton')); return; }
        setWdSubmitting(true);
        const res = await mmWithdrawCreate(initData, amount.toFixed(2), wdDetails.trim());
        setWdSubmitting(false);
        if (res?.error) { message.error(t('miniapp.err_create_request')); return; }
        message.success(t('miniapp.ok_request_created'));
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
        if (res?.error) { message.error(t('miniapp.err_load_statement')); return; }
        setStmtData(res?.data ?? null);
    };

    const openStatement = () => { setStmtData(null); setStmtRange(null); setStmtOpen(true); onLoadStatement(); };

    // B3: принять соглашение (онбординг-гейт). До акцепта кабинет заблокирован.
    const onAcceptAgreement = async () => {
        setAgreeBusy(true);
        const res = await mmAgreementAccept(initData);
        setAgreeBusy(false);
        if (res?.error) { message.error(t('miniapp.err_accept_agreement')); return; }
        setAgreement(res?.data ?? null);
        wa?.HapticFeedback?.notificationOccurred?.('success');
    };

    // F5: создать счёт на пополнение → открыть TON Pay checkout (без заказа, только зачисление).
    const onTopup = async () => {
        const amount = Number(topupAmount);
        if (!amount || amount <= 0) { message.error(t('miniapp.err_enter_topup')); return; }
        setTopupSubmitting(true);
        const res = await mmTopup(initData, Math.round(amount * 100)); // доллары → центы
        setTopupSubmitting(false);
        if (res?.error) { message.error(t('miniapp.err_create_topup')); return; }
        wa?.HapticFeedback?.impactOccurred?.('light');
        setTopupOpen(false);
        setTopupAmount(null);
        setTopupInvoice(res?.data ?? null);
    };

    const onTopupPaid = () => { setTopupInvoice(null); load(); };

    // B-3: перевыпуск инвойса пополнения из failed-фазы чекаута (старый memo мёртв на бэке).
    // Новый счёт на ту же сумму → новый payment_id/memo; смена invoice сбросит фазу в idle.
    const onReissueTopup = async () => {
        const cents = topupInvoice?.amount_cents;
        if (!cents) return false;
        const res = await mmTopup(initData, cents);
        if (res?.error || !res?.data) return false;
        setTopupInvoice(res.data);
        return true;
    };

    // F6: подать на верификацию. Реальный сбор документов через Telegram Passport — Фаза 5
    // (NEEDS-LIVE-VERIFY); здесь intake-заявка переводит KYC в pending для ручного аппрува.
    const onKycSubmit = async () => {
        setKycSubmitting(true);
        const res = await mmKycSubmit(initData, [{ type: 'passport', source: 'mini_app_intake' }]);
        setKycSubmitting(false);
        if (res?.error) { message.error(t('miniapp.err_kyc_submit')); return; }
        message.success(t('miniapp.ok_kyc_submitted'));
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
        if (!cpForm.full_name.trim()) { message.error(t('copartners.nameRequired')); return; }
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
        if (res?.error) { message.error(t('copartners.saveFailed')); return; }
        wa?.HapticFeedback?.notificationOccurred?.('success');
        setCpOpen(false);
        loadCopartners();
    };

    const onCpDelete = async (id) => {
        const res = await mmCopartnerDelete(initData, id);
        if (res?.error) { message.error(t('copartners.deleteFailed')); return; }
        loadCopartners();
    };

    const onCopyRef = () => {
        navigator.clipboard?.writeText(refLink).then(
            () => { message.success(t('miniapp.ok_link_copied')); wa?.HapticFeedback?.notificationOccurred?.('success'); },
            () => message.error(t('miniapp.err_copy')),
        );
    };

    // Лид: сменить спонсора по ref-коду (доступно, пока окно не истекло и пакет не куплен).
    const onChangeSponsor = async () => {
        const code = csRef.trim();
        if (!code) { message.error(t('miniapp.err_enter_ref')); return; }
        setCsBusy(true);
        const res = await mmChangeSponsor(initData, code);
        setCsBusy(false);
        if (res?.error || res?.status === 'error') {
            message.error(t('miniapp.err_change_sponsor'));
            return;
        }
        message.success(t('miniapp.ok_sponsor_updated'));
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
        { key: 'income', label: t('miniapp.tab_income'), icon: <WalletOutlined /> },
        { key: 'shop', label: t('miniapp.tab_shop'), icon: <ShoppingOutlined /> },
        { key: 'team', label: t('miniapp.tab_team'), icon: <TeamOutlined /> },
        { key: 'rank', label: t('miniapp.tab_rank'), icon: <TrophyOutlined /> },
        { key: 'profile', label: t('miniapp.tab_profile'), icon: <UserOutlined /> },
    ];
    // Админка вынесена в веб (admin.izigo.adarasoft.com) — в Mini App её больше нет.
    // T14: при включённом mh_plan_v2_miniapp V1-вкладка «Ранг» скрывается — её заменяет
    // таб «Мой план V2» (иначе двойная правда о ранге на экране до cutover T15). Правится
    // только состав базовых табов, не switch их рендера (registry-контракт неизменен).
    const baseTabs = flags?.mh_plan_v2_miniapp === true ? TABS.filter((tb) => tb.key !== 'rank') : TABS;
    // Block C: вкладки фич подмешиваются из registry, отфильтрованные по фиче-флагам
    // (deny-by-default: пустая карта flags => флаговые вкладки скрыты, базовый таб-бар цел).
    const tabs = [...baseTabs, ...visibleBlockCTabs(flags).map((tb) => ({ ...tb, label: t(tb.label) }))];
    // Block C: контекст шелла для render вкладок фич (чтобы не дублировать загрузку данных).
    const blockCCtx = { initData, pal, isDark, wa, me, dash, rank, tree, wallet, reload: load };

    const stateScreen = loading
        ? <Spin size="large" style={{ display: 'block', margin: '80px auto' }} />
        : authError
            ? <Result status="warning" title={t('miniapp.open_tg_title')}
                subTitle={t('miniapp.open_tg_sub')} />
            : serverError
                ? <Result status="error" title={t('miniapp.load_err_title')}
                    subTitle={t('miniapp.load_err_sub')}
                    extra={<Button type="primary" onClick={() => { setServerError(false); setLoading(true); load(); }}>{t('miniapp.retry')}</Button>} />
                : needReferral
                    ? <Result status="info" title={t('miniapp.need_ref_title')}
                        subTitle={t('miniapp.need_ref_sub')} />
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
                        <div style={{ ...numFont, fontSize: 18, fontWeight: 800 }}>{t('miniapp.welcome')}</div>
                        <div style={{ fontSize: 13.5, color: pal.muted, marginTop: 6 }}>
                            {t('miniapp.lead_activate_hint')}
                        </div>
                        <Divider style={{ margin: '14px 0' }} />
                        <Flex justify="space-between" align="center" gap={8}>
                            <div style={{ minWidth: 0 }}>
                                <div style={{ fontSize: 11.5, color: pal.muted }}>{t('miniapp.your_sponsor')}</div>
                                <div style={{ ...numFont, fontWeight: 700, fontSize: 16 }}>{leadInfo.sponsor?.name ?? '—'}</div>
                                {leadInfo.sponsor?.ref_code && (
                                    <div style={{ fontSize: 11, color: pal.muted }}>{t('miniapp.code_prefix')} {leadInfo.sponsor.ref_code}</div>
                                )}
                            </div>
                            <Button icon={<SwapOutlined />} onClick={() => { setCsRef(''); setCsOpen(true); }}>{t('miniapp.change')}</Button>
                        </Flex>
                        {leadDaysLeft != null && (
                            <div style={{ fontSize: 11.5, color: pal.muted, marginTop: 10 }}>
                                <ClockCircleOutlined /> {t('miniapp.lead_window_hint', { days: leadDaysLeft })}
                            </div>
                        )}
                    </Card>

                    <MiniAppShop initData={initData} pal={pal} isDark={isDark} wa={wa}
                        leadMode onUnauthorized={() => setAuthError(true)} onAfterPaid={load} />

                    <Modal title={t('miniapp.change_sponsor_title')} open={csOpen} onOk={onChangeSponsor} confirmLoading={csBusy}
                        onCancel={() => setCsOpen(false)} okText={t('miniapp.change')}>
                        <div style={{ fontSize: 12, color: pal.muted, marginBottom: 8 }}>
                            {t('miniapp.change_sponsor_hint')}
                        </div>
                        <Input placeholder={t('miniapp.ref_code_placeholder')} value={csRef} maxLength={16}
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
                                <div style={{ fontSize: 11, color: pal.muted, fontWeight: 600, letterSpacing: '.1em', textTransform: 'uppercase' }}>{t('miniapp.total_accrued')}</div>
                                <div style={{ ...balGradStyle, fontWeight: 800, fontSize: 36, lineHeight: 1.05, marginTop: 6 }}>
                                    ${dash?.total ?? '0.00'}
                                </div>
                                <Divider style={{ margin: '14px 0' }} />
                                <Flex justify="space-between" align="center">
                                    <div>
                                        <div style={{ fontSize: 11.5, color: pal.muted }}>{t('miniapp.available_to_withdraw')}</div>
                                        <div style={{ ...balanceFont, fontWeight: 700, fontSize: 20 }}>${wallet?.available ?? '0.00'}</div>
                                        {Number(wallet?.held ?? 0) > 0 && (
                                            <div style={{ fontSize: 11, color: pal.muted }}>{t('miniapp.in_hold')} ${wallet.held}</div>
                                        )}
                                        {Number(wallet?.clawback_debt ?? 0) > 0 && (
                                            <div style={{ fontSize: 11, color: pal.error }}>{t('miniapp.clawback')} ${wallet.clawback_debt}</div>
                                        )}
                                    </div>
                                    <Flex vertical gap={8}>
                                        <Button type="primary" style={Number(wallet?.available ?? 0) <= 0 ? undefined : gradBtnStyle}
                                            disabled={Number(wallet?.available ?? 0) <= 0}
                                            onClick={() => setWdOpen(true)}>{t('miniapp.withdraw')}</Button>
                                        <Button onClick={() => { setTopupAmount(null); setTopupOpen(true); }}>{t('miniapp.topup')}</Button>
                                    </Flex>
                                </Flex>
                            </Card>

                            {/* Бонусы по типам — 2×2 */}
                            <div>
                                <SectionLabel>{t('miniapp.bonuses_by_type')}</SectionLabel>
                                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
                                    {['binary', 'referral', 'leader', 'rank'].map((k) => (
                                        <Card key={k} size="small" styles={{ body: { padding: 12 } }}>
                                            <Flex align="center" gap={7}>
                                                <span style={{ width: 8, height: 8, borderRadius: '50%', background: bonusDot(k, isDark) }} />
                                                <span style={{ fontSize: 12, color: pal.muted }}>{t(TYPE_LABEL[k])}</span>
                                            </Flex>
                                            <div style={{ ...balanceFont, fontWeight: 700, fontSize: 18, marginTop: 4 }}>${byType[k] ?? '0.00'}</div>
                                        </Card>
                                    ))}
                                </div>
                            </div>

                            {/* Пакет — активация ТОЛЬКО через покупку в Магазине (без бесплатной активации) */}
                            <Card size="small" title={t('miniapp.package')}>
                                {me?.package_id ? (
                                    <Flex justify="space-between" align="center" gap={8}>
                                        <span style={{ fontSize: 13.5 }}>
                                            {t('miniapp.current')}: <b>{PACKAGES.find((p) => p.id === me.package_id)?.name ?? `#${me.package_id}`}</b>
                                        </span>
                                        <Button onClick={() => setTab('shop')}>{t('miniapp.change_in_shop')}</Button>
                                    </Flex>
                                ) : (
                                    <Flex justify="space-between" align="center" gap={8}>
                                        <span style={{ fontSize: 13, color: pal.muted }}>{t('miniapp.package_not_activated')}</span>
                                        <Button type="primary" onClick={() => setTab('shop')}>{t('miniapp.buy_in_shop')}</Button>
                                    </Flex>
                                )}
                            </Card>

                            {/* Последние начисления */}
                            <Card size="small" title={t('miniapp.recent_accruals')}>
                                <List
                                    dataSource={dash?.lines ?? []}
                                    locale={{ emptyText: t('miniapp.empty') }}
                                    renderItem={(l) => {
                                        const tt = bonusTint(l.type, isDark);
                                        return (
                                            <List.Item>
                                                <Flex align="center" gap={8}>
                                                    <span style={{ width: 8, height: 8, borderRadius: '50%', background: bonusDot(l.type, isDark) }} />
                                                    <Tag style={{ background: tt.bg, color: tt.color, border: 'none', fontWeight: 600 }}>
                                                        {TYPE_LABEL[l.type] ? t(TYPE_LABEL[l.type]) : l.type}
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
                                <Card size="small" title={t('miniapp.my_requests')}>
                                    <List
                                        dataSource={withdrawals}
                                        renderItem={(w) => {
                                            const s = WD_STATUS[w.status];
                                            const tt = tint(s?.kind ?? 'neutral', isDark);
                                            return (
                                                <List.Item>
                                                    <span>
                                                        <span style={{ ...numFont, fontWeight: 700 }}>${w.amount}</span>{' '}
                                                        <Tag style={{ background: tt.bg, color: tt.color, border: 'none', fontSize: 10.5, fontWeight: 600 }}>{s ? t(s.label) : w.status}</Tag>
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
                                    title={t('miniapp.operations_history')}
                                    extra={<Button size="small" type="link" onClick={openStatement}>{t('miniapp.statement')}</Button>}
                                >
                                    <List
                                        dataSource={walletTx}
                                        renderItem={(tx) => {
                                            const neg = String(tx.amount).startsWith('-');
                                            return (
                                                <List.Item>
                                                    <span style={{ color: pal.muted, fontSize: 12 }}>
                                                        {TX_SOURCE_LABEL[tx.source_type] ? t(TX_SOURCE_LABEL[tx.source_type]) : tx.source_type}
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
                                title={t('miniapp.statement_period_title')}
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
                                        {t('miniapp.show')}
                                    </Button>
                                    <Button
                                        size="small"
                                        icon={<ExportOutlined />}
                                        disabled={!stmtData?.items?.length}
                                        onClick={() => exportStatementCsv(stmtData.items, t)}
                                    >
                                        CSV
                                    </Button>
                                </Flex>

                                {stmtData?.summary && (
                                    <Flex justify="space-between" style={{ marginBottom: 12 }}>
                                        <Statistic title={t('miniapp.received')} value={`$${(stmtData.summary.credited_cents / 100).toFixed(2)}`}
                                            valueStyle={{ fontSize: 16, color: pal.success }} />
                                        <Statistic title={t('miniapp.debited')} value={`$${(stmtData.summary.debited_cents / 100).toFixed(2)}`}
                                            valueStyle={{ fontSize: 16, color: pal.muted }} />
                                        <Statistic title={t('miniapp.total_net')} value={`$${(stmtData.summary.net_cents / 100).toFixed(2)}`}
                                            valueStyle={{ fontSize: 16 }} />
                                    </Flex>
                                )}

                                <List
                                    size="small"
                                    loading={stmtLoading}
                                    locale={{ emptyText: t('miniapp.no_movements_period') }}
                                    dataSource={stmtData?.items ?? []}
                                    renderItem={(tx) => {
                                        const neg = String(tx.amount).startsWith('-');
                                        return (
                                            <List.Item>
                                                <span style={{ color: pal.muted, fontSize: 12 }}>
                                                    {TX_SOURCE_LABEL[tx.source_type] ? t(TX_SOURCE_LABEL[tx.source_type]) : tx.source_type}
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
                                    <Statistic title={t('miniapp.in_team')} value={teamCount.total} />
                                    <Divider type="vertical" style={{ height: 36 }} />
                                    <Statistic title={t('miniapp.active')} value={teamCount.active} valueStyle={{ color: pal.success }} />
                                    <Divider type="vertical" style={{ height: 36 }} />
                                    <Statistic title={t('miniapp.personal')} value={personalCount} />
                                </Flex>
                            </Card>
                            <div style={{ display: 'flex', gap: 8 }}>
                                {[['all', t('miniapp.filter_all')], ['active', t('miniapp.filter_active')], ['new', t('miniapp.filter_new')]].map(([k, lbl]) => (
                                    <Button key={k} size="small" type={teamFilter === k ? 'primary' : 'default'}
                                        onClick={() => setTeamFilter(k)} style={{ flex: 1 }}>{lbl}</Button>
                                ))}
                            </div>
                            <Card size="small" title={t('miniapp.binary_team')}>
                                <div style={{ fontSize: 11.5, color: pal.muted, marginBottom: 8 }}>
                                    {t('miniapp.binary_team_hint')}
                                </div>
                                {tree?.name ? <TreeNode node={tree} depth={0} isDark={isDark} filter={teamFilter} t={t} /> : t('miniapp.team_empty')}
                            </Card>

                            <Card size="small" title={t('miniapp.personal_referrals')}>
                                <div style={{ fontSize: 11.5, color: pal.muted, marginBottom: 8 }}>
                                    {t('miniapp.personal_referrals_hint')}
                                </div>
                                <List
                                    dataSource={personalReferrals}
                                    locale={{ emptyText: t('miniapp.no_personal_referrals') }}
                                    renderItem={(p) => {
                                        const tt = statusTint(p.status, isDark);
                                        return (
                                            <List.Item>
                                                <Flex align="center" gap={9} style={{ flex: 1, minWidth: 0 }}>
                                                    <Avatar size={28} style={{ background: tt.bg, color: tt.color, fontSize: 11, fontWeight: 700 }}>
                                                        {initials(p.name)}
                                                    </Avatar>
                                                    <span style={{ fontSize: 13.5, fontWeight: 600, flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{p.name}</span>
                                                </Flex>
                                                <Flex gap={6} align="center">
                                                    {p.depth_from_me != null && (
                                                        <Tag style={{ background: tint('blue', isDark).bg, color: tint('blue', isDark).color, border: 'none', fontSize: 10.5 }}>
                                                            {t('miniapp.depth_prefix')} {p.depth_from_me}
                                                        </Tag>
                                                    )}
                                                    <Tag style={{ background: tt.bg, color: tt.color, border: 'none', fontSize: 10.5, fontWeight: 600, marginInlineEnd: 0 }}>
                                                        {p.status === 'active' ? t('miniapp.status_active') : t('miniapp.status_new')}
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
                                            <div style={{ fontSize: 11.5, color: pal.muted }}>{t('miniapp.current_rank')}</div>
                                            <div style={{ ...numFont, fontWeight: 800, fontSize: 22 }}>{rank?.current?.alias ?? t('miniapp.rank_none')}</div>
                                        </div>
                                    </Flex>
                                    <div style={{ textAlign: 'right' }}>
                                        <div style={{ fontSize: 11.5, color: pal.muted }}>{t('miniapp.next')}</div>
                                        <div style={{ fontWeight: 700, color: pal.accent }}>{rank?.next?.alias ?? t('miniapp.rank_max')} ↗</div>
                                    </div>
                                </Flex>
                            </Card>
                            {rank?.next && (
                                <>
                                    <Card size="small" title={t('miniapp.small_branch_pv')}>
                                        <Flex justify="space-between" style={{ ...balanceFont, fontWeight: 700, marginBottom: 6 }}>
                                            <span>{rank.progress?.small_branch_pv ?? 0}</span>
                                            <span style={{ color: pal.muted }}>/ {rank.next.conditions.small_branch_pv}</span>
                                        </Flex>
                                        <Progress showInfo={false}
                                            percent={Math.min(100, Math.round(((rank.progress?.small_branch_pv ?? 0) / (rank.next.conditions.small_branch_pv || 1)) * 100))}
                                            strokeColor={progGrad} trailColor={pal.ghostBg} />
                                    </Card>
                                    <Card size="small" title={t('miniapp.invited')}>
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
                                            {t('miniapp.rank_opens', { rank: rank.next.alias })}
                                        </div>
                                        <div style={{ fontSize: 12.5, color: pal.muted }}>{t('miniapp.rank_perk_leader')}</div>
                                        <div style={{ fontSize: 12.5, color: pal.muted }}>{t('miniapp.rank_perk_quals')}</div>
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
                                            ● {me?.status === 'active' ? t('miniapp.status_active_cap') : t('miniapp.status_new_cap')}
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
                                    <Statistic title={t('miniapp.earned')} value={`$${dash?.total ?? '0.00'}`} />
                                    <Statistic title={t('miniapp.invited_stat')} value={personalCount} />
                                    <Statistic title={t('miniapp.in_team')} value={teamCount.total} />
                                    <Statistic title={t('miniapp.id')} value={me?.id ?? '—'} formatter={(v) => `#${v}`} />
                                </Flex>
                            </Card>
                            <Card size="small" title={t('miniapp.referral_link')}>
                                <div style={{ ...numFont, fontWeight: 800, fontSize: 18, marginBottom: 8 }}>{me?.ref_code ?? '—'}</div>
                                <Input readOnly value={refLink} style={{ marginBottom: 8 }} />
                                <Flex gap={8}>
                                    <Button type="primary" icon={<CopyOutlined />} onClick={onCopyRef} style={{ flex: 1 }}>{t('miniapp.copy_link')}</Button>
                                    {wa?.openTelegramLink && (
                                        <Button icon={<ExportOutlined />} onClick={() => wa.openTelegramLink(`https://t.me/share/url?url=${encodeURIComponent(refLink)}`)} />
                                    )}
                                </Flex>
                            </Card>
                            <Card size="small" title={t('miniapp.kyc_title')}>
                                {(() => {
                                    const s = kyc?.status ?? 'none';
                                    const k = KYC_STATUS[s] ?? KYC_STATUS.none;
                                    const tt = tint(k.kind, isDark);
                                    return (
                                        <>
                                            <Flex justify="space-between" align="center">
                                                <span style={{ fontSize: 13 }}>{t('miniapp.status')}</span>
                                                <Tag style={{ background: tt.bg, color: tt.color, border: 'none', fontWeight: 600 }}>{t(k.label)}</Tag>
                                            </Flex>
                                            {s === 'rejected' && kyc?.reject_reason && (
                                                <div style={{ fontSize: 11.5, color: pal.error, marginTop: 6 }}>{t('miniapp.kyc_reason')} {kyc.reject_reason}</div>
                                            )}
                                            {(s === 'none' || s === 'rejected') && (
                                                <Button type="primary" block style={{ marginTop: 10 }} loading={kycSubmitting}
                                                    onClick={onKycSubmit}>{t('miniapp.pass_verification')}</Button>
                                            )}
                                            {s === 'pending' && (
                                                <div style={{ fontSize: 11.5, color: pal.muted, marginTop: 8 }}>
                                                    {t('miniapp.kyc_pending_hint')}
                                                </div>
                                            )}
                                        </>
                                    );
                                })()}
                            </Card>
                            {agreement && (
                                <Card size="small" title={t('miniapp.agreement')}>
                                    <Flex justify="space-between" align="center">
                                        <span style={{ fontSize: 13 }}>{t('miniapp.agreement_status_ver', { v: agreement.version })}</span>
                                        {agreement.accepted ? (
                                            <Tag style={{ background: tint('success', isDark).bg, color: tint('success', isDark).color, border: 'none', fontWeight: 600 }}>{t('miniapp.accepted')}</Tag>
                                        ) : (
                                            <Button type="primary" size="small" loading={agreeBusy} onClick={onAcceptAgreement}>{t('miniapp.accept')}</Button>
                                        )}
                                    </Flex>
                                </Card>
                            )}
                            {/* C6: совладельцы/наследники — показ гейтится фиче-флагом (deny-by-default) */}
                            {flags?.c6_copartners === true && (
                            <Card
                                size="small"
                                title={t('copartners.title')}
                                extra={<Button size="small" type="link" onClick={openCpCreate}>{t('copartners.add')}</Button>}
                            >
                                <List
                                    dataSource={copartners}
                                    locale={{ emptyText: t('copartners.empty') }}
                                    renderItem={(c) => (
                                        <List.Item
                                            actions={[
                                                <Button key="edit" size="small" type="link" onClick={() => openCpEdit(c)}>{t('copartners.edit')}</Button>,
                                                <Popconfirm key="del" title={t('copartners.deleteConfirm')} okText={t('copartners.yes')} cancelText={t('copartners.no')}
                                                    onConfirm={() => onCpDelete(c.id)}>
                                                    <Button size="small" type="link" danger>{t('copartners.delete')}</Button>
                                                </Popconfirm>,
                                            ]}
                                        >
                                            <Flex vertical gap={2}>
                                                <span>
                                                    <Tag style={{ marginInlineEnd: 6 }}>
                                                        {c.kind === 'heir' ? t('copartners.kindHeir') : t('copartners.kindCopartner')}
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
                                    {t('copartners.infoHint')}
                                </div>
                            </Card>
                            )}

                            <Card size="small" title={t('miniapp.settings')}>
                                <Flex justify="space-between" align="center" style={{ padding: '4px 0 10px' }}>
                                    <span style={{ fontSize: 13.5 }}>{t('miniapp.language')}</span>
                                    <Segmented
                                        size="small"
                                        value={lang}
                                        onChange={(v) => { applyLang(v, true); message.success(i18n.t('miniapp.lang_changed', { lng: v })); }}
                                        options={[{ label: 'RU', value: 'ru' }, { label: 'EN', value: 'en' }]}
                                    />
                                </Flex>
                                <List>
                                    <List.Item style={{ cursor: 'pointer' }} onClick={() => setLegalDoc('privacy')}>
                                        {t('miniapp.privacy_policy')} <span style={{ color: pal.muted }}>›</span>
                                    </List.Item>
                                    <List.Item style={{ cursor: 'pointer' }} onClick={() => setLegalDoc('terms')}>
                                        {t('miniapp.terms_of_use')} <span style={{ color: pal.muted }}>›</span>
                                    </List.Item>
                                    <List.Item>
                                        <span style={{ color: pal.error, cursor: 'pointer' }} onClick={() => wa?.close?.()}>{t('miniapp.close')}</span>
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
                    {tabs.map((tb) => {
                        const on = tab === tb.key;
                        return (
                            <button key={tb.key} onClick={() => setTab(tb.key)}
                                style={{
                                    flex: 1, border: 'none', background: 'transparent', cursor: 'pointer',
                                    display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 3,
                                    fontSize: 10.5, fontWeight: on ? 700 : 500,
                                    color: on ? pal.accent : pal.tabInactive,
                                }}>
                                <span style={{ fontSize: 18, color: on ? pal.accent : pal.tabInactive }}>{tb.icon}</span>
                                {tb.label}
                            </button>
                        );
                    })}
                </div>

                {/* Модалка вывода средств (on-chain USDT в сети TON) */}
                <Modal title={t('miniapp.withdraw_funds')} open={wdOpen} onOk={onWithdraw} onCancel={() => setWdOpen(false)}
                    okText={t('miniapp.request_withdraw')} confirmLoading={wdSubmitting}>
                    <div style={{ fontSize: 12, color: pal.muted, marginBottom: 8 }}>{t('miniapp.available')}: ${wallet?.available ?? '0.00'}</div>
                    <InputNumber style={{ width: '100%', marginBottom: 8 }} min={0.01} step={0.01} precision={2}
                        prefix="$" placeholder={t('miniapp.amount')} value={wdAmount} onChange={setWdAmount} />
                    <Input.TextArea rows={2} maxLength={70} placeholder={t('miniapp.ton_address_placeholder')}
                        value={wdDetails} onChange={(e) => setWdDetails(e.target.value)} />
                    <div style={{ fontSize: 11, color: pal.muted, marginTop: 6 }}>
                        {t('miniapp.withdraw_hint')}
                    </div>
                </Modal>

                {/* Модалка пополнения внутреннего USDT-баланса (F5) */}
                <Modal title={t('miniapp.topup_title')} open={topupOpen} onOk={onTopup} onCancel={() => setTopupOpen(false)}
                    okText={t('miniapp.topup')} confirmLoading={topupSubmitting}>
                    <div style={{ fontSize: 12, color: pal.muted, marginBottom: 8 }}>
                        {t('miniapp.topup_hint')}
                    </div>
                    <InputNumber style={{ width: '100%' }} min={0.01} max={1000000} step={0.01} precision={2}
                        prefix="$" placeholder={t('miniapp.amount')} value={topupAmount} onChange={setTopupAmount} />
                </Modal>

                {/* Checkout пополнения — без заказа (order=null): только зачисление на баланс */}
                <TonPayCheckout open={!!topupInvoice} invoice={topupInvoice} order={null}
                    initData={initData} pal={pal} wa={wa}
                    onClose={() => setTopupInvoice(null)} onPaid={onTopupPaid} onReissue={onReissueTopup} />

                {/* C6: форма со-партнёра/наследника (справочная запись профиля) */}
                <Modal
                    title={cpEditId ? t('copartners.editTitle') : t('copartners.newTitle')}
                    open={cpOpen}
                    onOk={onCpSave}
                    onCancel={() => setCpOpen(false)}
                    okText={t('copartners.save')}
                    confirmLoading={cpSaving}
                >
                    <Segmented
                        block
                        style={{ marginBottom: 12 }}
                        value={cpForm.kind}
                        onChange={(v) => setCpForm((f) => ({ ...f, kind: v }))}
                        options={[
                            { label: t('copartners.kindCopartner'), value: 'copartner' },
                            { label: t('copartners.kindHeir'), value: 'heir' },
                        ]}
                    />
                    <Input
                        style={{ marginBottom: 8 }}
                        placeholder={t('copartners.fullName')}
                        maxLength={160}
                        value={cpForm.full_name}
                        onChange={(e) => setCpForm((f) => ({ ...f, full_name: e.target.value }))}
                    />
                    <Input
                        style={{ marginBottom: 8 }}
                        placeholder={t('copartners.phone')}
                        maxLength={32}
                        value={cpForm.phone}
                        onChange={(e) => setCpForm((f) => ({ ...f, phone: e.target.value }))}
                    />
                    <InputNumber
                        style={{ width: '100%', marginBottom: 8 }}
                        placeholder={t('copartners.sharePercent')}
                        min={0}
                        max={100}
                        step={1}
                        value={cpForm.share_percent}
                        onChange={(v) => setCpForm((f) => ({ ...f, share_percent: v }))}
                    />
                    <Input.TextArea
                        rows={2}
                        placeholder={t('copartners.note')}
                        maxLength={255}
                        value={cpForm.note}
                        onChange={(e) => setCpForm((f) => ({ ...f, note: e.target.value }))}
                    />
                    <div style={{ fontSize: 11, color: pal.muted, marginTop: 6 }}>
                        {t('copartners.shareHint')}
                    </div>
                </Modal>

                {/* Настройки: read-only юр-документы (Политика конфиденциальности / Условия использования) */}
                <Modal
                    title={legalDoc === 'terms' ? t('miniapp.terms_of_use') : t('miniapp.privacy_policy')}
                    open={!!legalDoc}
                    onCancel={() => setLegalDoc(null)}
                    footer={[<Button key="close" onClick={() => setLegalDoc(null)}>{t('miniapp.close')}</Button>]}
                >
                    <div style={{ maxHeight: '60vh', overflowY: 'auto', whiteSpace: 'pre-wrap', fontSize: 12.5, lineHeight: 1.6, color: pal.muted }}>
                        {legalDoc ? legalText(legalDoc, lang) : ''}
                    </div>
                </Modal>

                {/* B3: онбординг-гейт — пока соглашение не принято, кабинет заблокирован */}
                <Modal
                    title={t('miniapp.agreement_modal_title')}
                    open={!!agreement && agreement.accepted === false}
                    closable={false}
                    maskClosable={false}
                    keyboard={false}
                    footer={[
                        <Button key="accept" type="primary" loading={agreeBusy} onClick={onAcceptAgreement}>
                            {t('miniapp.i_accept')}
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
