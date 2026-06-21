'use client';
import React, { useEffect, useState } from 'react';
import dynamic from 'next/dynamic';
import { Card, Spin, Empty } from 'antd';
import { useGlobalContext } from '@/common/GlobalContext';
import { fetchTeamTree, handleAuthError } from './api';

const Tree = dynamic(() => import('react-d3-tree'), { ssr: false });

/**
 * Дерево команды (placement). Данные приходят уже в формате react-d3-tree
 * ({ name, attributes, children }) с backend /cabinet/team-tree.
 */
const TeamTree = () => {
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
            const res = await fetchTeamTree(userToken, lang, currency);
            if (handleAuthError([res], onUnauthorized)) return;
            setData(res?.data ?? null);
            setLoading(false);
        })();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [userToken]);

    if (loading) return <Spin size="large" style={{ display: 'block', margin: '80px auto' }} />;

    const hasTree = data && data.name;

    return (
        <Card title="Моя команда" bodyStyle={{ height: 600, padding: 0 }}>
            {hasTree ? (
                <div style={{ width: '100%', height: '100%' }}>
                    <Tree
                        data={data}
                        orientation="vertical"
                        collapsible={false}
                        translate={{ x: 400, y: 80 }}
                        separation={{ siblings: 1.5, nonSiblings: 2 }}
                        pathFunc="straight"
                    />
                </div>
            ) : (
                <Empty description="Дерево пусто" style={{ paddingTop: 80 }} />
            )}
        </Card>
    );
};

export default TeamTree;
