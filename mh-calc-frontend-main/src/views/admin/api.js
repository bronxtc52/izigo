'use client';
import { getData, sender, API_SERVER_URL } from '@/common/utils/utils';

// Админ-API. Все запросы с токеном; доступ ограничен ролями на backend (403).

export const fetchMembers = (token, params = {}) => {
    const qs = new URLSearchParams(
        Object.entries(params).filter(([, v]) => v !== '' && v != null),
    ).toString();
    return getData(`/api/v1/admin/members${qs ? `?${qs}` : ''}`, token);
};

export const fetchMember = (token, id) => getData(`/api/v1/admin/members/${id}`, token);

export const fetchPlanSettings = (token) => getData('/api/v1/admin/plan-settings', token);

const post = (token, url, method, data) =>
    new Promise((resolve, reject) => {
        sender(
            `${API_SERVER_URL}${url}`,
            method,
            data,
            (response) => resolve(response?.data ?? response),
            (d, status) => reject({ data: d, status }),
            token,
        );
    });

export const assignRole = (token, memberId, role, leaderScopeMemberId = null) =>
    post(token, `/api/v1/admin/members/${memberId}/role`, 'POST', {
        role,
        leader_scope_member_id: leaderScopeMemberId,
    });

export const revokeRole = (token, memberId, role) =>
    post(token, `/api/v1/admin/members/${memberId}/role`, 'DELETE', { role });

export const updatePlanSettings = (token, data) =>
    post(token, '/api/v1/admin/plan-settings', 'PUT', data);

export const ROLES = [
    { value: 'owner', label: 'Владелец' },
    { value: 'finance', label: 'Финансы' },
    { value: 'leader', label: 'Лидер' },
    { value: 'support', label: 'Саппорт' },
];

export const isForbidden = (res) => res?.status === 403;
export const isUnauthorized = (res) => res?.status === 401;
