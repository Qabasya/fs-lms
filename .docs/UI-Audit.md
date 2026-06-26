# UI-Audit.md — Аудит стилей (Фаза 0)

Дата: 2026-06-26  
Область: `src/scss/admin/` (26 файлов, 3572 строки) + `templates/admin/` + `src/js/admin/`  
Не трогаем: `src/scss/frontend/`, `src/scss/common/`, `templates/frontend/`

---

## 0.1 — Инвентаризация классов SCSS

### Мёртвые SCSS-классы (определены, нигде не используются)

| Класс | Файл SCSS | Примечание |
|---|---|---|
| `.fs-adsync-dep-warning` | `_config.scss:172` | В модуле AdSync есть только `fs-adsync-env-label` — dep-warning нет ни в шаблоне, ни в JS |
| `.fs-cb-mat-actions` | `_step-editor.scss:99` | Не найден ни в одном шаблоне, ни в JS |
| `.fs-consent-tab` | `_email-templates.scss:118` | Не встречается нигде |
| `.fs-detail-grid` | `_modal.scss:792` | Блок detail-grid/row/label/value — нет ни в PHP, ни в JS |
| `.fs-detail-row` | `_modal.scss:801` | — |
| `.fs-detail-label` | `_modal.scss:804` | — |
| `.fs-detail-value` | `_modal.scss:805` | — |
| `.fs-flex-row` | `_utilities.scss:20` | Нет ни в шаблонах, ни в JS |
| `.fs-mt-lg` | `_utilities.scss:10` | Нет ни в шаблонах, ни в JS (`fs-mt-xl` — в одном шаблоне, остальные — живые) |

### Мёртвый SCSS-блок в живом файле

**`_subject-tabs.scss` строки 8–63** — блок `.fs-lms-dashboard > .fs-tabs` (checkbox-based accordion-tab система на radio-кнопках). Ни один шаблон не использует класс `fs-tabs` в admin; subject-страница использует нативный WP `nav-tab-wrapper + GET ?tab=`. Этот блок является нереализованным экспериментом. Удалить строки 8–63, оставить только `.row-actions` (строки 70–77) — он живой (WP-таблицы).

### Дубликат `_toast.scss`

`src/scss/admin/components/_toast.scss` — побайтовый дубликат `src/scss/common/components/_toast.scss` (разница только в пути `@use`). JS-реализация `toast.js` живёт в `src/js/common/`, `common.min.css` подключается на всех страницах наряду с `admin.min.css` — стили дублируются. **Удалить `src/scss/admin/components/_toast.scss`**, убрать его из `src/scss/admin/admin.scss`.

### Живые JS-DYNAMIC классы (в SCSS, только в JS, не в шаблонах PHP)

Все `.fs-cb-*`, `.fs-te-*`, `.fs-sb-*`, `.fs-lms-ref-*`, `.fs-task-*-field`, `.fs-se` — рендерятся в строках JS (builders/modals). Это **JS-DYNAMIC**: статус KEEP, автопроверка их как мёртвых невозможна.

### Живые STATE-классы (добавляются в JS)

`.fs-pfield--editing`, `.fs-dragging` — добавляются через JS; в шаблонах PHP не встречаются. STATUS: KEEP.

### Полная сводка по статусам SCSS-классов

| Статус | Количество | Примеры |
|---|---|---|
| LIVE (в шаблоне PHP) | ~85 | `.fs-table`, `.fs-form-group`, `.fs-lms-modal`, `.fs-close` |
| JS-DYNAMIC (только в JS) | ~55 | Все `.fs-cb-*`, `.fs-te-*`, `.fs-sb-*`, `.fs-lms-ref-*` |
| STATE (добавляются JS) | ~10 | `.fs-pfield--editing`, `.fs-dragging`, `.fs-lms-modal-*` BEM-модификаторы |
| DEAD | 9 классов + 1 блок | Перечислены выше |
| ДУБЛИКАТ | `_toast.scss` (82 строки) | Полный дубликат common |

---

## 0.2 — Мёртвая разметка (классы в шаблонах без SCSS)

### Критические — нет ни SCSS, ни JS

| Класс (в шаблоне) | Шаблон | Диагноз |
|---|---|---|
| `fs-modal__overlay`, `fs-modal__container`, `fs-modal__header`, `fs-modal__title`, `fs-modal__close`, `fs-modal__body`, `fs-modal__footer` | `consent-definition-modal.php` | Вторая, несовместимая модальная система (BEM `__`). Нет SCSS, нет JS-обработчика. Единственный модал в этом стиле. Мигрировать на `fs-lms-modal-*` или написать SCSS. |
| `fs-cb-heading-row` | `course-builder.php:17` | Нет SCSS, нет JS. Пустой div-обёртка. Удалить класс или добавить стили. |
| `fs-form-group--full` | `application-enrollment-modal.php:162` | BEM-модификатор к живому `.fs-form-group`, но сам `--full` не определён в `_modal.scss`. Добавить `&--full { ... }`. |
| `fs-person-pii` | student/parent-person-modal.php (7 мест) | Нет SCSS, нет JS (в `src/js/admin/`). Вероятно, читается callbacks по `data-field`. Needs SCSS (PII-поле должно визуально отличаться). |
| `fs-person-reveal-bar` | student/parent-person-modal.php (2 места) | Нет SCSS, нет JS. Бар раскрытия PII без стилей. |
| `fs-students-bulk-bar` | userlist tabs (4 места) | Нет SCSS, нет JS. Только WP-нативные стили через `tablenav top`. |
| `fs-view-field` | archive-view-modal.php (59 мест!) | Нет SCSS, нет JS в `src/js/admin/`. Целевой JS может быть в callbacks PHP. Нужна проверка. |

### Модификаторы без SCSS-правила

| Класс (в шаблоне) | Шаблон | Базовый класс |
|---|---|---|
| `fs-dashicon--danger` | subject-4-taxonomies.php, boilerplate-editor.php | `.fs-dashicon` есть в `_icons.scss:5`, но модификаторы `--danger` и `--muted` не определены |
| `fs-dashicon--muted` | subject-4-taxonomies.php, settings-3-periods.php | — |
| `fs-config-section--dadata` | DaData/templates/settings-section.php | `.fs-config-section` есть, модификатор `--dadata` нет (только `--keys` определён) |
| `fs-config-section--adsync` | AdSync/templates/settings-section.php | — |
| `fs-config-section--smart-captcha` | SmartCaptcha/templates/settings-section.php | — |
| `fs-config-section--applications` | settings-7-config.php | — |
| `fs-lms-status--` (dynamic) | userlist-1-applications.php | `.fs-lms-status` с модификаторами `--pending-parent`, `--ready-for-review`, `--enrolling`, `--expired`, `--trash` — все определены в `_application-status.scss`. OK. |

### JS-hook классы без SCSS (функциональные, нужны для JS-таргетинга)

Следующие классы не нуждаются в SCSS — они являются JS-якорями:
`fs-enr-field`, `fs-quick-edit-form`, `fs-quick-edit-row`, `fs-enc-key-output`, `fs-enc-key-value`, `fs-hash-salt-output`, `fs-hash-salt-value` — все есть в JS (`src/js/admin/`).

---

## 0.3 — Аудит токенов (сырые значения)

### Цвета

**Токены существуют, но в компонентах — литералы:**

| Сырое значение | Кол-во | Файлы | Канонный токен |
|---|---|---|---|
| `#fff` | 5× | `_auth-manager.scss:139`, `_task-editor.scss:96`, `_config.scss:9`, `_modules-dashboard.scss:24`, `_modal.scss:525` | `$color-bg-modal` (=#ffffff) |
| `#f0f0f1` | 1× | `_modal.scss:604` (inline `code {}`) | `$cb-bg-hover` (=#f0f0f1, точное совпадение!) |
| `#008a00` | 1× | `_modules-dashboard.scss:69` (цвет "активен") | Снапить к `$wp-admin-green` (=#008a20, разница 1 оттенок) |
| `#fafafa` | 1× | `_modules-dashboard.scss:74` | Снапить к `$wp-admin-gray-bg-light` (=#f6f7f7, близко) |

**rgba() без SCSS-переменных:**

| Значение | Кол-во | Файлы | Рекомендация |
|---|---|---|---|
| `rgba(0,0,0,0.04)` | 2× | `_auth-manager.scss:21`, `_email-templates.scss:19` | Добавить токен `$shadow-card` |
| `rgba(0,0,0,0.18)` | 1× | `_toast.scss:26` | Добавить токен `$shadow-toast` |
| `rgba(0,0,0,0.25)` | 1× | `_ref-selector.scss:138` (backdrop) | Одиночный, литерал с комментарием |
| `rgba($variable, N)` | 12× | `_modal.scss` | OK — переменная-база, только opacity сырая |

### Border-radius

**Токены `$border-radius-sm: 3px`, `$border-radius-md: 4px`, `$border-radius-l: 6px` есть. Не хватает `lg`:**

| Сырое значение | Кол-во | Файлы | Рекомендация |
|---|---|---|---|
| `8px` | 4× | `_auth-manager.scss:20`, `_email-templates.scss:18,129,167`, `_logs.scss:16` | **Добавить `$border-radius-lg: 8px`** |
| `6px` | 1× | `_auth-manager.scss:138` | Заменить на `$border-radius-l` |
| `5px` | 2× | `_slot-builder.scss:59`, `_course-builder.scss:92,115,143` | Снапить к `$border-radius-l: 6px` (разница 1px) |
| `12px` | 2× | `_slot-builder.scss:81`, `_course-builder.scss:148` (badge радиусы) | Добавить `$border-radius-pill: 12px` или использовать `50px` |
| `4px` | 1× | `_modal.scss:604` (inline `code {}`) | `$border-radius-md` |
| `50%` | 2× | `_modal.scss:580,627` (аватары) | OK, семантически другой вид |

### Box-shadow

**`$shadow-modal` уже есть. Нужно добавить:**

```scss
$shadow-card:  0 1px 3px rgba(0, 0, 0, 0.04);  // auth-card + email-template-card
$shadow-toast: 0 2px 12px rgba(0, 0, 0, 0.18);  // toast
```

`box-shadow: none` (6×) — OK, не токенизировать.

### Transition

**`$transition-fast: 0.2s ease-out` и `$transition-default: all $transition-fast` уже есть:**

| Сырое значение | Кол-во | Файлы | Рекомендация |
|---|---|---|---|
| `border-color 0.15s` | 3× | `_task-editor.scss:98`, `_tables.scss:146,168`, `_modal.scss:1049` | Заменить на `border-color $transition-fast` |
| `background 0.15s ease-in-out` | 2× | `_tables.scss:146,168` | `background $transition-fast` |
| `border-color 0.2s ease` | 1× | `_auth-manager.scss:23` | `border-color $transition-fast` |
| `transform 0.12s, box-shadow 0.12s` | 1× | `_step-editor.scss:21` (drag) | Одиночный, оставить литерал |

### PX значения — кандидаты на токенизацию (3+ вхождений)

| Значение | Кол-во | Существующий токен | Действие |
|---|---|---|---|
| `13px` | 31× | — (ближайший `$font-size: 14px`) | **Добавить `$font-size-wp: 13px`** — WP-базовый размер текста в таблицах |
| `12px` | 21× | `$spacing-md: 12px` ✓ | Заменить на `$spacing-md` |
| `6px` | 18× | `$border-radius-l: 6px` ✓ | Контекстно-зависимо: где border-radius — токен; где padding — отсутствует, добавить `$spacing-xs2: 6px` или снапить к `$spacing-sm: 8px` |
| `11px` | 17× | `$font-size-code: 11px` ✓ | Заменить на `$font-size-code` |
| `16px` | 13× | `$spacing-lg: 16px` ✓ | Заменить на `$spacing-lg` |
| `14px` | 11× | `$font-size: 14px` ✓ | Заменить на `$font-size` |
| `8px` | 7× | `$spacing-sm: 8px` ✓ | Заменить на `$spacing-sm` |
| `40px` | 6× | — | Высоты элементов управления: добавить `$input-height: 40px` |
| `320px` | 6× | — | Распространённый max-width полей: добавить `$field-max-width: 320px` |

Значения `1px`, `2px` (borders, outlines) — **не токенизировать**, это нормальные raw значения.

### Итог: новые токены для добавления в `_variables.scss`

```scss
// Радиусы (дополнение)
$border-radius-lg:   8px;
$border-radius-pill: 12px;

// Тени компонентов
$shadow-card:  0 1px 3px rgba(0, 0, 0, 0.04);
$shadow-toast: 0 2px 12px rgba(0, 0, 0, 0.18);

// Типографика
$font-size-wp: 13px;    // базовый WP-текст в таблицах

// Поля ввода
$input-height:    40px;
$field-max-width: 320px;
```

---

## 0.4 — Карта консолидации

### Поля формы (5 → 1)

| Класс | Файл SCSS | Использований в шаблонах | Использований в inc/ | Контекст |
|---|---|---|---|---|
| `.fs-form-group` | `_modal.scss:271` | **176** | 4 | Основной класс модальных форм; `margin-bottom`, label, input, select, textarea |
| `.fs-form-row` | `_modal.scss:776` | 64 | 0 | Двухколоночный ряд внутри `.fs-form-group` |
| `.fs-lms-form-group` | `_auth-manager.scss:119` | 6 | 6 | Auth-карточка: flex-column, gap, border-radius на input |
| `.fs-config-field` | `_config.scss:52` (nested) | 10 | 7 | Config-секция: поле с label-inline + help-link |
| `.fs-lms-field-group` | *(нет standalone SCSS)* | 0 | **15** | MetaBoxes/Fields wrapper; нет собственных стилей кроме `:last-child` в assessment |

**Цель:** `.fs-field` (одиночное поле) + `.fs-field-row` (двухколоночный ряд).  
Модификаторы: `--full` (100% ширина), `--narrow` (320px max), `--inline` (label + control в строку).

### Карточки/контейнеры (3 → 1)

| Класс | Файл SCSS | Использований в шаблонах | Структура (заголовок + тело + тень) |
|---|---|---|---|
| `.fs-lms-auth-card` | `_auth-manager.scss:17` | 33 | `bg:white; border:1px; border-radius:8px; box-shadow:0 1px 3px; overflow:hidden` + BEM-элементы |
| `.fs-email-template-card` | `_email-templates.scss:15` | 10 | Идентично auth-card (тот же background/border/radius/shadow) |
| `.fs-config-section` | `_config.scss:5` (nested) | 17 | `bg:white; border:1px; border-radius:6px; padding:$spacing-xl` — без тени |

Структура `fs-lms-auth-card` и `fs-email-template-card` полностью совпадает (border, radius 8px, shadow). `.fs-config-section` отличается только отсутствием тени и меньшим radius (6px).

**Цель:** `.fs-card` (базовая карточка, radius 8px, shadow-card) + модификатор `--flat` (без тени) или просто `.fs-section` (для config-секций с padding).

### Кнопки

| Текущий класс | Использование | Куда |
|---|---|---|
| `.fs-btn-link-sm` | `_utilities.scss:34`, 3 шаблона | `.fs-btn--link-sm` |
| `button-link .button` ad-hoc | Разные шаблоны | `.fs-btn` + вариант |

---

## Итог: что удаляем прямо сейчас (до Фазы 1)

### Удалить из SCSS

- [ ] Строки 8–63 в `_subject-tabs.scss` (мёртвый fs-tabs-блок)
- [ ] `src/scss/admin/components/_toast.scss` целиком + строку `@use` в `admin.scss`
- [ ] `.fs-adsync-dep-warning` в `_config.scss:172`
- [ ] `.fs-cb-mat-actions` в `_step-editor.scss:99`
- [ ] `.fs-consent-tab` в `_email-templates.scss:118`
- [ ] `.fs-detail-grid/row/label/value` блок в `_modal.scss:792-835`
- [ ] `.fs-flex-row` в `_utilities.scss:20`
- [ ] `.fs-mt-lg` в `_utilities.scss:10`

### Добавить в SCSS (пустые/несогласованные)

- [ ] `.fs-form-group--full` в `_modal.scss` (модификатор: `width: 100%`)
- [ ] `.fs-dashicon--danger` и `.fs-dashicon--muted` в `_icons.scss` (цвета `$color-danger` и `$color-text-secondary`)
- [ ] Новые токены (см. §0.3 выше) в `_variables.scss`

### Мигрировать / решить

- [ ] `fs-modal__*` в `consent-definition-modal.php` — либо написать SCSS, либо переписать на `fs-lms-modal-*`
- [ ] `fs-person-pii`, `fs-person-reveal-bar` — добавить SCSS (PII-поля должны быть стилизованы)
- [ ] `fs-view-field` — проверить, есть ли JS-таргетинг вне `src/js/admin/`; если нет — styled или удалить класс

---

## Связь с UI.md

Данные этого аудита — входные данные для:

- **Фаза 1.1** — токены из §0.3
- **Фаза 1.2** — примитивы из §0.4
- **Фаза 2** — пилот: страницы Авторизация / Шаблоны писем / Конфигурация (именно их классы составляют основу дублирования)

Удаление мёртвых классов (итог выше) можно сделать **до старта Фазы 1** — это не ломает никакой функциональности.
