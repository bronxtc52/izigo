'use client';
// T13 — фиче-локальный API-клиент админки «План V2».
//
// Потребляет ТОЛЬКО read/action-эндпоинты задач-владельцев по словарю MF-8
// (v2_policy_versions статусы draft|active|retired; v2_pool_calibrations;
// v2_award_entitlements статусы granted|on_hold|paid_out|forfeited) — своих имён
// не вводит. Базовый префикс всех V2-админ-роутов: /api/v1/admin/v2/...
//
// Почему не reuse `req` из webApi.js: req() на !res.ok возвращает только { error:<code> }
// и ГЛОТАЕТ тело ответа. Редактор PolicyVersion обязан показывать текст серверной
// валидации (422 { message }) — поэтому локальный call() читает тело и на ошибке
// (иначе валидация была бы «просто 422» без причины). webApi.js НЕ трогаем: берём из
// него только clearToken (публичный экспорт). Транспорт — как у req(): same-origin
// BFF-proxy, httpOnly-cookie уходит автоматически, Bearer в JS больше не существует (t1).
import { clearToken } from '@/views/admin/webApi';

const BASE = '/api/v1/admin/v2';

// Зомби-сессия: 401 → чистим токен и уводим на логин (как req() в webApi.js), кроме
// самой страницы логина. Реальная защита — на бэке; это лишь закрытие UI.
const handleUnauthorized = () => {
    clearToken();
    if (typeof window !== 'undefined' && !window.location.pathname.endsWith('/admin/login')) {
        window.location.assign('/admin/login');
    }
};

const qs = (params = {}) => {
    const s = new URLSearchParams(
        Object.entries(params).filter(([, v]) => v !== '' && v != null),
    ).toString();
    return s ? `?${s}` : '';
};

/**
 * Унифицированный вызов V2-админ-API. Возвращает:
 *   успех → { ok:true, data }
 *   ошибка → { ok:false, status:<httpCode>, message:<текст бэка|null> }
 * Никогда не бросает — вызывающий сам решает, что показать (пустое состояние/сообщение).
 */
const call = async (path, method = 'GET', body = null) => {
    try {
        const res = await fetch(`${BASE}${path}`, {
            method,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/json;charset=UTF-8',
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        if (res.status === 401) { handleUnauthorized(); return { ok: false, status: 401, message: null }; }
        let json = null;
        try { json = await res.json(); } catch (e) { json = null; }
        if (!res.ok) {
            return { ok: false, status: res.status, message: json?.message ?? null };
        }
        return { ok: true, data: json?.data ?? json };
    } catch (e) {
        return { ok: false, status: 0, message: null };
    }
};

// --- PolicyVersion (T01, flag mh_plan_v2_admin) ---
export const listPolicyVersions = () => call('/policy-versions');
export const getPolicyVersion = (id) => call(`/policy-versions/${id}`);
export const resolvePolicy = (at) => call(`/policy-versions/resolve${qs({ at })}`);
export const createPolicyDraft = (payload) => call('/policy-versions', 'POST', payload);
export const updatePolicyDraft = (id, payload) => call(`/policy-versions/${id}`, 'PUT', payload);
export const activatePolicy = (id, payload = {}) => call(`/policy-versions/${id}/activate`, 'POST', payload);
export const retirePolicy = (id) => call(`/policy-versions/${id}/retire`, 'POST', {});

// --- Периоды (T04, flag mh_plan_v2_admin) — read-only + owner close ---
export const listPeriods = (params = {}) => call(`/periods${qs(params)}`);
export const getPeriod = (id) => call(`/periods/${id}`);
export const closePeriod = (id) => call(`/periods/${id}/close`, 'POST', {});

// --- Счета партнёра (T02, flag mh_plan_v2_admin) — read-only ---
export const memberAccounts = (memberId) => call(`/members/${memberId}/accounts`);
export const memberLots = (memberId) => call(`/members/${memberId}/lots`);

// --- Отчёт 60%-пула (T11, flag mh_v2_pool) ---
export const listPoolPeriods = () => call('/pool/periods');
export const getPoolPeriod = (code) => call(`/pool/periods/${code}`);
export const getPoolMembers = (code) => call(`/pool/periods/${code}/members`);
export const recalibratePool = (code) => call(`/pool/periods/${code}/recalibrate`, 'POST', {});

// --- Очередь наград (T10, flag mh_v2_awards) ---
export const listAwards = (status = '') => call(`/awards${qs({ status })}`);
export const markPaidAward = (id, note = null) => call(`/awards/${id}/mark-paid`, 'POST', note ? { note } : {});
export const holdAward = (id, note = null) => call(`/awards/${id}/hold`, 'POST', note ? { note } : {});
export const releaseAward = (id, note = null) => call(`/awards/${id}/release`, 'POST', note ? { note } : {});
export const forfeitAward = (id, reason) => call(`/awards/${id}/forfeit`, 'POST', { reason });

// --- Роли текущего админа (для скрытия mutation-кнопок; бэк — источник истины) ---
export { getRoles } from '@/views/admin/webApi';
