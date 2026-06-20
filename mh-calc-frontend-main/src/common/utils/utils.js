'use client';
import axios from 'axios';

export const API_SERVER_URL = process.env.NEXT_PUBLIC_SERVER_BACK_URL || false;
export const SITE_URL = process.env.NEXT_PUBLIC_SERVER_FRONT_URL || false;
export const PRODUCTION = process.env.NEXT_PUBLIC_SERVER_PROD || false;
export const MAIN_PROJECT = process.env.NEXT_PUBLIC_SERVER_MAIN_PROJECT || false;

export const getData = async (url, userToken, lang = false, currency = false) => {
    if (!url) return false;

    const resultUrl = url.includes('https://') || url.includes('http://') ? url : `${API_SERVER_URL}${url}`;

    try {
        const res = await fetch(resultUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/json;charset=UTF-8',
                ...(userToken ? { 'CalculatorAuthToken': userToken } : {}),
                ...(lang ? { 'Accept-Language': lang } : {}),
                ...(currency ? { 'Accept-Currency': currency } : {}),
                mode: 'no-cors'
            }
        });
        if (res.status === 500) {
            console.warn(url, res);
            return { status: 500 };
        }
        if (res.status === 404 || !res) {
            return false;
        } else if (res.status === 403) {
            return { status: 403 };
        } else if (res.status === 503) {
            return { status: 503 };
        } else if (res.status === 401) {
            return { status: 401 };
        } else {
            return await res.json();
        }
    } catch (e) {
        console.warn(url, e);
        return false;
    }
};

export const sender = (
    url,
    method,
    data,
    susseccFunc = () => { },
    errorFunc = () => { },
    token,
    lang,
    currency,
    dataType = 'json'
) => {
    const headers = token
        ? {

            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': `${dataType === 'json' ? 'application/json' : 'multipart/form-data'};charset=UTF-8`,
            ...(token ? { 'CalculatorAuthToken': token } : {}),
            mode: 'no-cors',
            ...(lang ? { 'Accept-Language': lang } : {}),
            ...(currency ? { 'Accept-Currency': currency } : {}),
        }
        : {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': `${dataType === 'json' ? 'application/json' : 'multipart/form-data'};charset=UTF-8`,
            ...(lang ? { 'Accept-Language': lang } : {}),
            ...(currency ? { 'Accept-Currency': currency } : {})
        };

    const config = {
        method: !['POST', 'GET'].includes(method) && dataType !== 'json' ? 'POST' : method,
        url,
        headers
    };

    if (data && dataType === 'json') {
        config.data = JSON.stringify(data);
    } else {
        let newData = new FormData();

        if (!data?.['_method']) {
            newData.append('_method', method);
        };

        for (let key in data) {
            newData.append(key, data[key]);
        };

        config.data = newData;
    };

    axios(config)
        .then((response) => {
            if (response.status === 204) {
                susseccFunc({ status: 204 });
            } else {
                susseccFunc(response);
            };
        })
        .catch((error) => {
            if (!error.response) return false;
            const { data, status, headers } = error.response;

            if (status === 401) {
                errorFunc(data, status, headers);
                console.log('err', error);
            } else {
                if (status === 0) {
                    document.location.reload();
                } else {
                    errorFunc(data, status, headers);
                };
            };
        });
};
