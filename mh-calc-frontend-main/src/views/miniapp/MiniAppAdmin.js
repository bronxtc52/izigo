'use client';
import React, { useState } from 'react';
import { Segmented, Button, Space } from 'antd';
import MembersList from '@/views/admin/MembersList';
import MemberCard from '@/views/admin/MemberCard';
import PlanSettings from '@/views/admin/PlanSettings';
import AdminWithdrawals from '@/views/admin/AdminWithdrawals';
import * as initDataApi from '@/views/admin/initDataApi';

/**
 * Админ-раздел внутри Telegram Mini App. Виден только обладателям ролей
 * (owner/finance/leader/support) — гейтинг по me.member.roles делает MiniAppShell.
 * Авторизация — по initData (initDataApi), а не web-токену. Навигация — локальным
 * стейтом (внутри одной страницы Mini App нет роутера разделов).
 *
 * onUnauthorized — поднять экран «Откройте через Telegram» на уровне Shell (401).
 */
const MiniAppAdmin = ({ initData, onUnauthorized }) => {
    // section: 'members' | 'plan'; memberId !== null → открыта карточка участника.
    const [section, setSection] = useState('members');
    const [memberId, setMemberId] = useState(null);

    return (
        <div>
            {memberId == null ? (
                <>
                    <Segmented
                        block
                        value={section}
                        onChange={(v) => setSection(v)}
                        options={[
                            { label: 'Участники', value: 'members' },
                            { label: 'Выводы', value: 'withdrawals' },
                            { label: 'Маркетинг-план', value: 'plan' },
                        ]}
                        style={{ marginBottom: 12 }}
                    />
                    {section === 'members' && (
                        <MembersList
                            creds={initData}
                            api={initDataApi}
                            onUnauthorized={onUnauthorized}
                            onOpenMember={(id) => setMemberId(id)}
                        />
                    )}
                    {section === 'withdrawals' && (
                        <AdminWithdrawals creds={initData} onUnauthorized={onUnauthorized} />
                    )}
                    {section === 'plan' && (
                        <PlanSettings creds={initData} api={initDataApi} onUnauthorized={onUnauthorized} />
                    )}
                </>
            ) : (
                <Space direction="vertical" size={12} style={{ display: 'flex' }}>
                    <Button onClick={() => setMemberId(null)}>← К списку участников</Button>
                    <MemberCard
                        id={memberId}
                        creds={initData}
                        api={initDataApi}
                        onUnauthorized={onUnauthorized}
                    />
                </Space>
            )}
        </div>
    );
};

export default MiniAppAdmin;
