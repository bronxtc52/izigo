// T12 (mh-full-plan): секция меню веб-админки — возвраты/сторно V2 (owner+finance).
// Показ гейтится фиче-флагом mh_v2_refunds (deny-by-default, OFF в проде).
import React from 'react';
import RefundsV2View from './RefundsV2View';

const refundsV2Nav = {
    key: 'refunds-v2',
    label: 'Возвраты V2',
    roles: ['owner', 'finance'],
    flag: 'mh_v2_refunds',
    render: () => <RefundsV2View />,
};

export default refundsV2Nav;
