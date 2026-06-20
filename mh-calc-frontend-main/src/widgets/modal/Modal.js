import React, { useState, useEffect, useRef } from 'react';
import css from './Modal.module.scss';
import classnames from 'classnames';
import SvgGlobal from '@/project/icons/SvgGlobal';
import { CSSTransition } from 'react-transition-group';

const Modal = ({ isOpen, onClose, children, title = false, componentCss = {}, }) => {
    const [enterModal, setEnterModal] = useState(false);

    const handleBackdropClick = (e) => {
        if (e.target === e.currentTarget) {
            onClose();
        };
    };

    useEffect(() => {
        setEnterModal(true);
    }, []);

    return (
        <CSSTransition
            in={isOpen}
            timeout={100}
            classNames={{
                enter: css.modalOverlayEnter,
                enterActive: css.modalOverlayEnterActive,
                exit: css.modalOverlayExit,
                exitActive: css.modalOverlayExitActive,
            }}
            unmountOnExit
            onEnter={() => setEnterModal(true)}
            onExit={() => setEnterModal(false)}
        >
            <div
                className={css.modalBackdrop}
                onClick={handleBackdropClick}
            >
                <CSSTransition
                    in={enterModal}
                    timeout={300}
                    classNames={{
                        enter: css.modalEnter,
                        enterActive: css.modalEnterActive,
                        exit: css.modalExit,
                        exitActive: css.modalExitActive,
                    }}
                    unmountOnExit
                >
                    <div
                        className={classnames(css.modalContent, componentCss?.modalLangCurrencyWrap || {})}
                    >
                        <div className={css.modalClose} onClick={() => onClose(pre => !pre)}>
                            <SvgGlobal
                                name={'cross'}
                                dataStyles={{ className: css.modalCloseSvg }}
                            />
                        </div>
                        {title ? (
                            <div className={classnames(css.modalContent__padding, css.modalContent__top)}>
                                <p className={css.modalContent__title}>
                                    {title}
                                </p>
                            </div>
                        ) : null}
                        <div className={classnames(css.modalContent__padding, css.modalContent__bottom)}>
                            <div className={classnames(css.modalContent__scroll, 'custom-scroll')}>
                                {children}
                            </div>
                        </div>
                    </div>
                </CSSTransition>
            </div>
        </CSSTransition>
    );
};

export default Modal;
