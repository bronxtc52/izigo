// mh-full-plan T14 — вкладка «Мой план V2» Mini App (счета ОС/НС/БС, прогресс 12
// статусов, тир, награды). Гейтится фиче-флагом mh_plan_v2_miniapp (deny-by-default,
// UI-флаг — НЕ движковый cutover T15). Регистрируется в blockCTabs (tabs/registry.js).
import React from 'react';
import { ProjectOutlined } from '@ant-design/icons';
import PlanV2Tab from '../planv2/PlanV2Tab';

const planv2Tab = {
    key: 'planv2',
    label: 'planv2.title', // i18n-ключ; MiniAppShell резолвит через t() при сборке таб-бара
    icon: <ProjectOutlined />,
    flag: 'mh_plan_v2_miniapp', // показ гейтится флагом (deny-by-default)
    render: (ctx) => (
        <PlanV2Tab
            initData={ctx.initData}
            pal={ctx.pal}
            isDark={ctx.isDark}
        />
    ),
};

export default planv2Tab;
