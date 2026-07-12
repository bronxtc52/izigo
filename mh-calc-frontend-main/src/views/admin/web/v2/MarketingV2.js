'use client';
import React from 'react';
import { Tabs } from 'antd';
import { useTranslation } from 'react-i18next';
import PolicyVersions from './PolicyVersions';
import PeriodsV2 from './PeriodsV2';
import MemberAccountsV2 from './MemberAccountsV2';
import PoolReport from './PoolReport';
import RewardsQueue from './RewardsQueue';

/**
 * T13 — секция «План V2» веб-админки: одна пункт-меню-секция с вкладками вместо пяти
 * (меньше строк в registry). Потребляет read/action-эндпоинты задач-владельцев по
 * словарю MF-8; своих таблиц/имён не вводит. Вся секция гейтится флагом
 * mh_plan_v2_admin в nav; вкладки Пул/Награды дополнительно деградируют при своих
 * флагах (mh_v2_pool / mh_v2_awards) OFF в пустое состояние, не в ошибку.
 */
const MarketingV2 = () => {
    const { t } = useTranslation();
    const items = [
        { key: 'policy', label: t('mhV2.tabs.policy'), children: <PolicyVersions /> },
        { key: 'periods', label: t('mhV2.tabs.periods'), children: <PeriodsV2 /> },
        { key: 'accounts', label: t('mhV2.tabs.accounts'), children: <MemberAccountsV2 /> },
        { key: 'pool', label: t('mhV2.tabs.pool'), children: <PoolReport /> },
        { key: 'rewards', label: t('mhV2.tabs.rewards'), children: <RewardsQueue /> },
    ];
    return <Tabs defaultActiveKey="policy" items={items} destroyOnHidden />;
};

export default MarketingV2;
