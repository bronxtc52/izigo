'use client';
import React, { useEffect, useState } from 'react';
import {
    Tree, Tag, Button, Space, InputNumber, Result, Typography, Spin,
    Modal, Select, Alert, Descriptions, message,
} from 'antd';
import MemberCard from '@/views/admin/MemberCard';
import * as webApi from '@/views/admin/webApi';

/** Узел backend-дерева → узел Antd Tree (key=id; обрезанные по глубине помечаем). */
const toTreeData = (node, onOpen, onMove) => {
    if (!node) return [];
    const children = (node.children ?? []).map((c) => toTreeData(c, onOpen, onMove)[0]);
    if (node.truncated) {
        children.push({ key: `${node.id}-more`, selectable: false, title: <Typography.Text type="secondary">… ниже есть участники</Typography.Text> });
    }
    return [{
        key: node.id,
        title: (
            <Space size={6}>
                <span>{node.name}</span>
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>#{node.id}</Typography.Text>
                <Tag color={node.status === 'active' ? 'green' : 'default'} style={{ marginInlineEnd: 0 }}>{node.status}</Tag>
                {node.position && <Tag style={{ marginInlineEnd: 0 }}>{node.position === 'left' ? 'L' : 'R'}</Tag>}
                {node.package && <Tag color="blue" style={{ marginInlineEnd: 0 }}>{node.package}</Tag>}
                <Typography.Link onClick={(e) => { e.stopPropagation(); onOpen(node.id); }}>карточка</Typography.Link>
                {onMove && (
                    <Typography.Link onClick={(e) => { e.stopPropagation(); onMove(node.id); }}>перенести</Typography.Link>
                )}
            </Space>
        ),
        children,
    }];
};

/** Генеалогия (B1/B2): read-only дерево + drill-down; для owner — перенос участника с dry-run preview. */
const Genealogy = () => {
    const creds = webApi.getToken();
    const isOwner = webApi.getRoles().includes('owner');

    const [rootId, setRootId] = useState(null);
    const [pendingRoot, setPendingRoot] = useState(null);
    const [tree, setTree] = useState(null);
    const [loading, setLoading] = useState(true);
    const [forbidden, setForbidden] = useState(false);
    const [selected, setSelected] = useState(null);

    // B2: состояние переноса (owner). preview ОБЯЗАТЕЛЕН перед применением.
    const [moveOpen, setMoveOpen] = useState(false);
    const [mvMember, setMvMember] = useState(null);
    const [mvParent, setMvParent] = useState(null);
    const [mvPos, setMvPos] = useState('left');
    const [preview, setPreview] = useState(null); // null = ещё не считали; затухает при правке формы
    const [busy, setBusy] = useState(false);

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

    const openMove = (memberId) => {
        setMvMember(memberId);
        setMvParent(null);
        setMvPos('left');
        setPreview(null);
        setMoveOpen(true);
    };

    // Любая правка формы инвалидирует прежний предпросмотр → «Применить» снова блокируется.
    const editField = (setter) => (val) => { setter(val); setPreview(null); };

    const runPreview = async () => {
        if (!mvMember || !mvParent) { message.error('Укажите участника и нового родителя'); return; }
        setBusy(true);
        const res = await webApi.previewMovePlacement(undefined, { member_id: mvMember, parent_id: mvParent, position: mvPos });
        setBusy(false);
        if (res?.error) { message.error(res.error === 403 ? 'Недостаточно прав' : 'Ошибка предпросмотра'); return; }
        setPreview(res?.data ?? null);
    };

    const applyMove = async () => {
        if (!preview?.valid) return; // защита: применять только после валидного preview
        setBusy(true);
        try {
            await webApi.movePlacement(undefined, { member_id: mvMember, parent_id: mvParent, position: mvPos });
            message.success('Участник перенесён');
            setMoveOpen(false);
            load(rootId);
        } catch (e) {
            message.error(e?.status === 422 ? 'Перенос отклонён валидацией' : 'Не удалось перенести');
        } finally {
            setBusy(false);
        }
    };

    if (forbidden) return <Result status="403" title="Недостаточно прав" />;

    if (selected != null) {
        return (
            <Space direction="vertical" size={12} style={{ display: 'flex' }}>
                <Button onClick={() => setSelected(null)}>← К дереву</Button>
                <MemberCard id={selected} creds={creds} api={webApi} piiApi={webApi} canReveal={isOwner} />
            </Space>
        );
    }

    const treeData = toTreeData(tree, setSelected, isOwner ? openMove : null);

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

            {/* B2: перенос участника. «Применить» доступно только после валидного dry-run preview. */}
            <Modal
                open={moveOpen}
                onCancel={() => setMoveOpen(false)}
                title="Перенос участника в дереве"
                okText="Применить"
                okButtonProps={{ danger: true, disabled: !preview?.valid, loading: busy }}
                onOk={applyMove}
                cancelText="Закрыть"
            >
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 12 }}
                    message="Перенос меняет структуру сети"
                    description="Это изменит вход расчёта бонусов (пересчёт — на следующей активации). Действие пишется в аудит. Обязателен предпросмотр."
                />
                <Space wrap style={{ marginBottom: 12 }}>
                    <InputNumber placeholder="ID участника" value={mvMember} onChange={editField(setMvMember)} min={1} />
                    <InputNumber placeholder="ID нового родителя" value={mvParent} onChange={editField(setMvParent)} min={1} />
                    <Select
                        value={mvPos}
                        onChange={editField(setMvPos)}
                        style={{ width: 110 }}
                        options={[{ value: 'left', label: 'Левая' }, { value: 'right', label: 'Правая' }]}
                    />
                    <Button onClick={runPreview} loading={busy}>Предпросмотр</Button>
                </Space>

                {preview && (
                    preview.valid ? (
                        <>
                            <Alert type="success" showIcon style={{ marginBottom: 12 }}
                                message={`Перенос допустим · затронуто узлов: ${preview.affected_nodes}`} />
                            <Descriptions size="small" column={1} bordered>
                                <Descriptions.Item label="Участник">
                                    {preview.member?.name} (#{preview.member?.id})
                                </Descriptions.Item>
                                <Descriptions.Item label="Текущий путь">{preview.member?.path}</Descriptions.Item>
                                <Descriptions.Item label="Новый путь">{preview.after?.path}</Descriptions.Item>
                                <Descriptions.Item label="Новый родитель / нога">
                                    #{preview.after?.parent_id} · {preview.after?.position === 'left' ? 'левая' : 'правая'}
                                </Descriptions.Item>
                            </Descriptions>
                        </>
                    ) : (
                        <Alert type="error" showIcon message="Перенос невозможен" description={preview.reason} />
                    )
                )}
            </Modal>
        </Space>
    );
};

export default Genealogy;
