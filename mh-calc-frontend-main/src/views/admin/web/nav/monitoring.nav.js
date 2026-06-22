// Block C — C7 monitoring: секция меню веб-админки (owner-only, read-only).
import React from 'react';
import Monitoring from '../Monitoring';

const monitoringNav = {
    key: 'monitoring',
    label: 'Мониторинг',
    roles: ['owner'],
    render: () => <Monitoring />,
};

export default monitoringNav;
