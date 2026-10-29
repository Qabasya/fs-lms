# Мастер-классы: контракт имён и классов

Источник правды о том, **какие классы создаются, что в них есть, что возвращают и от чего зависят**. Продуктовая часть —
`SPEC.md`. «(новое)» — в коде ещё нет; «(изменить)» — существующий класс ядра; остальное проверено по коду на 2026-10-28 (ред. 3, `SPEC.md` §16).

Правила для каждого файла (корневой `CLAUDE.md`, `basic_doc.md` §26):

- `declare( strict_types=1 )`, типы параметров и возвратов, ООП, табы, по строке на выражение, докблок «зачем».
- **Граница модуля.** Код ядра (`inc/` вне `inc/Modules/Masterclass/`) не содержит ни одного `use Inc\Modules\Masterclass\…`,
  имени таблицы, опции, экшена или строки «masterclass». Модуль публичные классы ядра использовать может. Проверка этапа:
  `grep -rni masterclass inc --exclude-dir=Modules` пуст.
- Слои внутри модуля те же: Controllers — только хуки; Callbacks — AJAX/хук-обработчики; Services — логика; Repositories — БД.
- DI: зависимости — типизированные параметры конструктора. Ядру известен один класс — `MasterclassModule` (строка в
  `Init::getServices()`); остальное контейнер собирает сам.
- AJAX-экшены — **константы контроллера модуля** (как `VideoLibrarySettingsController::SAVE_ACTION`), не кейсы core `AjaxHook`.
  Регистрация — `add_action( 'wp_ajax_' . self::X, … )`, nopriv-экшенов нет.
- Проверка запроса: `$this->authorize( Nonce::Config, Capability::ManageLmsPlatform )` (админка) и `Nonce::LearnerActivity->verify()`
  (плеер). Ввод — методы `Sanitizer` (передаётся **имя ключа**). Ответ — `$this->success()` / `$this->error()`.
- Транзакции — `TransactionRunner::inTransaction()`; `$wpdb->prepare()` для любого значения; имена таблиц — статические методы
  `MasterclassSchema` (как `VideoSchema::table()`).
- Ключи, состояния, действия журнала — энумы и константы модуля, сырых строк нет.
- HTML — в `inc/Modules/Masterclass/templates/` (подключение `require $this->path( … )`, как у VideoLibrary: `TemplateRenderer`
  ищет только в `templates/` ядра). Инлайновых `<svg>` и `style=""` нет (иконки — `Icon`).
- Логи — `PluginLogger`. Событие журнала — после успешной операции, вне блокировок, сбой записи гасится.
- Время: колонки `masterclasses` / `masterclass_accounts` / `masterclass_log` — **UTC**; колонки `group_lessons` — местное (как во
  всём ядре). Переводит только `ExamTime`.

## 1. Карта файлов

```
inc/Modules/Masterclass/
├── MasterclassModule.php                         bootstrap (единственный класс, известный ядру)
├── CLAUDE.md                                     правила модуля (§13)
├── Config/MasterclassConfig.php
├── Schema/MasterclassSchema.php
├── Enums/        MasterclassAccountState, LoginPlace, MasterclassContactKind, MasterclassLogAction, AccountDeleteReason
├── DTO/          MasterclassDTO, MasterclassInputDTO, MasterclassAccountDTO, MasterclassViewDTO, MasterclassFlyerDTO,
│                 MasterclassFunnelDTO, MasterclassLogDTO, MasterclassLogInputDTO
├── Repositories/ MasterclassRepository, MasterclassAccountRepository, MasterclassLogRepository
├── Services/     MasterclassService, MasterclassAccountService, MasterclassAccessGuard, MasterclassLoginTracker,
│                 MasterclassConversionService, MasterclassLifecycleService, MasterclassLogWriter
├── Controllers/  MasterclassSettingsController, MasterclassIntegrityController, MasterclassAdminController,
│                 MasterclassAuthController, MasterclassPlayerController, MasterclassEnrollmentController,
│                 MasterclassCronController
├── Callbacks/    MasterclassSettingsCallbacks, MasterclassAdminCallbacks, MasterclassPlayerCallbacks
├── templates/    settings-tab.php, admin-page.php, admin-form.php, admin-accounts.php, admin-log.php, flyers.php,
│                 player-banner.php, player-actions.php, player-contact.php, player-lock.php
└── assets/       settings.js, admin.js, flyers.css, player.js
```

Тесты — `tests/Unit/Modules/Masterclass/` и `tests/Integration/Modules/Masterclass/` (репозитории на дубле wpdb); проверки на настоящей MariaDB и загрузка DI —
скрипты `tests/stand/masterclass-schema-check.php` и `tests/stand/di-boot-check.php`. `assets/settings.js` (вкладка настроек, этап 1) добавлен к списку: у
вкладки «Настройки → Мастер-классы» свой скрипт, `admin.js` страницы модуля грузится только на ней.

## 2. Изменения ядра (универсальные швы, этап 0)

Каждый шов — докблок с контрактом и тест ядра «без подписчиков — прежнее поведение». Слова «мастер-класс» в ядре нет.

### Ш1. `Services\Course\LessonAccessPolicy` (изменить)
Тело `resolve()` переносится в `private function resolveBase( StudentRecordDTO, GroupLessonDTO ): AccessLevel`;
`resolve()` = `$base = resolveBase(...)`; `$filtered = apply_filters( 'fs_lms_lesson_access', $base, $record, $lesson )`;
возвращает `$filtered`, только если это `AccessLevel` и он **не выше** `$base` (порядок `None < Read < ReadSubmit`), иначе `$base`.
Нужен метод `AccessLevel::rank(): int` (изменить `Enums\Access\AccessLevel`: сейчас там только кейсы `None`, `Read`, `ReadSubmit`). Тесты: без подписчика — прежняя матрица;
подписчик может понизить; попытка повысить игнорируется.
**Сделано (этап 0).** Подписчик получает `( AccessLevel $level, StudentRecordDTO $record, GroupLessonDTO $lesson )` — регистрировать `add_filter( …, 10, 3 )`.
Значение не-`AccessLevel` игнорируется. Тесты — `LessonAccessPolicyTest` (шов), `AccessLevelTest`, `LessonFreezeChainTest` (понижение закрывает шаг урока в
`LessonGateResolver`, а значит ответы и прогресс: оба колбэка идут через гейт).

### Ш2. Плеер (изменить `Controllers\Course\LessonPlayerController`, `templates/frontend/lesson-player/player.php`)
Только в виде ученика (`! $isTeacher && ! $isParent`; в предпросмотре — не вызываются):
- `$back_url = (string) apply_filters( 'fs_lms_player_back_url', $back_url, $userId, $row )`; пустая строка — `.s-back` не выводится.
- В `.lock-modal`, когда `$locked`: `$lock_html = (string) apply_filters( 'fs_lms_player_lock_html', '', $userId, $row )`;
  непустая — выводится **вместо** стандартных веток (уже экранированный HTML шаблона модуля), иконка `.lock-ico` остаётся.
- `do_action( 'fs_lms_player_header_actions', $userId, $row )` — внутри `.s-right` перед кнопкой-колокольчиком.
- `do_action( 'fs_lms_player_before_content', $userId, $row )` — перед `<div class="player" id="fsPlayer">`.
- `do_action( 'fs_lms_player_footer', $userId, $row )` — перед `wp_footer()`.
**Сделано (этап 0).** Шаблону контроллер отдаёт `$player_user_id` (int) и `$player_row` (`GroupLessonDTO`); шаблон выводит их в швы как
`$userId` и `$row`. Переменные не заданы в предпросмотре курса (`CoursePreviewController`) — там швы выключены. Порядок экшенов:
`header_actions` → `before_content` → `footer`. Общий компонент `.s-banner` (`.s-banner__text`, `.s-banner__actions`) — в
`src/scss/player/components/_shell.scss`, на токенах темы. Кейсы `Icon::Phone`, `Icon::Message`, `Icon::Logout` (сетка 20, глифы Feather
приведены масштабом группы; JS-двойники не нужны). Кнопка «Вернуться к курсу» в конце урока тоже пропадает при пустом `back_url`.
Тесты — `PlayerSeamsRenderTest` (рендер настоящего `player.php`), `IconTest`.

### Ш3. `Services\Deletion\GroupDeletionHandler::handle()` (изменить)
Первой строкой, до транзакции: `do_action( 'fs_lms_group_deleting', $event->groupId, $event->actorId )`. Докблок: подписчик
убирает свои данные, связанные с группой (учётки временных учеников ядро не трогает — `deleteAllByGroupAndCollect()` собирает
только `is_trial = 0`); исключение подписчика удаление группы не останавливает (`try/catch` + `PluginLogger::exception( …, true )`).
**Сделано (этап 0);** тесты — `GroupDeletionHandlerTest` (экшен раньше первого чтения данных, упавший подписчик не мешает).

### Ш4. Служебные группы (изменить)
Фильтр `fs_lms_service_group_ids` → `list<int>`: группы, созданные модулями для своих целей, — не для зачисления настоящих
учеников. Чтение — новый метод `Services\Group\ServiceGroupResolver` (новое, ядро): `ids(): array` (кеш на запрос),
`isService( int $groupId ): bool`. Потребители:
- `Callbacks\Enrollment\EnrollmentLifecycleCallbacks::ajaxGetStudentGroups()` — исключить из списка;
- `Services\Enrollment\OpenGroupEnrollmentService::enrollableGroup()` — общая проверка для `enrollMany()` и `planFromSubject()`
  («Добавить всех учеников предмета»): служебная группа → `\InvalidArgumentException( 'В служебную группу учеников не добавляют.' )`;
- `Services\Export\StudentsExportProvider::rows()` — ученик, у которого все записи в служебных группах, пропускается
  (`ServiceGroupResolver` приходит через конструктор `AbstractPersonExportProvider`);
- вкладка «Ученики» админки (`templates/admin/components/tabs/userlist-tabs/userlist-2-students.php`): фильтр
  `exclude_group_ids` в `StudentRecordRepository::buildWhereClause()` (`NOT IN`, список и счётчик «Найдено учеников»), служебные группы
  убраны и из выпадающего списка групп.
**Сделано (этап 0).** Резолвер — обычный класс без зависимостей, в `Init::getServices()` не нужен (контейнер собирает сам). Решения по остальным
местам выбора группы — таблица в `tasks/stage-00.md`. Тесты — `ServiceGroupResolverTest`, `OpenGroupEnrollmentServiceTest`,
`StudentsExportServiceGroupTest`, `EnrollmentLifecycleCallbacksTest`, `StudentRecordRepositoryIntegrationTest`; фильтр вкладки проверен на `wp_db`.

### Ш5. `Controllers\Pages\ApplyPageController::renderApplyForm()` (изменить)
`$prefill = (array) apply_filters( 'fs_lms_apply_prefill', $prefill ?? array() )` — после разбора ссылки гостя экзамена; ключи —
те же, что читает `templates/frontend/apply-fields.php` (`subject_key`, `school`, …).
**Сделано (этап 0).** Токен гостя экзамена определяется ссылкой, а не ответом шва: модуль не может «выдать» себя за гостя. Не-массив от подписчика
считается пустым. Тест — `ApplyPageControllerTest`. **Для этапа 5:** в `apply-fields.php` `autofocus` стоит на почте при любом непустом
`$prefill` (задумано для гостя экзамена, у которого фамилия и имя уже заполнены); у мастер-класса имя пустое, и курсор окажется не на первом
поле — решить в этапе 5 (например, служебный ключ предзаполнения или `autofocus` по тому, заполнено ли имя).

### Ш6. `Services\Captcha\CaptchaService::check()` (изменить)
Перед `allowCaptchaFallback()`: если `$failure?->allowsFallback()` и
`true === apply_filters( 'fs_lms_captcha_fallback_unlimited', false, $scope, (string) $identifier, $remoteIp )` — лимит по IP не
применяется (запись `AuthAction::CaptchaFallback` в журнал остаётся, в `note` — «без лимита по правилу модуля»).
**Сделано (этап 0).** Шов спрашивается только при токене-пустышке и сбое доставки, который разрешает пропуск (не при токене, закрытом задании или
неразрешающем сбое); снимает лимит только строгое `true`. **Ограничение «только для входа» ядро не накладывает: оно в подписчике** (модуль проверяет
`CaptchaScope::Login === $scope` и логин `student-N`, этап 3). Тесты — `CaptchaServiceTest` (мутации: нестрогое `true`, обход проверки сбоя).

### Ш7. `Repositories\WPDBRepositories\StudentRecordRepository::findActiveTrials()` (изменить)
Добавить `AND EXISTS ( SELECT 1 FROM {applications} a WHERE a.student_person_id = r.student_person_id )` (`TableName::Applications`):
плитка «Временный доступ: ждём анкету родителя» (`NotificationCronService::trialAccess()`) — только ученикам с заявкой, так
устроен `TrialAccessService::grant()`. Тест на MariaDB.

### Прочее в ядре
- `Services\Course\OpenCourseValidator`: выделить `public function lessonProblems( LessonDTO $lesson ): array` (тело цикла
  `problems()`); `problems()` зовёт его. Поведение не меняется.
- `Init::getServices()`: `MasterclassModule::class` рядом с `VideoLibraryModule` (+ `use`).
- Предусловия из `SPEC.md` §11 (исправления текущего кода) — до этапа 2.
- **Аудит этапа 2 (п. 2.7): ещё четыре места ядра обходят служебные группы** (всё через `ServiceGroupResolver`, без слов модуля в ядре):
  `ServiceGroupResolver::onlyServiceRecords( list<StudentRecordDTO> ): bool` — единое правило «все записи ученика в служебных группах» (выгрузка учеников переведена на него);
  `StudentBundleExporter` (пакет переноса предмета) пропускает служебную группу целиком — иначе в чужой сайт уехали бы временные аккаунты с паролями открытым текстом;
  `StudentDirectoryService` («Сводка по ученику») не показывает служебные группы; `StudentGroupCallbacks::ajaxSearchStudentsForGroup()` и `PersonRepository::searchStudents( …, $serviceGroupIds )`
  (центр печати; `NOT EXISTS`/`EXISTS` по `student_records`) скрывают учеников, у которых все записи в служебных группах. Конструкторы этих классов получили `ServiceGroupResolver`.
  Решения по остальным местам — таблица в `tasks/stage-02.md` п. 2.7. Тесты: `ServiceGroupResolverTest`, `StudentBundleExporterServiceGroupTest`, `StudentDirectoryServiceGroupTest`,
  `StudentGroupSearchCallbacksTest`, `PrintCenterSearchServiceGroupTest`, `PersonRepositoryIntegrationTest`; SQL поиска — на `wp_db` (`masterclass-stage2-check.php`, раздел I).
- Тестовый `tests/bootstrap.php`: заглушки `wp_enqueue_script` / `wp_localize_script` (складывают в `$GLOBALS['_fs_test_scripts']` / `['_fs_test_localized']`). Заглушка `add_query_arg` принимает только массив
  аргументов — в шаблонах `add_query_arg( array( 'paged' => 2 ), $url )`, не `add_query_arg( 'paged', 2, $url )`.

## 3. Схема (`Schema\MasterclassSchema`, новое)

По образцу `VideoSchema`: `VERSION_OPTION = 'fs_lms_masterclass_schema_version'`, `VERSION = '1'`;
`public static function masterclasses(): string`, `accounts(): string`, `log(): string` — полные имена таблиц;
`public function ensure(): void` — `dbDelta` трёх DDL, только переносимый SQL (без `IF NOT EXISTS` у колонок); новая колонка
в будущем — `SHOW COLUMNS … LIKE` + обычный `ADD COLUMN` и подъём `VERSION`. Вызывается из `MasterclassModule::register()`
до любых зависящих от таблиц регистраций, **в том числе при выключенном модуле** (Ш1 и `deleted_user` читают таблицы).
Удаление таблиц — общий `uninstall.php` (по префиксу `fs_lms_`).
**Сделано (этап 1).** `VERSION_OPTION` и `VERSION` — публичные константы; DDL вынесены в публичные `masterclassesDdl()`, `accountsDdl()`, `logDdl( string $collate )`
(так колонки и ключи проверяет юнит-тест по таблицам ниже, а `ensure()` лишь зовёт `dbDelta`). На `wp_db` проверено: `ensure()` на чистой базе создаёт три таблицы,
повторный вызов (с сброшенной опцией, то есть повторный `dbDelta`) не меняет `SHOW CREATE TABLE`; настоящие таблицы создаются уже при загрузке плагина, и при
выключенном модуле. Тест — `MasterclassSchemaTest`, стенд — `tests/stand/masterclass-schema-check.php`.

### `fs_lms_masterclasses`
| Колонка | Тип | Примечание |
|---|---|---|
| `id` | `int unsigned NOT NULL AUTO_INCREMENT` | PK |
| `school_name` | `varchar(255) NOT NULL` | |
| `subject_key` | `varchar(50) NOT NULL` | |
| `lesson_id` | `bigint(20) unsigned NOT NULL` | урок банка |
| `group_id` | `smallint unsigned DEFAULT NULL` | тип `groups.id`; `NULL` после удаления группы |
| `group_lesson_id` | `int unsigned DEFAULT NULL` | |
| `teacher_user_id` | `bigint(20) unsigned NOT NULL` | дублирует `groups.teacher_id` и `group_lessons.teacher_user_id` — меняются вместе |
| `starts_at`, `ends_at` | `datetime NOT NULL` | UTC |
| `freeze_at`, `unused_purge_at`, `purge_at` | `datetime NOT NULL` | UTC |
| `frozen_at` | `datetime DEFAULT NULL` | тик записал заморозку |
| `unused_purged_at` | `datetime DEFAULT NULL` | неиспользованные удалены (тик больше не выбирает) |
| `delete_requested_at` | `datetime DEFAULT NULL` | «Удалить» нажато, тик вычищает |
| `purged_at` | `datetime DEFAULT NULL` | аккаунты и группа вычищены |
| `created_by_user_id` | `bigint(20) unsigned DEFAULT NULL` | |
| `created_at`, `updated_at` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` (+ `ON UPDATE` у `updated_at`) | |

Ключи: `PRIMARY KEY (id)`, `UNIQUE KEY group_id (group_id)`, `KEY freeze_at`, `KEY unused_purge_at`, `KEY purge_at`, `KEY purged_at`.

### `fs_lms_masterclass_accounts`
| Колонка | Тип | Примечание |
|---|---|---|
| `id` | `int unsigned NOT NULL AUTO_INCREMENT` | **номер `N`**; строка не удаляется |
| `masterclass_id` | `int unsigned NOT NULL` | |
| `user_id` | `bigint(20) unsigned DEFAULT NULL` | |
| `person_id` | `int unsigned DEFAULT NULL` | |
| `student_record_id` | `int unsigned DEFAULT NULL` | |
| `state` | `varchar(10) NOT NULL DEFAULT 'active'` | `MasterclassAccountState` |
| `first_login_at`, `home_at`, `contact_at` | `datetime DEFAULT NULL` | ставятся один раз (`UPDATE … IS NULL`) |
| `application_id` | `int unsigned DEFAULT NULL` | |
| `deleted_at` | `datetime DEFAULT NULL` | мягкое удаление |
| `delete_reason` | `varchar(20) DEFAULT NULL` | `AccountDeleteReason` |
| `created_at`, `updated_at` | как выше | |

Ключи: `PRIMARY KEY (id)`, `UNIQUE KEY user_id (user_id)`, `KEY masterclass_id`, `KEY person_id`, `KEY deleted_at`.
`user_id` при удалении **сохраняется** (для журнала), но `UNIQUE` не мешает: WordPress ID не переиспользует.

### `fs_lms_masterclass_log`
По образцу `exam_log` (`Migration_1_0_89::examLogDdl`): `id`, `action varchar(40)`, `result varchar(10) DEFAULT 'success'`,
`actor_type varchar(10) DEFAULT 'system'`, `actor_user_id bigint(20) unsigned`, `actor_label varchar(255)`,
`masterclass_id int unsigned`, `account_id int unsigned`, `subject_label varchar(255)`, `actor_ip varchar(45) NOT NULL DEFAULT ''`,
`context longtext`, `created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP`. Ключи: `action`, `masterclass_id`, `account_id`, `created_at`.

## 4. Конфигурация (`Config\MasterclassConfig extends ModuleConfig`, новое)

`OPTION = 'fs_lms_masterclass'`, `toggleConstant()` → `'FS_LMS_MASTERCLASS'`, `defaults()` — ключи `SPEC.md` §6.
Геттеры: `startTime(): string`, `endTime(): string` (валидное `HH:MM`, иначе по умолчанию), `batchSize(): int`,
`homeTimeoutMinutes(): int`, `freezeDays(): int`, `unusedPurgeDays(): int`, `frozenPurgeDays(): int`, `logRetentionDays(): int`
(границы — приватным `bounded()` по образцу `PluginConfig::bounded()`), `messengerUrl(): string`, `bannerText(): string`.
**Сделано (этап 1).** Публичные константы `OPTION`, `TOGGLE_CONSTANT`, `DEFAULT_START_TIME` / `DEFAULT_END_TIME` / `DEFAULT_BANNER_TEXT`, `BANNER_MAX = 300` и таблица
`INT_BOUNDS` (ключ → `[по умолчанию, минимум, максимум]`; ею же пользуется шаблон вкладки). Правила чтения: целое за границей **клампится** к границе, нечисловое → по умолчанию;
окно: если время не `HH:MM` или конец не позже начала — обе границы по умолчанию целиком (`startTime()` / `endTime()` всегда дают пригодную пару); `bannerText()` пустой → текст
по умолчанию, длиннее 300 → обрезается. Добавлен `normalize( array $partial ): array` — приводит присланные значения перед `save()` (клампит числа, режет баннер, отбрасывает
неизвестные ключи и `enabled`, не добавляет непришедшие), а плохое время и «конец не позже начала» (с учётом сохранённого парного значения) — `\InvalidArgumentException`
с текстом для пользователя. Тест — `MasterclassConfigTest`.

## 5. Энумы (`Inc\Modules\Masterclass\Enums`, новое)

- `MasterclassAccountState : string` — `Active = 'active'`, `Applied = 'applied'`, `Enrolled = 'enrolled'`; `label()`.
- `LoginPlace : string` — `BeforeStart = 'before_start'`, `OnSite = 'on_site'`, `AtHome = 'at_home'`; `label()`.
- `MasterclassContactKind : string` — `Open`, `Phone`, `Messenger`, `Apply` (`'open'|'phone'|'messenger'|'apply'`);
  `static fromRequest( string ): ?self` через `tryFrom`.
- `AccountDeleteReason : string` — `Unused = 'unused'`, `Expired = 'expired'`, `Requested = 'requested'`,
  `UserDeleted = 'user_deleted'`, `CreateFailed = 'create_failed'`, `GroupDeleted = 'group_deleted'`.
- `MasterclassLogAction : string` (значение — snake_case, менять нельзя): `MasterclassCreated`, `MasterclassUpdated`,
  `MasterclassExtended`, `DeleteRequested`, `MasterclassPurged`, `AccountsCreated`, `AccountDeleted`, `AccountDetached`,
  `FlyersPrinted`, `LoginBeforeStart`, `LoginOnSite`, `LoginAtHome`, `Frozen`, `ContactOpened`, `ContactPhone`,
  `ContactMessenger`, `ApplyClicked`, `ApplicationBound`, `ApplicationBindFailed`, `Enrolled`, `LoginsUnlocked`. `label(): string`;
  `static fromContact( MasterclassContactKind ): self`. Хука `do_action` нет: внутри модуля пишет `MasterclassLogWriter` напрямую.
**Сделано (этап 1).** Все пять энумов со значениями выше; у каждого `label()` (`MasterclassContactKind` — ещё `fromRequest()`). Значения зафиксированы тестом и влезают в
колонки (`state varchar(10)`, `delete_reason varchar(20)`, `action varchar(40)`). `LoginPlace` в БД не хранится — он попадает в контекст журнала. Тест — `MasterclassEnumsTest`.

## 6. DTO (`Inc\Modules\Masterclass\DTO`, `readonly class`, новое)

- `MasterclassDTO` — все колонки §3 (camelCase; даты `?string`/`string` UTC). `fromArray( array ): self`;
  `isFrozen( string $nowUtc ): bool`; `isRunning( string $nowUtc ): bool` (`startsAt <= now <= endsAt`); `isPurged(): bool`.
- `MasterclassInputDTO` — `schoolName`, `subjectKey`, `lessonId`, `teacherUserId: int`, `startsAtLocal`, `endsAtLocal`
  (`Y-m-d H:i:s`, местное).
- `MasterclassAccountDTO` — колонки §3, `state: MasterclassAccountState`, `deleteReason: ?AccountDeleteReason`;
  `fromArray()`; `number(): int`; `isDeleted(): bool`.
- `MasterclassViewDTO` — `state`, `frozen: bool`, `showBanner: bool`, `bannerText`, `phone`, `messengerUrl`, `applyUrl`,
  `logoutUrl`; `toArray()`.
- `MasterclassFlyerDTO` — `number`, `login`, `password`, `schoolName`, `loginUrl`.
- `MasterclassFunnelDTO` — `created`, `loggedIn`, `atHome`, `contacted`, `applied`, `enrolled`; `toArray()`.
- `MasterclassLogInputDTO` / `MasterclassLogDTO` — копия `ExamLogInputDTO` / `ExamLogDTO` с `?int $masterclassId`, `?int $accountId`.

**Сделано (этап 1).** Все DTO — `readonly class`; `fromArray()` читает пустую строку как `NULL`. Уточнения к контракту:
- `MasterclassDTO::isFrozen( $nowUtc )` — `now >= freeze_at` (в сам момент `freeze_at` уже закрыто); `isRunning()` — обе границы окна включены; добавлен
  `isDeleteRequested(): bool`. Заморозка выводится из времени, `frozen_at` на неё не влияет (тест `test_freeze_is_derived_from_time_not_from_the_tick_mark`).
- `MasterclassAccountDTO::fromArray()`: неизвестное состояние в БД читается как `Active`, неизвестная причина — как отсутствующая (список аккаунтов не падает на испорченной строке;
  состояние пишет только модуль).
- `MasterclassViewDTO::state` — энум, в `toArray()` уходит его значение. Тест — `MasterclassDtoTest`.

## 7. Репозитории (`Inc\Modules\Masterclass\Repositories`, новое; все `extends AbstractExamRepository`)

Ошибка БД = исключение (`readRow/readRows/readInt/insertRow/updateRow/write`). Конструктор унаследован (`?\wpdb $wpdb = null`).

### `MasterclassRepository`
- `find( int $id ): ?MasterclassDTO`; `findByGroupId( int $groupId ): ?MasterclassDTO`
- `page( int $page, int $perPage ): array{rows: list<MasterclassDTO>, total: int}` — `starts_at DESC, id DESC`
- `insert( array $data ): int`; `update( int $id, array $data ): void`
- `activeGroupIds(): list<int>` — `group_id IS NOT NULL` (для Ш4; кеш на запрос — в сервисе)
- `runningFor( string $nowUtc, int $leadMinutes ): list<MasterclassDTO>` — окно с запасом до начала (для Ш6)
- `listDueForFreeze( string $nowUtc, int $limit )` — `freeze_at <= now AND frozen_at IS NULL AND purged_at IS NULL`
- `listDueForUnusedPurge( string $nowUtc, int $limit )` — `unused_purge_at <= now AND unused_purged_at IS NULL AND purged_at IS NULL`
- `listDueForPurge( string $nowUtc, int $limit )` — `( purge_at <= now OR delete_requested_at IS NOT NULL ) AND purged_at IS NULL`,
  порядок `COALESCE( delete_requested_at, purge_at ) ASC, id ASC`
- `markFrozen( int $id, string $nowUtc ): bool`; `markUnusedPurged( int $id, string $nowUtc ): void`;
  `markDeleteRequested( int $id, string $nowUtc ): void`; `markPurged( int $id, string $nowUtc ): void`
  (`purged_at`, `group_id = NULL`, `group_lesson_id = NULL`)

### `MasterclassAccountRepository`
- `find( int $id )`; `findByUserId( int $userId )`; `findByPersonId( int $personId )` — `?MasterclassAccountDTO`, **включая удалённые**
  (вызывающий проверяет `isDeleted()`)
- `listByMasterclass( int $masterclassId ): list<MasterclassAccountDTO>` — по возрастанию `id`
- `liveUserIds( int $masterclassId ): list<int>` — `user_id` аккаунтов с учёткой, не удалённых (`user_id IS NOT NULL AND deleted_at IS NULL`;
  для `unlockLogins()`)
- `pageByMasterclass( int $masterclassId, int $page, int $perPage, bool $withDeleted ): array{rows: list<MasterclassAccountDTO>, total: int}` —
  по возрастанию `id`; без `$withDeleted` только `deleted_at IS NULL` (для списка аккаунтов страницы)
- `insertPending( int $masterclassId, string $nowUtc ): int` — **`id` = `N`**
- `attach( int $id, int $userId, int $personId, int $studentRecordId ): void`
- `setState( int $id, MasterclassAccountState $state, ?int $applicationId = null ): void`
- `markFirstLogin / markHome / markContact( int $id, string $nowUtc ): bool` — `UPDATE … WHERE id = %d AND col IS NULL AND deleted_at IS NULL`
- `markDeleted( int $id, AccountDeleteReason $reason, string $nowUtc ): bool` — `… AND deleted_at IS NULL`
- `markDeletedByUserId( int $userId, AccountDeleteReason $reason, string $nowUtc ): ?int` — id аккаунта или `null`
- `clearStudentRecord( int $id ): void` — отвязка (`student_record_id = NULL`)
- `nextUnused( int $masterclassId, int $limit ): list` — `deleted_at IS NULL AND first_login_at IS NULL AND state = 'active'`
- `nextPurgeable( int $masterclassId, int $limit ): list` — `deleted_at IS NULL AND state <> 'enrolled'`
- `countLive( int $masterclassId ): int` — `deleted_at IS NULL AND state <> 'enrolled'`
- `countByMasterclass( int $masterclassId ): int`
- `listStalePending( string $olderThanUtc, int $limit ): list` — `user_id IS NULL AND deleted_at IS NULL AND created_at < …`
- `funnel( int $masterclassId ): MasterclassFunnelDTO` — один запрос `COUNT(*)` / `SUM( col IS NOT NULL )` / `SUM( state = … )`
  по **всем** строкам (удалённые тоже)

### `MasterclassLogRepository`
- `create( MasterclassLogInputDTO ): int`
- `list( array $filters, int $page, int $perPage ): array{rows: list<MasterclassLogDTO>, total: int}` — фильтры `action`,
  `masterclass_id`, `account_id`, `date_from`, `date_to`
- `distinctActions(): list<string>`; `purgeOlderThan( string $utc ): int`

**Сделано (этап 1).** Проверено на `wp_db` (`tests/stand/masterclass-schema-check.php`, 100+ проверок) и на дубле wpdb (`MasterclassRepositoriesTest`). Уточнения к контракту:
- Условные `UPDATE` (`markFirstLogin / markHome / markContact`, `markDeleted`) требуют ещё `deleted_at IS NULL`: у удалённого аккаунта отметки не ставятся. `markFrozen` — ещё `purged_at IS NULL`.
  `markDeleteRequested` не двигает уже стоящее время запроса (по нему упорядочена очередь тика) и не трогает вычищенный; `markPurged` повторно отметку не двигает.
- `MasterclassAccountRepository::funnel()` не считает номера с `delete_reason = 'create_failed'`: это сожжённые номера без учётки, а не выданные аккаунты. Остальные удалённые считаются.
- `attach()` — `void`, безусловный по `deleted_at` (между `insertPending()` и `attach()` одного вызова `createChunk()` аккаунт может удалить только чистильщик «зависших»
  с порогом в часы). `UNIQUE user_id`: второй аккаунт на того же пользователя — `DuplicateKeyException`; несколько аккаунтов без учётки (`NULL`) допустимы.
- `MasterclassRepository`: `UNIQUE group_id` — `DuplicateKeyException` на вторую запись той же группы; после `markPurged()` номер группы можно занять снова. `runningFor()` берёт
  невычищенные мастер-классы с `starts_at − lead <= now <= ends_at`.
- `MasterclassLogRepository` **не** наследует `AbstractLogRepository` (тот привязан к core-энуму `LogChannel`); читает и фильтрует сам. Фильтры `date_from` / `date_to` — `Y-m-d`, границы
  суток включительно, **в UTC** (как у журналов ядра); страница журнала (этап 6) переводит местные даты сама.
- Метки времени `created_at` / `updated_at` пишутся явно (`gmdate()` / переданное `$nowUtc`): `DEFAULT CURRENT_TIMESTAMP` берёт пояс БД, а не UTC.

## 8. Сервисы (`Inc\Modules\Masterclass\Services`, новое)

### `MasterclassService`
Зависимости: `MasterclassRepository`, `MasterclassAccountRepository`, `GroupsRepository`, `GroupLessonRepository`,
`AcademicPeriodRepository`, `LessonManager`, `OpenCourseValidator`, `GroupLessonUsageGuard`, `UserRepository`,
`MasterclassConfig`, `MasterclassLogWriter`, `ExamTime`, `ClockInterface`. Трейт `TransactionRunner`.

- `create( MasterclassInputDTO $input, int $actorUserId ): MasterclassDTO` — (1) `validate()`; (2) `getCurrentPeriod()` (нет —
  исключение); (3) в транзакции: `GroupsRepository::create( array( subject_key, academic_period_id, name, teacher_id,
  meetings => '[]', access_mode => AccessMode::Open->value ) )` (0 → `\RuntimeException`), `GroupLessonRepository::add(
  new GroupLessonInputDTO( groupId, lessonId, position: 0, workIdsSnapshot: $lesson->workIds(), scheduledAt: startsAtLocal,
  endsAt: endsAtLocal, teacherUserId, visibility: LessonVisibility::Open->value, openedAt: null, createdByUserId ) )`,
  `MasterclassRepository::insert()` со сроками `deadlines()`; (4) журнал `MasterclassCreated`. Аккаунтов не создаёт.
  `@throws \InvalidArgumentException` (пустая школа; конец не позже начала; урок не найден / не опубликован / другого предмета;
  `OpenCourseValidator::lessonProblems()` не пуст — текст проблем; пользователь не преподаватель — нет роли `FSTeacher`;
  нет текущего периода), `\RuntimeException` (группа/занятие не создались).
- `update( int $id, MasterclassInputDTO $input, int $actorUserId ): MasterclassDTO` — школа; преподаватель (`GroupsRepository::update(
  teacher_id )` + `GroupLessonRepository::updateSchedule()` + строка); окно (`updateSchedule( scheduledAt, teacherUserId, endsAt )`,
  сроки пересчитываются от нового конца, `frozen_at`/`unused_purged_at` сбрасываются); урок — только пока
  `GroupLessonUsageGuard::hasLearningData()` ложно (`setLessonId()` + `setWorkIdsSnapshot()`). Вычищенный/помеченный к
  удалению — исключение.
- `extend( int $id, int $days, int $actorUserId ): MasterclassDTO` — `$days` 1–60; `freeze_at` от большего из (`freeze_at`, сейчас)
  + `$days`; `unused_purge_at`, `purge_at` — на ту же разницу; `frozen_at = NULL`.
- `requestDelete( int $id, int $actorUserId ): int` — `markDeleteRequested()`, журнал `DeleteRequested`; возвращает число аккаунтов
  с живой заявкой (их отвяжут, а не удалят) — для текста ответа.
- `find( int $id ): ?MasterclassDTO`
- `overview( int $page, int $perPage ): array{rows: list<array{masterclass: MasterclassDTO, funnel: MasterclassFunnelDTO,
  status: string}>, total: int}` — статус: «ждёт», «идёт», «открыт дома», «заморожен», «удаляется», «вычищен».
- `lessonOptions( string $subjectKey ): list<array{id: int, title: string, problems: list<string>}>` — опубликованные уроки
  `LessonManager::getBankBySubject()`; непригодные отдаются с `problems` (форма показывает причину и не даёт выбрать).
- `teacherOptions(): list<array{id: int, name: string}>` — `UserRepository::getByRole( UserRole::FSTeacher )`.
- ~~`serviceGroupIds()`~~ — **убрано (этап 1)**: Ш4 читает `MasterclassRepository::activeGroupIds()` прямо в `MasterclassIntegrityController`, а кеш на запрос уже есть в
  `ServiceGroupResolver` ядра.
- `private deadlines( string $endsAtUtc ): array{freeze_at, unused_purge_at, purge_at}`; `private groupName( string $school,
  string $dateLocal ): string` — «Мастер-класс · {школа} · {дд.мм.гггг}», занято (`existsByNameAndPeriod`) — « (2)», « (3)».

**Сделано (этап 2).** Отличия от контракта выше (правда — код, `tests/Unit/Modules/Masterclass/MasterclassServiceTest.php`):
- Конструктор: вместо `UserRepository` для проверки преподавателя — `UserManager::find()` (роль `FSTeacher` по `roles`), `UserRepository::getByRole()` остался только для `teacherOptions()`;
  добавлен `MasterclassAccountService` (`requestDelete()` берёт число аккаунтов с живой заявкой у него: `countWithLiveApplication()`) и `MasterclassAccountRepository` (воронка в `overview()`).
- Состояние в `overview()` — энум `MasterclassStatus` (`label()` — слова списка), а не строка; считает `MasterclassDTO::status( $nowUtc )`: вычищен → удаляется → заморожен → дома → идёт → ждёт.
- Проверки ввода: школа непустая и ≤ `SCHOOL_MAX` (200), время строго `Y-m-d H:i:s` (ловит и `2026-02-30`), конец позже начала; сообщения — для людей. Имя группы перебирает суффиксы до 100, дальше — отказ.
- `update()`: **предмет не меняется** (отказ); урок — только пока `GroupLessonUsageGuard::hasLearningData()` ложно (проверяется до проверки нового урока); сроки пересчитываются и отметки
  `frozen_at`/`unused_purged_at` сбрасываются **только когда сдвинулось окно** — правка школы или преподавателя «Продление» не затирает; расписание занятия трогается, только если сдвинулось окно или сменился
  преподаватель; имя группы пересобирается, если сменилась школа или дата (период берётся из группы).
- `extend()`: сбрасывает и `unused_purged_at` (сдвинутый срок должен отработать заново); база — большее из (`freeze_at`, сейчас), остальные сроки — на ту же разницу в секундах.
- `requestDelete()`: повтор безвреден — метку не двигает и в журнал второй раз не пишет, число аккаунтов с заявкой возвращает; вычищенный мастер-класс — исключение.
- `create()`: журнал — после `COMMIT`; сбой группы/занятия/строки откатывает всё (проверено на `wp_db`: `tests/stand/masterclass-stage2-check.php`).

### `MasterclassAccountService`
Зависимости: `MasterclassAccountRepository`, `MasterclassRepository`, `AccountProvisioningService`, `PersonService`,
`StudentRecordRepository`, `UserManager`, `PasswordGeneratorService`, `DeletionEventDispatcher`, `ApplicationRepository`,
`MasterclassLogWriter`, `LoginGuardService`, `ClockInterface`. Трейт `TransactionRunner`.

- `public const LOGIN_PREFIX = 'student-'`; `public const CHUNK_MAX = 25`; `public static function loginFor( int $n ): string`.
- `createChunk( int $masterclassId, int $count, int $actorUserId ): int` — `$count` 1–`CHUNK_MAX`; мастер-класс есть, не вычищен,
  не помечен к удалению, группа на месте — иначе `\InvalidArgumentException`. На аккаунт: `insertPending()` → `$n`; логин занят
  (`UserManager::findByLogin()`) → `markDeleted( CreateFailed )`, дальше; пароль `PasswordGeneratorService::generateReadable()`;
  физлицо `PersonService::createOrFindBy( new PersonInputDTO( lastName: 'Ученик#' . $n, firstName: '', docNumber: '' ) )`;
  `AccountProvisioningService::provisionStudent( $personId, $input, self::loginFor( $n ), $password )` (сохраняет копию пароля
  для флаера); запись `StudentRecordRepository::create( new StudentRecordInputDTO( studentPersonId, parentPersonId: 0,
  status: EnrollmentStatus::Active->value, enrolledAt, createdAt, updatedAt, groupId, snapshotLastName: 'Ученик#' . $n,
  snapshotFirstName: '', isTrial: true ) )`; `attach()`. Сбой — удалить созданное этим шагом (запись, физлицо и учётку —
  через `DeleteStudentEvent`, если физлицо уже есть), `markDeleted( CreateFailed )`, порция продолжается. Итог — журнал
  `AccountsCreated` (сколько создано). Возвращает число созданных.
- `flyers( int $masterclassId, int $actorUserId ): list<MasterclassFlyerDTO>` — живые аккаунты в `Active` с читаемой копией
  пароля (`getCredentials()` не `null`); журнал `FlyersPrinted` (число, без паролей).
- `page( int $masterclassId, int $page = 1, int $perPage = 50, bool $withDeleted = false ): array{masterclass: MasterclassDTO, rows: list<array>, total: int}` —
  список аккаунтов для страницы (решение 24). Строка: `number`, `login` (`loginFor( number )`), `state` (подпись `MasterclassAccountState`), `firstLoginLocal`,
  `homeLocal`, `contactLocal`, `deletedLocal` (`?string`, местное время через `ExamTime`), `deleteReason` (подпись или `null`), `hasApplication: bool`.
  **Паролей и ПД нет.** Мастер-класса нет → `\InvalidArgumentException`. Вычищенный мастер-класс отдаёт пустой список.
- `findLiveByUserId( int $userId ): ?MasterclassAccountDTO` — не удалён и не `Enrolled`.
- `playerUrlFor( int $userId ): ?string` — `PageRoutes::LessonPlayer->lessonUrl( groupId, groupLessonId )`; `null`, если нет живого
  аккаунта или мастер-класс без группы.
- `deleteAccount( MasterclassAccountDTO $a, AccountDeleteReason $reason, int $actorUserId ): void` — `markDeleted()` **до** удаления
  (обработчик `deleted_user` увидит уже помеченную строку и ничего не сделает), затем `DeletionEventDispatcher::dispatch(
  new DeleteStudentEvent( $a->personId, $actorUserId ) )`; журнал `AccountDeleted` (`context.reason`). Данные ученика по занятию
  удаляет потом `GroupDeletionHandler` (по `group_lesson_id`), отдельной чистки нет.
- `detachAccount( MasterclassAccountDTO $a, int $actorUserId ): void` — `StudentRecordRepository::deleteTrialByStudent( personId )`,
  `clearStudentRecord()`; учётка и физлицо остаются за заявкой; журнал `AccountDetached`.
- `unlockLogins( int $masterclassId, int $actorUserId ): int` — для каждого `liveUserIds()` `LoginGuardService::unlockUser( $userId )`;
  возвращает число аккаунтов; журнал `LoginsUnlocked` (`context.count`). Мастер-класса нет / вычищен → `\InvalidArgumentException`.
- `hasLiveApplication( MasterclassAccountDTO $a ): bool` — `ApplicationRepository::find( applicationId )` в статусе
  `PendingParent`, `ReadyForReview` или `Enrolling`.

**Сделано (этап 2).** Отличия от контракта (тест — `MasterclassAccountServiceTest`, стенд — `masterclass-stage2-check.php`):
- Конструктор: последним параметром `ExamTime` (время `page()` — местное), перед ним `ClockInterface`.
- Добавлен `countWithLiveApplication( int $masterclassId ): int` (для `MasterclassService::requestDelete()`).
- `createChunk()`: сбой шага → **сначала** `markDeleted( CreateFailed )`, потом откат созданного через `DeleteStudentEvent` (сбой отката гасится и логируется — порция идёт дальше); журнал
  `AccountsCreated` — один на порцию и только если создан хотя бы один. Запись, вернувшая `0`, — сбой, как и исключение. Пустой `docNumber` физлица не склеивает аккаунты (`PersonService` дедуплицирует только по непустому).
- `deleteAccount()` возвращает `void` и **идемпотентен**: если метка не поставилась (уже удалён), событие не диспетчеризуется и журнал не пишется. `detachAccount()` тоже безвреден при повторе.
- `flyers()` — мастер-класса нет → `\InvalidArgumentException`; вычищенный не отказ (список просто пуст). `unlockLogins()` — вычищенный → исключение. `page()` вычищенного — пустой список, `total = 0`.
- Замер на dev-стеке (`wp_db`, WP-CLI): порция 25 аккаунтов ≈ 2,4–5,2 с (холодный и прогретый процесс), 500 аккаунтов — 48 с. Страница крутит порции по `chunkMax = 25`.
- Параллельные порции двух процессов (`worker`): номера уникальны (`AUTO_INCREMENT`), «зависших» строк нет.

### `MasterclassAccessGuard`
Зависимости: `MasterclassAccountRepository`, `MasterclassRepository`, `MasterclassConfig`, `PluginConfig`, `ClockInterface`.
Кеш на запрос по физлицу и по пользователю.
- `filterLessonAccess( AccessLevel $level, StudentRecordDTO $record, GroupLessonDTO $lesson ): AccessLevel` — для Ш1: только
  `$record->isTrial`; аккаунт по физлицу живой, его мастер-класс — группа `$lesson->groupId` и заморожен → `AccessLevel::None`.
- `viewFor( int $userId ): ?MasterclassViewDTO` — `null`, если нет живого аккаунта. `frozen` — как выше; `showBanner` =
  `! frozen && ( homeAt !== null || now > endsAt )`; тексты — `MasterclassConfig`; телефон — `PluginConfig::centerContactsFallback()['phone']`;
  `applyUrl` — `PageRoutes::Apply->url()`; `logoutUrl` — `wp_logout_url( PageRoutes::SignIn->url() )`.

### `MasterclassLoginTracker`
Зависимости: `MasterclassAccountRepository`, `MasterclassRepository`, `MasterclassConfig`, `MasterclassLogWriter`, `ClockInterface`.
- `onLogin( \WP_User $user, string $ip ): ?LoginPlace` — нет живого аккаунта → `null`. `classify()`; `BeforeStart` — только журнал;
  иначе `markFirstLogin()`, при `AtHome` — `markHome()`; журнал `LoginOnSite` (первый вход на месте) / `LoginAtHome` (`context.ip`).
- `onPlayerOpen( int $userId ): void` — живой аккаунт, `now > endsAt` → `markHome()` (без журнала, если не поставилось).
- `classify( MasterclassDTO, MasterclassAccountDTO, string $nowUtc ): LoginPlace` — чистая: `now < startsAt` → `BeforeStart`;
  `firstLoginAt === null` → `OnSite` в окне, иначе `AtHome`; повторный → `AtHome`, если `now > endsAt` или прошло
  ≥ `homeTimeoutMinutes()` от `firstLoginAt`, иначе `OnSite`.
- `allowsCaptchaBypass( string $login ): bool` — для Ш6: логин вида `student-N`, живой аккаунт, его мастер-класс
  `runningFor( now, 30 )`.

### `MasterclassConversionService`
Зависимости: `MasterclassAccountRepository`, `MasterclassRepository`, `ApplicationRepository`, `PersonRepository`,
`UserManager`, `TrialAccessService`, `PiiCryptoService`, `MasterclassLogWriter`, `ClockInterface`.
- `bindApplication( int $applicationId, int $userId ): bool` — **наружу не бросает** (сбой → `PluginLogger::exception` +
  `ApplicationBindFailed`, `false`). Условия: живой аккаунт в `Active`/`Applied`, у него нет живой заявки, заявка существует и
  `student_person_id IS NULL`. Действия: `ApplicationRepository::update( $applicationId, array( student_person_id, trial_owner => 1,
  updated_at ) )`; данные заявки (`StudentDataDTO::fromArray( json_decode( $crypto->decrypt( … ), true ) )`) →
  `TrialAccessService::syncCredentials( $applicationId, $data->username, $data->loginPassword )` (сам ловит занятый логин и пишет
  предупреждение); `setState( Applied, $applicationId )`; журнал `ApplicationBound`.
- `markEnrolled( int $personId ): bool` — аккаунт по физлицу, не удалён → `setState( Enrolled )`; имя учётки из физлица:
  `UserManager::update( $userId, array( display_name => fullName, first_name, last_name ) )` по `PersonRepository::find()`;
  журнал `Enrolled`.
- `logContact( int $userId, MasterclassContactKind $kind ): void` — только живые аккаунты; `markContact()`; журнал `fromContact()`.
- `prefill( int $userId ): array` — для Ш5: живой аккаунт → `array( subject_key, school => schoolName )`, иначе `array()`.

### `MasterclassLifecycleService`
Зависимости: `MasterclassRepository`, `MasterclassAccountRepository`, `MasterclassAccountService`, `MasterclassLogRepository`,
`MasterclassConfig`, `DeletionEventDispatcher`, `MasterclassLogWriter`, `ClockInterface`.
- `public const ACCOUNTS_PER_TICK = 60`.
- `tick(): array{frozen, unused, purged, groups, stale, log}` — шаги ниже под `step()` (сбой → `PluginLogger::exception(
  'MasterclassTick', …, true )`, остальные шаги идут). Бюджет удалений `ACCOUNTS_PER_TICK` общий на шаги 2–3.
- `logFreezes( int $limit = 100 ): int` — `markFrozen()` → `Frozen`.
- `purgeUnused( int &$budget ): int` — по `listDueForUnusedPurge()`: `nextUnused()` → `deleteAccount( Unused )`; аккаунтов не осталось →
  `markUnusedPurged()`.
- `purgeExpired( int &$budget ): int` — по `listDueForPurge()`: `nextPurgeable()`; аккаунт с живой заявкой — при
  `delete_requested_at` → `detachAccount()`, иначе пропуск; остальные → `deleteAccount( Requested | Expired )`.
- `dropGroups( int $limit = 20 ): int` — мастер-классы из `listDueForPurge()`, где `countLive() === 0` (или все живые отвязаны):
  `DeleteGroupEvent( groupId, 0 )` → `markPurged()` → `MasterclassPurged`. Группа живёт, пока жив хоть один аккаунт с заявкой.
- `sweepStale( int $limit = 100 ): int` — `listStalePending( now − 1 ч )` → `markDeleted( CreateFailed )`.
- `purgeLog(): int` — `MasterclassLogRepository::purgeOlderThan( now − logRetentionDays )`.
- `onGroupDeleting( int $groupId, int $actorUserId ): void` — для Ш3: мастер-класс по группе; живые аккаунты: с живой заявкой →
  `detachAccount()`, `Enrolled` — пропуск, остальные → `deleteAccount( GroupDeleted )`; затем `markPurged()`. Синхронно (удаление
  со страницы «Группы» или снос предмета).
- `onUserDeleted( int $userId ): void` — `markDeletedByUserId( UserDeleted )`; если поставилось — журнал `AccountDeleted`.

**Сделано (этап 4).** Тест — `MasterclassLifecycleServiceTest` (24). Отличия от текста выше: `purgeUnused/purgeExpired` ранний выход при пустом бюджете и выборка по 25; `nextPurgeable( $id, $limit, $afterId = 0 )` берёт только аккаунты с записью в группе (отвязанные не выбираются), пропущенные аккаунты с заявкой обходятся курсором; `dropGroups()` сносит группу при `countAttached() === 0` (новый метод репозитория аккаунтов); `onGroupDeleting()` использует тот же обход без бюджета и отвязывает аккаунты с заявкой всегда; `finish()` — отметка `purged_at` и запись `MasterclassPurged` один раз (Ш3 мог успеть раньше). `MasterclassCronController( CronManager, ExamTickLock, MasterclassLifecycleService )` — не наследует `BaseController`, сам регистрирует интервал `every_15_minutes`; `MasterclassSettingsController` получил `MasterclassCronController` (`onToggle( false )` → `unschedule()`); `MasterclassIntegrityController( MasterclassRepository, MasterclassAccessGuard, MasterclassLifecycleService )` подписан на Ш1, Ш3 и `deleted_user`.

### `MasterclassLogWriter`
Зависимости: `MasterclassLogRepository`, `ClockInterface` (`MasterclassAccountRepository` не нужен: номер аккаунта и есть его `id`, подпись «Ученик#N» строится без запроса в БД).
Трейт `RequestContextProvider`.
`write( MasterclassLogAction $action, array $data = array() ): void` — ключи `$data`: `masterclass_id`, `account_id`, `actor`,
`actor_type` (`user`|`guest`|`system`), `result`, `context` (массив без паролей и ПД). Подпись «Ученик#N» — по `account_id`;
IP — из `RequestContextProvider`. Сбой гасится (`PluginLogger::exception( 'MasterclassLog', …, true )`).
**Сделано (этап 1).** Актор: `actor` не задан и `actor_type` не задан — пользователь текущего запроса (`user`), иначе `system`; явный `actor_type = system` не приписывает действие
администратору, оказавшемуся в сессии. `result` кроме `failure` читается как `success`; неположительные id отбрасываются. Из `context` на любой глубине вырезаются ключи
`/pass|pwd|secret|token|nonce/i`. Тест — `MasterclassLogWriterTest`.

### Дополнение ядра `PasswordGeneratorService` (изменить)
`generateReadable( int $length = 8 ): string` — алфавит без `0 O 1 l I`, `random_int`. Существующие методы не меняются.

## 9. Контроллеры (`Inc\Modules\Masterclass\Controllers`, новое; все `extends BaseController`, без `ServiceInterface` —
их зовёт `MasterclassModule`)

### `MasterclassModule implements ServiceInterface`
Конструктор: все контроллеры ниже + `MasterclassSchema`, `MasterclassConfig`. `register()`:
`$schema->ensure()` → `$settings->register()` → `$integrity->register()` → если `! $config->isEnabled()` — выход →
`$admin->register()`, `$auth->register()`, `$player->register()`, `$enrollment->register()`, `$cron->register()`.
**Сделано (этап 1):** конструктор пока `( MasterclassSchema, MasterclassConfig, MasterclassSettingsController, MasterclassIntegrityController, MasterclassAdminController )`, после
выхода регистрируется только `$admin`; остальные контроллеры добавляются в конструктор и в хвост `register()` по мере появления (этапы 2–5). Тест — `MasterclassModuleTest`
(порядок и состав при выключенном и включённом модуле); на стенде — `tests/stand/di-boot-check.php on|off`.

### `MasterclassSettingsController` (всегда)
`SAVE_ACTION = 'fs_lms_masterclass_save_settings'`. `register()`: `wp_ajax_` + `SAVE_ACTION` → `MasterclassSettingsCallbacks::ajaxSave`;
`fs_lms_settings_tabs` (`'tab-masterclasses' => array( 'title' => 'Мастер-классы', 'path' => … templates/settings-tab.php )`, только
при включённом); `fs_lms_dashboard_modules` (`id` `masterclass`, `const_key` `FS_LMS_MASTERCLASS`); `fs_lms_module_toggle_masterclass`
→ `onToggle( bool )`: `config->save( enabled )`, при выключении — `MasterclassCronController::unschedule()`.
**Сделано (этап 1):** константы `SAVE_ACTION` и `TAB = 'tab-masterclasses'`; `onToggle()` пока только сохраняет флаг — `unschedule()` добавляется с крон-контроллером (этап 4).
Вкладка отдаёт шаблону данные ключом `masterclass_config` (**не** `config`: страница настроек уже держит под `$config` массив настроек плагина, а `extract( …, EXTR_SKIP )`
его не перезапишет — так вкладка падала на живой странице). Скрипт `assets/settings.js` грузится только на `?page=fs_lms_settings&tab=tab-masterclasses` при включённом модуле,
локализация `fsLmsMasterclassSettings = { ajaxurl, action, nonce }`. Проверено по HTTP: вкладка, форма, скрипт и пункт меню есть при включённом модуле и исчезают при выключенном;
страница модуля при выключенном — 403; тумблер Dashboard (`fs_lms_toggle_module`) включает и выключает; карточка есть всегда. Тесты — `MasterclassSettingsControllerTest`,
`MasterclassTemplatesRenderTest` (рендер повторяет механизм `settings.php`).

### `MasterclassIntegrityController` (всегда)
`add_filter( 'fs_lms_lesson_access', array( $guard, 'filterLessonAccess' ), 10, 3 )`;
`add_action( 'deleted_user', array( $lifecycle, 'onUserDeleted' ) )`;
`add_filter( 'fs_lms_service_group_ids', … )` → `array_merge( $ids, MasterclassService::serviceGroupIds() )` (Ш4: группа остаётся служебной и при
выключенном модуле, иначе она вернулась бы в списки зачисления);
`add_action( 'fs_lms_group_deleting', array( $lifecycle, 'onGroupDeleting' ), 10, 2 )` (Ш3: удаление группы любым путём убирает учётки и при выключенном
модуле, иначе `student-N` остались бы сиротами). *(Решено 2026-10-28, этап 0: оба шва — «всегда».)*
**Сделано (этап 1):** контроллер зависит только от `MasterclassRepository` и держит одну подписку — Ш4 `fs_lms_service_group_ids` → `filterServiceGroupIds( mixed ): array`
(дописывает `activeGroupIds()`; не-массив от чужого подписчика считается пустым; сбой чтения таблицы гасится и логируется — список возвращается как пришёл, чтобы модуль не ронял
зачисление и выгрузку ядра). Ш1 (этап 3), `deleted_user` и Ш3 (этап 4) добавляются вместе со своими обработчиками. Тест — `MasterclassIntegrityControllerTest`.

### `MasterclassAdminController`
`PAGE_SLUG = 'fs_lms_masterclasses'`; экшены: `CREATE_ACTION = 'fs_lms_masterclass_create'`, `UPDATE_ACTION`, `EXTEND_ACTION`,
`DELETE_ACTION`, `ADD_ACCOUNTS_ACTION`, `LESSONS_ACTION`, `UNLOCK_ACTION = 'fs_lms_masterclass_unlock'`,
`FLYERS_ACTION = 'fs_lms_masterclass_flyers'` (admin-post).
`register()`: `MenuManager::register( array(), array( subpage: parent_slug Menu::Main->value, page_title/menu_title «Мастер-классы»,
capability Capability::ManageLmsPlatform->value, menu_slug PAGE_SLUG, callback array( $this, 'renderPage' ) ) )`;
`wp_ajax_*` → `MasterclassAdminCallbacks`; `admin_post_` + `FLYERS_ACTION` → `MasterclassAdminCallbacks::printFlyers`;
`admin_enqueue_scripts` → `enqueueAssets()` (только на `PAGE_SLUG`: `assets/admin.js` + `wp_localize_script( 'fsLmsMasterclass',
{ ajaxurl, nonce: Nonce::Config->create(), actions, chunkMax, defaults } )` — исключение «модуль локализует свой admin-JS в своём
контроллере», `CLAUDE.md`). `renderPage()`: `require` `templates/admin-page.php` с данными `MasterclassService::overview()`,
вид `?view=accounts&id=N[&paged=N][&deleted=1]` — `templates/admin-accounts.php` с `MasterclassAccountService::page()` (серверный рендер, без AJAX;
`id` через `Sanitizer`, нет мастер-класса — сообщение на странице, не фатал),
вкладка `?view=log` — `templates/admin-log.php` с `MasterclassLogRepository::list()`.
**Сделано (этап 1):** `PAGE_SLUG`, регистрация подстраницы через `MenuManager` и `renderPage()` с шаблоном-заглушкой `admin-page.php` (заголовок и описание); AJAX, виды и
`enqueueAssets()` — этап 2. Проверено по HTTP: пункт меню «Мастер-классы» и страница открываются при включённом модуле.

**Сделано (этап 2).** Тест — `MasterclassAdminControllerTest` (в нём же рендер настоящих шаблонов обоих видов; `AdminAccountsRenderTest` из плана — это его блок «вид аккаунты»):
- Конструктор: `MenuManager`, `MasterclassAdminCallbacks`, `MasterclassService`, `MasterclassAccountService`, `SubjectRepository`, `MasterclassConfig`, `ExamTime`. Константы: `CREATE/UPDATE/EXTEND/DELETE/ADD_ACCOUNTS/LESSONS/UNLOCK_ACTION`,
  `FLYERS_ACTION`, `VIEW_ACCOUNTS = 'accounts'`, `PER_PAGE = 20`, `ACCOUNTS_PER_PAGE = 50`.
- `enqueueAssets()` — только на `?page=fs_lms_masterclasses` и **не на виде аккаунтов** (серверный рендер, JS не нужен). Локализация `fsLmsMasterclass = { ajaxurl, nonce, actions{create,update,extend,delete,addAccounts,lessons,unlock},
  chunkMax, defaults{batchSize,startTime,endTime} }`.
- `renderPage()`: `?view=accounts&id=N[&paged=N][&deleted=1]` → `admin-accounts.php`, иначе `admin-page.php`; вид `log` — этап 6 (пока вкладка «Журнал» в шапке — неактивная заглушка). Шаблоны подключаются замыканием с
  `extract( $vars, EXTR_SKIP )` — свои переменные, пересечений со страницей настроек нет. Данные списка — массивы (`listView()`: `rows[]` с `status`, `funnel`, местным окном, адресами `accounts_url` / `flyers_url` /
  `player_url`; форма берёт предметы `readAll()` без архивных и без `hasBank = false`, преподавателей `teacherOptions()`).
- Ссылка на флаеры: `admin-post.php?action=…&id=N&security=<Nonce::Config>`, открывается в новой вкладке.

### `MasterclassAuthController`
`template_redirect` (приоритет 5 — раньше `ProfileController`) → `redirectAccount()`: залогинен, `PageRoutes::SignIn->isCurrent()`
или `PageRoutes::UserProfile->isCurrent()`, `playerUrlFor()` не `null` → `wp_safe_redirect(); exit;`.
`login_redirect` (приоритет 20, после `UserBehaviorManager::resolveLoginRedirect`) → URL плеера для аккаунта.
`wp_login` (10, 2) → `MasterclassLoginTracker::onLogin( $user, $ip )`.
`fs_lms_captcha_fallback_unlimited` (10, 4) → `true`, если `CaptchaScope::Login === $scope` и `allowsCaptchaBypass( $identifier )`.

### `MasterclassPlayerController`
`template_redirect` (20) → на `PageRoutes::LessonPlayer->isCurrent()` для залогиненного: `MasterclassLoginTracker::onPlayerOpen()`.
Швы Ш2: `fs_lms_player_back_url` (живой аккаунт → `''`); `fs_lms_player_lock_html` (вид заморожен → `templates/player-lock.php`:
«Доступ к мастер-классу закрыт» / для `Applied` «Заявка принята, с вами свяжутся», кнопки «Связаться», «Заполнить заявку», «Выйти»);
`fs_lms_player_header_actions` → `templates/player-actions.php` («Связаться», «Выйти»); `fs_lms_player_before_content` →
`templates/player-banner.php` (`.s-banner`, если `showBanner`); `fs_lms_player_footer` → `templates/player-contact.php` (окно на
`.cp-dim/.cp-modal`, `hidden`; телефон `tel:`, мессенджер, «Заполнить заявку»; `data-ajax`, `data-action`, `data-nonce`
(`Nonce::LearnerActivity->create()`) — без `wp_localize_script`). `wp_enqueue_scripts` (20) → `assets/player.js`, только если
`apply_filters( 'fs_lms_is_player_route', false )` и `viewFor()` не `null`.
`wp_ajax_` + `CONTACT_ACTION = 'fs_lms_masterclass_contact'` → `MasterclassPlayerCallbacks::ajaxContact`.

**Сделано (этап 3).** `MasterclassAuthController( MasterclassAccountService, MasterclassLoginTracker )`: `accountRedirectUrl(): ?string` (чистая часть редиректа), `filterLoginRedirect`, `onLogin` (сбой трекера вход не рвёт), `filterCaptchaBypass` (только `CaptchaScope::Login`, решение другого подписчика `true` не отменяется).
`MasterclassPlayerController( MasterclassAccessGuard, MasterclassLoginTracker, MasterclassPlayerCallbacks )`: `CONTACT_ACTION`, методы-швы `filterBackUrl/filterLockHtml/renderActions/renderBanner/renderContact`, `onPlayerRoute`, `enqueueAssets`; шаблоны рендерятся в строку замыканием. Тесты — `MasterclassAuthControllerTest`, `MasterclassPlayerControllerTest`.
`MasterclassConversionService` пока `( MasterclassAccountRepository, MasterclassLogWriter, ClockInterface )` и только `logContact()` — этап 5 расширит конструктор.

### `MasterclassEnrollmentController`
`fs_lms_application_created` → `onApplicationCreated( int $applicationId )`: `get_current_user_id()` 0 → выход, иначе
`bindApplication()`. `fs_lms_student_enrolled` (10, 2) → `markEnrolled( $studentPersonId )`. `fs_lms_apply_prefill` → пустой
`$prefill` и залогинен → `MasterclassConversionService::prefill()`. (Подписки Ш4 `fs_lms_service_group_ids` и Ш3 `fs_lms_group_deleting` живут в
`MasterclassIntegrityController` — они нужны и при выключенном модуле.)
(Обработчики — однострочные методы контроллера, как `AdSyncController::onApplicationCreated`.)

### `MasterclassCronController`
`TICK_HOOK = 'fs_lms_masterclass_tick'`, `LOCK = 'masterclass_tick'`. `register()`: `add_action( TICK_HOOK, … )`,
`CronManager::schedule( TICK_HOOK, 'every_15_minutes' )` (интервал регистрирует `CronController` ядра). `onTick()` →
`ExamTickLock::run( self::LOCK, fn() => $lifecycle->tick() )`. `unschedule()` → `CronManager::unschedule( TICK_HOOK )`.

## 10. Callbacks (`Inc\Modules\Masterclass\Callbacks`, новое; `extends BaseController`, `Authorizer`, `Sanitizer`)

### `MasterclassSettingsCallbacks`
`ajaxSave()` — `authorize( Nonce::Config, Capability::ManageLmsPlatform )`; целые через `sanitizeInt()`, время/текст —
`sanitizeText()`, `messenger_url` — `esc_url_raw( sanitizeText() )`; сохраняются только присланные ключи (`MasterclassConfig::save()`).
**Сделано (этап 1):** «присланный» — `hasParam()`; ссылка — `sanitizeHttpUrlOrNull()` (`http(s)://…`; `javascript:`, `ftp:` и текст без точки — отказ с сообщением, пустая строка очищает ссылку);
приведение и проверка окна — `MasterclassConfig::normalize()` (при отказе не сохраняется ничего, в том числе остальные присланные поля). Ключа `enabled` в запросе нет: тумблер на Dashboard.
Ответ об ошибке — `wp_send_json_error( строка )`, JS показывает `res.data`. Тест — `MasterclassSettingsCallbacksTest`; по HTTP проверены сохранение, кириллица, отказы, ответ 403 без nonce.

### `MasterclassAdminCallbacks`
Везде первая строка `authorize( Nonce::Config, Capability::ManageLmsPlatform )`; `\InvalidArgumentException` → `error( $e->getMessage() )`.
- `ajaxCreate()` — `school_name`, `subject_key`, `lesson_id`, `teacher_user_id`, `starts_at`, `ends_at` → `success( { id } )`
  (аккаунты страница добирает `ajaxAddAccounts` циклом).
- `ajaxUpdate()` — то же + `id` → `success( { id } )`.
- `ajaxExtend()` — `id`, `days` → `success( { freeze_at, purge_at } )` (местное время).
- `ajaxDelete()` — `id` → `success( { detached: int } )`.
- `ajaxAddAccounts()` — `id`, `count` (≤ `CHUNK_MAX`) → `success( { created: int, total: int } )`.
- `ajaxUnlock()` — `id` → `success( { unlocked: int } )` (`MasterclassAccountService::unlockLogins()`).
- `ajaxLessons()` — `subject_key` → `success( lessonOptions() )`.
- `printFlyers(): void` (admin-post, GET `id`, `security`) — `Nonce::Config->verify()` + `current_user_can( ManageLmsPlatform )`
  (не AJAX-метод — прямая проверка права допустима, `CLAUDE.md`), `nocache_headers()`, `require templates/flyers.php` (свой
  `<!doctype html>`, `assets/flyers.css`), `exit`.

**Сделано (этап 2).** Тест — `MasterclassAdminCallbacksTest` (право и nonce на **каждом** входе, спрашивается именно `ManageLmsPlatform`, мутации убиты):
- Конструктор: `MasterclassService`, `MasterclassAccountService`, `MasterclassAccountRepository` (для `total` в `ajaxAddAccounts` — `countLive()`), `ExamTime` (местное время в ответе `ajaxExtend`).
- Ввод формы: `school_name` / `subject_key` / `lesson_id` / `teacher_user_id` обязательны (`requireText/Key/Int`), `starts_at` / `ends_at` — `sanitizeText()`, строгий разбор формата — в сервисе. `id` — `requireInt`.
- Отказ сервиса (`\InvalidArgumentException` / `\RuntimeException`) — `error( $message )` его же текстом; сбой хранилища (`\RuntimeException`) дополнительно в журнал PHP (`PluginLogger::exception`).
- `printFlyers()`: `Nonce::Config->verify()` → прямая проверка права (`wp_die` 403) → `flyersDocument( $id )` (публичный, возвращает HTML — так он тестируется; мастер-класса нет → `\InvalidArgumentException` → `wp_die` 404) →
  `nocache_headers()`, `Content-Type`, вывод, `exit`. Выдача пишется в журнал сервисом (`FlyersPrinted`, число, без паролей).

### `MasterclassPlayerCallbacks`
`ajaxContact()` — `Nonce::LearnerActivity->verify()`; не залогинен → `error`; `kind` → `MasterclassContactKind::fromRequest()`
(неизвестный → `error`); `logContact( get_current_user_id(), $kind )`; ответ `success()` всегда (по ответу не узнать, аккаунт ли).

## 11. Шаблоны и ассеты

- `admin-page.php`: `.fs-page-header`, переключатель «Мастер-классы | Журнал», карточка-форма создания/правки (`admin-form.php`,
  `.fs-card`, скрыта атрибутом `hidden`, без модалки — модульный JS не тянет `modal-base.js` ядра), таблица `overview()` (школа,
  дата и окно местного времени, преподаватель, состояние, воронка), действия: «Открыть в плеере», «Докинуть», «Печать флаеров»
  (ссылка на `admin-post.php?action=…&id=…&security=…`, `target="_blank"`), «Аккаунты», «Снять блокировки», «Продлить», «Изменить», «Удалить»
  (подтверждение).
- `admin-accounts.php`: заголовок мастер-класса (школа, дата), ссылка «← К мастер-классам», таблица аккаунтов (`MasterclassAccountService::page()`): «№», «Логин»,
  «Состояние», «Первый вход», «Дома», «Связался», «Заявка» (да/нет), у удалённых — «Удалён: причина»; галочка «Показать удалённые», пагинация по 50. Только штатные классы
  админки (`wp-list-table`), без паролей.
- `assets/admin.js` — self-contained IIFE на jQuery (как `AdSync/assets/admin.js`): форма, список уроков по предмету (непригодные —
  неактивны с причиной), цикл `ajaxAddAccounts` с прогрессом «создано X из Y», продление, удаление. Показ/скрытие — `hidden`,
  стили из JS не задаются.
- `flyers.php` + `assets/flyers.css`: 4 флаера на A4 (`@media print`), школа, номер, логин, пароль, адрес `/sign-in/`. Пароли не
  логируются. Размеры печати в `mm` — единственное место с «сырыми» величинами (печатный носитель, не UI).
**Сделано (этап 2).** `admin-page.php` (список + форма + панель числа), `admin-form.php`, `admin-accounts.php`, `flyers.php`, `assets/admin.js`, `assets/flyers.css`, **`assets/flyers.js`** (кнопка «Печать» — inline-JS в
печатном документе нельзя). Подробности:
- `admin-page.php`: переменные `rows, total, page, pages, teachers, subjects, defaults, list_url, chunk_max`; действия строки — `data-mk-action="add|unlock|extend|edit|delete"` (кнопки `button-link`) и ссылки
  «Открыть в плеере» / «Аккаунты» / «Печать флаеров». У «удаляется» и «вычищен» изменяющих действий нет (у вычищенного — только «Аккаунты»). Общая панель `#fs-masterclass-panel` (число дней / число аккаунтов) вместо
  модалки; сообщение и прогресс — `#fs-masterclass-notice` (`hidden`).
- `admin-accounts.php`: «№ · Логин · Состояние · Первый вход · Дома · Связался · Заявка», у удалённых — «Удалён <время>: <причина>»; «Показать/Скрыть удалённые»; страницы по 50 («← Назад» / «Вперёд →»; ссылки
  сохраняют `deleted=1`); пустой список, вычищенный мастер-класс и несуществующий `id` — сообщение, а не фатал. Дата мастер-класса — `д.м.Г`.
- `flyers.php`: свой `<!doctype html>`, 4 флаера на лист (`.sheet` → `.flyer`), школа, адрес `/sign-in/`, логин, пароль, «№ N»; пустой список — «Нет аккаунтов для печати…». Размеры в `mm` — только в `flyers.css`.
- `admin.js`: цикл `addAccounts()` идёт по набранному числу, а не по числу порций (порция может создать меньше, чем просили); после трёх подряд пустых порций прекращает; на 403 `E-SESSION` просит обновить страницу.
- `player-*.php` — только штатные классы плеера (`.s-banner`, `.lock-*`, `.b .b-pri`, `.cp-dim/.cp-modal`) и `Icon`.
- `assets/player.js` — self-contained, без зависимостей: открытие/закрытие окна (`hidden`), отправка вида клика
  `fetch( data-ajax, { method: 'POST', keepalive: true, body } )` (`keepalive` — переход по `tel:`/мессенджеру не обрывает запрос).
  Устаревший nonce чинит `nonce-refresh.js` бандла плеера (перехватывает `fetch`).

## 12. Тесты (PHPUnit, `tests/Unit/Modules/Masterclass/` + ядро)

- Ядро: Ш1 (без подписчика — прежняя матрица `LessonAccessPolicyTest`; понижение работает; повышение игнорируется), Ш3
  (исключение подписчика не мешает удалению), Ш4 (`ajaxGetStudentGroups` без служебных, `enrollMany` отказывает), Ш5, Ш6
  (без подписчика лимит прежний), Ш7 (на MariaDB: запись без заявки плитку не получает), `OpenCourseValidator::lessonProblems()`,
  `PasswordGeneratorService::generateReadable()` (алфавит, длина).
- Граница: тест-сканер `inc/` вне `Modules/Masterclass` на `Masterclass` / `masterclass` (как `profile-no-native-select`).
- `MasterclassModuleTest` — выключен: зарегистрированы только настройки, Ш1, `deleted_user`; включён — всё.
- `MasterclassLoginTrackerTest::classify()` — до начала, в окне, после окна, повтор через 59/60 мин; удалённый/зачисленный → `null`.
- `MasterclassAccessGuardTest` — заморозка по `freeze_at`, чужая группа, не `isTrial`, `Enrolled`, удалённый; `viewFor()` (баннер).
- `MasterclassServiceTest` — валидации, порядок `create()`, `update()` синхронизирует преподавателя и расписание, `extend()`, `requestDelete()`.
- `MasterclassAccountServiceTest` — `loginFor()`, `createChunk()` (номер = id, занятый логин, откат при сбое учётки, лимит 25),
  `flyers()`, `deleteAccount()` (метка до события), `detachAccount()`, `unlockLogins()` (все живые аккаунты, удалённые не трогаются, журнал), `page()` (50 на страницу, удалённые по флагу, нет паролей и ПД в строке, местное время, мастер-класса нет — исключение).
- `MasterclassConversionServiceTest` — привязка (поля заявки, `trial_owner`, `syncCredentials`), отказ при живой заявке и при уже
  привязанной заявке, `markEnrolled()` переписывает имя учётки.
- `MasterclassLifecycleServiceTest` — бюджет аккаунтов, идемпотентность каждого шага, отвязка при `delete_requested_at`, группа не
  удаляется при живом аккаунте с заявкой, `onGroupDeleting()`, `onUserDeleted()`, `purgeLog()`.
- Коллбеки — право/nonce, ключи `Sanitizer`, ответы (память проекта «Cover callbacks with tests»).
- На MariaDB (`wp_db`): параллельные `createChunk()` (номера уникальны), `markFrozen()` один раз при двух тиках, тик не идёт
  параллельно, удаление аккаунта из крона (`wp cron event run fs_lms_masterclass_tick`).

## 13. Правила модуля (`inc/Modules/Masterclass/CLAUDE.md` и абзац в корневом `CLAUDE.md`, раздел «Модули»)

- Ядро о модуле не знает: связь — швы §2 и общие хуки WP. Новый шов называется по смыслу ядра.
- Заморозка выводится из времени (`MasterclassDTO::isFrozen`), применяется только через Ш1; новый путь доступа к занятию в ядре
  обязан идти через `LessonAccessPolicy::resolve()`.
- Строки аккаунтов не удаляются (мягкое удаление); номер — `id`; логин строит только `MasterclassAccountService::loginFor()`.
- Первый вход, «дома», «связаться» — условный `UPDATE … IS NULL`.
- Заявку к аккаунту привязывает только `MasterclassConversionService` по хуку, аккаунт — из сессии.
- Аккаунт с живой заявкой не удаляется, а отвязывается (`DeleteStudentEvent` стёр бы заявку).
- Удаление: аккаунты (порциями, тик) → группа (`DeleteGroupEvent`); любой чужой путь удаления группы проходит через Ш3.
- Журнал: пароли и ПД не пишутся; срок хранения — `log_retention_days`.
