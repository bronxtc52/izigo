'use client';
import React, { useState } from 'react';
import { Segmented, Flex } from 'antd';
import { useTranslation } from 'react-i18next';
import StatusProgress from './StatusProgress';
import AccountsPanel from './AccountsPanel';
import AwardsPanel from './AwardsPanel';

/**
 * T14 — контейнер таба «Мой план V2» Mini App (за флагом mh_plan_v2_miniapp). Один таб
 * с внутренним Segmented-переключателем секций: Статус / Счета / Награды (не раздуваем
 * таб-бар тремя вкладками). Каждая секция сама грузит свои read-эндпоинты через initData.
 * RU/EN — через i18n. IDOR-safe: все данные строго свои (скоуп по initData на бэке).
 */
const PlanV2Tab = ({ initData, pal, isDark }) => {
    const { t } = useTranslation();
    const [section, setSection] = useState('status');

    const options = [
        { value: 'status', label: t('planv2.section_status') },
        { value: 'accounts', label: t('planv2.section_accounts') },
        { value: 'awards', label: t('planv2.section_awards') },
    ];

    return (
        <Flex vertical gap={10} style={{ padding: 4 }}>
            <Segmented block value={section} onChange={setSection} options={options} />
            {section === 'status' && <StatusProgress initData={initData} pal={pal} isDark={isDark} />}
            {section === 'accounts' && <AccountsPanel initData={initData} pal={pal} isDark={isDark} />}
            {section === 'awards' && <AwardsPanel initData={initData} pal={pal} isDark={isDark} />}
        </Flex>
    );
};

export default PlanV2Tab;
