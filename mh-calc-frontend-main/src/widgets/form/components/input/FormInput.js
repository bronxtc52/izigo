import React from 'react';
import { Input } from "antd";
import formCss from '../../styles/form.module.scss';
import css from './FormInput.module.scss';
import SvgGlobal from '@/project/icons/SvgGlobal';
import { showNotification } from "@/common/notification";
import Translation from '@/common/translations/Translation';
import { CopyToClipboard } from 'react-copy-to-clipboard';

const FormInput = ({
    title = '',
    attribute,
    placeholder,
    type = 'text',
    disabled = false,
    setFormData = () => null,
    value = '',
    copy = false
}) => {
    return (
        <div className={formCss.wrapper}>
            <label className={formCss.label}>
                {title ? (
                    <span className={formCss.title}>
                        {title}
                    </span>
                ) : null}
                <div className={formCss.inputWrapper}>
                    <Input
                        name={attribute || ''}
                        placeholder={placeholder || ''}
                        type={type}
                        style={{
                            fontSize: '16px',
                            ...(copy ? { paddingRight: '4rem' } : {})
                        }}
                        disabled={disabled}
                        onChange={(event) => setFormData(event.target.name, event.target.value)}
                        {...value ? { value: value } : {}}
                    />
                    {copy ? (
                        <CopyToClipboard
                            text={value || ''}
                            onCopy={() => {
                                showNotification({
                                    message: (
                                        <Translation id={'copy_defaul_str'} defaultStr={'Скопировано в буфер обмена'} />
                                    )
                                })
                            }}
                        >
                            <div className={css.copyWrap}>
                                <SvgGlobal name={'copy'} dataStyles={{ className: css.copySvg }} />
                            </div>
                        </CopyToClipboard>
                    ) : null}
                </div>

            </label>
        </div>
    )
};

export default FormInput
