// AI-ассистент партнёра — вкладка Mini App (Block C pattern).
import React from 'react';
import { RobotOutlined } from '@ant-design/icons';
import Assistant from '../Assistant';

const assistantTab = {
    key: 'assistant',
    label: 'assistant.title', // i18n-ключ; MiniAppShell резолвит через t()
    icon: <RobotOutlined />,
    flag: 'ai_assistant', // deny-by-default; бэкенд тоже проверяет флаг
    render: (ctx) => (
        <Assistant
            initData={ctx.initData}
            pal={ctx.pal}
            isDark={ctx.isDark}
        />
    ),
};

export default assistantTab;
