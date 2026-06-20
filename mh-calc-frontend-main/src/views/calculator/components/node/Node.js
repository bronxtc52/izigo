'use client';
import React, { useState } from 'react';
import css from './Node.module.scss';
import classnames from 'classnames';
import SvgGlobal from '@/project/icons/SvgGlobal';
import Translation from '@/common/translations/Translation';
import { getNodeSize } from '../initData';
import { useWindowDimensions } from '@/common/utils/windowDimensions';

const Node = ({
    nodeDatum,
    setAddNode,
    setShowAddModal,
    canStructureEdit,
    deleteNode,
    setInformationId,
    calculatorOwner = {}
}) => {
    const windowDimensions = useWindowDimensions();
    const nodeSize = getNodeSize(windowDimensions);
    const [errorAvatar, setErrorAvatar] = useState(false);

    if (!nodeDatum) return null;

    const foreignObjectProps = {
        width: nodeSize.x, height: nodeSize.y - 32, x: -nodeSize.x / 2, y: -nodeSize.y / 2
    };

    const btn_left_branch = nodeDatum?.children?.length
        ? nodeDatum?.children[0]?.empty ? true : false
        : true

    const btn_right_branch = nodeDatum?.children?.length >= 2
        ? nodeDatum?.children[1]?.empty ? true : false
        : true

    const handleError = () => {
        setErrorAvatar(true);
    };

    return (
        <>
            <g>
                <foreignObject {...foreignObjectProps}>
                    <div className={css.wrapper}>
                        <div className={classnames(css.nodeWrapper, {
                            [css.nodeWrapper__main]: nodeDatum.parent_id === 0,
                            [css.nodeWrapper__empty]: nodeDatum.empty,
                        })}>
                            {nodeDatum?.empty ? (
                                <div />
                                // <div className={css.emptyNode}>
                                //     <span className={css.emptyNodeText}>
                                //         <Translation id={'empty_node_text'} defaultStr={'Пустая ячейка'} />
                                //     </span>
                                // </div>
                            ) : (
                                <>
                                    <div style={{ width: '100%' }}>
                                        <div className={css.topButtonWrapper}>
                                            <div className={css.topButtonItem}></div>
                                            <div className={css.topButtonItem}>
                                                {nodeDatum.parent_id !== 0 && canStructureEdit ? (
                                                    <div
                                                        className={css.deleteNodeWrap}
                                                        onClick={() => deleteNode(nodeDatum?.id)}
                                                    >
                                                        <SvgGlobal
                                                            name={'trash'}
                                                            dataStyles={{ className: css.deleteNodeSvg }}
                                                        />
                                                    </div>
                                                ) : null}
                                            </div>
                                            <div className={css.topButtonItem}>
                                                <div
                                                    className={css.informationNodeWrap}
                                                    onClick={() => {
                                                        if (nodeDatum?.id) {
                                                            setInformationId(nodeDatum?.id)
                                                        };
                                                    }}
                                                >
                                                    <SvgGlobal
                                                        name={'information'}
                                                        dataStyles={{ className: css.informationNodeSvg }}
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                        <div className={css.nodeTop}>
                                            <div className={css.nodeTopUserIcon}>
                                                {calculatorOwner?.avatar && nodeDatum.parent_id === 0 && !errorAvatar ? (
                                                    <img
                                                        src={calculatorOwner?.avatar}
                                                        alt={calculatorOwner?.avatar}
                                                        className={css.nodeTopUserImage}
                                                        onError={handleError}
                                                    />
                                                ) : (
                                                    <SvgGlobal
                                                        name={'user-icon'}
                                                        dataStyles={{ className: css.nodeTopUserIconSvg }}
                                                    />
                                                )}
                                            </div>
                                            <div>
                                                <p className={css.nodeTopUserName}>
                                                    {nodeDatum?.name}
                                                </p>
                                                {nodeDatum?.rank_name ? (
                                                    <p className={css.nodeTopUserRank}>
                                                        {nodeDatum?.rank_name}
                                                    </p>
                                                ) : (
                                                    <p className={css.nodeTopUserRank}>
                                                        <Translation id={'client_rank'} />
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                    <div style={{ width: '100%' }}>
                                        {nodeDatum.parent_id !== 0 && nodeDatum?.sponsor_id ? (
                                            <div>
                                                <div
                                                    className={classnames(css.nodeRow, css.nodeEdit)}
                                                    onClick={() => {
                                                        if (!canStructureEdit) return;

                                                        setAddNode({ position: nodeDatum?.pos, nodeDatum: nodeDatum, edit: true });
                                                        setShowAddModal(true);
                                                    }}
                                                >
                                                    <div>
                                                        <span
                                                            className={classnames(css.nodeEditText, css.nodeEditText_grey)}
                                                            style={{ paddingRight: 4, fontWeight: 400 }}
                                                        >
                                                            <Translation
                                                                id={'sponsor'}
                                                                defaultStr={'Спонсор'}
                                                            />
                                                        </span>
                                                        <span className={classnames(css.nodeEditText)}>
                                                            {nodeDatum?.sponsor || ''}
                                                        </span>
                                                    </div>
                                                    <div className={css.nodeEditIcon}>
                                                        {canStructureEdit ? <SvgGlobal name={'edit'} dataStyles={{ className: css.nodeEditIconSvg }} /> : null}
                                                    </div>
                                                </div>
                                            </div>
                                        ) : null}
                                        <div>
                                            <div
                                                className={classnames(css.nodeRow, css.nodeEdit)}
                                                onClick={() => {
                                                    if (!canStructureEdit) return;

                                                    setAddNode({ position: nodeDatum?.pos, nodeDatum: nodeDatum, edit: true });
                                                    setShowAddModal(true);
                                                }}
                                            >
                                                <div>
                                                    <span
                                                        className={classnames(css.nodeEditText, css.nodeEditText_grey)}
                                                        style={{ paddingRight: 4, fontWeight: 400 }}
                                                    >
                                                        <Translation
                                                            id={'contract'}
                                                            defaultStr={'Контракт'}
                                                        />
                                                    </span>
                                                    <span className={classnames(css.nodeEditText)}>
                                                        {nodeDatum?.package_name && nodeDatum?.package_id
                                                            ? nodeDatum?.package_name
                                                            : (
                                                                <Translation
                                                                    id={'not_package'}
                                                                    defaultStr={'Отсутствует'}
                                                                />
                                                            )}
                                                    </span>
                                                </div>
                                                <div className={css.nodeEditIcon}>
                                                    {canStructureEdit ? <SvgGlobal name={'edit'} dataStyles={{ className: css.nodeEditIconSvg }} /> : null}
                                                </div>
                                            </div>
                                        </div>
                                        <div className={css.nodeBottom}>
                                            <div className={css.nodeBottom_item}>
                                                <>
                                                    <span className={css.nodeBottom_item_title}>
                                                        <Translation
                                                            id={'left_branch'}
                                                            defaultStr={'Левая ветка'}
                                                        />
                                                    </span>
                                                    <span className={classnames(css.nodeBottom_item_val, css.nodeBottom_item_val_border)}>
                                                        {nodeDatum?.pv_left_format || '-'}
                                                    </span>
                                                    <span className={css.nodeBottom_item_val}>
                                                        {nodeDatum?.bv_left_format || '-'}
                                                    </span>
                                                </>
                                                <div className={css.nodeAddUserWrapper}>
                                                    {btn_left_branch && canStructureEdit ? (
                                                        <div
                                                            className={css.nodeAddUser}
                                                            onClick={() => {
                                                                setAddNode({ position: 1, nodeDatum: nodeDatum }); // left branch
                                                                setShowAddModal(true);
                                                            }}
                                                        >
                                                            <SvgGlobal
                                                                name={'pluse'}
                                                                dataStyles={{ className: css.nodeAddUser_svg }}
                                                            />
                                                        </div>
                                                    ) : null}
                                                </div>
                                            </div>
                                            <div className={css.nodeBottom_item}>
                                                <>
                                                    <span className={css.nodeBottom_item_title}>
                                                        <Translation
                                                            id={'right_branch'}
                                                            defaultStr={'Правая ветка'}
                                                        />
                                                    </span>
                                                    <span className={classnames(css.nodeBottom_item_val, css.nodeBottom_item_val_border)}>
                                                        {nodeDatum?.pv_right_format || '-'}
                                                    </span>
                                                    <span className={css.nodeBottom_item_val}>
                                                        {nodeDatum?.bv_right_format || '-'}
                                                    </span>
                                                </>
                                                <div className={css.nodeAddUserWrapper}>
                                                    {btn_right_branch && canStructureEdit ? (

                                                        <div
                                                            className={css.nodeAddUser}
                                                            onClick={() => {
                                                                setAddNode({ position: 2, nodeDatum: nodeDatum }); // right branch
                                                                setShowAddModal(true);
                                                            }}
                                                        >
                                                            <SvgGlobal
                                                                name={'pluse'}
                                                                dataStyles={{ className: css.nodeAddUser_svg }}
                                                            />
                                                        </div>
                                                    ) : null}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </>
                            )}
                        </div>
                    </div>
                </foreignObject>
            </g>
        </>
    );
};

export default Node;