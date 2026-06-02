# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Build Commands

> **Claude не запускает сборку.** Gulp и Docker-команды выполняет пользователь самостоятельно.
> При необходимости — показать команду в тексте ответа, не вызывать через инструменты.

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

Webpack (via gulp-webpack-stream) bundles ES6 modules with Babel. `require.context` in `modules/ui.js` auto-loads all components from `src/js/admin/components/`.

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
| Callbacks | `inc/Callbacks/` | AJAX handlers only |
| Repositories | `inc/Repositories/` | Read/write `wp_options` as structured arrays |
| MetaBoxes | `inc/MetaBoxes/` | Field and template definitions for metaboxes |
| DTO | `inc/DTO/` | Data transfer between layers |
| Enums | `inc/Enums/` | Typed constants (slugs, capabilities, option names, AJAX hooks) |
| Services | `inc/Services/` | Stateless services (auth, caching, template resolution, post type helpers) |
| Shared | `inc/Shared/Traits/` | Reusable traits (AjaxResponse, Sanitizer, Authorizer, NumericSorter, TemplateRenderer, TaxonomySeeder, ErrorHandler) |

**BaseController** (`Inc\Core\BaseController`): infrastructure utility only — not a domain or architectural base class. Provides `$plugin_path`, `$plugin_url`, `$plugin_name`, and helpers `path()`, `url()`. Also declares the `AjaxResponse` trait (inherited by all subclasses). Extend this purely to gain access to plugin path helpers and AJAX transport — not to express any domain relationship. Controllers and Callbacks extending it are unrelated to each other beyond sharing these utilities.

### Contracts

`inc/Contracts/` defines interfaces all implementations must satisfy:
- `ServiceInterface` — `register(): void`; required by DI container bootstrap
- `FieldInterface` — implemented by MetaBox field classes
- `AuthStrategyInterface` — implemented by each OAuth provider strategy
- `MenuBuilderInterface` — implemented by Builder classes (single implementation; interface exists for future extension)

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
- `PostMetaName` — `TemplateType` (`fs_lms_template_type`), `Meta` (`fs_lms_meta`) — use these instead of raw strings when reading/writing post meta
- `UserRole` — internal roles (`FSTeacher`, `FSStudent`, `FSParent`) and external/free roles (`Student`, `Teacher`); each has a `->label()` method

---

## Nonce Pattern

`Inc\Enums\Nonce` is a backed enum with:
- `create(): string` — generates nonce
- `verify(string $queryArg = 'security'): void` — validates request

Available nonces: `TaskCreation`, `Subject`, `Manager`, `SaveMeta`, `SaveBoilerplate`, `Apply`, `ParentSubmit`, `Enroll`, `RevealPii`, `AddRepresentative`, `ReplaceRepresentative`, `UpdatePerson`, `WithdrawConsent`, `RequestPiiDeletion`, `ExportPii`, `VerifyOtp`, `TrashApplication`, `EditApplication`, `ReviewApplication`.

**Usage in admin AJAX callbacks (with capability check):** always `$this->authorize(Nonce::X, Capability::Y)` — never call `check_ajax_referer()` or `current_user_can()` directly.

**Usage in public/nopriv AJAX callbacks (no capability check):** `Nonce::X->verify()` directly, since `authorize()` requires a capability.

## Shared Traits

**`Authorizer`** — `$this->authorize(Nonce::X, Capability::Y)` checks nonce + capability in one call and sends a JSON 403 on failure. Declare `use Authorizer;` + `use Inc\Shared\Traits\Authorizer;` in every Callback class that handles admin AJAX. Never call `check_ajax_referer()` or `current_user_can()` directly in Callback methods.

**`Sanitizer`** — use these instead of raw WP functions:
- `sanitizeText()`, `sanitizeKey()`, `sanitizeInt()`, `sanitizeHtml()`, `sanitizeEditorContent()`, `sanitizeBool()`
- `requireText()`, `requireInt()`, `requireKey()` — same as above but throw on empty/missing input

**`AjaxResponse`** — `$this->success($data)` / `$this->error($message)` wrap `wp_send_json_*` and log in `WP_DEBUG` mode.
**Required in all Callback classes. Inherited via `BaseController` — do not re-declare unless the class does not extend `BaseController`.**

**`ErrorHandler`** — `$this->sendError(code, message, status)` auto-detects context (`wp_doing_ajax()`) and responds with either `wp_send_json_error()` or `wp_die()`. `$this->logException(Throwable)` logs exceptions with file/line/trace.
**Allowed only in Controllers that handle both AJAX and standard HTTP flows (currently: `AuthController` only). Do NOT use in Callback classes — they are AJAX-only; `AjaxResponse` is sufficient.**

Log format for both traits: `[FS LMS] CONTEXT: message | Context: {...}` — grep-able with `[FS LMS]`.

**`TemplateRenderer`** — `$this->render('template-name', $dataOrDTO)` loads from `templates/`, extracts variables or accepts a DTO.

---

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

### PostTypeResolver (`inc/Services/PostTypeResolver.php`)

Static helpers — use instead of string concatenation:
- `PostTypeResolver::tasks($key)` → `"{$key}_tasks"`
- `PostTypeResolver::articles($key)` → `"{$key}_articles"`
- `PostTypeResolver::isTaskPostType($post_type)` → bool
- `PostTypeResolver::subjectFromTaskPostType($post_type)` → subject key

### ContentCacheService (`inc/Services/ContentCacheService.php`)

Transient-based cache for recent tasks/articles. Hooks `save_post` and `delete_post` via `SubjectController` to auto-invalidate.

### TemplateService (`inc/Services/TemplateService/`)

- `TemplateRegistry` — registers available metabox templates
- `TemplateResolver` — resolves the correct template for a given post/term

### Auth (`inc/Services/AuthService/`)

OAuth via Hybridauth. `AuthService` orchestrates the full flow: find user by social ID → find by email (account linking) → register new → WP login. Provider strategies in `AuthStrategies/` (Google, VK, GitHub) implement `AuthStrategyInterface`. Auth settings (client IDs, secrets) stored in `OptionName::AUTH_SETTINGS`. Social user meta keys follow the pattern `fs_social_{provider}_id`.

**Filter hook for CPT args:** `apply_filters('fs_lms_cpt_args', $args, $type, $subject)` — fired in `SubjectController` before registering each CPT; allows external modification of labels and options.

---

## JS Architecture

### Directory layout

```
src/js/
├── admin/
│   ├── admin.js          — entry point; jQuery $(document).ready()
│   ├── _types.js         — JSDoc @typedef for window globals (fs_lms_vars, fs_lms_task_data)
│   ├── components/       — UI only; NO AJAX (modals, UI widgets)
│   ├── services/         — AJAX + business logic; orchestrates components
│   └── modules/          — shared utilities (modal-base, utils, ui registry)
├── frontend/
│   ├── frontend.js       — entry point; pure DOMContentLoaded
│   ├── components/       — UI only; NO AJAX (tabs, carousels)
│   └── services/         — AJAX + business logic (apply-form)
└── common/
    ├── common.js         — entry point
    └── components/       — shared UI components used on both sides
```

### Export conventions

**Admin** (`admin/components/`, `admin/services/`) — jQuery-based, object pattern:
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

`modules/ui.js` uses `require.context` to auto-load all files from `admin/components/` — no manual import needed for components (the auto-loader calls their `.init()`). Services are imported and initialized manually in `admin.js`.

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

> **Claude не выполняет docker-команды.** При необходимости — показать команду в тексте ответа, пользователь запустит её сам.

The plugin runs inside Docker. The plugin directory is mounted as a volume — PHP file changes apply immediately, but OPcache may hold stale bytecode.

```bash
# After PHP changes, if behavior seems unchanged:
docker restart wp_app

# Query the database directly:
docker exec wp_db mariadb -u root -proot wordpress -e "SELECT ..."

# Services: wp_app (WordPress:8080), wp_db (MariaDB), wp_phpmyadmin (phpMyAdmin:8081)
```

Data is stored in `wp_options` — never in term meta or post meta directly.

### Миграции в dev-окружении

**Удаление колонки** — не создавать новый файл миграции. Вместо этого:
1. Удалить колонку из DDL в `Migration_1_0_0::up()`
2. Добавить строку в секцию "Cleanup" того же файла: `$wpdb->query( "ALTER TABLE \`$table\` DROP COLUMN IF EXISTS \`col\`" );`
3. Сбросить версию схемы: `docker exec wp_db mariadb -u root -proot wordpress -e "UPDATE wp_options SET option_value='0.0.0' WHERE option_name='fs_lms_schema_version';"`
4. Перезагрузить любую страницу WP — все миграции перезапустятся автоматически

**Новые таблицы** — добавлять в `Migration_1_0_0::up()` и `down()`, не создавать отдельный файл.

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