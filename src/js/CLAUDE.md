# JS — конвенции бандлов

Загружается при работе с файлами `src/js/`. Общие правила проекта — в корневом `CLAUDE.md`.

Раскладка каталогов: `admin/` (modals — UI без AJAX, managers — данные, services — AJAX+логика,
modules — общие утилиты), `frontend/` (components — UI без AJAX, services — AJAX), `common/`
(общие компоненты обоих бандлов). Точки входа и цели сборки — в `gulpfile.js`.

**Общие утилиты** — `src/js/common/utils.js`: `escapeHtml`, `fmtDate`/`fmtDayMonth`/`fmtDateTime`, `todayIso`, `initials`, `debounce`. Доменные бандлы реэкспортируют их под своими именами (`profile/utils.js` отдаёт `esc` ≡ `escapeHtml`) — своих копий не заводить.

**Модалки не ходят в сеть** — AJAX живёт в `admin/managers/*` (напр. `enrollment-api.js`, `draft-api.js`) и возвращает промисы; модалка только рисует. `init()` модалок идемпотентен (`_initialized`): их поднимает и автозагрузчик `ui.js`, и `admin.js`.

## Export conventions

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

## Entry points

`admin.js` wraps everything in `(function ($) { $(document).ready(...) })(jQuery)`.
`frontend.js` uses `document.addEventListener('DOMContentLoaded', ...)`.

## Initialization guards

- Admin: check selector presence before calling `.init()` — `if ($('.selector').length) { MyService.init(); }`
- Frontend: guard inside `initX()` — `if ( ! document.getElementById('el') ) { return; }`

## Auto-loader

`modules/ui.js` uses `require.context` to auto-load all files from `admin/modals/` — no manual import needed for modals (the auto-loader calls their `.init()`). Services and managers are imported and initialized manually in `admin.js`.

`require.context` в `ui.js` **нерекурсивен** — модалки из подпапок (`modals/enrollment/`) инициализируются явно в `admin.js`: у них есть порядок относительно своих сервисов. Фолбэк автозагрузчика берёт первый экспорт **с методом `init()`**, а не просто первый.

## Globals (window)

All `wp_localize_script` calls live in the `inc/Core/Assets/*` layer only (facade — `Enqueue.php`) — never in templates.

| Variable | Scope | Contents |
|---|---|---|
| `fs_lms_vars` | all admin pages | `ajaxurl`, `ajax_actions`, nonces |
| `fs_lms_task_data` | task CPT pages only | `ajax_url`, `nonce`, `subject_key`, `post_type` |
| `fs_lms_apply_vars` | frontend `/lms/apply` | `ajax_url`, `actions`, `nonces`, `captcha_key` |
| `fs_lms_applications_vars` | admin `fs_lms_userlist` | `nonces.trash` |

`fs_lms_vars` and `fs_lms_task_data` are typed in `src/js/admin/_types.js`. Import `_types.js` in any admin file that uses these globals (для подсказок IDE; на сборку не влияет, ESLint-правила на это нет).

`AjaxHook::toJsArray()` exports all hooks as `['camelCaseName' => 'snake_case_action']` — accessed as `fs_lms_vars.ajax_actions.myActionName`.

## SVG-иконки

Два единственных источника иконок — по одному на язык. **Инлайновые `<svg>` в JS-шаблонных строках и PHP-шаблонах запрещены.**

- **JS: `src/js/common/icons.js`** — именованные функции-фабрики (`icoCheck( 16 )`, `icoChevronRight( 18, 'var(--muted-2)' )`, `icoCaret( 12, 'kp-caret' )`), возвращают строку `<svg>…</svg>`. Импортируются любым бандлом (admin/frontend/profile/player); Webpack вкопирует только используемое (named exports → tree-shaking). Нет нужной иконки — добавить фабрику туда, не рисовать по месту.
- **PHP: enum `Inc\Enums\Ui\Icon`** — `Icon::Check->svg( 16 )` (без аргумента — размер по умолчанию кейса). В шаблонах: `<?php echo Icon::X->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>`.
- Глифы типов шагов урока — `STEP_GLYPHS` / `stepIcon( ui )` в `common/icons.js`; конструктор курса (`step-editor.js`) и плеер (`player/icons.js` → `typeIco`) используют один набор. `player/icons.js` — только мета типов (TYPES/typeMeta) и совместимый фасад `ICO` поверх common.
- Цвет всегда `currentColor` (наследуется от родителя); размер — аргумент фабрики. Общие глифы (check/chevron/lock/…) в JS- и PHP-источниках должны визуально совпадать.
