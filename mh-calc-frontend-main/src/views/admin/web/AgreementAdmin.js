'use client';
import React, { useEffect, useState } from 'react';
import { Card, Input, Button, Space, Statistic, Row, Col, Result, message } from 'antd';
import * as api from '@/views/admin/webApi';

/** Соглашение (B3): просмотр текста/версии + сколько приняли; правка (owner) поднимает версию. */
const AgreementAdmin = () => {
    const isOwner = api.getRoles().includes('owner');
    const [data, setData] = useState(null);
    const [text, setText] = useState('');
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [forbidden, setForbidden] = useState(false);

    const load = async () => {
        setLoading(true);
        const res = await api.fetchAgreement(undefined);
        if (api.isForbidden(res)) { setForbidden(true); setLoading(false); return; }
        setData(res?.data ?? null);
        setText(res?.data?.text ?? '');
        setLoading(false);
    };

    useEffect(() => { load(); /* eslint-disable-next-line */ }, []);

    if (forbidden) return <Result status="403" title="Недостаточно прав" />;

    const save = async () => {
        if (!text.trim()) { message.error('Текст не может быть пустым'); return; }
        setSaving(true);
        try {
            await api.updateAgreement(undefined, text);
            message.success('Соглашение обновлено — версия повышена, участники примут заново');
            load();
        } catch (e) {
            message.error(e?.status === 403 ? 'Только владелец может править' : 'Не удалось сохранить');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Space direction="vertical" size={16} style={{ display: 'flex' }}>
            <Row gutter={16}>
                <Col><Card size="small" loading={loading}><Statistic title="Версия" value={data?.version ?? '—'} /></Card></Col>
                <Col><Card size="small" loading={loading}>
                    <Statistic title="Приняли текущую" value={`${data?.accepted_current_count ?? 0} / ${data?.members_total ?? 0}`} />
                </Card></Col>
            </Row>

            <Card size="small" title="Текст соглашения" loading={loading}>
                <Input.TextArea
                    value={text}
                    onChange={(e) => setText(e.target.value)}
                    autoSize={{ minRows: 8, maxRows: 24 }}
                    disabled={!isOwner}
                />
                {isOwner && (
                    <Button type="primary" style={{ marginTop: 12 }} loading={saving} onClick={save}>
                        Сохранить (поднять версию)
                    </Button>
                )}
            </Card>
        </Space>
    );
};

export default AgreementAdmin;
