/** @type {import('next').NextConfig} */

const nextConfig = {
    output: 'standalone', // для Docker/ACA (Фаза 0)
    i18n: {
        defaultLocale: 'kk',
        locales: ['kk', 'ru', 'mn', 'uz', 'ky', 'az'],
        localeDetection: false
    }
};

export default nextConfig;
