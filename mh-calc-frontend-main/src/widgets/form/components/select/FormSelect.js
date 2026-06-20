import React from 'react';
import formCss from '../../styles/form.module.scss';
import { Select } from "antd";

const FormSelect = ({
    title = '',
    attribute,
    placeholder,
    disabled = false,
    setFormData = () => null,
    options = [],
    value = false
}) => {

    const handleChange = (value) => {
        setFormData(attribute, value);
    };

    return (
        <div className={formCss.wrapper}>
            <label className={formCss.label}>
                {title ? (
                    <span className={formCss.title}>
                        {title}
                    </span>
                ) : null}
                <div>
                    <Select
                        style={{ width: '100%', fontSize: '16px' }}
                        name={attribute || ''}
                        onChange={handleChange}
                        placeholder={placeholder || ''}
                        disabled={disabled}
                        {...value ? { value: value } : {}}
                    >
                        {options?.map((item, idx) => (
                            <Select.Option
                                value={item?.value}
                                key={`select_${attribute}_${idx}`}
                                style={{ fontSize: '16px' }}
                            >
                                {item?.label}
                            </Select.Option>
                        ))}
                    </Select>
                </div>
            </label>
        </div>
    );
};

export default FormSelect;