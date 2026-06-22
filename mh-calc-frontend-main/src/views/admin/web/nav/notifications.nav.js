// Block C — C1 notifications: секция меню веб-админки (рассылки, owner+support).
import React from 'react';
import Broadcasts from '../Broadcasts';

const notificationsNav = {
    key: 'broadcasts',
    label: 'Рассылки',
    roles: ['owner', 'support'],
    render: () => <Broadcasts />,
};

export default notificationsNav;
