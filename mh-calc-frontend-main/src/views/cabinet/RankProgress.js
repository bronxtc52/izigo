'use client';
import React, { useEffect, useState } from 'react';
import { Card, Spin, Progress, Descriptions, Tag } from 'antd';
import { useGlobalContext } from '@/common/GlobalContext';
import { fetchRankProgress, handleAuthError } from './api';

const pct = (cur, req) => (req > 0 ? Math.min(100, Math.round((cur / req) * 100)) : 100);

/**
 * Прогресс рангов: текущий ранг, условия следующего и прогресс по ним.
 */
const RankProgress = () => {
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
            const res = await fetchRankProgress(userToken, lang, currency);
            if (handleAuthError([res], onUnauthorized)) return;
            setData(res?.data ?? null);
            setLoading(false);
        })();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [userToken]);

    if (loading) return <Spin size="large" style={{ display: 'block', margin: '80px auto' }} />;

    const current = data?.current;
    const next = data?.next;
    const progress = data?.progress ?? {};

    return (
        <Card title="Прогресс рангов">
            <Descriptions column={1} style={{ marginBottom: 24 }}>
                <Descriptions.Item label="Текущий ранг">
                    {current ? <Tag color="gold">{current.alias}</Tag> : <Tag>нет</Tag>}
                </Descriptions.Item>
                <Descriptions.Item label="Следующий ранг">
                    {next ? <Tag color="blue">{next.alias}</Tag> : 'максимальный достигнут'}
                </Descriptions.Item>
            </Descriptions>

            {next && (
                <>
                    <div style={{ marginBottom: 16 }}>
                        <div>Объём малой ветки: {progress.small_branch_pv ?? 0} / {next.conditions.small_branch_pv} PV</div>
                        <Progress percent={pct(progress.small_branch_pv ?? 0, next.conditions.small_branch_pv)} />
                    </div>
                    <div>
                        <div>Лично приглашённые: {progress.personal_count ?? 0} / {next.conditions.personal_count}</div>
                        <Progress percent={pct(progress.personal_count ?? 0, next.conditions.personal_count)} />
                    </div>
                    {next.conditions.personal_in_rank_count > 0 && (
                        <div style={{ marginTop: 16, color: '#555' }}>
                            Дополнительно: {next.conditions.personal_in_rank_count} лично приглашённых
                            с рангом ≥ #{next.conditions.personal_in_rank_id}
                        </div>
                    )}
                </>
            )}
        </Card>
    );
};

export default RankProgress;
