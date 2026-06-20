import Translation from "@/common/translations/Translation";
import { MAIN_PROJECT } from "@/common/utils/utils";

export const getLinkPremiumPlan = (currency = 'KZT', lang = 'ru') => {
    let link = '';

    switch (currency) {
        case "kk":
        case "KZT":
            if (lang == 'ru') {
                link = `https://pdf.marinehealth.asia/books/kxla/`;
            } else {
                link = `https://pdf.marinehealth.asia/books/dtoz/`;
            };
            break;
        case "ru":
        case "RUB":
            link = `https://pdf.marinehealth.asia/books/unmg/`;
            break;
        case "ky":
        case "KGS":
            if (lang == 'ru') {
                link = `https://pdf.marinehealth.asia/books/slga/`;
            } else {
                link = `https://pdf.marinehealth.asia/books/fouu/`;
            };
            break;
        case "az":
        case "AZN":
            if (lang == 'ru') {
                link = `https://pdf.marinehealth.asia/books/gyaj/`;
            } else {
                link = `https://pdf.marinehealth.asia/books/hewd/`;
            };
            break;
        case "uz":
        case "UZS":
            if (lang == 'ru') {
                link = `https://pdf.marinehealth.asia/books/mnnd/`;
            } else {
                link = `https://pdf.marinehealth.asia/books/hltf/`;
            };
            break;
        default:
            // KZT || kk
            if (lang == 'ru') {
                link = `https://pdf.marinehealth.asia/books/kxla/`;
            } else {
                link = `https://pdf.marinehealth.asia/books/dtoz/`;
            };
    };

    return link;
};

export const getDataHeader = (data, currency) => {
    return [
        {
            title: (
                <span>
                    <Translation
                        id={'referral_bonus_short'}
                        defaultStr={'Реферал. бонус'}
                    />
                    {`(I), ${currency?.currency || ''}`}
                </span>
            ),
            value: data?.bonus_referral_sum_level_1_format
                ? data?.bonus_referral_sum_level_1_format?.replace(/\s?[A-Z]{1,5}$/, '')
                : '0.00'
        },
        {
            title: (
                <span>
                    <Translation
                        id={'referral_bonus_short'}
                        defaultStr={'Реферал. бонус'}
                    />
                    {`(II), ${currency?.currency || ''}`}
                </span>
            ),
            value: data?.bonus_referral_sum_level_2_format
                ? data?.bonus_referral_sum_level_2_format.replace(/\s?[A-Z]{1,5}$/, '')
                : '0.00'
        },
        {
            title: (
                <span>
                    <Translation
                        id={'trade_turnover_structure'}
                        defaultStr={'Товарооборот стр'}
                    />
                    {`, ${currency?.currency || ''}`}
                </span>
            ),
            value: data?.bonus_binary_sum_format
                ? data?.bonus_binary_sum_format.replace(/\s?[A-Z]{1,5}$/, '')
                : '0.00'
        },
        {
            title: (
                <span>
                    <Translation
                        id={'leadership_bonus_structure'}
                        defaultStr={'Лидерский бонус'}
                    />
                    {`, ${currency?.currency || ''}`}
                </span>
            ),
            value: data?.bonus_leader_sum_format
                ? data?.bonus_leader_sum_format.replace(/\s?[A-Z]{1,5}$/, '')
                : '0.00'
        },
        {
            title: (
                <span>
                    <Translation
                        id={'qualified_reward_structure'}
                        defaultStr={'Квалифик. награда'}
                    />
                    {`, ${currency?.currency || ''}`}
                </span>
            ),
            value: data?.bonus_rank_sum_format
                ? data?.bonus_rank_sum_format.replace(/\s?[A-Z]{1,5}$/, '')
                : '0.00'
        }
    ]
};

export const getFaqDataMobile = (css, currency) => {
    return [
        {
            title: (
                <Translation id={'how_use_calculator_modal_title'} />
            ),
            component: (
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
            )
        },
        {
            title: (
                <Translation
                    id={'global_bonus_modal_title'}
                    defaultStr={'Что такое «Глобальный бонус»'}
                />
            ),
            component: (
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
            )
        },
        {
            title: (
                <Translation
                    id={'discount_mh_modal_title'}
                    defaultStr={'Что такое «Скидка МН»'}
                />
            ),
            component: (
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
            )
        },
    ]
};

export const getMenuItem = (currency, lang) => {
    const linkPremiumPlan = getLinkPremiumPlan(currency, lang);

    return [
        {
            title: <Translation id={'personal_account'} />,
            link: MAIN_PROJECT,
        },
        {
            title: <Translation id={'premium_plan'} />,
            link: linkPremiumPlan,
        }
    ];
};

// creatTreeStructure
export const creatTreeStructure = (node) => {
    if (!node.children) {
        return node;
    }

    // Заменяем null в children на { empty: true }
    const updatedChildren = node.children.map(child =>
        child === null ? { empty: true } : creatTreeStructure(child)
    );

    // Обновляем узел с новыми children
    const updatedNode = {
        ...node,
        children: updatedChildren,
    };

    return updatedNode;
};

export const getNodeSize = (screen = false) => {
    if (screen && screen >= 1760) {
        return { x: 330, y: 374 };
    } else if (screen && (screen <= 1760 && screen > 1560)) {
        return { x: 330, y: 332 };
    } else if (screen && (screen <= 1560 && screen >= 1170)) {
        return { x: 290, y: 290 };
    } else {
        return { x: 330, y: 374 };
    };
};

export const withoutPackageOption = {
    value: null,
    label: <Translation id={'without_contract_option'} />
};