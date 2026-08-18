# Связка 19-21 (composite task) — декомпозиция в работах/курсе/экзамене

> **Статус:** реализация в основном сделана. §3.1, §3.3, §3.4, §4 — полностью; §3.2 и §3.5 —
> частично (см. галочки внутри разделов); §3.6 не требует правок. Осталось только: вывод
> `TripleAnswerChecker`/composite-кода из эксплуатации (намеренно отложено до подтверждения
> нулевого использования в проде, см. §3.5) и прогон миграции на самом проде (§4, последний
> пункт — нужно решение по окну простоя, см. §5.3). 1206/1206 тестов зелёные, `eslint`/
> `gulp scripts` без ошибок, вся цепочка (материализация → раскрытие в билдерах → WP-CLI
> миграция ссылок) прогнана end-to-end на реальном dev-банке через WP-CLI.
> **Дата:** 2026-08-18.
> **Связанные файлы:** `inc/MetaBoxes/Templates/ThreeInOneTemplate.php`, `inc/Enums/Subject/TaskTemplate.php`,
> `inc/Services/Task/CompositeSubItemResolver.php`, `inc/Services/Task/Checkers/TripleAnswerChecker.php`,
> `inc/Services/Course/BatchCheckService.php`, `inc/Services/Assessment/AutoGradeService.php`,
> `inc/Services/Assessment/AttemptTaskViewBuilder.php`, `inc/Services/Assessment/EgeCompletenessChecker.php`,
> `inc/DTO/Course/WorkDTO.php`, `inc/DTO/Assessment/AssessmentDTO.php`,
> `src/js/admin/services/{slot-builder,work-builder,assessment-builder}.js`, `src/js/admin/services/step-editor.js`.

## 1. Проблема

Задание-связка ЕГЭ (напр. «Связка 19-21, Теория игр») сегодня — **один пост** CPT `{key}_tasks`
шаблона `triple_task` (`ThreeInOneTemplate`) с тремя парами `task_19_condition/answer`,
`task_20_...`, `task_21_...`. Везде по стеку (Work.itemIds, Assessment.taskIds, таблицы
`submissions`/`assessment_answers`/`task_attempts`, все с `UNIQUE(attempt_id, task_id)`)
действует инвариант «1 task_id = 1 ответ = 1 строка» — связка на станции ЕГЭ визуально рисуется
как 3 подпункта, но физически это один слот с одним комбинированным баллом
(`TripleAnswerChecker`, до 3 баллов, JSON-ответ). В работах (Work) расщепления вообще нет.

## 2. Целевая модель

**Задание-связка = 1 канонический пост (parent) + 3 материализованных дочерних поста (children).**

- **Parent** (`triple_task`, как сейчас) — единственный источник контента: автор заполняет условие
  и ответ по 19/20/21 на одном экране. Это единственный пост, у которого есть **публичная
  страница** (`/{key}/trainer/{номер}/`) — на ней связка отображается как один пост/страница,
  как и сегодня.
- **Children** — 3 обычных поста CPT `{key}_tasks` шаблона `StandardTaskTemplate`
  (`task_condition`/`task_answer` как у любого обычного задания), с термом
  `{key}_task_number` = `19`/`20`/`21` каждый. Создаются и синхронизируются автоматически при
  сохранении parent-поста (upsert по связке `parent_id + суффикс`), сами по себе **не
  публикуются на витрину** — публичный роут для них гейтится/редиректит на parent (см. §3.2).
- **Work / Assessment / Lesson-step «Задача»** ссылаются на **children**, а не на parent —
  для них это 3 обычных независимых задания: свой `task_answer`, свой `TextAnswerChecker`, своя
  строка в `submissions`/`assessment_answers`/`task_attempts`. **Никаких изменений схемы БД и
  scoring-кода не требуется** — вся составная механика (`CompositeSubItemResolver`,
  `TripleAnswerChecker`, `expandsComposites()`-ветка в `BatchCheckService`/`AutoGradeService`,
  `subparts()` в `AttemptTaskViewBuilder`) со временем выводится из эксплуатации (см. §3.5).

## 3. Задачи

### 3.1 Материализация: parent → 3 children — ✅ СДЕЛАНО (2026-08-18)
- [x] `Inc\Services\Task\TaskBundleService` (`inc/Services/Task/TaskBundleService.php`):
      `syncChildren(int $parentId): array` — идемпотентный upsert 3 children по
      `task_19/20/21_condition/answer` parent-а (создаёт при отсутствии, иначе обновляет
      контент/заголовок/статус по сохранённым в `TaskBundleChildIds` id); `cascadeStatus(int, string)`
      — переносит статус на существующих children, ничего не создавая. 5 unit-тестов
      (`tests/Unit/Services/Task/TaskBundleServiceTest.php`).
- [x] `PostMetaName::TaskBundleParentId` (child → parent) и `PostMetaName::TaskBundleChildIds`
      (parent → [id19, id20, id21]) — добавлены в enum, пишутся только через
      `PostManager::updateMeta()`/`MetaBoxManager`, не напрямую.
- [x] Хук синхронизации — `Task\MetaBoxController::handleMetaSave()`: после `saveFieldsMerge()`
      для `template_id === TaskTemplate::Triple` зовёт `TaskBundleService::syncChildren()`. Номер
      задания на child ставится через новый `TermManager::getOrCreateIdByName()` (get-or-create
      термина по имени — в отличие от `insert()`, который для существующего термина отдаёт 0).
- [x] Статус — `MetaBoxController::handleBundleStatusTransition()` на `transition_post_status`
      (тот же паттерн, что `SlugCallbacks::lockOnPublish`/`CourseMetaBoxController::onCoursePublished`):
      cascade на children при draft/publish/trash parent-а. Дублирует часть работы `syncChildren()`
      (он тоже видит новый статус при save_post), но остаётся страховкой для путей, не идущих
      через полный сейв меты (быстрое редактирование, массовые действия).

### 3.2 Публичная страница — остаётся одна (parent) — ⚠️ ЧАСТИЧНО СДЕЛАНО (2026-08-18)
- [x] Гейт в роутинге — `Callbacks\Task\TemplateCallbacks::loadTaskFrontendTemplate()`: прямой
      заход на child (есть `TaskBundleParentId`) — `wp_safe_redirect(parent_link, 301)` (решение
      по §5 вопросу 1 принято: 301, не 404 — тот же паттерн, что
      `SubjectLandingController::redirectLegacySection()`).
- [x] Исключены из каталога/поиска банка тренажёра — `AllTasksDataBuilder::fetchTasks()`/
      `matchingIds()` (meta_query `NOT EXISTS TaskBundleParentId`) и
      `TermManager::countPostsByType()` (тот же `NOT EXISTS` в SQL — иначе счётчики фильтров
      «Все задания» разъезжались бы со списком). `ContentCacheService`/admin-виджет «Последние
      задания» НЕ трогали намеренно — там children видеть уместно (это авторский, не публичный
      экран).
- [ ] Экспорт предмета (`inc/Services/Subject/Bundle/PostCollector`) — не проверено и не покрыто
      тестом на круглый ремап `TaskBundleParentId`/`TaskBundleChildIds` через `RefRemapper`.

### 3.3 Work (работы) — 3 отдельных поста-слота — ✅ СДЕЛАНО (2026-08-18)
- [x] Общий механизм раскрытия — в `slot-builder.js` (используют и Work, и Assessment билдеры):
      новая `assignPicked(index, taskId, title, item)` — если у выбранного элемента есть
      `item.bundle_children` (отдаёт бэкенд, см. ниже), вместо одного слота подставляются
      **3 слота** (children в порядке 19/20/21, через `newSlot()` конфига — сохраняет форму слота
      конкретного билдера: у Assessment есть `points`/`number`, у Work — нет). Guard на дубли
      расширен на весь набор детей. `openPicker()` (`modules/picker.js`) теперь передаёт в
      `onPick` 4-м аргументом весь элемент кандидата (обратно совместимо — старые 3-арг колбэки
      не ломаются).
- [x] Источник `bundle_children` — `TaskBundleService::childrenSummary(int $parentId)`
      (`[{id, title, number}, ...]`, пусто для обычных заданий). Подключён в
      `WorkAuthoringService::getTaskCandidates()`/`getProblemCandidates()` — оба источника
      кандидатов Work (`{key}_tasks` и `fs_lms_problems`), т.к. связка может быть в любом из них
      (в текущей БД обе используемые связки — из банка, `fs_lms_problems`).
- [x] Guard на дубли (`WorkManager::setItemIds`) не тронут — children уже разные id.
- [x] `WorkDTO`/`WorkManager` — без изменений схемы, `itemIds` просто содержит 3 id вместо 1.
- [x] **Регрессия найдена и исправлена (2026-08-18, при выполнении пользовательской проверки
      «раскрытие ЕГЭ 19-21 + гард»):** `childrenSummary()` изначально отдавал только `{id, title}`
      без номера — банковские children (`fs_lms_problems`, без своей таксономии) получали в
      `assessment-builder.js` пустой `s.number`, и `EgeCompletenessChecker` видел их «сиротами»
      (терма нет, `task_numbers` тоже пуст). Исправлено: `childrenSummary()` теперь возвращает
      фиксированный номер (19/20/21 по порядку из `TaskBundleService::NUMBERS`) третьим полем,
      `slot-builder.js::assignPicked()` переносит его в `s.number` нового слота (только если у
      слота вообще есть это поле — Work-слоты его не имеют, Assessment-слоты имеют). Для
      предметных children поле избыточно (бэкенд игнорирует ручной номер при наличии терма), но
      безвредно. Подтверждено на реальных данных через `EgeCompletenessChecker::validate()` —
      после фикса позиции 19/20/21 резолвятся без orphans/missing/duplicated.
- [x] E2E проверено вручную через WP-CLI на реальных данных (parent 16694 → children
      16939/16940/16941) — `getStepCandidates`/кандидаты корректно отдают `bundle_children` с
      номерами, `EgeCompletenessChecker` корректно закрывает позиции 19/20/21.

### 3.4 Lesson-step «Задача» — 3 отдельных шага — ✅ СДЕЛАНО (2026-08-18)
- [x] `step-editor.js`: новая `expandStepToBundle(step, children)` — заменяет ТЕКУЩИЙ шаг
      «Задача» на **3 отдельных шага** подряд (ref = child 19/20/21, заголовок = заголовок
      child), с проверкой лимита `MAX_STEPS`. Прокинута в `ref-editor.js` через `ctx`
      (по образцу остальных колбеков `stepMeta`/`renderStepsRow`/…).
- [x] `ref-editor.js`: обработчик пика задачи (`isTask`-ветка) теперь принимает 4-й аргумент
      `item` из `openPicker` — при `item.bundle_children` зовёт `expandStepToBundle()` вместо
      простой установки `step.payload.ref = id`.
- [x] Источник `bundle_children` для лесенки — `LessonAuthoringService::candidatesFrom()`
      (новый параметр `$withBundles`, включён только для `kind = 'task'` — не для work/
      assessment/article/lesson кандидатов), через тот же `TaskBundleService::childrenSummary()`.
- [x] `LessonDTO`/`LessonManager` — без правок (шаг типа `task` хранит один `ref`; теперь просто
      3 шага вместо одного).
- [x] Затронутые конструкторы сервисов (`WorkAuthoringService`, `LessonAuthoringService`) получили
      новую DI-зависимость `TaskBundleService` — все прямые `new …AuthoringService(...)` в тестах
      обновлены (5 файлов), 1199/1199 тестов зелёные, `npx eslint`/`npx gulp scripts` без ошибок.

### 3.5 Assessment (контрольные/экзамен) — переезд на children, ретрит composite-кода
- [x] `assessment-builder.js` — раскрытие через тот же общий `assignPicked()`/`newSlot()` из
      `slot-builder.js` (см. §3.3): новый слот получает дефолтные `points: 1`/`number: ''` из
      `blankSlot()` конфига — вес 1 каждому ребёнку, а не 3 на весь блок, как и требовалось.
      Номер задания для предметных children выставляется термом автоматически
      (`TaskBundleService`), поле «Номер для банка» в UI остаётся резервным путём для
      банковских детей (как у любой другой банковской задачи).
- [ ] `EgeCompletenessChecker` — после перехода на children работает **без изменений**: номера
      19/20/21 закрываются тремя обычными таксон-термами, специальный orphan-кейс для целого
      блока больше не возникает.
- [ ] Once миграция (§4) завершена и в проде не осталось активных `taskIds`, указывающих на
      parent-посты связок в `AssessmentDTO`/`WorkDTO`: вывести из эксплуатации —
      `CompositeSubItemResolver`, `TripleAnswerChecker`, `expandsComposites()`-ветку в
      `BatchCheckService::check()`/`AutoGradeService::gradeAttempt()`, `subparts()` в
      `AttemptTaskViewBuilder`, `data-triple-subs`/`.kege-t-subpart` рендер в
      `templates/frontend/assessment/kege/exam.php` + `src/js/kege/kege-exam.js`. Отдельный
      чистовой PR, не раньше подтверждения нулевого использования в проде.

### 3.6 Станция ЕГЭ / шаблон задачи — рендер parent на публичной странице
- [ ] Публичная страница parent-поста (`templates/frontend/lesson-player/partials/step-task.php`,
      ветка `triple_task`, уже умеет рисовать 3 условия `fs-task-subpart`) — оставить как есть,
      это единственное место, где связка показывается «одним постом».

## 4. Миграция существующего банка — ✅ СДЕЛАНО (2026-08-18)

- [x] WP-CLI команда `wp fs-lms task-bundle migrate [--dry-run]`
      (`inc/Cli/TaskBundleMigrationCommand.php`, зарегистрирована в `Init::getServices()`) —
      тот же паттерн, что `wp fs-lms article reslug` (план отделён от применения,
      бизнес-логика в сервисе, не в CLI-классе): `TaskBundleMigrationPlanner`
      (`inc/Services/Task/TaskBundleMigrationPlanner.php`).
      Фаза 1 — `findBundleParents()` находит все `triple_task`-посты (предметные CPT
      предметов с банком + глобальный `fs_lms_problems`), `materialize()` зовёт
      `TaskBundleService::syncChildren()` на каждый (идемпотентно; выполняется **даже
      под `--dry-run`** — она не разрушительна, а план фазы 2 без реальных id children
      был бы пустым/лживым).
- [x] Фаза 2 — `planReferenceUpdates()`/`applyReferenceUpdates()`: находит Work/Assessment,
      чьи `itemIds`/`taskIds` ссылаются на parent-id связки, заменяет один id на 3 id
      children (`TaskBundleReferenceChangeDTO`, `inc/DTO/Task/TaskBundleReferenceChangeDTO.php`).
      Вес — по 1 на каждого child независимо от исходного `taskPoints[parent]` (совпадает
      с текущей поштучной оценкой 19/20/21 у `TripleAnswerChecker`). Ручной номер
      (`taskNumbers`, фолбэк Задачи 8) переносится **только для банковских children**
      (`fs_lms_problems` — нет своей таксономии) и **только по связкам, реально стоящим
      в этой конкретной работе/контрольной** — попутно найден и исправлен баг черновика:
      наивная реализация заносила номера ВСЕХ найденных по сайту связок в `taskNumbers`
      каждой затронутой записи, а не только своей.
- [x] `--dry-run` — показывает план обеих фаз, ничего не пишет во 2-й (разрушительной)
      фазе. Гейт неотключаем для фазы 2 (сама структура команды: apply — отдельный, явный
      шаг после показа таблицы плана).
      Исторические строки `task_attempts`/`assessment_answers` по старому parent-`task_id`
      не трогаются в принципе — миграция их не касается вообще.
- [x] `applyReferenceUpdates()` возвращает `{applied, failed}`, а не просто счётчик —
      `WorkManager::setItemIds()`/`AssessmentManager::setItemIds()` молча отказывают
      (`false`), если итоговый список содержит дубли (гард «задача 6»); найдено на
      реальном dev-банке: одна работа уже содержала повторяющийся `item_id` **до**
      миграции (независимо от связок). Команда явно предупреждает про такие ID вместо
      того, чтобы молча заявить успех — 7 unit-тестов на планировщик, включая регресс
      на утечку `taskNumbers` и на разделение applied/failed.
- [x] Прогнано end-to-end на реальном dev-банке через WP-CLI (`docker compose run --rm
      wpcli`): 8 найденных связок, 3 реально затронутых Work/Assessment (одна из связок
      обнаружилась в предмете `git`, у которого `hasBank = false` — по пути найден и
      исправлен второй баг черновика: Work/Assessment CPT регистрируются независимо от
      `hasBank`, фильтровать по нему список предметов для сканирования ссылок было
      нельзя, см. `SubjectContentRegistrar::registerAll()`). После применения повторный
      `--dry-run`/прогон находит 0 изменений — идемпотентность подтверждена. Полный набор
      — 1206/1206 тестов зелёные.
- [ ] Прогон на проде — не проводился (нужен отдельный запуск на реальном проде, когда
      будет решён вопрос §5.3 про расписание/окно простоя).

## 5. Открытые вопросы (нужно решить до старта)

1. ✅ **Закрыт (2026-08-18).** Публичный роут child-поста — 301 на parent (реализовано,
   см. §3.2).
2. ✅ **Закрыт, вопрос снят.** У `ThreeInOneTemplate` нет отдельного поля «общий контекст серии» —
   каждое из 19/20/21 уже хранит собственное самостоятельное условие (`task_19_condition` и т.д.),
   ничего общего дублировать не нужно: child просто получает свою пару condition/answer как есть.
3. **Частично закрыт фактом, решение по срокам не принято.** Проверено в текущей (dev)
   БД: 8 `triple_task`-постов, 2 из них опубликованы (ID 16694, 16707) и **реально
   используются** — 16694 в `taskIds` контрольной 16692, 16707 в `item_ids` работы 16732 и
   `taskIds` контрольной 16733; по обоим есть строки в `wp_fs_lms_assessment_answers`
   (история сдачи). План §4 это уже учитывает («исторические строки по старому
   parent-task_id не трогаем»), но подтверждения по прод-окружению (не dev) и расписания
   прогона миграции пока нет — нужно решить перед реальным запуском WP-CLI миграции.

---

# Компьютерный ОГЭ + вынос настроек компьютерных экзаменов в конфиг плагина

> **Статус:** §3.1 (enum), §3.2 (конфиг станций: время/попытки/шкала), §3.4 (критерии 13-16 —
> holistic-рубрика + dropdown), §3.5 (баллы за задание из конфига, `pass_score` тоже убран из
> UI) — сделаны, с тестами. Данные пользователя учтены полностью (`.docs/oge/{criteries,scores}.md`).
> Не сделано: собственно **визуальная станция ОГЭ** — см. §6 «Поэтапный план визуальной
> станции» ниже, работа начинается с уточняющих вопросов (§6.0) до кода.
> **Дата:** 2026-08-18.
> **Связанные файлы:** `inc/Enums/Assessment/AssessmentKind.php`, `inc/Modules/EgeComputer/EgeComputerModule.php`,
> `inc/Modules/EgeComputer/Config/{KegeScaleConfig,KegeInstructionConfig,KegeSlidesConfig}.php`,
> `inc/Modules/EgeComputer/Services/KegeResultSheetService.php`, `inc/DTO/Assessment/AssessmentDTO.php`,
> `inc/MetaBoxes/Templates/AssessmentTemplate.php`, `inc/Callbacks/Assessment/GradeAttemptCallbacks.php`,
> `inc/MetaBoxes/Fields/CriteriaField.php`, `inc/Services/Assessment/EgeCompletenessChecker.php`,
> `templates/frontend/assessment/kege/{entry,exam,finish}.php`, `templates/frontend/assessment/ege-computer.php`.

## 1. Проблема

Сейчас в `AssessmentKind` (`inc/Enums/Assessment/AssessmentKind.php:7-98`) три кейса:
`Control` (Контрольная), `Ege` (ЕГЭ), `EgeComputer` (Компьютерный ЕГЭ). `Ege` и `EgeComputer`
во всех match-ветках enum'а (`usesWeightedScore`, `needsSecondaryScore`, `expandsComposites`,
`needsCompletenessCheck`) ведут себя идентично — разница только в том, что `EgeComputer`
рендерится отдельным шаблоном-станцией (`EgeComputerModule::resolveRenderer`, L77-95) со своими
хардкод-конфигами (`KegeScaleConfig`, `KegeInstructionConfig`, `KegeSlidesConfig`), а `Ege` —
обычным `attempt-intro.php`-флоу с настройками из меты поста. Отдельный тип «просто ЕГЭ» не
нужен: станция «Компьютерный ЕГЭ» уже покрывает сценарий, а голый `Ege` только путает выбор в
конструкторе. Второе: те же настройки, что сейчас лежат в мете каждого экзамена
(`AssessmentDTO`: `time_limit_minutes`, `max_attempts`, `pass_score`/`score_map`, `intro_html`),
для «станционных» типов не должны редактироваться преподавателем — они имитируют реальный
экзамен и обязаны быть едиными для всех экзаменов этого типа на сайте.

## 2. Целевая модель

Три вида экзамена в конструкторе:

- **«Контрольная» (`Control`)** — без изменений, все текущие поля (`time_limit_minutes`,
  `max_attempts`, `score_map`, `intro_html`) остаются per-assessment, как сейчас.
- **«Компьютерный ЕГЭ» (`EgeComputer`)** — станция без изменений по существу (27 заданий,
  текущие `KegeScaleConfig`/`KegeInstructionConfig`/`KegeSlidesConfig`), но лимит времени,
  макс. попыток, таблица перевода баллов и вступительный текст больше не читаются из меты
  поста — приходят из module-level конфига (тот же паттерн, что уже применён к
  `KegeScaleConfig`).
- **«Компьютерный ОГЭ» (новый `OgeComputer`)** — вторая станция, тот же движок рендера/попыток,
  что и КЕГЭ, но: **16 позиций** в списке заданий — задания 1-12 автопроверяемые (ввод числа/
  буквы, как обычные задания), задание **13 — альтернатива 13.1/13.2** (ученик решает один из
  двух вариантов: 13.1 — про программирование, 13.2 — про пользовательские программы; считается
  одной позицией независимо от выбора), задания **13-16 решаются «на бумаге»**: в станции у них
  нет поля ввода ответа, только кнопка загрузки файла (аналог `file_answer_task`). Учитель видит
  загруженный файл и выставляет баллы по хардкод-критериям из плагинного конфига (см. §3.4),
  **раздельно для 13.1 и 13.2** (разные наборы критериев — разное содержание заданий).
  Свои вступительные экраны/инструкции, своё время, свой лимит попыток, своя шкала перевода
  первичных баллов во вторичные, свои баллы за задание — всё захардкожено в config-классах
  модуля (не редактируется через UI, аналогично `KegeScaleConfig`).
- `Ege` (плоский «ЕГЭ» без станции) — кейс enum'а удалён (в БД на момент постановки задачи не
  было ни одной записи `kind = ege` — миграция данных не потребовалась, см. §5 вопрос 1).

## 3. Задачи

### 3.1 Enum: убрать `Ege`, добавить `OgeComputer` — ✅ СДЕЛАНО (2026-08-18)
- [x] `inc/Enums/Assessment/AssessmentKind.php` — кейс `Ege` удалён, добавлен `OgeComputer`
      («Компьютерный ОГЭ») с той же логикой match-веток, что у `EgeComputer`. Добавлен помогающий
      предикат `isStation()` (true для `EgeComputer`/`OgeComputer`) — используется вместо
      повторения списка кейсов по всей кодовой базе.
- [x] `AssessmentMetaBoxController::allowsIncompletePublish` — теперь `$kind->isStation()`,
      автоматически покрывает оба вида. `renderBuilderContent()` собирает
      `allow-incomplete-kinds` перебором `AssessmentKind::cases()`, а не хардкодом одного кейса.
- [x] Все обращения к `AssessmentKind::Ege` по кодовой базе (`GradeBadge`, `AssessmentIntroConfig`,
      `attempt.php`, 9 тестовых файлов) исправлены. `attempt.php` — générique-шаблон попытки
      (партиал `attempt-form-nav.php`) реально обслуживал только плоский `Ege` и после его
      удаления стал недостижим (станции резолвятся отдельным рендерером модуля, `Control` всегда
      использовал `attempt-form-list.php`) — партиал и тест на него удалены как мёртвый код.
      Полный набор тестов зелёный (1191/1191).
- [x] БД проверена (`wp_postmeta` по `fs_lms_assessment_kind`): в проде 0 записей с
      `kind = ege`/`ege_computer` (только 2 `control`) — миграция данных не потребовалась.

### 3.2 Плагинный конфиг вместо per-assessment настроек станций — ✅ СДЕЛАНО (2026-08-18)
- [x] `StationExamConfig::for(AssessmentKind $kind)` (`inc/Modules/EgeComputer/Config/StationExamConfig.php`)
      — единая точка для обеих станций: `{timeLimit, maxAttempts, passScore, scoreMap}`, `null`
      для нестанционных kind'ов. Цифры — из `.docs/oge/scores.md` (2026-08-18): ЕГЭ 235 мин / 1
      попытка / проходной 6 первичных / шкала `KegeScaleConfig` (переиспользована, не
      продублирована); ОГЭ 150 мин / 1 попытка / проходной 5 первичных / новая
      `OgeScaleConfig::scale()` (0–21 первичных → 2–5, а не 100-балльная шкала ЕГЭ —
      историческое различие двух экзаменов, тот же generic `SecondaryScoreService::translate()`
      подходит без изменений).
- [x] Подмена значений `AssessmentDTO` — не прямой импорт модуля в DTO/Manager (ядро не знает о
      модулях), а WP-фильтр `AssessmentManager::STATION_SETTINGS_FILTER` (тот же приём, что
      `AssessmentPageController::RENDERER_FILTER`): `AssessmentManager::get()`/`getBankBySubject()`
      зовут `apply_filters(..., $dto)`, `EgeComputerModule::applyStationSettings()` подписан на
      фильтр и для `kind->isStation()` строит новую копию DTO (readonly — без `with`) с
      подменёнными `timeLimit`/`attemptsAllowed`/`passScore`/`scoreMap`; для `Control` — no-op.
      Это единственная точка подмены — все читающие сайты (`AttemptService`, `AttemptOutcomeService`,
      `ExamResultService`, `AssessmentIntroConfig`, `AttemptPageService`) получают уже
      подменённый DTO без собственных правок.
- [x] `AssessmentMetaBoxController::handleAssessmentSave()` — для `kind->isStation()` эти 4 поля
      (`time_limit_minutes`, `max_attempts`, `score_map`, `intro_html`) вырезаются из `$data`
      ДО `saveFieldsMerge()`, даже если пришли в `$_POST`.
      `assessment-builder.js::toggleKindFields()` скрывает те же 4 поля атрибутом `hidden`
      (не инлайн-стилем) при выборе станционного `kind`; ранее `score_map` был, наоборот, ВИДИМ
      только для ЕГЭ/ОГЭ — правило инвертировано (видим только для `Control`).
- [x] 12 новых unit-тестов (`OgeScaleConfigTest`, `StationExamConfigTest`,
      `EgeComputerModuleStationSettingsTest`, `AssessmentStationFieldsGateTest`) + полный набор
      1218/1218 зелёный, `eslint`/`gulp scripts` без ошибок.

### 3.3 «Компьютерный ОГЭ» — станция
- [ ] `EgeComputerModule::resolveRenderer` (L77-95) — расширить ветвление по `kind`: для
      `OgeComputer` резолвить отдельный шаблон/партиалы (`templates/frontend/assessment/oge-computer.php`
      + `templates/frontend/assessment/oge/{entry,exam,finish}.php` — параллельно `kege/*`, не
      переиспользуя `kege/*` напрямую, т.к. вступительные экраны и разметка листа ответов другие).
- [ ] Параллельные конфиги: `OgeInstructionConfig` (свои вступительные экраны/тексты — по
      аналогии с `KegeInstructionConfig::paragraphs()`), `OgeSlidesConfig` (свои картинки
      инструктажа — по аналогии с `KegeSlidesConfig`, свои assets в
      `inc/Modules/EgeComputer/assets/images/oge-*` либо отдельная папка модуля).
- [ ] `KegeResultSheetService`/`KegeSheetDTO` — сейчас завязаны на 27-заданный/29-строчный
      КЕГЭ-макет (`KegeResultSheetService.php:26`, `kege/finish.php:9`). Обобщить по `kind`
      (параметризовать число заданий/строк листа ответов) либо завести параллельный
      `OgeResultSheetService`/`OgeSheetDTO` под 16 заданий — решить при реализации, но жёстко
      прибитого «27» после этой задачи по кодовой базе остаться не должно ни в одном месте,
      которое теперь обслуживает два разных экзамена.
- [ ] `EgeCompletenessChecker` — уже общий (гоняет по числу термов таксономии номера задания,
      без хардкода 27), для ОГЭ с 16 заданиями правок не требует — только завести 16 термов
      `{key}_task_number` в банке.

### 3.4 Задания 13-16 ОГЭ — ручная проверка по хардкод-критериям — ✅ СДЕЛАНО (2026-08-18)

**Важное уточнение формата после чтения `.docs/oge/criteries.md`.** Критерии ОГЭ — НЕ
аддитивные (не сумма независимых К1+К2, как ожидалось в постановке ниже и как работает
существующий `CriteriaField`): каждый уровень (2/1/0 баллов, у №14 — 3/2/1/0) описывает
ЦЕЛОСТНОЕ качество работы, проверяющий выбирает РОВНО ОДИН уровень целиком. Решение с
пользователем (2026-08-18): учитель видит текст ВСЕХ уровней целиком и ставит один балл через
**dropdown** — это обычный «простой балл» (`GradeAttemptCallbacks` без критериев), не
покритерийная сумма. Ниже — как это реализовано вместо исходного плана «по структуре
`task_criteria.criteria`».

- [x] `OgeCriteriaConfig` (`inc/Modules/EgeComputer/Config/OgeCriteriaConfig.php`) — хардкод
      рубрик 13.1/13.2/14/15/16 (текст — дословно из `criteries.md`), `rubricFor(string $position): ?array{max_points, html}`.
      Позиция задания резолвится ТОЛЬКО через ручной номер (`AssessmentDTO::$taskNumbers`,
      Задача 8) — не через таксономию `{key}_task_number` (та принимает только целые числа,
      не умеет хранить «13.1»/«13.2», см. `TaskNumberTermGuard`). Автор банка ОГЭ обязан
      проставить номер вручную в Assessment builder для ЛЮБОГО задания 13.1/13.2/14/15/16, даже
      предметного — задокументировано в докблоке класса.
- [x] Задания 13-16 не автопроверяются — переиспользуется существующий `file_answer_task`
      (см. «Дополнительно» ниже, п. 2) — pending-верификация уже работает как есть, отдельного
      кода не потребовалось.
- [x] Подключение — фильтр `WorkDetailService::OGE_RUBRIC_FILTER` (core, тот же паттерн, что
      `AssessmentManager::STATION_SETTINGS_FILTER`): `fromAttempt()` добавляет `'oge_rubric'`
      в per-task массив, `EgeComputerModule::resolveOgeRubric()` подписан на фильтр, резолвит
      позицию по `taskNumbers[$taskId]`, возвращает `OgeCriteriaConfig::rubricFor()`.
- [x] Экран учителя (`src/js/profile/summary.js`, «Сводка по ученику» → проверка попытки
      экзамена) — новая ветка `ogeRubricGradeBlock()`: показывает `t.oge_rubric.html` (весь
      текст рубрики) + `<select>` с баллами `0..max_points`. Сохранение —
      `wireAttemptGrading()`, новая ветка `isOgeRubric`: шлёт `score`/`is_correct` (простой балл,
      существующий путь `GradeAttemptCallbacks`), НЕ `criteria_scores`. Новые SCSS-классы
      (`.sum-task-rubric`, `.fs-oge-rubric__*`, `.sum-task-grade--oge-rubric`) — токенами,
      без инлайн-стилей и хардкод-цветов, `stylelint` чист.
- [x] 12 новых unit-тестов (`OgeCriteriaConfigTest` — 7, `EgeComputerModuleStationSettingsTest`
      — доп. 4 на `resolveOgeRubric`, `WorkDetailServiceTest` — доп. 2 на `oge_rubric` в выдаче) +
      полный набор 1230/1230 зелёный, `eslint`/`stylelint`/`gulp build` без ошибок.
- [ ] Критерии заданий **14, 15, 16 уже получены и закодированы** — весь текст из
      `criteries.md` учтён (был вопрос §4 п.2 «ещё нужны критерии 14-15-16» — закрыт, файл
      пользователь дополнил).

### 3.5 Баллы за задание — тоже в конфиг — ✅ СДЕЛАНО (2026-08-18)
- [x] Поле «Баллов за задание» убрано из builder UI для станционных видов
      (`assessment-builder.js::renderExtraBody`) — остаётся только «Номер задания (для банка)».
      Баллы теперь вычисляются на чтении в `EgeComputerModule::applyStationSettings()` →
      `computeTaskPoints()`: для каждого `taskId` резолвится позиция (терм таксономии — для
      предметных заданий, иначе ручной номер `taskNumbers` — тот же путь, что использует
      `EgeCompletenessChecker`), затем баллы берутся из `KegeScaleConfig::answerSlots()` (ЕГЭ:
      1 балл на позицию, №26/27 — по 2) или новой `OgeScaleConfig::pointsForPosition()` (ОГЭ:
      1..12 → 1 балл, 13.1/13.2/14/15/16 → максимум берётся из `OgeCriteriaConfig::rubricFor()`
      — единый источник с рубрикой проверки, баллы задания и критерии не могут разойтись).
      Задание без распознанной позиции в карту `taskPoints` не попадает (эквивалент «нет баллов»).
- [x] **Проходной балл тоже убран** (пропущен в первом проходе §3.2 — исправлено по запросу
      пользователя): `pass_score` добавлен в список полей, скрываемых в UI и не сохраняемых
      на бэкенде для станций (`AssessmentMetaBoxController::handleAssessmentSave`,
      `assessment-builder.js::STATION_ONLY_HIDDEN_FIELD_IDS`).
- [x] **Побочные баги, найденные и исправленные при проверке на реальных данных:**
      1) Число слотов в builder ошибочно было ОДНИМ числом на оба вида экзамена (счётчик
      термов таксономии предмета — обычно 27, унаследовано от ЕГЭ), из-за чего «Компьютерный
      ОГЭ» тоже показывал 27 позиций вместо 16. Исправлено: `renderBuilderContent()` передаёт
      карту `{kind: slots}` (`ege_computer` — из таксономии, `oge_computer` — фиксированные 16),
      JS выбирает нужное число по текущему `kind`.
      2) Автозаполнение пустых слотов при выборе станционного вида сохраняло МАССИВ НУЛЕЙ
      (`taskId=0` для каждого незаполненного слота) — гард от дублей (`AssessmentManager`/
      `WorkManager::setItemIds`) схлопывал несколько нулей в один и отклонял сохранение с
      ложным тостом «Экзамен не найден». Баг СИММЕТРИЧНО живёт и в Work-конструкторе (тот же
      слот-билдер). Исправлено на клиенте: `persist()` в `assessment-builder.js` и
      `work-builder.js` теперь фильтрует `taskId > 0` перед отправкой — пустые слоты в
      `item_ids` не попадают.
- [x] 2 новых unit-теста на `computeTaskPoints()` (ЕГЭ: обычная позиция vs №26 с двумя
      ответами; ОГЭ: автопроверяемая позиция vs 13.1/14 с баллами из рубрики), полный набор
      1232/1232 зелёный, `eslint`/`gulp build` без ошибок. Подтверждено вручную через WP-CLI:
      карта слотов на dev — `{"ege_computer":27,"oge_computer":16}`.

## 4. Открытые вопросы (нужно решить до старта)

1. ✅ **Закрыт.** Что в проде хранится под `AssessmentKind::Ege` — проверено запросом к БД
   (`wp_postmeta` по `fs_lms_assessment_kind`): 0 записей с `kind = ege`/`ege_computer`, только
   2 `control`. Миграция данных не нужна, см. §3.1.
2. **В работе.** Итоговые критерии оценивания заданий 13-16 «Компьютерного ОГЭ»:
   - Формат задания 13 уточнён с пользователем: это **альтернатива 13.1/13.2** (ученик решает
     один из двух вариантов — 13.1 «презентация», 13.2 «текстовый документ с таблицей»), считается
     одной позицией из 16 в списке заданий; критерии — раздельные наборы для 13.1 и 13.2.
   - Задания 13-16 решаются «на бумаге»: в станции у них нет поля ответа, только загрузка файла
     (как `file_answer_task`); учитель проверяет вручную по критериям.
   - ✅ **Закрыт полностью.** Текст критериев для 13.1/13.2/14/15/16 получен
     (`.docs/oge/criteries.md`, 2026-08-18) и закодирован в `OgeCriteriaConfig`. По ходу
     выяснилось и решено с пользователем: критерии не аддитивные, а holistic (один уровень
     целиком) — учитель видит текст всех уровней + ставит балл через dropdown. См. §3.4.
3. ✅ **Закрыт.** Шкала перевода первичных баллов и время/лимит попыток для ОГЭ и ЕГЭ — цифры
   получены (`.docs/oge/scores.md`, 2026-08-18) и закодированы в `StationExamConfig`/
   `OgeScaleConfig`. См. §3.2.
4. **Открыт, частично закрыт.** Вступительные экраны ОГЭ: текст первого экрана получен
   (`.docs/oge/oge-screens/1-sreen.md`) и 8 картинок остальных экранов (`screen-2.png` … 
   `screen-9.png`) — но САМ карусель-код (`OgeInstructionConfig`/`OgeSlidesConfig`,
   аналог `KegeInstructionConfig`/`KegeSlidesConfig`) ещё не написан: экраны 2-9 — картинки как
   у КЕГЭ, а экран 1 — ТЕКСТ (не картинка, в отличие от КЕГЭ, где первый слайд карусели тоже
   картинка) — значит `entry.php`-карусель нужно параметризовать под смешанный тип слайда
   (текст ИЛИ картинка), а не просто скопировать структуру КЕГЭ. Не начато — входит в объём
   вопроса 8 ниже.
5. **Открыт.** `KegeResultSheetService`/лист ответов — обобщать один сервис на оба kind'а
   параметром числа заданий, или заводить отдельный `OgeResultSheetService`? Влияет на объём
   рефакторинга существующего КЕГЭ-кода при добавлении ОГЭ. Часть вопроса 8 ниже.
6. ✅ **Закрыт технически.** Задание 13 — альтернатива 13.1/13.2: со стороны банка это два
   отдельных task-поста (в банке `fs_lms_problems`, не предметные — таксономия `{key}_task_number`
   принимает только целые числа и не может хранить «13.1»), с **ручным** номером «13.1»/«13.2»
   в `taskNumbers` конкретного экзамена (не термом). Реализовано в `OgeCriteriaConfig`/
   `EgeComputerModule::resolveOgeRubric()`, см. §3.4. Как именно ученик ВЫБИРАЕТ между 13.1/13.2
   на экране станции (два аплоада с переключателем vs что-то другое) — вопрос UI станции,
   переходит в вопрос 8.
7. ✅ **Закрыт.** Формат критериев (аддитивный CriteriaField vs holistic) — решено с
   пользователем: holistic, единый балл через dropdown + полный текст рубрики. См. §3.4.
8. ✅ **Закрыт, объём переоценён вниз после чтения кода станции.** `kege/exam.php`,
   `kege/finish.php`, `ege-computer.php`-обёртка и `KegeResultSheetService` оказались почти
   полностью **уже параметризованы данными** (`$assessment->taskIds`/`taskNumbers`,
   `KegeScaleConfig::answerSlots()`/`scale()`) — НЕ хардкодят «27» нигде, кроме самих вызовов
   `KegeScaleConfig::*`. Решение: подход (а) — переиспользовать эти же файлы для ОГЭ через
   kind-условия в нескольких точках, НЕ форкать в параллельный `oge/*`. Разбивка на этапы —
   §6 ниже.

Дополнительно (проверено 2026-08-18):
1. ✅ **Проверено, найден и исправлен баг.** Раскрытие связки 19-21 в конструкторе ЕГЭ гоняли
   на реальных dev-данных (parent 16694 → children 16939/16940/16941) через
   `EgeCompletenessChecker::validate()`. Изначально гард видел раскрытых детей «сиротами» —
   баг в `TaskBundleService::childrenSummary()` (не отдавал номер), исправлен и подтверждён
   повторной проверкой. Подробности и место фикса — §3.3 выше.
2. ✅ **Не требуется — уже есть готовый шаблон.** Существующий `file_answer_task`
   (`inc/MetaBoxes/Templates/FileAnswerTaskTemplate.php`, «Развёрнутый ответ (файл, ручная
   проверка)») уже покрывает ровно этот сценарий один-в-один: у автора — поле
   `task_materials` (`FileAttachmentsField`, «Материалы задания, видны ученику») для своих
   файлов, у ученика — виджет загрузки файлов в générique-плеере попытки
   (`templates/frontend/assessment/partials/attempt-question.php:84-99`, приём
   `.jpg/.png/.pdf/.doc/.docx/.pptx/.txt/.py`, ручная проверка через `task_criteria`/простой
   балл). Докблок шаблона прямо называет целевые сценарии: «презентация/документ (ОГЭ инф.
   №13), программа .py (ОГЭ инф. №15)» — шаблон, судя по всему, изначально проектировался
   именно под эти номера ОГЭ. Для банка ОГЭ 13-16 использовать этот шаблон как есть, новый не
   заводить. **Важная оговорка:** этот générique-виджет загрузки существует только в
   `attempt-question.php` (générique-флоу попытки) — станция ОГЭ (§3.3 второй задачи, ещё не
   построена) рендерит задания СВОИМ отдельным кодом (по образцу `kege/exam.php`, который тоже
   не переиспользует `attempt-question.php`), так что для появления загрузки файлов внутри
   самой станции этот виджет всё равно придётся перенести/адаптировать в новый шаблон
   станции — шаблон банка уже готов, State станции — нет.

## 6. Поэтапный план визуальной станции ОГЭ

> **Статус:** §6.0 (все вопросы закрыты), §6.1, §6.2, §6.4 — сделаны. §6.3 — частично (шкала/
> баллы листа ответов ещё не диспетчеризованы по kind). §6.5 (тесты/e2e) — не начато.

**Ключевая находка при чтении кода станции ЕГЭ**, меняющая объём работы в меньшую сторону:
`kege/exam.php`, `kege/finish.php`, `ege-computer.php`-обёртка и `KegeResultSheetService` НЕ
хардкодят число заданий/позиций нигде — они полностью управляются `$assessment->taskIds`/
`taskNumbers`, а «27 заданий → 29 позиций» — это ПОВЕДЕНИЕ, вычисленное из
`KegeScaleConfig::answerSlots()` для конкретного набора номеров, а не константа в шаблоне.
Единственное МЕСТО, которое реально хардкодит логику ЕГЭ — сами точки вызова
`KegeScaleConfig::scale()/answerSlots()/secondaryMax()` (3-4 места) и несколько кусков текста
(«Единый государственный экзамен», вкладка «i» — конвенции логических операций КЕГЭ). Значит
план — НЕ форк в параллельный `oge/*`, а точечная параметризация существующих файлов по
`$assessment->kind` + один новый тип ответа («файл», для 13-16).

### 6.0 Уточнить перед стартом — статус ответов (решено с пользователем 2026-08-18)

1. ✅ **Решено, контент получен и согласован.** Вкладка «i» — текст из
   `.docs/oge/oge-screens/exam-1-screen.md` (исправленная версия, 2026-08-18: «16 заданий»,
   «файлы — результат заданий 13-16», согласуется с уже реализованными 16 позициями).
2. ✅ **Решено.** Бланк регистрации — тот же, что у ЕГЭ (`blank-number.webp`, та же разметка
   ввода). Отдельного `OgeSlidesConfig::blankHint()` не нужно — `EgeComputerModule` резолвит
   один и тот же `KegeSlidesConfig::blankHint()` для обоих kind.
3. ✅ **Решено.** Код активации — тот же `2599`, что у ЕГЭ.
4. ✅ **Решено.** Заголовки экранов ОГЭ — «Основной государственный экзамен».
5. ✅ **Решено.** Задания 13-16 ОГЭ на экране экзамена — ТОЛЬКО кнопка загрузки файла, без
   текстового поля ответа (в отличие от générique `file_answer_task`, где текст опционален).

### 6.1 Ритуал входа (`kege/entry.php`) — параметризация под kind — ✅ СДЕЛАНО (2026-08-18)

- [x] `OgeSlidesConfig` (`inc/Modules/EgeComputer/Config/OgeSlidesConfig.php`): слайд 1 — ТЕКСТ
      (шаги ритуала из `.docs/oge/oge-screens/1-sreen.md`), слайды 2-9 — картинки
      `oge-instruction-2.png` … `oge-instruction-9.png` (скопированы из `.docs/oge/oge-screens/
      screen-2..9.png` в `inc/Modules/EgeComputer/assets/images/`, формат оставлен png).
      `entry.php` теперь поддерживает смешанный тип слайда (`type: 'text'|'image'`) —
      КЕГЭ-слайды без явного `type` трактуются как `'image'` (обратная совместимость, правки
      `KegeSlidesConfig` не потребовалось).
- [x] `entry.php` резолвит `OgeSlidesConfig::slides()` для `OgeComputer`, иначе
      `KegeSlidesConfig::slides()`. Бланк регистрации — везде `KegeSlidesConfig::blankHint()`
      (решено: общий с ЕГЭ, п. 6.0.2). Код активации не трогали (общий `2599`, п. 6.0.3, зашит в
      `kege-entry.js`, kind не влияет).
- [x] Заголовок «Единый государственный экзамен» / «Основной государственный экзамен» —
      kind-aware (`$isOge` в `entry.php`, `finish.php`; `$stationLabel` в `ege-computer.php` для
      `<title>`, `kege-page`/`kege-*`-классы НЕ переименовывались — служебное имя платформы,
      см. 6.4).

### 6.2 Экран экзамена (`kege/exam.php`, `kege-exam.js`) — новый тип ответа «файл» — ✅ СДЕЛАНО (2026-08-18)

- [x] `exam.php`: `$isTable` теперь гейтится `! $isOge` (у ОГЭ таких позиций нет). Новая ветка
      `$isFile = TaskTemplate::FileAnswer->value === $view['template']` → `data-answer-shape="file"`.
- [x] Вкладка «i» — `OgeInstructionConfig::paragraphs()` (текст из исправленного
      `exam-1-screen.md`, 16 заданий/13-16 файлы, согласовано с пользователем) для `OgeComputer`,
      иначе прежний `KegeInstructionConfig`.
- [x] `kege-exam.js`: новая ветка `'file' === shape` в свитче ответов — загрузка через
      `kegeVars.actions.uploadAnswerFile`/`kegeVars.nonces.uploadAnswerFile` (тот же AJAX, что и
      générique `assessment.js::bindFileAnswers`, уже локализован в `fs_lms_kege_vars` —
      `BundleLoader::assessmentVars()`, правок бэкенда не потребовалось). `collect()` отдаёт JSON
      `{"text":"","files":[ids]}` (решено: только файл, без текстового поля, п. 6.0.5) — тот же
      формат, что уже разбирают `KegeResultSheetService::studentAnswer()` и
      `WorkDetailService::parseFileAnswer()`. Восстановление чипов при возврате на задание не
      реализовано — тот же (уже существующий) пробел, что и в générique-флоу `assessment.js`, не
      регрессия. SCSS — `src/scss/kege/components/_exam.scss` (`.kege-ap-file-*`, токенами).
- [x] Задания 1-12 — без изменений, прежняя ветка `else` (текстовый инпут).

### 6.3 Лист ответов (`kege/finish.php`, `KegeResultSheetService`) — ⚠️ ЧАСТИЧНО СДЕЛАНО (2026-08-18)

- [x] `finish.php`: заголовок kind-aware (см. 6.1).
- [ ] `KegeResultSheetService` — 3-4 точки вызова `KegeScaleConfig::scale()/answerSlots()/
      secondaryMax()` ВСЁ ЕЩЁ хардкожены на `KegeScaleConfig`, не диспетчеризованы по
      `$assessment->kind`. Для `OgeComputer` лист ответов сейчас посчитает баллы по ЕГЭ-шкале —
      это баг, который проявится, как только появится реальная попытка ОГЭ. Нужно: `match` на
      `KegeScaleConfig`/`OgeScaleConfig` (контракт `scale()`/`secondaryMax()` одинаковый;
      `OgeScaleConfig::answerSlots()` — новый метод, всегда возвращает 1, у ОГЭ нет позиций с
      двумя ответами вроде №26/27 ЕГЭ) — можно завести общий интерфейс вместо `match`, решить
      при реализации.
- [ ] Для строк 13-16 (`file`-ответ) не проверено, что колонка «Правильный ответ» показывает
      «—», а не путается с обычным пропуском (`correctAnswer()` для `file_answer_task` уже
      отдаёт пусто, но end-to-end не прогонялось).

### 6.4 Роутинг и обёртка — ✅ СДЕЛАНО (2026-08-18)

- [x] Заголовок/подпись `ege-computer.php` параметризованы (`$stationLabel`). Имена файлов и
      CSS/JS-классы `kege-*` НЕ переименовывались — решение по умолчанию (не переспрашивали
      отдельно): `kege` — служебное имя станции-платформы, общей для обоих экзаменов, а не
      аббревиатура ЕГЭ; переименование потянуло бы правки по всему бандлу без функциональной
      пользы.
- [ ] **НЕ СДЕЛАНО, блокирует всё остальное:** `EgeComputerModule::resolveRenderer()`
      всё ещё содержит `if ($kind !== AssessmentKind::EgeComputer->value) return $default;` —
      для `OgeComputer` НИКОГДА не резолвит `ege-computer.php`, попытка открыть станцию ОГЭ
      сейчас попадёт в générique-флоу (`attempt.php`), а не на новый экран. Все правки §6.1/6.2
      физически недостижимы для пользователя, пока это не исправлено — следующий шаг №1.
- [x] `Enqueue::enqueue_kege_assets()`/`KEGE_ROUTE_FILTER` не трогали — они взводятся тем же
      `add_filter(KEGE_ROUTE_FILTER, '__return_true')` внутри `resolveRenderer()`, так что после
      фикса выше сработают для обоих kind без дополнительных правок.

### 6.5 Тесты и проверка — ❌ НЕ НАЧАТО

- [ ] Unit-тесты на новую kind-диспетчеризацию в `KegeResultSheetService` (после §6.3).
- [ ] Unit-тест на то, что `resolveRenderer()` резолвит станцию для `OgeComputer` (после §6.4).
- [ ] E2E через WP-CLI/headless — прогнать реальную попытку ОГЭ (создать тестовый банк из 16
      задач нужных типов, пройти сценарий вход→экзамен→завершение, проверить лист ответов и
      загрузку файлов 13-16).