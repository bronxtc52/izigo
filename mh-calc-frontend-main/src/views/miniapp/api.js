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

export const mmMe = (i) => req('/api/v1/cabinet/me', i);
export const mmDashboard = (i) => req('/api/v1/cabinet/dashboard', i);
export const mmRank = (i) => req('/api/v1/cabinet/rank-progress', i);
export const mmTree = (i) => req('/api/v1/cabinet/team-tree', i);
export const mmActivate = (i, packageId) =>
    req('/api/v1/cabinet/activate-package', i, 'POST', { package_id: packageId });

export const PACKAGES = [
    { id: 1, name: 'Bronze', pv: 90, price: 100 },
    { id: 2, name: 'Silver', pv: 180, price: 200 },
    { id: 3, name: 'Gold', pv: 540, price: 600 },
];
