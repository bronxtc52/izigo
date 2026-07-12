// mh-full-plan T13 — секция меню веб-админки «План V2» (маркетинг-политика V2).
// Read — owner+finance; mutation внутри вкладок — owner-only (RBAC на бэке, кнопки
// скрыты не-owner). Показ гейтится фиче-флагом mh_plan_v2_admin (deny-by-default);
// awards/pool-вкладки дополнительно за mh_v2_awards / mh_v2_pool (деградация внутри).
import React from 'react';
import MarketingV2 from '../v2/MarketingV2';

const mhV2Nav = {
    key: 'mh-v2',
    label: 'План V2',
    roles: ['owner', 'finance'],
    flag: 'mh_plan_v2_admin',
    render: () => <MarketingV2 />,
};

export default mhV2Nav;
