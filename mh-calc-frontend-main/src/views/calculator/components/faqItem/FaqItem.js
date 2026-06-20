import React, { useState } from 'react';
import css from './FaqItem.module.scss';
import { CSSTransition } from 'react-transition-group';
import SvgGlobal from '@/project/icons/SvgGlobal';
import classNames from 'classnames';
import Link from 'next/link';

const FaqItem = ({ data, type = false }) => {
    const [showAnswer, setShowAnswer] = useState(false);

    return type === 'link' ? (
        <div className={css.wrapper}>
            <Link
                href={data?.link}
                className={css.top}
            >
                <div className={css.topTitle}>
                    <p className={css.topTitle_text}>
                        {data.title}
                    </p>
                </div>
                <div className={css.topArrow}>
                    <SvgGlobal
                        name={'arrow-small-down'}
                        dataStyles={{
                            className: classNames(css.topArrowSvg, {
                                [css.topArrowSvg_link]: true
                            })
                        }}
                    />
                </div>
            </Link>
        </div >
    ) : (
        <div className={css.wrapper}>
            <div
                className={css.top}
                onClick={() => setShowAnswer(pre => !pre)}
            >
                <div className={css.topTitle}>
                    <p className={css.topTitle_text}>
                        {data.title}
                    </p>
                </div>
                <div className={css.topArrow}>
                    <SvgGlobal
                        name={'arrow-small-down'}
                        dataStyles={{
                            className: classNames(css.topArrowSvg, {
                                [css.topArrowSvg_active]: showAnswer
                            })
                        }}
                    />
                </div>
            </div>
            <CSSTransition
                in={showAnswer}
                timeout={300}
                classNames={{
                    enter: css.answerEnter,
                    enterActive: css.answerEnterActive,
                    exit: css.answerExit,
                    exitActive: css.answerExitActive,
                }}
                unmountOnExit
            >
                <div className={css.answerWrapper}>
                    {data.component}
                </div>
            </CSSTransition>
        </div>
    );
};

export default FaqItem;