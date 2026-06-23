'use client';
import React, { useEffect, useRef, useState } from 'react';

const MIN_MS = 600;   // минимум показа — чтобы не мигнуть на быстрой/кешированной загрузке
const FADE_MS = 450;  // длительность ухода (синхронно с CSS transition .mini-splash)

/**
 * Aurora-сплэш запуска Mini App. Висит, пока приложение грузится (active=true: !ready||loading),
 * затем плавно уходит (crossfade) и размонтируется. Чистый CSS/SVG, без зависимостей.
 * prefers-reduced-motion → статичный знак без анимаций (globals.css).
 * Цвета берём из палитры Aurora (scrbg/heroBg/balGrad), анимации — из globals.css.
 */
export default function SplashScreen({ active, pal }) {
    const [mounted, setMounted] = useState(active);
    const [leaving, setLeaving] = useState(false);
    const startRef = useRef(null);

    // Засекаем старт показа один раз (для минимальной длительности).
    if (startRef.current === null && active) startRef.current = performance.now();

    useEffect(() => {
        if (active) { setMounted(true); setLeaving(false); return; }
        if (!mounted) return;
        const elapsed = startRef.current ? performance.now() - startRef.current : MIN_MS;
        const wait = Math.max(0, MIN_MS - elapsed);
        const t1 = setTimeout(() => setLeaving(true), wait);          // запускаем fade-out
        const t2 = setTimeout(() => setMounted(false), wait + FADE_MS); // размонтируем после ухода
        return () => { clearTimeout(t1); clearTimeout(t2); };
    }, [active, mounted]);

    if (!mounted) return null;

    return (
        <div className={`mini-splash${leaving ? ' is-leaving' : ''}`} style={{ background: pal.scrbg }} aria-hidden="true">
            <div className="mini-splash__glow" style={{ background: pal.heroBg }} />
            <div className="mini-splash__mark" style={{ backgroundImage: pal.balGrad }}>IziGo</div>
            <div className="mini-splash__bar">
                <span className="mini-splash__bar-fill" style={{ background: pal.balGrad }} />
            </div>
        </div>
    );
}
