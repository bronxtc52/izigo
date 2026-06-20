'use client'
import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import kk from '../locales/kk/translation.json';
import ru from '../locales/ru/translation.json';
import az from '../locales/az/translation.json';
import ky from '../locales/ky/translation.json';
import uz from '../locales/uz/translation.json';
import mn from '../locales/mn/translation.json';


i18n
    .use(initReactI18next)
    .init({
        resources: {
            kk: { translation: kk },
            ru: { translation: ru },
            az: { translation: az },
            ky: { translation: ky },
            uz: { translation: uz },
            mn: { translation: mn }
        },
        fallbackLng: 'kk',
        supportedLngs: ['kk', 'ru', 'mn', 'uz', 'ky', 'az'],
        interpolation: {
            escapeValue: false,
        },
    });

export default i18n;