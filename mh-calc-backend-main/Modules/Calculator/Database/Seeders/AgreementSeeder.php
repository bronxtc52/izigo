<?php

namespace Modules\Calculator\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Calculator\Models\PlanSetting;

/**
 * Реальный текст Пользовательского соглашения (RU+EN) для онбординг-гейта Mini App.
 *
 * ИДЕМПОТЕНТНО: текст ставится ТОЛЬКО если сохранённый ru-текст отличается от целевого.
 * Иначе версия НЕ бампается — иначе каждый деплой форсил бы повторное принятие у всех
 * участников (акцепт привязан к версии, member_agreement_acceptances). Версия поднимается
 * один раз при первой установке/изменении текста (минимум 2 — чтобы перекрыть возможный
 * акцепт дефолтного плейсхолдера v1). Формат значения совместим с AgreementService:
 * {version:int, text:{ru:string, en:string}}. Вызывается в docker/start.sh после migrate.
 */
class AgreementSeeder extends Seeder
{
    public function run(): void
    {
        $ru = <<<'TXT'
Пользовательское соглашение IziGo

Оператор: IziGo LTD (Дубай, ОАЭ; DXB). Используя приложение IziGo (Telegram Mini App), вы подтверждаете, что прочитали и принимаете настоящее Соглашение, а также Условия использования и Политику конфиденциальности, доступные в разделе «Настройки».

1. Участие в программе. IziGo — партнёрская (реферальная) программа. Вы можете активировать пакеты, формировать команду и получать бонусы согласно действующему маркетинг-плану, который может изменяться. Доход не гарантирован и зависит от ваших результатов и результатов вашей команды.

2. Аккаунт. Доступ осуществляется через ваш аккаунт Telegram. Вы отвечаете за сохранность доступа к нему и за действия, совершённые в приложении от вашего имени. Пароли не используются.

3. Расчёты и выплаты. Учёт и выплаты ведутся в USDT (сеть TON). Выплаты производятся на указанный вами TON-адрес. Операции в блокчейне необратимы: за средства, отправленные по неверно указанным реквизитам, оператор ответственности не несёт. Для вывода отдельных сумм может требоваться верификация (KYC).

4. Данные. Мы обрабатываем ваши данные в объёме, необходимом для работы программы, как описано в Политике конфиденциальности.

5. Допустимое использование. Запрещены мошенничество, фиктивные аккаунты, манипуляции со структурой и бонусами, а также нарушения закона. При нарушении доступ может быть ограничен или прекращён.

6. Возраст. Сервис предназначен для лиц 18 лет и старше.

7. Изменения. Соглашение и маркетинг-план могут обновляться. При существенном изменении текста потребуется повторное принятие.

Нажимая «Принимаю», вы соглашаетесь с условиями настоящего Соглашения.
TXT;

        $en = <<<'TXT'
IziGo User Agreement

Operator: IziGo LTD (Dubai, UAE; DXB). By using the IziGo application (Telegram Mini App) you confirm that you have read and accept this Agreement, as well as the Terms of Use and Privacy Policy available in the "Settings" section.

1. Participation. IziGo is a partner (referral) program. You may activate packages, build a team, and earn bonuses under the applicable marketing plan, which may change. Income is not guaranteed and depends on your results and those of your team.

2. Account. Access is provided through your Telegram account. You are responsible for keeping access to it secure and for actions performed in the app under your identity. No passwords are used.

3. Settlements and payouts. Accounting and payouts are denominated in USDT (TON network). Payouts are sent to the TON address you provide. Blockchain transactions are irreversible: the operator is not liable for funds sent to incorrectly provided details. Verification (KYC) may be required to withdraw certain amounts.

4. Data. We process your data to the extent necessary to operate the program, as described in the Privacy Policy.

5. Acceptable use. Fraud, fake accounts, manipulation of the structure or bonuses, and violations of the law are prohibited. In case of a breach, access may be restricted or terminated.

6. Age. The service is intended for persons aged 18 and over.

7. Changes. This Agreement and the marketing plan may be updated. A material change to the text will require re-acceptance.

By tapping "I accept" you agree to the terms of this Agreement.
TXT;

        // Источник правды соглашения — веб-админка (AgreementAdmin). Сидер лишь засевает
        // реальный текст ОДИН раз, когда соглашение ещё не настроено. Если ключ уже есть
        // (засеян ранее ИЛИ отредактирован владельцем) — не трогаем: иначе деплой перетёр бы
        // правку админа и форснул повторный акцепт у всех (см. урок izigo-plan-settings-editable).
        if (PlanSetting::get('agreement') !== null) {
            return;
        }

        // Версия 2 (не 1): перекрывает возможный акцепт дефолтного плейсхолдера (current() => v1),
        // чтобы реальный текст требовал явного принятия.
        PlanSetting::put('agreement', [
            'version' => 2,
            'text' => ['ru' => $ru, 'en' => $en],
        ]);
    }
}
