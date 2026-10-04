# Экзамены: правила исполнителя и контракт имён

Детализация плана `.docs/Tasks.md`. Один файл на этап: `stage-00.md` … `stage-13.md`
(гостевой этап разбит на `stage-11a.md` и `stage-11b.md`) — всего 15 файлов этапов. Источник требований — `../SPEC.md`,
решения — `../DECISIONS.md`, тексты — `../TEXTS.md`. При расхождении действует SPEC.

Этот файл читается **перед каждой задачей**. В нём то, что одинаково для всех этапов:
как работать, готовые рецепты и единый словарь имён. Имя, которого нет в §7, нельзя
придумывать по месту — сначала добавить его сюда.

## 1. Как работать с задачами

1. Брать задачи по порядку внутри этапа. Одна подзадача (`0.3.2`) — одно небольшое изменение.
2. Перед задачей выполнить блок «Проверить перед началом». Если проверка показала не то,
   что написано в задаче (файл переехал, метод называется иначе) — остановиться и сообщить,
   а не подгонять код под описание.
3. После подзадачи — отметить `[x]` в файле этапа. После последней подзадачи пункта —
   отметить пункт в `.docs/Tasks.md`.
4. Пункт закрыт, только когда пройден его блок «Готово, когда». Нарисованный экран без
   теста и проверки прав закрытым не считается.
5. Коммит и пуш — только по просьбе владельца.

## 2. Перед любой задачей

- Ветка `public-exams` должна содержать `master` (задача 0.0). Проверка:
  `git log --oneline public-exams..master | wc -l` → `0`.
- `git status` — чужие незакоммиченные правки не трогать.
- **Сначала искать готовое.** Перед новым классом, методом, JS-модулем, шаблоном, CSS-классом:
  ```bash
  grep -rn "ключевое_слово" inc src/js src/scss templates | head -30
  ```
  Нашлось почти подходящее — дорабатывать его (параметр, кейс энума), а не писать копию.
- Прочитать раздел SPEC, указанный в задаче.

## 3. Правила кода (выжимка из `CLAUDE.md`, нарушается чаще всего)

| Правило | Как правильно |
|---|---|
| Начало файла | `declare( strict_types=1 );`, типы параметров и возвратов обязательны |
| Строковые ключи | Только энумы: `TableName`, `Capability`, `Nonce`, `AjaxHook`, `OptionName`, `TransientKey`, `CronHook`, `NotificationType`. Сырой строки таблицы, права или экшена в коде быть не должно |
| Хуки WP | `add_action`/`add_filter` — только в `inc/Controllers/*`. Сервис хуков не вешает |
| Доступ к данным | Только через `Repositories`/`Managers`. В сервисе и коллбеке нет `$wpdb`, `get_posts`, `update_option`, `update_post_meta` |
| AJAX: права | Сотрудник — `$this->authorize( Nonce::X, Capability::Y )`. Ученик, родитель, гость — `Nonce::X->verify()` + проверка владельца данных на сервере |
| AJAX: ввод | Трейт `Sanitizer`. **В метод передаётся ИМЯ ключа `$_POST`, а не значение:** `$this->sanitizeInt( 'session_id' )`. Для значений внутри массива — `sanitizeIntValue()`/`sanitizeTextValue()` |
| AJAX: ответ | `$this->success( [...] )`, `$this->error( 'текст' )`, с кодом — `$this->fail( ErrorCode::X, 'текст', $context )`. Никаких `wp_send_json_*`, `echo`, `die` |
| Ошибка сервиса с кодом | `throw new CodedException( ErrorCode::ExamFull, 'текст' )`, коллбек ловит и отвечает `fail()` |
| Логи | `PluginLogger::warning()/exception()`, не `error_log()` |
| DI | Зависимости — типизированные параметры конструктора. Новый `ServiceInterface`-класс (контроллер, CLI-команда) добавить в `Init::getServices()` |
| Ядро и модули | Код в `inc/` (кроме `inc/Modules/`) **не импортирует** классы из `Inc\Modules\*`. Связь — только фильтрами |
| CSS | Только токены из `_variables.scss`, без `style=""` и без стилей из JS. Показ и скрытие — атрибут `hidden`, состояния — классы |
| Иконки | PHP — `Inc\Enums\Ui\Icon`, JS — `src/js/common/icons.js`. Инлайновый `<svg>` запрещён |
| Тексты | Без рода («Запись подтверждена», не «записан»); числа, даты, цены, адреса — только подстановкой. Склонения — `Pluralizer::ru()` (PHP), `pluralRu()` (JS). См. `../TEXTS.md` |
| Кабинет (`/profile/`) | Сеть только через `createApi( cfg.block )` из `src/js/profile/api.js`, прямой `fetch` запрещён |
| Макет | `../index.html`, `../app.js`, `../style.css` — иллюстрация. Разметку, стили и данные из них в код не переносить |
| Несуществующее | Класс, метод, свойство DTO, кейс энума, таблицу и колонку **не выдумывать**: сверить с кодом и DDL (`Migration_1_0_71.php`). Имя вне §7 — сначала в §7, и только если его правда нет в коде |
| Ошибки базы | Репозитории экзаменов — наследники `AbstractExamRepository`, чтение и запись только его хелперами (`readRow`, `readRows`, `readInts`, `readInt`, `insertRow`, `updateRow`, `write`): сбой запроса — исключение, не «пусто» |
| Транзакции | `inTransactionWithRetry()`; **первый оператор — `FOR UPDATE`**, всё для выбора строки читается до `START TRANSACTION` (REPEATABLE READ: снимок создаёт первое обычное чтение). Порядок: участник → участие → сеансы по возрастанию ID. Подробно — `inc/Services/Exam/CLAUDE.md` |
| Время | `NOW()` базы с местным временем не сравнивать; «сейчас» — параметром из `ClockInterface`/`ExamTime` |
| Файлы | Читаемый PHP (табы, по строке на выражение, докблок). Минифицированных однострочников и HTML в контроллерах нет |

## 4. Рецепты

### 4.1 Новое AJAX-действие

1. `inc/Enums/Wp/AjaxHook.php` — кейс по образцу соседних:
   `case GetExamEvents = 'get_exam_events';` (имя PascalCase, значение snake_case, рядом комментарий с параметрами).
2. Метод в коллбеке: имя = `'ajax' . ИмяКейса` → `ajaxGetExamEvents()`.
3. Регистрация: в контроллере-наследнике `Inc\Controllers\System\AjaxController`, метод
   `ajaxActions()` (для вошедших) или `publicAjaxActions()` (для гостей) — пара
   `array( AjaxHook::GetExamEvents, $this->callbacks )`. Образец — `inc/Controllers/Profile/LearnerProfileController.php`.
4. Если нужен новый nonce — кейс в `inc/Enums/Wp/Nonce.php` (значение `fs_lms_...`).
5. Экшен кабинета отдаётся в JS блоком конфига `{ nonce, actions }` — в `TeacherProfileView::teacherConfig()`
   (преподаватель, офис) или в `ProfileViewResolver::jsConfig()` (ученик, родитель). JS: `const api = createApi( window.fsProfile.exams ); api( 'getEvents', { ... } )`.
6. Тест коллбека — `tests/Unit/Callbacks/Exam/…Test.php` по образцу `tests/Unit/Callbacks/Course/RoomCallbacksTest.php`.

### 4.2 Новая таблица или колонка

Живые установки уже имеют `fs_lms_schema_version`, поэтому нужно **оба** места:

1. `inc/Enums/Settings/TableName.php` — кейс таблицы.
2. `inc/Migrations/Migration_1_0_0.php` — DDL в `up()` (новая установка) и таблица в списке `down()`.
3. Новый класс `inc/Migrations/Migration_1_0_NN.php` (`NN` — номер версии плагина, в которой выходит
   изменение; образец — `Migration_1_0_70.php` для таблицы, `Migration_1_0_54.php` для колонки).
   `up()` идемпотентен: `dbDelta()` для таблиц, `SHOW COLUMNS … LIKE` перед `ALTER TABLE … ADD COLUMN`.
4. Зарегистрировать миграцию в **двух** местах: `inc/Core/Activate.php` и `inc/Init.php`
   (рядом с `Migration_1_0_70`).
5. **Запрещено** сбрасывать `fs_lms_schema_version` в `0.0.0`: повторный `Migration_1_0_0::up()`
   на базе с людьми недопустим. Запрещены `DROP TABLE` и `DROP COLUMN` существующих таблиц.
6. Проверка на dev: открыть любую страницу сайта (миграция накатится из `Init::run()`), затем
   `docker exec wp_db mariadb -u root -proot wordpress -e "SHOW CREATE TABLE wp_fs_lms_exam_events\G"`.

### 4.3 Новое право

1. `inc/Enums/Access/Capability.php` — кейс с докблоком.
2. `inc/Enums/Access/UserRole.php`, метод `capabilities()` — добавить право нужным ролям.
3. `inc/Managers/Person/RoleManager.php`, `syncCapabilities()` — блок `$admin->add_cap( … )` для роли `administrator`.
4. `inc/Init.php` — поднять `$capsVersion` (сейчас `'5.6'`) и дописать комментарий, что добавлено.
   Это и есть «миграция ролей»: на следующей загрузке `registerAll()` выдаст права.
5. Тест — `tests/Unit/Enums/UserRoleTest.php`.

### 4.4 Тесты

| Что проверяем | Где и как |
|---|---|
| Сервис | `tests/Unit/Services/Exam/…Test.php`, зависимости — `$this->createMock()`, время — мок `ClockInterface` |
| Коллбек | `tests/Unit/Callbacks/Exam/…Test.php`: `fs_test_reset_ajax()` в `setUp()`, вход через `$_POST`, вызов через `fs_test_capture_json( fn() => $cb->ajaxX() )`, права — `$GLOBALS['_fs_test_can']`, nonce — `$GLOBALS['_fs_test_nonce_ok']` |
| SQL репозитория | `tests/Integration/Repositories/Exam/…Test.php` на `tests/Support/FakeWpdb.php` — проверяет форму запроса и маппинг в DTO |
| Гонки и блокировки | **Только** реальная MariaDB: стенд `wp fs-lms exam stand …` (этап 3.5). FakeWpdb гонок не доказывает |
| Фильтры | `$GLOBALS['_fs_test_filter_returns']['имя_фильтра'] = значение;` |
| JS-модель | `tests/js/*.test.mjs`, запуск `npm run test:js` |

Команды:

```bash
vendor/bin/phpunit --filter ИмяТеста      # один тест
npm run ci                                # eslint + stylelint + сборка стилей + PHPUnit + JS-тесты
npx gulp build                            # собрать бандлы после правок src/js, src/scss
docker restart wp_app                     # после правок PHP, если поведение не изменилось (OPcache)
docker compose -f /Users/daniil/FS-LMS/docker-compose.yml run --rm wpcli wp <команда>
docker exec wp_db mariadb -u root -proot wordpress -e "SELECT …"
```

Порт MariaDB наружу не проброшен: PHPUnit с хоста до базы не достаёт. Всё, что требует настоящей
базы, запускается внутри WP — командой WP-CLI.

## 5. Время

- Во всех **новых** таблицах `exam_*` время хранится в **UTC** (`Y-m-d H:i:s`).
- В **старых** таблицах (`assessment_attempts.started_at/deadline_at`, `group_lessons.scheduled_at`)
  время — местное время сайта. Менять это нельзя.
- Единственное место перевода — `Inc\Services\Exam\ExamTime` (этап 2.3): `nowUtc()`, `toUtc( $local )`,
  `toLocal( $utc )`. Сравнивать время новой и старой таблицы без `ExamTime` запрещено.
- В JSON для клиента уходит местное время сайта (после `toLocal()`), клиент часовых поясов не считает.

## 6. Права и роли

| Право (`Capability`) | Значение | Преподаватель | Методист | Администратор WP | Офис (`lms_office`) |
|---|---|---|---|---|---|
| `ManageExams` | `manage_lms_exams` | да, свои предметы | да, все | да, все | нет |
| `ManageExamGuests` | `manage_lms_exam_guests` | да, свои предметы | да | да | нет |
| `ShareExamResults` | `share_lms_exam_results` | да, свои предметы | да | да | нет |
| `ResolveExamPayments` | `resolve_lms_exam_payments` | нет | нет | да | да |

«Свои предметы» проверяет `ExamAccessGuard` (этап 1.2), а не право: право открывает раздел, guard — конкретное проведение.
Пункты меню кабинета показываются **по праву** (`user_can`), а не по роли: администратор WordPress
без LMS-роли получает витрину офиса, но обязан видеть экзамены.

## 7. Контракт имён

### 7.1 Таблицы (`TableName`)

| Кейс | Значение |
|---|---|
| `ExamEvents` | `fs_lms_exam_events` |
| `ExamSessions` | `fs_lms_exam_sessions` |
| `ExamParticipants` | `fs_lms_exam_participants` |
| `ExamParticipations` | `fs_lms_exam_participations` |
| `ExamRegistrations` | `fs_lms_exam_registrations` |
| `ExamSources` | `fs_lms_exam_sources` |
| `ExamAccessTokens` | `fs_lms_exam_access_tokens` |
| `ExamGuestSessions` | `fs_lms_exam_guest_sessions` |
| `ExamReports` | `fs_lms_exam_reports` |
| `ExamReportMembers` | `fs_lms_exam_report_members` |
| `ExamOutbox` | `fs_lms_exam_events_outbox` |
| `ExamOperationKeys` | `fs_lms_exam_operation_keys` |
| `ExamGuestApplications` | `fs_lms_exam_guest_applications` |
| `ExamPaymentLinks` | `fs_lms_exam_payment_links` |
| `ExamManualResolutions` | `fs_lms_exam_manual_resolutions` |

Полный DDL — `stage-02.md`, задача 2.1.

### 7.2 Энумы (`inc/Enums/Exam/`, namespace `Inc\Enums\Exam`)

У каждого энума со статусом есть `label(): string` — подпись для интерфейса без рода.

| Энум | Кейсы (значения) |
|---|---|
| `ExamDirection` | `Ege` (`ege`), `Oge` (`oge`); `grade(): int` → 11 / 9 |
| `ExamEventStatus` | `Draft`, `Published`, `Completed`, `Cancelled` (`draft`, `published`, `completed`, `cancelled`) |
| `ExamSessionStatus` | `Open`, `Cancelled`, `Completed` (`open`, `cancelled`, `completed`) |
| `ExamRegistrationStatus` | `Confirmed`, `Cancelled`, `Transferred`, `Missed` |
| `ExamAudience` | `Student` (`student`), `Guest` (`guest`) |
| `ExamProgress` | `NotStarted`, `InProgress`, `Submitted`, `Missed` — вычисляемое состояние участника в таблице сеанса |
| `GuestApplicationState` | `Hold`, `AwaitingPayment`, `PaymentPending`, `Confirmed`, `ExpiredUnpaid`, `Failed`, `PaidNeedsResolution`, `Cancelled`, `Missed` (значения — snake_case: `paid_needs_resolution`) |
| `ExamPaymentState` | `Pending`, `Paid`, `Failed`, `Cancelled` |
| `ExamTokenPurpose` | `Invitation`, `Entry`, `Result`, `Report`, `Payment` (ссылка на оплату заявки, созданной сотрудником на месте, — 11a.7) |
| `ManualResolutionKind` | `Pending`, `Transferred`, `RefundedOutside` (`refunded_outside`), `Other` |
| `ExamOutboxEvent` | `EventPublished`, `RegistrationOpened`, `RegistrationConfirmed`, `RegistrationTransferred`, `RegistrationCancelled`, `ParticipantMissed`, `EntryOpened`, `AttemptStarted`, `AttemptSubmitted`, `AttemptApproved`, `ResultCorrected`, `AttemptExtended`, `SessionMoved`, `SessionCancelled`, `EventCancelled`, `PaidNeedsResolution`, `ReconcileFailed`, `SourceLimitExceeded` |

### 7.3 Коды ошибок (`inc/Enums/Log/ErrorCode.php`)

SPEC §11 называет UI-коды словами; в коде это кейсы `ErrorCode` с префиксом `X-`.

| Код SPEC | Кейс | Значение | Когда |
|---|---|---|---|
| `full` | `ExamFull` | `X-FULL` | мест нет |
| — | `ExamHeld` | `X-HELD` | мест нет, но часть занята временными бронями гостей |
| `registration_closed` | `ExamClosed` | `X-CLOSED` | запись не открыта или закрыта |
| `conflict` | `ExamConflict` | `X-CONFLICT` | пересечение с другим экзаменом, занятый кабинет |
| `already_started` | `ExamStarted` | `X-STARTED` | попытка уже начата или сдана |
| `stale_version` | `ExamStale` | `X-STALE` | версия записи устарела |
| `invalid_link` | `ExamLink` | `X-LINK` | ключ недействителен |
| `consent_required` | `ExamConsent` | `X-CONSENT` | нет обязательного согласия |
| — | `ExamAccess` | `X-ACCESS` | нет права на проведение или участие |
| — | `ExamNotOpen` | `X-NOT-OPEN` | старт вне окна сеанса |
| — | `ExamLimit` | `X-LIMIT` | превышен лимит по IP или источнику |
| — | `ExamReplay` | `X-REPLAY` | тот же `request_key` с другими данными |
| — | `ExamRoom` | `X-ROOM` | кабинет не подходит: нет вместимости, не разрешён предмет, неактивен |

### 7.4 Классы

| Слой | Каталог | Классы |
|---|---|---|
| DTO | `inc/DTO/Exam/` | `ExamFormatDTO`, `ExamEventDTO`, `ExamSessionDTO`, `ExamParticipantDTO`, `ExamParticipationDTO`, `ExamRegistrationDTO`, `ExamSourceDTO`, `ExamAccessTokenDTO`, `ExamGuestSessionDTO`, `ExamOperationKeyDTO`, `ExamOutboxEventDTO`, `ExamGuestApplicationDTO`, `ExamPaymentLinkDTO`, `ExamManualResolutionDTO`, `ExamReportDTO`, `ExamReportMemberDTO`, `AttemptContext`, `RegistrationResultDTO` |
| Репозитории | `inc/Repositories/WPDBRepositories/` (общий каталог слоя, подкаталога `Exam/` нет) | `AbstractExamRepository` (общая база: чтение и запись с разбором ошибок 1213/1205/1062; сам разбор — трейт `Shared\Traits\RaisesDbError`), `RetryableDbException`, `DuplicateKeyException`, `ExamLockRepository` (именованная блокировка тиков), `ExamEventRepository`, `ExamSessionRepository`, `ExamParticipantRepository`, `ExamParticipationRepository`, `ExamRegistrationRepository`, `ExamSourceRepository`, `ExamAccessTokenRepository`, `ExamGuestSessionRepository`, `ExamOutboxEventRepository`, `ExamOperationKeyRepository`, `ExamGuestApplicationRepository`, `ExamPaymentLinkRepository`, `ExamManualResolutionRepository`, `ExamReportRepository`, `ExamReportMemberRepository` |
| Сервисы | `inc/Services/Exam/` | `ExamFormatRegistry` (0.3), `ExamAudienceResolver` (1.1), `ExamAccessGuard` (1.2), `ExamVariantPolicy` (1.3), `ExamTime` и `ExamOutbox` (2.3), `ExamEventService`, `ExamRoomService` и `ExamPlanService` (2.4, 4.2; обратная проверка кабинета — 4.4: единая точка `Course\RoomAvailabilityService`), `ExamAccessTokenService` (2.5), `ExamTickLock` и `ExamTickService` (2.6; готовы — минутный тик автоистечения и неявок подключён к `CronController`, `wp fs-lms exam tick`), `ExamRegistrationService` (3.1–3.3), `ExamHoldService` (3.4), `ExamSourceService` (4.6; доступ — `ExamAccessGuard::canManageEvent()`/`canManageEventGuests()`), `LearnerExamsService` (5), `Assessment\AssessmentKindGuard` (6.6.3: станцию нельзя назначить работе, стоящей в уроке), `ExamAttemptService` (6.1–6.2), `ExamNoShowService` (6.3), `ExamReviewProjection` (7.1; имя `ExamResultService` уже занято в ядре), `ExamScoreService` (7.3), `ExamConductService` (8.1–8.3), `ExamApprovalService` (8.5–8.6), `ExamNotificationComposer`, `ExamOutboxWorker`, `ExamReminderService` (9), `ExamStatsService` (10), `GuestIdentity`, `GuestApplicationService`, `ExamLaunchChecklist` (11a), `GuestSessionService` (11a.2, 11b), `ExamReportService` (12), `ExamVariantGuard` и `ExamRetentionService` (13) |
| Оплата | `inc/Services/Exam/Payment/` | `WooGateway` (единственная обёртка над функциями WooCommerce, мокается в тестах), `WooExamAdapter`, `ExamPaymentReconciler` |
| Общие | `inc/Services/Shared/` | `CenterContactsService` (11a.6) |
| Коллбеки | `inc/Callbacks/Exam/` | `ExamEventCallbacks`, `ExamSourceCallbacks`, `LearnerExamCallbacks`, `ExamConductCallbacks`, `ExamResultCallbacks`, `ExamStatsCallbacks`, `ExamPaymentQueueCallbacks`, `GuestApplicationCallbacks`, `GuestEntryCallbacks`, `ExamReportCallbacks`, `WooExamCallbacks` |
| Контроллеры | `inc/Controllers/Exam/` | `ExamController` (все AJAX экзаменов), `ExamGuestPageController` (страницы гостя и отчёта; **создаётся на 11a** — прежняя заглушка с HTML в контроллере удалена 2026-10-04), `WooExamController` (хуки WooCommerce; пока только совместимость с HPOS), `ExamVariantGuardController` (хуки заморозки варианта, 13.2) |
| CLI | `inc/Cli/` | `ExamCommand` (`wp fs-lms exam audience`, `exam tick`), `ExamStandCommand` (`exam selftest`, `exam stand-*` — стенд гонок), `SelfTestRollback` (сигнал отката самопроверки) |

В ядре уже есть `Inc\Services\Assessment\ExamResultService` и `ExamLockService` — их не переименовывать
и не путать с новыми классами.

### 7.5 Nonce, cron, фильтры

| Что | Имя | Значение |
|---|---|---|
| Nonce сотрудника | `Nonce::ExamManage` | `fs_lms_exam_manage` |
| Nonce ученика и родителя | `Nonce::ExamLearner` | `fs_lms_exam_learner` |
| Nonce гостя | `Nonce::ExamGuest` | `fs_lms_exam_guest` |
| Nonce очереди оплат | `Nonce::ExamPayments` | `fs_lms_exam_payments` |
| Cron: автоистечение и неявка | `CronHook::ExamAutoExpireTick` | `fs_lms_exam_auto_expire_tick` |
| Cron: брони гостей и сверка | `CronHook::ExamHoldReleaseTick` | `fs_lms_exam_hold_release_tick` |
| Cron: worker событий | `CronHook::ExamOutboxTick` | `fs_lms_exam_outbox_tick` |
| Фильтр форматов | `ExamFormatRegistry::FILTER` | `fs_lms_exam_formats` |
| Фильтр контактов центра | `CenterContactsService::FILTER` | `fs_lms_center_contacts` |
| Транзиент синхронизации аудитории | `TransientKey::ExamAudienceSync` | `fs_lms_exam_audience_sync_` |
| Страница формы гостя | `PageRoutes::ExamSignup`, `ShortCode::ExamSignup` | `exam-signup`, `fs_lms_exam_signup` |
| Страница входа гостя | `PageRoutes::ExamEntry`, `ShortCode::ExamEntry` | `exam-entry`, `fs_lms_exam_entry` |
| Страница результата гостя | `PageRoutes::ExamResult`, `ShortCode::ExamResult` | `exam-result`, `fs_lms_exam_result` |
| Страница школьного отчёта | `PageRoutes::ExamReport`, `ShortCode::ExamReport` | `exam-report`, `fs_lms_exam_report` |
| Кука приглашения / входа / результата / отчёта | — | `fs_exam_inv`, `fs_exam_guest`, `fs_exam_result`, `fs_exam_report` (все `HttpOnly`, сессионные) |

### 7.6 Экраны кабинета

| Ключ экрана | Кому | Заголовок |
|---|---|---|
| `exam-conduct` | сотрудник | Проведение экзамена |
| `exam-stats` | сотрудник | Статистика |
| `exam-plan` | сотрудник | Назначить экзамен |
| `exam-results` | сотрудник | Результаты |
| `exam-payments` | `ResolveExamPayments` | Оплаты гостей |
| `learner-exams` | ученик, родитель | Мои экзамены |
| `exam-review` | ученик, родитель (программно) | Результаты экзамена |

JS-файлы экранов — `src/js/profile/exams/*.js`, стили — `src/scss/profile/components/_exams.scss`.

## 8. Решения, принятые при детализации (подтвердить у владельца)

Эти места в SPEC и плане допускали два прочтения. Чтобы исполнитель не выбирал сам, выбрано одно.

1. **Хеш ключа приглашения хранится только в `exam_access_tokens`** (`purpose = invitation`, `target_id = source_id`).
   В `exam_sources` остаются `key_generation` и `key_revoked_at`, колонки `invitation_key_hash` нет.
   SPEC §6 и §11 описывали оба варианта; два места хранения дали бы два источника истины.
2. **Расписание `NotificationsTick` не меняется** (остаётся раз в 15 минут). Раз в минуту идут только
   новые экзаменные тики, серверный cron раз в минуту дёргает `wp-cron.php`. Формулировку 2.6
   «запуск `NotificationsTick`» читаем как «минутный запуск запускает всё, чему пришло время».
3. **Проведение `cancelled` — отдельный статус** `ExamEventStatus::Cancelled` (SPEC: «cancellation отдельно»).
4. **ФИО гостя хранится зашифрованным** (`PiiCryptoService`), для поиска дублей — хеши ФИО и телефона.
5. **Число позиций формата** ядро получает фильтром `fs_lms_exam_formats`; при выключенном модуле
   `EgeComputer` конструктор и проверка полноты работают по-старому (по термам), а проведения КЕГЭ/ОГЭ недоступны.
6. **Нумерация этапа 8** исправлена: в плане было два пункта 8.3. Теперь 8.1–8.9.
7. **Чистый методист без роли преподавателя** сейчас не имеет витрины `/profile/` и уходит в wp-admin.
   Право `ManageExams` он получает, но экраны экзаменов живут в кабинете. До решения владельца
   методист работает с экзаменами только при второй роли (преподаватель или администратор). См. «Открыто» в `Tasks.md`.

8. **Автоистечение экзаменной попытки = сдача по дедлайну с обычной проверкой** (`submitted_at = deadline_at`, 6.2), а не пометка `expired`
   без проверки, как у попыток курса. Основание — SPEC §4: «принять уже сохранённые ответы, завершить и проверить».
9. **Экзаменный ОГЭ ученика требует явного утверждения** (7.5.1), хотя ОГЭ в курсе раскрывается сразу после ручной проверки. Основание — SPEC §7.
10. **Пробные записи (`isTrial`) в аудиторию не входят** (1.1.2): пробный доступ — не зачисление.
11. **Глобальный охват по предметам** = право `ManageExams` + (`manage_options` или `ManageSubjects`) (1.2.2). Офис под это не попадает: у него нет `ManageExams`.
12. **«Добавить гостя на месте» выдаёт ссылку на оплату** (`ExamTokenPurpose::Payment`, 11a.7): корзина привязана к браузеру, поэтому платит гость
    со своего устройства по ссылке от сотрудника. Кнопки «отметить оплаченным» нет.
13. **Последний вход гостя побеждает** (11b.1.5): открытие новой ссылки входа отзывает прежние сессии входа этого участия; попытка и ответы общие.
14. **Строка школьного отчёта адресуется порядковым номером в отчёте** (`p=N`, 12.3.4), а не идентификатором участия или попытки.
15. **Номер версионной миграции** в `stage-02.md` назван `Migration_1_0_71` условно. На момент детализации `master` уже на версии 1.0.72,
    новых миграций после `Migration_1_0_70` нет — класс получит номер релиза, в котором выйдет этап 2 (спросить у владельца).
16. **Версия участия — защита от устаревшей вкладки (SPEC §11).** Любое изменение указателя участия увеличивает `exam_participations.version`; ученик передаёт
    версию из карточки при переносе и отмене, расхождение — `ExamStale`. Это уточняет 3.3.5 («отдельный `stale_version` для записи не нужен»): при расхождении действует SPEC.
17. **Родителю из действий остаётся «Результаты».** Запись, перенос, отмена, запуск и продолжение — только у ученика; результат ребёнка родитель смотрит на том же экране.
18. **Адрес станции для официальной попытки — `?exam_reg=ID`** (`AssessmentManager::EXAM_REGISTRATION_PARAM`, `examStationUrl()`); вариант записи обязан совпадать
    с открытой работой, иначе обычный 404.
