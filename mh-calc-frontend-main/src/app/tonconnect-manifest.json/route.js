// TonConnect dApp manifest (TEP-66). Отдаётся динамически, чтобы url/iconUrl брались из
// домена Mini App (NEXT_PUBLIC_SERVER_FRONT_URL), а не хардкодились под конкретный деплой.
// Перед продом: положить public/tonconnect-icon.png (180×180) — иначе строгие кошельки
// могут показать заглушку вместо иконки (на подключение не влияет).
export function GET() {
    const origin = process.env.NEXT_PUBLIC_SERVER_FRONT_URL || 'https://izigo.app';
    return Response.json({
        url: origin,
        name: 'IziGo',
        iconUrl: `${origin}/tonconnect-icon.png`,
    });
}
