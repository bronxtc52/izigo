'use client';
import React, { useEffect, useState } from 'react';
import { Tree, Tag, Button, Space, InputNumber, Result, Typography, Spin } from 'antd';
import MemberCard from '@/views/admin/MemberCard';
import * as webApi from '@/views/admin/webApi';

/** Узел backend-дерева → узел Antd Tree (key=id; обрезанные по глубине помечаем). */
const toTreeData = (node, onOpen) => {
    if (!node) return [];
    const children = (node.children ?? []).map((c) => toTreeData(c, onOpen)[0]);
    if (node.truncated) {
        children.push({ key: `${node.id}-more`, selectable: false, title: <Typography.Text type="secondary">… ниже есть участники</Typography.Text> });
    }
    return [{
        key: node.id,
        title: (
            <Space size={6}>
                <span>{node.name}</span>
                <Tag color={node.status === 'active' ? 'green' : 'default'} style={{ marginInlineEnd: 0 }}>{node.status}</Tag>
                {node.position && <Tag style={{ marginInlineEnd: 0 }}>{node.position === 'left' ? 'L' : 'R'}</Tag>}
                <Typography.Link onClick={(e) => { e.stopPropagation(); onOpen(node.id); }}>карточка</Typography.Link>
            </Space>
        ),
        children,
    }];
};

/** Генеалогия (B1): read-only бинарное дерево живой сети + drill-down в карточку участника. */
const Genealogy = () => {
    const creds = webApi.getToken();
    const [rootId, setRootId] = useState(null);     // от какого участника строить (null = вершина сети)
    const [pendingRoot, setPendingRoot] = useState(null);
    const [tree, setTree] = useState(null);
    const [loading, setLoading] = useState(true);
    const [forbidden, setForbidden] = useState(false);
    const [selected, setSelected] = useState(null); // открытая карточка участника

    const load = (root) => {
        setLoading(true);
        webApi.fetchGenealogy(undefined, root).then((res) => {
            if (webApi.isForbidden(res)) { setForbidden(true); setLoading(false); return; }
            setTree(res?.data?.tree ?? null);
            setLoading(false);
        });
    };

    useEffect(() => {
        load(rootId);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rootId]);

    if (forbidden) return <Result status="403" title="Недостаточно прав" />;

    if (selected != null) {
        return (
            <Space direction="vertical" size={12} style={{ display: 'flex' }}>
                <Button onClick={() => setSelected(null)}>← К дереву</Button>
                <MemberCard id={selected} creds={creds} api={webApi} />
            </Space>
        );
    }

    const treeData = toTreeData(tree, setSelected);

    return (
        <Space direction="vertical" size={16} style={{ display: 'flex' }}>
            <Space wrap>
                <InputNumber placeholder="ID участника (корень)" value={pendingRoot} onChange={setPendingRoot} min={1} />
                <Button type="primary" onClick={() => setRootId(pendingRoot || null)}>Смотреть от участника</Button>
                <Button onClick={() => { setPendingRoot(null); setRootId(null); }}>Вершина сети</Button>
            </Space>

            {loading ? (
                <Spin />
            ) : treeData.length ? (
                <Tree treeData={treeData} defaultExpandAll selectable={false} showLine />
            ) : (
                <Result status="info" title="Сеть пуста" subTitle="Нет участников для отображения." />
            )}
        </Space>
    );
};

export default Genealogy;
