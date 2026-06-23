// Block C — C4 i18n: секция меню веб-админки (owner-only). Редактор DB-оверрайдов переводов.
import React from 'react';
import Translations from '../Translations';

const i18nNav = {
    key: 'translations',
    label: 'Переводы',
    roles: ['owner'],
    flag: 'c4_i18n_admin', // показ гейтится фиче-флагом (deny-by-default)
    render: () => <Translations />,
};

export default i18nNav;
