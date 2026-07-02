'use client';
import React, { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal, Button, Flex, Spin, Result, message } from 'antd';
import { CopyOutlined } from '@ant-design/icons';
import { useTonConnectUI, useTonWallet, TonConnectButton } from '@tonconnect/ui-react';
import { numFont, balanceFont } from './tokens';
import { mmCheckPayment } from './api';
import { sendTonPayment, tonConfigured } from './tonPay';
import { usd } from './format';

const POLL_MS = 4000;
const MAX_POLLS = 22; // ~90с авто-поллинга, далее — ручная «Проверить оплату»

/**
 * Checkout TON Pay: подключение кошелька → отправка jetton-перевода → поллинг статуса
 * платежа на бэке (POST /payments/{id}/check). Подтверждение — только серверное (paid),
 * товар/активация выдаются на бэке после on-chain-матча. Реквизиты показываем копируемыми
 * как запасной путь (оплата из любого кошелька).
 */
export default function TonPayCheckout({ open, invoice, order, initData, pal, wa, onClose, onPaid }) {
    const { t } = useTranslation();
    const [tonConnectUI] = useTonConnectUI();
    const wallet = useTonWallet();
    const [phase, setPhase] = useState('idle'); // idle | sending | awaiting | sent | paid | failed
    const [checking, setChecking] = useState(false);
    const pollRef = useRef(null);
    const attemptsRef = useRef(0);
    const pidRef = useRef(null); // актуальный payment_id — для отсева устаревших ответов

    const stopPoll = () => { if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null; } };

    // Новый инвойс / открытие — сбрасываем фазу; на размонтировании глушим поллинг.
    useEffect(() => {
        pidRef.current = invoice?.payment_id ?? null;
        if (open) setPhase('idle');
        return stopPoll;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, invoice?.payment_id]);

    const checkOnce = async (silent) => {
        const pid = invoice?.payment_id;
        if (!pid) return;
        if (!silent) setChecking(true);
        const res = await mmCheckPayment(initData, pid);
        if (!silent) setChecking(false);
        if (pidRef.current !== pid) return; // инвойс сменился, пока ждали ответ — игнорируем
        if (res?.error) { if (!silent) message.error(t('miniapp.pay_err_check')); return; }
        const st = res?.data?.payment_status;
        if (st === 'paid') {
            stopPoll(); setPhase('paid');
            wa?.HapticFeedback?.notificationOccurred?.('success');
            onPaid?.();
        } else if (st === 'failed' || st === 'expired') {
            stopPoll(); setPhase('failed');
        }
    };

    const startAwaiting = () => {
        setPhase('awaiting');
        stopPoll();
        attemptsRef.current = 0;
        pollRef.current = setInterval(async () => {
            attemptsRef.current += 1;
            await checkOnce(true);
            // checkOnce при paid/failed уже заглушил поллинг (pollRef=null) — не перетираем итог.
            if (pollRef.current && attemptsRef.current >= MAX_POLLS) {
                stopPoll();
                // F5 (P1-hardening): перевод УЖЕ отправлен — не возвращаемся в idle с кнопкой
                // «Оплатить кошельком» (повторный клик = второе списание), остаётся только
                // «Проверить оплату» + подсказка.
                setPhase('sent');
                message.info(t('miniapp.pay_info_delayed'));
            }
        }, POLL_MS);
    };

    const onPay = async () => {
        if (!wallet) { message.info(t('miniapp.pay_info_connect_wallet')); return; }
        if (!tonConfigured()) { message.error(t('miniapp.pay_err_not_configured')); return; }
        setPhase('sending');
        try {
            await sendTonPayment(tonConnectUI, invoice);
            startAwaiting();
        } catch (e) {
            setPhase('idle');
            message.error(e?.message || t('miniapp.pay_err_send'));
        }
    };

    const copy = (txt) => navigator.clipboard?.writeText(String(txt ?? '')).then(
        () => message.success(t('miniapp.pay_ok_copied')), () => {});

    const close = () => { stopPoll(); onClose?.(); };

    // Aurora: градиентная сумма к оплате + градиентный CTA «Оплатить».
    const gradBtn = { background: pal.primBg, color: pal.primTxt, border: 'none', boxShadow: pal.primGlow };
    const amtGrad = { ...balanceFont, fontWeight: 800, background: pal.balGrad, WebkitBackgroundClip: 'text', backgroundClip: 'text', color: 'transparent' };

    const Row = ({ label, value, mono }) => (
        <Flex justify="space-between" align="center" gap={8} style={{ padding: '7px 0' }}>
            <span style={{ fontSize: 12, color: pal.muted }}>{label}</span>
            <Flex align="center" gap={6} style={{ minWidth: 0 }}>
                <span style={{ ...(mono ? numFont : {}), fontWeight: 600, fontSize: 13, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: 180 }}>
                    {value}
                </span>
                <CopyOutlined style={{ color: pal.accent, cursor: 'pointer' }} onClick={() => copy(value)} />
            </Flex>
        </Flex>
    );

    let body;
    if (phase === 'paid') {
        body = <Result status="success" title={t('miniapp.pay_paid_title')}
            subTitle={order ? t('miniapp.pay_paid_order_sub') : t('miniapp.pay_paid_topup_sub')}
            extra={<Button type="primary" onClick={close}>{t('miniapp.pay_done')}</Button>} />;
    } else if (phase === 'failed') {
        body = <Result status="error" title={t('miniapp.pay_failed_title')}
            subTitle={t('miniapp.pay_failed_sub')}
            extra={<Button onClick={() => setPhase('idle')}>{t('miniapp.pay_back')}</Button>} />;
    } else {
        body = (
            <>
                <div style={{ textAlign: 'center', margin: '4px 0 12px' }}>
                    <div style={{ fontSize: 11, color: pal.muted, letterSpacing: '.1em', textTransform: 'uppercase' }}>{t('miniapp.pay_to_pay')}</div>
                    <div style={{ ...amtGrad, fontSize: 30, lineHeight: 1.1, marginTop: 4 }}>
                        {usd(invoice?.amount_cents)} <span style={{ fontSize: 16, color: pal.muted, WebkitTextFillColor: pal.muted }}>{invoice?.currency || 'USDT'}</span>
                    </div>
                </div>

                <div style={{ background: pal.surface2, borderRadius: 12, padding: '4px 12px', marginBottom: 12 }}>
                    <Row label={t('miniapp.pay_recipient_address')} value={invoice?.merchant_address} mono />
                    <Row label={t('miniapp.pay_memo_required')} value={invoice?.memo} mono />
                </div>

                {!tonConfigured() && (
                    <div style={{ fontSize: 11.5, color: pal.warning, marginBottom: 10 }}>
                        {t('miniapp.pay_not_configured_hint')}
                    </div>
                )}

                <Flex vertical align="center" gap={10}>
                    <TonConnectButton />
                    {phase === 'awaiting' ? (
                        <Flex vertical align="center" gap={6} style={{ padding: '6px 0' }}>
                            <Spin />
                            <span style={{ fontSize: 12.5, color: pal.muted }}>{t('miniapp.pay_awaiting')}</span>
                        </Flex>
                    ) : phase === 'sent' ? (
                        <span style={{ fontSize: 12.5, color: pal.muted, textAlign: 'center', padding: '6px 0' }}>
                            {t('miniapp.pay_sent_hint')}
                        </span>
                    ) : (
                        <Button type="primary" block loading={phase === 'sending'}
                            style={(!wallet || !tonConfigured()) ? undefined : gradBtn}
                            disabled={!wallet || !tonConfigured()} onClick={onPay}>
                            {t('miniapp.pay_wallet')}
                        </Button>
                    )}
                    <Button block loading={checking} onClick={() => checkOnce(false)}>{t('miniapp.pay_check_payment')}</Button>
                </Flex>
            </>
        );
    }

    return (
        <Modal title={t('miniapp.pay_checkout_title')} open={open} onCancel={close} footer={null} destroyOnClose maskClosable={false}>
            {body}
        </Modal>
    );
}
