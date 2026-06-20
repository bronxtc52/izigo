/** @type {import('next').NextConfig} */

const nextConfig = {
    i18n: {
        defaultLocale: 'kk',
        locales: ['kk', 'ru', 'mn', 'uz', 'ky', 'az'],
        localeDetection: false
    }
};

export default nextConfig;
