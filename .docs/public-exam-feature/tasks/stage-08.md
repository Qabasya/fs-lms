# Этап 8. «Проведение экзамена» и утверждение

Зависимости: этапы 6 и 7. Результат: сотрудник ведёт сеанс (таблица участников, отмена, перенос, продление, отметка прихода),
управляет жизненным циклом сеанса и проведения, проверяет работы, утверждает их поштучно и массово, исправляет результат.

Перед началом прочитать `README.md`. SPEC: §3 (последний абзац), §7 целиком, §8 (CSV), §16 критерии 6, 8, 16, 27, 28, 29.
Макет: `../session.png`, `../mobile-session.png`, `../teacher-review.png`, `../guests.png`, `../payments.png`, `../QA.md`.

**Нумерация.** В плане было два пункта 8.3 — здесь пункты идут подряд 8.1–8.9 (жизненный цикл — 8.3, экран проверки — 8.4,
утверждение — 8.5, исправление — 8.6, «Результаты» — 8.7, гости — 8.8, печать и CSV — 8.9).

**Порядок:** 8.1 → 8.2 → 8.3 → 8.4 → 8.5 → 8.6 → 8.7 → 8.9 → 8.8 (8.8 зависит от данных этапа 11a; до него экран работает без гостей).

## Статус (2026-10-09)

**Сделано и проверено — 8.1–8.7 и 8.9** (подзадачи выше с `[x]`; пункт 8.8 ждёт этап 11a, его подзадачи не отмечены).

Проверено: PHPUnit (2823 теста), `npx eslint src/js`, `npx stylelint "src/scss/profile/**/*.scss"`, `npx gulp build`, загрузка DI для всех сервисов `Init::getServices()`,
стенд на настоящей MariaDB (`wp fs-lms exam stand-attempts|stand-approve|stand-approve-all|stand-correct`), сквозные запросы по HTTP и браузерный проход (headless Chrome по CDP):
- 8 параллельных `approve` одной попытки → 1 `approved` + 7 `already_approved`, в outbox одно событие `attempt_approved`;
- 50 попыток: «Утвердить все» — `approved=49 skipped=1` (одна уже утверждена гонкой) за 29 мс на сервисе; повтор — `approved=0 skipped=50`; в outbox ровно 50 событий;
- два проверяющих исправляют одну работу с одной версией → 1 `corrected` + 1 `X-STALE`; `answer_text` не тронут, версия 0→1, в журнале изменений «Итог 1→2; №1: 1→2. Причина: …»;
- по HTTP: доска сеанса, список печати (без контактов), CSV (одноразовая ссылка, шесть колонок, без контактов), «Результаты» с фильтрами; в браузере: доска (50 строк, плитки, меню действий),
  «Открыть работу» → экран проверки с кнопкой «Исправить результат» и панелью исправления, возврат «‹ Назад» на доску, меню сеанса («Перенести сеанс», «Отменить сеанс»), ошибок JS и ответов ≥ 400 нет.

**Не проверено (прямо):**
- PHPStan уровня 5: `vendor/bin/phpstan` в проекте отсутствует.
- Сохранение исправления и массовое утверждение кликом в браузере (подтверждающий диалог, тост): на стенде у заданий попытки нет соответствующих строк в условиях работы; серверная часть и HTTP проверены, клиентская логика — только сборкой и линтерами.
- Ручные пункты «Готово, когда» 8.2 и 8.3 («ученик видит причину отмены в „Моих экзаменах“», «карточка ученика показывает новую дату после переноса», «станция показывает новый остаток после продления») — на реальном ученике не прогонялись.
- Гонка «`approve` против `correct`» параллельными процессами; `moveSession`/`cancelSession` на настоящей базе (только юнит-тесты на моках).
- Размер бандла `profile.min.js` теперь 246 КиБ — предупреждение webpack о лимите 244 КиБ (не ошибка сборки).

**Что изменилось по сравнению с текстом этапа:**
- `ExamConductService` — доска, результаты (`results()`), строки для CSV/печати (`exportRows()`), решение «можно ли работать с экзаменной попыткой» (`canManageAttempt()`) и разбор для экрана проверки (`reviewFor()`).
  `ExamAccessGuard::canManageEvent()` уже существовал — вызывается напрямую.
- `ExamApprovalService` (`approve`, `approveMany`, `correct`) — зависит от `AutoGradeService` и `LogEventDispatcherInterface`. Журнал исправления — `LogEvent::ExamResultCorrected` / `EntityType::ExamAttempt`
  (метка до 255 знаков: причина и пары «было → стало»); полный перечень пар — в событии outbox `ResultCorrected` (`changes`, `old_total`, `new_total`).
- `ExamEventService` получил `moveSession`, `cancelSession`, `cancelEvent` с отменой записей и броней, `completeIfDone`, `completionCandidates`; зависит от `ExamRegistrationService`, `ExamHoldService`, `ExamGuestApplicationRepository`.
  Отмена сеанса и проведения **сначала закрывает сеанс/проведение** (новые записи и старты невозможны), затем отменяет записи по одной в своих транзакциях; повторный вызов `cancelSession` доводит оставшиеся.
- `ExamTickService::autoExpireTick()` теперь возвращает ещё `completed` и завершает проведения после неявок; `wp fs-lms exam tick` печатает и это число.
- Новые экшены: `GetExamConduct`, `CancelExamRegistrationByStaff`, `TransferExamRegistration`, `ExtendExamAttempt`, `MarkExamArrival`, `ApproveExamAttempts`, `CorrectExamResult`, `ExportExamParticipants`, `GetExamPrintList`,
  `GetExamResults`, `MoveExamSession`, `CancelExamSession`; все под nonce `ExamManage`.
- Выгрузка и печать списка: права `ManageLmsPlatform` + `ExportPII` (`authorizeAll`) **с nonce `ExamManage`** (nonce `Manager` в кабинете преподавателя нет) и `canManageEvent()`; в конфиг добавлен `canExportPii`.
  **Печать не через `PrintDocument`:** «Центр печати» формирует DOCX/PDF по одному ученику и для списка не подходит — список печатается страницей кабинета (`window.print()` + `@media print`), данные отдаёт `GetExamPrintList`.
- `CsvExportService::neutralizeFormula()` — для всех выгрузок. Номера вида `+7999…` теперь получают ведущий апостроф (число — только `-?\d+([.,]\d+)?`). Попутно `fputcsv` получил явный параметр `escape` (устаревание PHP 8.4+).
- Закрыта дыра: `GradeAttemptCallbacks` больше не пропускает проверку при `group_id = NULL` — попытка без группы и без экзаменного контекста отклоняется; экзаменная проверяется по проведению.
  Утверждённую экзаменную попытку обычной оценкой не править (`error`): только «Исправить результат» с причиной.
- `ResetAttempts` для экзаменной попытки отвечает отказом; кнопка «Сбросить попытки» на экране проверки экзамена не рисуется.
- Переключатель сеансов на доске — `prof-seg`, а не `sc-tabs` (сеансов немного, лента со стрелками не нужна).
- Тест-заготовка `test_oge_exam_attempt_requires_explicit_approval` проверяет, что готовая работа сама не утверждается (отдельного ОГЭ-сценария на моках нет: правило одно для обоих направлений).

---

## Исходное состояние (после этапов 0–7, проверено 2026-10-04)

Этап строится на готовом и проверенном; новых аналогов не писать.

**Уже есть и используется как есть:**
- `ExamRegistrationService::cancelByStaff()` и `transferByStaff()` — внутри уже проверка пересечения по времени, блокировка участника и участия, outbox; 8.2 добавляет только транспорт и права.
- `ExamAttemptService::extend()` (6.2.4) — готов, работает под блокировкой участия; 8.2.1 `ExtendExamAttempt` только вызывает его (`CodedException` → `fail()`).
- `ExamReviewProjection::forViewer( $attemptId, 'manage' )` отдаёт полный разбор и `result_version`; `ExamScoreService::summarize()` и `units()` дают итог и единицы.
- `ExamNoShowService`, `ExamTickService` и минутный тик в `CronController` (`wp fs-lms exam tick`) — собственного cron у этапа 8 нет.
- `AttemptService::isRevealed()` — единственное решение о раскрытии для ученика; режим сотрудника (`manage`) его не использует (7.5.7 — здесь).
- `task-render.js` с режимом `manage` и флагами `canGradeAttempt`/`canGradeBatch` — экран «Работы» уже рисует им задачи; `work-review.js` для экзаменной попытки подключает оценивание тем же контекстом (`taskContext( d )`).
- `ExamOutbox::add()` вызывается внутри транзакций всех готовых операций; воркер и уведомления — этап 9.

**Предусловий, которых в коде ещё нет, — они блокируют пункты ниже (сделать раньше или в этом же этапе):**
- `ExamEventService` / `ExamRoomService` (2.4, 4.4) — нужны 8.3 (`moveSession`, `cancelSession`, `cancelEvent`, `completeIfDone`) и 8.1.3 (`lateStartConflicts()`). Файлов нет.
- `exam-conduct.js` и меню «Проведение экзамена» (4.1.4, 4.7.4) — заглушки не созданы; 8.1.6 создаёт экран с нуля.
- В `ExamAccessGuard` реально есть `canManageSubject( $userId, $subjectKey )` (им уже пользуются `cancelByStaff()`, `transferByStaff()`, `extend()`); `canManageEvent( $userId, ExamEventDTO )` из 2.4.1 — тонкая обёртка над ним
  (`event->subjectKey`), появится вместе с `ExamEventService`. Везде ниже, где написано `canManageEvent()`, до 2.4.1 вызывать `canManageSubject()`. Коллбеков сотрудника (`ExamConductCallbacks`, `ExamEventCallbacks`) ещё нет.
- Права `ManageExams` выданы ролям (`capsVersion = 5.8`), блок конфига `exams.actions` для преподавателя (`TeacherProfileView::teacherConfig()`) пока не содержит экшенов сотрудника.

**Правила, обязательные для нового кода этапа** (из рефакторинга; подробно — `inc/Services/Exam/CLAUDE.md`):
- Новые репозитории наследуют `AbstractExamRepository`; методы записи с проверкой числа затронутых строк (например, `bumpResultVersion()` — `updateRow()` и сравнение с 1, а не `update(): bool`).
- В каждой транзакции **первый оператор — `FOR UPDATE`** (участие или попытка), идентификаторы определяются до `START TRANSACTION`; версия (`result_version`, `expectedVersion`) сверяется уже под блокировкой.
  Массовое утверждение — по транзакции на работу; перечитывание состояния работы — только после её блокировки.
- Отказы сотрудника — `CodedException` с кодом (`ExamStale`, `ExamAccess`, `ExamConflict`, `ExamStarted`), текст клиенту через `ajaxErrorText()`.
- Гонки утверждения и исправления (параллельные `approve` одной попытки → ровно одно событие `AttemptApproved`; `approve` против `correct`) проверять на настоящей MariaDB
  по образцу смоука этапа 6, а не только юнит-тестами.
- Время сравнивать только через `ExamTime`/`ClockInterface`, не `NOW()` базы (на dev пояс БД и сайта расходятся на 3 часа).
- Тексты, SCSS, иконки — по `README.md` §3; в SCSS кабинета — токены ядра (`shared/_tokens.scss`), без чисел и без inline-стилей.
- Заглушки будущих этапов (гости, оплата) в `Init::getServices()` не регистрировать; «Гости» на экране сеанса работают без данных до 11a (пункт 8.8).

## Общие правила этапа

- Коллбеки сотрудника: `$this->authorize( Nonce::ExamManage, Capability::ManageExams )`, затем `ExamAccessGuard::canManageEvent()`.
  Старые коллбеки проверки (`GradeAttemptCallbacks`, `GradingCallbacks`) привязаны к группе (`group_id`); у экзаменной попытки группы нет —
  для неё доступ решает проведение.
- Каждое изменяющее действие пишет событие в outbox **в той же транзакции** (уведомления строит этап 9).
- Имена участников: ученик — снимок ФИО из `student_records` (как на остальных экранах преподавателя), гость — расшифрованное ФИО из
  `exam_participants` (право `ManageExamGuests`). Контакты гостя в таблице сеанса не выводятся.
- Все даты в ответах — местное время; «остаток» считает сервер (`seconds_left`), клиент только тикает от него.

---

## 8.1 Таблица сеанса

**Зачем.** Экран «Проведение экзамена»: кто записан, кто начал, кто сдал, кто не явился, сколько осталось (SPEC §7).

**Проверить перед началом**
- Экран-заглушка `src/js/profile/exams/exam-conduct.js` и `openExamConductFor( sessionId )` (4.1.4, 4.7.4).
- Существующие классы (`../QA.md`): `sc-tabs` (сеансы), `prof-stat-tiles`, `pr-row` (строки участников), `prof-state-pill`, меню `prof-ctx-menu` + `ctx-item`.
- `ExamRoomService::lateStartConflicts()` (4.4.5).
- `NotificationService::studentSnapshotName( int $studentPersonId, int $groupId )` — снимок имени требует группу; для экзамена нужен вариант без группы.

**Шаги**
- [x] 8.1.1 `inc/Services/Exam/ExamConductService.php`. Зависимости: репозитории проведений, сеансов, записей, участий, участников, попыток;
  `StudentRecordRepository`, `ExamRoomService`, `ExamScoreService`, `ExamAccessGuard`, `ExamTime`.
- [x] 8.1.2 `participantName( ExamParticipantDTO $p ): string` — для `person_id`: фамилия и имя из последней активной записи `student_records`
  (`findActiveByStudentFirst()`; если активной нет — из любой, `findByStudent()`); для гостя — расшифровка `name_enc` (сервис расшифровки появится в 11a;
  до него — «Гость #{id}»).
- [x] 8.1.3 `sessionBoard( int $actorUserId, int $sessionId ): array`:
  - `session`: id, название проведения, дата, время начала и планового конца, кабинет, `capacity`, `occupied`, статус, `is_locked`, `version`;
  - `tiles`: записано, начали, сдали, не явились, ожидают проверки (числа);
  - `rows` — по одной на участие с записью в этом сеансе (действующей **или** исторической `missed`/`cancelled` этого сеанса):
    `registration_id`, `participation_id`, `name`, `audience`, `audience_label`, `source` (школа источника, для гостя), `registration_status`,
    `arrived_at`, `progress` (`ExamProgress`), `progress_label`, `started_at`, `deadline_at`, `seconds_left`, `attempt_id`,
    `result_status` (`none` / `pending_review` / `ready` / `approved`), `result_status_label`, `actions` (список разрешённых действий);
  - `warnings` — из `lateStartConflicts()` для самого позднего личного дедлайна среди идущих попыток: «После планового конца в кабинете
    стоит {занятие или экзамен} в {время}.» Только информация, ничего не блокируется;
  - `sessions` — сеансы этого проведения для переключателя.
  Баллы в строках: `score` (итог `ExamScoreService::summarize()`) — сотруднику видны сразу.
- [x] 8.1.4 `result_status`: нет попытки → `none`; сдана, есть задания на ручной проверке → `pending_review`; проверка завершена, не утверждена → `ready`;
  утверждена → `approved`. Для гостя утверждение не требуется: `ready` и `approved` для него равнозначны, подпись — «Результат выдан».
- [x] 8.1.5 `inc/Callbacks/Exam/ExamConductCallbacks.php`, экшен `GetExamConduct` (`session_id?`, `event_id?`): без параметров — ближайший сеанс
  пользователя (идущий сейчас, иначе следующий); зарегистрировать в `ExamController`, действия — в блок `exams.actions` (`TeacherProfileView`).
- [x] 8.1.6 `exam-conduct.js`: переключатель сеансов (`sc-tabs`), плитки (`prof-stat-tiles`), строки (`pr-row`) с пилюлями состояния, меню действий
  в конце строки. Автообновление — повторный запрос раз в 30 секунд, пока экран активен и вкладка видима; между запросами «остаток» уменьшается
  таймером на клиенте от `seconds_left`. Перезагрузка страницы ничего не продлевает: дедлайн хранится на сервере.
- [x] 8.1.7 Пустые состояния: нет проведений — «Экзамены не назначены.» со ссылкой на «Назначить экзамен»; в сеансе нет записей — «На этот сеанс пока никто не записан.»

**Тесты**
- `tests/Unit/Services/Exam/ExamConductServiceTest.php`: `test_board_tiles_count_registered_started_submitted_missed`,
  `test_row_progress_for_each_state`, `test_result_status_pending_review_for_manual_tasks`,
  `test_missed_row_of_this_session_is_listed`, `test_late_start_warning_present_when_room_busy_after_end`,
  `test_board_denied_for_foreign_event`, `test_student_name_is_snapshot_not_decrypted_document`.
- `tests/Unit/Callbacks/Exam/ExamConductCallbacksTest.php`: `test_requires_manage_exams`, `test_default_session_is_running_or_next`.

**Готово, когда:** тесты зелёные; на dev экран показывает сеанс с записанным учеником, «остаток» идёт у начавшего.

---

## 8.2 Действия над участником

**Зачем.** Отменить не начатую запись с причиной, назначить другой сеанс, продлить активную попытку, отметить приход, открыть работу (SPEC §7).

**Проверить перед началом**
- Готовые методы: `ExamRegistrationService::cancelByStaff()`, `transferByStaff()` (3.2), `ExamAttemptService::extend()` (6.2.4).
- Колонки `exam_registrations.arrived_at`, `arrived_by_user_id`.

**Шаги**
- [x] 8.2.1 Экшены в `ExamConductCallbacks`:
  | `AjaxHook` | Параметры | Сервис |
  |---|---|---|
  | `CancelExamRegistrationByStaff` | `registration_id`, `reason` | `cancelByStaff()` |
  | `TransferExamRegistration` | `registration_id`, `session_id`, `reason` | `transferByStaff()` |
  | `ExtendExamAttempt` | `attempt_id`, `minutes`, `reason` | `ExamAttemptService::extend()` |
  | `MarkExamArrival` | `registration_id`, `arrived` (0/1) | `ExamConductService::markArrival()` |
  Ответ каждого — обновлённая доска сеанса (`sessionBoard()`), чтобы клиент перерисовал экран одним ответом.
- [x] 8.2.2 `ExamConductService::markArrival( int $actorUserId, int $registrationId, bool $arrived ): void` — запись действующая; ставит или снимает
  `arrived_at`/`arrived_by_user_id`. Атрибут операционный: на неявку и допуск **не влияет**. Outbox не пишется (уведомлений нет).
- [x] 8.2.3 Разрешённые действия строки (`actions` в 8.1.3):
  | Действие | Когда доступно |
  |---|---|
  | `cancel` | запись действующая, попытки нет |
  | `transfer` | запись действующая, попытки нет, в проведении есть другой сеанс со свободным местом, не завершившийся |
  | `extend` | попытка в процессе |
  | `arrival` | запись действующая, попытки нет |
  | `open_work` | попытка сдана |
  Для гостя перенос сохраняет оплату: запись остаётся у того же участия (3.2.4), нового оформления нет.
- [x] 8.2.4 Формы действий — поповер `prof-grade-pop` + `gp-form`: «Отменить запись» (обязательная причина), «Назначить другой сеанс»
  (список сеансов с датой, временем, свободными местами + обязательная причина), «Продлить» (минуты `1…120`, обязательная причина; показать новый дедлайн
  после ответа сервера). Подтверждение отмены — `confirmDialog()`.
- [x] 8.2.5 Продление проверяет кабинет: если новый дедлайн выходит за плановый конец и кабинет занят — сервер не отказывает, а возвращает
  предупреждение в `warnings` доски (см. 8.1.3).
- [x] 8.2.6 Действие кнопки называется «Отметить приход» (не «Пришёл»): тексты без рода (`../TEXTS.md` §4).

**Тесты**
- `ExamConductCallbacksTest.php`: `test_cancel_requires_reason`, `test_cancel_delegates_and_returns_board`,
  `test_transfer_delegates_with_reason`, `test_extend_delegates_minutes_and_reason`,
  `test_extend_rejects_minutes_out_of_range`, `test_actions_denied_for_foreign_event`.
- `ExamConductServiceTest.php`: `test_mark_arrival_sets_and_clears`, `test_arrival_does_not_change_registration_status`,
  `test_row_actions_for_each_state`.

**Готово, когда:** тесты зелёные; вручную: отменить запись с причиной (ученик видит причину в «Моих экзаменах» и может записаться снова),
продлить идущую попытку на 10 минут (станция после обновления показывает новый остаток).

---

## 8.3 Жизненный цикл сеанса и проведения

**Зачем.** После первого старта общие параметры сеанса не меняются. Сеанс с участниками переносится и отменяется только явным действием с причиной.
Отмена проведения отменяет записи. Проведение завершается, когда закрыты все попытки (SPEC §3).

**Проверить перед началом**
- `ExamEventService::saveSession()` уже запрещает менять запертый сеанс (2.4.6); `cancelEvent()` содержит `// TODO(8.3)` (2.4.10, 4.5.6).
- `ExamSessionDTO::isLocked()` (`first_started_at`).

**Шаги**
- [x] 8.3.1 `ExamEventService::moveSession( int $actorUserId, int $sessionId, array $input, string $reason, int $expectedVersion ): ExamSessionDTO` —
  перенос сеанса **с участниками**: причина обязательна; сеанс не заперт (ни одной попытки); новые дата/время/кабинет проходят те же проверки, что в
  `saveSession()`; записи участников остаются на сеансе; outbox `SessionMoved` (`old_scheduled_at`, `new_scheduled_at`, `old_room_id`, `new_room_id`, `reason`).
  Сеанс **без** записей по-прежнему правится обычным `saveSession()` без причины.
- [x] 8.3.2 `saveSession()` — если у сеанса есть действующие записи и меняются дата, время или кабинет, отказ: «В сеансе есть участники: используйте перенос с причиной.»
- [x] 8.3.3 `cancelSession( int $actorUserId, int $sessionId, string $reason, int $expectedVersion ): void` — причина обязательна; сеанс не заперт;
  статус `cancelled`; каждая действующая запись отменяется через `ExamRegistrationService::cancelByStaff()` с той же причиной (место освобождается,
  участник может записаться на другой сеанс); брони гостей этого сеанса освобождаются (`ExamHoldService::release( …, Cancelled )`); outbox `SessionCancelled`.
  Сеанс с начатыми попытками отменить нельзя: «Сеанс уже начат.»
- [x] 8.3.4 `cancelEvent()` — снять ограничение `TODO(8.3)`: отменить все сеансы без попыток через `cancelSession()`; сеансы с начатыми попытками
  не отменяются — если такие есть, проведение отменить нельзя («Экзамен уже начат: отмена невозможна.»). Outbox `EventCancelled` (причина).
  После отмены активных входов и повторной записи в это проведение нет (состояние карточки `event_cancelled`, 5.2.2).
- [x] 8.3.5 Завершение. `completeIfDone( int $eventId ): bool` — проведение `published`; все сеансы закончились (`planned_end_at <= now`) или отменены;
  нет действующих записей без исхода; нет попыток `in_progress`. Тогда статус `completed`, `completed_at`. Сеансы → `completed`.
  Вызывать из `ExamTickService::autoExpire()` после неявок (по проведениям, у которых что-то изменилось в этом тике). Завершённое проведение остаётся
  доступным для проверки, утверждения и исправления результатов.
- [x] 8.3.6 Экшены в `ExamEventCallbacks`: `MoveExamSession`, `CancelExamSession`; в интерфейсе — пункты меню сеанса на экране «Назначить экзамен» и
  «Проведение экзамена» с формой причины. Кнопка «Отменить проведение» (4.5.6) теперь доступна и для проведений с записями.

**Тесты** — `ExamEventServiceTest.php`:
- `test_move_session_requires_reason_and_writes_outbox`;
- `test_move_locked_session_is_denied`;
- `test_save_session_with_participants_is_denied_without_move`;
- `test_cancel_session_cancels_registrations_and_releases_holds`;
- `test_cancel_session_with_started_attempt_is_denied`;
- `test_cancel_event_cancels_all_unstarted_sessions`;
- `test_cancel_event_denied_when_any_attempt_started`;
- `test_complete_when_all_sessions_ended_and_no_active_attempts`;
- `test_not_completed_while_attempt_in_progress`.

**Готово, когда:** тесты зелёные; вручную: перенести сеанс с записанным учеником — карточка ученика показывает новую дату.

---

## 8.4 Экран проверки

**Зачем.** Работа открывается в существующем экране «Работы» в режиме управления; ручная часть ОГЭ оценивается там же; работа с непроверенными
заданиями не получает фиктивного итога (SPEC §7).

**Проверить перед началом**
- `src/js/profile/work-review.js`: `openWorkReview( sourceType, sourceId, from )`, загрузка через `GetWorkDetail`.
- `inc/Callbacks/Course/GradingCallbacks.php::ajaxGetWorkDetail()` — доступ по `group_id` (`GroupAccessGuard::canManage`), право `ManageLmsTeaching`.
- `inc/Callbacks/Assessment/GradeAttemptCallbacks.php`: `ajaxGradeAttempt()`, `ajaxApproveAttempt()` — доступ по группе попытки; при `group_id = null`
  проверка группы **пропускается** (`if ( $attempt->groupId && … )`). Для экзаменной попытки это дыра: любой преподаватель с `ManageLmsTeaching`
  смог бы оценить чужой экзамен.
- `ExamReviewProjection::forViewer( …, 'manage' )` (7.1.4).

**Шаги**
- [x] 8.4.1 Закрыть дыру: общий приватный метод в `GradeAttemptCallbacks` — `canGrade( AttemptDTO $attempt, int $userId ): bool`:
  экзаменная попытка (`isExam()`) → `ExamAccessGuard::canManageEvent()` по проведению участия **и** право `ManageExams`; иначе — прежняя проверка группы.
  Использовать в `ajaxGradeAttempt()` и `ajaxApproveAttempt()`. Для попытки без группы и без экзаменного контекста — отказ.
- [x] 8.4.2 `GradingCallbacks::ajaxGetWorkDetail()`: если источник — попытка и она экзаменная, доступ решает `canManageEvent()`,
  ответ строит `ExamReviewProjection::forViewer( $attemptId, 'manage' )` + `result` (`ExamScoreService::summarize()`) + `participant_name`.
- [x] 8.4.3 `work-review.js`: параметр возврата `from = 'exam-conduct' | 'exam-results'` (кнопка «‹ Назад» возвращает на экран экзаменов);
  шапка для экзамена — итог через `resultCaption()` (5.6.4) вместо «балл/максимум»; при `pending` — «Проверка не завершена», без вторичного балла и отметки.
- [x] 8.4.4 Ручная часть ОГЭ (№13–16) — существующие блоки `ogeRubricGradeBlock()` / `criteriaGradeBlock()`; сохранение — существующий `GradeAttempt`.
  После оценки последнего ручного задания статус результата на доске становится `ready`.
- [x] 8.4.5 Каждое сохранение оценки увеличивает `assessment_attempts.result_version` (в `AssessmentAttemptRepository` — метод
  `bumpResultVersion( int $id, int $expected ): bool`: `UPDATE … SET result_version = result_version + 1 WHERE id = %d AND result_version = %d`).
  `ajaxGradeAttempt()` для экзаменной попытки принимает `result_version`; при несовпадении — `fail( ErrorCode::ExamStale, 'Работу уже изменил другой проверяющий. Обновите страницу.' )`.
  Для попыток курса параметр необязателен, поведение прежнее.
- [x] 8.4.6 Сброс попыток (`ResetAttempts`) и «Пройти заново» для экзаменной попытки недоступны: кнопки не показывать, сервер отклоняет
  (SPEC §7: административный сброс — не обычная отмена брони).

**Тесты**
- `tests/Unit/Callbacks/Assessment/GradeAttemptCallbacksTest.php`: `test_exam_attempt_grading_requires_event_scope`,
  `test_teacher_of_other_subject_cannot_grade_exam_attempt`, `test_attempt_without_group_and_exam_context_is_denied`,
  `test_grade_with_stale_result_version_is_rejected`, `test_course_attempt_grading_unchanged`.
- `tests/Unit/Callbacks/Course/GradingCallbacksTest.php`: `test_work_detail_of_exam_attempt_uses_event_scope`,
  `test_work_detail_of_exam_attempt_denied_for_foreign_teacher`.

**Готово, когда:** тесты зелёные; из доски сеанса работа ОГЭ открывается, ручные задания оцениваются, статус становится «готова к утверждению».

---

## 8.5 Утверждение: одиночное и массовое

**Зачем.** Ученик видит результат только после явного утверждения. Утвердить можно одну работу на экране проверки или сразу несколько
на экране сеанса; утверждаются только готовые работы; частичный успех допустим (SPEC §7, критерий 29).

**Проверить перед началом**
- `AssessmentAttemptRepository::approve()`; `GradeAttemptCallbacks::ajaxApproveAttempt()` (после 8.4.1 — с проверкой проведения).
- `work-review.js::wireApprove()` — кнопка «Утвердить работу» показывается для `APPROVABLE_KIND = 'ege_computer'`.

**Шаги**
- [x] 8.5.1 `inc/Services/Exam/ExamApprovalService.php`, `use TransactionRunner;`. Зависимости: репозитории попыток, участий, проведений;
  `ExamAccessGuard`, `ExamOutbox`, `ClockInterface`.
  `approve( int $actorUserId, int $attemptId, int $expectedVersion ): array` → `array{ status: 'approved'|'skipped', reason?: string }`. Причины пропуска:
  | Условие | `reason` |
  |---|---|
  | попытка не экзаменная или не найдена | `not_found` |
  | нет права на проведение | `no_access` |
  | участие гостя | `guest` (гостю утверждение не нужно) |
  | попытка не сдана | `not_submitted` |
  | есть задания на ручной проверке (`is_correct IS NULL`) | `pending_review` |
  | `result_version` не совпал | `stale` |
  | уже утверждена | `already_approved` (не ошибка, уведомление повторно не создаётся) |
  Успех — в транзакции: `approve()` попытки, outbox `AttemptApproved` (`attempt_id`, `participation_id`, `result_version`).
- [x] 8.5.2 `approveMany( int $actorUserId, array $items ): array` — `$items` = список `array{attempt_id, result_version}`; каждая работа — **своя транзакция**;
  ответ: `approved` (число), `skipped` (список `array{attempt_id, reason, reason_label}`). Ошибка одной работы не откатывает остальные.
- [x] 8.5.3 Экшен `ApproveExamAttempts` в `ExamConductCallbacks` (`items` — массив; читать через `unslashArray()` + `sanitizeIntValue()`).
  Ответ — результат `approveMany()` и обновлённая доска. Одиночное утверждение на экране проверки для экзаменной попытки идёт через этот же сервис
  (`ajaxApproveAttempt()` при `isExam()` делегирует в `ExamApprovalService::approve()`).
- [x] 8.5.4 `work-review.js`: кнопку «Утвердить работу» показывать для экзаменной попытки ученика любого формата (ЕГЭ и ОГЭ), когда `result_status = ready`;
  для гостя кнопки нет.
- [x] 8.5.5 Массовое утверждение на экране сеанса (`exam-conduct.js`):
  - кнопка «Утвердить работы» переводит таблицу в режим выбора: у строк с `result_status = ready` появляются чекбоксы, рядом — «Отмена»;
  - у остальных строк чекбокс неактивен с подсказкой («Проверка не завершена», «Работа не сдана», «Уже утверждена», «Гостю утверждение не требуется»);
  - ничего не выбрано → кнопка подтверждения «Утвердить все»; выбрано N → «Утвердить выбранные (N)» (число — подстановкой);
  - «Утвердить все» отправляет все готовые работы сеанса;
  - после ответа — `toast`: «Утверждено: {a}. Пропущено: {b}.» и список пропущенных с причинами; режим выбора закрывается.
- [x] 8.5.6 До утверждения ученик и родитель не видят итог, баллы и решения ни в одном ответе (уже обеспечено 7.5) — после утверждения карточка
  ученика переходит в `approved` без перезагрузки кабинета при следующем автообновлении.

**Тесты**
- `tests/Unit/Services/Exam/ExamApprovalServiceTest.php`: по тесту на каждую причину пропуска; `test_approve_writes_outbox_once`,
  `test_second_approve_is_already_approved_without_outbox`, `test_approve_many_is_partial_success`,
  `test_approve_many_runs_each_item_in_own_transaction`, `test_oge_exam_attempt_requires_explicit_approval`.
- `ExamConductCallbacksTest.php`: `test_approve_many_reads_items_array`, `test_approve_many_returns_counts_and_board`.
- Нагрузочная проверка: на dev сеанс с 50 сданными попытками (сгенерировать стендом: команда `fs-lms exam stand-attempts --session=<id> --n=50`,
  создающая сданные попытки стендовых участников с аудиторией `student`) → «Утвердить все» → 50 утверждено, время ответа записать в `NOTES.md`.

**Готово, когда:** тесты зелёные; массовое утверждение на 50 работах проходит; повторное нажатие не создаёт второго события.

---

## 8.6 Исправление результата

**Зачем.** После утверждения преподаватель может исправить баллы с причиной; старые и новые значения журналируются; два проверяющих
не перезаписывают друг друга (SPEC §7).

**Проверить перед началом**
- `GradeAttemptCallbacks::ajaxGradeAttempt()` — запись балла задания и пересчёт итога (`AutoGradeService::finalize()`).
- Журнал изменений сущности: `inc/DTO/Log/Events/EntityChangedEvent.php` и как его диспатчат
  (`grep -rn "EntityChangedEvent(" inc | head -3`).
- `result_version` (2.2) и `bumpResultVersion()` (8.4.5).

**Шаги**
- [x] 8.6.1 `ExamApprovalService::correct( int $actorUserId, int $attemptId, array $changes, string $reason, int $expectedVersion ): AttemptDTO`.
  `$changes` — список `array{task_id, score, feedback?}`. В транзакции:
  1. право на проведение; попытка экзаменная и **утверждена** (до утверждения правки — обычная проверка 8.4);
  2. причина обязательна («Укажите причину исправления.»);
  3. `bumpResultVersion( $attemptId, $expectedVersion )` — `false` → `ExamStale`;
  4. по каждому заданию: `0 ≤ score ≤ max_score` задания; запомнить старый балл; записать новый балл, `graded_by_user_id`, `graded_at`;
     `is_correct` — истина при `score === max_score`. **Ответ ученика (`answer_text`) не изменяется никогда**;
  5. пересчитать итог — `AutoGradeService::finalize()`;
  6. журнал: событие изменения сущности с типом цели «попытка экзамена», причиной, автором и парами «старое → новое» по каждому заданию и по итогу
     (диспатчер — как в проверке выше; если нужного типа цели нет в `AuditTargetType`/`EntityType` — добавить кейс);
  7. outbox `ResultCorrected` (`attempt_id`, `participation_id`, `reason`, новая версия).
  Исправление сразу действует на опубликованный результат; повторного утверждения не требуется.
- [x] 8.6.2 Экшен `CorrectExamResult` в `ExamConductCallbacks` (`attempt_id`, `changes[]`, `reason`, `result_version`).
- [x] 8.6.3 `work-review.js`: для утверждённой экзаменной попытки — кнопка «Исправить результат». Режим исправления: поля балла у заданий,
  обязательное поле причины, «Сохранить исправление» → `confirmDialog( 'Исправить результат? Ученик получит уведомление с причиной.' )`.
  При `X-STALE` — сообщение и кнопка «Обновить».
- [x] 8.6.4 Апелляции как отдельного процесса нет: это разговор с преподавателем и запись причины исправления.

**Тесты** — `ExamApprovalServiceTest.php`:
- `test_correct_requires_reason`, `test_correct_requires_approved_attempt`;
- `test_correct_with_stale_version_is_rejected_and_changes_nothing`;
- `test_correct_never_changes_answer_text`;
- `test_correct_rejects_score_above_max`;
- `test_correct_recalculates_totals_and_bumps_version`;
- `test_correct_logs_old_and_new_values_with_actor_and_reason`;
- `test_correct_writes_result_corrected_outbox`.
- Проверка одновременности (вручную, две вкладки двух преподавателей с правом на проведение): оба открывают работу, первый сохраняет исправление,
  второй получает «Работу уже изменил другой проверяющий», его правка не записана.

**Готово, когда:** тесты и ручная проверка пройдены; в журнале изменений видны старые и новые баллы.

---

## 8.7 Раздел «Результаты»

**Зачем.** Список работ с фильтрами и очередь проверки (SPEC §7).

**Проверить перед началом**
- Заглушка `exam-results.js` (4.1.4). Классы: `pr-row`, `prof-work-item`, `prof-seg` (`../QA.md`).
- Экран «Работы» (`works.js`) — образец списка с вкладками и переходом в проверку.

**Шаги**
- [x] 8.7.1 `inc/Callbacks/Exam/ExamResultCallbacks.php`, экшен `GetExamResults`: параметры `subject_key`, `event_id?`, `session_id?`,
  `status?` (`pending_review` / `ready` / `approved` / `all`), `audience?` (`student` / `guest` / `all`), `source_id?`.
  Ответ: `filters` (проведения предмета, сеансы выбранного проведения, источники) и `items` — строки как в 8.1.3 (`name`, `audience_label`, `source`,
  сеанс, `result_status`, итог, `attempt_id`). Только проведения, доступные пользователю (`canManageEvent()`).
  Выборку вынести в `ExamConductService::results( int $actorUserId, array $filters ): array`.
- [x] 8.7.2 `exam-results.js`: сегменты статуса (`prof-seg`): «На проверке» (по умолчанию — очередь `pending_review`), «Готовы к утверждению»,
  «Утверждены», «Все»; селекторы проведения и сеанса; переключатель «ученики / гости / все»; селектор источника (если есть гости).
  Клик по строке → `openWorkReview( 'attempt', attemptId, 'exam-results' )`.
- [x] 8.7.3 В очереди «Готовы к утверждению» — те же «Утвердить выбранные (N)» / «Утвердить все» (переиспользовать функции режима выбора из
  `exam-conduct.js`, вынеся их в `exam-common.js`).
- [x] 8.7.4 Пустые состояния для каждого сегмента.

**Тесты**
- `ExamConductServiceTest.php`: `test_results_filter_by_status`, `test_results_filter_by_audience_and_source`,
  `test_results_exclude_foreign_events`.
- `tests/Unit/Callbacks/Exam/ExamResultCallbacksTest.php`: `test_requires_manage_exams`, `test_filters_are_sanitized`.

**Готово, когда:** тесты зелёные; очередь проверки показывает работу ОГЭ с непроверенными заданиями, после оценки она переходит в «Готовы к утверждению».

---

## 8.9 Печать списка участников и CSV

**Зачем.** Список участников сеанса на печать и в CSV — только при двух экспортных правах, без контактов, с защитой от формул (SPEC §2, §7, §8, критерии 18, 27).

**Проверить перед началом**
- `inc/Contracts/CsvExportProviderInterface.php` (`columns()`, `rows()`, `filename()`), `inc/Services/Export/CsvExportService.php`,
  `CsvExportProviderRegistry.php`, образец — `StudentsExportProvider.php`. Экспорт регистрируется в `ExportServiceBootstrap`.
- Как существующие выгрузки проверяют права: `grep -rn "authorizeAll" inc/Callbacks | head`.
- Защита от формул: `grep -rn "formula\|=cmd\|ltrim" inc/Services/Export` — сейчас её нет.
- `inc/Enums/Export/ExportTarget.php` — перечень целей экспорта.

**Шаги**
- [x] 8.9.1 Защита от формул — в **общем** `CsvExportService` (выиграют все выгрузки): перед записью строкового значения, которое после удаления
  ведущих пробелов и управляющих символов начинается с `=`, `+`, `-`, `@`, табуляции или возврата каретки, дописать в начало апостроф `'`.
  Числовые значения (`is_int`, `is_float`, строка-число вида `-12.5`) не трогать. Вынести в метод `neutralizeFormula( mixed $value ): mixed`.
- [x] 8.9.2 `inc/Services/Export/ExamParticipantsExportProvider.php` (`implements CsvExportProviderInterface`), цель `ExportTarget::ExamParticipants`.
  Контекст: `session_id` **или** список `participation_ids` (выборка экспорта = выбранные участия, не все гости).
  Колонки: ФИО, источник (школа), сеанс (дата и время), статус, первичный балл / максимум, вторичный балл / максимум **или** отметка.
  **Без телефона, мессенджера и ссылок.** Зарегистрировать в `ExportServiceBootstrap`.
- [x] 8.9.3 Права: `authorizeAll( Nonce::…, array( Capability::ManageLmsPlatform, Capability::ExportPII ) )` (nonce — тот, что используют существующие выгрузки ПД)
  **и** `ExamAccessGuard::canManageEvent()`. Преподаватель без двух прав кнопку не видит и получает отказ на прямой запрос.
  Использовать существующий механизм одноразовой ссылки (`OneTimeDownloadService`), как у остальных выгрузок ПД; событие — в журнал экспорта.
- [x] 8.9.4 Печать: кнопка «Печать списка» при тех же правах открывает страницу печати со списком (ФИО, источник, сеанс) без контактов.
  Посмотреть, есть ли готовый механизм печатных документов (`inc/Enums/Print/PrintDocument.php`, `inc/Services/Print`) и добавить документ туда;
  отдельную страницу печати не изобретать. **Ссылки входа и результата печатью не раздаются.**
- [x] 8.9.5 В блок конфига `exams` добавить флаг `canExportPii` (оба права); кнопки «CSV» и «Печать списка» на экране сеанса и в «Результатах» —
  только при `true`.

**Тесты**
- `tests/Unit/Services/Export/CsvExportServiceTest.php` (если есть — дописать): `test_formula_prefixes_are_neutralized` для значений
  `=1+1`, `+7999`, `-cmd`, `@SUM(A1)`, `"  =HYPERLINK(...)"`, `"\t=1"`; `test_numeric_values_are_untouched` (`-12.5`, `42`);
  `test_plain_text_is_untouched`.
- `tests/Unit/Services/Export/ExamParticipantsExportProviderTest.php`: `test_columns_have_no_contacts`,
  `test_rows_limited_to_selected_participations`, `test_ege_row_has_secondary_oge_row_has_grade`.
- Тест коллбека выгрузки: `test_export_requires_both_pii_caps`, `test_export_denied_for_teacher_without_export_pii`,
  `test_export_denied_for_foreign_event`.

**Готово, когда:** тесты зелёные; CSV с участником по имени `=HYPERLINK("http://x")`, открытый в табличном редакторе, показывает текст, а не формулу.

---

## 8.8 Гости в таблице сеанса и очередь оплат

**Зачем.** У гостя четыре независимых состояния: оплата, запись, допуск, попытка. Проблемные оплаты разбирает сотрудник в отдельной очереди
(SPEC §6, §7). Данные создаёт этап 11a; **до него пункт не начинать**. Без гостей экран работает как после 8.1–8.7.

**Проверить перед началом**
- Этап 11a выполнен: `GuestApplicationService`, `exam_payment_links`, `ExamPaymentReconciler`, расшифровка ФИО гостя.
- `ExamHoldService::convert()`, `ExamRegistrationService::transferByStaff()`.
- Права: `ManageExamGuests` (гости на экране сеанса), `ResolveExamPayments` (очередь оплат; у офиса нет `ManageExams`).

**Шаги**
- [ ] 8.8.1 `sessionBoard()`: для гостей в строке — **отдельные поля** `payment` (`ExamPaymentState` + подпись), `registration_status`, `admission`
  (`admitted_at` задан или нет), `progress`. В таблице — четыре пилюли, не одна «галочка». Колонки видны, только если в сеансе есть гости.
  В доску добавить и гостей с действующей бронью без записи (строка «Место удерживается до {время}»).
- [x] 8.8.2 Допуск: `ExamConductService::admit( int $actorUserId, int $participationId, bool $admitted ): void` (право `ManageExamGuests`) —
  ставит `admitted_at`/`admitted_by_user_id`. Сотрудник отмечает допуск после личной проверки данных и согласия представителя на площадке.
  Экшен `AdmitExamGuest`. Выдача ссылки входа (11b.1) возможна только при допуске и подтверждённой записи.
- [ ] 8.8.3 Очередь «Оплачено, требуется помощь». `inc/Callbacks/Exam/ExamPaymentQueueCallbacks.php`
  (`authorize( Nonce::ExamPayments, Capability::ResolveExamPayments )`; пользователь с `ManageExams` тоже допускается — проверить любое из двух прав).
  Экшены: `GetExamPaymentQueue` (заявки в `paid_needs_resolution`: гость, проведение, сеанс заявки, заказ WooCommerce — номер и сумма, причина,
  время последней сверки, ответственный за проведение), `ResolveExamPayment` (`application_id`, `kind`, `session_id?`, `reason`, `amount?`).
- [ ] 8.8.4 `GuestApplicationService::resolve( int $actorUserId, int $applicationId, ManualResolutionKind $kind, ?int $sessionId, string $reason, ?float $amount ): void`:
  - `Transferred` — выбрать сеанс со свободным местом: занять место и подтвердить запись (как ветка «занять и подтвердить» в `ExamHoldService::convert()`);
  - `RefundedOutside` — заявка `cancelled`, место не занимается; **refund API WooCommerce не вызывается, статус заказа не меняется, писем нет**;
  - `Other` — только отметка.
  Всегда — строка в `exam_manual_resolutions` (вид, причина, автор, сумма, старый и новый сеанс) и запись в журнал. Причина обязательна.
- [ ] 8.8.5 Перенос уже подтверждённого оплаченного гостя в другой сеанс — `TransferExamRegistration` (8.2) с правом `ManageExams` **или**
  `ResolveExamPayments`; строка в `exam_manual_resolutions` с `kind = Transferred`.
- [ ] 8.8.6 Отметка «Ссылка передана [когда, кем]» у ссылок входа и результата гостя: экшен `MarkExamLinkPassed` (`token_id`) →
  `ExamAccessTokenService::markPassed()`. Ставит сотрудник вручную; **копирование ссылки отметку не ставит**. В строке — «Передана {дата}, {кто}».
- [ ] 8.8.7 Экран `exam-payments.js` (вместо заглушки): список `prof-work-item` (как блок «Требует внимания» на «Главной»), сегменты `prof-seg`
  («Требуют помощи» / «Урегулированы»), форма урегулирования в поповере. Офис видит только этот экран раздела «Мои экзамены».

**Тесты**
- `ExamConductServiceTest.php`: `test_guest_row_has_four_independent_states`, `test_board_without_guests_has_no_guest_columns`,
  `test_admit_requires_manage_exam_guests`.
- `tests/Unit/Callbacks/Exam/ExamPaymentQueueCallbacksTest.php`: `test_office_with_resolve_cap_sees_queue`,
  `test_office_cannot_create_or_publish_event` (экшены `SaveExamEvent`, `PublishExamEvent` → отказ),
  `test_teacher_with_manage_exams_sees_queue`, `test_user_without_both_caps_denied`.
- `tests/Unit/Services/Exam/GuestApplicationServiceTest.php`: `test_resolve_refunded_outside_never_calls_woo_refund`,
  `test_resolve_transfer_occupies_seat_and_confirms`, `test_resolve_requires_reason`,
  `test_resolve_writes_manual_resolution_row`, `test_copying_link_does_not_mark_passed`.

**Готово, когда:** тесты зелёные; офис видит очередь и урегулирует заявку, но не может создать проведение.

---

## Проверка этапа (SPEC §16: 6, 8, 16, 27, 28, 29)

- [x] Продление и отмена работают; отмена требует причины; начатую попытку отмена не стирает. (юнит-тесты; руками на ученике не прогонялось)
- [x] Массовое утверждение на 50 работах; повтор не дублирует событие. (стенд на MariaDB)
- [x] Одновременное исправление двумя проверяющими — второй получает отказ по версии. (стенд на MariaDB: два процесса)
- [x] CSV с именами `=…`, `+…`, `@…` не выполняет формулу; выгрузка и печать недоступны без двух экспортных прав. (юнит-тесты; CSV по HTTP)
- [x] Преподаватель другого предмета не может открыть, оценить и утвердить экзаменную попытку. (юнит-тесты коллбеков)
- [x] `npm run ci`, `npx gulp build` — зелёные (2026-10-09; пункт 8.8 не сделан — ждёт этап 11a).
