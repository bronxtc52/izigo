import React from 'react';

/**
 * Логотип IziGo: значок (squircle + «go»-шеврон) + вордмарк «izigo».
 * Inline-SVG, чтобы id градиентов были изолированы.
 */
const IziGoLogo = ({ height = 48 }) => (
    <svg height={height} viewBox="0 0 200 64" fill="none" xmlns="http://www.w3.org/2000/svg"
        role="img" aria-label="IziGo">
        <defs>
            <linearGradient id="iziLogoBg" x1="0" y1="0" x2="64" y2="64" gradientUnits="userSpaceOnUse">
                <stop stopColor="#2DD4BF" />
                <stop offset="1" stopColor="#4F46E5" />
            </linearGradient>
            <linearGradient id="iziLogoText" x1="120" y1="20" x2="200" y2="48" gradientUnits="userSpaceOnUse">
                <stop stopColor="#2DD4BF" />
                <stop offset="1" stopColor="#4F46E5" />
            </linearGradient>
        </defs>

        {/* значок */}
        <rect x="2" y="2" width="60" height="60" rx="17" fill="url(#iziLogoBg)" />
        <path d="M19 19 L33 32 L19 45" stroke="#ffffff" strokeWidth="7" strokeLinecap="round" strokeLinejoin="round" />
        <path d="M33 19 L47 32 L33 45" stroke="#ffffff" strokeWidth="7" strokeLinecap="round" strokeLinejoin="round" opacity="0.6" />

        {/* вордмарк */}
        <text x="74" y="44"
            style={{ fontFamily: "var(--font-muller), system-ui, -apple-system, 'Segoe UI', sans-serif" }}
            fontSize="38" fontWeight="800" letterSpacing="-1">
            <tspan fill="#111827">izi</tspan><tspan fill="url(#iziLogoText)">go</tspan>
        </text>
    </svg>
);

export default IziGoLogo;
