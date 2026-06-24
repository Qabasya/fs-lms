# План рефакторинга

Источник: анализ изменений после коммита `c7547f8` (7 коммитов + рабочее дерево, ~148 файлов).

## Соответствие архитектуре

Базовые правила CLAUDE.md соблюдены: слои чистые, AJAX через `Nonce`/`Sanitizer`/репозитории, DI. Нарушения сосредоточены в слое JS-конструкторов и PHP-валидации публикации — это DRY/SOLID, не нарушение слоёв.

Эталоны (не трогать): `TaskCheckerRegistry` + `Checkers/*` (Strategy + DI), `SubmitTaskAnswerCallbacks` (корректные nonce/Sanitizer/репозитории).

## Находки

| # | Severity | Что | Где |
|---|----------|-----|-----|
| 1 | HIGH | `work-builder.js` ≈ `assessment-builder.js` на ~85% (идентичны layout, `render/renderLeft/renderCenter`, `renderEditorTop`, `renderTaskContent`, `renderEmptySlot`, `renderActions`, `openCreateForm`, `setStatus`, `addSlot/removeSlot/assignTask`) | `src/js/admin/services/work-builder.js`, `assessment-builder.js` |
| 2 | HIGH | Знание о структуре полей утроено: PHP `Fields/*`, `task-fields.js`, `task-editor.js`. Radio-эксклюзивность и WP-audio-picker реализованы независимо в каждом | `inc/MetaBoxes/Fields/*`, `src/js/admin/services/task-fields.js`, `task-editor.js` |
| 3 | MED | Кросс-модульная связанность: `WorkBuilder.postAssessment()` берёт чужой `authorAssessment` nonce + endpoint `getTaskPreview`. `postWork`/`postAssessment` отличаются только nonce | `src/js/admin/services/work-builder.js:341-370` |
| 4 | MED | Дублирование валидации публикации (skip autosave → check status → check title → `getSoftError` → transient → force draft) + парный `showPublishError` | `inc/Callbacks/Subject/SubjectValidationCallbacks.php:57`, `inc/Controllers/Problems/ProblemsController.php:263` |
| 5 | MED | Мёртвый код: `work-step-editor.js` не импортируется нигде (заменён `WorkBuilder`) | `src/js/admin/services/work-step-editor.js` |
| 6 | MED | CSS-связанность: `work-builder.js` и разметка `WorkMetaBoxController` переиспользуют `fs-ab-*` (assessment) и `.builder/.tree-pane/.fs-lms-cb-wrap` (course) | `src/js/admin/services/work-builder.js`, `inc/Controllers/Course/WorkMetaBoxController.php:107` |
| 7 | LOW | В Checkers повторяется хвост «all-or-nothing + per-item feedback» | `inc/Services/Task/Checkers/{Matching,Ordering,Fill}Checker.php` |
| 8 | LOW | PHP repeatable-rows scaffold (ul + add + `<script type=template>`) повторён | `inc/MetaBoxes/Fields/{Options,Pairs,OrderItems}Field.php` |
| 9 | LOW | Магические строки `'ege'/'ege_computer'/'control'` в JS дублируют PHP `AssessmentKind` enum | `src/js/admin/services/assessment-builder.js:71,99` |

> Примечание к #2: `task-editor.js` решает radio-эксклюзивность через общий `name="fste-correct-${key}"` (нативная группировка), PHP-путь использует уникальные `name` и требует JS-фикса в `task-fields.js`. Одна задача — два решения: следствие утроения.

## Этапы

### Этап 1 — быстрые победы (низкий риск) ✅
- [x] Удалить `work-step-editor.js` (#5). Импортёров нет — проверено.
- [x] Схлопнуть `postWork`/`postAssessment` в один `post(action, nonce, data)` (#3). _Живёт в `slot-builder.js` (этап 2)._
- [x] Вынести `'ege'/'ege_computer'/'control'` в общий конфиг/`data`-атрибут из PHP `AssessmentKind` (#9). _`AssessmentKind::weightedScoreValues()` → `data-ege-kinds`._

### Этап 2 — общий slot-builder (#1, #6) ✅
- [x] Создать `services/slot-builder.js` — фабрика `createSlotBuilder(el, config)` (прецедент: `createStepEditor` в `step-editor.js`).
- [x] Параметры config: подписи, `persist`, `search`, `createTask`, `preview`, опц. хуки `renderExtraBody` (баллы ЕГЭ), `onReady` (смена вида).
- [x] `WorkBuilder` (~46 стр.) и `AssessmentBuilder` (~155 стр.) → тонкие конфиги вместо 388/477.
- [x] Единый SCSS-партиал `_slot-builder.scss`, неймспейс `fs-sb-*`; assessment/work переведены на него (убирает #6). PHP-обёртки → `.fs-sb-wrap`.

### Этап 3 — единый источник PHP-валидации (#4) ✅
- [x] Сервис `TaskPublishGuard`: `enforce(array $data, string $prefix, string $emptyTitleError, callable $resolveError): array` + `renderDeferredError(string $prefix, string $heading)`. Применимость по типу поста и доменная проверка остаются у вызывающего.
- [x] `SubjectValidationCallbacks` и `ProblemsController` делегируют ему. Выносит бизнес-логику из контроллера.

### Этап 4 — единый источник для полей (#2, самый дорогой) ✅ — путь A
- [x] Поведение полей теперь в одном месте: `task-fields.js` стал переиспользуемым (`init(root)`), модалка навешивает его на тот же HTML (repeatable-rows / radio / audio — одна реализация вместо двух).
- [x] **Путь A**: модалка рендерит поля через PHP-HTML по AJAX (`GetTaskEditorForm` → `BaseTemplate::render()` с болванкой `WP_Post(0)`), отправляет `fs_lms_meta[...]` тем же путём `MetaBoxManager::saveFields()`. Из `task-editor.js` удалены ~250 строк JS-построения полей. Источник истины зафиксирован в `CLAUDE.md`.

### Опционально
- [ ] `CheckResultDTO::fromFeedback(array $feedback)` (#7).
- [ ] PHP-база `RepeatableRowsField` (#8).

## Рекомендация

Начинать с этапов 1–3 (ощутимый эффект, низкий риск, ~6 файлов). Этап 4 требует отдельного решения по источнику истины.
