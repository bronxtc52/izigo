// Block C — C3 feature_flags: секция меню веб-админки (owner-only).
import React from 'react';
import FeatureFlags from '../FeatureFlags';

const featureFlagsNav = {
    key: 'feature-flags',
    label: 'Фиче-флаги',
    roles: ['owner'],
    render: () => <FeatureFlags />,
};

export default featureFlagsNav;
