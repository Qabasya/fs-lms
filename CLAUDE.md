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

```

Линтеры и CI — скрипты `package.json` (`npm run ci` гоняет всё сразу).
Сборка: webpack (gulp-webpack-stream) + Babel; точки входа и цели описаны в `gulpfile.js`.

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
| Controllers | `inc/Controllers/` | Domain controllers in root; `Subscribers/` — log-channel subscribers; `Pages/` — public page controllers |
| Callbacks | `inc/Callbacks/` | AJAX handlers only; разложены по доменным подпапкам |
| Repositories | `inc/Repositories/` | Read/write `wp_options` as structured arrays; `WPDBRepositories/Log/` — лог-репозитории, почти все наследуют `AbstractLogRepository` (общие `list()`/`countFiltered()`/`listAll()`; наследник задаёт `channel()`, `filterMap()`, `hydrate()`) |
| MetaBoxes | `inc/MetaBoxes/` | Field and template definitions for metaboxes |
| DTO | `inc/DTO/` | Data transfer between layers; подпапки зеркалят домены |
| Enums | `inc/Enums/` | Typed constants (slugs, capabilities, option names, AJAX hooks) |
| Services | `inc/Services/` | Stateless services; subdirs mirror domain groups |
| Shared | `inc/Shared/` | Traits (`inc/Shared/Traits/`) + static utility `PluginLogger` |
| Cli | `inc/Cli/` | WP-CLI команды; реализуют `ServiceInterface` и сами выходят из `register()`, если `WP_CLI` не определён |

**Транзиенты** — только через `Managers/Wp/TransientManager` (`get`/`set`/`delete`/`take`); ключ — кейс `Enums/Wp/TransientKey`, сырых строк в вызывающем коде быть не должно. Исключение: сервисы с собственным инкапсулированным префиксом (`RateLimitService`, `EmailOtpService`, `TaskPublishGuard`) — там ключ уже локализован в одном классе.

**Модули** (`inc/Modules/`) — конфигурация наследует `Modules/Shared/ModuleConfig` (опция + дефолты + тумблер с приоритетом константы `wp-config.php`). Ключи модульных опций живут В МОДУЛЕ и НЕ попадают в core-`OptionName`: ядро не должно знать о модулях. По той же причине модуль публикует свои реализации ядру фильтрами (напр. `fs_lms_captcha_provider`), а не биндингом в core-контейнере.

**КТП (`inc/Callbacks/Course/`)** разложена по ответственностям, и сервисный слой зеркалит
это разбиение один-в-один: `ProgramCallbacks` ↔ `ProgramCompositionService`,
`LessonScheduleCallbacks` ↔ `ScheduleReflowService`, `IndividualLessonCallbacks` ↔
`IndividualLessonService`, `GroupRosterCallbacks`/`LessonDeliveryCallbacks` ↔
`GroupCalendarService` + `ScheduleEventPublisher`. Новый коллбек КТП обязан попадать в
существующую ответственность, а не заводить свою.

**BaseController** (`Inc\Core\BaseController`): infrastructure utility only — not a domain or architectural base class. Provides `$plugin_path`, `$plugin_url`, `$plugin_name`, and helpers `path()`, `url()`. Also declares the `AjaxResponse` trait (inherited by all subclasses). Extend this purely to gain access to plugin path helpers and AJAX transport — not to express any domain relationship. Controllers and Callbacks extending it are unrelated to each other beyond sharing these utilities.

### Contracts

`inc/Contracts/` defines interfaces all implementations must satisfy:
- `ServiceInterface` — `register(): void`; required by DI container bootstrap
- `FieldInterface` — implemented by MetaBox field classes

### Data Model

Subjects are stored in `wp_options` (key: `fs_lms_subjects_list`) as `['subject_key' => ['key' => ..., 'name' => ...]]`. Each subject dynamically registers two CPTs (`{key}_tasks`, `{key}_articles`) and a fixed taxonomy `{key}_task_number` (numeric sort applied automatically). User-defined taxonomies are also stored in `wp_options` via `TaxonomyRepository`. Boilerplates and template assignments are similarly stored in `wp_options` — never in post/term meta.

**Два флага пользовательской таксономии** (`TaxonomyDataDTO`) задают её роль, и оба обязаны
доезжать до экспорта/импорта предмета:
`is_required` — публичная: попадает в фильтры тренажёра/учебника и блокирует публикацию;
`use_in_articles` — привязывает таксономию к CPT статей (`TaxonomyDataDTO::postTypes()`,
задания получают её всегда). Матрица: обязательная + в статьях → фильтр и в тренажёре, и в
учебнике, публикация статьи без терма запрещена (`ArticlePublishValidator`); необязательная +
в статьях → служебная пометка автора, на фронт не выходит. Публикация статьи, кроме того,
всегда требует терм `{key}_task_number`.

**Ключи и константы — только через энумы**, сырых строк в коде быть не должно:
`OptionName` (`inc/Enums/OptionName.php`) — все ключи `wp_options`; `PostMetaName` — ключи
пост-меты; `Capability` — права; `UserRole` — роли (у каждой есть `->label()`);
`EmailTemplateType` — шаблоны писем для `EmailService`/`EmailTemplateInterface::get()`.
Состав кейсов смотреть в самих энумах.

**Стандарт прав на выгрузку ПД:** любой экспорт персональных данных
(`students`, `parents`, `archive`, точечная выгрузка записи об отчислении)
требует **обеих** проверок — `ManageLmsPlatform` (доступ к разделу) и
`ExportPII` (право выгружать ПД), через `authorizeAll()`. Одного
`ManageLmsPlatform` недостаточно.

---

## Nonce Pattern

`Inc\Enums\Nonce` is a backed enum with:
- `create(): string` — generates nonce
- `verify(string $queryArg = 'security'): void` — validates request

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

Дополнено 2026-08-08 (этап фиксов по сквозному ревью):

- **Модульные AJAX-экшены** — локальные константы модуля (`AdSyncSettingsController::SAVE_ACTION`,
  `AdSyncController::STATUS_ACTION` и аналоги в DaData/VideoLibrary/SmartCaptcha), вне core `AjaxHook`:
  ядро не знает о модулях. Обработчики — всё равно в Callbacks-классах модуля.
- **Модульные транзиенты** — ключи живут в модуле константой одного класса
  (`VideoLibrary/RecordingAlertService::COUNT_TRANSIENT`, `AdSync/AdStatusTokenService::PREFIX`),
  вне core `TransientKey`; сырые `set_/get_transient` там легальны — та же логика инкапсуляции,
  что у RateLimitService/EmailOtpService.
- **`current_user_can()` вне AJAX** — запрет прямых вызовов в Callbacks относится к AJAX-методам
  (там `Authorizer` с JSON-ответом); в не-AJAX хук-колбеках (`admin_notices` и т.п.) прямая проверка
  права допустима, право — только кейсом `Capability`.
- **`$_FILES`** — трейт `Sanitizer` даёт `uploadedFile()` для чтения дескриптора в Callbacks;
  внутри `MediaManager` прямой `$_FILES` легален (менеджер-обёртка WP upload API — его транспорт).

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

Полный пакет переноса — ZIP (`manifest.json` + `media/`), ссылки внутри пакета — `_export_id`,
а не WP ID; прогресс учеников не переносится. Формат, порядок импорта, откат и безопасность
распаковки описаны в `inc/Services/Subject/Bundle/CLAUDE.md` (грузится при работе с каталогом).
Большие предметы — через WP-CLI `wp fs-lms subject export|import`.

### Вход (`inc/Controllers/Person/AuthPageController.php`)

Вход — только по логину и паролю, штатным механизмом WordPress. Страница `/sign-in/`
(шорткод `ShortCode::LoginForm`, шаблон `templates/frontend/auth-page.php`) живёт в ядре:
контроллер регистрирует шорткод, уводит GET-заходы с `wp-login.php` на страницу плагина,
на `wp_login_failed` (приоритет 20 — после `AuthLogController`) возвращает на неё с флагом
ошибки и введённым логином, а `template_include` подменяет шаблон темы на `clean-page.php`.
Сама форма постит на `wp-login.php`, поэтому проверкой пароля и куками занимается WP.

Входа через соцсети нет: модуль `SocialAuth` (Hybridauth, Google/VK/GitHub) снят при
подготовке релиза вместе со свободными ролями внешних пользователей.

**Filter hook for CPT args:** `apply_filters('fs_lms_cpt_args', $args, $type, $subject)` — fired in `SubjectController` before registering each CPT; allows external modification of labels and options.

---

## JS Architecture

ES6-модули, сборка Webpack через Gulp. Два несмешиваемых паттерна: admin — jQuery и объекты
`export const X = { init() {} }`, frontend — чистые функции `export function initX()`.
AJAX живёт в `services/`/`managers/`, модалки и компоненты — только UI.

Полные конвенции (экспорты, точки входа, гарды инициализации, автозагрузчик, `window`-глобалы,
SVG-иконки) — `src/js/CLAUDE.md`, грузится при работе с `src/js/`.

**Инлайновые `<svg>` в JS-строках и PHP-шаблонах запрещены**: иконки берутся из
`src/js/common/icons.js` (JS) и enum `Inc\\Enums\\Ui\\Icon` (PHP).

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
- Лендинг предмета — WP-страницы, создаваемые вместе с предметом: `/{key}/` (описание,
  наполняется вручную) и дочерние разделы `trainer` / `articles` / `courses`. Состав, слаги,
  заголовки и шорткоды разделов задаёт enum `Inc\Enums\Wp\SubjectPageType`; заводит и сносит
  страницы `SubjectPagesService` (идемпотентно; ID хранит `SubjectPagesRepository` в опции
  `fs_lms_subject_pages`), рендерит — `SubjectLandingController` через шорткоды.
  Предмет без банка (`hasBank = false`) получает только описание и курсы; удаление предмета
  отправляет страницы в корзину, архивация их не трогает
- **Адреса записей живут внутри разделов**: задание — `/{key}/trainer/{номер}/`, статья —
  `/{key}/articles/{slug}/` (`SubjectCptArgsBuilder::sectionRouting()`). Собственных архивов
  у `{key}_tasks` / `{key}_articles` нет (`has_archive => false`): их адреса совпали бы с
  адресами страниц разделов. Списки отдаёт лендинг, поэтому смена ключа предмета или слага
  раздела требует `flush_rewrite_rules()`. **Значение кейса `SubjectPageType` — это
  одновременно слаг страницы, ключ в опции `fs_lms_subject_pages`, имя шаблона раздела
  (`templates/frontend/subject/{value}.php`) и хвост тега шорткода**: менять его можно
  только вместе с data-миграцией (образец — `Inc\Migrations\ArticlesSectionMigration`,
  переезд `textbook` → `articles`) и редиректом со старого адреса
  (`SubjectLandingController::redirectLegacySection()`)
- **Учебник — каталог, а не витрина**: `ArticlesDataBuilder` отдаёт все опубликованные
  статьи предмета секциями по номерам заданий, фильтрует и ищет по ним клиент
  (`article-catalog.js`), пересчитывая счётчики опций по видимому срезу. Группы фильтров
  сайдбара у тренажёра и учебника строит общий `FilterGroupService` (JS-зеркало сводок —
  `summaryFor()` там же в `article-catalog.js`). Карточки каталога кешируются транзиентом
  `TransientKey::ArticleCatalog`, сбрасывает его `ContentCacheService` на `save_post`.
  Подгрузка по прокрутке — тоже клиентская: разметка сентинела общая с тренажёром, но
  «страница» здесь — окно из 5 секций уже отрисованного каталога, и смена фильтра
  возвращает окно к началу
- **Слаг статьи собирает плагин, а не ядро**: `article-task-{номер задания}-{номер в серии}`,
  без номера задания — `article-{номер}` (`ArticleSlugService`, фильтр `wp_insert_post_data`
  на приоритете 20 — после валидации, которая может откатить публикацию в черновик).
  Пересобирается на каждом сохранении из редактора, пока статья не опубликована; первая
  публикация ставит мету `PostMetaName::ArticleSlugLocked`, и адрес больше не меняется никогда.
  Сигнал «сохранение из формы» — наличие `tax_input` в данных записи: программные вставки
  (перенос предмета пакетом, черновик из конструктора урока) свой `post_name` сохраняют.
  **Уникальность — на сервисе**: `wp_unique_post_slug()` отрабатывает ДО фильтра, ядро
  выставленный слаг не проверяет. Привести старые статьи к правилу — `wp fs-lms article reslug`
- **Ссылки на разделы предмета — только через `SubjectPagesService::links()`** (`SubjectLinksDTO`):
  крошки, «Все задания», «Все материалы», «Все курсы» и чипы-фильтры ведут на страницы лендинга.
  Фолбэк на архивы CPT зашит в сам `links()` — билдеры и шаблоны `getArchiveLink()` для
  заданий и статей больше не зовут
- Страница статьи (`{subject}_articles`) — `ArticlePageController` → `Callbacks\Article\TemplateCallbacks`
  → `single-article.php`. Спец-разметку внутри текста автор НЕ размечает: классы врезкам,
  иллюстрациям и листингам, якоря заголовкам (для оглавления) и карточку задания вместо
  абзаца-ссылки на задание делает пост-обработка `ArticleContentService`

### ThemeCompatService — обязательно для всех публичных шаблонов

**Никогда не вызывать `get_header()` / `get_footer()` напрямую** в шаблонах плагина. Использовать только:

```php
use Inc\Services\Shared\ThemeCompatService;

ThemeCompatService::header(); // вместо get_header()
ThemeCompatService::footer(); // вместо get_footer()
```

Причина: блочные (FSE) темы не имеют `header.php` / `footer.php`, прямые вызовы выдают Deprecated. `ThemeCompatService` автоматически выбирает нужный API в зависимости от типа темы.

### Клиентская валидация форм

Система валидации — `src/js/common/validators/` + `validation-manager.js`; как повесить
валидатор на поле и завести новый, описывает скилл `form-validation`.

### wp_localize_script — только в слое Core/Assets

Все `wp_localize_script()` вызовы должны быть в слое `inc/Core/Assets/*`
(`AdminAssets` / `AdminLocalizations` / `FrontendAssets` / `BundleLoader`;
фасад — `inc/Core/Enqueue.php`), не в шаблонах. Исключения — self-contained
модули, локализующие СВОЙ admin-JS в своём settings-контроллере (AdSync и др.),
и `ModulesDashboardController` (локализация Dashboard-бандла).

## CSS / SCSS Rules

- **No inline styles** — never use `style=""` attributes in PHP templates or JS DOM manipulation
- **No raw values** — цвета, отступы, кегли и тайминги только токенами из `_variables.scss`;
  нет нужной ступени — сначала завести её, потом использовать
- **JS не задаёт стили** — состояния переключаются классами, показ/скрытие — атрибутом `hidden`
- **stylelint обязателен**: `npm run lint:css` (входит в `npm run ci`)

Лестница токенов, палитры, правила `!important`/вложенности и работа с `rem()` —
`src/scss/CLAUDE.md`, грузится при работе с `src/scss/`.

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

DDL-миграции (`Migration_1_0_0`) выполняются ТОЛЬКО при (ре)активации плагина — перезагрузка
страницы их не перезапускает. Порядок работы с колонками, таблицами и data-миграциями,
которые обязаны доехать до живых установок, описывает скилл `db-migrations`.

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