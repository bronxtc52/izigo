import { API_SERVER_URL, sender } from "@/common/utils/utils";
import { showNotification } from "@/common/notification";

export const changePackagesStructure = (data, userToken, lang, currency, setRootStructure, onClose, token) => {
    if (!data) return;
    if (!token) return;

    sender(
        `${API_SERVER_URL}/api/v1/calculator/structure/node/set-structure-package`,
        'POST',
        { ...data, structure_token: token },
        (response) => {
            if (response?.data) {
                setRootStructure(response?.data);
            };

            if (response?.data?.notify?.length > 0) {
                response?.data?.notify.map(item => {
                    showNotification({ message: item?.message, type: item?.is_good ? 'success' : 'error' })
                });
            };

            onClose(false);
        },
        (errors, status) => {
            if (status === 403 || status === 401 || errors?.need_login) {
                localStorage.removeItem('userToken'); window.location.reload();
            };
            if (errors?.message) {
                showNotification({ message: errors?.message, type: 'error' })
            };
        },
        userToken,
        lang,
        currency
    );
};

export const createNewStructure = (
    lang,
    currency,
    setRootStructure,
    changeToken,
    changeViewToken,
    userToken
) => {
    sender(
        `${API_SERVER_URL}/api/v1/calculator/structure`,
        'POST',
        {},
        (response) => {
            if (response?.data) {
                if (response?.data?.data?.token_edit) {
                    changeToken(response?.data?.data?.token_edit);
                };

                if (response?.data?.data?.token_view) {
                    changeViewToken(response?.data?.data?.token_view);
                };

                setRootStructure(response?.data);
            };
        },
        (errors, status) => {
            if (status === 403 || status === 401 || errors?.need_login) {
                localStorage.removeItem('userToken'); window.location.reload();
            };
            if (errors?.message) {
                showNotification({ message: errors?.message, type: 'error' })
            };
        },
        userToken,
        lang,
        currency
    );
};

export const getStructureByToken = (
    token,
    lang,
    currency,
    setRootStructure,
    changeToken,
    changeViewToken,
    userToken
) => {
    sender(
        `${API_SERVER_URL}/api/v1/calculator/structure/${token}`,
        'GET',
        {},
        (response) => {
            if (response?.data) {
                if (response?.data?.data?.token_edit !== token) {
                    changeToken(response?.data?.data?.token_edit);
                };
                if (response?.data?.data?.token_view) {
                    changeViewToken(response?.data?.data?.token_view);
                };

                setRootStructure(response?.data);
            };
        },
        (errors, status) => {
            if (status === 403 || status === 401 || errors?.need_login) {
                localStorage.removeItem('userToken'); window.location.reload();
            };
            if (status === 404) {
                createNewStructure(
                    lang,
                    currency,
                    setRootStructure,
                    changeToken,
                    changeViewToken,
                    userToken
                );
            };
            if (errors?.message) {
                showNotification({ message: errors?.message, type: 'error' })
            };
        },
        userToken,
        lang,
        currency
    )
};

export const addStructureNode = (data, userToken = false, lang, currency, setRootStructure, onClose, update_id = false,) => {
    const url = update_id
        ? `${API_SERVER_URL}/api/v1/calculator/structure/node/update/${update_id}`
        : `${API_SERVER_URL}/api/v1/calculator/structure/node/create`
    sender(
        url,
        update_id ? 'PUT' : 'POST',
        data,
        (response) => {
            if (response?.data) {
                setRootStructure(response?.data);
            };

            onClose(false);

            if (response?.data?.notify?.length > 0) {
                response?.data?.notify.map(item => {
                    showNotification({ message: item?.message, type: item?.is_good ? 'success' : 'error' })
                });
            };
        },
        (errors, status) => {
            if (status === 403 || status === 401 || errors?.need_login) {
                localStorage.removeItem('userToken'); window.location.reload();
            };
            if (errors?.message) {
                showNotification({ message: errors?.message, type: 'error' })
            };
        },
        userToken,
        lang,
        currency
    );
};


export const deleteNodeStructure = (id, userToken, token, setRootStructure, lang, currency) => {
    if (!id) return;
    if (!token) return;

    sender(
        `${API_SERVER_URL}/api/v1/calculator/structure/node/delete/${id}`,
        'DELETE',
        { structure_token: token },
        (response) => {
            if (response?.data) {
                setRootStructure(response?.data);
            };
            if (response?.data?.notify?.length > 0) {
                response?.data?.notify.map(item => {
                    showNotification({ message: item?.message, type: item?.is_good ? 'success' : 'error' })
                });
            };
        },
        (errors, status) => {
            if (status === 403 || status === 401 || errors?.need_login) {
                localStorage.removeItem('userToken'); window.location.reload();
            };
            if (errors?.message) {
                showNotification({ message: errors?.message, type: 'error' })
            };
        },
        userToken,
        lang,
        currency
    );
};