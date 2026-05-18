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

**BaseController** (`Inc\Core\BaseController`): provides `$plugin_path`, `$plugin_url`, `$plugin_name`, and helpers `path()`, `url()`. Extend this in Controllers and Callbacks that need plugin file paths.

### Contracts

`inc/Contracts/` defines interfaces all implementations must satisfy:
- `ServiceInterface` — `register(): void`; required by DI container bootstrap
- `RepositoryInterface` — base contract for all repositories
- `FieldInterface` — implemented by MetaBox field classes
- `MenuBuilderInterface` — implemented by Builder classes
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
- `Capability` — `ADMIN` (`manage_options`), `ViewLMSStats`, `ManageLMSAssignments`, `Read`
- `PostMetaName` — `TemplateType` (`fs_lms_template_type`), `Meta` (`fs_lms_meta`) — use these instead of raw strings when reading/writing post meta
- `UserRole` — internal roles (`FSTeacher`, `FSStudent`, `FSParent`) and external/free roles (`Student`, `Teacher`); each has a `->label()` method

---

## Nonce Pattern

`Inc\Enums\Nonce` is a backed enum with:
- `create(): string` — generates nonce
- `verify(string $queryArg = 'security'): void` — validates request

Available nonces: `TaskCreation`, `Subject`, `Manager`, `SaveMeta`, `SaveBoilerplate`.

**Usage:** Always call `Nonce::Subject->verify()` (or appropriate case) at the top of every AJAX callback.

## Shared Traits

**`Authorizer`** — call `$this->authorize(Nonce::Subject, Capability::ADMIN)` to check nonce + capability in one step. Throws and sends a JSON error on failure.

**`Sanitizer`** — use these instead of raw WP functions:
- `sanitizeText()`, `sanitizeKey()`, `sanitizeInt()`, `sanitizeHtml()`, `sanitizeEditorContent()`, `sanitizeBool()`
- `requireText()`, `requireInt()`, `requireKey()` — same as above but throw on empty/missing input

**`AjaxResponse`** — `$this->success($data)` / `$this->error($message)` wrap `wp_send_json_*` and log in `WP_DEBUG` mode.

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

Two globals injected via `wp_localize_script`:
- `fs_lms_vars` — always available on admin pages: `ajaxurl`, `subject_nonce`, `manager_nonce`, `ajax_actions`
- `fs_lms_task_data` — only on `_tasks` CPT pages: `ajax_url`, `nonce`, `subject_key`, `post_type`

Both are typed in `src/js/admin/_types.js` (JSDoc `@typedef` + `window.*` declarations). Import `_types.js` in any file that uses these globals.

JS modules: `components/` (UI only, no AJAX), `services/` (AJAX + business logic), `modules/` (shared utilities). Services use component callbacks for decoupling — service registers `onSubmit(fn)`, component fires it.

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
- Always: validate nonce, sanitize input, return via `wp_send_json_success` / `wp_send_json_error`
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

The plugin runs inside Docker. The plugin directory is mounted as a volume — PHP file changes apply immediately, but OPcache may hold stale bytecode.

```bash
# After PHP changes, if behavior seems unchanged:
docker restart wp_app

# Query the database directly:
docker exec wp_db mariadb -u root -proot wordpress -e "SELECT ..."

# Services: wp_app (WordPress:8080), wp_db (MariaDB), wp_phpmyadmin (phpMyAdmin:8081)
```

Data is stored in `wp_options` — never in term meta or post meta directly.

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