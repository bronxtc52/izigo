'use client';
import React, { useEffect, useState } from 'react';
import { Tabs, Table, InputNumber, Input, Button, Space, Spin, Result, message, Typography, Alert } from 'antd';
import * as api from '@/views/admin/webApi';

/**
 * Редактор маркетинг-плана (боевой комп-движок). Подвкладки: Пакеты, Ранги, Бинарный %,
 * Реферальный %, Лидерский %, Глобальные. Загружает полный документ, правит, сохраняет
 * целиком (PUT /admin/plan). Forward-only: меняет только будущие активации. Правка — owner.
 */
const MarketingPlan = () => {
    const [doc, setDoc] = useState(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [forbidden, setForbidden] = useState(false);

    useEffect(() => {
        (async () => {
            const res = await api.fetchPlan();
            if (api.isForbidden(res)) { setForbidden(true); setLoading(false); return; }
            setDoc(res?.data ?? null);
            setLoading(false);
        })();
    }, []);

    // Обновить вложенный путь в документе иммутабельно.
    const patch = (mutator) => setDoc((prev) => { const d = structuredClone(prev); mutator(d); return d; });

    const save = async () => {
        setSaving(true);
        try {
            const res = await api.updatePlan(undefined, doc);
            setDoc(res);
            message.success('План сохранён (применится к будущим активациям)');
        } catch (e) {
            message.error(e?.status === 403 ? 'Менять план может только владелец' : 'Ошибка валидации/сохранения');
        } finally {
            setSaving(false);
        }
    };

    if (loading) return <Spin style={{ display: 'block', margin: '60px auto' }} />;
    if (forbidden) return <Result status="403" title="Недостаточно прав" />;
    if (!doc) return <Result status="warning" title="Нет данных плана" />;

    const numCell = (value, onChange, opts = {}) => (
        <InputNumber value={value} onChange={onChange} style={{ width: 110 }} {...opts} />
    );

    // --- Пакеты ---
    const packagesTab = (
        <Table rowKey="id" size="small" pagination={false} dataSource={doc.packages}
            columns={[
                { title: 'ID', dataIndex: 'id' },
                { title: 'Sort', render: (_, r, i) => numCell(r.sort, (v) => patch((d) => { d.packages[i].sort = v; }), { min: 1 }) },
                { title: 'Название', render: (_, r, i) => <Input value={r.name} style={{ width: 140 }} onChange={(e) => patch((d) => { d.packages[i].name = e.target.value; })} /> },
                { title: 'PV', render: (_, r, i) => numCell(r.pv, (v) => patch((d) => { d.packages[i].pv = v; }), { min: 0 }) },
            ]}
        />
    );

    // --- Ранги ---
    const ranksTab = (
        <Table rowKey="id" size="small" pagination={false} dataSource={doc.ranks} scroll={{ x: true }}
            columns={[
                { title: 'ID', dataIndex: 'id' },
                { title: 'Sort', render: (_, r, i) => numCell(r.sort, (v) => patch((d) => { d.ranks[i].sort = v; }), { min: 1, style: { width: 70 } }) },
                { title: 'Alias', render: (_, r, i) => <Input value={r.alias} style={{ width: 130 }} onChange={(e) => patch((d) => { d.ranks[i].alias = e.target.value; })} /> },
                { title: 'Малая ветка PV', render: (_, r, i) => numCell(r.small_branch_pv, (v) => patch((d) => { d.ranks[i].small_branch_pv = v; }), { min: 0 }) },
                { title: 'Лично пригл.', render: (_, r, i) => numCell(r.personal_count, (v) => patch((d) => { d.ranks[i].personal_count = v; }), { min: 0, style: { width: 80 } }) },
                { title: 'N в ранге', render: (_, r, i) => numCell(r.in_rank_count, (v) => patch((d) => { d.ranks[i].in_rank_count = v; }), { min: 0, style: { width: 80 } }) },
                { title: 'Треб. ранг', render: (_, r, i) => numCell(r.in_rank_id, (v) => patch((d) => { d.ranks[i].in_rank_id = v; }), { min: 0, style: { width: 80 } }) },
                { title: 'Ранг-бонус $', render: (_, r, i) => numCell(r.bonus_usd, (v) => patch((d) => { d.ranks[i].bonus_usd = v; }), { min: 0 }) },
            ]}
        />
    );

    // --- Бинарный % (по рангу) ---
    const binaryRows = doc.ranks.map((r) => ({ rankId: r.id, alias: r.alias }));
    const binaryTab = (
        <Table rowKey="rankId" size="small" pagination={false} dataSource={binaryRows}
            columns={[
                { title: 'Ранг', render: (_, r) => `${r.rankId} · ${r.alias}` },
                {
                    title: 'Бинарный %', render: (_, r) =>
                        numCell(doc.binary_percent_by_rank[r.rankId], (v) => patch((d) => { d.binary_percent_by_rank[r.rankId] = v; }), { min: 0, max: 100 }),
                },
            ]}
        />
    );

    // --- Реферальный % [packageSort][level] ---
    const refLevels = Array.from(
        new Set(Object.values(doc.referral_percent).flatMap((lv) => Object.keys(lv))),
    ).sort();
    const referralTab = (
        <Table rowKey={(r) => r.sort} size="small" pagination={false}
            dataSource={Object.keys(doc.referral_percent).map((sort) => ({ sort }))}
            columns={[
                { title: 'Пакет (sort)', dataIndex: 'sort' },
                ...refLevels.map((lvl) => ({
                    title: `Уровень ${lvl}`,
                    render: (_, r) => numCell(
                        doc.referral_percent[r.sort]?.[lvl],
                        (v) => patch((d) => { d.referral_percent[r.sort][lvl] = v; }),
                        { min: 0, max: 100 },
                    ),
                })),
            ]}
        />
    );

    // --- Лидерский % [level][packageId][rankId] — плоский список, правим значение ---
    const leaderEntries = [];
    Object.entries(doc.leader_percent).forEach(([level, byPkg]) =>
        Object.entries(byPkg).forEach(([pkg, byRank]) =>
            Object.entries(byRank).forEach(([rank, pct]) =>
                leaderEntries.push({ key: `${level}-${pkg}-${rank}`, level, pkg, rank, pct }),
            ),
        ),
    );
    const leaderTab = (
        <Table rowKey="key" size="small" pagination={false} dataSource={leaderEntries}
            columns={[
                { title: 'Уровень', dataIndex: 'level' },
                { title: 'Пакет', dataIndex: 'pkg' },
                { title: 'Ранг', dataIndex: 'rank' },
                {
                    title: '%', render: (_, r) =>
                        numCell(r.pct, (v) => patch((d) => { d.leader_percent[r.level][r.pkg][r.rank] = v; }), { min: 0, max: 100 }),
                },
            ]}
        />
    );

    // --- Глобальные ---
    const globalTab = (
        <Space direction="vertical" size={12}>
            <Space>
                <Typography.Text>Компрессия (max_rank_diff):</Typography.Text>
                {numCell(doc.global.max_rank_diff, (v) => patch((d) => { d.global.max_rank_diff = v; }), { min: 1 })}
            </Space>
            <Space>
                <Typography.Text>Глубина реферального (referral_depth):</Typography.Text>
                {numCell(doc.global.referral_depth, (v) => patch((d) => { d.global.referral_depth = v; }), { min: 1 })}
            </Space>
        </Space>
    );

    return (
        <Space direction="vertical" size={16} style={{ display: 'flex' }}>
            <Alert type="info" showIcon
                message="Изменения применяются только к будущим активациям. Прошлые начисления не пересчитываются." />
            <Tabs
                items={[
                    { key: 'packages', label: 'Пакеты', children: packagesTab },
                    { key: 'ranks', label: 'Ранги', children: ranksTab },
                    { key: 'binary', label: 'Бинарный %', children: binaryTab },
                    { key: 'referral', label: 'Реферальный %', children: referralTab },
                    { key: 'leader', label: 'Лидерский %', children: leaderTab },
                    { key: 'global', label: 'Глобальные', children: globalTab },
                ]}
            />
            <Button type="primary" loading={saving} onClick={save}>Сохранить план</Button>
        </Space>
    );
};

export default MarketingPlan;
