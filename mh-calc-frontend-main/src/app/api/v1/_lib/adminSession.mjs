// Server-only утилиты BFF-сессии веб-админки (t1-admin-cookie-auth).
// Sanctum-токен админки больше НЕ живёт в JS/localStorage: Next-сервер запечатывает его
// в httpOnly-cookie (AES-256-GCM ключом из runtime env ADMIN_COOKIE_SECRET) и сам
// подставляет Authorization: Bearer в прокси-запросах к бэку. Файл в `_lib` —
// приватная папка App Router (не роут); исполняется ТОЛЬКО на Next-сервере.
// .mjs — чтобы seal/unseal были тестируемы node:test без транспиляции (ESM в CJS-пакете).
import crypto from 'node:crypto';

export const COOKIE_NAME = 'izigo_admin_s';
// Cookie уходит только на пути BFF-прокси — меньше поверхность (не на HTML-страницы).
export const COOKIE_PATH = '/api/v1';

// TTL cookie в синхроне с TTL Sanctum-токена бэка (WEB_ADMIN_TOKEN_TTL_MINUTES, дефолт 12ч).
const ttlMinutes = () => {
    const n = Number.parseInt(process.env.WEB_ADMIN_TOKEN_TTL_MINUTES || '', 10);
    return Number.isFinite(n) && n > 0 ? n : 720;
};

export const cookieOptions = () => ({
    httpOnly: true,
    // localhost-dev ходит по http — Secure там не даст поставить cookie.
    secure: process.env.NODE_ENV === 'production',
    sameSite: 'lax',
    path: COOKIE_PATH,
    maxAge: ttlMinutes() * 60,
});

export const clearCookieOptions = () => ({ ...cookieOptions(), maxAge: 0 });

// Ключ AES-256 — SHA-256 от произвольной строки секрета (не требуем ровно 32 байта).
const keyFor = (secret) => crypto.createHash('sha256').update(String(secret), 'utf8').digest();

/** Запечатать токен: base64url( iv(12) || gcm-tag(16) || ciphertext ). */
export const seal = (token, secret = process.env.ADMIN_COOKIE_SECRET) => {
    if (!secret) throw new Error('ADMIN_COOKIE_SECRET не задан');
    const iv = crypto.randomBytes(12);
    const cipher = crypto.createCipheriv('aes-256-gcm', keyFor(secret), iv);
    const ct = Buffer.concat([cipher.update(String(token), 'utf8'), cipher.final()]);
    return Buffer.concat([iv, cipher.getAuthTag(), ct]).toString('base64url');
};

/** Распечатать cookie-значение → токен или null (tamper/мусор/чужой ключ — молча null). */
export const unseal = (value, secret = process.env.ADMIN_COOKIE_SECRET) => {
    if (!secret || !value) return null;
    try {
        const raw = Buffer.from(String(value), 'base64url');
        if (raw.length < 12 + 16 + 1) return null;
        const decipher = crypto.createDecipheriv('aes-256-gcm', keyFor(secret), raw.subarray(0, 12));
        decipher.setAuthTag(raw.subarray(12, 28));
        return Buffer.concat([decipher.update(raw.subarray(28)), decipher.final()]).toString('utf8');
    } catch (e) {
        return null;
    }
};

// База бэка для server-side прокси: runtime BACKEND_INTERNAL_URL приоритетнее
// запечённого build-time NEXT_PUBLIC_SERVER_BACK_URL (fallback для dev).
export const backendBase = () => String(
    process.env.BACKEND_INTERNAL_URL || process.env.NEXT_PUBLIC_SERVER_BACK_URL || '',
).replace(/\/+$/, '');

// Host-guard (зеркало логики src/middleware.js): BFF-роуты живут только на admin-хосте
// и localhost. Mini App (izigo.*) ходит на бэк напрямую — тут ей делать нечего.
export const isAllowedAdminHost = (host) => {
    const h = String(host || '').toLowerCase();
    return h.startsWith('admin.') || h.startsWith('localhost') || h.startsWith('127.0.0.1');
};

// CSRF defense-in-depth поверх SameSite=Lax: не-GET запросы обязаны быть same-origin.
// Проверяем Origin (если браузер прислал) против Host; иначе Sec-Fetch-Site.
// Ни того ни другого (не-браузерный клиент) — fail-closed (curl-тесты шлют Origin явно).
export const sameOriginViolation = (request) => {
    const method = (request.method || 'GET').toUpperCase();
    if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') return false;
    const host = String(request.headers.get('host') || '').toLowerCase();
    const origin = request.headers.get('origin');
    if (origin) {
        try {
            return new URL(origin).host.toLowerCase() !== host;
        } catch (e) {
            return true;
        }
    }
    return request.headers.get('sec-fetch-site') !== 'same-origin';
};
