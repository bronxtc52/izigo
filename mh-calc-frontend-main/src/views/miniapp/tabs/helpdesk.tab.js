// Block C — C2 helpdesk: вкладка «Поддержка» в Mini App.
import React from 'react';
import { CustomerServiceOutlined } from '@ant-design/icons';
import Helpdesk from '../Helpdesk';

/**
 * Регистрируется в blockCTabs (tabs/registry.js). render получает контекст шелла
 * (initData/pal/isDark/wa/me/...). Тикеты + чат с polling — внутри Helpdesk.
 */
const helpdeskTab = {
    key: 'helpdesk',
    label: 'Поддержка',
    icon: <CustomerServiceOutlined />,
    render: (ctx) => (
        <Helpdesk
            initData={ctx.initData}
            pal={ctx.pal}
            isDark={ctx.isDark}
        />
    ),
};

export default helpdeskTab;
