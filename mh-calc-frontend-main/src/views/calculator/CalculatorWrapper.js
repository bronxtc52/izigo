'use client';
import React, { useState, useEffect, useCallback, useRef } from 'react';
import css from './Calculator.module.scss';
import Header from './components/header/Header';
import { useGlobalContext } from '@/common/GlobalContext';
import { createNewStructure, getStructureByToken, deleteNodeStructure, changePackagesStructure } from './utils';
import Structure from './components/structure/Structure';
import SvgGlobal from '@/project/icons/SvgGlobal';
import { getData } from '@/common/utils/utils';
import Tooltip from '@/widgets/tooltip/Tooltip';
import Translation from '@/common/translations/Translation';
import Modal from '@/widgets/modal/Modal';
import StructureLinks from './components/structureLinks/StructureLinks';
import ChangePackage from './components/changePackage/ChangePackage';

const CalculatorWrapper = () => {
    const {
        tokenStructure,
        userToken,
        lang,
        currency,
        changeTokenStructure,
        setTokenViewStructure
    } = useGlobalContext();

    const [rootStructure, setRootStructure] = useState(false);
    const [canStructureEdit, setCanStructureEdit] = useState(false);
    const [packages, setPackages] = useState([]);
    const [showShareModal, setShowShareModal] = useState(false);

    const [showPackagesModal, setShowPackagesModal] = useState(false);

    const widthTree = useRef(null);
    const [zoom, setZoom] = useState(1);
    const [position, setPosition] = useState({ x: 0, y: 0 });

    useEffect(() => {
        if (typeof window !== 'undefined' && userToken) {
            const storegeToken = localStorage.getItem('token_structure') || '';
            const tokenLink = new URLSearchParams(window.location.search).get('token');

            if (!tokenStructure && !storegeToken && !tokenLink) {
                createNewStructure(
                    lang,
                    currency,
                    setRootStructure,
                    changeTokenStructure,
                    setTokenViewStructure,
                    userToken
                );
            } else if (tokenStructure) {
                getStructureByToken(
                    tokenStructure,
                    lang,
                    currency,
                    setRootStructure,
                    changeTokenStructure,
                    setTokenViewStructure,
                    userToken
                );
            };
        };
    }, [lang, currency, tokenStructure, userToken]);

    useEffect(() => {
        (async () => {
            const response = await getData('/api/v1/calculator/packages', false, lang, currency);

            if (response?.data) {
                setPackages(response?.data);
            };
        })()
    }, [lang, currency])

    useEffect(() => {
        if (rootStructure?.data?.can_edit) {
            setCanStructureEdit(rootStructure?.data?.can_edit);
        };
    }, [rootStructure]);

    const deleteNode = (id) => {
        deleteNodeStructure(
            id,
            userToken,
            tokenStructure,
            setRootStructure,
            lang,
            currency
        );
    };

    const refreshStructure = () => {
        createNewStructure(
            lang,
            currency,
            setRootStructure,
            changeTokenStructure,
            setTokenViewStructure,
            userToken
        );
    };

    const changePackages = (data) => {
        changePackagesStructure(
            data,
            userToken,
            lang,
            currency,
            setRootStructure,
            setShowPackagesModal,
            tokenStructure
        );
    };

    const checkScaleProperty = useCallback(() => {
        const svgTransform = document?.querySelector('g.rd3t-g').getAttribute('transform');

        const svgTransformStylesArray = svgTransform.match(/([^()]*)/g).filter((el) => el);

        const scaleIndex = svgTransformStylesArray?.findIndex((el) => el === ' scale');
        let currentZoom = zoom;
        if (
            scaleIndex !== -1 &&
            svgTransformStylesArray?.[scaleIndex + 1] &&
            typeof +svgTransformStylesArray?.[scaleIndex + 1] === 'number'
        ) {
            currentZoom = +svgTransformStylesArray?.[scaleIndex + 1];
            setZoom(currentZoom);
        }
        return currentZoom;
    }, [zoom, setZoom]);

    const handleTop = useCallback(() => {
        if (widthTree?.current) {
            const x = widthTree.current.getBoundingClientRect().width / 2;
            const y = 200;
            const currentZoom = checkScaleProperty();

            document.querySelector('g.rd3t-g').setAttribute('transform', `translate(${x}, ${y}) scale(${currentZoom})`);
            setPosition(() => ({ x, y }));
        };
    }, [checkScaleProperty, widthTree]);

    return (
        <div className={css.calculatorWrapper}>
            <Header
                data={rootStructure?.data || false}
                rootData={rootStructure?.data?.root || false}
                refreshStructure={refreshStructure}
                currency={rootStructure?.data?.currency || false}
                setShowShareModal={setShowShareModal}
                setShowPackagesModal={setShowPackagesModal}
                handleTop={handleTop}
            />
            <div className={css.content}>
                <div className={css.structureButtons}>
                    <div
                        className={css.structureButton}
                        onClick={() => refreshStructure()}
                    >
                        <Tooltip
                            title={(
                                <Translation
                                    id={'clear_structure_btn'}
                                    defaultStr={'Очистить структуру'}
                                />
                            )}
                            placement={'right'}
                        >
                            <div className={css.structureButton__tooltip}>
                                <SvgGlobal name={'clear'} dataStyles={{ className: css.structureButton_svg }} />
                            </div>
                        </Tooltip>
                    </div>
                    <div
                        className={css.structureButton}
                        onClick={() => setShowShareModal(pre => !pre)}
                    >
                        <Tooltip
                            title={(
                                <Translation
                                    id={'share_structure_tooltip'}
                                    defaultStr={'Поделится структурой'}
                                />
                            )}
                            placement={'right'}
                        >
                            <div className={css.structureButton__tooltip}>
                                <SvgGlobal name={'share'} dataStyles={{ className: css.structureButton_svg }} />
                            </div>
                        </Tooltip>
                    </div>
                    <div
                        className={css.structureButton}
                        onClick={() => handleTop()}
                    >
                        <Tooltip
                            title={(
                                <Translation
                                    id={'structure_aligned_top'}
                                    defaultStr={'Выровнять структуру'}
                                />
                            )}
                            placement={'right'}
                        >
                            <div className={css.structureButton__tooltip}>
                                <SvgGlobal name={'arrow-top'} dataStyles={{ className: css.structureButton_svg }} />
                            </div>
                        </Tooltip>
                    </div>
                    <div
                        className={css.structureButton}
                        onClick={() => setShowPackagesModal(pre => !pre)}
                    >
                        <Tooltip
                            title={(
                                <Translation
                                    id={'select_contract_all_structure'}
                                    defaultStr={'Выбрать контракт для всей структуры'}
                                />
                            )}
                            placement={'right'}
                        >
                            <div className={css.structureButton__tooltip}>
                                <SvgGlobal name={'briefcase'} dataStyles={{ className: css.structureButton_svg }} />
                            </div>
                        </Tooltip>
                    </div>
                </div>
                <Structure
                    calculatorOwner={rootStructure?.data?.calculator_owner || {}}
                    treeData={rootStructure?.data?.root || false}
                    setRootStructure={setRootStructure}
                    canStructureEdit={canStructureEdit}
                    deleteNode={deleteNode}
                    packages={packages}
                    widthTree={widthTree}
                    position={position}
                    setPosition={setPosition}
                    zoom={zoom}
                />
                {/* LINK STRUCTURE */}
                <Modal
                    isOpen={showShareModal}
                    onClose={setShowShareModal}
                    title={(<Translation id={'structure_link_modal_title'} defaultStr={'Сохранение структуры'} />)}
                >
                    <StructureLinks structureEdit={canStructureEdit} />
                </Modal>
                {/* CHANGE PACKAGES STRUCTURE */}
                <Modal
                    isOpen={showPackagesModal}
                    onClose={setShowPackagesModal}
                    title={(
                        <Translation id={'select_contract_all_structure'} defaultStr={'Выбрать контракт для всей структуры'} />
                    )}
                >
                    <ChangePackage
                        changePackages={changePackages}
                        packages={packages}
                    />
                </Modal>
            </div>
        </div>
    )
};

export default CalculatorWrapper;