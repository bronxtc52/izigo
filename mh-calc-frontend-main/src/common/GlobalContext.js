'use client'
import React, {
    createContext,
    useState,
    useContext,
    useEffect
} from 'react';
import { getData } from './utils/utils';
import { useTranslation } from 'next-i18next';
import { usePathname } from 'next/navigation';
import LocalAuth from '@/views/auth/LocalAuth';

const GlobalContext = createContext();

export const GlobalContextProvider = ({ children }) => {
    const { i18n } = useTranslation();
    const pathname = usePathname();
    // Telegram Mini App авторизуется по initData, а не web-токеном — не гейтим его
    // формой входа LocalAuth.
    const isMiniApp = (pathname || '').startsWith('/miniapp');

    const [lang, setLang] = useState('kk');
    const [currency, setCurrency] = useState('kk'); // kk

    const [tokenViewStructure, setTokenViewStructure] = useState('');
    const [tokenStructure, setTokenStructure] = useState('');
    const [userToken, setUserToken] = useState(false);

    const [activeCurrency, setActiveCurrency] = useState(false);
    const [activeLangs, setActiveLangs] = useState(false);

    // Локальная авторизация (email+пароль): показывать форму, если нет токена
    const [showAuth, setShowAuth] = useState(false);
    // Пока авторизация не решена — не монтируем калькулятор (иначе мигание и
    // краш removeChild при сносе дерева d3-tree/antd при переключении на форму).
    const [authChecked, setAuthChecked] = useState(false);

    const onAuthSuccess = (token) => {
        if (!token) return;
        localStorage.setItem("userToken", token);
        setUserToken(token);
        setShowAuth(false);
    };

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

        const token = localStorage.getItem("userToken");
        if (token) {
            setUserToken(token);
        } else {
            // нет токена → локальная форма входа/регистрации (единственный вход)
            setShowAuth(true);
        }
        setAuthChecked(true);
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
            // user token
            userToken,
            setUserToken,
            // local auth
            showAuth,
            setShowAuth,
            onAuthSuccess
        }}>
            {isMiniApp
                ? children
                : (showAuth
                    ? <LocalAuth onSuccess={onAuthSuccess} lang={lang} currency={currency} />
                    : (authChecked ? children : null))}
        </GlobalContext.Provider>
    );
};

export const useGlobalContext = () => {
    return useContext(GlobalContext);
};