import React from 'react';
import { Tooltip } from 'antd';

const TooltipElement = ({ title = '', children, placement = 'top' }) => {
    return (
        <Tooltip title={title} placement={placement}>
            {children}
        </Tooltip>
    );
};

export default TooltipElement;