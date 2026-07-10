import localFont from "next/font/local";
import { Manrope, Space_Grotesk } from "next/font/google";
import { headers } from "next/headers";
import "@/project/styles/globals.css";
import GlobalMiddleware from "@/middleware/GlobalLayout";

// Manrope — кирилличные заголовки/имена Mini App (есть кириллица). next/font self-host на билде.
const manrope = Manrope({
  subsets: ["latin", "cyrillic"],
  weight: ["500", "600", "700", "800"],
  variable: "--font-manrope",
  display: "swap",
});

// Space Grotesk — ТОЛЬКО числа (баланс/суммы/PV) в редизайне Aurora. Кириллицы НЕ содержит,
// поэтому к тексту не применяется (иначе ru/kk ломается на фолбэк) — см. tokens.js::balanceFont.
const spaceGrotesk = Space_Grotesk({
  subsets: ["latin"],
  weight: ["500", "600", "700"],
  variable: "--font-space-grotesk",
  display: "swap",
});

const geistSans = localFont({
  src: "./fonts/GeistVF.woff",
  variable: "--font-geist-sans",
  weight: "100 900",
});
const geistMono = localFont({
  src: "./fonts/GeistMonoVF.woff",
  variable: "--font-geist-mono",
  weight: "100 900",
});

const mullerFont = localFont({
  src: [
    {
      path: './fonts/muller/MullerRegular.woff2',
      weight: '400',
      style: 'normal',
    },
    {
      path: './fonts/muller/MullerMedium.woff2',
      weight: '500',
      style: 'normal',
    },
    {
      path: './fonts/muller/MullerBold.woff2',
      weight: '700',
      style: 'normal',
    },
  ],
  variable: "--font-muller",
})

export const metadata = {
  title: "IziGo",
  description: "IziGo",
};

export default async function RootLayout({ children }) {
  // G2 hardening: не грузим аналитику (GA + Яндекс.Метрика с webvisor) на веб-админке.
  // Webvisor пишет DOM-сессии — на admin.* это TON-адреса/суммы выводов, PII, KYC.
  // Админка живёт на отдельном хосте admin.* (прод) или на пути /admin; middleware
  // прокидывает x-pathname для случая одного хоста/localhost.
  const h = await headers(); // Next 15: request APIs асинхронны
  const host = h.get("host") || "";
  const pathname = h.get("x-pathname") || "";
  const isAdmin = host.startsWith("admin.") || pathname.startsWith("/admin");
  const isProduction = (process.env.NEXT_PUBLIC_SERVER_PROD || false) && !isAdmin;

  return (
    <html lang="en">
        {isProduction ? (
          <head>
            {/* Google Analytics */}
            <script
              async
              src="https://www.googletagmanager.com/gtag/js?id=G-RQTTQCGTSZ"
            ></script>
            <script
              dangerouslySetInnerHTML={{
                __html: `
              window.dataLayer = window.dataLayer || [];
              function gtag(){dataLayer.push(arguments);}
              gtag('js', new Date());
              gtag('config', 'G-RQTTQCGTSZ');
            `,
              }}
            />

            {/* Yandex.Metrika */}
            <script
              dangerouslySetInnerHTML={{
                __html: `
              (function(m,e,t,r,i,k,a){
                m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
                m[i].l=1*new Date();
                for (var j = 0; j < document.scripts.length; j++) {
                  if (document.scripts[j].src === r) { return; }
                }
                k=e.createElement(t),a=e.getElementsByTagName(t)[0],
                k.async=1,k.src=r,a.parentNode.insertBefore(k,a)
              })(window, document, "script", "https://mc.yandex.ru/metrika/tag.js", "ym");

              ym(99044373, "init", {
                clickmap:true,
                trackLinks:true,
                accurateTrackBounce:true,
                webvisor:true
              });
            `,
              }}
            />
            <noscript>
              <div>
                <img
                  src="https://mc.yandex.ru/watch/99044373"
                  style={{ position: 'absolute', left: '-9999px' }}
                  alt=""
                />
              </div>
            </noscript>
          </head>
        ) : null}
        <body className={`${geistSans.variable} ${geistMono.variable} ${mullerFont.variable} ${manrope.variable} ${spaceGrotesk.variable}`}>
          <GlobalMiddleware>
            {children}
          </GlobalMiddleware>
        </body>
      </html>
  );
};
