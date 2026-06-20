'use client';
import React from 'react';
import { GlobalContextProvider } from "@/common/GlobalContext";
import { I18nextProvider } from 'react-i18next';
import i18n from "@/common/i18n";

const GlobalMiddleware = ({ children }) => {
    return (
        <I18nextProvider i18n={i18n}>
            <GlobalContextProvider>
                <>
                    {children}
                </>
            </GlobalContextProvider>
        </I18nextProvider>
    );
};

export default GlobalMiddleware;