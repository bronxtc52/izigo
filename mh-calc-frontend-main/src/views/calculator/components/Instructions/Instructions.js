import React, { useState } from 'react';
import css from './Instructions.module.scss';
import Translation from '@/common/translations/Translation';
import SvgGlobal from '@/project/icons/SvgGlobal';
import Modal from '@/widgets/modal/Modal';
import { getLinkPremiumPlan } from "../initData";
import { useGlobalContext } from '@/common/GlobalContext';
import { MAIN_PROJECT } from '@/common/utils/utils';

const Instructions = ({ currency = false }) => {
    const globalContext = useGlobalContext();
    const [howUseCalculator, setHowUseCalculator] = useState(false);
    const [discountMh, setDiscountMh] = useState(false);
    const [globalBonus, setGlobalBonus] = useState(false);

    const handlerPremiumPlan = () => {
        const link = getLinkPremiumPlan(currency?.currency || globalContext?.currency, globalContext?.lang);

        if (typeof window !== 'undefined') {
            window.location.href = link;
        };
    };

    const handlerPersonalAccount = () => {
        if (typeof window !== 'undefined') {
            window.location.href = MAIN_PROJECT;
        };
    }

    return (
        <>
            <div className={css.instructionsWrapper}>
                <div
                    className={css.instructionsItem}
                    onClick={() => setHowUseCalculator(pre => !pre)}
                >
                    <div className={css.instructionsItem__content}>
                        <Translation
                            id={'how_use_calculator'}
                            defaultStr={'Как использовать калькулятор'}
                        />
                        <span className={css.instructionsItem__icon}>
                            <SvgGlobal
                                name={'hint-icon'}
                                dataStyles={{ className: css.instructionsItem__svg }}
                            />
                        </span>
                    </div>
                </div>
                <div
                    className={css.instructionsItem}
                    onClick={() => setDiscountMh(pre => !pre)}
                >
                    <div className={css.instructionsItem__content}>
                        <Translation
                            id={'discount_mh'}
                            defaultStr={'Скидка МН'}
                        />
                        <span className={css.instructionsItem__icon}>
                            <SvgGlobal
                                name={'hint-icon'}
                                dataStyles={{ className: css.instructionsItem__svg }}
                            />
                        </span>
                    </div>
                </div>
                <div
                    className={css.instructionsItem}
                    onClick={() => setGlobalBonus(pre => !pre)}
                >
                    <div className={css.instructionsItem__content}>
                        <Translation
                            id={'global_bonus'}
                            defaultStr={'Глобальный бонус'}
                        />
                        <span className={css.instructionsItem__icon}>
                            <SvgGlobal
                                name={'hint-icon'}
                                dataStyles={{ className: css.instructionsItem__svg }}
                            />
                        </span>
                    </div>
                </div>
                <div
                    className={css.instructionsItem}
                    onClick={() => handlerPremiumPlan()}
                >
                    <div className={css.instructionsItem__content}>
                        <Translation
                            id={'premium_plan'}
                            defaultStr={'Премиальный план'}
                        />
                        <span className={css.instructionsItem__icon}>
                            <SvgGlobal
                                name={'hint-icon'}
                                dataStyles={{ className: css.instructionsItem__svg }}
                            />
                        </span>
                    </div>
                </div>
                <div
                    className={css.instructionsItem}
                    onClick={() => handlerPersonalAccount()}
                >
                    <div className={css.instructionsItem__content} style={{ transform: 'translateY(2px)' }}>
                        <Translation id={'personal_account'} />
                    </div>
                </div>
            </div>
            <Modal
                onClose={() => setHowUseCalculator(pre => !pre)}
                isOpen={howUseCalculator}
                title={(
                    <Translation id={'how_use_calculator_modal_title'} />
                )}
            >
                <div className={css.modalWrap}>
                    <p className={css.modalText}>
                        <Translation id={'how_use_calculator_modal_deck_1'} />
                    </p>
                    <p className={css.modalTitle}>
                        <Translation id={'how_use_calculator_modal_deck_2'} />
                    </p>
                    <ul className={css.modalList}>
                        <li className={css.modalListItem}>
                            <Translation id={'how_use_calculator_modal_deck_2_1'} />
                        </li>
                        <li className={css.modalListItem}>
                            <Translation id={'how_use_calculator_modal_deck_2_2'} />
                        </li>
                        <li className={css.modalListItem}>
                            <Translation id={'how_use_calculator_modal_deck_2_3'} />
                        </li>
                        <li className={css.modalListItem}>
                            <Translation id={'how_use_calculator_modal_deck_2_4'} />
                        </li>
                        <li className={css.modalListItem}>
                            <Translation id={'how_use_calculator_modal_deck_2_5'} />
                        </li>
                        <li className={css.modalListItem}>
                            <Translation id={'how_use_calculator_modal_deck_2_6'} />
                        </li>
                    </ul>

                </div>
            </Modal>
            <Modal
                onClose={() => setDiscountMh(pre => !pre)}
                isOpen={discountMh}
                title={(
                    <Translation
                        id={'discount_mh_modal_title'}
                        defaultStr={'Что такое «Скидка МН»'}
                    />
                )}
            >
                <div className={css.modalWrap}>
                    <p className={css.modalText}>
                        <Translation
                            id={'discount_mh_modal_desc_1'}
                            defaultStr={'Скидка МН - вознаграждение которое зависит исключительно от вашей личной покупки после оформления пакета Elite. С каждой личной покупки 10% будет возвращаться на бонусный счет.'}
                        />
                    </p>
                    <div className={css.modalGreyBlock}>
                        <p className={css.modalTitle}>
                            <Translation
                                id={'discount_mh_modal_desc_2'}
                                defaultStr={'Пример'}
                            />
                        </p>
                        <p className={css.modalText} style={{ marginBottom: 6 }}>
                            <Translation
                                id={'discount_mh_modal_desc_3'}
                                defaultStr={'Вы оформили покупку на сумму 54 000 BV.'}
                            />
                        </p>
                        <p className={css.modalText} style={{ marginBottom: 0 }}>
                            <Translation
                                id={'discount_mh_modal_desc_4'}
                                defaultStr={'Ваш бонус составит:'}
                            />
                            <span style={{ marginLeft: 4 }}>
                                {`5 400 ${currency?.currency || ''}.`}
                            </span>
                        </p>
                    </div>
                </div>
            </Modal>
            <Modal
                onClose={() => setGlobalBonus(pre => !pre)}
                isOpen={globalBonus}
                title={(
                    <Translation
                        id={'global_bonus_modal_title'}
                        defaultStr={'Что такое «Глобальный бонус»'}
                    />
                )}
            >
                <div className={css.modalWrap}>
                    <p className={css.modalText}>
                        <Translation
                            id={'global_bonus_modal_desc_1'}
                            defaultStr={'Глобальный бонус — это ваше вознаграждение за достижение статуса «Директора» и выше. '}
                        />
                    </p>
                    <p className={css.modalText}>
                        <Translation
                            id={'global_bonus_modal_desc_2'}
                            defaultStr={'Ежемесячно компания выделяет 3% от глобального бизнес-оборота для выплат участникам, выполнившим квалификацию.'}
                        />
                    </p>
                    <p className={css.modalText} style={{ marginBottom: 0 }}>
                        <Translation id={'global_bonus_modal_desc_3'} />
                    </p>
                </div>
            </Modal>
        </>
    );
};

export default Instructions;