'use client';
import { API_SERVER_URL } from '@/common/utils/utils';

// Web-админ API (admin.izigo.adarasoft.com): авторизация Bearer Sanctum-токеном
// (выдан после входа через Telegram Login Widget). Интерфейс функций совпадает с
// initDataApi, поэтому переиспользуемые вьюхи (MembersList/MemberCard/AdminWithdrawals)
// работают с api={webApi}, creds={token}. Контракт ответов как у initDataApi:
// req() → { status, data } бэкенда либо { error: <httpStatus> } при !ok.

const TOKEN_KEY = 'izigo_admin_token';
const ROLES_KEY = 'izigo_admin_roles';

export const getToken = () =>
    (typeof window !== 'undefined' ? window.localStorage.getItem(TOKEN_KEY) : null) || '';
export const setToken = (t) => window.localStorage.setItem(TOKEN_KEY, t);
export const clearToken = () => {
    window.localStorage.removeItem(TOKEN_KEY);
    window.localStorage.removeItem(ROLES_KEY);
};

export const setRoles = (roles) => window.localStorage.setItem(ROLES_KEY, JSON.stringify(roles ?? []));
export const getRoles = () => {
    if (typeof window === 'undefined') return [];
    try {
        return JSON.parse(window.localStorage.getItem(ROLES_KEY) || '[]');
    } catch (e) {
        return [];
    }
};

// req(path, token, method, body): token — Bearer (первый «creds» аргумент, как initData).
export const req = async (path, token, method = 'GET', body = null) => {
    try {
        const res = await fetch(`${API_SERVER_URL}${path}`, {
            method,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/json;charset=UTF-8',
                Authorization: `Bearer ${token || getToken()}`,
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        if (!res.ok) return { error: res.status };
        return await res.json();
    } catch (e) {
        return { error: 0 };
    }
};

const mutate = async (token, path, method, data) => {
    const res = await req(path, token, method, data);
    if (res?.error) throw { status: res.error };
    return res?.data ?? res;
};

const qs = (params = {}) => {
    const s = new URLSearchParams(
        Object.entries(params).filter(([, v]) => v !== '' && v != null),
    ).toString();
    return s ? `?${s}` : '';
};

// --- Участники + роли ---
export const fetchMembers = (token, params = {}) => req(`/api/v1/admin/members${qs(params)}`, token);
export const fetchMember = (token, id) => req(`/api/v1/admin/members/${id}`, token);
export const assignRole = (token, memberId, role, leaderScopeMemberId = null) =>
    mutate(token, `/api/v1/admin/members/${memberId}/role`, 'POST', {
        role,
        leader_scope_member_id: leaderScopeMemberId,
    });
export const revokeRole = (token, memberId, role) =>
    mutate(token, `/api/v1/admin/members/${memberId}/role`, 'DELETE', { role });

// --- Маркетинг-план (полный документ) ---
export const fetchPlan = (token) => req('/api/v1/admin/plan', token);
export const updatePlan = (token, doc) => mutate(token, '/api/v1/admin/plan', 'PUT', doc);
// Узкие настройки (placement_mode) — legacy, оставлены для совместимости вьюх.
export const fetchPlanSettings = (token) => req('/api/v1/admin/plan-settings', token);
export const updatePlanSettings = (token, data) => mutate(token, '/api/v1/admin/plan-settings', 'PUT', data);

// --- Заявки на вывод ---
export const fetchWithdrawals = (token, status = '') =>
    req(`/api/v1/admin/withdrawals${status ? `?status=${status}` : ''}`, token);
export const approveWithdrawal = (token, id) => mutate(token, `/api/v1/admin/withdrawals/${id}/approve`, 'POST', {});
export const rejectWithdrawal = (token, id, reason) =>
    mutate(token, `/api/v1/admin/withdrawals/${id}/reject`, 'POST', { reason });
export const markPaidWithdrawal = (token, id) => mutate(token, `/api/v1/admin/withdrawals/${id}/mark-paid`, 'POST', {});
export const sendWithdrawal = (token, id) => mutate(token, `/api/v1/admin/withdrawals/${id}/send`, 'POST', {});
export const cancelWithdrawal = (token, id) => mutate(token, `/api/v1/admin/withdrawals/${id}/cancel`, 'POST', {});

// --- Дашборд / Финансы / Операции (read) ---
export const fetchDashboard = (token) => req('/api/v1/admin/dashboard', token);
export const fetchLedger = (token, params = {}) => req(`/api/v1/admin/ledger${qs(params)}`, token);
export const fetchMemberWallet = (token, id) => req(`/api/v1/admin/members/${id}/wallet`, token);
export const fetchPayments = (token, params = {}) => req(`/api/v1/admin/payments${qs(params)}`, token);
export const fetchAutoship = (token, params = {}) => req(`/api/v1/admin/autoship${qs(params)}`, token);
export const fetchAuditLog = (token, params = {}) => req(`/api/v1/admin/audit-log${qs(params)}`, token);

// --- Продукты ---
export const fetchProducts = (token) => req('/api/v1/admin/products', token);
export const createProduct = (token, data) => mutate(token, '/api/v1/admin/products', 'POST', data);
export const updateProduct = (token, id, data) => mutate(token, `/api/v1/admin/products/${id}`, 'PUT', data);
export const deleteProduct = (token, id) => mutate(token, `/api/v1/admin/products/${id}`, 'DELETE');

// --- Заказы ---
export const fetchOrders = (token, status = '') =>
    req(`/api/v1/admin/orders${status ? `?status=${status}` : ''}`, token);
export const updateOrderStatus = (token, id, status, trackingNo = null) =>
    mutate(token, `/api/v1/admin/orders/${id}/status`, 'PATCH', { status, tracking_no: trackingNo });

// --- KYC ---
export const fetchKyc = (token, status = '') =>
    req(`/api/v1/admin/kyc${status ? `?status=${status}` : ''}`, token);
export const reviewKyc = (token, id, approve, reason = null) =>
    mutate(token, `/api/v1/admin/kyc/${id}`, 'PATCH', { approve, reason });

export const ROLES = [
    { value: 'owner', label: 'Владелец' },
    { value: 'finance', label: 'Финансы' },
    { value: 'leader', label: 'Лидер' },
    { value: 'support', label: 'Саппорт' },
];

export const isForbidden = (res) => res?.error === 403 || res?.status === 403;
export const isUnauthorized = (res) => res?.error === 401 || res?.status === 401;
