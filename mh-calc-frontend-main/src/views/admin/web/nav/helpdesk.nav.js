// Block C — C2 helpdesk: секция меню веб-админки (тикеты поддержки, owner+support).
import React from 'react';
import Helpdesk from '../Helpdesk';

const helpdeskNav = {
    key: 'helpdesk',
    label: 'Поддержка',
    roles: ['owner', 'support'],
    render: () => <Helpdesk />,
};

export default helpdeskNav;
