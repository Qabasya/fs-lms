## Новые баги
1.
2.

---

# Эпик 16 — Контрольные и ЕГЭ: правила оценки + настраиваемый интро-шаг

Три требования:
1. **Контрольная** — сколько угодно заданий, все на одной странице, время и попытки по желанию, **1 задание = 1 балл** без фильтрации по типам.
2. **ЕГЭ / КЕГЭ** — **строго заданное число заданий = число термов** таксономии `{key}_task_number` (одно задание на каждый номер). Жёсткая проверка (пример: КЕГЭ всегда ровно 27). Сейчас проверка мягкая.
3. **Настраиваемый шаг-описание перед заданиями** — вынести стартовый экран в отдельный, декуплированный от рендера и подключаемый через фильтр «интро-шаг», чтобы менять его в одном месте.

## Что уже есть (базис, не переписывать)

- Тип экзамена — enum `Inc\Enums\Assessment\AssessmentKind` (`Control` / `Ege` / `EgeComputer`) с предикатами `usesWeightedScore()`, `expandsComposites()`, `needsCompletenessCheck()`, `answersOnly()`.
- Работа = CPT `{key}_assessments`; конфиг — сериализованный `fs_lms_meta` (`kind`, `time_limit_minutes`, `max_attempts`, `pass_score`, `score_map`, `task_ids`, `task_points`). DTO — `Inc\DTO\Assessment\AssessmentDTO` (`readonly`, `fromPost()`, `maxPrimary()`).
- Набор заданий — авторский упорядоченный `task_ids` (не рандом, не по термам): метабокс «Конструктор контрольной» → `AssessmentAuthorCallbacks::ajaxSaveAssessmentItems` → `AssessmentManager::setItemIds`.
- Оценивание — `Inc\Services\Assessment\AutoGradeService::gradeAttempt()`: идёт по полному `taskIds`, чекер на задание через `TaskCheckerRegistry::get($template)` → `CheckResult{isCorrect, score, maxScore}`; manual-задание (чекер `null`) → `max = sum(task_criteria.max_points)` или `1.0`, уходит на ручную проверку. `finalize()` — пересчёт после ручной оценки.
- ЕГЭ-полнота — `Inc\Services\Assessment\EgeCompletenessChecker` (`getMissingTaskNumbers`/`isComplete`) — **мягкое предупреждение, сохранение не блокирует**.
- Рендер попытки — `Inc\Controllers\Pages\AssessmentPageController::loadTemplate()`; дефолт `templates/frontend/assessment/attempt.php`; скины через фильтр `RENDERER_FILTER = fs_lms_assessment_renderer`; шелл-пейджа отмечается `ROUTE_FILTER`, КЕГЭ — `KEGE_ROUTE_FILTER`.
- Стартовый экран уже существует: `attempt.php:215-222` — голая кнопка «Начать контрольную» + шапка-мета (`attempt.php:19-33`). Настраиваемого описания нет.
- КЕГЭ-модуль (`Inc\Modules\EgeComputer`) уже имеет свой контент-декуплированный интро: `KegeSlidesConfig::slides()` + `templates/frontend/assessment/kege/entry.php`. Число заданий КЕГЭ = `count($assessment->taskIds)` (константы 27 в коде нет — это авторская договорённость: 27 термов у предмета).

## Решения (УТВЕРЖДЕНЫ 2026-07-06, интерактивно)

- **D16.1** ✅ Контрольная: при `kind = Control` балл **нормируется в бинарный 1/0 на задание** — верно→1, иначе→0, `max = 1` независимо от типа задания и внутреннего `maxScore` чекера. Частичный балл и критерии не учитываются (только для ЕГЭ). Manual-задание (развёрнутый ответ) → `max = 1`, преподаватель ставит **0/1** («верно/неверно»), критерии игнорируются. Итоговый максимум работы = `count(taskIds)`.
- **D16.2** ✅ ЕГЭ/КЕГЭ: строгая полнота = **биекция задание↔номер 1:1**: ровно одно задание на каждый терм `{key}_task_number`, все номера покрыты, без дублей и «лишних». `count(taskIds) === count(terms)` — следствие.
- **D16.3** ✅ Жёсткий контроль ЕГЭ в **двух точках**: (а) **блок публикации** незавершённой работы (publish→revert to draft + notice); черновик сохраняется свободно; (б) **блок старта** попытки (`AttemptService::start`) для незавершённой ЕГЭ-работы. Контрольную (`Control`) не касается.
- **D16.4** ✅ Интро-шаг: конвейер стадий `[intro] → [tasks] → [result]`; интро — партиал, подключается новым фильтром `INTRO_FILTER = fs_lms_assessment_intro` (зеркало `RENDERER_FILTER`). Контент: per-work **WYSIWYG-поле `intro_html`** (`wp_editor`, санитайз `sanitizeEditorContent`) **переопределяет** дефолты из `AssessmentIntroConfig` (паттерн `KegeSlidesConfig`); блок правил (время/попытки/N/проходной) — авто из DTO.
- **D16.5** ✅ Конструктор ЕГЭ в админке — **полная переделка на N фиксированных слотов** (по числу термов), одно задание своего номера в слот, живой индикатор «заполнено X/N», подсветка пропусков/дублей.
- **D16.6** ✅ КЕГЭ-ритуал (`kege/entry.php` + `KegeSlidesConfig` + станция-навигатор) **НЕ трогаем** — остаётся на своём контракте. Новый интро-шаг применяется только к `Control` и обычному `Ege`.
- **D16.7** ✅ Обычный `Ege` (не КЕГЭ) студенту показывается **станцией-навигатором** (одно задание на экран + боковое меню номеров), а не одностраничным списком. Контрольная (`Control`) — одностраничный список (как сейчас, `attempt.php`). Навигатор — **core** (Ege — ядровый kind, не модуль); при необходимости общий с КЕГЭ код вынести в core-примитив позже, КЕГЭ пока не рефакторить.

---

## Задачи

### Часть A. Контрольная: 1 задание = 1 балл (D16.1)

- **T16.1** В `AssessmentKind` добавить явный предикат режима оценки, напр. `binaryScoring(): bool` → `true` для `Control`, `false` для `Ege`/`EgeComputer` (не полагаться на инверсию `usesWeightedScore()` — читаемость). Покрыть тестом enum.
- **T16.2** `AutoGradeService::gradeAttempt()`: при `assessment->kind->binaryScoring()`:
  - auto-задание → `score = isCorrect ? 1.0 : 0.0`, `maxScore = 1.0` (игнорировать `CheckResult::maxScore`/partial);
  - manual-задание → `maxScore = 1.0` (без суммы критериев), `hasManual = true`;
  - без фильтрации по шаблону/типу — каждое задание из `taskIds` весит ровно 1.
  Для `Ege`/`EgeComputer` — прежнее поведение (взвешенное, критерии, `score_map`).
- **T16.3** `AutoGradeService::finalize()`: при `binaryScoring` пересчитывать max ручного задания как `1.0` (иначе после ручной оценки max «поедет» с текущей логики критериев). Итог по контрольной = сумма 0/1.
- **T16.4** Тесты `AutoGradeServiceTest`: контрольная из смешанных типов (авто + развёрнутый ответ + композит) → max == число заданий, каждое даёт 0/1; ЕГЭ-регрессия не сломана.
- **T16.5** Подтвердить: `attempt.php:31` «Заданий: N» и `maxPrimary()` для Control уже дают `count(taskIds)` — правок в шаблон результата не требуется (max берётся из попытки после автопроверки, T13.7). Только сверить визуально.

### Часть B. ЕГЭ/КЕГЭ: строгое число заданий (D16.2, D16.3)

- **T16.6** Расширить `EgeCompletenessChecker` жёстким валидатором, напр. `validate(AssessmentDTO, string $subjectKey): EgeCompletenessResult` (новый маленький DTO/структура), возвращающим: `missing[]` (номера без задания), `duplicated[]` (номер с >1 заданием), `orphans[]` (task_id без номера или номер вне термов), `expectedCount`, `actualCount`, `isStrictlyComplete: bool`. Старые `getMissingTaskNumbers/isComplete` оставить как обёртки (мягкий слой КЕГЭ-навигатора не ломать).
- **T16.7** Блок публикации: в `AssessmentMetaBoxController` (или отдельном `AssessmentPublishGuard` на `save_post_{key}_assessments` / `wp_insert_post_data`), если `kind` из `needsCompletenessCheck()` и `!isStrictlyComplete` — не дать статус `publish` (вернуть в `draft`) и показать admin-notice со списком missing/duplicated/orphans. Черновик сохраняется свободно. Правило через Manager/Repository, без прямых WP-вызовов в контроллере.
- **T16.8** Блок старта: `AttemptService::start()` — при `kind->needsCompletenessCheck()` и `!isStrictlyComplete` бросать/возвращать доменную ошибку «работа не укомплектована», не создавать попытку. Проверить, что фронт (`assessment.js` / `kege-entry.js`) показывает сообщение из ответа.
- **T16.9** Конструктор ЕГЭ (метабокс «Конструктор контрольной», `renderBuilderContent` + его JS) — **полная переделка на N фиксированных слотов** (D16.5): рендерить ровно N слотов = числу термов (`data-ege-slots`), в каждый слот кладётся одно задание своего номера; живой индикатор «заполнено X/N», подсветка пропусков и дублей; кнопку «готово/опубликовать» гейтить до строгой полноты. Вердикт полноты — от `EgeCompletenessChecker::validate` через ajax (не дублировать логику в JS). КЕГЭ-станцию НЕ трогать (D16.6).
- **T16.10** `ajaxSaveAssessmentItems` (`AssessmentAuthorCallbacks`): для ЕГЭ-типов отклонять дубли номера и задания без номера ещё на сохранении состава (или сохранять, но возвращать вердикт полноты в ответе для индикатора). Санитайзеры — через trait (см. память: передавать имя ключа `$_POST`, не значение).
- **T16.11** Тесты: `EgeCompletenessCheckerTest` (missing/duplicated/orphans/полный кейс на 27 номерах), `AttemptServiceTest` (старт ЕГЭ блокируется при неполноте, Control — нет), guard-тест публикации.

### Часть B2. Презентация обычного ЕГЭ станцией-навигатором (D16.7)

- **T16.11a** Для `kind = Ege` (не КЕГЭ) добавить **core-рендерер станции-навигатора**: одно задание на экран + боковое меню номеров (переиспользует `buildTaskViews` → `taskNumber`). Подключить через существующий `RENDERER_FILTER` дефолтом ядра для Ege (не через модуль). `Control` остаётся на `attempt.php` (одностраничный список).
- **T16.11b** JS/SCSS навигатора — **core-бандл** (`src/js/assessment/…`, `src/scss/assessment/…`), НЕ зависеть от модульного `kege-exam.js`. Общий с КЕГЭ код (навигатор/таймер/панель ответа) при желании вынести в core-примитив позже; сейчас КЕГЭ не рефакторим (D16.6). Контракт AJAX — тот же (`saveAnswer`/`submitAttempt`/`getAttemptResult`).
- **T16.11c** Тест-проход: Ege рендерится навигатором, ответы сохраняются, сабмит и результат работают; Control по-прежнему одностраничный.

### Часть C. Настраиваемый интро-шаг перед заданиями (D16.4)

- **T16.12** Ввести конвейер стадий в `AssessmentPageController::loadTemplate()`: явные партиалы `[intro] → [tasks] → [result]`. Вынести стартовый экран из `attempt.php:215-222` в отдельный `templates/frontend/assessment/attempt-intro.php` (заголовок + описание работы + блок правил: время/попытки/число заданий/проходной + кнопка «Начать»). `attempt.php` включает интро только в ветке «нет активной/последней попытки».
- **T16.13** Фильтр подключения интро `AssessmentPageController::INTRO_FILTER = 'fs_lms_assessment_intro'` (сигнатура как у `RENDERER_FILTER`: `($defaultPartial, $kind, $subjectKey)`), чтобы модуль/скин мог заменить шаг целиком (КЕГЭ уже имеет свой `kege/entry.php` — при желании перевести его на этот же контракт).
- **T16.14** Контент интро декуплировать: класс `Inc\Services\Assessment\AssessmentIntroConfig` (паттерн `KegeSlidesConfig`) — дефолтные подписи/структура правил в одном месте; per-work переопределение через новое meta-поле.
- **T16.15** Новое meta-поле `intro_html` (описание работы) — **WYSIWYG через `wp_editor`** (новый `EditorField` или переиспользовать имеющийся редакторный филд) в `AssessmentTemplate::__construct()` (settings-метабокс). Сохранение через merge в `fs_lms_meta`; санитайз — `sanitizeEditorContent()` (trait `Sanitizer`). Прокинуть в `AssessmentDTO` (`introHtml`) и в `attempt-intro.php` (`wp_kses_post`); пусто → дефолты `AssessmentIntroConfig`.
- **T16.16** SCSS интро-шага — только токены (`src/scss/assessment/…`), без инлайн-стилей; общие примитивы кабинета. Пересобрать `assessment.min.css`.
- **T16.17** Задокументировать конвейер стадий и оба фильтра (`RENDERER_FILTER`, `INTRO_FILTER`) в шапке `AssessmentPageController` — чтобы «менять шаг до заданий» было тривиально (одна запись/один фильтр).

### Кросс

- **T16.18** `docs`/CLAUDE-заметок не требуется; обновить только память по итогам. Прогнать `npm run ci` (ESLint + stylelint) и PHPUnit (текущий зелёный набор) — часть A/B добавляет тесты, регрессия ЕГЭ и Control не должна падать.
- **T16.19** Проверка на живой среде: контрольная со смешанными типами (max = число заданий, 0/1 на каждое); ЕГЭ с одним пропущенным номером — публикация и старт заблокированы, notice со списком; интро-шаг с заполненным `intro_html` рендерится перед заданиями и меняется правкой одного поля.
