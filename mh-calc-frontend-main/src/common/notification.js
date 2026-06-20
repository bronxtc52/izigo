import { notification } from 'antd';


export const showNotification = (config) => {
    const { message, description = false, type = 'success', duration = 6, placement = 'topRight' } = config;
    // success, error, info, warning
    notification[type]({
        message: message,
        description: description,
        duration: duration,
        placement: placement,
    });
};