'use client';
import { API_SERVER_URL } from '@/common/utils/utils';

// Mini App API: авторизация через заголовок X-Telegram-Init-Data (не web-токен).

export const req = async (path, initData, method = 'GET', body = null) => {
    try {
        const res = await fetch(`${API_SERVER_URL}${path}`, {
            method,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/json;charset=UTF-8',
                'X-Telegram-Init-Data': initData || '',
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        if (!res.ok) return { error: res.status };
        return await res.json();
    } catch (e) {
        return { error: 0 };
    }
};

// C3: активные фиче-флаги кабинета (карта ключ→true; есть только включённые).
// Гейтит ПОКАЗ blockC-фич в Mini App (deny-by-default — сбой/отсутствие = всё скрыто).
export const mmFeatureFlags = (i) => req('/api/v1/cabinet/feature-flags', i);

export const mmMe = (i) => req('/api/v1/cabinet/me', i);
export const mmDashboard = (i) => req('/api/v1/cabinet/dashboard', i);
export const mmRank = (i) => req('/api/v1/cabinet/rank-progress', i);
export const mmTree = (i) => req('/api/v1/cabinet/team-tree', i);
export const mmActivate = (i, packageId) =>
    req('/api/v1/cabinet/activate-package', i, 'POST', { package_id: packageId });

// Кошелёк (Фаза 3): баланс, лента движений, заявки на вывод.
export const mmWallet = (i) => req('/api/v1/cabinet/wallet', i);
export const mmWalletTx = (i) => req('/api/v1/cabinet/wallet/transactions', i);
// A2: выписка за период (from/to — YYYY-MM-DD; пусто = вся история).
export const mmWalletStatement = (i, from = '', to = '') => {
    const p = new URLSearchParams();
    if (from) p.set('from', from);
    if (to) p.set('to', to);
    const q = p.toString();
    return req(`/api/v1/cabinet/wallet/statement${q ? `?${q}` : ''}`, i);
};
// B3: пользовательское соглашение (онбординг-акцепт).
export const mmAgreement = (i) => req('/api/v1/cabinet/agreement', i);
export const mmAgreementAccept = (i) => req('/api/v1/cabinet/agreement/accept', i, 'POST');
export const mmWithdrawals = (i) => req('/api/v1/cabinet/withdrawals', i);
export const mmWithdrawCreate = (i, amount, payoutDetails) =>
    req('/api/v1/cabinet/withdrawals', i, 'POST', { amount, payout_details: payoutDetails });

// Commerce (Фаза 4): каталог, заказы, оплата TON Pay, статус платежа.
export const mmCatalog = (i) => req('/api/v1/cabinet/catalog', i);
export const mmOrders = (i) => req('/api/v1/cabinet/orders', i);
export const mmOrder = (i, id) => req(`/api/v1/cabinet/orders/${id}`, i);
export const mmCreateOrder = (i, productId, qty = 1) =>
    req('/api/v1/cabinet/orders', i, 'POST', { product_id: productId, qty });
export const mmPayOrder = (i, id) => req(`/api/v1/cabinet/orders/${id}/pay`, i, 'POST');
export const mmCheckPayment = (i, id) => req(`/api/v1/cabinet/payments/${id}/check`, i, 'POST');

// Autoship (F4): подписки на автоповтор покупки. interval_days — целое 1..365.
export const mmAutoship = (i) => req('/api/v1/cabinet/autoship', i);
export const mmAutoshipCreate = (i, productId, intervalDays) =>
    req('/api/v1/cabinet/autoship', i, 'POST', { product_id: productId, interval_days: intervalDays });
export const mmAutoshipAction = (i, id, action) =>
    req(`/api/v1/cabinet/autoship/${id}`, i, 'PATCH', { action }); // action: pause|resume|cancel

// Пополнение внутреннего USDT-баланса (F5): тот же checkout-флоу, что и заказ. amount — в центах.
export const mmTopup = (i, amountCents) =>
    req('/api/v1/cabinet/wallet/topup', i, 'POST', { amount_cents: amountCents });

// KYC (F6): статус (none|pending|approved|rejected) + подача документов (Passport-intake).
export const mmKyc = (i) => req('/api/v1/cabinet/kyc', i);
export const mmKycSubmit = (i, documents) =>
    req('/api/v1/cabinet/kyc/passport', i, 'POST', { documents });

export const PACKAGES = [
    { id: 1, name: 'Bronze', pv: 90, price: 100 },
    { id: 2, name: 'Silver', pv: 180, price: 200 },
    { id: 3, name: 'Gold', pv: 540, price: 600 },
];

// >>> Block C notifications
// C1: inbox партнёра (свои уведомления + отметка прочтения).
export const mmNotifications = (i) => req('/api/v1/cabinet/notifications', i);
export const mmNotificationsUnread = (i) => req('/api/v1/cabinet/notifications/unread-count', i);
export const mmNotificationRead = (i, id) => req(`/api/v1/cabinet/notifications/${id}/read`, i, 'POST');
export const mmNotificationReadAll = (i) => req('/api/v1/cabinet/notifications/read-all', i, 'POST');
// <<< Block C notifications

// >>> Block C copartners
// C6: со-партнёры / наследники в профиле (справочные данные). Партнёр CRUD-ит ТОЛЬКО
// свои записи (бэкенд скоупит по текущему участнику). Несколько записей разрешено,
// сумма долей не валидируется. payload: { kind: 'copartner'|'heir', full_name, phone?, share_percent?, note? }.
export const mmCopartners = (i) => req('/api/v1/cabinet/copartners', i);
export const mmCopartnerCreate = (i, payload) => req('/api/v1/cabinet/copartners', i, 'POST', payload);
export const mmCopartnerUpdate = (i, id, payload) => req(`/api/v1/cabinet/copartners/${id}`, i, 'PUT', payload);
export const mmCopartnerDelete = (i, id) => req(`/api/v1/cabinet/copartners/${id}`, i, 'DELETE');
// <<< Block C copartners

// >>> Block C helpdesk
// C2: тикеты поддержки партнёра (cabinet). Бэкенд скоупит по текущему участнику —
// видны/доступны ТОЛЬКО свои тикеты. Чтение треда — polling по since-курсору (5–8с).
export const mmTickets = (i) => req('/api/v1/cabinet/tickets', i);
export const mmTicketCreate = (i, subject, body) =>
    req('/api/v1/cabinet/tickets', i, 'POST', { subject, body });
export const mmTicket = (i, id) => req(`/api/v1/cabinet/tickets/${id}`, i);
export const mmTicketMessage = (i, id, body) =>
    req(`/api/v1/cabinet/tickets/${id}/messages`, i, 'POST', { body });
export const mmTicketPoll = (i, id, since = 0) =>
    req(`/api/v1/cabinet/tickets/${id}/poll?since=${since}`, i);
// <<< Block C helpdesk
