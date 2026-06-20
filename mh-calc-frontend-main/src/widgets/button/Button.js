import React from 'react';
import css from './Button.module.scss';
import classnames from 'classnames';

const Button = ({ type = 'main', size = 's', clickFunc, disabled = false, title }) => {
    return (
        <div className={css.overlay}>
            <div
                className={classnames(css.buttonDefaultWrapper, {
                    [css[`buttonWrapper_type_${type}`]]: type,
                    [css[`buttonWrapper_size_${size}`]]: size
                })}
                onClick={disabled ? () => null : clickFunc}
            >
                <div>
                    <span className={css.buttonTitle}>
                        {title || 'OK'}
                    </span>
                </div>
            </div>
            {disabled ? <div className={css.buttonDefaulDisabled} /> : null}
        </div>
    );
};

export default Button;