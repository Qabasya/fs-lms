# Tasks — текущий этап: Видеозаписи занятий (S3 + REST, модуль VideoLibrary)

## Статус предыдущего этапа (17 багфиксов) — ✅ выполнен

Проверено по коду 2026-07-17: все 17 задач реализованы (сверка файл-в-файл по разбивке).
Найденная при проверке регрессия задачи 15 — висячий вызов удалённого метода
`LearnerService::subjectAbbr()` (`inc/Services/Profile/LearnerService.php:287`, фатал на живом пути
`build()→buildCatalog()` при наличии открытых групп с курсом) — **устранена в ходе проверки**
(строка `'abbr' => …` удалена; поле в JS никем не читалось).

**Хвосты этапа (мелочь, не блокируют):**

- Задача 8, этап 6: юнит-тест fallback'а `task_numbers` так и не написан —
  `tests/Unit/Services/Assessment/EgeCompletenessCheckerTest.php` покрывает только таксономические кейсы.
- Задача 1: внутренняя точка `.radio::after` в состояниях `.sel/.ok/.no` остаётся круглой внутри
  квадратного чекбокса (`_step-task.scss` — правила состояний ниже по исходнику перекрывают `rem(1)`).
- Задача 6: пограничный кейс двух пустых слотов (`taskId=0`) — ложный тост «Эта задача уже добавлена»
  (`slot-builder.js:186-191`) и `false` из `AssessmentManager::setItemIds()` (нет `array_filter` перед
  `array_unique`, в отличие от `WorkManager.php:99-103`).
- `ExamResultService`/`ExamResultDTO` — подтверждённый мёртвый код (0 вызовов) со старым сравнением
  порога по первичному баллу; удалить при случае.

---

## Этап: интеграция видеозаписей занятий (S3 Beget + fs-video-uploader)

**Статус 2026-07-17: V1–V10 реализованы** (модуль `Inc\Modules\VideoLibrary`, швы ядра V4,
тесты зелёные — 863 в контейнере, e2e по чек-листу V10 прогнан: matched/повтор/unmatched/
индивидуальная ветка/401/400/404 при выключенном модуле, ручная привязка-отвязка, presigned-фильтр
с фиктивными ключами). **Хвост: V8 этап 4** — смоук против реального Beget (curl подписанного
URL → 200, после TTL → 403) — после покупки S3 и прописывания реальных `FS_LMS_S3_*`.
Отступление от V9.3: JS ручной привязки — не в `src/js/admin/`, а self-contained asset модуля
`inc/Modules/VideoLibrary/assets/admin.js` (паттерн AdSync: core-бандл не знает о модуле, §4.6).

**Контекст.** Внешний сервис `fs-video-uploader` переносит записи занятий с SMB-шары в S3 и после
загрузки шлёт push-регистрацию в плагин. Контракты: сторона сервиса — `.docs/video-uploader.md`
(«LMS REST (push)», «TODO интеграции»), сторона плагина — `.docs/FS_LMS_API.md` §7.

**Зафиксированные решения:**

1. Новый **лист-модуль `Inc\Modules\VideoLibrary`** (чек-лист `ModularArchitecture.md` §8); ядро на модуль не ссылается.
2. Аутентификация — **HMAC** (схема AdSync: `X-Fs-Timestamp` + `X-Fs-Signature`, окно ±300 с), свой секрет `FS_LMS_VIDEO_HMAC_SECRET`.
3. Бакет **приватный**; выдача ученикам — **presigned SigV4** ссылки, генерит плагин (чистый PHP, без SDK; плеер уже понимает URL c query-string — `StepContentRenderer::resolveVideoMode()` парсит расширение из path).
4. Реестр записей — своя таблица `fs_lms_video_recordings` (upsert по `s3_key` = идемпотентность), version-gated схемой модуля (паттерн `AdSchema`).
5. Резолв занятия: `recorded_at` (нормализация к TZ сайта) + ветка по составу `lms`-блока: `group_id` → занятия группы того дня; `teacher_username` → `kind='individual'` занятия препода того дня по всем его группам. «Не нашли» → регистрация со статусом `unmatched` и **ответ 200** (4xx сервис трактует как терминальный fail).
6. При успешной привязке занятие помечается **`status='held'`** («запись есть → занятие состоялось») — `reflow` такие строки не сдвигает (`GroupLessonRepository::applySlots():139-143`), дата фиксируется.
7. В `group_lessons.recording_url` пишется стабильный указатель `s3://{bucket}/{key}`; в presigned-URL его превращает модуль через новый generic-фильтр `fs_lms_recording_url` (graceful absence: без модуля не-http указатель не рендерится).

**Вне кода (закупка/инфраструктура):** купить S3 у Beget (приватный бакет; в идеале два ключа —
write для сервиса, read-only для плагина, если Beget разрешает раздельные ключи), NTP на LXC сервиса.

**Порядок:** V1 → V2+V3 (параллельно) → V4 → V5 → V6 → V7 → V10 (без S3-ключей всё, кроме V8);
V8 — после покупки S3; V9 — после V7.

---

### Задача V1 — каркас модуля VideoLibrary

**Образец:** `inc/Modules/AdSync/` (эталон листа по `ModularArchitecture.md`).

**Этапы:**
1. `inc/Modules/VideoLibrary/VideoLibraryModule.php` — `implements ServiceInterface`, по `AdSyncModule.php:28-59`: settings-контроллер регистрируется всегда; рантайм (schema/REST/фильтр выдачи) — только при `config->isEnabled()`.
2. `inc/Modules/VideoLibrary/Config/VideoLibraryConfig.php` — по `AdSyncConfig.php`: опция модуля `fs_lms_video_library` (`['enabled' => false]`); `isEnabled()` с перекрытием константой `FS_LMS_VIDEO_LIBRARY`; `hmacSecret()` → константа `FS_LMS_VIDEO_HMAC_SECRET`; `s3()` → константы `FS_LMS_S3_ENDPOINT` (default `https://s3.ru1.storage.beget.cloud`), `FS_LMS_S3_REGION` (`ru-1`), `FS_LMS_S3_BUCKET`, `FS_LMS_S3_KEY`, `FS_LMS_S3_SECRET`; `presignTtl()` (default 6 ч, опция).
3. Секция настроек в «Настройки → Конфигурация» — по AdSync (`AdSyncSettingsController` + `Callbacks` + `templates/settings-section.php`): тумблер модуля, генератор секрета (кнопка «Сгенерировать» → строка `define('FS_LMS_VIDEO_HMAC_SECRET', '…');`), бейджи «задан/нет» для секрета и S3-констант.
4. Зарегистрировать `VideoLibraryModule::class` в `Init::getServices()` (`inc/Init.php:169-173`, рядом с `AdSyncModule::class`).

### Задача V2 — схема и репозиторий реестра записей

**Образец:** `inc/Modules/AdSync/Schema/AdSchema.php:16-61` (version-gated `ensure()`, своя опция версии).

**Этапы:**
1. `Schema/VideoSchema.php` — опция `fs_lms_video_schema_version`, таблица:

```sql
CREATE TABLE {prefix}fs_lms_video_recordings (
    id              bigint unsigned   NOT NULL AUTO_INCREMENT,
    s3_bucket       varchar(100)      NOT NULL,
    s3_key          varchar(500)      NOT NULL,            -- ключ идемпотентности
    manifest_key    varchar(510)      DEFAULT NULL,
    group_slug      varchar(100)      NOT NULL DEFAULT '', -- slug папки-источника (диагностика)
    group_id        smallint unsigned DEFAULT NULL,        -- из lms-блока (групповая ветка)
    teacher_user_id bigint unsigned   DEFAULT NULL,        -- резолв teacher_username (индив. ветка)
    group_lesson_id int unsigned      DEFAULT NULL,        -- привязка; NULL = unmatched
    status          varchar(20)       NOT NULL DEFAULT 'unmatched',  -- matched|unmatched
    recorded_at     datetime          NOT NULL,            -- нормализовано к TZ сайта
    size_bytes      bigint unsigned   NOT NULL DEFAULT 0,
    sha256          char(64)          NOT NULL DEFAULT '',
    duration_sec    int unsigned      DEFAULT NULL,
    payload         longtext          DEFAULT NULL,        -- сырой JSON запроса (аудит/переразбор)
    created_at      datetime          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      datetime          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    UNIQUE KEY s3_key (s3_key),
    KEY group_lesson_id (group_lesson_id),
    KEY status (status),
    KEY group_recorded (group_id, recorded_at)
);
```

2. `DTO/VideoRecordingDTO.php` (readonly, `fromRow()`), `DTO/VideoRecordingInputDTO.php`.
3. `Repositories/VideoRecordingRepository.php`: `findByS3Key()`, `upsertByS3Key()` (select → insert/update; вернуть id + признак «новая»), `attach(int $id, int $groupLessonId)`, `detach(int $id)`, `listUnmatched(int $limit = 50)`, `listByGroupLesson(int $glId)`, `countByStatus()`.
4. Тест репозитория на `FakeWpdb` — по образцу `tests/Integration/Repositories/*`.

### Задача V3 — HMAC-аутентификация модуля

**Этапы:**
1. `Services/VideoHmacAuth.php` — схема `AdHmacAuth.php:20-49` один-в-один (timestamp + "." + rawBody, `hash_equals`, skew 300 с), секрет из `VideoLibraryConfig`. Класс копируется, не импортируется из AdSync — листья не ссылаются друг на друга (`ModularArchitecture.md` §3.3); при третьем потребителе — вынести общий `HmacAuth` в Kernel (`inc/Services/Security/`), отдельным рефакторингом.

### Задача V4 — швы ядра: GroupLessonRepository + фильтр рендера

**Правки ядра (вне модуля):** модуль работает с чужой таблицей только через публичный репозиторий (`ModularArchitecture.md` §3.4).

**Этапы:**
1. `GroupLessonRepository::listByGroupAndDay( int $groupId, string $day ): array` — `WHERE group_id = %d AND DATE(scheduled_at) = %s` (day `Y-m-d`), возврат `GroupLessonDTO[]`.
2. `GroupLessonRepository::listIndividualByTeacherAndDay( int $teacherUserId, string $day ): array` — `kind='individual'`, тот же день, эффективный препод: `gl.teacher_user_id = %d OR (gl.teacher_user_id IS NULL AND g.teacher_id = %d)` (JOIN `fs_lms_groups g`).
3. `GroupLessonRepository::setRecordingUrl( int $id, ?string $url ): bool` — зеркало `setRoom()` (`GroupLessonRepository.php:216`); колонка `recording_url` уже есть, писателя не было.
4. `GroupLessonRepository::setStatus( int $id, LessonStatus $status ): bool` — писателя `status` в кодовой базе нет вообще (проверено grep: только DDL, enum `Inc\Enums\Course\LessonStatus` и чтение в `applySlots():140`).
5. Шов выдачи: в `LessonPlayerService.php:85` прокинуть URL через `apply_filters( 'fs_lms_recording_url', $groupLesson->recordingUrl, $groupLesson )`; в `StepContentRenderer::resolveVideoUrl()` (`:363-370`) — guard для ветки recording-slot: URL не начинается с `http` → вернуть `''` (без модуля указатель `s3://…` не рендерится — graceful absence §4.5).
6. Дополнить `tests/Integration/Repositories/GroupLessonRepositoryTest.php` (новые методы) и `tests/Unit/Services/Course/LessonPlayerServiceTest.php` (фильтр + guard).

### Задача V5 — резолвер занятия

**Этапы:**
1. `Services/VideoLessonResolver.php` (модуль). Вход: нормализованный `recorded_at` (`DateTimeImmutable` из ISO-offset → `wp_timezone()`; `scheduled_at` в БД — локальный wall-clock), `group_id|null`, `teacher_user_id|null`.
2. Ветка A (`group_id`): кандидаты `listByGroupAndDay()` минус `status='cancelled'` (включая `kind='individual'` этой группы — покрывает «индивидуальную запись положили в папку группы»).
3. Ветка B (`teacher_user_id`): кандидаты `listIndividualByTeacherAndDay()` минус cancelled.
4. Выбор: приоритет — попадание в окно `[scheduled_at − 45 мин; ends_at + 45 мин]` (`ends_at IS NULL` → `scheduled_at + 3 ч`); из нескольких — минимум `|recorded_at − scheduled_at|`; строгая ничья → неоднозначность.
5. Результат: `{group_lesson_id|null, reason: matched|no_candidates|ambiguous}`.
6. Юнит-тесты: обе ветки, окно/вне окна, два занятия в день, ничья, cancelled-скип, индивидуальное в папке группы.

### Задача V6 — сервис регистрации (upsert + привязка + held)

**Этапы:**
1. `Services/VideoRegistrationService.php`: валидация → `upsertByS3Key()` → резолв (только если строка новая или была `unmatched`; существующую привязку, в т.ч. ручную, повторная отправка **не** перерезолвливает — обновляются лишь метаданные).
2. При матче: `attach()` + `setRecordingUrl( $glId, "s3://{bucket}/{key}" )` + `setStatus( $glId, LessonStatus::Held )` — held ставить только если текущий статус `scheduled` (cancelled/moved не перетирать).
3. Кросс-чек группового `lms`-блока против `fs_lms_groups` (`course_id`/`teacher_id` расходятся → `PluginLogger::warning()`, не отказ). `teacher_username` → `get_user_by( 'login' )`; не найден → `unmatched` + WARNING.
4. `do_action( 'fs_lms_video_registered', int $recordingId, ?int $groupLessonId )` — generic-шов (Notifier и др.).
5. Юнит-тесты: идемпотентность (повтор → без дублей, привязка не сбита), unmatched-путь, held не ставится поверх cancelled.

### Задача V7 — REST-контроллер `POST /videos`

**Образец:** `AdSyncRestController.php:31-58`.

**Этапы:**
1. `Controllers/VideoRestController.php`: `register_rest_route( 'fs-lms/v1', '/videos', … )`, `permission_callback` → `VideoHmacAuth::verify()`.
2. Валидация тела: обязательные `s3_bucket`, `s3_key`, `recorded_at` (парсится как ISO-8601), `lms` — плоский объект, содержащий `group_id` (int>0) **или** `teacher_username` (строка); иначе `400 {ok:false, error}`.
3. Ответы строго по `FS_LMS_API.md` §7.3: `200 {ok:true, matched:bool, group_lesson_id:int|null}`; «занятие не найдено» — это `200 matched:false`, **не** 4xx.
4. Юнит-тест колбэков по образцу `tests/Unit/Modules/AdSync/*`.

### Задача V8 — presigned-выдача в плеер (требует S3-ключи)

**Этапы:**
1. `Services/S3UrlSigner.php` — SigV4 **query presign** на чистом PHP (`hash_hmac`-цепочка AWS4: date → region → service → aws4_request; параметры `X-Amz-Algorithm/Credential/Date/Expires/SignedHeaders/Signature`), path-style URL (`{endpoint}/{bucket}/{key}`), TTL из конфига. Без SDK — по правилам CLAUDE.md «только встроенные API».
2. Подписчик фильтра `fs_lms_recording_url` в модуле: указатель `s3://{bucket}/{key}` → presigned https; прочие URL — как есть. Регистрировать только при заполненных S3-константах.
3. Юнит-тест подписи по эталонному вектору (зафиксировать known-good пример: фикс. ключи/дата/ключ объекта → ожидаемая подпись).
4. Смоук против реального Beget — руками после покупки (curl подписанного URL → 200, после TTL → 403).

### Задача V9 — ручная привязка unmatched (админ)

**Этапы:**
1. В секции настроек модуля (или отдельная вкладка) — таблица unmatched-записей: `recorded_at`, источник (`group_slug`/препод), `s3_key`, размер; выбор «группа → занятие дня» → привязка тем же путём, что V6 (включая `recording_url` + held). Возможность отвязать/перепривязать matched-запись.
2. AJAX-действия — константами в контроллере модуля (паттерн `AdSyncController::STATUS_ACTION`, без правки core-enum `AjaxHook` — §4.6), `authorize()` с `Capability::Admin`.
3. JS — в `src/js/admin/` по существующим паттернам (объектный jQuery-модуль).

### Задача V10 — сквозная проверка (docker)

**Этапы:**
1. Поднять стек, включить модуль, задать `FS_LMS_VIDEO_HMAC_SECRET`; подписанный запрос (python/bash сниппет из `FS_LMS_API.md` §2) с валидным `group_id`+`recorded_at` под существующее занятие → `matched:true`; проверить в БД `recording_url` + `status='held'` + строку реестра.
2. Повторить тот же запрос → тот же `group_lesson_id`, без дублей в реестре.
3. Запрос с датой без занятий → `matched:false`, строка `unmatched`; привязать руками (V9).
4. Индивидуальная ветка: `lms:{teacher_username}` → матч `kind='individual'`.
5. `reflow` группы → held-занятие с видео не сдвинулось.
6. Плеер: шаг с `recording_slot=true` отдаёт presigned-ссылку (после V8); при выключенном модуле — шаг без записи, ничего не падает.
7. `composer` тесты + `npm run ci` — зелёные.

# AdSync: селект «Направление» в apply-форме вместо код-гейта + фильтр provisioning по предметам

## Контекст

Сейчас `subject_key` попадает в заявку только через «гейт кода направления» на `/lms/apply`: при включённом тумблере `applications_bind_to_subject` ученик обязан ввести цифровой код (карта `direction_codes` в `fs_lms_plugin_config`), иначе форма не рендерится. Модуль AdSync при `fs_lms_application_created` ставит **каждую** заявку в очередь `fs_lms_ad_outbox`, и Python-сервис создаёт AD-учётку всем — включая онлайн-учеников и «не компьютерные» предметы, которым домен не нужен.

Решение:
1. Код-гейт удалить полностью — вместо него **обязательный селект «Направление»** в apply-форме (опции из `fs_lms_subjects_list`).
2. Список «доменных» предметов — **чекбоксы в секции AdSync** таба «Конфигурация» (`provision_subjects` в опции `fs_lms_ad_sync`).
3. AdSync ставит provision-задание только по этим предметам; спиннер/поллинг «создаём аккаунт» — только если задание реально поставлено.

## Этап 1. Ядро: убрать гейт, добавить селект

- [x] `templates/frontend/apply.php` — удалить `$gated`, блок `#fs-apply-gate`, `#fs-apply-direction`; безусловный `require apply-fields.php`
- [x] `templates/frontend/apply-fields.php` — field-group «Направление»: `<select name="subject_key" id="fs_subject" required>` по образцу `fs_grade`; `data-validate` не нужен (нативный `valueMissing`)
- [x] `inc/Controllers/Pages/ApplyPageController.php` — зависимость `ApplicationSettingsService` → `SubjectRepository`; передавать `'subjects' => readActive()`
- [x] `inc/Callbacks/Enrollment/ApplicationCallbacks.php` — удалить `ajaxValidateDirectionCode()` и `ApplicationSettingsService`; в `ajaxCreateApplication()` валидация `requireKey('subject_key')` + `getByKey` (не null, не archived)
- [x] `src/js/frontend/services/apply-form.js` — удалить `_directionCode`, `initGate()`, ветку `bind_to_subject`; `subject_key` в `collectFormData()`
- [x] `inc/Controllers/Enrollment/ApplicationController.php` — убрать регистрацию `ValidateDirectionCode`
- [x] `inc/Enums/Wp/AjaxHook.php` — удалить кейсы `ValidateDirectionCode`, `SaveApplicationSettings`
- [x] `inc/Core/Enqueue.php` — удалить `validate_code`, `bind_to_subject`, зависимость `ApplicationSettingsService`
- [x] `inc/Services/Security/RateLimitService.php` — удалить `allowDirectionCode()` + `LIMIT_DIRECTION_CODE`
- [x] `src/scss/frontend/components/_apply-form.scss` — удалить блок `.fs-apply-gate`

## Этап 2. Ядро: удалить настройки гейта в админке

- [x] `templates/admin/components/tabs/settings-tabs/settings-7-config.php` — удалить форму `#fs-applications-form`; хук `fs_lms_config_sections` оставить
- [x] `src/js/admin/services/settings/config-settings.js` — удалить биндинг + `saveApplicationSettings()`
- [x] `inc/Callbacks/Settings/ConfigCallbacks.php` — удалить `ajaxSaveApplicationSettings()`, `sanitizeDirectionCodes()`, зависимость `SubjectRepository`
- [x] `inc/Controllers/Settings/ConfigController.php` — убрать регистрацию `SaveApplicationSettings`
- [x] `PluginConfigRepository::DEFAULTS` + `PluginConfig::viewState()` — удалить ключи `applications_bind_to_subject`, `direction_codes`
- [x] Удалить `inc/Services/Application/ApplicationSettingsService.php` + его тест
- [x] `src/scss/admin/components/_config.scss` — удалить блок `.fs-direction-codes`
- [x] Финальный grep `direction_code|bind_to_subject|directionCode|bindToSubject|validate_code|ValidateDirectionCode|ApplicationSettingsService` = 0 (кроме .docs до этапа 4)

## Этап 3. Модуль AdSync: фильтр по предметам

- [x] `AdSyncConfig` — `'provision_subjects' => array()` в DEFAULTS; методы `provisionSubjects(): array`, `shouldProvision(?string): bool` (пустой список = никого)
- [x] `AdProvisioningService` — `+ AdSyncConfig $config`; guard в `enqueueProvision()`; guard `latestByApplication()` в `enqueueDeprovisionByApplication()`; `enqueueDeprovisionByPerson()` и reconcile не фильтровать (комментарий в докблок)
- [x] `AdSyncController::filterApplyResponse()` — ранний return при `statusForApplication() === 'none'`
- [x] `templates/settings-section.php` — чекбокс-список `provision_subjects[]` + submit + статус
- [x] `assets/admin.js` — submit-обработчик (`SAVE_ACTION`, `Nonce::Config`)
- [x] `AdSyncSettingsCallbacks::ajaxSaveSettings()` — реальное сохранение (Sanitizer, валидация по `readAll()`)

## Этап 4. Тесты и документация

- [x] Удалить `tests/Unit/Services/Application/ApplicationSettingsServiceTest.php`
- [x] `AdProvisioningServiceTest` — мок `AdSyncConfig`, правки существующих + 2 новых negative-теста
- [x] `.docs/FS_LMS_API.md` §6, `.docs/AdSyncPythonService.md` — «коды направлений» → «направление из формы + provision_subjects»

## Этап 5. Верификация

- [x] `npx gulp build`, `npm run lint:js`, `npm run lint:css`, `vendor/bin/phpunit` (861 OK); phpcs — новых ошибок сверх исторических нет
- [x] `docker restart wp_app`; /lms/apply отдаёт селект `fs_subject`, гейта в разметке нет (HTTP 200)
- [x] `AdSyncConfig::shouldProvision` проверен в рантайме (math=true, inf/пусто/null=false); enqueue-guard'ы покрыты юнит-тестами
- [ ] Ручной прогон полного OTP-флоу с проверкой `subject_key` и строки в `fs_lms_ad_outbox` — сделать руками в браузере
- [ ] Админка: секция «Настройка заявок» исчезла, чекбоксы AdSync сохраняются — проверить в UI
- [ ] Регресс: предвыбор subject_key в модалке зачисления — проверить в UI
