import React, { useState, useEffect } from 'react';
import css from './AddNode.module.scss';
import Modal from '@/widgets/modal/Modal';
import Translation from '@/common/translations/Translation';
import FormInput from '@/widgets/form/components/input/FormInput';
import FormSelect from '@/widgets/form/components/select/FormSelect';
import { useGlobalContext } from '@/common/GlobalContext';
import { addStructureNode } from '../../utils';
import Button from '@/widgets/button/Button';
import { withoutPackageOption } from '../initData';
import { useTranslation } from 'next-i18next';

const AddNode = ({
    node,
    setNode,
    isOpen,
    onClose,
    setRootStructure,
    packages
}) => {
    const { t } = useTranslation();

    const [sendData, setSendData] = useState({});
    const [sponsors, setSponsors] = useState(false);
    const { tokenStructure, lang, currency, userToken } = useGlobalContext();

    const changeFormData = (name, value) => {
        setSendData(pre => ({ ...pre, [name]: value }));
    };

    useEffect(() => {
        if (!node?.nodeDatum) return;
        if (!isOpen) return;

        if (node?.nodeDatum?.possible_sponsor_list_for_child) {
            const transformData = [];

            Object.keys(node?.nodeDatum?.possible_sponsor_list_for_child).map(item => {
                transformData.push({ value: Number(item), label: node?.nodeDatum?.possible_sponsor_list_for_child[item] });
            });

            setSponsors(transformData)
        };

        if (node?.edit) {
            setSendData(pre => ({
                ...pre,
                ...(node?.nodeDatum?.package_id ? { package_id: node?.nodeDatum?.package_id } : {}),
                ...(node?.nodeDatum?.sponsor_id ? { sponsor_id: node?.nodeDatum?.sponsor_id } : {}),
                username: node?.nodeDatum?.name || '',
                structure_token: tokenStructure,
            }));
        } else {
            setSendData(pre => ({
                ...pre,
                position: node?.position,
                top_node_id: node?.nodeDatum?.id,
                structure_token: tokenStructure,
            }));
        };
    }, [node, isOpen]);

    useEffect(() => {
        if (!isOpen) {
            setSendData({})
            setNode(false);
        };
    }, [isOpen]);

    return (
        <Modal
            isOpen={isOpen}
            onClose={onClose}
            title={
                node?.edit ? (
                    <Translation
                        id={'edit_node_user_title'}
                        defaultStr={'Редактировать пользователя'}
                    />
                ) : (
                    <Translation
                        id={'add_node_user_title'}
                        defaultStr={'Добавить пользователя'}
                    />
                )
            }
        >
            <div>
                <div>
                    <FormInput
                        attribute={'username'}
                        title={(
                            <Translation id={'addNode_username_title'} defaultStr={'Имя'} />
                        )}
                        placeholder={t('addNode_username_placeholder')}
                        setFormData={changeFormData}
                        value={node?.edit && sendData?.username ? sendData?.username : ''}
                    />
                </div>
                {!(node?.nodeDatum?.parent_id == 0 && node?.edit) ? (
                    <div>
                        <FormSelect
                            attribute={'sponsor_id'}
                            title={(
                                <Translation id={'addNode_sponsor_username'} defaultStr={'Спонсор'} />
                            )}
                            placeholder={(
                                <Translation id='addNode_sponsor_id_placeholder' />
                            )}
                            setFormData={changeFormData}
                            options={sponsors ? sponsors : []}
                            value={node?.edit && sendData?.sponsor_id ? Number(sendData?.sponsor_id) : false}
                        />
                    </div>
                ) : null}
                <div>
                    <FormSelect
                        attribute={'package_id'}
                        title={(
                            <Translation id={'addNode_package_id'} defaultStr={'Пакет'} />
                        )}
                        placeholder={(
                            <Translation id='addNode_package_id_placeholder' />
                        )}
                        setFormData={changeFormData}
                        options={
                            packages?.length > 0
                                ? packages?.map(item => ({ value: Number(item?.id), label: item?.description || item?.name }))
                                : []
                        }
                        value={node?.edit && sendData?.package_id ? Number(sendData?.package_id) : null}
                    />
                </div>
                <div className={css.buttonWrap}>
                    <div>
                        <Button
                            title={(
                                <Translation id={'save'} defaultStr={'Сохранить'} />
                            )}
                            clickFunc={() => {
                                if (node?.edit) {
                                    addStructureNode(
                                        sendData,
                                        userToken,
                                        lang,
                                        currency,
                                        setRootStructure,
                                        onClose,
                                        node?.nodeDatum?.id
                                    )
                                    return;
                                };
                                addStructureNode(
                                    sendData,
                                    userToken,
                                    lang,
                                    currency,
                                    setRootStructure,
                                    onClose
                                )
                            }}
                        />
                    </div>
                </div>
            </div>
        </Modal>
    );
};

export default AddNode;