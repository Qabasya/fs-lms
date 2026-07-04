# План рефактора SCSS

> Аудит: ветка `stage_11`, 2026-07-04. Объём: 91 файл, ~11 300 строк в `src/scss/{admin, common, frontend, player, profile, shared}`.
> Порядок фаз = порядок исполнения; каждая фаза — отдельный коммит/PR, после каждой — `npx gulp styles:check` + визуальная проверка затронутого бандла.
>
> **Статус выполнения:**
> - ✅ Фаза 0 — stylelint + конвенции (правило «цвет только токеном» пока warning)
> - ✅ Фаза 1 — `shared/_tokens.scss` (rem-шкалы, брейкпоинты, z); common отвязан от admin/variables; 782px → `$bp-wp`
> - ✅ Фаза 2 — cabinet-слой: `shared/cabinet/_theme.scss` (единый :root profile+player, словарь статусов плеера), `_ui.scss` (cab-btn/card/toast/reset — `.prof-btn` ≡ `.b`), hex плеера токенизированы, палитра типов шагов едина (`$step-type-palette` в tokens; admin приведён к плееру; JS-зеркало — player/icons.js)
> - ⬜ Фаза 3 — токенизация остатков (admin/frontend hex, font-size шкала profile/player)
> - ⬜ Фаза 4 — px → rem + гибкие контейнеры
> - ⬜ Фаза 5 — адаптив frontend/profile/player
> - ⬜ Фаза 6 — санация admin (_modal, !important)
>
> ⚠️ После Фазы 2 нужна визуальная проверка: кабинет `/profile/` (кнопки +1px паддинга, chip.ok чуть темнее текст), плеер (радиус карточки 16→14, b-lg радиус), конструктор курса (цвета типов шагов приведены к плееру: видео/работа/контрольная сменили оттенок — намеренно).

---

## 1. Итоги аудита

### 1.1. Пиксели вместо относительных величин

Вхождений `NNpx` (без учёта `_variables.scss` это почти всё — сырые значения):

| Бандл | px | rem/em | Комментарий |
|---|---|---|---|
| profile | **897** | 25 | худший; топ: `_ktp.scss` (181), `_summary.scss` (131), `_student-courses.scss` (105), `_dashboard.scss` (102), `_journal.scss` (86) |
| player | **458** | 10 | топ: `_step-work.scss` (87), `_shell.scss` (85), `_step-task.scss` (78), `_rail.scss` (62) |
| admin | 356 | 6 | много токенизировано, но токены сами в px |
| frontend | 331 | 18 | токены тоже в px |
| common | 54 | 0 | |
| **всего** | **~2100** | 59 | |

Дrobno-типографика с дробями (`font-size: 14.5px`, `12.5px` в player) — прямой перенос из дизайн-хэндоффа. Фиксированные ширины макета: `--side-w: 244px` (profile), `--colw: 1402px` (player), `$max-width: 1160px` (frontend), `$settings-max-width: 860px` (admin).

### 1.2. Адаптивность

Всего **20 `@media` на 91 файл**. Дыры:

- **profile** (нужен мобильный): без media — `_journal.scss` (таблица журнала!), `_ktp.scss` (drag&drop календарь, крупнейший файл экрана), `_buttons.scss`, `_card.scss`, `_overlays.scss` (модалка/поповеры). Покрыты: dashboard (1 bp), roster, summary (2 bp), substitutions, student-courses, layout (только 782px — WP-брейкпоинт, не мобилка).
- **frontend** (публичные страницы, нужен мобильный): без media — **12 из 16 компонентов**, в т.ч. `_apply-form`, `_join-form`, `_auth-page`, `_assessment` (прохождение контрольной!), `_task-widget`, `_carousel`, `_nav`, `_sidebar`, `_tabs`, `_submissions`, `_task-content`.
- **player**: `_shell` (1100/640) и `_card` (640) покрыты; `_rail`, `_strip`, `_step-task`, `_step-work`, `_step-video` — нет. Рейка на hover — на тач-устройствах недоступна в принципе (нужен tap-режим).
- **admin** — по ТЗ не требуется (кроме уже существующих builder-collapse).

### 1.3. Токенизация

- **52 hex-цвета вне `_variables.scss`**: player — 33 (`_step-task` 10, `_step-video` 9, `_shell` 5…), это «слепые» цвета вроде `#12152b`, `#c9cde4`, `#2b3244`, `#69db7c` (тост: в profile это токен `--toast-ok`, в player — хардкод!). Плюс россыпь сырых `rgba(...)` (17 мест вне variables).
- **167 сырых `font-size: NNpx`** в profile+player — шрифтовой шкалы в этих бандлах просто нет.
- Отступы/радиусы в profile/player почти не токенизированы: `padding: 16px 18px`, `border-radius: 16px` (при существующем `--radius: 14px` рядом), `999px`-пилюли и т.п.
- `782px` (WP-брейкпоинт admin-bar) захардкожен в 3 файлах (`admin/_modal`, `admin/_task-fields`, `profile/_layout`).

### 1.4. Дубли компонентов (profile ↔ player и не только)

| Компонент | profile | player | Расхождения |
|---|---|---|---|
| reset/base | `components/_base.scss` | `components/_base.scss` | почти идентичны (диффер: скоуп `body` vs `body.fs-player-page` + 2 хелпера) |
| Кнопка | `.prof-btn` (+`-primary/-danger/-ghost/-sm/-lg`) | `.b` (+`.b-pri/.b-gh/.b-sm/.b-lg/.b-dis`) | те же цвета/структура; padding 8×15 vs 9×16, font-size 13 vs 12.5/14 |
| Тост | `.prof-toast` (цвет из `--toast-ok`) | `.toast` (цвет `#69db7c` хардкод) | одна и та же конструкция, скопирована |
| Карточка | `.prof-card` (radius 14, шапка) | `.card16` (radius **16**, padding 34×40) | концептуально одно, размеры разные |
| Контекст-меню/поповер | `.prof-ctx-menu` | поповеры в `_shell` | схожая конструкция |
| Чип/бейдж | `.prof-chip` | бейджи в step-* | схожая конструкция |

Другие дубли: **тосты в 3 системах** (common `.fs-toast`, profile, player — плюс admin JS-тост со стилями в admin); **карточки в 3 системах** (admin `_card.scss` `.fs-card`, profile, player); формы apply/join/auth на фронте собраны из одинаковых mixin-заготовок, но фронт живёт на своей палитре.

### 1.5. profile vs player — «должны быть одинаковыми»

Значения токенов уже совпадают (акцент `#3b5bdb`, поверхности, `--shadow-*`, шрифт Golos Text), но:

- **Имена статус-токенов разные**: profile `--g-good / --ok-bg / --ok-border / --ok-ink / --absent / --absent-bg`, player `--ok / --ok-soft / --ok-line / --err / --err-soft / --err-line / --wait*`. Одни значения — два словаря → уже пошёл дрейф (см. тост).
- `:root`-блоки **скопированы** между двумя `_variables.scss` (тени, акцент, поверхности — дословные дубли).
- У player есть `--mono`, `--radius` без `-xs`; у profile — `--radius-xs`, `--ink-inverse`, семантика посещаемости. Пересечение ~70%, различия не осмысленные, а исторические.
- Радиус карточки 14 vs 16, кнопочные размеры «почти» совпадают — визуально это два слегка разных продукта.

### 1.6. Цвета типов шагов — ТРИ источника с РАЗНЫМИ значениями

| Тип | admin `$step-*` (конструктор) | player `$type-*` (+ JS `TYPES` в `player/icons.js`) |
|---|---|---|
| video | `#8c3bca` | `#7048e8` |
| practice/work | `#c9540a` | `#e8590c` |
| quiz/assessment | `#00834a` | `#e03131` (assessment) / task `#099268` |

Т.е. один и тот же тип шага в конструкторе и в плеере подсвечен разными цветами, плюс значения продублированы в JS (`player/icons.js TYPES`) и в SCSS.

### 1.7. `_variables.scss` по доменам — четыре разных стиля

| Домен | Стиль | Проблемы |
|---|---|---|
| admin | SCSS-переменные + CSS-bridge `--color-primary` (WP-тема) | токены в px; компонентные секции (tabs/tables) вперемешку с глобальными; `@mixin cb-chip` живёт в variables, а не в mixins |
| profile | CSS custom props в `:root` + SCSS для таймингов/bp/z | нет шкалы шрифтов/отступов вообще |
| player | то же, «по образцу profile» | `:root` скопирован из profile с расхождениями; `$type-*` дублируют JS |
| frontend | чисто SCSS, свой нейминг (`$color-accent`, `$radius`, `$bp-md`) | не пересекается ни с кем: другая палитра (`#28c0f1`), другой шрифт (Ubuntu), другие имена |
| common | **нет своего файла** — 8 компонентов тянут `@use '../../admin/variables'` | кросс-доменная связка: admin-токены (WP-цвета) вшиваются в бандл, который грузится на публичке |

Брейкпоинты — три несовместимых набора: admin `768/960`, frontend `900/600`, profile `1180/900/720/640`, player — magic numbers `1100/640` прямо в media. Радиусы — три шкалы. Моноширинные стеки — три разных (`Consolas…`, `'JetBrains Mono'…`, `'Courier New'…`). Шрифт фронта (Ubuntu) грузится через `@import url()` в `_reset.scss` (рендер-блок, мимо `wp_enqueue`), профиль/плеер — через `<link>` в шаблонах.

### 1.8. Стандарты SCSS

Хорошо: везде `@use` (deprecated `@import` — только `url()` шрифта), вложенность умеренная (>4 уровней не обнаружено), файлы-компоненты мелкие, комментарии содержательные.

Плохо:
- **115 `!important`**: `admin/_modal.scss` — 48 (война специфичности с WP-core), `common/_widths.scss` — 29 (utility-классы — допустимо, но не помечено как осознанное), `frontend/_mixins` — 8.
- `admin/components/_modal.scss` — **1152 строки**, монолит из десятка модалок.
- Два стиля форматирования вперемешку (однострочные declaration-blocks в profile/player vs многострочные в admin/player-shell; табы vs пробелы по файлам).
- Нет stylelint / автопроверки конвенций; `styles:check` проверяет только компиляцию.

---

## 2. Целевая архитектура

```
src/scss/
├── shared/
│   ├── _tokens.scss          # ЯДРО (новое): палитра-значения, spacing/radius/type-шкалы в rem,
│   │                         #   брейкпоинты, z-шкала, тени, шрифтовые стеки, rem()-функция
│   ├── _chip-palette.scss    # как есть (единственный легальный источник hex вне tokens)
│   └── cabinet/              # НОВОЕ: общий слой кабинета (profile + player)
│       ├── _theme.scss       # один :root {} на оба бандла (цвета, тени, радиусы, --font)
│       ├── _base.scss        # reset + body-холст + loading/empty
│       ├── _buttons.scss     # единая кнопка/чип/сегмент
│       ├── _card.scss        # единая карточка (radius/padding из токенов)
│       ├── _toast.scss       # единый тост
│       └── _popover.scss     # ctx-menu / grade-pop / поповеры
├── admin/    — @use shared/tokens; свои WP-цвета остаются
├── common/   — @use shared/tokens (СВОИ токены вместо ../../admin/variables)
├── frontend/ — @use shared/tokens; палитра фронта — поверх ядра
├── profile/  — @use shared/tokens + shared/cabinet/*; остаются только экраны
└── player/   — @use shared/tokens + shared/cabinet/*; остаются только шаги/рейка
```

Принципы:

1. **Единицы**: `rem` для шрифтов, отступов, радиусов и размеров компонентов (функция `rem($px)` в tokens; база 16px). `%`/`fr`/`minmax()`/`clamp()` — для сеток и контейнеров (`--side-w: clamp(13rem, 18vw, 15.25rem)`, `--colw: min(100%, 87.5rem)`, `$max-width: min(72.5rem, 94vw)`). Крупная типографика — `clamp()`. В `px` остаются только 1–2px бордеры/разделители и тени.
2. **Токены**: компонентные файлы не содержат hex/rgba/сырых px — только `var(--…)`/`$token`/`rem()`. Нет токена — добавить в tokens/theme, потом использовать (правило уже в CLAUDE.md — распространить на profile/player фактически).
3. **Один словарь статусов**: `--ok/--ok-soft/--ok-line`, `--err/…`, `--warn/…`, `--info/…` (нейминг player как более системный) + алиасы посещаемости в profile объявляются через них.
4. **Брейкпоинты — один набор** в tokens: `$bp-sm: 640px`, `$bp-md: 782px` (WP), `$bp-lg: 960px`, `$bp-xl: 1180px` + миксин `@mixin below($bp)`. Все magic numbers в media уходят.
5. **Mobile-first обязателен** для frontend/profile/player; admin — без требований (существующие builder-collapse сохраняются).

---

## 3. Фазы

### Фаза 0 — Инструменты и конвенции (S)
- Добавить **stylelint** (`stylelint-config-standard-scss`) + правила: запрет hex/px в компонентах (`declaration-property-value-allowed-list` / кастом через `scale-unlimited/declaration-strict-value` для `color/background/font-size`), `max-nesting-depth: 3`, единый порядок свойств, один стиль отступов (табы — как в большинстве файлов).
- `npm run lint:css`, включить в `styles:check`.
- Зафиксировать конвенции в CLAUDE.md (раздел CSS/SCSS) — форматирование, нейминг токенов, правило «px только для бордеров».

### Фаза 1 — Ядро токенов `shared/_tokens.scss` (M)
- Функция `rem()`; шкалы: `$space-1…8` (rem), `$radius-xs/sm/md/lg/pill`, type-scale `$fs-2xs…2xl` (rem), `$bp-*`, `$z-*` (сквозная: base < sticky < topbar < rail < popover < modal < tooltip < toast), `$font-ui` (Golos), `$font-mono` (один стек), базовые тени.
- `common/` переводится с `../../admin/variables` на tokens (8 файлов) — admin-цвета из публичного бандла уходят; нужные семантические цвета (`$color-danger` для валидации) — в tokens.
- Захардкоженный `782px` → `$bp-md` во всех 3 местах.

### Фаза 2 — Слияние profile + player в cabinet-слой (L, самая ценная)
- `shared/cabinet/_theme.scss`: один `:root` (объединение двух текущих, **словарь player для статусов**), недостающее у каждого добавляется (`--mono` в profile, `--radius-xs`/`--ink-inverse` в player). Оба `_variables.scss` бандлов худеют до таймингов/bp/z + `@forward` темы.
- Переименование статус-токенов в profile-компонентах (`--g-good`→`--ok`, `--ok-bg`→`--ok-soft`, `--absent`→`--err`, …). **Обязательно** grep по JS: инлайновые `var(--…)` из `src/js/profile/**` (например `var(--muted-2)` в иконках-шевронах, цвета в dashboard) и по PHP-шаблонам.
- Общие компоненты: `_base`, `_buttons` (`.prof-btn` остаётся канон-классом; `.b*` в player — алиасом на тот же placeholder/mixin или миграция классов в JS/шаблонах плеера), `_card` (один радиус/паддинг из токенов; `.card16` = карточка + модификатор размера), `_toast`, `_popover`.
- Убрать 33 хардкод-hex из player-компонентов: завести в теме `--video-surface (#12152b)`, `--video-ink-soft (#c9cde4)` и т.п.; `#69db7c` тоста → `--toast-ok`.
- **Цвета типов шагов — один источник.** Выбрать палитру player (`#1c7ed6/#7048e8/#099268/#e8590c/#e03131`), положить в `shared/_tokens.scss` (SCSS map) + CSS-vars в темах admin и cabinet; привести admin `$step-*` к ней; в JS `player/icons.js TYPES` читать цвета из CSS-vars (`getComputedStyle`) или, минимум, пометить оба места перекрёстными комментариями-зеркалами (как сделано у chip-palette).

### Фаза 3 — Токенизация остатков (M)
- 167 `font-size: NNpx` в profile/player → type-scale (14.5px/12.5px округляются до шкалы — визуальный дифф ожидаем и допустим).
- Отступы/радиусы profile/player → `$space-*`/`--radius-*`; сырые `rgba()` → токены-тени/оверлеи.
- frontend/admin: оставшиеся 3+2 hex и rgba → в свои variables.
- `admin/_variables.scss` навести порядок: `@mixin cb-chip` → `_mixins.scss`; компонентные секции (Subject Tabs/Tables) — в файлы компонентов или под явный раздел.

### Фаза 4 — px → rem + гибкие контейнеры (L)
- Механическая конвертация компонентов на `rem()` (можно полуавтоматом: sed по паттерну + ручная вычитка бордеров).
- Токены-размеры в variables тоже переводятся в rem (admin `$spacing-*`, `$font-size-*`; frontend аналогично).
- Фиксированные ширины → `clamp()/min()`: `--side-w`, `--top-h`, `--colw`, `$max-width`, `$sidebar-width`, `$field-max-width`.
- admin: конвертация в rem — да; адаптив — нет (вне ТЗ).

### Фаза 5 — Адаптив (L)
- **frontend** (mobile-first, `$bp-sm/$bp-md`): `_apply-form`, `_join-form`, `_auth-page` (формы — стек, поля 100%), `_assessment` + `_task-widget` (прохождение на телефоне), `_nav`/`_sidebar`/`_tabs` (бургер/скролл-табы), `_carousel`, `_submissions`, `_task-content`.
- **profile**: `_journal` — горизонтальный скролл сетки со sticky-колонками + компактная ячейка; `_ktp` — банк тем над календарём (стек), календарь со скроллом; `_overlays` — поповеры/модалки fullscreen на `$bp-sm`; `_buttons`/`_card` — touch-размеры (min-height 44px интерактивов).
- **player**: рейка — вместо hover-разворота на тач: кнопка-тоггл (JS-флаг уже есть — pin); `_strip`, `_step-task/work/video` — стеки и 100%-ширины на `$bp-sm`; проверить 1100/640 после rem-конверсии.
- Каждому экрану — smoke-проверка на 360px/768px (см. `.docs/UI.md`, если появится чек-лист).

### Фаза 6 — Санация admin (M, низкий приоритет)
- `_modal.scss` (1152 строки) распилить по модалкам; 48 `!important` снять поднятием специфичности через обёртку `.fs-lms-modal-root` (у модалок свой корень) — цель ≤10.
- `common/_widths.scss` — оставить `!important` как задокументированные utility.
- Дубль-тосты: admin-тост перевести на common `.fs-toast`.

---

## 4. Порядок, риски, критерии готовности

**Порядок**: 0 → 1 → 2 → 3 → 4 → 5 → 6. Фазы 3–5 можно вести по-бандльно (frontend / profile / player отдельными PR). Не смешивать с фичами.

**Риски и что проверять**:
- Переименование CSS-vars ломает **JS-инлайны и PHP**: grep `var(--` по `src/js/**` и `templates/**` при каждом переименовании (уже есть точки: `icoChevronRight(18, 'var(--muted-2)')`, покраска чипов из JS, `ProfileViewResolver::chipColorIndex()` — PHP-зеркало chip-палитры).
- px→rem меняет рендер при нестандартном root font-size браузера — это **ожидаемое поведение** (доступность), но визуальные диффы на ревью обязательны.
- Слияние `.b` ↔ `.prof-btn` требует правок HTML-строк в JS плеера и PHP-шаблонах — делать в одном PR со стилями.
- Порядок каскада в `profile.scss`/`player.scss` сохранять (комментарий в entry про монолит `_core.scss`).

**Definition of Done**:
1. `stylelint` зелёный; в компонентах нет hex/rgba/сырых px (кроме 1–2px бордеров) — проверяется правилом, не глазами.
2. Один `:root`-словарь на profile+player; ни одного дублированного значения между их variables.
3. Цвета типов шагов совпадают в конструкторе, плеере и JS.
4. Все публичные экраны (frontend/profile/player) юзабельны на 360px.
5. `@media` только через `$bp-*`; magic numbers отсутствуют.
6. `!important` ≤ 40 по проекту (widths-utility + WP-неизбежное), каждый — с комментарием-причиной.
