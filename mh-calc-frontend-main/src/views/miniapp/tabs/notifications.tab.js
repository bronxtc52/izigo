// Block C — C1 notifications: вкладка-колокольчик inbox в Mini App.
import React from 'react';
import { BellOutlined } from '@ant-design/icons';
import NotificationInbox from '../NotificationInbox';

/**
 * Регистрируется в blockCTabs (tabs/registry.js). render получает контекст шелла
 * (initData/pal/isDark/wa/me/...). Бейдж непрочитанных — на самом табе через label
 * не выразить из реестра, поэтому inbox сам поднимает счётчик через onUnreadChange,
 * но базовый таб-бар показывает иконку всегда.
 */
const notificationsTab = {
    key: 'inbox',
    label: 'Уведомления',
    icon: <BellOutlined />,
    flag: 'c1_notifications', // показ гейтится фиче-флагом (deny-by-default)
    render: (ctx) => (
        <NotificationInbox
            initData={ctx.initData}
            pal={ctx.pal}
            isDark={ctx.isDark}
        />
    ),
};

export default notificationsTab;
