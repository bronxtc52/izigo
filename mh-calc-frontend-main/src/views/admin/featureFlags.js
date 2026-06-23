'use client';
import React from 'react';

// Block C (C3) — карта активных фиче-флагов веб-админки {key: enabled}, прокинутая
// от WebAdminShell вниз по дереву (Users/Genealogy → MemberCard) без prop-drilling
// через render-замыкания SECTIONS. Deny-by-default: дефолт — пустая карта {} (все
// флаговые блоки скрыты, пока шелл не загрузил флаги или при сбое запроса).
export const FeatureFlagsContext = React.createContext({});

/** Включён ли флаг по карте из контекста. Неизвестный/выключенный => false. */
export const useFeatureFlag = (key) => {
    const flags = React.useContext(FeatureFlagsContext);
    return flags?.[key] === true;
};
