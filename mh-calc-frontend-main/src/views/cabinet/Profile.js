'use client';
import React, { useEffect, useState } from 'react';
import { Card, Spin, Descriptions, Input, Button, Tag, message } from 'antd';
import { useGlobalContext } from '@/common/GlobalContext';
import { fetchMe, handleAuthError } from './api';

/**
 * Профиль партнёра: статус, ранг, пакет и реф-ссылка (с копированием).
 */
const Profile = () => {
    const { userToken, lang, currency, setUserToken, setShowAuth } = useGlobalContext();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        if (!userToken) return;
        const onUnauthorized = () => {
            if (typeof window !== 'undefined') localStorage.removeItem('userToken');
            setUserToken(false);
            setShowAuth(true);
        };
        (async () => {
            const res = await fetchMe(userToken, lang, currency);
            if (handleAuthError([res], onUnauthorized)) return;
            setData(res?.data ?? null);
            setLoading(false);
        })();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [userToken]);

    if (loading) return <Spin size="large" style={{ display: 'block', margin: '80px auto' }} />;

    const member = data?.member;
    const refLink = data?.ref_link ?? '';

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(refLink);
            message.success('Ссылка скопирована');
        } catch (e) {
            message.error('Не удалось скопировать');
        }
    };

    return (
        <Card title="Профиль">
            <Descriptions column={1}>
                <Descriptions.Item label="Имя">{member?.name ?? '—'}</Descriptions.Item>
                <Descriptions.Item label="Статус">
                    <Tag color={member?.status === 'active' ? 'green' : 'default'}>{member?.status}</Tag>
                </Descriptions.Item>
                <Descriptions.Item label="Ранг">{member?.rank?.alias ?? 'нет'}</Descriptions.Item>
                <Descriptions.Item label="Реф-код">{member?.ref_code}</Descriptions.Item>
                <Descriptions.Item label="Реф-ссылка">
                    <Input.Group compact style={{ display: 'flex', maxWidth: 480 }}>
                        <Input readOnly value={refLink} />
                        <Button type="primary" onClick={copy}>Копировать</Button>
                    </Input.Group>
                </Descriptions.Item>
            </Descriptions>
        </Card>
    );
};

export default Profile;
