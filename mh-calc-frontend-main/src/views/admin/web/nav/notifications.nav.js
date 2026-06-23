// Block C — C1 notifications: секция меню веб-админки (рассылки, owner+support).
import React from 'react';
import Broadcasts from '../Broadcasts';

const notificationsNav = {
    key: 'broadcasts',
    label: 'Рассылки',
    roles: ['owner', 'support'],
    flag: 'c1_notifications', // показ гейтится фиче-флагом (deny-by-default)
    render: () => <Broadcasts />,
};

export default notificationsNav;
