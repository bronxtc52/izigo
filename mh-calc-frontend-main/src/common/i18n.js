'use client'
import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import kk from '../locales/kk/translation.json';
import ru from '../locales/ru/translation.json';
import az from '../locales/az/translation.json';
import ky from '../locales/ky/translation.json';
import uz from '../locales/uz/translation.json';
import mn from '../locales/mn/translation.json';
import en from '../locales/en/translation.json';
import { API_SERVER_URL } from '@/common/utils/utils';


i18n
    .use(initReactI18next)
    .init({
        resources: {
            kk: { translation: kk },
            ru: { translation: ru },
            az: { translation: az },
            ky: { translation: ky },
            uz: { translation: uz },
            mn: { translation: mn },
            en: { translation: en }
        },
        fallbackLng: 'kk',
        // en — второй основной язык Mini App (переключатель RU/EN в профиле). Витрина
        // продолжает работать на своих 6 языках (список с бэка /api/v1/locales, en там нет).
        supportedLngs: ['kk', 'ru', 'mn', 'uz', 'ky', 'az', 'en'],
        interpolation: {
            escapeValue: false,
        },
    });

// C4 (Block C): DB-оверрайды поверх статических locale-JSON. Sparse-карта locale→key→value
// тянется с бэка и подмешивается через addResourceBundle(..., deep, overwrite). Фолбэк
// строго graceful: при недоступном/пустом эндпоинте или ошибке — работаем на статике как
// раньше (нет белого экрана). Не ломаем существующую загрузку языков и переключатель —
// changeLanguage из GlobalContext продолжает работать, оверрайды уже лежат в bundle.
const applyTranslationOverrides = async () => {
    if (typeof window === 'undefined' || !API_SERVER_URL) return;
    try {
        const res = await fetch(`${API_SERVER_URL}/api/v1/i18n/overrides`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) return;
        const json = await res.json();
        const byLocale = json?.data;
        if (!byLocale || typeof byLocale !== 'object') return;

        Object.entries(byLocale).forEach(([locale, map]) => {
            if (!map || typeof map !== 'object') return;
            // Разворачиваем плоские dot-ключи ("a.b.c") в вложенный объект — i18next хранит
            // переводы деревом, addResourceBundle ждёт ту же структуру для глубокого мёржа.
            const tree = {};
            Object.entries(map).forEach(([flatKey, value]) => {
                const parts = String(flatKey).split('.');
                let node = tree;
                parts.forEach((part, idx) => {
                    if (idx === parts.length - 1) {
                        node[part] = value;
                    } else {
                        if (typeof node[part] !== 'object' || node[part] === null) node[part] = {};
                        node = node[part];
                    }
                });
            });
            // deep=true, overwrite=true: оверрайд побеждает статику, остальные ключи целы.
            i18n.addResourceBundle(locale, 'translation', tree, true, true);
        });
    } catch (e) {
        // graceful — остаёмся на статике
    }
};

applyTranslationOverrides();

// Повторное применение оверрайдов без релоада страницы — дёргается админ-редактором
// (Translations.js) после upsert/delete, чтобы правка перевода доезжала до UI сразу.
// Тоже graceful: при ошибке остаёмся на текущих ресурсах.
export const reloadTranslationOverrides = () => applyTranslationOverrides();

export default i18n;
