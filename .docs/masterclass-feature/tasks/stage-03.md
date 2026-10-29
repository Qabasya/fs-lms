# Этап 3. Вход и плеер

Зависимости: этап 2 (аккаунты и флаеры), этап 0 (Ш1, Ш2, Ш6, `.s-banner`).
Результат: временный ученик входит по флаеру и попадает сразу в плеер своего мастер-класса, видит «до начала / на месте / дома», дома — баннер и
«Связаться», везде «Выйти»; по сроку доступ закрывается заморозкой. Тика и удаления ещё нет.

Перед началом прочитать `README.md`, `../SPEC.md` §2 (решения 8–11, 13, 22), §4, `../CONTRACTS.md` §8 (`MasterclassAccessGuard`, `MasterclassLoginTracker`),
§9 (`MasterclassAuthController`, `MasterclassPlayerController`), §10 (`MasterclassPlayerCallbacks`), §11 (`player-*.php`, `player.js`).

**Порядок:** 3.1 → 3.2 → 3.3 → 3.4 → 3.5 → 3.6.

## Статус

Не начат. **Переписан по факту этапа 2 (2026-10-29).** Что уже есть и чего нет:

- **Готово в этапе 2 и используется здесь:** `MasterclassAccountService::findLiveByUserId()` (живой = не удалён и не `Enrolled`), `playerUrlFor( $userId )` (адрес `PageRoutes::LessonPlayer->lessonUrl( group, group_lesson )`
  или `null`), `unlockLogins()`; `MasterclassDTO::isFrozen()` / `isRunning()` / `status()`; `MasterclassAccountRepository::markFirstLogin / markHome / markContact` (условные `UPDATE … IS NULL`, `bool`);
  `MasterclassRepository::runningFor( $nowUtc, $leadMinutes )` (для Ш6); DTO `MasterclassViewDTO`; `MasterclassLogWriter` (`LoginBeforeStart/OnSite/AtHome`, `ContactOpened/Phone/Messenger`, `Frozen` уже в `MasterclassLogAction`).
- **Нет и делается здесь:** `MasterclassAccessGuard`, `MasterclassLoginTracker`, `MasterclassAuthController`, `MasterclassPlayerController`, `MasterclassPlayerCallbacks`, `templates/player-*.php`, `assets/player.js`.
  В `MasterclassModule` добавляются `$auth` и `$player` (после `$admin`, только при включённом модуле); в `MasterclassIntegrityController` — подписка Ш1 (`fs_lms_lesson_access`, всегда).
- **«Первый вход» на странице аккаунтов** (`admin-accounts.php`, колонки «Первый вход» / «Дома» / «Связался») заполняется трекером — это проверка этого этапа, а не 2.6: после входа под `student-N` строка списка аккаунтов должна показать время.
- Страница модуля уже умеет «Снять блокировки» (`MasterclassAccountService::unlockLogins()`): проверка 3.6 «класс ошибся тремя паролями → сняли блокировки» идёт через неё (в этапе 2 проверено: после трёх ошибок вход закрыт даже с верным паролем).
- **Инструментарий проверки уже отработан в этапе 2:** HTTP-вход `POST /wp-login.php` (`log`, `pwd`, cookie `wordpress_test_cookie`; успех — `Set-Cookie wordpress_logged_in_…`), headless Chrome по CDP со временным mu-plugin
  `zz-dev-login.php` (одноразовый токен → `wp_set_auth_cookie`, ещё отдаёт nonce по действию), скрипты в scratchpad; **стек медленный** — запрос ≈ 60 с, пока в контейнере включена проверка меток времени opcache
  (`opcache.validate_timestamps=1` + файлы по bind-mount с Windows); на время e2e помогает временный `conf.d/zz-mk-fast.ini` с `opcache.validate_timestamps=0` (≈ 13 с на запрос) — **убрать после** и `apachectl graceful`.
- Тестовые заглушки `wp_enqueue_script` / `wp_localize_script` уже в `tests/bootstrap.php`; заглушка `add_query_arg` принимает только массив.

---

## 3.1 `MasterclassAccessGuard`

- [ ] `filterLessonAccess( AccessLevel, StudentRecordDTO, GroupLessonDTO )` (Ш1): только `isTrial`; живой аккаунт по физлицу, его мастер-класс — группа занятия,
  `isFrozen( now )` → `None`. `viewFor( int $userId ): ?MasterclassViewDTO` (`frozen`, `showBanner`, тексты, телефон из `centerContactsFallback()`, `applyUrl`, `logoutUrl`).
  Кеш на запрос по физлицу и пользователю.
- [ ] Подключить в `MasterclassIntegrityController` (всегда, и при выключенном модуле — иначе выключение откроет замороженные уроки).
- [ ] Тесты: заморозка ровно по `freeze_at`, чужая группа, не `isTrial`, `Enrolled`, удалённый; повысить уровень нельзя (Ш1); `viewFor()` — баннер только не в заморозке
  и после окна/«дома».

**Готово, когда:** тесты зелёные; тесты ядра Ш1 (этап 0) по-прежнему зелёные.

## 3.2 `MasterclassLoginTracker`

- [ ] `onLogin()` (`wp_login`): «до начала» — только журнал; иначе `markFirstLogin()`, при «дома» — `markHome()`; журнал `LoginOnSite`/`LoginAtHome`/`LoginBeforeStart`
  (IP — только здесь). `onPlayerOpen()` — «дома» после окончания даже при живой сессии. `classify()` — чистая. `allowsCaptchaBypass( $login )` (Ш6).
- [ ] Тесты `classify()`: до начала, в окне, после окна, повтор через 59 и 60 минут (`home_timeout`), удалённый и зачисленный → `null`; условные `UPDATE … IS NULL` — один раз.

**Готово, когда:** тесты зелёные.

## 3.3 Редиректы и вход (`MasterclassAuthController`)

- [ ] `template_redirect` (приоритет 5, раньше `ProfileController`): `/profile/` и `/sign-in/` для залогиненного живого аккаунта → плеер; `login_redirect` (приоритет 20)
  → плеер; `wp_login` → трекер; `fs_lms_captcha_fallback_unlimited` → `allowsCaptchaBypass()` только для `CaptchaScope::Login`.
- [ ] Тесты: редирект только для живого аккаунта; у обычного ученика, учителя и гостя поведение прежнее; обход капчи только в окне (запас 30 минут) и только для логина `student-N`.

**Готово, когда:** тесты зелёные; на стенде вход по флаеру ведёт сразу в плеер.

## 3.4 Плеер (`MasterclassPlayerController`, шаблоны, `player.js`)

- [ ] Ш2: `fs_lms_player_back_url` (живой аккаунт → `''`), `fs_lms_player_lock_html` (окно заморозки; «Заявка принята» для `Applied`), `fs_lms_player_header_actions`
  («Связаться», «Выйти»), `fs_lms_player_before_content` (баннер `.s-banner`), `fs_lms_player_footer` (окно «Связаться» на `.cp-dim/.cp-modal`, `hidden`).
- [ ] `assets/player.js` (self-contained, `fetch( data-ajax, { method: 'POST', keepalive: true } )`; устаревший nonce чинит `nonce-refresh.js` бандла плеера);
  грузится только на маршруте плеера у живого аккаунта.
- [ ] `MasterclassPlayerCallbacks::ajaxContact()` (`Nonce::LearnerActivity->verify()`, ответ `success()` всегда); `MasterclassConversionService::logContact()` — минимально
  здесь (остальное в этапе 5) либо заглушка, решить и записать.
- [ ] Только штатные классы плеера и `Icon`; ни `style=""`, ни `<svg>` в шаблонах.

**Готово, когда:** шаблоны не содержат `style=` и `<svg`; `npx eslint .`, `npx stylelint`, `npx gulp build` чисты.

## 3.5 Тесты контроллеров

- [ ] Коллбек контакта: не залогинен → ошибка; неизвестный `kind` → ошибка; успех всегда `success()`.
- [ ] Контроллер плеера: у учителя/родителя/обычного ученика хуки модуля ничего не выводят.

**Готово, когда:** тесты зелёные.

## 3.6 Живая проверка (HTTP + браузер)

- [ ] HTTP-драйвер: создать мастер-класс с окном «сейчас», добрать 3 аккаунта; войти под `student-N` до начала (окно в будущем), в окне, после окна → в ответе плеера
  есть/нет баннера, «Выйти», нет «Назад»; сдвинуть `freeze_at` в прошлое — урок закрыт окном заморозки, ответы на задания отклоняются (проверка предусловия §11 п.2 вживую);
  `/profile/` и `/sign-in/` ведут в плеер; повторный вход через час — «дома».
- [ ] Браузер (headless Chrome по CDP, временный mu-plugin с одноразовым токеном — **удалить после**): баннер, окно «Связаться», «Выйти» → `/sign-in/`, окно заморозки.
- [ ] Класс за одним IP: > 30 входов в час в окне проходят без капчи (при недоступном скрипте Яндекса), вне окна — лимит ядра прежний.

**Готово, когда:** сценарии проходят; временные пользователи, группа, mu-plugin и скрипты удалены по точным именам.

---

## Проверки этапа

PHPUnit полностью; DI-загрузка (вкл./выкл.); PHPStan 5 по модулю и правленым файлам ядра; `npx eslint .`; `npx stylelint`; `npx gulp build`; 3.6 — на стенде.
Затем **переписать этап 4**.
