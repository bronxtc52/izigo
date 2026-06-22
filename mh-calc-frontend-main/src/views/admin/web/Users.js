'use client';
import React, { useState } from 'react';
import { Button, Space } from 'antd';
import MembersList from '@/views/admin/MembersList';
import MemberCard from '@/views/admin/MemberCard';
import * as webApi from '@/views/admin/webApi';

/** Раздел «Пользователи»: список + карточка участника (переиспользует Mini-App-компоненты). */
const Users = () => {
    const [memberId, setMemberId] = useState(null);
    const creds = webApi.getToken();
    // C5: PII reveal/полный экспорт — owner-only (UX-гейт; реальная защита на бэкенде).
    const isOwner = webApi.getRoles().includes('owner');

    if (memberId != null) {
        return (
            <Space direction="vertical" size={12} style={{ display: 'flex' }}>
                <Button onClick={() => setMemberId(null)}>← К списку</Button>
                <MemberCard id={memberId} creds={creds} api={webApi} piiApi={webApi} canReveal={isOwner} />
            </Space>
        );
    }

    return <MembersList creds={creds} api={webApi} onOpenMember={(id) => setMemberId(id)} />;
};

export default Users;
