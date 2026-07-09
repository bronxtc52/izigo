'use client';
import React from 'react';
import { useTranslation } from 'react-i18next';

const Translation = ({ id, defaultStr = '' }) => {
    const { t } = useTranslation(); 

    return (
        <React.Fragment key={`${id}_translation`}>
            {t(id)}
        </React.Fragment>
    );
};

export default Translation;