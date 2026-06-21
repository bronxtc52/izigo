'use client'
import React, {
    createContext,
    useState,
    useContext,
    useEffect
} from 'react';
import { getData } from './utils/utils';
import { useTranslation } from 'next-i18next';

const GlobalContext = createContext();

export const GlobalContextProvider = ({ children }) => {
    const { i18n } = useTranslation();

    const [lang, setLang] = useState('kk');
    const [currency, setCurrency] = useState('kk'); // kk

    const [tokenViewStructure, setTokenViewStructure] = useState('');
    const [tokenStructure, setTokenStructure] = useState('');
    // userToken оставлен ИСКЛЮЧИТЕЛЬНО для анонимного публичного калькулятора-витрины
    // (src/views/calculator/*). Платформа (кабинет/админка) авторизуется ТОЛЬКО
    // через Telegram Mini App (initData), email-входа больше нет.
    const [userToken, setUserToken] = useState(false);

    const [activeCurrency, setActiveCurrency] = useState(false);
    const [activeLangs, setActiveLangs] = useState(false);

    const changeTokenStructure = (token) => {
        if (!token) return;

        localStorage.setItem('token_structure', token);
        setTokenStructure(token);
    };

    const changeLocalization = (data) => {
        if (!data?.lang && !data?.currency) return;


        localStorage.setItem('localization', JSON.stringify(data));

        i18n.changeLanguage(data?.lang || 'kk');
        setLang(data?.lang);
        setCurrency(data?.currency);
    };

    useEffect(() => {
        if (typeof window === 'undefined') return;

        // Токен витрины-калькулятора (анонимный инструмент), если есть в localStorage.
        const token = localStorage.getItem("userToken");
        if (token) setUserToken(token);
    }, []);

    useEffect(() => {
        const storegeToken = localStorage.getItem('token_structure') || '';
        setTokenStructure(storegeToken);

        const storegeLocalization = localStorage.getItem('localization') || false;

        if (storegeLocalization) {
            const data = JSON.parse(storegeLocalization);

            i18n.changeLanguage(data?.lang || 'kk');
            setLang(data?.lang);
            setCurrency(data?.currency);
        };
    }, []);

    useEffect(() => {
        (async () => {
            const response = await getData('/api/v1/locales', false, lang, currency);

            if (response?.data) {
                const currencyFilter = [];
                const langFilter = [];

                Object.keys(response?.data).map(item => {
                    currencyFilter.push({ name: response?.data[item]?.currency, code: response?.data[item]?.code });
                    langFilter.push({ name: response?.data[item]?.language, code: response?.data[item]?.code });
                });

                setActiveLangs(langFilter || false);
                setActiveCurrency(currencyFilter || false)
            };
        })();
    }, [lang, currency]);

    return (
        <GlobalContext.Provider value={{
            lang,
            setLang,
            currency,
            setCurrency,
            // start token structure
            tokenViewStructure,
            setTokenViewStructure,
            tokenStructure,
            changeTokenStructure,
            // end token structure
            activeCurrency,
            activeLangs,
            changeLocalization,
            // токен витрины-калькулятора (анонимный, НЕ авторизация платформы)
            userToken,
            setUserToken
        }}>
            {children}
        </GlobalContext.Provider>
    );
};

export const useGlobalContext = () => {
    return useContext(GlobalContext);
};
