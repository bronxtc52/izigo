# ТЗ: Удаление SSO из mh-calc (расширенная чистка)

**Дата:** 2026-06-20
**Статус:** Гейт 1/2 — на согласовании
**Связано:** [[2026-06-20-local-auth]] (локальная авторизация — остаётся единственной)

## Цель

Полностью убрать из кода авторизацию через внешний главный сайт Marine Health (SSO):
внешний сервис `ApiOutMH`, вход по `sso_token`, связанные DTO/конфиги/lang, фронтовую
ветку SSO. Прилегающее легаси (хардкод-бэкдор токен `123456`, dev-гейтинг локального
входа, SSO-редиректы) — тоже вычистить. **Локальная авторизация (email+пароль) остаётся
единственным способом входа** — следовательно, перестаёт быть dev-only.

## Сценарии (после чистки)

1. Пользователь заходит без токена → видит локальную форму входа/регистрации (всегда,
   и в dev, и в проде). Нет редиректа на внешний сайт.
2. Токен протух / 401/403 → сбрасываем токен и показываем локальную форму (не редирект на SSO).
3. Меню «Личный кабинет»/«Премиальный план» и share-ссылки структуры продолжают вести на
   внешний сайт/PDF — это обычные ссылки, не авторизация.
4. Эндпоинт SSO-логина `POST /login` отсутствует.

## Границы

ВХОДИТ:
- Backend: удалить `ApiOutMH`, `AuthController`, `LoginRequest`, DTO `LoginDto/AuthResult/OutProfileData`,
  маршрут `POST /login`, SSO-нутро `CalculatorAuthService` (метод `login()`, `generateAuthToken()`,
  поле `$api`). Убрать конфиги `calculator_main_site_auth_url`, `calculator_super_email`.
  Убрать бэкдор-токен `123456` из миграции. Снять dev-гейтинг локального входа
  (`LocalAuthEnabledMiddleware`, `config calculator.local_auth`). Почистить SSO-ключи в `lang/*/auth.php`.
  Убрать `out_id` из модели (поле-колонку оставить как безвредную nullable).
- Frontend: удалить SSO-ветку в `GlobalContext.js` (`mh-sso-token`, POST /login, редирект на
  MAIN_PROJECT/SITE_URL при входе). Нет токена → всегда показывать локальную форму. Заменить
  5× `window.location.href = MAIN_PROJECT` при 401/403 в `views/calculator/utils.js` на сброс
  токена + reload. Убрать фронтовый флаг `NEXT_PUBLIC_LOCAL_AUTH` (вход безусловный).

НЕ ВХОДИТ (остаётся как есть):
- `CalculatorAuth` фасад + token-валидация (`SetCalculatorUserMiddleware`, `CheckUserTokenMiddleware`,
  `calculator.validate.token`) — нужны локальному входу.
- Меню-ссылки на внешний сайт (`MAIN_PROJECT` в `initData.js`, `Instructions.js`) и share-ссылки
  (`SITE_URL` в `StructureLinks.js`) — это навигация, не SSO.
- Пре-существующий сломанный `StructureTest` (отдельная легаси-проблема с удалённой колонкой
  `email`; зависит от `calculator_super_email`) — не чиним в этой итерации, но фиксируем, что
  он и так красный.

## Тесты

- `LocalAuthTest`: убрать тест на gating (`testEndpointsReturn404WhenLocalAuthDisabled` —
  гейтинга больше нет); остальные (register/login/токен) должны проходить.
- Новый тест: `POST /api/v1/login` (SSO) больше не существует (404/405).
- `php artisan migrate:fresh` проходит без бэкдор-токена.
- Фронт: без токена → форма; компиляция без ошибок.
