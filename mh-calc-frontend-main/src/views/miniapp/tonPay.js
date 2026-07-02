'use client';
// Единственное место сборки и отправки TON-платежа (jetton-transfer USDT) через TonConnect.
// Изолировано намеренно — это самая хрупкая часть фронта. Боевые числовые параметры
// (forward_ton_amount, газ, decimals) помечены NEEDS-LIVE-VERIFY: сквозную оплату на проде
// нельзя проверить, пока бэкенд `TonPayGateway::amountMatches` — заглушка. Тест — против
// FakeTonPayGateway (см. plan.md, раздел K).
import { TonClient } from '@ton/ton';
import { Address, beginCell, toNano } from '@ton/core';
import { CHAIN } from '@tonconnect/ui-react';
import i18n from '@/common/i18n';

const JETTON_TRANSFER_OP = 0x0f8a7ea5; // TEP-74 transfer
const USDT_DECIMALS = 6; // USDT-джеттон в сети TON

// Публичная конфигурация (НЕ секреты): RPC keyless/прокси, мастер-джеттон, сеть.
export function tonConfig() {
    return {
        rpc: process.env.NEXT_PUBLIC_TON_RPC || '',
        jettonMaster: process.env.NEXT_PUBLIC_USDT_JETTON_MASTER || '',
        network: process.env.NEXT_PUBLIC_TON_NETWORK === 'testnet' ? 'testnet' : 'mainnet',
    };
}

// Готов ли фронт строить реальный jetton-transfer (есть RPC + мастер-адрес).
export function tonConfigured() {
    const c = tonConfig();
    return Boolean(c.rpc && c.jettonMaster);
}

export function tonChain() {
    return tonConfig().network === 'testnet' ? CHAIN.TESTNET : CHAIN.MAINNET;
}

// USDT-центы бэкенда (100 = 1 USDT) → минимальные единицы джеттона (decimals=6).
function usdtUnits(amountCents) {
    return BigInt(amountCents) * 10n ** BigInt(USDT_DECIMALS - 2);
}

// Текстовый комментарий (memo = pay:{id}) в формате TEP («snake», 32-битный нулевой опкод).
function commentCell(text) {
    return beginCell().storeUint(0, 32).storeStringTail(text).endCell();
}

// jetton-кошелёк отправителя для нашего USDT-мастера (get_wallet_address(owner)).
async function resolveSenderJettonWallet(client, jettonMaster, ownerAddress) {
    const res = await client.runMethod(Address.parse(jettonMaster), 'get_wallet_address', [
        { type: 'slice', cell: beginCell().storeAddress(Address.parse(ownerAddress)).endCell() },
    ]);
    return res.stack.readAddress();
}

// Тело jetton-transfer (TEP-74): получатель = merchant, forward_payload = memo (доходит до
// merchant вместе с notification, по нему бэкенд матчит платёж). NEEDS-LIVE-VERIFY: размеры
// forward_ton_amount/газа под боевой кошелёк/сеть.
function buildTransferBody(invoice, ownerAddress) {
    const forwardTon = toNano('0.02'); // на доставку notification с memo — выверить вживую
    return beginCell()
        .storeUint(JETTON_TRANSFER_OP, 32)
        .storeUint(0, 64) // query_id
        .storeCoins(usdtUnits(invoice.amount_cents))
        .storeAddress(Address.parse(invoice.merchant_address))
        .storeAddress(Address.parse(ownerAddress)) // response_destination — сдача отправителю
        .storeBit(0) // custom_payload отсутствует
        .storeCoins(forwardTon)
        .storeBit(1) // forward_payload вынесен в ref
        .storeRef(commentCell(invoice.memo))
        .endCell();
}

/**
 * Отправить платёж по инвойсу через подключённый кошелёк TonConnect.
 * Бросает Error с понятным сообщением — вызывающий ловит и показывает пользователю.
 */
export async function sendTonPayment(tonConnectUI, invoice) {
    const owner = tonConnectUI?.account?.address;
    if (!owner) throw new Error(i18n.t('miniapp.pay_err_wallet_not_connected'));
    const c = tonConfig();
    if (!tonConfigured()) throw new Error(i18n.t('miniapp.pay_err_ton_params'));
    if (!invoice?.merchant_address || !invoice?.memo) throw new Error(i18n.t('miniapp.pay_err_invalid_invoice'));

    const client = new TonClient({ endpoint: c.rpc });
    const jettonWallet = await resolveSenderJettonWallet(client, c.jettonMaster, owner);
    const body = buildTransferBody(invoice, owner);

    // С этого момента открывается кошелёк и возможна ПОДПИСЬ + широковещание перевода.
    // Если тут произойдёт ошибка/разрыв связи — перевод мог уже уйти в сеть. Помечаем ошибку
    // флагом `broadcastAttempted`, чтобы вызывающий НЕ показал повторную кнопку «Оплатить»
    // (иначе — риск двойного списания). Ошибки на этапе подготовки выше флага не несут.
    try {
        return await tonConnectUI.sendTransaction({
            validUntil: Math.floor(Date.now() / 1000) + 600,
            network: tonChain(),
            messages: [
                {
                    address: jettonWallet.toString(),
                    amount: toNano('0.1').toString(), // газ + forward; NEEDS-LIVE-VERIFY
                    payload: body.toBoc().toString('base64'),
                },
            ],
        });
    } catch (e) {
        if (e && typeof e === 'object') {
            try { e.broadcastAttempted = true; } catch { /* frozen error object — не критично */ }
        }
        throw e;
    }
}
