'use client';
import React, { useEffect, useMemo, useState } from 'react';
import { Table, Input, Button, Select, Result, message, Typography, Tag, Space } from 'antd';
import { useTranslation } from 'react-i18next';
import * as api from '@/views/admin/webApi';
import { reloadTranslationOverrides } from '@/common/i18n';
import ru from '@/locales/ru/translation.json';
import kk from '@/locales/kk/translation.json';
import az from '@/locales/az/translation.json';
import ky from '@/locales/ky/translation.json';
import uz from '@/locales/uz/translation.json';
import mn from '@/locales/mn/translation.json';

/**
 * Редактор переводов (C4, Block C): owner-only. Список ключей берётся из СТАТИЧЕСКОГО
 * translation.json (показываем ключ + дефолт), поле ввода — DB-оверрайд. Сохранение/сброс
 * через webApi (upsert/delete). Эффективный перевод = оверрайд поверх статики, иначе дефолт.
 */
const STATIC = { ru, kk, az, ky, uz, mn };
const LOCALES = [
    { value: 'ru', label: 'Русский' },
    { value: 'kk', label: 'Қазақша' },
    { value: 'az', label: 'Azərbaycan' },
    { value: 'ky', label: 'Кыргызча' },
    { value: 'uz', label: "O'zbekcha" },
    { value: 'mn', label: 'Монгол' },
];

// Плоская карта dot-ключ→строка из дерева translation.json (только листья-строки).
const flatten = (obj, prefix = '', out = {}) => {
    Object.entries(obj || {}).forEach(([k, v]) => {
        const key = prefix ? `${prefix}.${k}` : k;
        if (v && typeof v === 'object' && !Array.isArray(v)) {
            flatten(v, key, out);
        } else if (typeof v === 'string') {
            out[key] = v;
        }
    });
    return out;
};

const Translations = () => {
    const { t } = useTranslation();
    const isOwner = api.getRoles().includes('owner');
    const [locale, setLocale] = useState('ru');
    const [overrides, setOverrides] = useState({}); // key→value (текущая локаль)
    const [drafts, setDrafts] = useState({}); // key→value (несохранённый ввод)
    const [search, setSearch] = useState('');
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(null);
    const [forbidden, setForbidden] = useState(false);

    const staticFlat = useMemo(() => flatten(STATIC[locale]), [locale]);

    const load = async (loc) => {
        setLoading(true);
        const res = await api.fetchTranslationOverrides(undefined, loc);
        if (api.isForbidden(res)) { setForbidden(true); setLoading(false); return; }
        const map = {};
        (res?.data ?? []).forEach((row) => { map[row.key] = row.value; });
        setOverrides(map);
        setDrafts({});
        setLoading(false);
    };

    useEffect(() => { load(locale); /* eslint-disable-next-line */ }, [locale]);

    const rows = useMemo(() => {
        const q = search.trim().toLowerCase();
        return Object.entries(staticFlat)
            .filter(([key, def]) => !q || key.toLowerCase().includes(q) || String(def).toLowerCase().includes(q))
            .map(([key, def]) => ({ key, def }));
    }, [staticFlat, search]);

    if (forbidden) return <Result status="403" title={t('i18nAdmin.forbidden')} />;

    const save = async (key) => {
        const value = drafts[key];
        if (value == null || value === '') return;
        setSaving(key);
        try {
            await api.upsertTranslationOverride(undefined, locale, key, value);
            setOverrides((p) => ({ ...p, [key]: value }));
            setDrafts((p) => { const n = { ...p }; delete n[key]; return n; });
            reloadTranslationOverrides(); // правка доезжает до UI без релоада (graceful)
            message.success(t('i18nAdmin.saved'));
        } catch (e) {
            message.error(e?.status === 403 ? t('i18nAdmin.ownerOnly') : t('i18nAdmin.saveFailed'));
        } finally {
            setSaving(null);
        }
    };

    const reset = async (key) => {
        setSaving(key);
        try {
            await api.deleteTranslationOverride(undefined, locale, key);
            setOverrides((p) => { const n = { ...p }; delete n[key]; return n; });
            setDrafts((p) => { const n = { ...p }; delete n[key]; return n; });
            reloadTranslationOverrides(); // сброс к дефолту виден без релоада (graceful)
            message.success(t('i18nAdmin.reset'));
        } catch (e) {
            message.error(e?.status === 403 ? t('i18nAdmin.ownerOnly') : t('i18nAdmin.saveFailed'));
        } finally {
            setSaving(null);
        }
    };

    const columns = [
        {
            title: t('i18nAdmin.colKey'),
            dataIndex: 'key',
            key: 'key',
            width: '28%',
            render: (v) => <Typography.Text code>{v}</Typography.Text>,
        },
        {
            title: t('i18nAdmin.colDefault'),
            dataIndex: 'def',
            key: 'def',
            width: '28%',
            render: (v) => <Typography.Text type="secondary">{v}</Typography.Text>,
        },
        {
            title: t('i18nAdmin.colOverride'),
            key: 'override',
            render: (_, row) => {
                const overridden = Object.prototype.hasOwnProperty.call(overrides, row.key);
                const draftVal = drafts[row.key];
                const current = draftVal != null ? draftVal : (overrides[row.key] ?? '');
                return (
                    <Space.Compact style={{ width: '100%' }}>
                        <Input
                            value={current}
                            disabled={!isOwner}
                            placeholder={overridden ? '' : t('i18nAdmin.usingDefault')}
                            onChange={(e) => setDrafts((p) => ({ ...p, [row.key]: e.target.value }))}
                        />
                        <Button
                            type="primary"
                            disabled={!isOwner || draftVal == null || draftVal === ''}
                            loading={saving === row.key}
                            onClick={() => save(row.key)}
                        >
                            {t('i18nAdmin.save')}
                        </Button>
                        <Button
                            danger
                            disabled={!isOwner || !overridden}
                            loading={saving === row.key}
                            onClick={() => reset(row.key)}
                        >
                            {t('i18nAdmin.resetBtn')}
                        </Button>
                    </Space.Compact>
                );
            },
        },
        {
            title: '',
            key: 'status',
            width: 110,
            render: (_, row) => (
                Object.prototype.hasOwnProperty.call(overrides, row.key)
                    ? <Tag color="green">{t('i18nAdmin.overridden')}</Tag>
                    : null
            ),
        },
    ];

    return (
        <div>
            <Space style={{ marginBottom: 12 }} wrap>
                <Select
                    value={locale}
                    style={{ width: 180 }}
                    options={LOCALES}
                    onChange={setLocale}
                />
                <Input.Search
                    allowClear
                    placeholder={t('i18nAdmin.searchPlaceholder')}
                    style={{ width: 280 }}
                    onChange={(e) => setSearch(e.target.value)}
                />
            </Space>
            <Table
                rowKey="key"
                loading={loading}
                columns={columns}
                dataSource={rows}
                size="small"
                pagination={{ pageSize: 50, showSizeChanger: false }}
            />
        </div>
    );
};

export default Translations;
