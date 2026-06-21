'use client';
import { getData, sender, API_SERVER_URL } from '@/common/utils/utils';

// Кабинет партнёра — обёртки над API. Все запросы идут с токеном (CalculatorAuthToken).

export const fetchMe = (token, lang, currency) =>
    getData('/api/v1/cabinet/me', token, lang, currency);

export const fetchDashboard = (token, lang, currency) =>
    getData('/api/v1/cabinet/dashboard', token, lang, currency);

export const fetchRankProgress = (token, lang, currency) =>
    getData('/api/v1/cabinet/rank-progress', token, lang, currency);

export const fetchTeamTree = (token, lang, currency) =>
    getData('/api/v1/cabinet/team-tree', token, lang, currency);

// Если любой ответ — 401/403 (токен протух/невалиден), вызвать onUnauthorized
// (сброс токена → форма входа) и вернуть true, чтобы вьюха не рендерила пустоту.
export const handleAuthError = (responses, onUnauthorized) => {
    const bad = responses.find((r) => r && (r.status === 401 || r.status === 403));
    if (bad) {
        onUnauthorized();
        return true;
    }
    return false;
};

export const activatePackage = (token, packageId) =>
    new Promise((resolve, reject) => {
        sender(
            `${API_SERVER_URL}/api/v1/cabinet/activate-package`,
            'POST',
            { package_id: packageId },
            (response) => resolve(response?.data ?? response),
            (data, status) => reject({ data, status }),
            token,
        );
    });

// Фикс-пакеты IziGo (PV из доменного ядра). Цены — справочно.
export const PACKAGES = [
    { id: 1, name: 'Bronze', pv: 90, price: 100 },
    { id: 2, name: 'Silver', pv: 180, price: 200 },
    { id: 3, name: 'Gold', pv: 540, price: 600 },
];
