'use client';
import React from 'react';
import { TonConnectUIProvider } from '@tonconnect/ui-react';
import MiniAppShell from '@/views/miniapp/MiniAppShell';

// Манифест отдаётся динамическим роутом /tonconnect-manifest.json от домена Mini App.
const manifestUrl = `${process.env.NEXT_PUBLIC_SERVER_FRONT_URL || ''}/tonconnect-manifest.json`;

export default function MiniAppPage() {
    return (
        <TonConnectUIProvider manifestUrl={manifestUrl}>
            <MiniAppShell />
        </TonConnectUIProvider>
    );
}
