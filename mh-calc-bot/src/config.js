import { DefaultAzureCredential } from '@azure/identity';
import { SecretClient } from '@azure/keyvault-secrets';

// Несекретные параметры — из env. Сам токен бота — ТОЛЬКО из Key Vault.
const VAULT_NAME = process.env.KEY_VAULT_NAME || 'kv-bronxtc-dev';
const TOKEN_SECRET = process.env.BOT_TOKEN_SECRET_NAME || 'izigo--beta--TELEGRAM-BOT-TOKEN';

// URL Mini App (https). Без него кнопка запуска приложения не показывается.
export const MINI_APP_URL = process.env.MINI_APP_URL || '';
// Имя секрета DSN Sentry (self-host sentry.adarasoft.com); опционален.
const SENTRY_DSN_SECRET = process.env.SENTRY_DSN_SECRET_NAME || 'izigo--beta--SENTRY-DSN';

let sharedClient = null;
function client() {
    if (!sharedClient) {
        sharedClient = new SecretClient(`https://${VAULT_NAME}.vault.azure.net`, new DefaultAzureCredential());
    }
    return sharedClient;
}

/** Прочитать секрет из Key Vault (null, если пуст/недоступен). */
export async function getSecret(name) {
    try {
        const secret = await client().getSecret(name);
        return secret.value || null;
    } catch {
        return null;
    }
}

/**
 * Токен бота из Azure Key Vault через managed identity / DefaultAzureCredential.
 * Никогда не читаем токен из переменных окружения (глобальное правило по секретам).
 */
export async function loadBotToken() {
    const value = await getSecret(TOKEN_SECRET);
    if (!value) {
        throw new Error(`Пустой/недоступный секрет ${TOKEN_SECRET} в ${VAULT_NAME}`);
    }
    return value;
}

/** DSN Sentry из Key Vault (best-effort; может отсутствовать). */
export function loadSentryDsn() {
    return getSecret(SENTRY_DSN_SECRET);
}
