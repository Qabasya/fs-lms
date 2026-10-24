# Мастер-классы: контракт имён и классов

Источник правды о том, **какие классы создаются, что в них есть, что возвращают и от чего зависят**. Продуктовая часть —
`SPEC.md`. «(новое)» — в коде ещё нет; «(изменить)» — существующий класс ядра; остальное проверено по коду на 2026-10-24.

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
├── templates/    settings-tab.php, admin-page.php, admin-form.php, admin-log.php, flyers.php,
│                 player-banner.php, player-actions.php, player-contact.php, player-lock.php
└── assets/       admin.js, flyers.css, player.js
```

Тесты — `tests/Unit/Modules/Masterclass/` (как у остальных модулей).

## 2. Изменения ядра (универсальные швы, этап 0)

Каждый шов — докблок с контрактом и тест ядра «без подписчиков — прежнее поведение». Слова «мастер-класс» в ядре нет.

### Ш1. `Services\Course\LessonAccessPolicy` (изменить)
Тело `resolve()` переносится в `private function resolveBase( StudentRecordDTO, GroupLessonDTO ): AccessLevel`;
`resolve()` = `$base = resolveBase(...)`; `$filtered = apply_filters( 'fs_lms_lesson_access', $base, $record, $lesson )`;
возвращает `$filtered`, только если это `AccessLevel` и он **не выше** `$base` (порядок `None < Read < ReadSubmit`), иначе `$base`.
Нужен метод `AccessLevel::rank(): int` (изменить `Enums\Access\AccessLevel`: сейчас там только кейсы `None`, `Read`, `ReadSubmit`). Тесты: без подписчика — прежняя матрица;
подписчик может понизить; попытка повысить игнорируется.

### Ш2. Плеер (изменить `Controllers\Course\LessonPlayerController`, `templates/frontend/lesson-player/player.php`)
Только в виде ученика (`! $isTeacher && ! $isParent`; в предпросмотре — не вызываются):
- `$back_url = (string) apply_filters( 'fs_lms_player_back_url', $back_url, $userId, $row )`; пустая строка — `.s-back` не выводится.
- В `.lock-modal`, когда `$locked`: `$lock_html = (string) apply_filters( 'fs_lms_player_lock_html', '', $userId, $row )`;
  непустая — выводится **вместо** стандартных веток (уже экранированный HTML шаблона модуля), иконка `.lock-ico` остаётся.
- `do_action( 'fs_lms_player_header_actions', $userId, $row )` — внутри `.s-right` перед кнопкой-колокольчиком.
- `do_action( 'fs_lms_player_before_content', $userId, $row )` — перед `<div class="player" id="fsPlayer">`.
- `do_action( 'fs_lms_player_footer', $userId, $row )` — перед `wp_footer()`.
Переменные `$userId` и `$row` шаблону отдаёт контроллер (сейчас не передаются). Плюс общий компонент `.s-banner`
(`.s-banner__text`, `.s-banner__actions`) в `src/scss/player/components/_shell.scss` — токены и переменные темы, без сырых значений.
Кейсы `Icon::Phone`, `Icon::Message`, `Icon::Logout` (изменить `Enums\Ui\Icon` + SVG; JS-двойники в `common/icons.js` не нужны).

### Ш3. `Services\Deletion\GroupDeletionHandler::handle()` (изменить)
Первой строкой, до транзакции: `do_action( 'fs_lms_group_deleting', $event->groupId, $event->actorId )`. Докблок: подписчик
убирает свои данные, связанные с группой (учётки временных учеников ядро не трогает — `deleteAllByGroupAndCollect()` собирает
только `is_trial = 0`); исключение подписчика удаление группы не останавливает (обернуть в `try/catch` + `PluginLogger::exception`).

### Ш4. Служебные группы (изменить)
Фильтр `fs_lms_service_group_ids` → `list<int>`: группы, созданные модулями для своих целей, — не для зачисления настоящих
учеников. Чтение — новый метод `Services\Group\ServiceGroupResolver` (новое, ядро): `ids(): array` (кеш на запрос),
`isService( int $groupId ): bool`. Потребители:
- `Callbacks\Enrollment\EnrollmentLifecycleCallbacks::ajaxGetStudentGroups()` — исключить из списка;
- `Services\Enrollment\OpenGroupEnrollmentService::enrollMany()` — `\InvalidArgumentException( 'В служебную группу учеников не добавляют.' )`;
- `Services\Export\StudentsExportProvider::rows()` — ученик, у которого все записи в служебных группах, пропускается.
В этапе 0 — `grep` остальных выборов группы для зачисления (`findByPeriodAndSubject`, `findByFilters`) и решение по каждому
записать в `tasks/stage-00.md`.

### Ш5. `Controllers\Pages\ApplyPageController::renderApplyForm()` (изменить)
`$prefill = (array) apply_filters( 'fs_lms_apply_prefill', $prefill ?? array() )` — после разбора ссылки гостя экзамена; ключи —
те же, что читает `templates/frontend/apply-fields.php` (`subject_key`, `school`, …).

### Ш6. `Services\Captcha\CaptchaService::check()` (изменить)
Перед `allowCaptchaFallback()`: если `$failure?->allowsFallback()` и
`true === apply_filters( 'fs_lms_captcha_fallback_unlimited', false, $scope, (string) $identifier, $remoteIp )` — лимит по IP не
применяется (запись `AuthAction::CaptchaFallback` в журнал остаётся, в `note` — «без лимита по правилу модуля»).

### Ш7. `Repositories\WPDBRepositories\StudentRecordRepository::findActiveTrials()` (изменить)
Добавить `AND EXISTS ( SELECT 1 FROM {applications} a WHERE a.student_person_id = r.student_person_id )` (`TableName::Applications`):
плитка «Временный доступ: ждём анкету родителя» (`NotificationCronService::trialAccess()`) — только ученикам с заявкой, так
устроен `TrialAccessService::grant()`. Тест на MariaDB.

### Прочее в ядре
- `Services\Course\OpenCourseValidator`: выделить `public function lessonProblems( LessonDTO $lesson ): array` (тело цикла
  `problems()`); `problems()` зовёт его. Поведение не меняется.
- `Init::getServices()`: `MasterclassModule::class` рядом с `VideoLibraryModule` (+ `use`).
- Предусловия из `SPEC.md` §11 (исправления текущего кода) — до этапа 2.

## 3. Схема (`Schema\MasterclassSchema`, новое)

По образцу `VideoSchema`: `VERSION_OPTION = 'fs_lms_masterclass_schema_version'`, `VERSION = '1'`;
`public static function masterclasses(): string`, `accounts(): string`, `log(): string` — полные имена таблиц;
`public function ensure(): void` — `dbDelta` трёх DDL, только переносимый SQL (без `IF NOT EXISTS` у колонок); новая колонка
в будущем — `SHOW COLUMNS … LIKE` + обычный `ADD COLUMN` и подъём `VERSION`. Вызывается из `MasterclassModule::register()`
до любых зависящих от таблиц регистраций, **в том числе при выключенном модуле** (Ш1 и `deleted_user` читают таблицы).
Удаление таблиц — общий `uninstall.php` (по префиксу `fs_lms_`).

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
  `ContactMessenger`, `ApplyClicked`, `ApplicationBound`, `ApplicationBindFailed`, `Enrolled`. `label(): string`;
  `static fromContact( MasterclassContactKind ): self`. Хука `do_action` нет: внутри модуля пишет `MasterclassLogWriter` напрямую.

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
- `serviceGroupIds(): list<int>` — `MasterclassRepository::activeGroupIds()` с кешем на запрос.
- `private deadlines( string $endsAtUtc ): array{freeze_at, unused_purge_at, purge_at}`; `private groupName( string $school,
  string $dateLocal ): string` — «Мастер-класс · {школа} · {дд.мм.гггг}», занято (`existsByNameAndPeriod`) — « (2)», « (3)».

### `MasterclassAccountService`
Зависимости: `MasterclassAccountRepository`, `MasterclassRepository`, `AccountProvisioningService`, `PersonService`,
`StudentRecordRepository`, `UserManager`, `PasswordGeneratorService`, `DeletionEventDispatcher`, `ApplicationRepository`,
`MasterclassLogWriter`, `ClockInterface`. Трейт `TransactionRunner`.

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
- `findLiveByUserId( int $userId ): ?MasterclassAccountDTO` — не удалён и не `Enrolled`.
- `playerUrlFor( int $userId ): ?string` — `PageRoutes::LessonPlayer->lessonUrl( groupId, groupLessonId )`; `null`, если нет живого
  аккаунта или мастер-класс без группы.
- `deleteAccount( MasterclassAccountDTO $a, AccountDeleteReason $reason, int $actorUserId ): void` — `markDeleted()` **до** удаления
  (обработчик `deleted_user` увидит уже помеченную строку и ничего не сделает), затем `DeletionEventDispatcher::dispatch(
  new DeleteStudentEvent( $a->personId, $actorUserId ) )`; журнал `AccountDeleted` (`context.reason`). Данные ученика по занятию
  удаляет потом `GroupDeletionHandler` (по `group_lesson_id`), отдельной чистки нет.
- `detachAccount( MasterclassAccountDTO $a, int $actorUserId ): void` — `StudentRecordRepository::deleteTrialByStudent( personId )`,
  `clearStudentRecord()`; учётка и физлицо остаются за заявкой; журнал `AccountDetached`.
- `hasLiveApplication( MasterclassAccountDTO $a ): bool` — `ApplicationRepository::find( applicationId )` в статусе
  `PendingParent`, `ReadyForReview` или `Enrolling`.

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

### `MasterclassLogWriter`
Зависимости: `MasterclassLogRepository`, `MasterclassAccountRepository`, `ClockInterface`. Трейт `RequestContextProvider`.
`write( MasterclassLogAction $action, array $data = array() ): void` — ключи `$data`: `masterclass_id`, `account_id`, `actor`,
`actor_type` (`user`|`guest`|`system`), `result`, `context` (массив без паролей и ПД). Подпись «Ученик#N» — по `account_id`;
IP — из `RequestContextProvider`. Сбой гасится (`PluginLogger::exception( 'MasterclassLog', …, true )`).

### Дополнение ядра `PasswordGeneratorService` (изменить)
`generateReadable( int $length = 8 ): string` — алфавит без `0 O 1 l I`, `random_int`. Существующие методы не меняются.

## 9. Контроллеры (`Inc\Modules\Masterclass\Controllers`, новое; все `extends BaseController`, без `ServiceInterface` —
их зовёт `MasterclassModule`)

### `MasterclassModule implements ServiceInterface`
Конструктор: все контроллеры ниже + `MasterclassSchema`, `MasterclassConfig`. `register()`:
`$schema->ensure()` → `$settings->register()` → `$integrity->register()` → если `! $config->isEnabled()` — выход →
`$admin->register()`, `$auth->register()`, `$player->register()`, `$enrollment->register()`, `$cron->register()`.

### `MasterclassSettingsController` (всегда)
`SAVE_ACTION = 'fs_lms_masterclass_save_settings'`. `register()`: `wp_ajax_` + `SAVE_ACTION` → `MasterclassSettingsCallbacks::ajaxSave`;
`fs_lms_settings_tabs` (`'tab-masterclasses' => array( 'title' => 'Мастер-классы', 'path' => … templates/settings-tab.php )`, только
при включённом); `fs_lms_dashboard_modules` (`id` `masterclass`, `const_key` `FS_LMS_MASTERCLASS`); `fs_lms_module_toggle_masterclass`
→ `onToggle( bool )`: `config->save( enabled )`, при выключении — `MasterclassCronController::unschedule()`.

### `MasterclassIntegrityController` (всегда)
`add_filter( 'fs_lms_lesson_access', array( $guard, 'filterLessonAccess' ), 10, 3 )`;
`add_action( 'deleted_user', array( $lifecycle, 'onUserDeleted' ) )`.

### `MasterclassAdminController`
`PAGE_SLUG = 'fs_lms_masterclasses'`; экшены: `CREATE_ACTION = 'fs_lms_masterclass_create'`, `UPDATE_ACTION`, `EXTEND_ACTION`,
`DELETE_ACTION`, `ADD_ACCOUNTS_ACTION`, `LESSONS_ACTION`, `FLYERS_ACTION = 'fs_lms_masterclass_flyers'` (admin-post).
`register()`: `MenuManager::register( array(), array( subpage: parent_slug Menu::Main->value, page_title/menu_title «Мастер-классы»,
capability Capability::ManageLmsPlatform->value, menu_slug PAGE_SLUG, callback array( $this, 'renderPage' ) ) )`;
`wp_ajax_*` → `MasterclassAdminCallbacks`; `admin_post_` + `FLYERS_ACTION` → `MasterclassAdminCallbacks::printFlyers`;
`admin_enqueue_scripts` → `enqueueAssets()` (только на `PAGE_SLUG`: `assets/admin.js` + `wp_localize_script( 'fsLmsMasterclass',
{ ajaxurl, nonce: Nonce::Config->create(), actions, chunkMax, defaults } )` — исключение «модуль локализует свой admin-JS в своём
контроллере», `CLAUDE.md`). `renderPage()`: `require` `templates/admin-page.php` с данными `MasterclassService::overview()`,
вкладка `?view=log` — `templates/admin-log.php` с `MasterclassLogRepository::list()`.

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

### `MasterclassEnrollmentController`
`fs_lms_application_created` → `onApplicationCreated( int $applicationId )`: `get_current_user_id()` 0 → выход, иначе
`bindApplication()`. `fs_lms_student_enrolled` (10, 2) → `markEnrolled( $studentPersonId )`. `fs_lms_apply_prefill` → пустой
`$prefill` и залогинен → `MasterclassConversionService::prefill()`. `fs_lms_service_group_ids` → `array_merge( $ids,
MasterclassService::serviceGroupIds() )`. `fs_lms_group_deleting` (10, 2) → `MasterclassLifecycleService::onGroupDeleting()`.
(Обработчики — однострочные методы контроллера, как `AdSyncController::onApplicationCreated`.)

### `MasterclassCronController`
`TICK_HOOK = 'fs_lms_masterclass_tick'`, `LOCK = 'masterclass_tick'`. `register()`: `add_action( TICK_HOOK, … )`,
`CronManager::schedule( TICK_HOOK, 'every_15_minutes' )` (интервал регистрирует `CronController` ядра). `onTick()` →
`ExamTickLock::run( self::LOCK, fn() => $lifecycle->tick() )`. `unschedule()` → `CronManager::unschedule( TICK_HOOK )`.

## 10. Callbacks (`Inc\Modules\Masterclass\Callbacks`, новое; `extends BaseController`, `Authorizer`, `Sanitizer`)

### `MasterclassSettingsCallbacks`
`ajaxSave()` — `authorize( Nonce::Config, Capability::ManageLmsPlatform )`; целые через `sanitizeInt()`, время/текст —
`sanitizeText()`, `messenger_url` — `esc_url_raw( sanitizeText() )`; сохраняются только присланные ключи (`MasterclassConfig::save()`).

### `MasterclassAdminCallbacks`
Везде первая строка `authorize( Nonce::Config, Capability::ManageLmsPlatform )`; `\InvalidArgumentException` → `error( $e->getMessage() )`.
- `ajaxCreate()` — `school_name`, `subject_key`, `lesson_id`, `teacher_user_id`, `starts_at`, `ends_at` → `success( { id } )`
  (аккаунты страница добирает `ajaxAddAccounts` циклом).
- `ajaxUpdate()` — то же + `id` → `success( { id } )`.
- `ajaxExtend()` — `id`, `days` → `success( { freeze_at, purge_at } )` (местное время).
- `ajaxDelete()` — `id` → `success( { detached: int } )`.
- `ajaxAddAccounts()` — `id`, `count` (≤ `CHUNK_MAX`) → `success( { created: int, total: int } )`.
- `ajaxLessons()` — `subject_key` → `success( lessonOptions() )`.
- `printFlyers(): void` (admin-post, GET `id`, `security`) — `Nonce::Config->verify()` + `current_user_can( ManageLmsPlatform )`
  (не AJAX-метод — прямая проверка права допустима, `CLAUDE.md`), `nocache_headers()`, `require templates/flyers.php` (свой
  `<!doctype html>`, `assets/flyers.css`), `exit`.

### `MasterclassPlayerCallbacks`
`ajaxContact()` — `Nonce::LearnerActivity->verify()`; не залогинен → `error`; `kind` → `MasterclassContactKind::fromRequest()`
(неизвестный → `error`); `logContact( get_current_user_id(), $kind )`; ответ `success()` всегда (по ответу не узнать, аккаунт ли).

## 11. Шаблоны и ассеты

- `admin-page.php`: `.fs-page-header`, переключатель «Мастер-классы | Журнал», карточка-форма создания/правки (`admin-form.php`,
  `.fs-card`, скрыта атрибутом `hidden`, без модалки — модульный JS не тянет `modal-base.js` ядра), таблица `overview()` (школа,
  дата и окно местного времени, преподаватель, состояние, воронка), действия: «Открыть в плеере», «Докинуть», «Печать флаеров»
  (ссылка на `admin-post.php?action=…&id=…&security=…`, `target="_blank"`), «Продлить», «Изменить», «Удалить» (подтверждение).
- `assets/admin.js` — self-contained IIFE на jQuery (как `AdSync/assets/admin.js`): форма, список уроков по предмету (непригодные —
  неактивны с причиной), цикл `ajaxAddAccounts` с прогрессом «создано X из Y», продление, удаление. Показ/скрытие — `hidden`,
  стили из JS не задаются.
- `flyers.php` + `assets/flyers.css`: 4 флаера на A4 (`@media print`), школа, номер, логин, пароль, адрес `/sign-in/`. Пароли не
  логируются. Размеры печати в `mm` — единственное место с «сырыми» величинами (печатный носитель, не UI).
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
  `flyers()`, `deleteAccount()` (метка до события), `detachAccount()`.
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
