import React from 'react';
import css from './StructureLinks.module.scss';
import { useGlobalContext } from '@/common/GlobalContext';
import FormInput from '@/widgets/form/components/input/FormInput';
import Translation from '@/common/translations/Translation';
import { SITE_URL } from '@/common/utils/utils';

const StructureLinks = ({ structureEdit }) => {
    const { tokenStructure, tokenViewStructure } = useGlobalContext();

    return (
        <div className={css.wrapper}>
            {tokenViewStructure ? (
                <div>
                    <FormInput
                        attribute={'structure_view'}
                        disabled={true}
                        title={(
                            <Translation id={'structure_link_with_token_view'} defaultStr={'Ссылка на просмотр структуры'} />
                        )}
                        placeholder={''}
                        setFormData={() => null}
                        value={`${SITE_URL}?token=${tokenViewStructure}`}
                        copy={true}
                    />
                </div>
            ) : null}
            {tokenStructure && structureEdit ? (
                <div>
                    <FormInput
                        attribute={'structure_view'}
                        disabled={true}
                        title={(
                            <Translation id={'structure_link_with_token_edit'} defaultStr={'Ссылка на редактирование структуры'} />
                        )}
                        placeholder={''}
                        setFormData={() => null}
                        value={`${SITE_URL}?token=${tokenStructure}`}
                        copy={true}
                    />
                </div>
            ) : (
                <div>
                    <FormInput
                        attribute={'structure_view'}
                        disabled={true}
                        title={(
                            <Translation id={'structure_link_with_token'} defaultStr={'Ссылка на структуру'} />
                        )}
                        placeholder={''}
                        setFormData={() => null}
                        value={`${SITE_URL}?token=${tokenStructure}`}
                        copy={true}
                    />
                </div>
            )}
        </div>
    );
};

export default StructureLinks;