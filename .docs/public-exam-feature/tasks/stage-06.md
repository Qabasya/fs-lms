# Этап 6. Сдача вне курса: попытки, неявка, блокировка

Зависимости: этап 2 (минутный cron 2.6, колонки попыток 2.2), этап 3, этап 5. Результат: ученик проходит экзамен на существующей
станции КЕГЭ/ОГЭ по своей записи; неявка фиксируется сама; попытки экзаменов не видны в журнале и «Моих работах».

Перед началом прочитать `README.md`. SPEC: §4 «Неявка и старт», §11 («AssessmentAttempts», «Журнал и оценки»), §14, §16 критерии 5, 6, 7, 31.

**Порядок:** 6.7 → 6.1 → 6.2 → 6.3 → 6.4 → 6.5 → 6.6. Пункт 6.7 идёт первым: пока старые экраны видят экзаменные попытки,
создавать такие попытки нельзя.

## Общие правила этапа

- **Новый плеер не пишется.** Сдача идёт на существующей станции (`templates/frontend/assessment/kege/*`, `src/js/kege/*`).
- Весь экзаменный путь попытки проходит через `ExamAttemptService`. Старый путь (`AttemptService::start()` через занятие курса)
  для Control и станций в курсе остаётся как был.
- Время в `assessment_attempts` (`started_at`, `deadline_at`, `submitted_at`) — **местное** время сайта, как у всех старых попыток.
  Время сеанса в `exam_sessions` — UTC. Сравнивать только через `ExamTime`.
- Допуск проверяется на сервере в момент каждого запроса. Задержка cron не открывает просроченный старт.

---

## 6.7 Экзаменные попытки не видны старым экранам

**Зачем.** Экзамены не попадают в журнал группы, «Мои работы», оценки ученика и старые сводки (SPEC §11, критерий 31).

**Проверить перед началом**
- Запросы репозитория: `sed -n 60,260p inc/Repositories/WPDBRepositories/AssessmentAttemptRepository.php`.
- Потребители: `grep -rln "AssessmentAttemptRepository" inc` — список сервисов (журнал, очередь проверки, оценки ученика, сброс попыток, прогресс урока).
- Колонка `exam_participation_id` уже есть (2.2).

**Шаги**
- [ ] 6.7.1 В `AssessmentAttemptRepository` добавить условие `AND exam_participation_id IS NULL` в методы «старого мира»:
  | Метод | Менять | Почему |
  |---|---|---|
  | `findActive( person, assessment )` | да | станция в курсе не должна подхватить экзаменную попытку |
  | `findLastSubmitted( person, assessment )` | да | результат курса ≠ результат экзамена |
  | `listByStudentAndAssessment()` | да | история попыток курса |
  | `countByAssessmentAndStudent()` | да | сдача в проведении не расходует лимит попыток курса, и наоборот |
  | `listByGroupForGradebook()`, `listByGroupsForGradebook()` | да | у экзаменных попыток `group_id = NULL`, условие — страховка |
  | `listByStudentForGradebook()` | **да, обязательно** | выборка по ученику: без условия экзамен попадёт в «Мои оценки» |
  | `listByGroupLesson()` | да | страховка |
  | `expireOverdue()` | **да, обязательно** | экзаменную попытку по дедлайну завершает и проверяет этап 6.2, а не простая пометка `expired` |
  | `nextAttemptNumber()` | **нет** | номер должен расти по всем попыткам: действует старый уникальный ключ `(assessment_id, student_person_id, attempt_number)` |
  | `findAnyActive()` | **нет** | блокировка контента обязана видеть экзаменную попытку (6.4) |
  | `find()`, `update()`, `approve()`, `delete()` | нет | работа по ID |
- [ ] 6.7.2 Новые методы для экзаменов: `findByParticipation( int $participationId ): ?AttemptDTO`,
  `listByParticipations( array $participationIds ): array`, `listOverdueExamIds( string $nowLocal, int $limit ): array`
  (`status = 'in_progress' AND deadline_at < %s AND exam_participation_id IS NOT NULL`).
- [ ] 6.7.3 Пройти список потребителей и убедиться, что каждый получает попытки только через методы из таблицы. Отдельно проверить
  `WorkResetService` («сброс попыток»): он не должен удалять экзаменные попытки ученика — добавить явную проверку `isExam()` и пропуск.
- [ ] 6.7.4 `NotificationSubscriber::handleAttemptGraded()` — в начале метода: `if ( $attempt->isExam() ) { return; }`.
  Уведомление «Экзамен проверен» с баллами и ссылкой в «Мои оценки» для экзаменной попытки не отправляется (свои уведомления — этап 9).
  Это же закрывает утечку баллов до утверждения.

**Тесты**
- `tests/Integration/Repositories/AssessmentAttemptRepositoryTest.php` (FakeWpdb, проверка текста SQL) — по тесту на каждый изменённый метод:
  `test_<метод>_excludes_exam_attempts`; и `test_next_attempt_number_counts_exam_attempts`, `test_find_any_active_includes_exam_attempts`.
- `tests/Unit/Controllers/Subscribers/NotificationSubscriberTest.php` (если нет — создать): `test_attempt_graded_is_skipped_for_exam_attempt`.
- `tests/Unit/Services/Course/WorkResetServiceTest.php`: `test_reset_does_not_delete_exam_attempts`.

**Готово, когда:** тесты зелёные; полный `vendor/bin/phpunit` без регрессий.

---

## 6.1 `AttemptContext` и старт попытки

**Зачем.** Старт, продолжение, сохранение и сдача экзамена идут через единый контекст участника; допуск проверяется на сервере:
`начало ≤ сейчас < плановый конец` (SPEC §4, §11).

**Проверить перед началом**
- `inc/Services/Assessment/AttemptService.php`: `start()` требует доступного занятия курса и считает лимит попыток по работе — для экзамена не подходит.
  `saveAnswer()`, `submit()`, `getResult()` проверяют владельца через `studentPersonId`.
- `inc/Callbacks/Assessment/AttemptCallbacks.php` — транспорт станции: `ajaxStartAttempt`, `ajaxSaveAttemptAnswer`, `ajaxSubmitAttempt`, `ajaxGetAttemptResult`.
- `src/js/kege/kege-entry.js::requestStartAttempt()` — читает `from_gid`, `from_gl` из адреса и дописывает в запрос.
- `inc/Controllers/Pages/AssessmentPageController.php::loadTemplate()` и `AttemptPageService::build()` — страница станции требует доступа через занятие.

**Шаги**
- [ ] 6.1.1 `inc/DTO/Exam/AttemptContext.php` — `readonly class`: `audience` (`ExamAudience`), `participationId`, `registrationId`, `?int $personId`,
  `?int $wpUserId`. Гостевой контекст появится на этапе 11b; сейчас создаётся только ученический.
- [ ] 6.1.2 `AttemptService` — выделить тела без проверки владельца (публичные методы, поведение старых не меняется):
  - `saveAnswerFor( AttemptDTO $attempt, int $taskId, string $answerText ): void` — проверка «задание входит в работу» + запись ответа;
  - `submitFor( AttemptDTO $attempt ): AttemptDTO` — статус `submitted`, `submitted_at`, событие журнала, автопроверка.
  `saveAnswer()` и `submit()` после своих проверок вызывают эти методы. `actorUserId()` должен принимать `?int` и для `null` возвращать `0`.
  **`expireIfOverdue()` для экзаменной попытки ничего не делает** (в начале: `if ( $attempt->isExam() ) { return false; }`) — её завершает 6.2.
- [ ] 6.1.3 `inc/Services/Exam/ExamAttemptService.php`, `use TransactionRunner;`. Зависимости: репозитории попыток, участий, записей, сеансов, проведений;
  `AttemptService`, `ExamNoShowService` (6.3), `ExamFormatRegistry`, `ExamOutbox`, `ExamTime`.
- [ ] 6.1.4 `contextForStudent( int $wpUserId, int $registrationId ): AttemptContext` — запись существует, её участие принадлежит участнику с
  `person_id` этого пользователя; иначе `CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' )`. Чужой `registration_id` даёт тот же ответ, что несуществующий.
- [ ] 6.1.5 `start( AttemptContext $ctx ): AttemptDTO` — в `inTransactionWithRetry()`:
  1. блокировка участия (`findForUpdate`);
  2. если `current_attempt_id` задан — вернуть эту попытку (**обновление страницы и двойной клик не создают вторую попытку**);
  3. запись действующая (`active_slot = 1`, статус `confirmed`), иначе `ExamAccess`;
  4. сеанс и проведение не отменены;
  5. `nowUtc < scheduled_at` → `CodedException( ErrorCode::ExamNotOpen, 'Экзамен ещё не начался.' )`;
  6. `nowUtc >= planned_end_at` → вызвать `ExamNoShowService::markMissedLocked()` (6.3) и бросить `ExamNotOpen` («Время начала истекло.»);
  7. у ученика нет другой активной попытки (`findAnyActive( personId )`), иначе `ExamConflict` («Сначала завершите начатую работу.»);
  8. вариант — `session->assessmentId`; длительность — из снимка проведения (`snapshotFor()['duration_minutes']`), при отсутствии снимка — из формата;
  9. создать попытку: `started_at = nowLocal`, `deadline_at = started_at + длительность` (плановый конец сеанса дедлайн **не** обрезает),
     `exam_participation_id`, `exam_registration_id`, `group_id = null`, `group_lesson_id = null`,
     `attempt_number = nextAttemptNumber( personId, assessmentId )`;
  10. `setCurrentAttempt()`; если у сеанса `first_started_at` пуст — записать `nowUtc` (с этого момента сеанс «заперт», 2.4.6);
  11. outbox `AttemptStarted`.
  Лимит попыток работы (`attemptsAllowed`) здесь **не проверяется**: одна официальная попытка на участие обеспечена уникальным индексом и шагом 2.
- [ ] 6.1.6 `saveAnswer( AttemptContext $ctx, int $attemptId, int $taskId, string $text ): void` и `submit( AttemptContext $ctx, int $attemptId ): AttemptDTO`:
  попытка принадлежит участию контекста (`exam_participation_id === $ctx->participationId`), иначе «Попытка не найдена.»; статус `in_progress`;
  дедлайн не прошёл (если прошёл — `finalizeExpired()` из 6.2 и ответ «Время попытки истекло.»); затем `AttemptService::saveAnswerFor()` / `submitFor()`.
  `submit()` — в транзакции с блокировкой участия, после сдачи outbox `AttemptSubmitted`.
- [ ] 6.1.7 Транспорт. `AttemptCallbacks`:
  - `ajaxStartAttempt()`: если пришёл `exam_registration_id` — `contextForStudent()` + `ExamAttemptService::start()`; иначе прежний путь;
  - `ajaxSaveAttemptAnswer()`, `ajaxSubmitAttempt()`, `ajaxGetAttemptResult()`: загрузить попытку; `isExam()` → путь `ExamAttemptService`, иначе прежний.
    Вынести выбор в приватный метод `examContextFor( AttemptDTO $attempt ): ?AttemptContext`;
  - `CodedException` → `$this->fail()` с кодом.
  **Ответ `ajaxSubmitAttempt()` для экзаменной попытки ученика не содержит `total_score`, `max_score`, `per_task`** — только `status`
  (раскрытие — этап 7.5).
- [ ] 6.1.8 Страница станции. `AttemptPageService::buildForExam( AssessmentDTO $assessment, AttemptContext $ctx ): ?AttemptPageDTO` — как `build()`,
  но доступ определяется контекстом, активная и последняя попытки берутся через `findByParticipation()`, `canRetry = false`.
  `AssessmentPageController::loadTemplate()`: при параметре `exam_reg` (целое) и вошедшем пользователе — собрать контекст и страницу через
  `buildForExam()`; контекст не собрался или вариант записи не совпал с открытой работой → обычный 404 (как для постороннего).
- [ ] 6.1.9 `kege-entry.js::requestStartAttempt()`: читать `exam_reg` из адреса и добавлять `exam_registration_id` в запрос (рядом с `from_gid`/`from_gl`).

**Тесты**
- `tests/Unit/Services/Exam/ExamAttemptServiceTest.php` (время — мок `ClockInterface`; сеанс 10:00–13:55 местного):
  `test_start_denied_at_09_59`, `test_start_allowed_at_10_00`,
  `test_start_at_13_54_gets_deadline_17_49`, `test_start_denied_at_13_55_and_marks_missed`,
  `test_second_start_returns_same_attempt`, `test_start_denied_for_cancelled_registration`,
  `test_start_denied_with_other_active_attempt`, `test_start_sets_first_started_at_once`,
  `test_start_does_not_check_assessment_attempt_limit`, `test_start_writes_outbox`,
  `test_foreign_registration_is_denied_like_missing`,
  `test_save_denied_for_foreign_participation`, `test_submit_writes_outbox_and_grades`.
- `tests/Unit/Services/Assessment/AttemptServiceTest.php` — дописать: `test_expire_if_overdue_ignores_exam_attempt`,
  `test_save_answer_for_rejects_task_outside_assessment`; существующие тесты не менять.
- `tests/Unit/Callbacks/Assessment/AttemptCallbacksTest.php`: `test_start_with_exam_registration_uses_exam_service`,
  `test_start_without_exam_param_uses_legacy_path`, `test_submit_of_exam_attempt_returns_no_scores`.

**Готово, когда:** тесты зелёные; существующие тесты `AttemptService` и `AttemptCallbacks` без изменений ожиданий.

---

## 6.2 Личный дедлайн и автоистечение

**Зачем.** Начавший получает полную длительность от фактического старта; плановый конец закрывает вход, но не прерывает начатое.
По личному дедлайну сохранённые ответы принимаются, попытка завершается и проверяется (SPEC §4).

**Проверить перед началом**
- `inc/Services/Assessment/AutoGradeService.php::gradeAttempt()` — оценивает по полному составу работы, берёт сохранённые ответы.
- `ExamTickService::autoExpire()` (2.6.4) — пустой метод минутного тика.
- `CronController::handleExpireAttempts()` → `expireOverdue()` — после 6.7.1 экзаменные попытки не трогает.

**Шаги**
- [ ] 6.2.1 `ExamAttemptService::finalizeExpired( int $attemptId ): bool` — в транзакции: блокировка участия → перечитать попытку →
  если статус не `in_progress` или дедлайн не прошёл — `false`; иначе: `submitted_at = deadline_at` (не момент срабатывания тика),
  статус `submitted`, затем `AutoGradeService::gradeAttempt()` — та же проверка, что при обычной сдаче; outbox `AttemptSubmitted` с `auto = true`.
  Автосохранённые ответы не теряются: проверка читает их из `assessment_answers`. Статус «время истекло» не означает «неявка».
- [ ] 6.2.2 `ExamTickService::autoExpire()`: по ID из `listOverdueExamIds( nowLocal, 200 )` вызвать `finalizeExpired()`; ошибка одной попытки
  логируется (`PluginLogger::exception( …, true )`) и не останавливает остальные.
- [ ] 6.2.3 Ленивый путь: `saveAnswer()`, `submit()`, выдача результата и `buildForExam()` перед работой с попыткой вызывают `finalizeExpired()`,
  если дедлайн прошёл. Так просроченная попытка завершается и без cron.
- [ ] 6.2.4 Продление (используется на этапе 8.2): `extend( int $actorUserId, int $attemptId, int $minutes, string $reason ): AttemptDTO` —
  право `canManageEvent()`; попытка `in_progress`; `1 ≤ minutes ≤ 120`; причина обязательна; `deadline_at += minutes`; outbox `AttemptExtended`
  (`minutes`, `reason`, `actor_user_id`, новый дедлайн). Завершённую попытку продлением не возобновлять («Попытка уже завершена.»).
- [ ] 6.2.5 Станция показывает остаток по `deadline_at` попытки (уже так: `kege-resume.js`, `state.deadlineTs`). Проверить, что после продления
  перезагруженная страница берёт новый дедлайн с сервера, а не из сохранённого состояния браузера; если берёт из браузера — при загрузке
  страницы сервер должен быть главнее (правка в `kege-state.js`, найти место записи `deadlineTs`).

**Тесты** — `ExamAttemptServiceTest.php`:
- `test_attempt_started_at_11_00_has_deadline_14_55_and_is_not_interrupted_at_13_55`;
- `test_finalize_expired_submits_at_deadline_and_grades_saved_answers`;
- `test_finalize_expired_is_idempotent`;
- `test_finalize_writes_outbox_with_auto_flag`;
- `test_save_after_deadline_finalizes_and_reports_time_over`;
- `test_extend_requires_reason_and_scope`, `test_extend_denied_for_finished_attempt`, `test_extend_moves_deadline`.
- `tests/Unit/Services/Exam/ExamTickServiceTest.php`: `test_auto_expire_continues_after_single_failure`.

**Готово, когда:** тесты зелёные; на dev: начать попытку, вручную сдвинуть `deadline_at` в прошлое, выполнить `wp fs-lms exam tick --name=auto-expire` —
попытка получила `submitted_at = deadline_at`, баллы посчитаны, ответы на месте.

---

## 6.3 Неявка

**Зачем.** Если к плановому концу сеанса попытка не начата, запись становится «пропущено», а участник снова может записаться.
Проверка фоновая и ленивая, идемпотентная, согласованная со стартом и отменой (SPEC §4, критерии 5–7).

**Проверить перед началом**
- Старт (6.1.5), отмена (3.2) и неявка блокируют **одну и ту же строку участия** — это и даёт один согласованный исход.
- `ExamRegistrationRepository::deactivate()` меняет запись, только если `active_slot = 1`.

**Шаги**
- [ ] 6.3.1 `inc/Services/Exam/ExamNoShowService.php`, `use TransactionRunner;`. Зависимости: репозитории записей, участий, сеансов; `ExamOutbox`, `ExamTime`.
- [ ] 6.3.2 `markMissedLocked( ExamParticipationDTO $participation, ExamRegistrationDTO $registration, ExamSessionDTO $session ): bool` —
  вызывается, когда участие **уже заблокировано** вызывающим кодом. Условия: запись действующая; `current_attempt_id === null`;
  `nowUtc >= planned_end_at`. Действия: `deactivate( …, Missed, nowUtc, null, null )`; если она вернула `true` — `releaseSeat()`,
  `setActiveRegistration( null )`, outbox `ParticipantMissed` (`registration_id`, `session_id`). Возвращает, была ли неявка проставлена.
- [ ] 6.3.3 `markMissed( int $registrationId ): bool` — своя транзакция: блокировка участия → `markMissedLocked()`.
- [ ] 6.3.4 `sweep( int $limit = 200 ): int` — для минутного тика. В `ExamRegistrationRepository` добавить
  `listActiveOfEndedSessions( string $nowUtc, int $limit ): array` (действующие записи сеансов с `planned_end_at <= now`). По каждой — `markMissed()`.
  Подключить в `ExamTickService::autoExpire()` после автоистечения.
- [ ] 6.3.5 Ленивые точки: `ExamAttemptService::start()` (уже в 6.1.5) и `LearnerExamsService::build()` — перед расчётом состояния карточки
  вызвать `markMissed()` для действующей записи ученика, если её сеанс закончился. Ученик видит «Экзамен пропущен» сразу, не дожидаясь cron.
- [ ] 6.3.6 Отметка прихода (этап 8.2) на неявку **не влияет**: правило смотрит только на отсутствие попытки. Кода вида «нет отметки прихода —
  неявка через 15 минут» быть не должно.
- [ ] 6.3.7 Запись с начатой попыткой при достижении планового конца не меняется (условие `current_attempt_id === null`).

**Тесты** — `tests/Unit/Services/Exam/ExamNoShowServiceTest.php`:
- `test_missed_at_planned_end_without_attempt`;
- `test_not_missed_one_minute_before_planned_end`;
- `test_not_missed_when_attempt_started` — старт в 13:54, проверка в 13:55;
- `test_missed_releases_seat_and_clears_active_registration`;
- `test_second_run_does_nothing_and_writes_no_second_outbox_event`;
- `test_arrival_mark_does_not_prevent_missed`;
- `test_can_register_again_after_missed` (через `ExamRegistrationService`, окно записи открыто).
- Стенд (расширение 3.5), сценарий `start-vs-missed`: 50 участников с записью на сеанс, у которого `planned_end_at` наступает в момент запуска;
  параллельно `stand-start --participant=…` и `fs-lms exam tick --name=auto-expire`. Ожидание: у каждого участника ровно один исход —
  либо попытка, либо `missed`; нет участий с попыткой и пропущенной записью одновременно.

**Готово, когда:** тесты и сценарий стенда проходят; результаты стенда дописаны в `NOTES.md`.

---

## 6.4 Блокировка контента на время попытки

**Зачем.** Пока идёт экзамен, остальной учебный контент закрыт; блокировка снимается при любом завершении попытки (SPEC §4).

**Проверить перед началом**
- `inc/Services/Assessment/ExamLockService.php` — ищет активную попытку ученика (`findAnyActive`) и проверяет `kind->locksContent()`.
  Экзаменная попытка ученика имеет `student_person_id`, поэтому блокировка срабатывает без изменений.
- `inc/Services/Profile/Learner/LearnerCoursesSection.php::examLock()` — строит ссылку «вернуться к работе» как постоянную ссылку работы.
- `src/js/profile/learner.js::examLockBanner()` — текст «Идёт контрольная…».

**Шаги**
- [ ] 6.4.1 `LearnerCoursesSection::examLock()`: для экзаменной попытки (`isExam()`) ссылка — постоянная ссылка работы **с параметром**
  `exam_reg = $attempt->examRegistrationId`, иначе станция откроется по пути курса и ответит 404. В ответ добавить `is_exam => true`.
- [ ] 6.4.2 `learner.js::examLockBanner()` и текст в карточке курса: для `is_exam` — «Идёт экзамен «{название}»» вместо «контрольная».
- [ ] 6.4.3 Проверить снятие блокировки для каждого завершения: сдача, автоистечение по дедлайну (6.2). После них `findAnyActive()` возвращает `null`
  (статус не `in_progress`). Отмена записи и неявка попытку не создают — блокировки нет.
- [ ] 6.4.4 `findAnyActive()` содержит условие `deadline_at > NOW()` — время базы. Убедиться, что часовой пояс базы совпадает с местным временем сайта:
  `docker exec wp_db mariadb -u root -proot wordpress -N -e "SELECT NOW()"` и
  `docker compose -f /Users/daniil/FS-LMS/docker-compose.yml run --rm wpcli wp eval 'echo current_time("mysql");'`.
  Если расходятся — заменить `NOW()` на обязательный параметр `string $nowLocal` (значение — `ClockInterface::now()`), обновить вызов в `ExamLockService`
  (добавив `ClockInterface` в его конструктор) и покрыть тестом. Расхождение записать в `NOTES.md`.

**Тесты**
- `tests/Unit/Services/Assessment/ExamLockServiceTest.php` — дописать: `test_exam_attempt_outside_course_locks_content`,
  `test_lock_released_after_submit`, `test_lock_released_after_deadline_finalize`.
- `tests/Unit/Services/Profile/…LearnerCoursesSectionTest.php` (если есть): `test_exam_lock_url_contains_exam_registration`.

**Готово, когда:** тесты зелёные; на dev во время попытки «Мои курсы» показывают баннер со ссылкой, ведущей обратно на станцию; после сдачи баннер исчезает.

---

## 6.5 Кнопки «Приступить» и «Продолжить»

**Зачем.** Из карточки «Моих экзаменов» ученик попадает на существующую станцию; обновление страницы не создаёт новую попытку (SPEC §5).

**Проверить перед началом**
- Состояния `entry_open` и `in_progress` карточки (5.2.2), действия `start` и `resume`.
- Шаблоны станции: `templates/frontend/assessment/kege/entry.php`, `exam.php`, `finish.php`.

**Шаги**
- [ ] 6.5.1 `LearnerExamsService`: в карточку добавить `station_url` — постоянная ссылка варианта сеанса с `?exam_reg={registration_id}`;
  отдавать только в состояниях `entry_open` и `in_progress` и только ученику (не родителю). Для `in_progress` — ещё `deadline` (местное время) и
  `seconds_left`.
- [ ] 6.5.2 `learner-exams.js`: `entry_open` — синяя «Приступить» (ссылка на `station_url`), пояснение «Начать можно до {плановый конец}»;
  `in_progress` — «Продолжить», «Завершение в {дедлайн}». Серую кнопку состояния `registered` не трогать.
- [ ] 6.5.3 Автообновление карточки: пока есть карточка в `registered`, раз в 30 секунд перезапрашивать список, чтобы кнопка стала синей без перезагрузки.
  Таймер останавливать при уходе с экрана (`document.hidden`) и когда таких карточек нет.
- [ ] 6.5.4 Экран завершения станции (`finish.php`) для экзаменной попытки ученика: текст «Работа сдана и ожидает утверждения преподавателем.»,
  кнопка «К моим экзаменам» (`PageRoutes::UserProfile->url()` + `?screen=learner-exams`). Лист результатов до утверждения не раскрывается:
  `KegeResultSheetService` уже получает флаг `$revealed`; убедиться, что для экзаменной попытки он считается по новой политике (7.5), а до этапа 7 — `false`.
- [ ] 6.5.5 Кнопка «Вернуться» станции (`resolveBackUrl()` в `AssessmentPageController`) для экзамена ведёт в «Мои экзамены», а не в курс.
- [ ] 6.5.6 Проверка с телефона: открыть станцию на ширине 390 px. Если задания нельзя выполнить с телефона — **до старта** показать
  предупреждение «Экзамен рассчитан на компьютер.» на экране входа станции (SPEC §17). Новый мобильный плеер не делать.

**Тесты**
- `LearnerExamsServiceTest.php`: `test_station_url_present_only_for_entry_open_and_in_progress`, `test_parent_never_gets_station_url`,
  `test_in_progress_card_has_personal_deadline`.
- e2e (headless CDP): запись → наступление времени (сдвинуть `scheduled_at` сеанса в базе) → «Приступить» → станция → обновить страницу (попытка та же,
  `SELECT COUNT(*) FROM wp_fs_lms_assessment_attempts WHERE exam_participation_id = …` → 1) → ответить → сдать → «Ожидает утверждения».

**Готово, когда:** e2e проходит для КЕГЭ; тот же проход для ОГЭ.

---

## 6.6 Станцию нельзя добавить шагом курса

**Зачем.** Экзамены отделены от курса; Control остаётся шагом курса без изменений. В проде станций в курсах нет, миграция не нужна (SPEC §0, §14).

**Проверить перед началом**
- `inc/Services/Course/LessonAuthoringService.php`: `getStepCandidates()` (ветка `assessment`), `buildSteps()`, `refBelongsToSubject()`.
- `src/js/admin/services/step-editors/ref-editor.js` — «Выбрать существующую» / «Добавить новую».
- Готовая проверка использования работы в уроках: `grep -n "public function" inc/Services/Course/BankUsageIndex.php inc/Services/Course/ContentUsageService.php`.
- Сохранение вида работы: `AssessmentMetaBoxController::handleAssessmentSave()`.

**Шаги**
- [ ] 6.6.1 `getStepCandidates()`: для `kind = 'assessment'` отфильтровать кандидатов — оставить работы, у которых `AssessmentKind` не станция
  (`! $assessment->kind->isStation()`). Вид брать через `AssessmentManager::get()`.
- [ ] 6.6.2 `buildSteps()` / `refBelongsToSubject()`: шаг `assessment`, ссылающийся на станцию, отклонять при сохранении с текстом
  «Экзамен-станцию нельзя добавить в урок: назначьте его через „Мои экзамены“.» **Исключение:** шаг, который уже был сохранён в уроке со станцией
  (dev-данные), не удалять и не ломать — сравнивать с текущим составом шагов урока и отклонять только **новые** ссылки на станцию.
- [ ] 6.6.3 Смена вида работы на станцию, когда работа уже стоит в уроке: при сохранении метабокса проверить использование (готовым сервисом из проверки выше);
  если используется — вид не менять и показать уведомление в админке «Работа используется в уроках как контрольная: вид „экзамен“ недоступен.»
- [ ] 6.6.4 Control: ни один его путь не меняется. Прогнать существующие тесты плеера и конструктора.

**Тесты**
- `tests/Unit/Services/Course/LessonAuthoringServiceTest.php`: `test_station_assessments_are_not_step_candidates`,
  `test_control_assessments_are_step_candidates`, `test_new_step_with_station_is_rejected`,
  `test_existing_step_with_station_is_kept`.
- Тест сохранения вида работы — в тесте контроллера или менеджера, где уже проверяется `handleAssessmentSave()`:
  `test_kind_cannot_become_station_when_used_in_lesson`.
- Полный прогон тестов `tests/Unit/Callbacks/Course/LessonCallbacksTest.php`, `CourseBuilderCallbacksTest.php` — зелёные.

**Готово, когда:** тесты зелёные; в конструкторе урока в списке «Выбрать существующую» для шага «Контрольная» нет работ-станций.

---

## Проверка этапа (SPEC §16: 5, 6, 7, 31)

- [ ] Границы времени: 09:59 — отказ; 10:00 — старт; 13:54 — старт с дедлайном 17:49; 13:55 без старта — неявка; начавший раньше продолжает.
- [ ] Одновременные старт, неявка и отмена дают один исход (стенд).
- [ ] Старый Control без регрессий (тесты плеера и попыток зелёные).
- [ ] Экзаменная попытка отсутствует в журнале группы, «Работах», «Моих оценках», сводке по ученику.
- [ ] e2e: полный проход КЕГЭ и ОГЭ на станции.
- [ ] `npm run ci`, `npx gulp build` — зелёные.
