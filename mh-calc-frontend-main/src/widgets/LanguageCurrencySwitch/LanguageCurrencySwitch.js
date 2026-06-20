'use client'
import React, { useEffect, useState } from 'react';
import css from './LanguageCurrencySwitch.module.scss';
import SvgGlobal from '@/project/icons/SvgGlobal';
import Modal from '../modal/Modal';
import Translation from '@/common/translations/Translation';
import { useGlobalContext } from '@/common/GlobalContext';
import FormSelect from '../form/components/select/FormSelect';
import Button from '@/widgets/button/Button';

const LanguageCurrencySwitch = () => {
    const {
        activeCurrency,
        activeLangs,
        currency,
        lang,
        changeLocalization
    } = useGlobalContext();

    const [showModal, setShowModal] = useState(false);
    const [formData, setFormData] = useState({ lang, currency });

    const [currencyOptions, setCurrencyOptions] = useState([]);
    const [langOptions, setLangOptions] = useState([]);

    useEffect(() => {
        const localization = localStorage.getItem('localization');

        if (!localization) {
            setShowModal(true);
        };
    }, []);

    useEffect(() => {
        if (lang && currency) {
            setFormData({ lang, currency });
        };
    }, [lang, currency])

    useEffect(() => {
        if (activeCurrency?.length) {
            const options = activeCurrency.map(item => ({ label: String(item?.name), value: String(item?.code) }));
            setCurrencyOptions(options || []);
        };
    }, [activeCurrency]);

    useEffect(() => {
        if (activeLangs?.length) {
            const options = activeLangs.map(item => ({ label: String(item?.name), value: String(item?.code) }));
            setLangOptions(options || []);
        };
    }, [activeLangs]);

    const setFromData = (key, val) => {
        setFormData(pre => ({ ...pre, [key]: val }))
    };

    const applySettings = (data) => {
        if (data?.lang && data?.currency) {
            changeLocalization(data);
            setShowModal(pre => !pre);
        };
    };

    return (
        <div>
            <div
                className={css.wrapperBtn}
                onClick={() => setShowModal(pre => !pre)}
            >
                <SvgGlobal
                    name={'localization-icon'}
                    dataStyles={{ className: css.localizationIconSvg }}
                />
            </div>
            <Modal
                isOpen={showModal}
                onClose={setShowModal}
                componentCss={css}
                title={
                    <Translation id={'lang_currency_modal_title'} defaultStr={'Настройки'} />
                }
            >
                <div className={css.modalWrapper}>
                    <div>
                        <FormSelect
                            attribute={'lang'}
                            options={langOptions}
                            setFormData={(a, v) => setFromData(a, v)}
                            title={(
                                <Translation id={'lang'} defaultStr={'Язык'} />
                            )}
                            value={formData?.lang || false}
                        />
                    </div>
                    <div>
                        <FormSelect
                            attribute={'currency'}
                            options={currencyOptions}
                            setFormData={(a, v) => setFromData(a, v)}
                            title={(
                                <Translation id={'currency'} defaultStr={'Валюта'} />
                            )}
                            value={formData?.currency || false}
                        />
                    </div>
                    <div className={css.modalBtnWrap}>
                        <div>
                            <Button
                                title={<Translation id={'save'} defaultStr={'Сохранить'} />}
                                clickFunc={() => applySettings(formData)}
                            />
                        </div>
                    </div>
                </div>
            </Modal>
        </div>
    );
};

export default LanguageCurrencySwitch;