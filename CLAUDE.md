# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Build Commands

```bash
npx gulp build            # JS + all CSS once
npx gulp watch            # watch + rebuild
npx gulp scripts          # JS only (admin + frontend + common)
npx gulp styles:admin     # admin CSS only
npx gulp styles:frontend  # frontend CSS only
npx gulp styles:common    # common CSS only

npm run lint:js   # ESLint check
npm run fix:js    # ESLint auto-fix
```

JS entry points: `src/js/admin/admin.js`, `src/js/frontend/frontend.js`, `src/js/common/common.js` → `assets/js/*.min.js`
CSS entry points: `src/scss/admin/admin.scss`, `src/scss/frontend/frontend.scss`, `src/scss/common/common.scss` → `assets/css/*.min.css`

Webpack (via gulp-webpack-stream) bundles ES6 modules with Babel. `require.context` in `modules/ui.js` auto-loads all modals from `src/js/admin/modals/`.

---

## Architecture

**Entry:** `fs-lms.php` → `Inc\Init::run()`

**DI:** `Inc\Core\Container` — autowiring + lazy singleton. All class dependencies must be type-hinted in constructors. Cannot resolve built-in types without defaults.

**Adding a new service:** add its class to `Init::getServices()`. Must implement `ServiceInterface` (requires `register(): void`).

**Autoload:** PSR-4, `Inc\` → `./inc/` (via Composer)

### Layers

| Layer | Location | Role |
|---|---|---|
| Controllers | `inc/Controllers/` | Register WP hooks only, orchestrate other layers |
| Builders | `inc/Controllers/Builders/` | Build structured config arrays (menus, etc.) |
| Registrars | `inc/Registrars/` | Wrap WP registration APIs (menus, settings, CPT, taxonomies, metaboxes) |
| Managers | `inc/Managers/` | Wrap WP data APIs (CRUD for posts, terms, options, metaboxes) |
| Controllers | `inc/Controllers/` | Domain controllers in root; `Subscribers/` — 9 log-channel subscribers; `Pages/` — 5 public page controllers |
| Callbacks | `inc/Callbacks/` | AJAX handlers only; subdirs: `Subject/`, `Person/`, `Settings/`, `Enrollment/`, `Task/` |
| Repositories | `inc/Repositories/` | Read/write `wp_options` as structured arrays; `WPDBRepositories/Log/` — 9 log repositories, 8 из них наследуют `AbstractLogRepository` (общие `list()`/`countFiltered()`/`listAll()`; наследник задаёт `channel()`, `filterMap()`, `hydrate()`) |
| MetaBoxes | `inc/MetaBoxes/` | Field and template definitions for metaboxes |
| DTO | `inc/DTO/` | Data transfer between layers; subdirs: `Application/`, `Person/`, `Enrollment/`, `Task/`, `Subject/`, `Log/`, `Export/`, `Email/`, `Settings/` |
| Enums | `inc/Enums/` | Typed constants (slugs, capabilities, option names, AJAX hooks) |
| Services | `inc/Services/` | Stateless services; subdirs mirror domain groups; `Security/` — PiiCrypto, PasswordGenerator, RateLimit; `Shared/` — WpClock; `Captcha/` — CaptchaService |
| Shared | `inc/Shared/` | Traits (`inc/Shared/Traits/`) + static utility `PluginLogger` |
| Cli | `inc/Cli/` | WP-CLI команды; реализуют `ServiceInterface` и сами выходят из `register()`, если `WP_CLI` не определён |

**Транзиенты** — только через `Managers/Wp/TransientManager` (`get`/`set`/`delete`/`take`); ключ — кейс `Enums/Wp/TransientKey`, сырых строк в вызывающем коде быть не должно. Исключение: сервисы с собственным инкапсулированным префиксом (`RateLimitService`, `EmailOtpService`, `TaskPublishGuard`) — там ключ уже локализован в одном классе.

**Модули** (`inc/Modules/`) — конфигурация наследует `Modules/Shared/ModuleConfig` (опция + дефолты + тумблер с приоритетом константы `wp-config.php`). Ключи модульных опций живут В МОДУЛЕ и НЕ попадают в core-`OptionName`: ядро не должно знать о модулях. По той же причине модуль публикует свои реализации ядру фильтрами (напр. `fs_lms_captcha_provider`), а не биндингом в core-контейнере.

**Callbacks subdirectories:**
- `inc/Callbacks/Subject/` — SubjectCrudCallbacks, SubjectDataCallbacks, SubjectImportExportCallbacks, SubjectBundleCallbacks, SubjectPageCallbacks, SubjectValidationCallbacks, TaxonomySettingsCallbacks
- `inc/Callbacks/Person/` — PersonViewCallbacks, PersonUpdateCallbacks, PiiRevealCallbacks, RepresentativeCallbacks
- `inc/Callbacks/Settings/` — AcademicPeriodCallbacks, ConsentSettingsCallbacks, EmailTemplateSettingsCallbacks
- `inc/Callbacks/Enrollment/` — ApplicationCallbacks, EnrollmentCallbacks, ExpulsionCallbacks, RecoveryCallbacks, DeletionCallbacks
- `inc/Callbacks/Task/` — TaskCreationCallbacks, BoilerplateCallbacks, TemplateCallbacks, TemplateManagerCallbacks, AllTasksCallbacks
- `inc/Callbacks/Course/` — КТП разложена по ответственностям: `ProgramCallbacks` (состав программы, публикация, настройки шагов), `LessonScheduleCallbacks` (даты, pin, reflow, календарь), `IndividualLessonCallbacks` (D3), `GroupRosterCallbacks`, `LessonDeliveryCallbacks` (работы, дедлайны, запись). Сервисный слой зеркальный: `ProgramCompositionService` / `ScheduleReflowService` / `IndividualLessonService` / `GroupCalendarService` + `ScheduleEventPublisher`

**BaseController** (`Inc\Core\BaseController`): infrastructure utility only — not a domain or architectural base class. Provides `$plugin_path`, `$plugin_url`, `$plugin_name`, and helpers `path()`, `url()`. Also declares the `AjaxResponse` trait (inherited by all subclasses). Extend this purely to gain access to plugin path helpers and AJAX transport — not to express any domain relationship. Controllers and Callbacks extending it are unrelated to each other beyond sharing these utilities.

### Contracts

`inc/Contracts/` defines interfaces all implementations must satisfy:
- `ServiceInterface` — `register(): void`; required by DI container bootstrap
- `FieldInterface` — implemented by MetaBox field classes
- `AuthStrategyInterface` — implemented by each OAuth provider strategy

### Data Model

Subjects are stored in `wp_options` (key: `fs_lms_subjects_list`) as `['subject_key' => ['key' => ..., 'name' => ...]]`. Each subject dynamically registers two CPTs (`{key}_tasks`, `{key}_articles`) and a fixed taxonomy `{key}_task_number` (numeric sort applied automatically). User-defined taxonomies are also stored in `wp_options` via `TaxonomyRepository`. Boilerplates and template assignments are similarly stored in `wp_options` — never in post/term meta.

**`OptionName` enum** (`inc/Enums/OptionName.php`) centralises all `wp_options` keys:
- `SUBJECTS` → `fs_lms_subjects_list`
- `METABOXES` → `fs_lms_custom_metaboxes`
- `TAXONOMY` → `fs_lms_custom_taxonomies`
- `BOILERPLATE` → `fs_lms_task_type_boilerplates`
- `AUTH_SETTINGS` → `fs_lms_auth_settings`

**Other key enums:**
- `Capability` — `Admin` (`manage_options`), `ViewLMSStats`, `ManageLMSAssignments`, `ManageApplications`, `EnrollStudent`, `ViewPII`, `ExportPII`, `ManagePersons`
  - **Стандарт прав на выгрузку ПД:** любой экспорт персональных данных
    (`students`, `parents`, `archive`, точечная выгрузка записи об отчислении)
    требует **обеих** проверок — `ManageLmsPlatform` (доступ к разделу) и
    `ExportPII` (право выгружать ПД), через `authorizeAll()`. Одного
    `ManageLmsPlatform` недостаточно.
- `PostMetaName` — `TemplateType` (`fs_lms_template_type`), `Meta` (`fs_lms_meta`) — use these instead of raw strings when reading/writing post meta
- `UserRole` — internal roles (`FSTeacher`, `FSStudent`, `FSParent`) and external/free roles (`Student`, `Teacher`); each has a `->label()` method
- `EmailTemplateType` — `OtpCode`, `PasswordSetup`, `ApplicationConfirmation`, `ApplicationReady`, `Rejection`, `NewRepresentative`, `WelcomeWithCredentials`; use instead of raw strings when calling `EmailService` or `EmailTemplateInterface::get()`

---

## Nonce Pattern

`Inc\Enums\Nonce` is a backed enum with:
- `create(): string` — generates nonce
- `verify(string $queryArg = 'security'): void` — validates request

Available nonces: `TaskCreation`, `Subject`, `SubjectBundle`, `Manager`, `SaveMeta`, `SaveBoilerplate`, `Apply`, `ParentSubmit`, `Enroll`, `RevealPii`, `AddRepresentative`, `ReplaceRepresentative`, `UpdatePerson`, `WithdrawConsent`, `RequestPiiDeletion`, `VerifyOtp`, `TrashApplication`, `EditApplication`, `ReviewApplication`.

**Usage in admin AJAX callbacks (with capability check):** always `$this->authorize(Nonce::X, Capability::Y)` — never call `check_ajax_referer()` or `current_user_can()` directly.

**Usage in public/nopriv AJAX callbacks (no capability check):** `Nonce::X->verify()` directly, since `authorize()` requires a capability.

## Shared Traits

**`Authorizer`** — `$this->authorize(Nonce::X, Capability::Y)` checks nonce + capability in one call and sends a JSON 403 on failure. Declare `use Authorizer;` + `use Inc\Shared\Traits\Authorizer;` in every Callback class that handles admin AJAX. Never call `check_ajax_referer()` or `current_user_can()` directly in Callback methods.
`$this->authorizeAll(Nonce::X, [Capability::A, Capability::B])` — та же проверка, но требует **все** права. Использовать там, где одно право открывает раздел, а второе — конкретную чувствительную операцию внутри него.

**`Sanitizer`** — use these instead of raw WP functions:
- `sanitizeText()`, `sanitizeKey()`, `sanitizeInt()`, `sanitizeHtml()`, `sanitizeEditorContent()`, `sanitizeBool()`
- `sanitizeIntList()`, `sanitizeKeyList()`/`sanitizeKeyArray()` — массивы из запроса (списки ID/слагов); не собирать их вручную из `$_POST`
- `unslashArray()` — структуры произвольной формы (шаги урока, модули курса, мета задания): снимает слэши, санитайзинг значений — на доменном коде
- `sanitizeTextValue()`/`sanitizeKeyValue()`/`sanitizeIntValue()` — для значений внутри уже полученных массивов
- `requireText()`, `requireInt()`, `requireKey()` — same as above but throw on empty/missing input

**`AjaxResponse`** — `$this->success($data)` / `$this->error($message)` wrap `wp_send_json_*` and log in `WP_DEBUG` mode.
**Required in all Callback classes. Inherited via `BaseController` — do not re-declare unless the class does not extend `BaseController`.**

**`ErrorHandler`** — `$this->sendError(code, message, status)` auto-detects context (`wp_doing_ajax()`) and responds with either `wp_send_json_error()` or `wp_die()`.
**Allowed only in Controllers that handle both AJAX and standard HTTP flows (currently: `AuthController` only). Do NOT use in Callback classes — they are AJAX-only; `AjaxResponse` is sufficient.**

**`PluginLogger`** (`Inc\Shared\PluginLogger`) — static utility class for all plugin logging. Use instead of `error_log()`:
- `PluginLogger::debug('Context', 'message', $data)` — only when `WP_DEBUG = true`
- `PluginLogger::warning('Context', 'message', $data)` — always logs (operational warnings)
- `PluginLogger::exception('Context', $e, $extra, $always)` — convenience wrapper; `$always=true` for cron/production errors

Log format: `[FS LMS] CONTEXT: message | Context: {timestamp, user_id, ip, ...data}` — grep-able with `[FS LMS]`. Never call `error_log()` directly.

**`ProgramAccess`** — гарды AJAX КТП: `requireGroupAccess()`, `requireProgramRow()`, `denyIfProgramLocked()`. Класс-потребитель отдаёт зависимости через `accessGuard()` и `programService()`. Новый мутатор программы обязан звать `denyIfProgramLocked()` (публикация КТП блокирует правки структуры и расписания, T1.8).

**`ScopedFilter`** — `$this->withFilter( $hook, $callback, $operation, $priority )`: временный WP-фильтр ровно на одну операцию (снос предмета обходит референс-гард, загрузка вложений расширяет MIME). Снимается даже при исключении. Это НЕ нарушение правила «хуки — в контроллерах»: там речь о постоянных хуках жизненного цикла.

**`TemplateRenderer`** — `$this->render('template-name', $dataOrDTO)` loads from `templates/`, extracts variables or accepts a DTO.

---

## Принятые исключения (решено 2026-07-31, аудит P4)

- **`add_action` внутри Managers** (`CPTManager`, `TaxonomyManager`, `MenuManager`, `MetaBoxManager`, `MediaManager`) — легально: менеджер регистрирует хук ТОГО API, которое сам оборачивает, а не доменную логику. Правило «хуки только в контроллерах» относится к доменным хукам.
- **Статические утилиты** без состояния и зависимостей: `PostTypeResolver`, `ContentKindResolver`, `LogNameResolver`, `Icon`. DI для них не окупается — но добавлять новые можно только в этот список.
- **Инлайновые стили в `templates/emails/`** — обязательны: почтовые клиенты вырезают `<style>`. Запрет на inline-стили действует только для UI-шаблонов.
- **`_types.js`** — JSDoc-типизация глобалов; импорт в admin-файлах **необязателен** (нужен лишь для подсказок IDE, на сборку не влияет). ESLint-правило ради этого не заводим.
- **`require.context` в `ui.js` нерекурсивен** — модалки из подпапок (`modals/enrollment/`) инициализируются явно в `admin.js`: у них есть порядок относительно своих сервисов. Фолбэк автозагрузчика берёт первый экспорт **с методом `init()`**, а не просто первый.

## Strict Rules

- Controllers must NOT contain business logic or direct WP API calls
- Do NOT use `WP_Query`, `get_posts`, `update_option`, `update_post_meta` directly
- All data access → through Repositories/Managers
- Use DI via Container only
- Follow existing architecture, do not invent new layers

---

## AJAX Hook Pattern

AJAX actions are defined in `Inc\Enums\AjaxHook` as PascalCase backed enum cases:

```php
case SaveBoilerplate = 'SaveBoilerplate';
```

This auto-generates:
- WP hook: `wp_ajax_save_boilerplate` (via `->action()`)
- JS action: `save_boilerplate` (via `->jsAction()`)
- PHP callback method: `ajaxSaveBoilerplate` (via `->callbackMethod()`)

`AjaxHook::toJsArray()` exports all hooks as `['camelCaseName' => 'snake_case_action']` — used in `Enqueue::enqueue_admin_assets()` to populate `fs_lms_vars.ajax_actions`.

To add a new AJAX action: add a case to `AjaxHook`, register it in the relevant Controller using `->action()`, implement `ajax{CaseName}()` in the Callback class.

---

## Key Services

### PostTypeResolver (`inc/Services/Subject/PostTypeResolver.php`)

Static helpers — use instead of string concatenation:
- `PostTypeResolver::tasks($key)` → `"{$key}_tasks"`
- `PostTypeResolver::articles($key)` → `"{$key}_articles"`
- `PostTypeResolver::isTaskPostType($post_type)` → bool
- `PostTypeResolver::subjectFromTaskPostType($post_type)` → subject key

### ContentCacheService (`inc/Services/Subject/ContentCacheService.php`)

Transient-based cache for recent tasks/articles. Hooks `save_post` and `delete_post` via `SubjectController` to auto-invalidate.

### TemplateService (`inc/Services/Template/`)

- `TemplateRegistry` — registers available metabox templates
- `TemplateResolver` — resolves the correct template for a given post/term

### EmailService (`inc/Services/Email/`)

- `EmailService` — sends all plugin emails via `wp_mail()`; accepts `EmailTemplateType` enum
- `EmailOtpService` — generates, stores, and verifies OTP codes for email confirmation
- `PhpEmailTemplate` / `WpOptionsEmailTemplate` — template strategies (PHP file fallback → DB overrides)

### Import (`inc/Services/Import/`)

CSV-импорт учеников, два режима (`Inc\Enums\Import\ImportMode`): `archive` — записи прошлых лет без WP-учёток; `enrolled` — полное зачисление с созданием учёток ученика (логин/пароль — обязательные колонки CSV) и родителя (логин = email, пароль генерируется) через `AccountProvisioningService`. Импортёры строк реализуют `RowImporterInterface`, оркестратор — `ImportService::run()`; выбор импортёра по `mode` — в `ImportCallbacks`.

### Перенос предмета между сайтами (`inc/Services/Subject/Bundle/`)

Полный пакет переноса — **ZIP**: `manifest.json` + `media/{attachment_id}__{file}`
(`BundleSchema`; версия формата `schema_version`, совместимость по major).

- **Ссылки внутри пакета — `_export_id`, не WP ID.** Ключ вида `tasks:123`
  (`ExportIdMapper`), подмена ссылок — `RefRemapper` (обход меты по имени ключа:
  `item_ids`, `task_ids`, `lesson_ids`, `ref`, `attachment_id(s)`). Нерезолвимая
  ссылка **выбрасывается**, а не переносится как чужой ID.
- **Порядок импорта = порядок кейсов `Inc\Enums\Subject\BundleSection`** — это
  топологическая сортировка графа (`tasks/articles → problems → works →
  assessments → lessons → courses`). Отдельного резолвера зависимостей нет и не нужно.
- **Общая инфраструктура**: `PostCollector` (снять запись) / `PostRestorer`
  (создать запись) — одна операция для всех семи разделов; их же использует
  «лёгкий» `SubjectExportService`. `post_name` переносится как есть: у задания
  слаг — это его номер (`PostManager::getPostsByTerm()`), а не производная
  заголовка.
- **Медиа ищется двумя способами.** По ключам меты (`attachment_id(s)` —
  материалы задания, аудио) и **по содержимому строк**: картинка условия
  (`ConditionField`) лежит в HTML как `<img class="wp-image-{id}" src="…">`, а
  `LinkField` (`file`, `file_primary`, `file_secondary`) хранит голый URL. Строки
  сканирует `MediaCollector` (класс `wp-image-` + URL внутри своего `uploads`, со
  снятием суффикса размера), манифест несёт `source_id`/`source_urls`, а импорт
  переписывает подстроки через `MediaUrlRewriter`. Ссылку, для которой в пакете
  нет файла, импорт **оставляет как есть** (ломать HTML условия хуже), а экспорт
  сообщает о ней в `warnings`.
- **Глобальный банк `fs_lms_problems`** подтягивается автоматически (только
  задачи, на которые реально ссылаются works/assessments) и дедуплицируется при
  импорте (`ProblemDeduplicator`: метка происхождения + отпечаток контента).
- **Откат.** `wp_insert_post`/`wp_insert_term`/`wp_options` не откатываются
  транзакцией, поэтому всё созданное пишется в `ImportedEntitiesDTO`, а при
  ошибке `ImportRollbackService` удаляет ровно это. **Переиспользованные**
  сущности (существующий термин, найденная задача банка, привязанный
  пользователь) в журнал не пишутся — иначе откат сотрёт чужие данные.
- **Прогресс не переносится** (зафиксированное решение): ни посещаемость, ни
  сдачи, ни попытки, ни оценки. Раздел `students` (опциональный) переносит
  учётки, группы и факт зачисления — через тот же `StudentRecordWriter` /
  `AccountProvisioningService`, что и CSV-импорт.
- **Пароли переносятся**: на источнике они лежат в мете `fs_lms_enc_password`
  (шифр + base64), при сборке пакета расшифровываются и на целевом сайте
  ставятся как есть — семья входит по прежним логину и паролю. Следствие:
  архив с разделом `students` содержит ПД и пароли **открытым текстом**, о чём
  обязана предупреждать модалка экспорта. Новый пароль генерируется только
  когда в пакете его нет.
- **Безопасность распаковки**: `BundleArchive` отвергает архив с path traversal,
  посторонними файлами и превышением распакованного объёма; `sha256` каждого
  медиафайла проверяется **до** первой записи в БД. MIME-белый список
  `MediaSideloader` — зеркало `MediaManager`, расширять его ради импорта нельзя.
- **Большие предметы** — WP-CLI (`inc/Cli/SubjectBundleCommand.php`):
  `wp fs-lms subject export <key> --out=file.zip`, `wp fs-lms subject import file.zip [--dry-run]`.

### Auth (`inc/Modules/SocialAuth/Services/`)

OAuth via Hybridauth, extracted into the disable-able `SocialAuth` module (`inc/Modules/SocialAuth/`). `AuthService` orchestrates the full flow: find user by social ID → find by email (account linking) → register new → WP login. Provider strategies in `Services/AuthStrategies/` (Google, VK, GitHub) implement `AuthStrategyInterface`. Auth settings (client IDs, secrets) stored in `OptionName::AUTH_SETTINGS`. Social user meta keys follow the pattern `fs_social_{provider}_id`.

**Filter hook for CPT args:** `apply_filters('fs_lms_cpt_args', $args, $type, $subject)` — fired in `SubjectController` before registering each CPT; allows external modification of labels and options.

---

## JS Architecture

### Directory layout

```
src/js/
├── admin/
│   ├── admin.js          — entry point; jQuery $(document).ready()
│   ├── _types.js         — JSDoc @typedef for window globals (fs_lms_vars, fs_lms_task_data)
│   ├── modals/           — UI only; NO AJAX (modal dialogs, UI widgets); auto-loaded by modules/ui.js
│   ├── managers/         — data managers (form state, field helpers)
│   ├── services/         — AJAX + business logic; orchestrates modals/managers
│   └── modules/          — shared utilities (modal-base, utils, ui registry)
├── frontend/
│   ├── frontend.js       — entry point; pure DOMContentLoaded
│   ├── components/       — UI only; NO AJAX (tabs, carousels)
│   └── services/         — AJAX + business logic (apply-form)
└── common/
    ├── common.js         — entry point
    └── components/       — shared UI components used on both sides
```

**Общие утилиты** — `src/js/common/utils.js`: `escapeHtml`, `fmtDate`/`fmtDayMonth`/`fmtDateTime`, `todayIso`, `initials`, `debounce`. Доменные бандлы реэкспортируют их под своими именами (`profile/utils.js` отдаёт `esc` ≡ `escapeHtml`) — своих копий не заводить.

**Модалки не ходят в сеть** — AJAX живёт в `admin/managers/*` (напр. `enrollment-api.js`, `draft-api.js`) и возвращает промисы; модалка только рисует. `init()` модалок идемпотентен (`_initialized`): их поднимает и автозагрузчик `ui.js`, и `admin.js`.

### Export conventions

**Admin** (`admin/modals/`, `admin/managers/`, `admin/services/`) — jQuery-based, object pattern:
```js
export const MyService = {
    init() { ... },
    bindEvents() { ... },
};
// admin.js: MyService.init();
```

**Frontend** (`frontend/components/`, `frontend/services/`) — pure JS, function pattern:
```js
export function initMyFeature() {
    if ( ! document.getElementById( 'my-element' ) ) { return; }
    // ...
}
// frontend.js: initMyFeature();
```

**Modules** (`admin/modules/`) — named function exports:
```js
export function openModal( $modal ) { ... }
export function closeModal( $modal ) { ... }
```

**Never mix patterns** within a bundle: admin files use jQuery object pattern, frontend files use pure-JS function pattern.

### Entry points

`admin.js` wraps everything in `(function ($) { $(document).ready(...) })(jQuery)`.  
`frontend.js` uses `document.addEventListener('DOMContentLoaded', ...)`.

### Initialization guards

- Admin: check selector presence before calling `.init()` — `if ($('.selector').length) { MyService.init(); }`
- Frontend: guard inside `initX()` — `if ( ! document.getElementById('el') ) { return; }`

### Auto-loader

`modules/ui.js` uses `require.context` to auto-load all files from `admin/modals/` — no manual import needed for modals (the auto-loader calls their `.init()`). Services and managers are imported and initialized manually in `admin.js`.

### Globals (window)

All `wp_localize_script` calls live in `Enqueue.php` only — never in templates.

| Variable | Scope | Contents |
|---|---|---|
| `fs_lms_vars` | all admin pages | `ajaxurl`, `ajax_actions`, nonces |
| `fs_lms_task_data` | task CPT pages only | `ajax_url`, `nonce`, `subject_key`, `post_type` |
| `fs_lms_apply_vars` | frontend `/lms/apply` | `ajax_url`, `actions`, `nonces`, `captcha_key` |
| `fs_lms_applications_vars` | admin `fs_lms_userlist` | `nonces.trash` |

`fs_lms_vars` and `fs_lms_task_data` are typed in `src/js/admin/_types.js`. Import `_types.js` in any admin file that uses these globals.

`AjaxHook::toJsArray()` exports all hooks as `['camelCaseName' => 'snake_case_action']` — accessed as `fs_lms_vars.ajax_actions.myActionName`.

---

## MetaBox Fields & Templates

- `inc/MetaBoxes/Fields/` — individual field types (extend `BaseField`, implement `FieldInterface`)
- `inc/MetaBoxes/Templates/` — task metabox templates (extend `BaseTemplate`)

**Источник истины полей задания = PHP `Fields/*`.** И нативный метабокс, и inline-модалка (`task-editor.js`) рендерят одну и ту же разметку: метабокс — напрямую через `BaseTemplate::render()`, модалка — забирая тот же HTML по AJAX (`AjaxHook::GetTaskEditorForm`) и навешивая поведение из `task-fields.js` (`TaskFields.init(root)`). Сохранение в обоих случаях — `fs_lms_meta[...]` → `MetaBoxManager::saveFields()`. **Не строить поля задания в JS** — добавлять/менять поля только в PHP `Fields/*` (опц. `editorType()`/`editorConfig()` для схемы `<select>`).

---

## Code Style

- `declare(strict_types=1)` at top of every file
- Typed params and return types required
- OOP only

---

## Frontend

- JS uses ES6 modules; Webpack bundles via Gulp
- Do not write inline JS or CSS
- Modify only source files in `src/js/` or `src/scss/`
- Build step runs separately
- Frontend task page template injected via `template_include` filter in `TaskPageCallbacks`

### ThemeCompatService — обязательно для всех публичных шаблонов

**Никогда не вызывать `get_header()` / `get_footer()` напрямую** в шаблонах плагина. Использовать только:

```php
use Inc\Services\ThemeCompatService;

ThemeCompatService::header(); // вместо get_header()
ThemeCompatService::footer(); // вместо get_footer()
```

Причина: блочные (FSE) темы не имеют `header.php` / `footer.php`, прямые вызовы выдают Deprecated. `ThemeCompatService` автоматически выбирает нужный API в зависимости от типа темы.

### Клиентская валидация форм

Система валидации: `src/js/common/validators/` + `src/js/common/validation-manager.js`.

**Добавить валидатор к полю:**
1. Добавить `data-validate="ключ"` к `<input>`
2. Обернуть поле в `<div class="fs-form-group">`

**Создать новый валидатор (3 шага):**
1. Создать `src/js/common/validators/MyValidator.js` — наследовать `BaseValidator`, переопределить `checkCustom(value, input)` — возвращать строку ошибки или `null`
2. Зарегистрировать в `validators/index.js`: `{ myKey: new MyValidator() }`
3. Добавить `data-validate="myKey"` к инпуту — больше ничего

**Автоматическая привязка:** формы с `data-fs-validate` или `.fs-lms-form` подхватываются `common.js` автоматически.

**Ручная привязка** (AJAX-формы со своим submit-обработчиком):
```js
import { initFormValidation } from '../../common/validation-manager.js';
const validateAll = initFormValidation( form ); // blur + input события
form.addEventListener( 'submit', async ( e ) => {
    e.preventDefault();
    if ( ! validateAll() ) { return; }
    // ... AJAX
} );
```

**Стили ошибок:** `src/scss/common/components/_validation.scss` — единственное место. Переменная `$color-danger` из admin-переменных. Не дублировать в компонентных SCSS.

### wp_localize_script — только в Enqueue.php

Все `wp_localize_script()` вызовы должны быть в `inc/Core/Enqueue.php`, не в шаблонах.

## CSS / SCSS Rules

- **No inline styles** — never use `style=""` attributes in PHP templates or JS DOM manipulation
- **Variables required** — all SCSS component files must use tokens from `src/scss/admin/_variables.scss` (or frontend equivalent); no hardcoded colors, spacing, font sizes, or transition values
- **No raw values in components** — if a needed token doesn't exist in `_variables.scss`, add it there first, then use it
- **Одна лестница токенов на весь проект** — имя ступени значит ОДИН размер/вес во всех бандлах: кегли `$font-size-2xs 10 / -code 11 / -xs 12 / -sm 13 / -base 14 / -md 16 / -lg 22 / -xl 24 / -2xl 28`, отступы `$spacing-xs 4 / -sm 8 / -md 12 / -lg 16 / -xl 20 / -2xl 24 / -3xl 28 / -4xl 32`, радиусы `$border-radius-sm 4 / -md 6 / -lg 8 / -xl 12 / -2xl 16 / -pill 999px`, веса `$font-regular 450 / $font-semibold 600 / $font-bold 700`. Ядро — `shared/_tokens.scss`; `frontend/_variables.scss` общие ступени **форвардит**, а не переопределяет, и добавляет только свои (крупные кегли/отступы, `$border-radius-2xl`, `$border-radius-pill`). Новая ступень → сначала в ядро, потом использование
- **Одноимённое ≠ разное** — если значение расходится по доменам, имя обязано различаться: `$font-code` (моноширинный публичных страниц) vs `$font-mono` (кабинет/плеер), `$shadow-surface` vs `$shadow-card`, `$line-height-relaxed` 1.6 vs `$line-height-base` 1.5
- **JS не задаёт стили** — состояния переключаются классами (`.is-loading`, `.is-deleting`, `.fs-parent-action`), показ/скрытие — атрибутом `hidden`, а не `style.display`
- **Один физический цвет — одно объявление** — сырые оттенки (`$hue-violet`, `$hue-violet-dk`, `$hue-red`, `$hue-red-soft`, `$hue-amber-dk`) объявлены в `shared/_tokens.scss`; палитры (типы шагов, чипы, cabinet-тема, подсветка кода) ссылаются на них, а не копируют hex
- **stylelint обязателен**: `npm run lint:css` (авто-фикс — `npm run fix:css`); конфиг — `.stylelintrc.json`. Входит в `npm run ci`
- **Цвета в компонентах** — только `var(--…)` / `$token` (правило `scale-unlimited/declaration-strict-value` — **error**). Hex разрешён только в `_variables.scss`, `shared/_tokens.scss`, `shared/cabinet/_theme.scss`, `shared/_chip-palette.scss`; полупрозрачные `rgba()`-оверлеи/тени в компонентах допустимы
- **profile + player** — общая тема `shared/cabinet/_theme.scss` (один `:root`, словарь статусов `--ok/--err/--wait`) и примитивы `shared/cabinet/_ui.scss` (`.prof-btn` ≡ `.b`, тост, карточка). Цвета типов шагов — `$step-type-palette` в `shared/_tokens.scss` (JS-зеркало: `src/js/player/icons.js` TYPES)
- **`!important`** — только в utility-классах (`common/_widths.scss`) и для перебивания WP-core, всегда с комментарием-причиной
- **Вложенность** ≤ 4 уровней; deprecated `@import` запрещён (только `@use`/`@forward`)
- План рефактора стилей (rem, адаптив, слияние profile+player) — `refactor.md`

---

## SVG-иконки

Два единственных источника иконок — по одному на язык. **Инлайновые `<svg>` в JS-шаблонных строках и PHP-шаблонах запрещены.**

- **JS: `src/js/common/icons.js`** — именованные функции-фабрики (`icoCheck( 16 )`, `icoChevronRight( 18, 'var(--muted-2)' )`, `icoCaret( 12, 'kp-caret' )`), возвращают строку `<svg>…</svg>`. Импортируются любым бандлом (admin/frontend/profile/player); Webpack вкопирует только используемое (named exports → tree-shaking). Нет нужной иконки — добавить фабрику туда, не рисовать по месту.
- **PHP: enum `Inc\Enums\Ui\Icon`** — `Icon::Check->svg( 16 )` (без аргумента — размер по умолчанию кейса). В шаблонах: `<?php echo Icon::X->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>`.
- Глифы типов шагов урока — `STEP_GLYPHS` / `stepIcon( ui )` в `common/icons.js`; конструктор курса (`step-editor.js`) и плеер (`player/icons.js` → `typeIco`) используют один набор. `player/icons.js` — только мета типов (TYPES/typeMeta) и совместимый фасад `ICO` поверх common.
- Цвет всегда `currentColor` (наследуется от родителя); размер — аргумент фабрики. Общие глифы (check/chevron/lock/…) в JS- и PHP-источниках должны визуально совпадать.

---

## WordPress Rules

### Naming

- Option keys: `fs_lms_{entity}_{type}`
- CPT: `{subject}_tasks`, `{subject}_articles`
- Taxonomies: `{subject}_{taxonomy}`, fixed: `{subject}_task_number`
- AJAX actions: `fs_lms_{action}`

Use snake_case for all WP-related identifiers.

### Hooks

- Register hooks only inside Controllers
- Use `add_action` / `add_filter` only in Controllers
- Delegate all logic to Callbacks/Managers

### AJAX

- All AJAX logic in Callbacks classes
- Controllers only register `wp_ajax_{action}` / `wp_ajax_nopriv_{action}`
- Admin AJAX: validate with `$this->authorize(Nonce::X, Capability::Y)` — never `check_ajax_referer()` or `current_user_can()` directly
- Public/nopriv AJAX (no capability): validate with `Nonce::X->verify()`
- Sanitize input via `Sanitizer` trait methods only
- Return via `$this->success()` / `$this->error()` from `AjaxResponse` — never `wp_send_json_*` directly
- No direct `echo` / `die`

### Data Handling

- Read/write only via Repositories/Managers
- Always treat `wp_options` data as structured arrays
- Do not overwrite full option if only one key changes

### Security

- Sanitize input via `Sanitizer` trait methods — not raw WP functions
- Escape output when rendering (`esc_html`, `esc_attr`)
- Validate nonces via `Authorizer` trait or `Nonce::*->verify()` in every AJAX request

---

## Docker Environment

> **Claude может выполнять docker-команды напрямую** (перезапуск контейнера, запросы к БД, прогон миграций, проверка рантайма). Сервисы: `wp_app` (WordPress:8080), `wp_db` (MariaDB), `wp_phpmyadmin` (phpMyAdmin:8081).

The plugin runs inside Docker. The plugin directory is mounted as a volume — PHP file changes apply immediately, but OPcache may hold stale bytecode.

```bash
# After PHP changes, if behavior seems unchanged:
docker restart wp_app

# Query the database directly:
docker exec wp_db mariadb -u root -proot wordpress -e "SELECT ..."

# WP-CLI — отдельный сервис wpcli (профиль cli, со стеком не поднимается, --allow-root не нужен).
# Из каталога со стеком (где docker-compose.yml); из другого места добавь -f <путь>/docker-compose.yml:
docker compose run --rm wpcli wp <command>   # напр.: wp plugin list | wp option get home | wp post list --post_type=page

# Services: wp_app (WordPress:8080), wp_db (MariaDB), wp_phpmyadmin (phpMyAdmin:8081)
```

Data is stored in `wp_options` — never in term meta or post meta directly.

### Миграции в dev-окружении

**DDL-миграции (`Migration_1_0_0`) запускаются только при (ре)активации плагина.**
`MigrationRunner::run()` вызывается единственный раз — из `register_activation_hook`
(`Activate::activate()`), НЕ на обычной загрузке. Простая перезагрузка страницы миграции
не перезапускает.

**Удаление колонки** — не создавать новый файл миграции. Вместо этого:
1. Удалить колонку из DDL в `Migration_1_0_0::up()`
2. Добавить строку в секцию "Cleanup" того же файла: `$wpdb->query( "ALTER TABLE \`$table\` DROP COLUMN IF EXISTS \`col\`" );`
3. Сбросить версию схемы: `docker exec wp_db mariadb -u root -proot wordpress -e "UPDATE wp_options SET option_value='0.0.0' WHERE option_name='fs_lms_schema_version';"`
4. Реактивировать плагин, чтобы миграции перезапустились: `docker compose -f /Users/daniil/FS-LMS/docker-compose.yml run --rm wpcli wp plugin deactivate fs-lms && ... wp plugin activate fs-lms` (или тумблер в админке). ⚠️ Сброс версии + реактивация прогонит `up()` целиком, включая `CREATE TABLE`/DROP — на dev это безопасно, на данных с людьми — нет.

**Новые таблицы** — добавлять в `Migration_1_0_0::up()` и `down()`, не создавать отдельный файл.

**Data-миграция, которая обязана доехать до живых установок** (правка уже сохранённых
`wp_options`/`postmeta`, а не схемы) — НЕ класть в `Migration_1_0_0::up()`: на инсталляциях с
уже проставленным `fs_lms_schema_version` он не запустится. Делать самодостаточный класс,
version-gated собственной опцией (паттерн `VideoSchema`/`AdSchema`), и звать его на обычной
загрузке из `Init::run()`. Образец — `BroadcastStepMigration` (recording_slot → broadcast):
прямой `$wpdb` по `postmeta` (не зависит от регистрации CPT), дешёвый option-read при уже
выполненной миграции.

---

## Logs

- Debug logs: `..debug.log`
- Read last 15 lines only; do not process full log files; ask user before read

---

## Scope

- Use only built-in PHP and WordPress APIs
- Do not introduce third-party libraries unless explicitly requested

---

## Output Rules

- Output code only when code is requested
- No explanations, reasoning, or summaries
- No phrases like "Проблема", "Решение", "Причина"
- Respond with minimal required output only