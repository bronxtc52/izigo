'use client';
import React from 'react';
import { TonConnectUIProvider } from '@tonconnect/ui-react';
import MiniAppShell from '@/views/miniapp/MiniAppShell';

// Манифест отдаётся динамическим роутом /tonconnect-manifest.json от домена Mini App.
const manifestUrl = `${process.env.NEXT_PUBLIC_SERVER_FRONT_URL || ''}/tonconnect-manifest.json`;

// Внутри Telegram Mini App (TWA) TonConnect должен возвращать в Mini App после оплаты в
// кошельке. Без twaReturnUrl SDK рисует web-overlay и не приоритизирует Telegram Wallet —
// кошелёк оказывается «за окном покупки» и недоступен. Значение запекается при сборке.
const twaReturnUrl = process.env.NEXT_PUBLIC_TWA_RETURN_URL || 'https://t.me/Izigopro_mlm_bot';

export default function MiniAppPage() {
    return (
        <TonConnectUIProvider
            manifestUrl={manifestUrl}
            actionsConfiguration={{ twaReturnUrl, returnStrategy: 'back' }}
        >
            <MiniAppShell />
        </TonConnectUIProvider>
    );
}
