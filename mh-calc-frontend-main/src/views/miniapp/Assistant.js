'use client';
import React, { useRef, useState } from 'react';
import { Button, Input, Spin, Typography, Alert } from 'antd';
import { RobotOutlined, SendOutlined } from '@ant-design/icons';
import { useTranslation } from 'next-i18next';
import { mmAssistantAsk } from './api';

const { Text } = Typography;

/**
 * AI-ассистент партнёра. Stateless: каждый запрос независим (бэкенд не помнит историю).
 * История хранится только в React state текущей сессии.
 */
const Assistant = ({ initData, pal, isDark }) => {
    const { t, i18n } = useTranslation();
    const locale = i18n.language?.startsWith('en') ? 'en' : 'ru';

    const [messages, setMessages] = useState([]); // [{role:'user'|'assistant', text, error?}]
    const [input, setInput] = useState('');
    const [loading, setLoading] = useState(false);
    const bottomRef = useRef(null);

    const suggestedQuestions = t('assistant.suggested', { returnObjects: true }) ?? [];

    const scrollBottom = () =>
        setTimeout(() => bottomRef.current?.scrollIntoView({ behavior: 'smooth' }), 50);

    const ask = async (question) => {
        if (!question.trim() || loading) return;
        const q = question.trim();
        setInput('');
        setMessages((prev) => [...prev, { role: 'user', text: q }]);
        setLoading(true);
        scrollBottom();

        try {
            const res = await mmAssistantAsk(initData, q, locale);
            if (res?.status === 'success' && res.answer) {
                setMessages((prev) => [...prev, { role: 'assistant', text: res.answer }]);
            } else if (res?.code === 'RATE_LIMITED' || res?.error === 429) {
                setMessages((prev) => [...prev, { role: 'assistant', text: t('assistant.rate_limited'), error: true }]);
            } else {
                setMessages((prev) => [...prev, { role: 'assistant', text: t('assistant.unavailable'), error: true }]);
            }
        } catch {
            setMessages((prev) => [...prev, { role: 'assistant', text: t('assistant.unavailable'), error: true }]);
        } finally {
            setLoading(false);
            scrollBottom();
        }
    };

    const accent = pal?.accent ?? '#3B82F6';
    const bg = isDark ? '#1a1a2e' : '#f8f9ff';
    const cardBg = isDark ? '#252540' : '#ffffff';
    const textColor = isDark ? '#e8e8f0' : '#1a1a2e';
    const mutedColor = isDark ? '#8888aa' : '#6b7280';

    return (
        <div style={{ display: 'flex', flexDirection: 'column', height: '100%', background: bg }}>
            {/* Header */}
            <div style={{
                padding: '12px 16px',
                background: cardBg,
                borderBottom: `1px solid ${isDark ? '#333355' : '#e5e7eb'}`,
                display: 'flex', alignItems: 'center', gap: 8,
            }}>
                <RobotOutlined style={{ color: accent, fontSize: 18 }} />
                <div>
                    <Text strong style={{ color: textColor, fontSize: 15 }}>
                        {t('assistant.title')}
                    </Text>
                    <div>
                        <Text style={{ color: mutedColor, fontSize: 11 }}>
                            {t('assistant.stateless_hint')}
                        </Text>
                    </div>
                </div>
            </div>

            {/* Messages */}
            <div style={{ flex: 1, overflowY: 'auto', padding: '12px 16px' }}>
                {messages.length === 0 ? (
                    /* Suggested questions */
                    <div>
                        <Text style={{ color: mutedColor, fontSize: 12, display: 'block', marginBottom: 10 }}>
                            {t('assistant.suggestions_hint')}
                        </Text>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                            {Array.isArray(suggestedQuestions) && suggestedQuestions.map((q, i) => (
                                <button
                                    key={i}
                                    onClick={() => ask(q)}
                                    style={{
                                        background: cardBg,
                                        border: `1px solid ${isDark ? '#333355' : '#e5e7eb'}`,
                                        borderRadius: 10,
                                        padding: '10px 14px',
                                        textAlign: 'left',
                                        cursor: 'pointer',
                                        color: textColor,
                                        fontSize: 13,
                                        lineHeight: 1.4,
                                    }}
                                >
                                    {q}
                                </button>
                            ))}
                        </div>
                    </div>
                ) : (
                    messages.map((msg, i) => (
                        <div
                            key={i}
                            style={{
                                marginBottom: 10,
                                display: 'flex',
                                justifyContent: msg.role === 'user' ? 'flex-end' : 'flex-start',
                            }}
                        >
                            <div style={{
                                maxWidth: '85%',
                                background: msg.role === 'user'
                                    ? accent
                                    : (msg.error ? (isDark ? '#3a1a1a' : '#fff1f0') : cardBg),
                                color: msg.role === 'user'
                                    ? '#ffffff'
                                    : (msg.error ? (isDark ? '#ff8888' : '#cf1322') : textColor),
                                borderRadius: msg.role === 'user' ? '14px 14px 4px 14px' : '14px 14px 14px 4px',
                                padding: '10px 14px',
                                fontSize: 13,
                                lineHeight: 1.5,
                                border: msg.role === 'assistant' && !msg.error
                                    ? `1px solid ${isDark ? '#333355' : '#e5e7eb'}`
                                    : 'none',
                                whiteSpace: 'pre-wrap',
                            }}>
                                {msg.text}
                            </div>
                        </div>
                    ))
                )}

                {loading && (
                    <div style={{ textAlign: 'left', padding: '8px 0' }}>
                        <Spin size="small" />
                        <Text style={{ color: mutedColor, fontSize: 12, marginLeft: 8 }}>
                            {t('assistant.thinking')}
                        </Text>
                    </div>
                )}
                <div ref={bottomRef} />
            </div>

            {/* Input */}
            <div style={{
                padding: '10px 16px',
                background: cardBg,
                borderTop: `1px solid ${isDark ? '#333355' : '#e5e7eb'}`,
                display: 'flex', gap: 8, alignItems: 'flex-end',
            }}>
                <Input.TextArea
                    value={input}
                    onChange={(e) => setInput(e.target.value)}
                    onPressEnter={(e) => {
                        if (!e.shiftKey) { e.preventDefault(); ask(input); }
                    }}
                    placeholder={t('assistant.placeholder')}
                    autoSize={{ minRows: 1, maxRows: 4 }}
                    disabled={loading}
                    style={{
                        flex: 1,
                        borderRadius: 10,
                        fontSize: 13,
                        background: isDark ? '#1a1a2e' : '#f3f4f6',
                        color: textColor,
                        border: `1px solid ${isDark ? '#333355' : '#d1d5db'}`,
                    }}
                    maxLength={500}
                />
                <Button
                    type="primary"
                    shape="circle"
                    icon={<SendOutlined />}
                    onClick={() => ask(input)}
                    disabled={!input.trim() || loading}
                    style={{ background: accent, borderColor: accent, flexShrink: 0 }}
                />
            </div>
        </div>
    );
};

export default Assistant;
