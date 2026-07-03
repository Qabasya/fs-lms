# Рефакторинг домена «Личный кабинет» (profile)

Анализ на соответствие стратегии (CLAUDE.md), SOLID, мёртвый код, токенизация SCSS
`src/scss/profile` по аналогии с `src/scss/admin`.

Дата: 2026-07-03. Область: `inc/**/Profile*`, `src/js/profile/`, `src/scss/profile/`,
`templates/frontend/profile.php`. Незакоммиченные изменения по Assessment не трогаем.

---

## Результаты анализа

### Соответствие стратегии (CLAUDE.md)

Соблюдено: контроллеры только регистрируют хуки (Template Method через `AjaxController`);
Callbacks тонкие, авторизация через `authorize()` / `Nonce::verify()`; данные через
Repositories/Managers; DI через контейнер; локализация только в `Enqueue.php`.

Нарушения:
1. **Инлайн-стили в JS** — правило «No inline styles»: статические `style="cursor:pointer"`,
   `style="margin-left:auto"`, `style="flex:1"`, `style="font-size:12px;color:var(--muted)"`
   и т.п. в `dashboard.js`, `journal.js`, `ktp.js`, `learner.js`. (Динамические data-driven
   цвета групп/аватаров — допустимое исключение, в CSS их не выразить.)
2. **Нет токенов у profile-бандла** — правило «Variables required»: нет `_variables.scss`,
   токены зарыты в `:root` внутри `_core.scss`, ~25 захардкоженных цветов в компонентах
   (`#d8dbe2`, `#ebfbee`, `#fff4e6`, `#e6fcf5`, `#fdf0f0`, `#f6f8ff`…).
3. `profile.scss` использует `@forward` вместо `@use` (forward — для библиотек;
   admin-бандл собран через `@use`).

### SOLID

1. **SRP: `ProfileViewResolver::jsConfig()`** — 130 строк: базовый конфиг + 9 блоков
   препода/офиса + блок учащегося в одном методе. Разбить на приватные
   `baseConfig()` / `teacherConfig()` / `learnerConfig()`.
2. **Information Expert / тонкие Callbacks: `LearnerCallbacks`** — доменное правило
   «родитель видит только своих детей» реализовано в AJAX-слое. Перенести в
   `ProfileContext::resolveSubjectPersonId(int $requested): ?int` (чистая функция на DTO,
   тестируемая отдельно).
3. **SRP: `DashboardService::build()`** — ~140 строк: сбор групп + замены, маппинг занятия,
   ворклисты, статистика. Извлечь `collectGroups()`, `lessonItem()`, `coveredUntil()`.
4. DRY в JS: в 5–7 экранах продублированы `GROUP_COLORS`/`AVA_COLORS`, `DOW`/`MONTHS_RU`,
   `shortName()`, `initials()`, `fmtDate()`, `plural()`, `fmtNum()`, empty-state разметка
   и целиком «пикер группы» (kp-btn + openCtxMenu-меню — в journal/summary/ktp).
   Бонус-баг: dashboard красит группу хэшем id, сайдбар/пикеры — индексом в списке →
   одна группа получает разные цвета на разных экранах. Унифицировать (индекс в
   `fsProfile.groups`, хэш — фолбэк).

### Мёртвый код

1. `src/js/profile/data.js` (191 строка) — демо-слой, не импортируется нигде
   (в `dashboard.js` прямо написано «Демо-слой (data.js) убран»).
2. Неиспользуемый импорт `toast` в `app.js`.
3. Мёртвые SCSS-селекторы (проверено: 0 упоминаний в JS/PHP/шаблонах):
   - `_journal.scss`: варианты B–E (`.var-b/…/.var-e`), `.var-a .gc.work` (класс `work`
     ячейкам больше не ставится, T10.5), `.gc.has-grade`, `.gc.plain`, столбец среднего
     (`.col-avg`, `.hd-avg`, `.avg-pill`, `.avg-good/mid/low/none`), `.att-makeup`,
     `.att-l`, `.att-p`, `.hd-type`, `.hd-type-dot`, `.hd-work`, `.hd-col.work`,
     `.hd-col.today`, `.j-toolbar`, `.j-tb-spacer`, верхняя легенда `.j-legend`,
     `body.prof-density-cozy`;
   - `_core.scss`: `.prof-dash-grid` (3-колоночная сетка), `.prof-st-delta.up/.down`,
     `.kp-empty`, `.kp-arrow`, `.gp-grades`, `.gp-g`, `.gp-indi`, `body.prof-density-cozy`;
   - токены, живущие только ради мёртвых правил: `--t-sam`, `--t-contr`, `--t-prakt`,
     `--t-dom`, `--late`, `--g-mid` (используется только `--t-zachet`, `--g-good`, `--g-low`).
4. Артефакты сборки, закоммиченные в `src/`: `src/scss/admin/admin.css(.map)`,
   `src/scss/common/common.css(.map)` — gulp собирает в `assets/css/`, эти файлы
   никем не читаются.
5. `class="var-a"` в `journal.js` (после удаления вариантов) и `prof-st-delta`
   в `dashboard.js` — убрать вместе с правилами.

---

## План работ

### Фаза 1 — SCSS: токены + структура по аналогии с admin

1. Создать `src/scss/profile/_variables.scss`:
   - перенести `:root`-блок токенов из `_core.scss` (CSS custom properties остаются —
     JS использует `var(--…)` в рантайме);
   - добавить недостающие цветовые токены вместо хардкодов компонентов:
     `--line-3` (#d8dbe2, ховер-границы/скроллбары), `--ok-bg/--ok-border/--ok-ink`
     (chip.ok), `--good-bg` (#e7f5ec), `--good-bg-soft` (#f3faf5), `--warn-bg/--warn-ink`
     (#fff4e6/#d9480f), `--indi-bg/--indi-ink` (#e6fcf5/#087f5b), `--holiday-bg/
     --holiday-border/--holiday-tag-bg` (#fdf0f0/#f7d6d6/#ffe3e3), `--accent-soft-3`
     (#f6f8ff), `--ava-from/--ava-to` (градиент аватара), `--toast-ok` (#69db7c),
     `--backdrop`, `--topbar-bg`;
   - SCSS-переменные (по аналогии с admin): `$tr` (0.12s), `$breakpoint-wide` (1180px),
     `$breakpoint-tablet` (900px), `$breakpoint-mobile` (720px), `$breakpoint-narrow`
     (640px), `$z-*` (sticky-слои журнала, ctx/тост/модалка).
   - Вне объёма (следующая итерация, зафиксировано сознательно): пиксельные
     font-size/padding — у профиля своя мелкозернистая шкала из дизайн-макета,
     1:1-переменные без семантики только зашумят диff.
2. Разбить `_core.scss` (562 строки, «всё в одном») на `components/` по аналогии
   с admin, сохранив порядок каскада:
   `_base.scss` (reset, body, selection) → `_layout.scss` (грид, сайдбар, топбар, сцена) →
   `_buttons.scss` (prof-btn, chip, seg, icon-ghost, att-mark, child-bar) →
   `_card.scss` → `_dashboard.scss` → `_ktp.scss` → `_overlays.scss` (toast, ctx-menu,
   grade-pop, wd-pop) + перенести `_journal/_roster/_substitutions/_summary` в `components/`.
3. `profile.scss`: `@use 'variables'` + `@use 'components/…'` (вместо `@forward`).
4. Заменить все захардкоженные цвета/тайминги/брейкпоинты на токены.
5. Удалить мёртвые селекторы и осиротевшие токены (список выше).
6. Добавить классы под снимаемые инлайн-стили JS: `.att-y`, `.prof-btn-danger`,
   `.jl-sw--present/--absent`, `.is-clickable`, `.prof-work-ico.grade`, `.kal-hint`,
   `.kal-spacer`/`.prof-spacer`, `.tb-empty`, `.tc-pinned`, `.ke-ico--danger`,
   `.prof-work-count--pending`, `.prof-card-empty`.

### Фаза 2 — JS: общие модули, удаление мёртвого кода, инлайн-стили

1. Удалить `data.js`; убрать неиспользуемый импорт `toast` из `app.js`.
2. Новый `constants.js`: `GROUP_COLORS`, `AVA_COLORS`, `DOW_JS`, `DOW_RU`, `MONTHS_RU`.
3. Расширить `utils.js`: `shortName()`, `initials()`, `firstWord()`, `plural()`,
   `fmtNum()`, `fmtDayMonth()`, `todayIso()`, `groupColor(id)` (индекс в
   `fsProfile.groups`, фолбэк — хэш), `avaColor(list, id)`, `emptyState(icon, title,
   text, wrapClass)`.
4. Новый `picker.js`: `pickerBtnHtml()` (kp-btn с чипом) + `groupPickerItems()` —
   переиспользуют journal/summary/ktp (+ пикер ученика в summary).
5. Переключить все экраны на общие модули, убрать локальные дубликаты.
6. Убрать статические инлайн-стили (замена на классы из Фазы 1.6);
   динамические цвета групп/аватаров остаются.
7. Убрать `var-a` (journal.js) и `prof-st-delta` (dashboard.js) вместе с мёртвым CSS.

### Фаза 3 — PHP: SOLID

1. `ProfileContext::resolveSubjectPersonId(int $requested): ?int` — правило доступа
   родителя к детям; `LearnerCallbacks` вызывает его вместо inline-логики.
2. `ProfileViewResolver::jsConfig()` → `baseConfig()` + `teacherConfig()` +
   `learnerConfig()`; поведение и структура массива не меняются.
3. `DashboardService::build()` → извлечь `collectGroups()`, `roomNames()`,
   `lessonItem()`, `coveredUntil()`; поведение не меняется.

### Фаза 4 — мёртвые файлы и мелочь

1. Удалить `src/scss/admin/admin.css(.map)`, `src/scss/common/common.css(.map)`.
2. Поправить опечатку `TDOO` в `templates/frontend/profile.php`.

### Фаза 5 — проверка

1. `npx gulp build:check`-аналог (`stylesCheck`) + `npx gulp build` — сборка без ошибок.
2. `npm run lint:js` — чисто.
3. `vendor/bin/phpunit` — юнит-тесты Profile (Callbacks/Services/Resolver) зелёные.
4. Смоук: страница `/profile/` отвечает 200, `profile.min.css/js` пересобраны.

Не делаем (осознанно): слияние двух тривиальных AJAX-контроллеров профиля (паттерн
«контроллер на домен» единый по проекту); перевод пиксельной типографики на токены;
изменение структуры ответа `jsConfig` (контракт `window.fsProfile`).
