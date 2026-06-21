import Script from 'next/script';

export const metadata = {
    title: 'IziGo · Telegram',
};

export default function MiniAppRouteLayout({ children }) {
    return (
        <>
            <Script src="https://telegram.org/js/telegram-web-app.js" strategy="afterInteractive" />
            {children}
        </>
    );
}
