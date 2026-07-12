'use client';
// T12 (mh-full-plan): фиче-локальный API-клиент возвратов/сторно V2 для веб-админки.
// По образцу T13-паттерна: импортируем { req } из webApi (mutate там не экспортирован),
// свой mutate-хелпер; webApi.js НЕ трогаем. Токен инжектится webApi (передаём undefined).
import { req } from '@/views/admin/webApi';

const mutate = (path, method, data) => req(path, undefined, method, data);

const qs = (params = {}) => {
    const s = new URLSearchParams(
        Object.entries(params).filter(([, v]) => v !== '' && v != null),
    ).toString();
    return s ? `?${s}` : '';
};

// Возвраты.
export const listReturnsV2 = (params = {}) =>
    req(`/api/v1/admin/v2/refunds${qs(params)}`, undefined);
export const getReturnV2 = (id) => req(`/api/v1/admin/v2/refunds/${id}`, undefined);
export const createReturnV2 = (data) => mutate('/api/v1/admin/v2/refunds', 'POST', data);

// Корректировки закрытых периодов.
export const listPeriodCorrectionsV2 = (params = {}) =>
    req(`/api/v1/admin/v2/period-corrections${qs(params)}`, undefined);
export const approveCorrectionV2 = (id) =>
    mutate(`/api/v1/admin/v2/period-corrections/${id}/approve`, 'POST', {});
export const rejectCorrectionV2 = (id) =>
    mutate(`/api/v1/admin/v2/period-corrections/${id}/reject`, 'POST', {});
export const postCorrectionV2 = (id) =>
    mutate(`/api/v1/admin/v2/period-corrections/${id}/post`, 'POST', {});
