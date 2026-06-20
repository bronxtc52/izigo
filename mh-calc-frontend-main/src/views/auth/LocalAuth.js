'use client';
import React, { useState } from 'react';
import { Tabs, Form, Input, Button } from 'antd';
import { API_SERVER_URL, sender } from '@/common/utils/utils';
import { showNotification } from '@/common/notification';
import IziGoLogo from './IziGoLogo';
import css from './LocalAuth.module.scss';

/**
 * Локальная авторизация по email+паролю — единственный способ входа.
 * Показывается, когда нет сохранённого токена.
 */
const LocalAuth = ({ onSuccess, lang = false, currency = false }) => {
    const [loading, setLoading] = useState(false);

    const handle = (url, payload) => {
        setLoading(true);
        sender(
            `${API_SERVER_URL}${url}`,
            'POST',
            payload,
            (response) => {
                setLoading(false);
                const token = response?.data?.token;
                if (token) {
                    onSuccess(token);
                } else {
                    showNotification({ message: 'Не удалось получить токен', type: 'error' });
                }
            },
            (errors, status) => {
                setLoading(false);
                const message = errors?.message
                    || (errors?.errors && Object.values(errors.errors)[0]?.[0])
                    || (status === 401 ? 'Неверный email или пароль' : 'Ошибка авторизации');
                showNotification({ message, type: 'error' });
            },
            false,
            lang,
            currency,
        );
    };

    const onLogin = (values) => handle('/api/v1/auth/login', values);

    const onRegister = (values) => handle('/api/v1/auth/register', {
        email: values.email,
        password: values.password,
        password_confirmation: values.password_confirmation,
        first_name: values.first_name || null,
        last_name: values.last_name || null,
    });

    const items = [
        {
            key: 'login',
            label: 'Вход',
            children: (
                <Form layout="vertical" onFinish={onLogin} requiredMark={false}>
                    <Form.Item name="email" label="Email"
                        rules={[{ required: true, type: 'email', message: 'Введите корректный email' }]}>
                        <Input placeholder="you@example.com" size="large" />
                    </Form.Item>
                    <Form.Item name="password" label="Пароль"
                        rules={[{ required: true, message: 'Введите пароль' }]}>
                        <Input.Password placeholder="••••••" size="large" />
                    </Form.Item>
                    <Button type="primary" htmlType="submit" size="large" block loading={loading}>
                        Войти
                    </Button>
                </Form>
            ),
        },
        {
            key: 'register',
            label: 'Регистрация',
            children: (
                <Form layout="vertical" onFinish={onRegister} requiredMark={false}>
                    <Form.Item name="email" label="Email"
                        rules={[{ required: true, type: 'email', message: 'Введите корректный email' }]}>
                        <Input placeholder="you@example.com" size="large" />
                    </Form.Item>
                    <Form.Item name="first_name" label="Имя">
                        <Input placeholder="Имя" size="large" />
                    </Form.Item>
                    <Form.Item name="last_name" label="Фамилия">
                        <Input placeholder="Фамилия" size="large" />
                    </Form.Item>
                    <Form.Item name="password" label="Пароль"
                        rules={[{ required: true, min: 6, message: 'Минимум 6 символов' }]}>
                        <Input.Password placeholder="••••••" size="large" />
                    </Form.Item>
                    <Form.Item name="password_confirmation" label="Повторите пароль"
                        dependencies={['password']}
                        rules={[
                            { required: true, message: 'Повторите пароль' },
                            ({ getFieldValue }) => ({
                                validator(_, value) {
                                    if (!value || getFieldValue('password') === value) {
                                        return Promise.resolve();
                                    }
                                    return Promise.reject(new Error('Пароли не совпадают'));
                                },
                            }),
                        ]}>
                        <Input.Password placeholder="••••••" size="large" />
                    </Form.Item>
                    <Button type="primary" htmlType="submit" size="large" block loading={loading}>
                        Зарегистрироваться
                    </Button>
                </Form>
            ),
        },
    ];

    return (
        <div className={css.wrapper}>
            <div className={css.card}>
                <div className={css.logo}><IziGoLogo height={52} /></div>
                <p className={css.subtitle}>Калькулятор маркетинг-плана</p>
                <Tabs defaultActiveKey="login" items={items} centered />
            </div>
        </div>
    );
};

export default LocalAuth;
