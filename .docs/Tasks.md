# План рефакторинга по итогам аудита (2026-07-31)

Аудит: архитектура PHP, SOLID/дизайн, JS-конвенции, SCSS/шаблоны, мёртвый код.
Приоритеты: **P1** — высокая выгода / источник багов, **P2** — системный долг, **P3** — точечные правки, **P4** — решения/политика (обсудить, зафиксировать в CLAUDE.md).

---

## P1. Первая волна — высокая выгода, ограниченный риск

### 1.1 Log-инфраструктура: убрать ~400 строк копипасты
- [ ] Ввести `AbstractLogRepository` (общие `list()`, `countFiltered()`, `listAll()`, скелет `buildConditions()`; абстрактные `hydrate()`, `filterMap()`), отнаследовать 8 репозиториев `inc/Repositories/WPDBRepositories/Log/`
- [ ] Вынести дублированный `resolveRole()` из 7 Log-writer'ов (`inc/Services/Log/*Writer.php`) в `ActorRoleResolver` (или трейт)
- [ ] `AdminCallbacks::logsPage()` (`inc/Callbacks/System/AdminCallbacks.php:301`, 185 строк, 8 elseif по `LogChannel`) → реестр `LogPageProvider` по каналу; минус 8 из 19 зависимостей конструктора

### 1.2 Разбить главные god-классы
- [ ] `inc/Callbacks/Course/ProgramCallbacks.php` (708 строк, 31 ajax-метод, 10 зависимостей) → 4–5 классов: программа / расписание-reflow / индивидуальные занятия / ростер / дедлайны работ
- [ ] `inc/Services/Group/ScheduleService.php` (744 строки, 23 метода, 11 зависимостей) → `ProgramCompositionService` + `ScheduleReflowService` + `IndividualLessonService` + `GroupCalendarService` (синхронно с разбиением ProgramCallbacks)
- [ ] `inc/Controllers/Subject/SubjectController.php` — 18 зависимостей, из них 8 Callbacks-классов: диспетчеризация ajax через реестр вместо инъекции всех коллбеков; `getDefaultCptArgs()` (:367, 125 строк) и `registerForSubject()` (:247) → `SubjectCptArgsFactory` / Builders

### 1.3 Слои, которые «врут» о себе
- [ ] `inc/DTO/Task/PostsListTableDTO.php` — не DTO: держит `WP_Posts_List_Table`, рендерит HTML через `ob_start()`, мутирует `$_SERVER` → перенести в презентационный слой (`Controllers/Builders/` или `View/`) как `PostsListTablePresenter`
- [ ] `inc/DTO/Subject/ImportedEntitiesDTO.php` — мутабельный аккумулятор с 8 мутаторами → переименовать в `ImportedEntitiesCollector`, переместить в `Services/Subject/`

### 1.4 Токены SCSS: одинаковые имена — разные значения (источник багов)
- [ ] Устранить конфликт имён между `shared/_tokens.scss` и `frontend/_variables.scss`: `$spacing-xl` (20 vs 24), **`$font-size-sm` (10px vs 14px!)**, `$font-size-xs`, `$radius-pill`/`$border-radius-pill`, `$line-height-base`, `$font-mono` — либо переименовать с доменным префиксом, либо свести к одной шкале
- [ ] Согласовать две лестницы радиусов (tokens: 3/4/6/8/12 vs frontend: 4/6/8/12/16/999); схлопнуть неразличимые `$border-radius-sm`/`$border-radius-md` (3px vs 4px)
- [ ] Убрать точные дубли hex под разными именами: `#7048e8` ×3 (`_tokens.scss:105`, `_theme.scss:64,77`), `#b36b00` (`--wait` ≡ `--wait-ink`), `#d63638` (`$color-danger` скопирован в frontend), `#fdecec`, `#7c3aed`
- [ ] Почистить почти-дубли (правило памяти «не плодить близкие токены»): 4 бледно-зелёных в `_theme.scss:40,41,58,59`; `--line`/`--line-2` (:34-35); `--accent-soft`/`--accent-soft-3`; 3 неразличимых серых (`$cb-chip-bg`/`$cb-badge-bg`/`$wp-admin-gray-2`); 4 почти-белых

### 1.5 Мёртвая фича «Все задания» (JS)
- [ ] Решить судьбу цепочки из 5 неподключённых файлов (~350 строк): `frontend/services/all-tasks-page.js`, `all-tasks-api.js`, `components/filter-section.js`, `task-card.js`, `modules/card-tabs.js` — PHP-страница жива (`AllTasksPageController`, `templates/frontend/all-tasks.php`), JS не импортируется ни одной точкой входа
- [ ] Если фичу оживлять: починить рассогласование селекторов (`task-card.js` генерирует `.js-tab-btn`, `card-tabs.js` ищет `.js-answer-toggle`); если нет — удалить все 5 файлов

---

## P2. Вторая волна — системный долг

### 2.1 Бизнес-логика в Controllers → Services + templates
- [ ] `inc/Controllers/Course/LearningMenuController.php` (581 строка): usage-индексы (:426-487) → отдельный сервис индексации; фильтры списков (:174, :397) → `ListTableFilters`; рендер банков (:545) → шаблон
- [ ] `inc/Controllers/Problems/ProblemsController.php` (536 строк): `applyColumnSort()` (:263), `courseProblemIndex()`/`workProblemIndex()` (:429, :481) → сервис; `renderProblemsFilters()` (:332, 86 строк echo) → шаблон; регистрация CPT → Registrar
- [ ] `inc/Controllers/Pages/AssessmentPageController.php`: `loadTemplate()` (:106, ~127 строк — гарды, 404, заголовки, расчёт canRetry) и `buildTaskViews()` (:263) → сервис
- [ ] `inc/Controllers/Subject/ContentDeletionGuard.php` — расчёт ссылочности и transient-журнал (:124, :189-218) → сервис
- [ ] Убрать ручной `echo`/`printf` HTML из контроллеров (8 файлов, 25+ мест: MetaBox-контроллеры Assessment/Course/Lesson/Work/Task, ProblemsController, BoilerplatePageController, AdminController) → `TemplateRenderer`; отдельно `CourseMetaBoxController.php:62` — инлайновый `<style>`

### 2.2 Длинные методы (200+ строк) + array→DTO
- [ ] `EnrollmentService::enroll()` (`inc/Services/Enrollment/EnrollmentService.php:69`, 258 строк, 15 зависимостей) → `PersonResolver` + `EnrollmentTransaction`, декомпозиция на приватные шаги
- [ ] `LearnerService::build()` (`inc/Services/Profile/LearnerService.php:55`, 214 строк, 17 зависимостей, возвращает array) → билдеры секций + `LearnerDashboardDTO`
- [ ] `Enqueue::enqueue_admin_assets()` / `enqueue_frontend_assets()` (`inc/Core/Enqueue.php:77, :456`, 220/203 строки) → реестр asset-бандлов с `shouldEnqueue()`/`data()`

### 2.3 Дублирование PHP
- [ ] `AbstractRowImporter` для `StudentRowImporter` / `EnrolledStudentRowImporter` — `requireValues()`, `toDate()`, `toDateTime()` побайтово идентичны (`inc/Services/Import/`)
- [ ] `ProfileViewResolver::teacherConfig()` (:173, 127 строк) → перенести в `TeacherProfileView::build()`; резолвер только выбирает стратегию

### 2.4 Расширить трейт `Sanitizer` и закрыть сырые санитайзеры
- [ ] Добавить методы: `sanitizeIntList()`, `sanitizeKeyList()`, `unslashArray()` (или аналог) — текущий трейт не покрывает массивы/JSON
- [ ] Заменить ~30 сырых вызовов в 17 Callbacks-файлах (`AllTasksCallbacks`, `StudentGroupCallbacks`, `LogsCallbacks` ×4, `ProgramCallbacks`, `CourseBuilderCallbacks`, `LessonCallbacks` и др.)

### 2.5 Транзиенты: ввести абстракцию
- [ ] Нет `TransientManager`/`CacheRepository` — сырые `get/set/delete_transient` со строковыми ключами в `PiiController:142,154`, `ContentDeletionGuard:189-218`, `SubjectDataCallbacks:158-209`; ключи `fs_lms_export_`, `fs_lms_delete_blocked_` продублированы строками в двух слоях

### 2.6 Modules: параллельная архитектура → общий стандарт
- [ ] 5 модульных `*Config`-классов (`SocialAuth`, `SmartCaptcha`, `DaData`, `VideoLibrary`, `AdSync`) с прямыми `get_option/update_option` → репозитории/OptionName
- [ ] 5 `wp_localize_script` вне `Enqueue.php` (`ModulesDashboardController:38` + 4 модульных Settings-контроллера) → перенести в Enqueue или легализовать модульный паттерн в CLAUDE.md
- [ ] `AdSyncController.php:97` — `wp_send_json_success()` напрямую → трейт `AjaxResponse`
- [ ] `SocialAuth/templates/settings-tab.php:19` — `get_option('fs_lms_auth_settings')` сырой строкой прямо в шаблоне
- [ ] `SmartCaptchaModule.php:34` — конкретный `YandexSmartCaptchaProvider` вместо `CaptchaProviderInterface` → биндинг в контейнере

### 2.7 DIP: точечные нарушения
- [ ] `ImportCallbacks.php:45-46` — инъекция конкретных импортёров вместо реестра `array<ImportMode, RowImporterInterface>`
- [ ] `ProfileViewResolver.php:39-40` — конкретные view-классы вместо `ProfileViewInterface`
- [ ] `ContentUsageService::kindOf()` — статика в DI-сервисе (вызовы из `ContentDeletionGuard:94,217,235`) → выделить `ContentKindResolver` по образцу `PostTypeResolver`
- [ ] `'submission'|'attempt'` — строковые литералы в 4 match трёх файлов (`WorkDetailService:51`, `OwnWorkDetailService:42`, `WorkResetService:40,52`) → enum `WorkSourceType` + стратегия

### 2.8 JS: общий слой утилит
- [ ] Создать `common/utils` (строки/даты) и свести дубли: `escapeHtml`/`esc` — **11 реализаций**, `toast` — 5 (в т.ч. две побайтово идентичные в `kege/`), `fmtDate` — 4 внутри `profile/` при живом `profile/utils.js`, `debounce` — 3
- [ ] AJAX из UI-слоя: `admin/modals/enrollment/select-parent-modal.js:190,268,335` и `admin/modals/draft-creator-modal.js:112` → вынести в managers/services
- [ ] `alert-modal.js` — двойная инициализация (явно из `admin.js:66` + автозагрузка `ui.js`) → добавить `_initialized`-guard; заодно guard'ы в `bundle-export-modal`, `confirm-modal`, `pii-export-modal`
- [ ] Мёртвые экспорты: `utils.js:181 toggleButtonExtended`, `:236 apiErrorEnhanced`, `ui-helpers.js:25 fsEmpty`, `input-masks.js:74 bindInnMask`, `icons.js` — `icoFlag`/`icoFile`/`icoArrowRight` (последние два при этом продублированы инлайном в `task-card.js`)

### 2.9 SCSS: недостающие слои токенов
- [ ] Завести размерную шкалу для `assessment/` (нет своего `_variables.scss`; `_attempt.scss` — 89 сырых px, дробные кегли 12.5/13.5/14.5px) и `kege/` (в `_variables` только цвета и `--kege-tfs`)
- [ ] `_redirect-box.scss` (27 `!important`) и `_person-field.scss` (15) → переписать через один родительский сброс по образцу `modal/_base.scss:111`; попутно `:173` — `$font-size-m!important` без пробела

---

## P3. Точечные правки (дёшево, можно между делом)

### PHP
- [ ] `TaskContentCallbacks.php:94,96,110` — `wp_update_post`/`wp_insert_post`/`update_post_meta` напрямую → `PostManager`
- [ ] `ConsentSettingsCallbacks.php:73` — `wp_insert_post` из коллбэка → `PageGeneratorService`/Manager
- [ ] `SubjectValidationCallbacks.php:109` — `current_user_can('manage_categories')` сырой строкой → `Capability` + Authorizer
- [ ] `SubjectPageCallbacks.php:124,132,139` — `echo` из коллбэка → шаблоны
- [ ] `WpOptionsEmailTemplate.php:37` → существующий `EmailTemplatesRepository`
- [ ] `ProfileController.php:84` → существующий `ExpulsionPolicyRepository`
- [ ] `Init.php:218,221` — `'fs_lms_caps_version'` → кейс в `OptionName`
- [ ] Сырые meta-ключи → `PostMetaName`: `'fs_lms_person_id'` (`EnrollmentCallbacks:623`, `LogNameResolver:85`), `'fs_lms_forked_from'`/`'fs_lms_forked_for_group'` (`PostCollector:46-47`), константы `ProblemDeduplicator:43,48`, legacy `'_fs_lms_template_type'` (`TemplateResolver:78`)
- [ ] `NumericSorter.php:35` — трейт сам регистрирует `add_filter` → перенести регистрацию в контроллер
- [ ] templates: `subject-1-stats.php:99,117` — два `new WP_Query()` в шаблоне; `application-enrollment-modal.php:26`, `settings-4-email-templates.php:9`, `settings-5-consents.php:7` — `get_option()` в шаблонах → данные передавать из контроллера
- [ ] `ApplicationRepository.php:265-285, 353-364` — валидация переходов статусов и правило trash-удаления → `ApplicationService`

### JS/SCSS/шаблоны
- [ ] `apply-fields.php:303`, `join.php:551` — `style="display:none"` → `hidden`
- [ ] Инлайновые SVG: `step-assessment.php:60,112,157,168`, `attempt-shell-header.php:43,54` → `Icon::` (файл `step-assessment` мигрирован наполовину); логотипы провайдеров в `help-modal.php` — вынести в партиал
- [ ] Хардкод-цвета: `kege/components/_base.scss:123,127`, `_exam.scss:24`, `admin/_mixins.scss:339,346` → токены
- [ ] `frontend/components/_reset.scss:2` — CSS `@import` шрифта Ubuntu → `wp_enqueue_style` + preconnect; заодно решить конфликт двух базовых шрифтов (Ubuntu vs Golos Text в `_tokens.scss:61`)
- [ ] `utils.js:538` — `.css('background', '#ff8d8d')` хардкод-цвет; `select-parent-modal.js:414-430`, `utils.js:359` — `style="..."` в шаблонных строках
- [ ] `.css('opacity')` как индикатор загрузки (`recent-posts.js:124,146,181`, `posts-table.js:239,268,326`) → класс
- [ ] Дубль HTML письма: `templates/emails/otp_code.php` ≡ heredoc в `settings-4-email-templates.php:20+` → один источник

---

## P4. Решения / политика (обсудить и зафиксировать в CLAUDE.md)

- [ ] **Scoped-фильтры**: временные `add_filter/remove_filter` в `SubjectDeletionService`, `MediaSideloader`, `MediaManager`, `TaskPublishGuard` — легализовать как исключение или вынести в `Shared/Traits/ScopedFilter`
- [ ] **`LogNameResolver` как статика** — добавить в белый список статических утилит (рефакторинг не окупится)
- [ ] **`style.display` в JS** (~14 мест) — либо явно разрешить, либо ввести утилиту `toggleVisible()`
- [ ] **Правило `_types.js`** — 15 админ-файлов используют глобалы без импорта типов: автоматизировать (ESLint) или признать декоративным
- [ ] **`add_action` в Managers** (`CPTManager:45`, `TaxonomyManager:40`, `MenuManager:88`, `MetaBoxManager:73`, `MediaManager:67`) — сложившийся паттерн «менеджер сам вешает свой хук»: легализовать или переносить в контроллеры
- [ ] **Инлайн-стили в email-шаблонах** — исключить `templates/emails/` из запрета (требование почтовых клиентов)
- [ ] **`require.context` в `ui.js:59`** — нерекурсивный: модалки в `modals/enrollment/**` не автозагружаются (сейчас все инициализируются вручную — работает, но контракт из CLAUDE.md верен только для верхнего уровня); фолбэк `ui.js:82` берёт первый экспорт файла — хрупко

---

## Мёртвый PHP-код

### Точно мёртвое — удалить
- [ ] `inc/Enums/Wp/AjaxHook.php:129` `WithdrawConsent` + `inc/Enums/Wp/Nonce.php:41` `WithdrawConsent` — фича «отзыв согласия» не реализована: нет регистрации, нет `ajaxWithdrawConsent`, нет JS-вызова
- [ ] `inc/Enums/Wp/AjaxHook.php:57` `SaveTemplateAssignment` — хук нигде не зарегистрирован; заодно убрать мёртвую запись из JSDoc `src/js/admin/_types.js:20` (сам `TaskTemplateAssignmentDTO` жив по другой ветке — не трогать)
- [ ] `inc/Controllers/System/CronController.php:56-59` — устаревший комментарий «будут подключены по мере реализации»: все три хука уже зарегистрированы в `RecoveryController.php:63-65` и запланированы в `Activate.php:56-58`

### Эндпоинты, недостижимые с фронта — проверить и удалить либо доделать UI
Сервер проверяет nonce, который клиент нигде не создаёт (`->create()` отсутствует) — вызов физически невозможен:
- [ ] `RepresentativeCallbacks.php:57,91` — `AddRepresentative`, `ReplaceRepresentative`
- [ ] `BatchSubmissionCallbacks.php:94` — `GradeBatch` (хук `GradeBatchTask`)

Хуки зарегистрированы, но ни одного JS-потребителя (вероятно, недоделанные или брошенные фичи — решить по каждому):
- [ ] `PiiController.php:83-91` — `RevealPiiField`, `RequestPiiDeletion`, `AddRepresentative`, `ReplaceRepresentative`
- [ ] `CourseController.php:41-45` — весь блок клонирования `CloneLesson`/`CloneWork`/`CloneAssessment`/`CloneCourse`/`ForkLessonForGroup` (сервисный слой `ContentCloneService` при этом жив и используется из `CourseBuilderService`)
- [ ] `SettingsController.php:91,99` — `LookupConsentByHash`, `DeleteAcademicPeriod`
- [ ] `StudentGroupController.php:56` — `DeleteStudentGroup` (JS использует `checkGroupDeletion`/`deleteGroup`)
- [ ] `ExpulsionController.php:71` — `ExportExpelledRecord`; `EnrollmentController.php:72` — `EmptyApplicationsTrash`
- [ ] `WorkController.php:29` — `GetWorkTaskCandidates`; `LessonController.php:30,36` — `GetLessonArticles`, `CreateArticleDraft`
- [ ] `AssessmentController.php:37,38` — `ParseScoreMap`, `CopyScoreMap`; `ScheduleController.php:29` — `SetLessonExtraWorks`
- [ ] `SubstitutionController.php:28` — `GetGroupSubstitutions`; `RoomController.php:26,29` — `GetRooms`, `AssignGroupRoom`

### Проверить вручную
- [ ] `inc/Services/Email/EmailOtpService.php:64-67` — закомментированный ранний return для `FS_LMS_TEST_ENV`; комментарий-описание противоречит коду — проверить, не полагаются ли тесты на обход отправки

### Чисто (проверено, мёртвого нет)
- Init::getServices() ↔ диск: все контроллеры/модули зарегистрированы или инжектятся (модули регистрируются вручную в `Init.php:181-186`)
- DTO (90+ классов), `inc/Shared/`, `inc/Contracts/`, все 114 шаблонов `templates/` — используются
- Трейты с единственным потребителем (кандидаты на инлайн, не на удаление): `ErrorHandler` (только SocialAuthController), `NumericSorter` (только SubjectController)

---

## Что трогать не нужно (проверено, вердикт «оставить»)

- `Migration_1_0_0::up()` (675 строк DDL) — снимок схемы, риск/выгода плохие
- `StudentRecordRepository` (28 методов) — узкие query-методы одной таблицы, SRP не нарушен
- `PostManager` — широкий фасад, не god-класс (кроме `buildListTable():154` — вынести)
- Log-export-провайдеры (`inc/Services/Export/Log/`) — здоровая Strategy
- Все 11 трейтов `inc/Shared/Traits/` — чистые, без state
- `match` на енумах в сервисах — идиоматичный PHP 8
- `error_log`, `get_header/get_footer`, `check_ajax_referer`/`wp_send_json` в Callbacks, хуки в Repositories/Registrars/Cli — нарушений нет
- SCSS: вложенность (0 нарушений), `@use`/`@forward` везде, инлайн-`<script>` в шаблонах отсутствует
