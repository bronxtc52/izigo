import React, { useState } from 'react';
import FormSelect from '@/widgets/form/components/select/FormSelect';
import Translation from '@/common/translations/Translation';
import css from './ChangePackage.module.scss';
import Button from '@/widgets/button/Button';
import { showNotification } from "@/common/notification";
// import { withoutPackageOption } from '../initData';

const ChangePackage = ({ packages, changePackages }) => {
    const [formData, setFormData] = useState({});

    return (
        <div>
            <div>
                <FormSelect
                    attribute={'package_id'}
                    title={(
                        <Translation id={'addNode_package_id'} defaultStr={'Пакет'} />
                    )}
                    placeholder={(
                        <Translation id='addNode_package_id_placeholder' />
                    )}
                    setFormData={(a, v) => setFormData(pre => ({ ...pre, [a]: v }))}
                    options={
                        packages?.length > 0
                            ? packages?.map(item => ({ value: Number(item?.id), label: item?.description || item?.name }))
                            : []
                    }
                />
            </div>
            <div className={css.buttonRow}>
                <div>
                    <Button
                        title={(<Translation id={'save'} defaultStr={'Сохранить'} />)}
                        clickFunc={() => {
                            if (formData?.package_id || formData?.package_id == null) {
                                changePackages(formData)
                            } else {
                                showNotification({ message: <Translation id='not_select_packages' />, type: 'error' })
                            };
                        }}
                    />
                </div>
            </div>
        </div>
    );
};

export default ChangePackage;