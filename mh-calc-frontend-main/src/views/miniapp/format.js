'use client';
// USDT-центы бэкенда (100 = 1 USDT) → строка с двумя знаками.
export const usd = (cents) => (Number(cents || 0) / 100).toFixed(2);
