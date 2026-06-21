'use client';
import React from 'react';
import RedirectToMiniApp from '@/views/miniapp/RedirectToMiniApp';

// Браузерный /cabinet вне Telegram → редирект на /miniapp. Кабинет живёт в Mini App.
export default function Layout() {
    return <RedirectToMiniApp />;
}
