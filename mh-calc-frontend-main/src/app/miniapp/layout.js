import Script from 'next/script';

export const metadata = {
    title: 'IziGo · Telegram',
};

export default function MiniAppRouteLayout({ children }) {
    return (
        <>
            <Script src="https://telegram.org/js/telegram-web-app.js" strategy="afterInteractive" />
            {/* miniapp-bg — фон Aurora с первого кадра (до монтирования React), без белой вспышки. */}
            <div className="miniapp-bg">{children}</div>
        </>
    );
}
