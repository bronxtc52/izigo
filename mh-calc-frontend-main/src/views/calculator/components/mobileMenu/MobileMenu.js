import React from 'react';
import css from './MobileMenu.module.scss';
import LanguageCurrencySwitch from '@/widgets/LanguageCurrencySwitch/LanguageCurrencySwitch';
import SvgGlobal from '@/project/icons/SvgGlobal';
import classnames from 'classnames';
import { getFaqDataMobile, getMenuItem } from '../initData';
import FaqItem from '../faqItem/FaqItem';
import { useGlobalContext } from '@/common/GlobalContext';

// ref прокидывается пропом (React 19: ref-as-prop, forwardRef не нужен) — его требует
// nodeRef у CSSTransition в Header (React 19 удалил findDOMNode).
const MobileMenu = ({ closeMenu, currency = false, ref }) => {
    const globalContext = useGlobalContext();
    const faqData = getFaqDataMobile(css, currency);

    const menuList = getMenuItem(currency?.currency, globalContext?.lang);

    return (
        <div ref={ref} className={css.wrapper}>
            <div className={css.content}>
                <div className={css.contentTop}>
                    <div
                        className={classnames(css.contentTopBtn, css.closeIcon)}
                        onClick={() => closeMenu(false)}
                    >
                        <SvgGlobal
                            name={'arrow-small-left'}
                            dataStyles={{
                                className: css.closeIconSvg
                            }}
                        />
                    </div>
                    <div className={css.contentTopBtn}>
                        <LanguageCurrencySwitch />
                    </div>
                </div>
                <div className={css.contentBottom}>
                    <div className={classnames(css.contentBottomScroll, 'custom-scroll')}>
                        {menuList?.length && menuList?.map((item, idx) => (
                            <FaqItem
                                key={`menu_item_mobile_${idx}`}
                                data={item}
                                type={'link'}
                            />
                        ))}
                        {faqData?.map((item, idx) => (
                            <FaqItem
                                key={`faq_item_mobile_${idx}`}
                                data={item}
                            />
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
};

export default MobileMenu;