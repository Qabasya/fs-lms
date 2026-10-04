# Этап 3. Сервис записи и защита от гонок (без UI)

Зависимости: этап 2. Результат: `ExamRegistrationService` (запись, перенос, отмена), `ExamHoldService` (временная бронь гостя
без WooCommerce), события в outbox, стенд параллельных запросов на настоящей MariaDB.

Перед началом прочитать `README.md`. SPEC: §4 целиком, §6 «Временная бронь и подтверждение оплаты», §16 критерии 1–4, 6, 32–35, §18 критерии 39, 41.

**Порядок:** 3.1 → 3.2 → 3.3 → 3.4 → 3.5. Стенд 3.5 можно начинать сразу после 3.1 и расширять.

## Общие правила этапа

**Порядок блокировок един для всех операций** (SPEC §4). Нарушение порядка даёт взаимные блокировки:

1. участие (`exam_participations`, `FOR UPDATE`);
2. гостевая заявка (`exam_guest_applications`, `FOR UPDATE`) — если операция с ней работает;
3. сеансы по возрастанию ID (`ExamSessionRepository::lockInOrder()`);
4. записи оплаты (`exam_payment_links`) — этап 11a.

**Каждая операция — одна транзакция.** Отказ по бизнес-правилу — это исключение `CodedException` внутри транзакции:
`TransactionRunner` делает `ROLLBACK`, побочных эффектов не остаётся.

**Время** — `ExamTime::nowUtc()`. `time()`, `date()` и `current_time()` в сервисах этапа не использовать.


## Статус (проверено 2026-10-04)

**Сделано и проверено:** 3.1–3.5. Юнит-тесты поимённо по спецификации: `ExamRegistrationServiceTest` (58), `ExamHoldServiceTest` (34), `TransactionRunnerRetryTest`, `TransactionRunnerTest`.
Стенд гонок на настоящей MariaDB — `tests/stand/exam-race.sh`, результаты и повторы — `NOTES.md`, раздел «Стенд гонок».

**Что изменилось по сравнению с текстом этапа:** команды стенда лежат в `ExamStandCommand` (`fs-lms exam stand-seed|stand-register|stand-hold|stand-session|stand-report|stand-clean|stand-window|stand-start`),
класс заявки гостя — `ExamGuestApplicationDTO`/`ExamGuestApplicationRepository`; `confirmHeld()`/`confirmLate()` своей транзакции не открывают (их зовёт `convert()` под блокировкой заявки);
ключ идемпотентности — `exam_operation_keys` со сроком (`expires_at`), просроченный ключ считается новой операцией (тест `test_expired_operation_key_is_treated_as_new_operation`).

---

## 3.1 Запись с защитой от гонки, идемпотентность, outbox

**Зачем.** Два участника одновременно занимают последнее место — подтверждён ровно один. Двойной клик не создаёт вторую запись.

**Проверить перед началом**
- `inc/Shared/Traits/TransactionRunner.php` — `inTransaction()` без повторов. Его поведение менять нельзя: им пользуются
  `EnrollmentService` и другие (`grep -rn "use TransactionRunner" inc`).
- `AbstractExamRepository::write()` из 2.3.2 бросает `RetryableDbException` на ошибках 1213 и 1205.
- `ExamSessionRepository::occupySeat()` — условный `UPDATE … occupied_count < capacity`.
- `ExamAudienceResolver::isEligible()` (1.1), `ExamOutbox::add()` (2.3.8), `ExamOperationKeyRepository` (2.3.7).

**Шаги**
- [x] 3.1.1 В трейт `TransactionRunner` добавить **новый** метод (существующий не трогать):
  ```php
  public function inTransactionWithRetry( callable $fn, int $maxAttempts = 3 ): mixed
  ```
  Цикл: `START TRANSACTION` → `$fn()` → `COMMIT`. При `RetryableDbException` — `ROLLBACK`, пауза `usleep( random_int( 20000, 80000 ) )`,
  следующая попытка; после последней — пробросить. Любое другое исключение — `ROLLBACK` и проброс без повтора.
- [x] 3.1.2 `inc/DTO/Exam/RegistrationResultDTO.php`: `registrationId`, `participationId`, `sessionId`, `status` (`ExamRegistrationStatus`),
  `freeSeats`, `replayed` (bool — ответ взят по ключу идемпотентности), `warnings` (array). Методы `toArray()` и `fromArray()`
  (результат хранится в `exam_operation_keys.result_ref`).
- [x] 3.1.3 `inc/Services/Exam/ExamRegistrationService.php`, `use TransactionRunner;`. Зависимости: репозитории проведений, сеансов,
  участников, участий, записей, ключей операций; `ExamAudienceResolver`, `ExamOutbox`, `ExamTime`.
- [x] 3.1.4 Публичный метод ученика `register( int $personId, int $sessionId, string $requestKey ): RegistrationResultDTO`:
  1. `$requestKey` — непустая строка до 64 символов, иначе `CodedException( ErrorCode::ExamReplay, 'Повторите действие.' )`;
  2. сеанс и проведение читаются без блокировки; проведение `published`, иначе `ExamClosed`;
  3. `isEligible( $personId, $event->subjectKey )`, иначе `ExamAccess` («Экзамен недоступен для этого ученика.»);
  4. `$participantId = getOrCreateForPerson( $personId )`;
  5. вызвать `registerParticipant( $participantId, ExamAudience::Student, $sessionId, $requestKey, null )`.
- [x] 3.1.5 Публичный метод ядра записи
  `registerParticipant( int $participantId, ExamAudience $audience, int $sessionId, string $requestKey, ?int $actorUserId ): RegistrationResultDTO`.
  Им пользуются `register()`, перенос сотрудником, конвертация брони гостя и стенд. Всё тело — в `inTransactionWithRetry()`:
  1. **блокировка 1:** `getOrCreateLocked( $eventId, $participantId, $audience )`;
  2. **идемпотентность — уже под блокировкой участия** (иначе два одновременных одинаковых запроса оба пройдут проверку,
     и второй получит «запись уже есть» вместо прежнего ответа): `scope = 'participant:' . $participantId`, `operation = 'register'`.
     Найдена строка → `payload_hash` совпал с `hash( 'sha256', (string) $sessionId )` → вернуть сохранённый результат с `replayed = true`;
     не совпал → `ExamReplay`;
  3. **блокировка 2:** `sessions->findForUpdate( $sessionId )`;
  4. проверки под блокировкой — правила 3.3 (метод `assertCanRegister()`);
  5. **место:** `occupySeat( $sessionId )`. `false` → `ExamFull` (или `ExamHeld`, см. 3.4.7);
  6. создать запись (`status = confirmed`, `active_slot = 1`, `request_key`, `actor_user_id`), `setActiveRegistration()`;
  7. `ExamOutbox::add( RegistrationConfirmed, 'registration', $registrationId, 1, [ 'event_id', 'session_id', 'participation_id', 'audience' ] )`;
  8. сохранить результат в `exam_operation_keys` (`expires_at = now + 24 часа`);
  9. вернуть `RegistrationResultDTO` (`freeSeats` — из перечитанного сеанса).
- [x] 3.1.6 Уникальный индекс `(participation_id, active_slot)` — вторая линия защиты. Если вставка записи упала с ошибкой дубля
  (номер 1062), преобразовать в `CodedException( ErrorCode::ExamConflict, 'Запись на этот экзамен уже есть.' )`:
  в `AbstractExamRepository::write()` добавить для 1062 отдельное исключение `DuplicateKeyException`.

**Тесты** — `tests/Unit/Services/Exam/ExamRegistrationServiceTest.php` (моки репозиториев; транзакцию проверять по порядку вызовов):
- `test_register_occupies_seat_and_creates_confirmed_registration`;
- `test_register_returns_full_when_seat_not_occupied` — `occupySeat` → `false`: записи нет, outbox не вызван, ключ операции не сохранён;
- `test_register_locks_participation_before_session` — порядок вызовов моков;
- `test_register_writes_outbox_inside_transaction`;
- `test_same_request_key_returns_same_result_without_second_seat` — `occupySeat` вызван один раз;
- `test_same_request_key_with_other_session_is_rejected` — `ExamReplay`;
- `test_ineligible_student_is_denied`;
- `test_duplicate_key_is_reported_as_conflict`.
- `tests/Unit/Shared/TransactionRunnerRetryTest.php`: `test_retries_on_retryable_exception_up_to_limit`,
  `test_does_not_retry_business_exception`, `test_in_transaction_behaviour_unchanged`.

**Готово, когда:** тесты зелёные; `grep -n "function inTransaction(" -A14 inc/Shared/Traits/TransactionRunner.php` показывает прежнее тело метода.

---

## 3.2 Самоотмена, перенос, отмена сотрудником, история

**Зачем.** Ученик сам отменяет и переносит запись до начала своего сеанса; сотрудник отменяет не начатую запись с причиной;
неудачный перенос сохраняет старую бронь; история не стирается (SPEC §4).

**Проверить перед началом**
- `ExamRegistrationRepository::deactivate()` (2.3.6) меняет статус, только если `active_slot = 1`.
- `ExamAccessGuard::canManageEvent()` (2.4.1).

**Шаги**
- [x] 3.2.1 `change( int $personId, int $newSessionId, string $requestKey ): RegistrationResultDTO` — перенос учеником.
  Внутри `inTransactionWithRetry()`:
  1. заблокировать участие; затем идемпотентность (`operation = 'change'`, под блокировкой, как в 3.1.5);
  2. действующая запись обязана быть, иначе `ExamConflict` («Действующей записи нет.»);
  3. `lockInOrder( [ старый, новый ] )` — **по возрастанию ID**, а не «сначала старый»;
  4. проверки: оба сеанса одного проведения; `now < scheduled_at` **старого** сеанса (после начала своего сеанса перенос только у сотрудника);
     новый сеанс `open`, в будущем, окно записи открыто; попытки нет; старый ≠ новый;
  5. `occupySeat( новый )`. `false` → `ExamFull`. Транзакция откатывается — **старая бронь остаётся действующей**;
  6. `releaseSeat( старый )`; `deactivate( старая, Transferred, … )`; новая запись `confirmed`; `setActiveRegistration( новая )`;
  7. outbox `RegistrationTransferred` (`old_session_id`, `new_session_id`, `by = 'self'`).
- [x] 3.2.2 `cancelBySelf( int $personId, int $eventId, string $requestKey ): void` — блокировки участие → сеанс; разрешено при `now < scheduled_at`
  своего сеанса и отсутствии попытки; `deactivate( …, Cancelled, …, null, null )`, `releaseSeat()`, `setActiveRegistration( null )`;
  outbox `RegistrationCancelled` (`by = 'self'`). Повторный вызов с тем же ключом — без ошибки и без второго освобождения места.
- [x] 3.2.3 `cancelByStaff( int $actorUserId, int $registrationId, string $reason ): void`:
  - `canManageEvent()`, иначе `ExamAccess`;
  - причина после `trim()` не пуста, иначе `InvalidArgumentException( 'Укажите причину отмены.' )`;
  - разрешено и после начала сеанса, **пока попытка не начата** (`current_attempt_id === null`), иначе `ExamStarted`
    («Попытка уже начата: запись отменить нельзя.»). Начатую и сданную попытку обычная отмена не стирает;
  - outbox `RegistrationCancelled` (`by = 'staff'`, `reason`).
- [x] 3.2.4 `transferByStaff( int $actorUserId, int $registrationId, int $newSessionId, string $reason ): RegistrationResultDTO` —
  как `change()`, но: право — `canManageEvent()`; причина обязательна; ограничение «до начала своего сеанса» не действует;
  окно записи не проверяется; попытки нет. Запись остаётся привязанной к тому же участию — связь с оплатой гостя (через
  `exam_guest_applications.participation_id`) сохраняется сама. Outbox `RegistrationTransferred` (`by = 'staff'`, `reason`).
- [x] 3.2.5 `history( int $participationId ): array` — все записи участия по возрастанию `created_at` (статус, сеанс, причина, кто, когда).
  Строки не удаляются ни одной операцией этапа: `grep -n "delete" inc/Repositories/WPDBRepositories/ExamRegistrationRepository.php` → пусто.
- [x] 3.2.6 После `cancelled` и `missed` участие остаётся, `active_registration_id = NULL` — участник может записаться заново,
  если окно записи открыто и есть места. Отдельного кода для этого не нужно: проверить тестом.

**Тесты** — в `ExamRegistrationServiceTest.php`:
- `test_change_locks_sessions_in_ascending_id_order` — новый сеанс с меньшим ID блокируется первым;
- `test_failed_change_keeps_old_registration_active` — `occupySeat( новый ) = false`: `deactivate` и `releaseSeat` не вызваны;
- `test_successful_change_releases_old_seat_exactly_once`;
- `test_change_after_own_session_start_is_denied_for_student`;
- `test_self_cancel_before_start_releases_seat`, `test_self_cancel_after_start_is_denied`;
- `test_staff_cancel_requires_reason`, `test_staff_cancel_denied_when_attempt_started`;
- `test_staff_cancel_requires_event_scope` — преподаватель чужого проведения получает `ExamAccess`;
- `test_staff_transfer_allowed_after_session_start_without_attempt`;
- `test_can_register_again_after_cancel`;
- `test_cancel_is_idempotent_by_request_key`.

**Готово, когда:** тесты зелёные.

---

## 3.3 Правила записи и коды отказов

**Зачем.** Одни и те же правила для всех путей записи; интерфейс различает причины отказа по коду (SPEC §4, §11).

**Проверить перед началом**
- README §7.3 — коды.
- Пересечение с занятиями ученика: `grep -n "listByGroupAndDay\|listStartingBetween" inc/Repositories/WPDBRepositories/GroupLessonRepository.php` —
  какой метод даёт занятия группы на день. Время занятий местное.

**Шаги**
- [x] 3.3.1 Приватный метод `assertCanRegister( ExamEventDTO $event, ExamSessionDTO $session, ExamParticipationDTO $participation, string $nowUtc, bool $byStaff ): void`.
  Порядок проверок и ответы:
  | № | Условие отказа | Код | Текст |
  |---|---|---|---|
  | 1 | проведение не `published` | `ExamClosed` | «Запись на этот экзамен закрыта.» |
  | 2 | сеанс не `open` | `ExamClosed` | «Сеанс отменён.» |
  | 3 | `now < registration_opens_at` (не для сотрудника) | `ExamClosed` | «Запись ещё не открыта.» |
  | 4 | `now >= registration_closes_at` (не для сотрудника) | `ExamClosed` | «Запись закрыта.» |
  | 5 | `now >= scheduled_at` (не для сотрудника) | `ExamClosed` | «Сеанс уже начался.» |
  | 6 | для сотрудника: `now >= planned_end_at` | `ExamClosed` | «Сеанс завершён.» |
  | 7 | у участия есть попытка | `ExamStarted` | «Экзамен уже начат или сдан.» |
  | 8 | у участия есть действующая запись | `ExamConflict` | «Запись на этот экзамен уже есть.» |
  | 9 | действующая запись участника в **другом** проведении пересекается по времени | `ExamConflict` | «В это время уже есть запись на другой экзамен.» |
- [x] 3.3.2 Для правила 9 в `ExamRegistrationRepository` добавить
  `hasOverlappingActive( int $participantId, string $startUtc, string $endUtc, int $excludeEventId ): bool` — соединение записей
  (`active_slot = 1`), участий и сеансов; условие пересечения `scheduled_at < %s(end) AND planned_end_at > %s(start)`.
- [x] 3.3.3 Сдача в другом проведении лимит этого проведения не расходует, даже при том же варианте: правило 7 смотрит только
  на `current_attempt_id` **этого** участия. Проверить тестом.
- [x] 3.3.4 Пересечение с занятиями ученика — **предупреждение, не отказ**. Приватный метод `lessonOverlapWarning( int $personId, ExamSessionDTO $session ): bool`:
  активные группы ученика → занятия на день сеанса → сравнение с окном сеанса после `ExamTime::toLocal()`.
  При пересечении в `RegistrationResultDTO::$warnings` добавить `'lesson_overlap'`. Для гостя не вычисляется.
- [x] 3.3.5 Версия: операции над записью защищены блокировкой участия, отдельный `stale_version` для записи не нужен.
  Код `ExamStale` возвращают операции с проведением и сеансом (этап 2.4) и исправление результата (этап 8.6).

**Тесты** — в `ExamRegistrationServiceTest.php`, по одному на строку таблицы:
`test_rule_event_not_published`, `test_rule_session_cancelled`, `test_rule_registration_not_open_yet`,
`test_rule_registration_closed`, `test_rule_session_already_started`, `test_rule_attempt_exists`,
`test_rule_active_registration_exists`, `test_rule_overlap_with_other_event`,
`test_same_variant_in_other_event_does_not_block`, `test_lesson_overlap_gives_warning_not_error`.
В каждом проверять **код** (`$e->errorCode`), а не текст.

**Готово, когда:** тесты зелёные.

---

## 3.4 Временная бронь гостя на уровне сервиса (без WooCommerce)

**Зачем.** Гость до оплаты держит место ограниченное время. Бронь и запись ученика делят одну вместимость.
Конвертация брони в запись не занимает второе место. Истёкшая бронь освобождается ровно один раз (SPEC §6).
Форма, корзина и оплата — этап 11a; здесь только сервис и его тесты.

**Проверить перед началом**
- DDL `exam_guest_applications` (2.1): `is_held`, `hold_expires_at`, `state`, `active_slot`, `request_key`, уникальные индексы.
- `GuestApplicationState::holdsSeat()` (0.5.3).
- TTL брони по умолчанию — 20 минут; настройка появится в 11a.6. Сейчас значение приходит параметром метода.

**Шаги**
- [x] 3.4.1 `inc/DTO/Exam/ExamGuestApplicationDTO.php` (поля по колонкам, `state` — энум) и
  `inc/Repositories/WPDBRepositories/ExamGuestApplicationRepository.php`:
  `create( array $data ): int`, `find`, `findForUpdate`, `findBySourceAndRequestKey( int $sourceId, string $requestKey )`,
  `update( int $id, array $data ): void`,
  `releaseHeldFlag( int $id ): bool` — `UPDATE … SET is_held = 0 WHERE id = %d AND is_held = 1` (`true` при одной затронутой строке),
  `listExpiredHeldIds( string $nowUtc, int $limit ): array`, `listExpiredHeldIdsBySession( int $sessionId, string $nowUtc ): array`,
  `countHeldBySession( int $sessionId ): int`, `countHeldBySource( int $sourceId ): int`, `countHeldByIp( string $ipHash ): int`.
- [x] 3.4.2 `inc/Services/Exam/ExamHoldService.php`, `use TransactionRunner;`. Зависимости: репозитории заявок, сеансов, проведений,
  участников, участий; `ExamRegistrationService`, `ExamOutbox`, `ExamTime`.
- [x] 3.4.3 `capture( array $data, int $ttlMinutes ): ExamGuestApplicationDTO`. `$data`: `event_id`, `session_id`, `source_id`, `identity_hash`,
  `request_key`, `draft_enc`, `source_snapshot`, `consent_refs`, `ip_hash`, `created_by_user_id`. В `inTransactionWithRetry()`:
  1. **идемпотентность:** `findBySourceAndRequestKey()` — заявка найдена → вернуть её без изменений (бронь не продлевается);
     если у найденной заявки другой `session_id` или `identity_hash` → `ExamReplay`;
  2. блокировка сеанса (`findForUpdate`);
  3. синхронно освободить истёкшие брони **этого сеанса**: по каждому ID из `listExpiredHeldIdsBySession()` вызвать внутренний `releaseLocked()` (3.4.5);
  4. проверки: проведение `published` и `guest_registration_enabled`; окно записи открыто; сеанс `open`;
     `now < scheduled_at` (для заявки сотрудника на месте, `created_by_user_id` задан, — `now < planned_end_at`);
  5. `occupySeat()`. `false` → `ExamFull`;
  6. `hold_expires_at = min( now + TTL, registration_closes_at, scheduled_at )`; для заявки сотрудника — `min( now + TTL, planned_end_at )`;
  7. создать заявку: `state = hold`, `is_held = 1`, `active_slot = 1`.
  Нарушение уникального индекса `identity_active` (у личности уже есть активная заявка в проведении) →
  `CodedException( ErrorCode::ExamConflict, 'Заявка на этот экзамен уже оформлена.' )` — **без данных чужой заявки в тексте**.
- [x] 3.4.4 `convert( int $applicationId, ?int $actorUserId ): ExamGuestApplicationDTO` — подтверждение после оплаты. Порядок блокировок:
  заявка (`findForUpdate`) → сеанс. Ветки:
  | Состояние заявки | Действие |
  |---|---|
  | уже `confirmed` | вернуть как есть (повтор безвреден) |
  | `cancelled`, `missed` | не воскрешать; вернуть как есть |
  | `is_held = 1` | **место уже занято бронью** — создать участника, участие и запись **без** `occupySeat()`; `is_held = 0`, `state = confirmed` |
  | `is_held = 0`, сеанс `open`, `now < scheduled_at`, окно записи открыто | `occupySeat()`; удалось → подтвердить; не удалось → `paid_needs_resolution` |
  | `is_held = 0`, сеанс начался, отменён или мест нет | `state = paid_needs_resolution`, outbox `PaidNeedsResolution`; место не занимать, другой сеанс не выбирать |
  Для ветки без `occupySeat()` в `ExamRegistrationService` добавить метод
  `confirmHeld( int $participantId, int $sessionId, string $requestKey, int $sourceId ): RegistrationResultDTO` — те же шаги, что `registerParticipant()`,
  кроме шага «место» и проверок окна записи.
- [x] 3.4.5 Приватный `releaseLocked( int $applicationId, GuestApplicationState $newState ): bool` — вызывается под блокировкой сеанса:
  `releaseHeldFlag()` → только если вернул `true`: `releaseSeat()`, `state = $newState`, `active_slot = NULL`.
  Флаг `is_held` переключается один раз — это и есть гарантия «освобождается ровно один раз».
- [x] 3.4.6 `releaseExpired( int $limit = 100 ): int` — для минутного тика: по каждому ID из `listExpiredHeldIds()` отдельная транзакция:
  заявка `FOR UPDATE` → сеанс `FOR UPDATE` → повторная проверка `is_held = 1 AND hold_expires_at <= now` → `releaseLocked( …, ExpiredUnpaid )`.
  `release( int $applicationId, GuestApplicationState $newState ): bool` — то же для одной заявки (компенсация сбоя корзины, удаление позиции, отмена сотрудником).
  Данные заявки при освобождении **не удаляются**: поздняя оплата должна её найти.
- [x] 3.4.7 Различение «мест нет» для ученика: в `ExamRegistrationService::registerParticipant()` при `occupySeat() === false`:
  `countHeldBySession() > 0` → `CodedException( ErrorCode::ExamHeld, 'Свободных мест сейчас нет: часть мест удерживается до оплаты. Попробуйте позже.' )`,
  иначе `ExamFull` («Свободных мест нет.»).
- [x] 3.4.8 Подключить тик: `ExamTickService::releaseHolds()` (2.6.4) вызывает `ExamHoldService::releaseExpired()`.

**Тесты** — `tests/Unit/Services/Exam/ExamHoldServiceTest.php`:
- `test_capture_occupies_seat_and_sets_expiry_by_ttl`;
- `test_expiry_is_capped_by_registration_close_and_session_start`;
- `test_staff_on_site_expiry_is_capped_by_planned_end`;
- `test_capture_with_same_request_key_returns_same_application_without_second_seat`;
- `test_capture_releases_expired_holds_of_session_first`;
- `test_capture_full_session_throws_exam_full`;
- `test_second_active_application_of_same_identity_is_conflict_without_leaking_data`;
- `test_convert_live_hold_does_not_occupy_second_seat` — `occupySeat` не вызван;
- `test_convert_expired_hold_with_free_seat_occupies_and_confirms`;
- `test_convert_expired_hold_without_seat_becomes_paid_needs_resolution_and_writes_outbox`;
- `test_convert_confirmed_is_noop`, `test_convert_cancelled_is_not_resurrected`;
- `test_release_expired_frees_seat_once` — второй вызов: `releaseHeldFlag = false`, `releaseSeat` не вызван;
- `test_release_keeps_application_row`.
- В `ExamRegistrationServiceTest.php`: `test_full_with_active_holds_returns_exam_held`.

**Готово, когда:** тесты зелёные; `wp fs-lms exam tick --name=hold-release` отрабатывает без ошибок.

---

## 3.5 Стенд параллельных запросов

**Зачем.** Гонку доказывает только настоящая MariaDB с несколькими соединениями (SPEC §16 критерий 1). FakeWpdb этого не проверяет.

**Проверить перед началом**
- `ExamCommand` (1.4) и `selftest` (2.3.9).
- Контейнер WP-CLI: `docker compose -f /Users/daniil/FS-LMS/docker-compose.yml run --rm wpcli sh -c 'echo ok'` — оболочка есть.
- Стенд **не трогает** `persons`, `student_records`, `person_documents`: участники стенда — строки `exam_participants` без `person_id`,
  запись идёт через `registerParticipant()`.

**Шаги**
- [x] 3.5.1 Подкоманды `ExamStandCommand` (регистрируются как `fs-lms exam stand-*`):
  | Команда | Что делает |
  |---|---|
  | `fs-lms exam stand-seed --seats=<n> --participants=<m> [--sessions=<k>]` | создаёт проведение с названием `STAND <время>`, `k` сеансов в будущем с `capacity = n`, `m` участников; печатает ID сеансов и диапазон ID участников |
  | `fs-lms exam stand-register --session=<id> --participant=<id> [--key=<строка>]` | одна попытка записи; печатает одно слово: `confirmed`, `full`, `held`, `conflict`, `replay` или `error:<текст>` |
  | `fs-lms exam stand-hold --session=<id> --n=<номер>` | одна бронь гостя через `ExamHoldService::capture()` (личность `stand-<номер>`) |
  | `fs-lms exam stand-report --session=<id>` | `capacity`, `occupied_count`, число действующих записей, число действующих броней, число участий с двумя действующими записями |
  | `fs-lms exam stand-clean` | удаляет строки всех таблиц `exam_*`, относящиеся к проведениям `STAND%` |
  Сеансу стенда нужен кабинет с вместимостью: взять первый кабинет с `seats > 0`; если такого нет — остановиться с подсказкой про 0.9.
  Вместимость сеанса стенда задаётся напрямую (`capacity = --seats`), минуя `ExamEventService` — это тестовая фикстура, и в докблоке
  команды так и написать.
- [x] 3.5.2 Скрипт `tests/stand/exam-race.sh` (исполняемый, `set -eu`), параметры — число мест и участников. Внутри одного контейнера
  запускает параллельные процессы:
  ```sh
  docker compose -f /Users/daniil/FS-LMS/docker-compose.yml run --rm wpcli sh -c '
    for p in $(seq '"$FIRST"' '"$LAST"'); do
      wp fs-lms exam stand-register --session='"$SESSION"' --participant=$p --key=k$p &
    done
    wait
  '
  ```
  Затем печатает `stand-report` и сам сравнивает числа; при расхождении — код выхода `1`.
- [x] 3.5.3 Сценарии (каждый — отдельная функция скрипта или параметр `--scenario=`):
  | Сценарий | Вход | Ожидание |
  |---|---|---|
  | `last-seat` | 20 мест, 100 участников | ровно 20 `confirmed`, 80 `full`, `occupied_count = 20`, дублей нет, ни одного `error:` |
  | `multi-session` | 4 сеанса с местами 5, 10, 15, 20; 100 участников, сеанс выбирается случайно | по каждому сеансу подтверждённых ≤ `capacity` |
  | `same-participant` | один участник, 10 параллельных запросов: 5 с одним ключом, 5 с разными | одна действующая запись |
  | `two-sessions` | один участник одновременно в два сеанса одного проведения | одна действующая запись |
  | `guest-vs-student` | 1 место, параллельно 1 `stand-register` и 1 `stand-hold` | занято ровно одно место, один победитель |
  | `expired-hold` | бронь с TTL в прошлом, параллельно два `fs-lms exam tick --name=hold-release` | `occupied_count` уменьшился ровно на 1 |
- [x] 3.5.4 Для сценария `expired-hold` в `stand-hold` добавить флаг `--expired` (ставит `hold_expires_at` в прошлое).
- [x] 3.5.5 Результаты прогона (дата, версия MariaDB, числа по каждому сценарию, были ли повторы по deadlock) записать в
  `.docs/public-exam-feature/NOTES.md`, раздел «Стенд гонок». Раздел пополняется на этапах 6 и 13.1.
- [x] 3.5.6 После прогона — `stand-clean`. Проверка: `SELECT COUNT(*) FROM wp_fs_lms_exam_events WHERE title LIKE 'STAND%'` → `0`.

**Тесты.** Сам стенд и есть проверка. Юнит-тест нужен только на разбор результата команды: если логика подсчёта вынесена в
метод `ExamStandCommand::summarize( array $rows ): array` — покрыть его.

**Готово, когда:** все шесть сценариев проходят три прогона подряд; в выводе нет `error:`; `debug.log` (последние 15 строк) без ошибок SQL.

---

## Проверка этапа (SPEC §16: 1–4, 6, 32–35; §18: 39, 41)

- [x] 100 параллельных записей на 20 мест → ровно 20. — стенд `last-seat`: 20 `confirmed`, 80 `full`
- [x] Ученик и гость на последнее место → один победитель. — стенд `guest-vs-student`
- [x] Истёкшая бронь освобождается один раз. — стенд `expired-hold`
- [x] Повтор запроса не дублирует место, участие и событие. — стенд `same-participant`, `two-sessions`; тесты идемпотентности
- [x] Неуспешный перенос сохраняет старую бронь. — `test_failed_change_keeps_old_registration_active`
- [x] `npm run ci` зелёный. — по частям (2026-10-04): `eslint .` и `stylelint` без ошибок, `gulp styles:check` и `gulp build` успешны, PHPUnit в контейнере 2727 тестов без падений, `npm run test:js` 79 тестов; целиком `npm run ci` на Windows-хосте не идёт: `npm test` вызывает `vendor/bin/phpunit`, который хост не запускает
