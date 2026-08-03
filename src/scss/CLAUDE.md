# SCSS — токены и правила стилей

Загружается при работе с файлами `src/scss/`. Общие правила проекта — в корневом `CLAUDE.md`.

- **No inline styles** — never use `style=""` attributes in PHP templates or JS DOM manipulation
- **Variables required** — all SCSS component files must use tokens from `src/scss/admin/_variables.scss` (or frontend equivalent); no hardcoded colors, spacing, font sizes, or transition values
- **No raw values in components** — if a needed token doesn't exist in `_variables.scss`, add it there first, then use it
- **Одна лестница токенов на весь проект** — имя ступени значит ОДИН размер/вес во всех бандлах: кегли `$font-size-2xs 10 / -code 11 / -xs 12 / -sm 13 / -base 14 / -md 16 / -lead 20 / -lg 22 / -xl 24 / -2xl 28`, отступы `$spacing-xs 4 / -sm 8 / -md 12 / -lg 16 / -xl 20 / -2xl 24 / -3xl 28 / -4xl 32`, радиусы `$border-radius-sm 4 / -md 6 / -lg 8 / -xl 12 / -2xl 16 / -pill 999px`, веса `$font-regular 450 / $font-semibold 600 / $font-bold 700`. Ядро — `shared/_tokens.scss`; `frontend/_variables.scss` общие ступени **форвардит**, а не переопределяет, и добавляет только свои (крупные кегли/отступы, `$border-radius-2xl`, `$border-radius-pill`). Новая ступень → сначала в ядро, потом использование
- **Публичные страницы знают два веса** — `$font-normal` 400 и `$font-medium` 500: ядровые `$font-semibold`/`$font-bold` во `frontend/_variables.scss` намеренно НЕ форвардятся. Ошибка «Undefined variable: $font-bold» чинится заменой использования, а не возвратом токена в `@forward`
- **Одноимённое ≠ разное** — если значение расходится по доменам, имя обязано различаться: `$font-code` (моноширинный публичных страниц) vs `$font-mono` (кабинет/плеер), `$shadow-surface` vs `$shadow-card`, `$line-height-relaxed` 1.6 vs `$line-height-base` 1.5
- **JS не задаёт стили** — состояния переключаются классами (`.is-loading`, `.is-deleting`, `.fs-parent-action`), показ/скрытие — атрибутом `hidden`, а не `style.display`
- **Один физический цвет — одно объявление** — сырые оттенки (`$hue-violet`, `$hue-violet-dk`, `$hue-red`, `$hue-red-soft`, `$hue-amber-dk`) объявлены в `shared/_tokens.scss`; палитры (типы шагов, чипы, cabinet-тема, подсветка кода) ссылаются на них, а не копируют hex. На публичных страницах приглушённые оттенки текста выражены альфой основного цвета (`rgba($color-text, $alpha-*)`), а не отдельными hex — ховер меняет альфу
- **stylelint обязателен**: `npm run lint:css` (авто-фикс — `npm run fix:css`); конфиг — `.stylelintrc.json`. Входит в `npm run ci`
- **Цвета в компонентах** — только `var(--…)` / `$token` (правило `scale-unlimited/declaration-strict-value` — **error**). Hex разрешён только в `_variables.scss`, `shared/_tokens.scss`, `shared/cabinet/_theme.scss`, `shared/_chip-palette.scss`; полупрозрачные `rgba()`-оверлеи/тени в компонентах допустимы
- **profile + player** — общая тема `shared/cabinet/_theme.scss` (один `:root`, словарь статусов `--ok/--err/--wait`) и примитивы `shared/cabinet/_ui.scss` (`.prof-btn` ≡ `.b`, тост, карточка). Цвета типов шагов — `$step-type-palette` в `shared/_tokens.scss` (JS-зеркало: `src/js/player/icons.js` TYPES)
- **`!important`** — только в utility-классах (`common/_widths.scss`) и для перебивания WP-core, всегда с комментарием-причиной
- **Вложенность** ≤ 4 уровней; deprecated `@import` запрещён (только `@use`/`@forward`)
- **`rem()`** (`shared/_tokens.scss`) переводит макетные px в rem с базой 16 — базу не менять: файл общий для всех бандлов, правка масштабирует весь проект. Нужен другой размер — правится конкретный токен, а не функция
- План рефактора стилей (rem, адаптив, слияние profile+player) — `refactor.md`
