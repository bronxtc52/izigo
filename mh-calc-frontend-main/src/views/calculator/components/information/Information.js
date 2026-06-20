'use client';
import React, { useState, useEffect } from 'react';
import { useGlobalContext } from '@/common/GlobalContext';
import { getData } from '@/common/utils/utils';
import { CSSTransition } from 'react-transition-group';
import css from './Information.module.scss';
import Translation from '@/common/translations/Translation';
import SvgGlobal from '@/project/icons/SvgGlobal';
import classnames from 'classnames';

const Information = ({ id, setId }) => {
    const { lang, currency, tokenStructure, userToken } = useGlobalContext();

    const [userData, setUserData] = useState(false);

    useEffect(() => {
        (async () => {
            if (!id) return;
            if (!tokenStructure) return;

            const dataNode = await getData(`/api/v1/calculator/structure/${tokenStructure}/details/${id}`, userToken, lang, currency);

            if (dataNode?.data) {
                setUserData(dataNode);
            };
        })();
    }, [id, lang, currency, tokenStructure, userToken]);

    return (
        <div>
            <CSSTransition
                in={userData ? true : false}
                timeout={300}
                classNames={{
                    enter: css.infoEnter,
                    enterActive: css.infoEnterActive,
                    exit: css.infoExit,
                    exitActive: css.infoExitActive
                }}
                unmountOnExit

            >
                <div className={css.wrapperModal}>
                    <div className={classnames(css.headerWrapper, css.paddingContent)}>
                        <div className={css.headerRow}>
                            <div className={css.headerTitle}>
                                <p className={css.headerTitle_text}>
                                    <Translation
                                        id={'infornation_user_title'}
                                        defaultStr={'Расчет бонусов для'}
                                    />
                                    {' - '}
                                    {userData?.data?.name || ''}
                                </p>
                            </div>
                            <div
                                className={css.headerClose}
                                onClick={() => {
                                    setUserData(false);
                                    setId(false);
                                }}
                            >
                                <SvgGlobal
                                    name={'cross'}
                                    dataStyles={{ className: css.headerCloseIcon }}
                                />
                            </div>
                        </div>
                    </div>
                    <div className={css.cotent}>
                        <div className={classnames('custom-scroll')} style={{ overflowY: 'auto', height: '100%' }}>

                            {userData?.data?.log?.length > 0
                                ? (
                                    <>
                                        {userData?.data?.log?.map((item, idx) => (
                                            <div
                                                key={`information_${idx}_${userData?.data?.id || 'full'}`}
                                                className={classnames(css.logItemWrapper, css.paddingContent)}
                                            >
                                                {item?.length ? (
                                                    <>
                                                        {item?.map((el, i) => (
                                                            <p
                                                                key={`information_${idx}_${i}`}
                                                                className={classnames(css.text_default, css.logItem_row)}
                                                            >
                                                                {el}
                                                            </p>
                                                        ))}
                                                    </>
                                                ) : null}
                                            </div>
                                        ))}
                                    </>
                                ) : null}
                        </div>
                    </div>
                </div>
            </CSSTransition>
        </div>
    )
};

export default Information;