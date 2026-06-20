import localFont from "next/font/local";
import "@/project/styles/globals.css";
import GlobalMiddleware from "@/middleware/GlobalLayout";

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

export default function RootLayout({ children }) {
  const isProduction = process.env.NEXT_PUBLIC_SERVER_PROD || false;

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
        <body className={`${geistSans.variable} ${geistMono.variable} ${mullerFont.variable}`}>
          <GlobalMiddleware>
            {children}
          </GlobalMiddleware>
        </body>
      </html>
  );
};
