// Юридические тексты Mini App (Политика конфиденциальности и Условия использования).
// Статика: правка = редеплой (для редко меняющихся юр-документов это норма). Рендерятся
// как plain-text (white-space: pre-wrap), React экранирует — санитайзер не нужен, markdown/HTML
// выведутся литералами, поэтому форматирование — только переносами строк и абзацами.
// От имени IziGo LTD (Dubai, DXB). Тексты носят общий характер и не заменяют консультацию юриста.

const PRIVACY_POLICY = {
    ru: `Политика конфиденциальности IziGo

Оператор: IziGo LTD (Дубай, ОАЭ; DXB). Настоящая Политика описывает, какие данные мы
обрабатываем в приложении IziGo (Telegram Mini App) и как мы их защищаем.

1. Какие данные мы обрабатываем
• Данные вашего аккаунта Telegram, переданные через механизм авторизации Telegram (initData):
  идентификатор Telegram, имя, имя пользователя, выбранный язык. Пароли мы не используем и не храним.
• Данные участия в партнёрской программе: ваш реферальный код, спонсор, структура команды,
  ранг, начисленные бонусы и движения по внутреннему балансу.
• Платёжные реквизиты для выплат: указанный вами адрес в сети TON для получения USDT.
• Технические данные: журналы обращений, сведения об ошибках и базовая аналитика использования.
• Данные верификации (KYC), если вы её проходите, — в объёме, необходимом для проверки.

2. Зачем мы это обрабатываем
• Для предоставления функций приложения: регистрация, расчёт и отображение бонусов, оформление
  заказов и выплат, поддержка.
• Для выполнения требований законодательства, предотвращения мошенничества и злоупотреблений.
• Для улучшения качества и стабильности сервиса.

3. Правовые основания
Обработка осуществляется на основании исполнения соглашения с вами, нашего законного интереса в
работе и защите сервиса, а также для соблюдения применимых правовых требований.

4. Передача третьим лицам
Мы не продаём ваши персональные данные. Мы можем передавать данные поставщикам инфраструктуры
(хостинг, аналитика, рассылка уведомлений) исключительно в объёме, необходимом для работы сервиса,
а также по законному требованию уполномоченных органов. Платёжные операции в сети TON являются
публичными по своей природе (блокчейн).

5. Хранение и защита
Мы храним данные столько, сколько необходимо для целей обработки и соблюдения закона. Мы применяем
организационные и технические меры защиты. Платёжная авторизация осуществляется через Telegram;
расчёты ведутся в USDT.

6. Ваши права
Вы вправе запросить доступ к своим данным, их исправление или удаление в пределах, допустимых
законом и сохранением данных, обязательных для бухгалтерского/комплаенс-учёта. Удаление аккаунта
может ограничить доступ к функциям программы.

7. Данные несовершеннолетних
Сервис не предназначен для лиц младше 18 лет.

8. Изменения
Мы можем обновлять настоящую Политику. Актуальная версия всегда доступна в приложении.

9. Контакты
По вопросам конфиденциальности обращайтесь к оператору IziGo LTD через каналы поддержки в
приложении.`,
    en: `IziGo Privacy Policy

Operator: IziGo LTD (Dubai, UAE; DXB). This Policy describes what data we process in the IziGo
application (Telegram Mini App) and how we protect it.

1. Data we process
• Your Telegram account data provided via Telegram's authorization mechanism (initData): Telegram
  ID, name, username, selected language. We do not use or store passwords.
• Partner-program data: your referral code, sponsor, team structure, rank, accrued bonuses, and
  internal balance transactions.
• Payout details: the TON network address you provide to receive USDT.
• Technical data: request logs, error reports, and basic usage analytics.
• Verification (KYC) data, if you undergo it, to the extent required for the check.

2. Why we process it
• To provide the app's features: registration, calculation and display of bonuses, orders and
  payouts, and support.
• To comply with legal requirements and to prevent fraud and abuse.
• To improve the quality and stability of the service.

3. Legal bases
Processing is based on the performance of the agreement with you, our legitimate interest in
operating and protecting the service, and compliance with applicable legal requirements.

4. Sharing with third parties
We do not sell your personal data. We may share data with infrastructure providers (hosting,
analytics, notification delivery) strictly to the extent necessary to operate the service, and as
lawfully required by competent authorities. TON network payments are public by nature (blockchain).

5. Retention and security
We retain data for as long as necessary for the processing purposes and legal compliance. We apply
organizational and technical security measures. Payment authorization is performed via Telegram;
settlements are denominated in USDT.

6. Your rights
You may request access to, correction of, or deletion of your data, subject to limits permitted by
law and to the retention of data required for accounting/compliance. Account deletion may limit
access to program features.

7. Minors
The service is not intended for persons under 18 years of age.

8. Changes
We may update this Policy. The current version is always available in the app.

9. Contact
For privacy matters, contact the operator IziGo LTD via the support channels in the app.`,
};

const TERMS_OF_USE = {
    ru: `Условия использования IziGo

Оператор: IziGo LTD (Дубай, ОАЭ; DXB). Используя приложение IziGo, вы соглашаетесь с настоящими
Условиями. Если вы не согласны — не используйте приложение.

1. Описание сервиса
IziGo — партнёрская (реферальная) программа в виде Telegram Mini App. Сервис позволяет
активировать пакеты, формировать команду, получать бонусы согласно действующему маркетинг-плану и
запрашивать выплаты в USDT.

2. Аккаунт и доступ
Доступ осуществляется через ваш аккаунт Telegram. Вы отвечаете за сохранность доступа к своему
Telegram-аккаунту и за все действия, совершённые в приложении от вашего имени.

3. Бонусы и маркетинг-план
Размер и условия бонусов определяются действующим маркетинг-планом, который может изменяться.
Бонусы начисляются за фактическую активность согласно правилам программы. Доход не гарантирован и
зависит от ваших результатов и результатов вашей команды.

4. Платежи и выплаты
Расчёты ведутся в USDT (сеть TON). Оплата и авторизация осуществляются средствами Telegram.
Выплаты производятся на указанный вами TON-адрес. Вы обязаны указывать корректный адрес: операции
в блокчейне необратимы, и оператор не несёт ответственности за средства, отправленные по неверно
указанным реквизитам. Для вывода отдельных сумм может требоваться верификация (KYC).

5. Допустимое использование
Запрещены: мошенничество, создание фиктивных аккаунтов, манипуляции со структурой и бонусами,
нарушение законодательства, а также любые действия, наносящие ущерб сервису или другим участникам.

6. Приостановка и прекращение
Мы вправе ограничить или прекратить доступ при нарушении настоящих Условий, признаках
злоупотребления или по требованию закона.

7. Отказ от гарантий и ограничение ответственности
Сервис предоставляется «как есть». В максимальной степени, допустимой законом, оператор не несёт
ответственности за косвенные убытки, упущенную выгоду, а также за потери, связанные с действиями в
блокчейне, сбоями третьих сторон или неверно указанными реквизитами.

8. Изменения условий
Мы можем обновлять настоящие Условия и маркетинг-план. Продолжая пользоваться приложением после
изменений, вы принимаете их.

9. Применимое право
К настоящим Условиям применяется право, действующее по месту регистрации оператора (Дубай, ОАЭ),
если иное не предусмотрено императивными нормами.

10. Контакты
По вопросам, связанным с Условиями, обращайтесь к IziGo LTD через каналы поддержки в приложении.`,
    en: `IziGo Terms of Use

Operator: IziGo LTD (Dubai, UAE; DXB). By using the IziGo application you agree to these Terms. If
you do not agree, do not use the application.

1. Service description
IziGo is a partner (referral) program delivered as a Telegram Mini App. The service lets you
activate packages, build a team, earn bonuses under the applicable marketing plan, and request
payouts in USDT.

2. Account and access
Access is provided through your Telegram account. You are responsible for keeping access to your
Telegram account secure and for all actions performed in the app under your identity.

3. Bonuses and marketing plan
The amounts and conditions of bonuses are defined by the applicable marketing plan, which may
change. Bonuses are accrued for genuine activity under the program rules. Income is not guaranteed
and depends on your results and those of your team.

4. Payments and payouts
Settlements are denominated in USDT (TON network). Payment and authorization are performed via
Telegram. Payouts are sent to the TON address you provide. You must provide a correct address:
blockchain transactions are irreversible, and the operator is not liable for funds sent to
incorrectly provided details. Verification (KYC) may be required to withdraw certain amounts.

5. Acceptable use
The following are prohibited: fraud, creating fake accounts, manipulating the structure or bonuses,
violating the law, and any actions that harm the service or other participants.

6. Suspension and termination
We may restrict or terminate access in case of a breach of these Terms, signs of abuse, or where
required by law.

7. Disclaimer and limitation of liability
The service is provided "as is". To the maximum extent permitted by law, the operator is not liable
for indirect damages, lost profits, or losses related to blockchain actions, third-party failures,
or incorrectly provided details.

8. Changes to the Terms
We may update these Terms and the marketing plan. By continuing to use the app after changes, you
accept them.

9. Governing law
These Terms are governed by the law in force at the operator's place of registration (Dubai, UAE),
unless mandatory rules provide otherwise.

10. Contact
For matters related to these Terms, contact IziGo LTD via the support channels in the app.`,
};

const DOCS = { privacy: PRIVACY_POLICY, terms: TERMS_OF_USE };

/** Текст юр-документа по ключу ('privacy'|'terms') и языку ('ru'|'en'); фолбэк на ru. */
export const legalText = (doc, lang) => {
    const d = DOCS[doc];
    if (!d) return '';
    return d[lang] || d.ru;
};
