'use client';
import React, { useEffect, useState } from 'react';
import {
    Table, Button, Space, Tag, Typography, Input, Modal, DatePicker,
    Checkbox, Alert, message, Popconfirm, Descriptions, Result,
} from 'antd';
import { useTranslation } from 'react-i18next';
import * as api from './apiV2';

const { TextArea } = Input;

// Статусы версии политики — словарь MF-8: draft|active|retired (APPROVED нет).
const STATUS_COLOR = { draft: 'default', active: 'green', retired: 'default' };

const dt = (iso) => (iso ? new Date(iso).toLocaleString('ru-RU') : '—');

const isOwner = () => {
    try { return api.getRoles().includes('owner'); } catch (e) { return false; }
};

/**
 * T13 — редактор версий политики V2 (raw-JSON + серверная валидация, one-step
 * owner-activate, diff в аудите пишет бэк). Правится только draft; active/retired
 * immutable (мутация → 422/409, показываем сообщение бэка). Суммы в конфиге — центы USD.
 */
const PolicyVersions = () => {
    const { t } = useTranslation();
    const owner = isOwner();

    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [forbidden, setForbidden] = useState(false);

    const [selected, setSelected] = useState(null); // {id, code, status, config, notes, ...}
    const [draftText, setDraftText] = useState('');
    const [draftNotes, setDraftNotes] = useState('');
    const [serverError, setServerError] = useState(null);
    const [busy, setBusy] = useState(false);

    const [createOpen, setCreateOpen] = useState(false);
    const [newCode, setNewCode] = useState('');
    const [newConfig, setNewConfig] = useState('{\n}');

    const [activateFor, setActivateFor] = useState(null); // version being activated
    const [validFrom, setValidFrom] = useState(null);
    const [allowRetro, setAllowRetro] = useState(false);

    const load = async () => {
        setLoading(true);
        const res = await api.listPolicyVersions();
        if (!res.ok) {
            if (res.status === 403) setForbidden(true);
            setRows([]);
            setLoading(false);
            return;
        }
        setRows(res.data ?? []);
        setLoading(false);
    };

    useEffect(() => { load(); /* eslint-disable-next-line */ }, []);

    const openVersion = async (id) => {
        setServerError(null);
        const res = await api.getPolicyVersion(id);
        if (!res.ok) { message.error(t('mhV2.policy.loadFailed')); return; }
        setSelected(res.data);
        setDraftText(JSON.stringify(res.data.config ?? {}, null, 2));
        setDraftNotes(res.data.notes ?? '');
    };

    // Разбор JSON перед отправкой: клиентская ошибка формата отделена от серверной валидации.
    const parseConfig = (text) => {
        try { return { config: JSON.parse(text) }; }
        catch (e) { return { error: t('mhV2.policy.jsonInvalid') + ': ' + e.message }; }
    };

    const saveDraft = async () => {
        setServerError(null);
        const parsed = parseConfig(draftText);
        if (parsed.error) { setServerError(parsed.error); return; }
        setBusy(true);
        const res = await api.updatePolicyDraft(selected.id, { config: parsed.config, notes: draftNotes || null });
        setBusy(false);
        if (!res.ok) {
            // 422 — серверная валидация (текст с путём ошибки); 403 — не owner; др. — код.
            setServerError(res.message || t('mhV2.policy.saveFailedCode', { code: res.status }));
            return;
        }
        message.success(t('mhV2.policy.saved'));
        await load();
        await openVersion(selected.id);
    };

    const doCreate = async () => {
        setServerError(null);
        const parsed = parseConfig(newConfig);
        if (parsed.error) { setServerError(parsed.error); return; }
        setBusy(true);
        const res = await api.createPolicyDraft({ code: newCode.trim(), config: parsed.config });
        setBusy(false);
        if (!res.ok) { setServerError(res.message || t('mhV2.policy.saveFailedCode', { code: res.status })); return; }
        message.success(t('mhV2.policy.created'));
        setCreateOpen(false);
        setNewCode('');
        setNewConfig('{\n}');
        await load();
    };

    const doActivate = async () => {
        setBusy(true);
        const payload = {};
        if (validFrom) payload.valid_from = validFrom.toISOString();
        if (allowRetro) payload.allow_retro = true;
        const res = await api.activatePolicy(activateFor.id, payload);
        setBusy(false);
        if (!res.ok) { message.error(res.message || t('mhV2.policy.activateFailedCode', { code: res.status })); return; }
        message.success(t('mhV2.policy.activated'));
        setActivateFor(null);
        setValidFrom(null);
        setAllowRetro(false);
        await load();
        if (selected) await openVersion(selected.id);
    };

    const doRetire = async (id) => {
        const res = await api.retirePolicy(id);
        if (!res.ok) { message.error(res.message || t('mhV2.policy.retireFailedCode', { code: res.status })); return; }
        message.success(t('mhV2.policy.retired'));
        await load();
        if (selected?.id === id) await openVersion(id);
    };

    if (forbidden) return <Result status="403" title={t('mhV2.forbidden')} />;

    const columns = [
        { title: 'ID', dataIndex: 'id', width: 60 },
        { title: t('mhV2.policy.colCode'), dataIndex: 'code', render: (v) => <Typography.Text code>{v}</Typography.Text> },
        {
            title: t('mhV2.policy.colStatus'),
            dataIndex: 'status',
            width: 110,
            render: (s) => <Tag color={STATUS_COLOR[s] ?? 'default'}>{t(`mhV2.policy.status.${s}`, s)}</Tag>,
        },
        { title: t('mhV2.policy.colHash'), dataIndex: 'config_hash', ellipsis: true, render: (v) => <Typography.Text type="secondary" style={{ fontSize: 11 }}>{(v ?? '').slice(0, 12)}</Typography.Text> },
        { title: t('mhV2.policy.colValidFrom'), dataIndex: 'valid_from', width: 170, render: dt },
        { title: t('mhV2.policy.colValidTo'), dataIndex: 'valid_to', width: 170, render: dt },
        {
            title: '',
            key: 'act',
            width: 90,
            render: (_, r) => <Button size="small" onClick={() => openVersion(r.id)}>{t('mhV2.policy.open')}</Button>,
        },
    ];

    const selectedIsDraft = selected?.status === 'draft';

    return (
        <Space direction="vertical" style={{ width: '100%' }} size="middle">
            <Space>
                <Button type="primary" disabled={!owner} onClick={() => { setServerError(null); setCreateOpen(true); }}>
                    {t('mhV2.policy.newDraft')}
                </Button>
                <Button onClick={load}>{t('mhV2.refresh')}</Button>
            </Space>

            <Table
                rowKey="id"
                loading={loading}
                columns={columns}
                dataSource={rows}
                size="small"
                pagination={false}
                scroll={{ x: 'max-content' }}
            />

            {selected && (
                <div style={{ borderTop: '1px solid #f0f0f0', paddingTop: 12 }}>
                    <Descriptions size="small" column={2} title={<span>{t('mhV2.policy.editorTitle')}: <Typography.Text code>{selected.code}</Typography.Text> <Tag color={STATUS_COLOR[selected.status]}>{t(`mhV2.policy.status.${selected.status}`, selected.status)}</Tag></span>}>
                        <Descriptions.Item label={t('mhV2.policy.colValidFrom')}>{dt(selected.valid_from)}</Descriptions.Item>
                        <Descriptions.Item label={t('mhV2.policy.colValidTo')}>{dt(selected.valid_to)}</Descriptions.Item>
                        <Descriptions.Item label={t('mhV2.policy.colHash')} span={2}><Typography.Text copyable style={{ fontSize: 11 }}>{selected.config_hash}</Typography.Text></Descriptions.Item>
                    </Descriptions>

                    {serverError && <Alert type="error" showIcon style={{ marginBottom: 8 }} message={t('mhV2.policy.validationError')} description={<pre style={{ whiteSpace: 'pre-wrap', margin: 0 }}>{serverError}</pre>} />}

                    {!selectedIsDraft && <Alert type="info" showIcon style={{ marginBottom: 8 }} message={t('mhV2.policy.immutableNote')} />}

                    <TextArea
                        value={draftText}
                        onChange={(e) => setDraftText(e.target.value)}
                        readOnly={!selectedIsDraft || !owner}
                        autoSize={{ minRows: 12, maxRows: 30 }}
                        style={{ fontFamily: 'monospace', fontSize: 12 }}
                    />
                    {selectedIsDraft && (
                        <Input
                            style={{ marginTop: 8 }}
                            placeholder={t('mhV2.policy.notesPlaceholder')}
                            value={draftNotes}
                            onChange={(e) => setDraftNotes(e.target.value)}
                            maxLength={2000}
                            disabled={!owner}
                        />
                    )}

                    <Space style={{ marginTop: 8 }} wrap>
                        {selectedIsDraft && <Button type="primary" loading={busy} disabled={!owner} onClick={saveDraft}>{t('mhV2.policy.saveValidate')}</Button>}
                        {selectedIsDraft && <Button loading={busy} disabled={!owner} onClick={() => { setServerError(null); setActivateFor(selected); }}>{t('mhV2.policy.activate')}</Button>}
                        {selected.status !== 'retired' && (
                            <Popconfirm title={t('mhV2.policy.retireConfirm')} onConfirm={() => doRetire(selected.id)} disabled={!owner}>
                                <Button danger disabled={!owner}>{t('mhV2.policy.retire')}</Button>
                            </Popconfirm>
                        )}
                        <Button onClick={() => setSelected(null)}>{t('mhV2.close')}</Button>
                    </Space>
                </div>
            )}

            <Modal
                open={createOpen}
                title={t('mhV2.policy.newDraft')}
                onCancel={() => setCreateOpen(false)}
                onOk={doCreate}
                confirmLoading={busy}
                okButtonProps={{ disabled: !owner }}
                okText={t('mhV2.policy.create')}
                width={720}
            >
                {serverError && <Alert type="error" showIcon style={{ marginBottom: 8 }} message={serverError} />}
                <Input style={{ marginBottom: 8 }} placeholder={t('mhV2.policy.codePlaceholder')} value={newCode} onChange={(e) => setNewCode(e.target.value)} maxLength={64} />
                <TextArea value={newConfig} onChange={(e) => setNewConfig(e.target.value)} autoSize={{ minRows: 10, maxRows: 24 }} style={{ fontFamily: 'monospace', fontSize: 12 }} />
            </Modal>

            <Modal
                open={!!activateFor}
                title={<span>{t('mhV2.policy.activateTitle')}: <Typography.Text code>{activateFor?.code}</Typography.Text></span>}
                onCancel={() => setActivateFor(null)}
                onOk={doActivate}
                confirmLoading={busy}
                okButtonProps={{ disabled: !owner }}
                okText={t('mhV2.policy.activateConfirm')}
            >
                <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={t('mhV2.policy.activateWarn')} />
                <div style={{ marginBottom: 8 }}>{t('mhV2.policy.validFromLabel')}</div>
                <DatePicker showTime style={{ width: '100%', marginBottom: 12 }} value={validFrom} onChange={setValidFrom} />
                <Checkbox checked={allowRetro} onChange={(e) => setAllowRetro(e.target.checked)}>{t('mhV2.policy.allowRetro')}</Checkbox>
            </Modal>
        </Space>
    );
};

export default PolicyVersions;
