# Этап 7. Общий renderer задач и «Результаты» ученика

Зависимости: этап 6 (попытки существуют). Результат: один renderer задачи для «Работ» преподавателя и разбора экзамена; экран
«Результаты» у ученика и родителя; баллы КЕГЭ и ОГЭ; сервер не отдаёт баллы, эталоны и решения до утверждения.

Перед началом прочитать `README.md`. SPEC: §5 «Результаты и задания», «Баллы КЕГЭ», «Баллы ОГЭ», §7 «Результаты и утверждение», §13, §16 критерии 15, 19, 20, 24.

**Порядок:** 7.5 → 7.1 → 7.2 → 7.3 → 7.4. Пункт 7.5 первым: сначала закрыть утечки, потом открывать разбор.

## Статус (проверено 2026-10-04, рефакторинг по `refactor.md`)

**Сделано и проверено** — подзадачи выше с `[x]`: `tests/js/task-render.test.mjs`, `exam-result.test.mjs`; `ExamReviewProjectionTest`,
`ExamScoreServiceTest`, `LearnerExamsServiceTest`, `LearnerExamCallbacksTest`, `AttemptRevealPolicyTest`, `AttemptServiceTest`;
сквозной проход по HTTP: до утверждения в карточке нет `result`/`units`/`approved_at`, разбор отвечает `revealed: false` без заданий, `attempt_id` из
запроса не принимается, чужой получает «Результат недоступен.»; после утверждения — итог, перечень (`number`, `status`, `anchor`) и разбор без идентификаторов оценивания.

**Что изменилось по сравнению с текстом этапа:**
- `task-render.js` — общий renderer (`renderTask( t, { mode, kind, canGradeAttempt, canGradeBatch } )`); `work-review.js` использует его и в «Работах»,
  и для прошлых раундов сдачи (прежний `historyTaskBlock` — дубль read-only разметки — удалён).
- Экран разбора — `exams/exam-review.js` (`renderExamReview`, `openExamReview( eventId, anchor )`), секция `exam-review` в `app.js` вне `cfg.screens`.
  Общие для плитки и разбора `resultCaption`, `resultPercent`, `UNIT_STATUS` — `exams/exam-result.js`.
- Родителю из действий доступны «Результаты» (разбор данных ребёнка); запись, перенос, отмена и запуск — только ученику.
- `ExamReviewProjection` собирает проекцию одним `assemble()` для обоих входов и решает раскрытие через `AttemptService::isRevealed()`.
- Направление (ЕГЭ/ОГЭ) в `ExamScoreService` берётся из `ExamFormatDTO::$direction`, а не из вида работы.

**Закрыто 2026-10-04 (повторная приёмка этапа):**
- **7.5.5:** `grep -rn "solution|answer_file|task_solution" templates/frontend/assessment inc/Services/Assessment inc/Modules/EgeComputer` находит только `TaskPreviewService`
  (предпросмотр задания автором, `solution_html`) — лист станции и карточка ссылок на файлы решения/эталона не формируют; условие и приложенные к нему файлы доступны всегда.
- Недостающие по спецификации тесты: `AttemptRevealPolicyTest::test_control_rule_unchanged` и `::test_course_oge_rule_unchanged`, `ExamReviewProjectionTest::test_oge_files_code_and_tables_are_kept_in_read_only`,
  `LearnerExamsServiceTest::test_unapproved_card_has_no_result_keys`.
- **Браузер:** карточка с итогом, перечень заданий, разбор — на 1440 и 390 px (снимки сняты; без горизонтальной прокрутки), 0 ошибок консоли; подпись плитки «N из M» для ЕГЭ и ОГЭ,
  «из 100» для ОГЭ не используется; клик по заданию в перечне открывает разбор, прокрученный к этому заданию (7.4.5).

**Остаётся за этапом 8:** 7.5.7 (режим `manage` сотрудника); сверка экрана «Работы» преподавателя со снимком «до» (7.1.3) — снимка «до» в репозитории нет, поведение «Работ» покрыто
`task-render.test.mjs` и `WorkDetailServiceTest` без изменений.

## Общие правила этапа

- Третьего дизайна задач не создаётся: разбор экзамена рисует тот же код, что экран «Работы».
- Что показывать, решает сервер. Режим `read_only` — это не «скрытые кнопки», а **отсутствие** в ответе полей оценивания и идентификаторов для записи.
- Скрытое через CSS защитой не считается: до раскрытия данных нет ни в HTML, ни в JSON, ни в адресах файлов.

---

## 7.5 Политика раскрытия

**Зачем.** Ученик и родитель до утверждения работы не получают баллы, эталоны и решения никаким путём. Гость получает разбор сразу после сдачи
(гостевой путь — этап 11b, правило закладывается сейчас). Старый подписчик `AttemptGraded` не обходит утверждение (SPEC §6, §7, критерий 15).

**Проверить перед началом**
- `inc/Services/Assessment/AttemptRevealPolicy.php::isRevealed()` — ЕГЭ: `isApproved()`; ОГЭ: `status === Graded`; Control: `true`.
- Все места, где ученик получает данные попытки:
  `grep -rn "isRevealed\|revealPolicy\|reviewReveal" inc templates --include="*.php"` — записать список.
- Пути выдачи: `AttemptCallbacks::ajaxSubmitAttempt()`, `ajaxGetAttemptResult()`, `AttemptService::getResult()`, `AttemptResultService::studentPerTask()`,
  `ExamResultService::buildForStudent()`, `KegeResultSheetService` (лист станции), `templates/frontend/assessment/kege/finish.php`,
  `AttemptPageService::buildReview()` (`?attempt=ID`), `LearnerPerformanceSection`.
- 6.7.4 уже отключил уведомление «Экзамен проверен» для экзаменных попыток.

**Шаги**
- [x] 7.5.1 `AttemptRevealPolicy::isRevealed()` — в начале метода ветка экзаменной попытки:
  ```php
  if ( $attempt->isExam() ) {
      return $this->isExamRevealed( $attempt );
  }
  ```
  `isExamRevealed()`: аудитория участия `student` → `$attempt->isApproved()` (**и для ОГЭ тоже**: экзаменный ОГЭ требует явного утверждения);
  аудитория `guest` → попытка сдана (статус не `in_progress`). Аудиторию читать из участия (`ExamParticipationRepository::find()`), добавив репозиторий
  в конструктор политики. Старые ветки (попытки курса) не менять.
- [x] 7.5.2 `AttemptService::getResult()`: при `! $revealed` кроме ответов зачистить и саму попытку — вернуть копию `AttemptDTO` с `totalScore = null`,
  `maxScore = null` (сейчас итог уходит ученику как есть). Изменение действует на все попытки с отложенным раскрытием; проверить, что экран
  станции не ломается от `null` (тест и ручная проверка КЕГЭ в курсе).
- [x] 7.5.3 `AttemptCallbacks::ajaxSubmitAttempt()`: для экзаменной попытки ответ без баллов уже сделан в 6.1.7. Для гостя (этап 11b) баллы отдаются —
  решение принимать через `AttemptRevealPolicy::isRevealed()`, а не по признаку «экзамен».
- [x] 7.5.4 `AttemptPageService::buildReview()` и `buildForExam()`: ученик, открывший `?attempt=ID` или `?exam_reg=ID` до утверждения, получает
  `reviewReveal = false`, пустой `resultPerTask`, пустые `outcome` и `outcomeState`. Проверить по коду, что `finish.php` при `false` не выводит
  ни баллы, ни правильные ответы, ни ссылки на решения (читать шаблон целиком).
- [x] 7.5.5 Файлы. Найти, отдаёт ли лист результата или карточка задания ссылки на файлы решения/эталона
  (`grep -rn "solution\|answer_file\|task_solution" templates/frontend/assessment inc/Services/Assessment inc/Modules/EgeComputer`).
  Если такие ссылки есть — они формируются только при раскрытии. Исходные материалы задания (условие, прикреплённые к условию файлы) доступны
  во время попытки и после неё — это не эталон.
- [x] 7.5.6 Родитель: любые данные экзамена ребёнка идут через `LearnerExamsService` и `ExamReviewProjection` (7.1), где раскрытие проверяется так же,
  как для ученика. Отдельной «родительской» политики нет.
- [ ] 7.5.7 Сотрудник с правом на проведение (`ExamAccessGuard::canManageEvent()`) видит результат сразу — это режим `manage`, политика раскрытия
  на него не распространяется.

**Тесты**
- `tests/Unit/Services/Assessment/AttemptRevealPolicyTest.php` — дописать:
  `test_exam_student_ege_hidden_until_approved`, `test_exam_student_oge_hidden_until_approved_even_when_graded`,
  `test_exam_guest_revealed_after_submit`, `test_exam_guest_hidden_while_in_progress`,
  `test_course_oge_rule_unchanged`, `test_control_rule_unchanged`.
- `tests/Unit/Services/Assessment/AttemptServiceTest.php`: `test_get_result_hides_totals_when_not_revealed`.
- `tests/Unit/Services/Assessment/AttemptPageServiceReviewTest.php`: `test_student_review_of_unapproved_exam_attempt_has_no_scores`.
- Тест на утечку (ручной, записать команды и вывод в `NOTES.md`): войти учеником со сданной неутверждённой экзаменной попыткой и получить
  1) ответ `get_attempt_result`, 2) страницу станции `?exam_reg=…`, 3) ответ `get_learner_exams`. В выводе не должно быть правильных ответов заданий
  и чисел баллов: `curl -s -b cookies.txt … | grep -c "<известный эталон задания>"` → `0`.

**Готово, когда:** тесты зелёные; три проверки на утечку дают ноль совпадений; КЕГЭ в курсе работает как прежде.

---

## 7.1 Общий renderer задачи с режимами `manage` и `read_only`

**Зачем.** «Работы» преподавателя, разбор ученика, результат гостя и школьный отчёт рисуют задачу одним кодом (SPEC §5, критерий 20).

**Проверить перед началом**
- `src/js/profile/work-review.js`: `taskBlock( t, d )`, `codeBlock()`, `taskFilesBlock()`, `criteriaGradeBlock()`, `ogeRubricGradeBlock()`,
  `answeredAtHtml()`, словарь `VERDICT_LABEL`; обработчики `wireAttemptGrading()`, `wireSubmissionTaskGrading()`, `wireTaskCredit()`.
- `inc/Services/Course/WorkDetailService.php::fromAttempt()` — поля задачи: `n`, `task_id`, `condition`, `answer`, `code`, `files`, `correct`,
  `verdict`, `score`, `max_score`, `manual`, `manually_graded`, `feedback`, `criteria`, `oge_rubric`.
- `src/scss/profile/components/_summary.scss` — классы `sum-task`, `sum-verdict`, `sv-correct`, `sv-incorrect`, `sv-pending`, `sv-corrected`.
- Снимок экрана «Работы» с открытой работой КЕГЭ и ОГЭ **до** изменений — для сравнения.

**Шаги**
- [x] 7.1.1 Создать `src/js/profile/task-render.js`. Перенести из `work-review.js` **без изменения разметки**: `taskBlock` → `export function renderTask( t, ctx )`,
  а также `codeBlock`, `taskFilesBlock`, `criteriaGradeBlock`, `ogeRubricGradeBlock`, `answeredAtHtml`, `VERDICT_LABEL`.
  `ctx = { mode: 'manage' | 'read_only', kind: 'work' | 'exam', canGradeAttempt: bool, canGradeBatch: bool }`.
- [x] 7.1.2 Режимы: в `read_only` функция **не вызывает** блоки оценивания и зачёта (`grade`, `credit` — пустые строки) независимо от полей задачи.
  В `manage` поведение прежнее. Условие «показать эталон» (`showCorrect`) — общее для обоих режимов.
- [x] 7.1.3 `work-review.js`: импортировать `renderTask` и вызывать с `mode: 'manage'`; обработчики `wire*` остаются в `work-review.js`.
  После правки — визуальное сравнение со снимком «до»: разметка задач совпадает.
- [x] 7.1.4 Серверная проекция. `inc/Services/Exam/ExamReviewProjection.php`. Зависимости: `WorkDetailService`, `AssessmentAttemptRepository`,
  `ExamParticipationRepository`, `AttemptRevealPolicy`, `ExamFormatRegistry`, `AssessmentManager`.
  Метод `forViewer( int $attemptId, string $mode ): ?array`:
  - берёт `WorkDetailService::forWork( 'attempt', $attemptId )`;
  - для `read_only`: если `! isRevealed()` → вернуть `array( 'revealed' => false, 'status' => … )` **без `tasks` и баллов**;
    если раскрыто — убрать из каждой задачи поля оценивания: `task_id`, `manual`, `criteria` (оставить только набранные по критериям баллы для показа),
    `oge_rubric` (оставить текст выбранного уровня), `review_url`; из корня — `attempt_id`, `group_id`, `student_name`;
  - для `manage` — отдать как есть, добавив `result_version` попытки.
  Проверку прав вызывающий делает сам (7.4 — ученик, 8.4 — сотрудник); проекция прав не проверяет.
- [x] 7.1.5 Решение задания (текст разбора) — общее расширение блока `sum-task`: поле `solution` в задаче (HTML, если у задания есть разбор;
  посмотреть, где хранится разбор задания: `grep -rn "solution" inc/Enums/Wp/PostMetaName.php inc/Services/Task`). В `renderTask()` — раскрываемый блок
  «Решение» под правильным ответом. В `read_only` до раскрытия поля нет вовсе. Если у заданий разбора в данных нет — блок не добавлять и записать это в `NOTES.md`.

**Тесты**
- `tests/js/task-render.test.mjs`: `read_only` не содержит `stg-save`, `stg-credit`, `sum-task-grade`; `manage` для ручной задачи экзамена содержит форму оценки;
  эталон не выводится при `verdict = correct`; код выводится блоком; файлы выводятся списком.
- `tests/Unit/Services/Exam/ExamReviewProjectionTest.php`: `test_read_only_unrevealed_has_no_tasks_and_scores`,
  `test_read_only_revealed_has_no_grading_identifiers`, `test_manage_has_result_version`,
  `test_oge_files_code_and_tables_are_kept_in_read_only`.
- Существующий `tests/Unit/Services/Course/WorkDetailServiceTest.php` — без изменений, зелёный.

**Готово, когда:** тесты зелёные; экран «Работы» визуально и функционально прежний (оценить задание, утвердить работу, зачесть задание).

---

## 7.2 Статусы задания и устойчивые якоря

**Зачем.** Пять статусов: верно, неверно, не решено, частично, проверяется. Клик по заданию ведёт к этому заданию, а не к строке с тем же индексом (SPEC §5, критерий 22).

**Проверить перед началом**
- `WorkDetailService::fromAttempt()`: `verdict` = `pending` / `correct` / `incorrect` (по `is_correct`).
- `inc/Services/Assessment/ScoringUnits.php::keysFor()` — ключ единицы оценивания: `n:<номер>` или `t:<task_id>`; задания одного номера делят ключ.
- `_summary.scss`: есть `sv-corrected` (жёлтый, токен `--wait`) — фон для «Частично» уже существует как токен.

**Шаги**
- [x] 7.2.1 `WorkDetailService::fromAttempt()` — расчёт `verdict`:
  | Условие | `verdict` |
  |---|---|
  | `is_correct === null` | `pending` |
  | ответ пуст (после `trim`, для файловых — нет ни текста, ни файлов, для кода — нет кода) и балл 0 | `unanswered` |
  | `0 < score < max_score` | `partial` |
  | `is_correct` истинно | `correct` |
  | иначе | `incorrect` |
  Порядок проверок — как в таблице. `pending` не превращается в 0, пустой ответ не считается «неверно».
  Задание работы, на которое **нет строки ответа** (ученик не открывал), сейчас в список не попадает — добавить такие задания по `assessment->taskIds`
  со статусом `unanswered`, баллом `0` и максимумом из формата.
- [x] 7.2.2 В каждую задачу добавить `unit_key` (из `ScoringUnits::keysFor()`), `number` (номер задания строкой, без префикса) и `anchor` —
  `'u-' . md5( unit_key . ':' . task_id )` (устойчив к порядку и повторам номера). Поле `n` (порядковый счётчик) оставить для подписи «Задача N».
- [x] 7.2.3 `task-render.js`: `VERDICT_LABEL` дополнить `unanswered: 'Не решено'`, `partial: 'Частично'`; корневому `div.sum-task` — `id="${t.anchor}"`.
- [x] 7.2.4 `_summary.scss`: `.sv-partial` и `.sv-unanswered` — цвета токенами (`--wait` для обоих допустимо только если макет так показывает;
  иначе `unanswered` — нейтральный, как `sv-pending`). Свериться с `../student.png`: «Верно» зелёный, «Не решено» жёлтый, «Неверно» красный.
  «Частично верно» — новый вердикт (`../QA.md`, «Новое» п. 5).
- [x] 7.2.5 Повторы и составные задания: несколько заданий одного номера (`unit_key` совпадает) в перечне показываются отдельными строками, но с общим
  номером; балл единицы считается по `ScoringUnits::totals()` (7.3), а не суммой строк.

**Тесты**
- `tests/Unit/Services/Course/WorkDetailServiceTest.php` — дописать: `test_verdict_unanswered_for_empty_answer`,
  `test_verdict_partial_for_fractional_score`, `test_verdict_pending_is_not_zeroed`,
  `test_task_without_answer_row_is_listed_as_unanswered`, `test_anchor_is_stable_and_unique_for_repeated_numbers`.
- `task-render.test.mjs`: подписи новых вердиктов, `id` якоря.

**Готово, когда:** тесты зелёные; в «Работах» задания без ответа помечены «Не решено», частично решённое №26 — «Частично».

---

## 7.3 Баллы КЕГЭ и ОГЭ

**Зачем.** КЕГЭ: 27 единиц, 29 первичных, 100 вторичных, №26–27 по 2. ОГЭ: первичные из 21 и отметка 2–5. Клиент получает готовые числа (SPEC §5, критерий 19).

**Проверить перед началом**
- `ExamFormatDTO` (0.3): `primaryMax`, `secondaryMax`, `gradeMax`, `translate()`, `unitMax()`.
- `ScoringUnits::totals()` — итог по единицам; `SecondaryScoreService::translate( float $primary, array $scoreMap ): ?int`.
- Снимок варианта в проведении (`variant_snapshot`: `scale`, `primary_max`) — история не пересчитывается при смене конфигурации модуля.
- `assessment_attempts.total_score` / `max_score` — уже посчитаны по единицам при проверке (`AutoGradeService`).

**Шаги**
- [x] 7.3.1 `inc/Services/Exam/ExamScoreService.php` (добавить в README §7.4). Зависимости: `ExamFormatRegistry`, `ExamEventRepository`, `AssessmentManager`.
  Метод `summarize( AttemptDTO $attempt, ExamEventDTO $event ): array`:
  ```php
  array(
      'direction'     => 'ege' | 'oge',
      'primary'       => int,          // округлённый total_score
      'primary_max'   => int,          // из снимка проведения, иначе из формата
      'secondary'     => ?int,         // ЕГЭ: шкала[primary]; ОГЭ: null
      'secondary_max' => ?int,         // 100 / null
      'grade'         => ?int,         // ОГЭ: отметка; ЕГЭ: null
      'grade_max'     => ?int,         // 5 / null
      'pending'       => bool,         // есть задания на ручной проверке
      'final'         => bool,         // ! pending
  )
  ```
  Шкала и максимум — **из снимка проведения**; формат модуля — только запасной путь, когда снимка нет.
- [x] 7.3.2 При `pending = true` поля `secondary` и `grade` равны `null`: неподтверждённый итог окончательным не показывается (ручная часть ОГЭ).
  `primary` при этом — сумма уже проверенного, с пометкой на клиенте «предварительно».
- [x] 7.3.3 Баллы по единицам для перечня заданий: `units( AttemptDTO $attempt, array $tasks ): array` — по `unit_key`: `number`, `score`, `max`
  (`unitMax( number )` из формата), `status` (худший из статусов заданий единицы: `pending` > `unanswered`/`incorrect` > `partial` > `correct`).
- [x] 7.3.4 Не писать в коде и текстах «22 первичных = 72»: значения получаются только из шкалы.

**Тесты** — `tests/Unit/Services/Exam/ExamScoreServiceTest.php`:
- `test_kege_18_primary_is_72_secondary`, `test_kege_22_primary_is_83_secondary`, `test_kege_max_is_29_and_100`;
- `test_kege_task_26_and_27_have_max_2`;
- `test_repeated_numbers_count_as_one_unit` — три задания №14 дают максимум 1;
- `test_oge_returns_primary_of_21_and_grade_not_secondary`;
- `test_oge_pending_manual_part_has_no_grade_and_is_not_final`;
- `test_scale_is_taken_from_event_snapshot_not_module` — снимок и формат различаются, побеждает снимок;
- существующие `ScoringUnitsTest`, `SecondaryScoreServiceTest` — без изменений, зелёные.

**Готово, когда:** тесты зелёные.

---

## 7.4 Экран «Результаты» и перечень заданий в карточке

**Зачем.** После утверждения ученик и родитель видят итог на плитке, прогресс-бар, кнопку «Результаты» и перечень заданий с бейджами;
клик по заданию открывает полноэкранный разбор и прокручивает к этому заданию (SPEC §5).

**Проверить перед началом**
- 7.5 выполнен (утечек нет), 7.1–7.3 готовы.
- `learner-exams.js` (этап 5): состояния `awaiting_approval`, `approved`; шаблон `resultCaption()` (5.6.4).
- `app.js`: как экран `work-review` монтируется вне `cfg.screens` (`buildStage()`, `mountScreens()`, `openWorkReviewFrom()`).
- Существующие классы строки задания «Моих курсов»: `sc-row`, `sc-pill` (`learner.js::scRowHtml()`).

**Шаги**
- [x] 7.4.1 `LearnerExamsService`: для состояния `approved` добавить в карточку `result` (`ExamScoreService::summarize()`), `approved_at`
  и `units` — перечень единиц: `number`, `status`, `anchor` (якорь первого задания единицы). **Только при раскрытии**; в остальных состояниях ключей нет.
- [x] 7.4.2 Экшен `GetExamReview` в `LearnerExamCallbacks` (`event_id`, `student_person_id?`; nonce `ExamLearner`): личность — через `ProfileContext`
  (ученик — себя, родитель — своего ребёнка); попытка — `current_attempt_id` участия этой личности в проведении; ответ —
  `ExamReviewProjection::forViewer( $attemptId, 'read_only' )` + `result`. Чужой `event_id` или отсутствие участия — «Результат недоступен.» без подробностей.
  **`attempt_id` из запроса не принимается.**
- [x] 7.4.3 `learner-exams.js`, состояние `approved`: подпись плитки — `resultCaption()`; в карточке прогресс-бар (`sc-hprog`, доля `primary / primary_max`),
  справа кнопка «Результаты»; **на месте карусели сеансов — перечень заданий** строками `sc-row` с бейджем `sc-pill`:
  «Верно» (зелёный), «Не решено» (жёлтый), «Неверно» (красный), «Частично», «Проверяется».
- [x] 7.4.4 Экран `exam-review` — `src/js/profile/exams/exam-review.js`: `renderExamReview( root, { onBack } )`, `openExamReview( eventId, anchor? )`.
  Секция экрана добавляется в `buildStage()` так же, как `work-review` (вне меню). Шапка: название, дата сдачи, итог (`resultCaption`), кнопка «‹ Назад».
  Задачи — `renderTask( t, { mode: 'read_only', kind: 'exam' } )`. В шапке задачи: номер, вердикт, время ответа, балл/максимум справа.
- [x] 7.4.5 Клик по строке перечня → `openExamReview( eventId, anchor )` → после отрисовки `document.getElementById( anchor ).scrollIntoView()`.
  Переход ведёт к фактическому заданию, а не всегда ко второму (критерий 22) — покрыть e2e.
- [x] 7.4.6 Родитель: тот же экран, те же данные ребёнка; кнопок запуска и оценивания нет (их нет и в `read_only`).
- [x] 7.4.7 Нет кнопок «Перерешать», «Решить самостоятельно», нет режима тренировки (SPEC §6). Проверка:
  `grep -rn "practice\|Перерешать\|retry" src/js/profile/exams` → пусто.

**Тесты**
- `LearnerExamsServiceTest.php`: `test_approved_card_has_result_and_units`, `test_unapproved_card_has_no_result_keys`.
- `LearnerExamCallbacksTest.php`: `test_review_ignores_attempt_id_param`, `test_review_denied_for_foreign_event`,
  `test_review_before_approval_returns_unrevealed_without_tasks`, `test_parent_gets_review_of_own_child_only`.
- e2e: утвердить работу (на этом этапе — вручную: `UPDATE wp_fs_lms_assessment_attempts SET approved_at = NOW(), approved_by_user_id = 1 WHERE id = …`),
  открыть «Мои экзамены» → плитка с итогом → клик по заданию №14 → экран разбора прокручен к №14.
- Визуальная сверка: экран разбора ученика рядом с экраном «Работы» преподавателя для той же попытки — задачи выглядят одинаково.

**Готово, когда:** тесты и e2e пройдены для КЕГЭ и ОГЭ; таблицы, код и файлы ответов ОГЭ в разборе на месте.

---

## Проверка этапа (SPEC §16: 15, 19, 20, 24)

- [x] Тесты на утечку: до утверждения ни HTML, ни JSON, ни файлы не содержат баллов, эталонов и решений. — `LearnerExamsServiceTest`, `ExamReviewProjectionTest`, `AttemptPageServiceReviewTest`; сквозной проход в браузере и по HTTP
- [x] КЕГЭ: 27 единиц, 29/100, №26–27 по 2; 18→72, 22→83. ОГЭ: первичные из 21 и отметка. — `ExamFormatsProviderTest`, `ExamScoreServiceTest` (18→72, 22→83); ОГЭ — первичные из 21 и отметка
- [x] Один renderer в «Работах» и в разборе ученика; у `read_only` нет скрытых кнопок оценивания (`grep` по HTML ответа). — `task-render.js`, `tests/js/task-render.test.mjs`
- [x] Перерешивания и тренировочных экшенов нет. — `grep "practice|Перерешать|retry" src/js/profile/exams` пусто
- [x] `npm run ci`, `npx gulp build` — зелёные. Новые вердикты занесены в `../QA.md`. — по частям (2026-10-04): `eslint .` и `stylelint` без ошибок, `gulp styles:check` и `gulp build` успешны, PHPUnit в контейнере 2727 тестов без падений, `npm run test:js` 79 тестов; целиком `npm run ci` на Windows-хосте не идёт: `npm test` вызывает `vendor/bin/phpunit`, который хост не запускает
