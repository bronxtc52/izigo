'use client';
import React, { useState, useEffect, useRef } from 'react';
import css from './Header.module.scss';
import classnames from 'classnames';
import LanguageCurrencySwitch from '@/widgets/LanguageCurrencySwitch/LanguageCurrencySwitch';
import Translation from '@/common/translations/Translation';
import Instructions from '../Instructions/Instructions';
import { useWindowDimensions } from '@/common/utils/windowDimensions';
import SvgGlobal from '@/project/icons/SvgGlobal';
import { getDataHeader } from '../initData';
import { CSSTransition } from 'react-transition-group';
import MobileMenu from '../mobileMenu/MobileMenu';

const Header = ({
    data = false,
    rootData = false,
    refreshStructure,
    currency = false,
    setShowShareModal,
    setShowPackagesModal,
    handleTop
}) => {
    const windowDimensions = useWindowDimensions();
    const [mobile, setMobile] = useState(false);
    const [showDataHeader, setShowDataHeader] = useState(false);
    const [showMenuMob, setShowMenuMob] = useState(false);
    // React 19 удалил findDOMNode — react-transition-group без nodeRef падает в рантайме.
    const overlayRef = useRef(null);
    const mobileMenuRef = useRef(null);

    useEffect(() => {
        if (windowDimensions <= 1170) {
            setMobile(true)
        } else {
            setMobile(false)
        };
    }, [windowDimensions]);

    const dataHeader = getDataHeader(rootData, currency);

    return (
        <>
            <div className={css.wrapper}>
                <div className={classnames(css.headerWrapper, {
                    [css.showDataHeader_height_auto]: showDataHeader && mobile
                })}>
                    <div className={css.headerRow}>
                        <div className={css.headerRowItem_left}>
                            <div className={css.item}>
                                <div className={css.itemContent}>
                                    <div className={css.itemContentTop}>
                                        {mobile ? (
                                            <div
                                                className={css.menuIconWrapper}
                                                onClick={() => setShowMenuMob(pre => !pre)}
                                            >
                                                <SvgGlobal
                                                    name={'menu-icon'}
                                                    dataStyles={{ className: css.menuIconSvg }}
                                                />
                                            </div>
                                        ) : null}
                                        <p className={css.itemContentTop_title}>
                                            <Translation
                                                id={'total_profit_structure'}
                                                defaultStr={'Итоговая прибыль'}
                                            />
                                            {' '}
                                            {`, ${currency?.currency || ''}`}
                                        </p>
                                        <span className={css.itemContentTop_value}>
                                            {data?.profit_by_last_node_format
                                                ? `+ ${data?.profit_by_last_node_format.replace(/\s?[A-Z]{1,5}$/, '')}`
                                                : ''
                                            }
                                        </span>
                                    </div>
                                    <div className={css.itemContentBottom}>
                                        <span className={classnames(css.itemContentBottom_value, css.itemContentBottom_value_l)}>
                                            {rootData?.all_bonus_sum_format
                                                ? rootData?.all_bonus_sum_format.replace(/\s?[A-Z]{1,5}$/, '')
                                                : '0.00'
                                            }
                                        </span>
                                    </div>
                                </div>
                            </div>
                            {!mobile ? (
                                <>
                                    {dataHeader?.map((item, idx) => (
                                        <div className={css.item} key={`data_header_desktop_${idx}`}>
                                            <div className={css.itemContent}>
                                                <div className={css.itemContentTop}>
                                                    <p className={css.itemContentTop_title}>
                                                        {item?.title || ''}
                                                    </p>
                                                </div>
                                                <div className={css.itemContentBottom}>
                                                    <span className={css.itemContentBottom_value}>
                                                        {item.value}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </>
                            ) : null}
                        </div>
                        {!mobile ? (
                            <div className={css.headerRowItem_right}>
                                <div className={classnames(css.item, css.instructionsWrapper)}>
                                    <Instructions currency={currency} />
                                </div>
                                <div className={classnames(css.item, css.langCurrencyWrapper)}>
                                    <LanguageCurrencySwitch />
                                </div>
                            </div>
                        ) : null}
                    </div>
                    {showDataHeader && mobile ? (
                        <div className={css.headerMobileData_wrapper}>
                            {dataHeader?.map((item, idx) => (
                                <div className={css.headerMobileData_row} key={`data_mobile_${idx}`}>
                                    <div className={css.headerMobileData_row_l}>
                                        <p className={css.headerMobileData_title}>
                                            {item?.title || ''}
                                        </p>
                                    </div>
                                    <div className={css.headerMobileData_row_r}>
                                        <span className={css.headerMobileData_value}>
                                            {item.value}
                                        </span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : null}
                    {mobile ? (
                        <div className={css.headerMobileBtn_position}>
                            <div className={css.headerMobileBtn}>
                                <div
                                    className={css.headerMobileBtn_item}
                                    onClick={() => refreshStructure()}
                                >
                                    <SvgGlobal
                                        name={'clear'}
                                        dataStyles={{ className: css.headerMobileBtnItem_svg }}
                                    />
                                </div>
                                <div
                                    className={css.headerMobileBtn_item}
                                    onClick={() => setShowPackagesModal(pre => !pre)}
                                >
                                    <SvgGlobal
                                        name={'briefcase'}
                                        dataStyles={{ className: css.headerMobileBtnItem_svg }}
                                    />
                                </div>
                                <div
                                    className={css.headerMobileBtn_item}
                                    onClick={() => setShowShareModal(pre => !pre)}
                                >
                                    <SvgGlobal name={'share'} dataStyles={{ className: css.headerMobileBtnItem_svg }} />
                                </div>
                                <div
                                    className={css.headerMobileBtn_item}
                                    onClick={() => setShowDataHeader(pre => !pre)}
                                >
                                    <SvgGlobal
                                        name={'arrow-small-down'}
                                        dataStyles={{
                                            className: classnames(css.headerMobileBtnItem_svg, {
                                                [css.headerMobileBtnItem_svg_rotate]: showDataHeader
                                            })
                                        }}
                                    />
                                </div>
                                <div
                                    className={css.headerMobileBtn_item}
                                    onClick={() => handleTop()}
                                >
                                    <SvgGlobal
                                        name={'arrow-top'}
                                        dataStyles={{ className: css.headerMobileBtnItem_svg }}
                                    />
                                </div>
                            </div>
                        </div>
                    ) : null}
                </div>
                <CSSTransition
                    nodeRef={overlayRef}
                    in={showDataHeader && mobile ? true : false}
                    timeout={100}
                    classNames={{
                        enter: css.headerOverlayEnter,
                        enterActive: css.headerOverlayEnterActive,
                        exit: css.headerOverlayExit,
                        exitActive: css.headerOverlayExitActive,
                    }}
                    unmountOnExit
                >
                    <div ref={overlayRef} className={css.headerOverlayBackdrop} />
                </CSSTransition>
            </div>
            <CSSTransition
                nodeRef={mobileMenuRef}
                in={showMenuMob && mobile ? true : false}
                timeout={300}
                classNames={{
                    enter: css.menuEnter,
                    enterActive: css.menuEnterActive,
                    exit: css.menuExit,
                    exitActive: css.menuExitActive,
                }}
                unmountOnExit
            >
                <MobileMenu
                    ref={mobileMenuRef}
                    closeMenu={setShowMenuMob}
                    currency={currency}
                />
            </CSSTransition>
        </>
    );
};

export default Header; 
