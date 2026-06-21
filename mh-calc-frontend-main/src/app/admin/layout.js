'use client';
import React from 'react';
import RedirectToMiniApp from '@/views/miniapp/RedirectToMiniApp';

// Браузерный /admin вне Telegram → редирект на /miniapp. Админка живёт в Mini App.
export default function Layout() {
    return <RedirectToMiniApp />;
}
