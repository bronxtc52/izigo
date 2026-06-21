'use client';
import { req } from '@/views/miniapp/api';

// Админ-API для Telegram Mini App: авторизация через заголовок X-Telegram-Init-Data
// (не web-токен). Образ — src/views/admin/api.js, но первым аргументом initData.
// Доступ ограничен ролями на backend (403); невалидный initData → 401.
//
// Контракт ответов: req() возвращает либо JSON бэкенда ({ status, data }), либо
// { error: <httpStatus> } при !ok. Вьюхи проверяют isForbidden/isUnauthorized.

export const fetchMembers = (initData, params = {}) => {
    const qs = new URLSearchParams(
        Object.entries(params).filter(([, v]) => v !== '' && v != null),
    ).toString();
    return req(`/api/v1/admin/members${qs ? `?${qs}` : ''}`, initData);
};

export const fetchMember = (initData, id) => req(`/api/v1/admin/members/${id}`, initData);

export const fetchPlanSettings = (initData) => req('/api/v1/admin/plan-settings', initData);

// POST/PUT/DELETE: при ошибке бросаем { status } — чтобы вьюхи отрабатывали 403 в catch
// так же, как с token-API (sender).
const mutate = async (initData, path, method, data) => {
    const res = await req(path, initData, method, data);
    if (res?.error) throw { status: res.error };
    return res?.data ?? res;
};

export const assignRole = (initData, memberId, role, leaderScopeMemberId = null) =>
    mutate(initData, `/api/v1/admin/members/${memberId}/role`, 'POST', {
        role,
        leader_scope_member_id: leaderScopeMemberId,
    });

export const revokeRole = (initData, memberId, role) =>
    mutate(initData, `/api/v1/admin/members/${memberId}/role`, 'DELETE', { role });

export const updatePlanSettings = (initData, data) =>
    mutate(initData, '/api/v1/admin/plan-settings', 'PUT', data);

// Заявки на вывод (Фаза 3): очередь + статус-машина (только owner/finance).
export const fetchWithdrawals = (initData, status = '') =>
    req(`/api/v1/admin/withdrawals${status ? `?status=${status}` : ''}`, initData);

export const approveWithdrawal = (initData, id) =>
    mutate(initData, `/api/v1/admin/withdrawals/${id}/approve`, 'POST', {});

export const rejectWithdrawal = (initData, id, reason) =>
    mutate(initData, `/api/v1/admin/withdrawals/${id}/reject`, 'POST', { reason });

export const markPaidWithdrawal = (initData, id) =>
    mutate(initData, `/api/v1/admin/withdrawals/${id}/mark-paid`, 'POST', {});

export const cancelWithdrawal = (initData, id) =>
    mutate(initData, `/api/v1/admin/withdrawals/${id}/cancel`, 'POST', {});

export const ROLES = [
    { value: 'owner', label: 'Владелец' },
    { value: 'finance', label: 'Финансы' },
    { value: 'leader', label: 'Лидер' },
    { value: 'support', label: 'Саппорт' },
];

// req() кладёт http-статус в res.error; админ-вьюхи трактуют его как 403/401.
export const isForbidden = (res) => res?.error === 403 || res?.status === 403;
export const isUnauthorized = (res) => res?.error === 401 || res?.status === 401;
