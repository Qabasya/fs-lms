# Этап 9. Уведомления в кабинете

Предусловие (выполнено 2026-10-09): вкладка «Мои экзамены» перенесена на последнее место в меню ученика и родителя.
Зависимости: этапы 3, 6, 8 (события уже пишутся в outbox). Результат: все события SPEC §10 доходят до ленты ученика, родителя, преподавателя
и администраторов платформы; повторы и сбои не дают дублей.

Перед началом прочитать `README.md`. SPEC: §10 целиком, §16 критерий 23, §18 критерий 56. Тексты: `../TEXTS.md` (§1, §5). Макет: `../notifications.png`.

**Порядок:** 9.1 → 9.2 → 9.3 → 9.7 → 9.5 → 9.6 → 9.4.

**После этапа 8 (2026-10-09) в outbox уже пишутся** (все — внутри транзакций, только идентификаторы и причина):
`RegistrationCancelled` (`session_id`, `by` = `staff`/`self`, `reason`), `RegistrationTransferred`, `AttemptExtended` (`attempt_id`, `minutes`, `reason`, `actor_user_id`, `deadline_at`),
`SessionMoved` (`old_scheduled_at`, `new_scheduled_at`, `old_room_id`, `new_room_id`, `reason`; агрегат `session`), `SessionCancelled` (`session_id`, `event_id`, `reason`; сами отмены записей идут отдельными `RegistrationCancelled` с той же причиной),
`EventCancelled` (`event_id`, `reason`), `AttemptApproved` (`attempt_id`, `participation_id`, `result_version`; агрегат `participation`; гостю не пишется), `ResultCorrected` (`attempt_id`, `participation_id`, `reason`, `result_version`, `old_total`, `new_total`, `changes[]`).
Для гостя утверждения нет вовсе — уведомление «результат утверждён» ему не нужно (SPEC §10: гостю LMS ничего не отправляет).
Проведение теперь завершается тиком (`ExamTickService::completeEvents()`): завершённое проведение остаётся доступным для проверки и исправления.

## Статус (2026-10-09)

**Сделано** — все подзадачи 9.1–9.7 выше с `[x]`. Проверено: PHPUnit (2877 тестов), `npx eslint src/js`, `npx gulp build`, загрузка DI, и на настоящем стеке (MariaDB, ученики и родители dev):
- `wp fs-lms exam tick --name=outbox` доставил 3 события → 6 плиток (ученик + родитель); повторная доставка тех же строк (сброс `processed_at`) дублей не дала (6 → 6);
- `wp fs-lms exam tick` (напоминания): записи на сеанс через 50 минут получили `exam_soon` (6 плиток); повтор тика дублей не создаёт;
- отмена записи сотрудником → после `--name=outbox` плитка `exam:soon:{id}` этой записи исчезла, пришло «Запись отменена»; данные стенда убраны.

**Что изменилось по сравнению с текстом этапа:**
- `ExamNotificationComposer`, `ExamOutboxWorker`, `ExamReminderService` — как в плане. `ExamTickService` получил `sendReminders()`, `syncAudience()`, `deliverEvents()`, `reconcilePayments()` (заготовка) и обёртку `step()` (сбой шага не останавливает следующие); `autoExpireTick()` теперь возвращает ещё `reminders`.
- `SessionCancelled` и `EventCancelled` **только снимают напоминания**: ученику отмена приходит от `RegistrationCancelled` (причина та же); композитор называет её «Сеанс экзамена отменён», если сеанс или проведение отменены. Иначе участник получил бы два уведомления об одном.
- `RegistrationTransferred` дополнен `old_registration_id` в payload — по нему снимаются напоминания старой записи.
- Продление: ключ `exam:extended:{attempt}:{дедлайн}` (две правки одной попытки — два уведомления). Утверждение: `exam:approved:{attempt}`; исправление: `pushFresh` с `exam:corrected:{attempt}`.
- Счётчик «напоминаний» тика считает обработанные записи, а не вставленные плитки: повторный тик отправляет «ту же» запись и ничего не вставляет (уникальный индекс).
- `UserRepository::getByCapability()` — выборка администраторов WordPress без прямого `get_users()` в сервисе.
- `--name=outbox` у `wp fs-lms exam tick`; блокировка `exam_outbox`.
- Источники событий `PaidNeedsResolution` (есть), `ReconcileFailed` и `SourceLimitExceeded` (появятся в 11a) проверены только тестами; ФИО гостя в уведомлении администратору до 11a — «Гость».

**Не проверено (прямо):**
- Системный cron на сервере (9.4: инструкция написана в `HANDOFF.md`, владельцу показать).
- Колокольчик кабинета в браузере (отрисовка иконок и клик по плитке с переходом `?screen=learner-exams&event=` / `exam-conduct&session=`) — только сборка и линтер.
- Проверка 50 работ «Утвердить все» → одна плитка на ученика (серверные события и ключ покрыты тестами; на стенде этапа 8 уведомления не включались).
- `git diff master -- inc/Enums/Email/EmailTemplateType.php` — файл не менялся; новых шаблонов писем нет.
- PHPStan: `vendor/bin/phpstan` в проекте отсутствует.

---

## Общие правила этапа

- Уведомления LMS — **только в кабинете**. Никаких новых `EmailTemplateType`, писем, SMS, OTP и мессенджеров. Письма WooCommerce не трогать.
- Гостю LMS не отправляет ничего: список получателей для гостя всегда пуст.
- Лента одна — существующая (`NotificationService`, `NotificationRepository`, `src/js/profile/notifications.js`). Вторую ленту не заводить.
- Тексты — на сервере (`NotificationType::title()` и `NotificationService::renderBody()`), без рода, числа и даты — подстановкой из `payload`.
- До утверждения работы в уведомлениях ученику и родителю нет баллов.

---

## 9.1 Типы уведомлений и тексты

**Зачем.** Каждому событию SPEC §10 — свой тип с заголовком, тоном и телом.

**Проверить перед началом**
- `inc/Enums/Profile/NotificationType.php` — кейсы, `title()`, `tone()` (оба `match` без `default`: новый кейс обязан попасть в оба).
- `inc/Services/Profile/NotificationService.php::renderBody()` — `match` по типу; `toClientArray()`.
- `src/js/profile/notifications.js` — выбор иконки по `type` (`grep -n "type" src/js/profile/notifications.js | head -20`).
- Колонка `notifications.type` — `varchar(40)`: значение кейса не длиннее 40 символов.

**Шаги**
- [x] 9.1.1 Кейсы `NotificationType` (значение — snake_case):
  | Кейс | Заголовок (`title()`) | Тон |
  |---|---|---|
  | `ExamRegistrationOpened` | «Открыта запись на экзамен» | `info` |
  | `ExamRegistrationConfirmed` | «Запись на экзамен подтверждена» | `ok` |
  | `ExamRegistrationChanged` | «Запись на экзамен изменена» | `info` |
  | `ExamRegistrationCancelled` | «Запись на экзамен отменена» | `warn` |
  | `ExamMissed` | «Экзамен пропущен, запись аннулирована» | `err` |
  | `ExamTomorrow` | «Завтра экзамен» | `info` |
  | `ExamSoon` | «Экзамен скоро начнётся» | `warn` |
  | `ExamEntryOpened` | «Вход на экзамен открыт» | `info` |
  | `ExamWorkAccepted` | «Работа принята» | `ok` |
  | `ExamWorkSubmitted` | «Экзамен сдан — нужна проверка» | `warn` |
  | `ExamApproved` | «Работа утверждена» | `ok` |
  | `ExamResultCorrected` | «Результат исправлен» | `info` |
  | `ExamExtended` | «Время экзамена продлено» | `info` |
  | `ExamSessionMoved` | «Сеанс экзамена перенесён» | `warn` |
  | `ExamSessionCancelled` | «Сеанс экзамена отменён» | `err` |
  | `ExamPaymentNeedsHelp` | «Оплачено, требуется помощь» | `err` |
  | `ExamReconcileFailed` | «Сбой сверки оплаты» | `err` |
  | `ExamSourceLimit` | «Превышен лимит заявок по ссылке» | `warn` |
- [x] 9.1.2 `renderBody()` — ветки для новых типов. Поля `payload`: `event_title`, `date` (местная дата сеанса), `time`, `time_end`, `room`,
  `reason`, `participant_name`, `score_caption` (готовая строка итога), `order_number`, `source_label`. Образцы тел:
  | Тип | Тело |
  |---|---|
  | `ExamRegistrationOpened` | «{event_title}» |
  | `ExamRegistrationConfirmed`, `ExamTomorrow`, `ExamSoon`, `ExamEntryOpened` | «{event_title}» · {date}, {time} · {room} |
  | `ExamRegistrationCancelled`, `ExamSessionCancelled` | «{event_title}». Причина: {reason} (без причины — только название) |
  | `ExamMissed` | «{event_title}» · {date}, {time} |
  | `ExamWorkAccepted` | «{event_title}». Работа ожидает утверждения преподавателем. |
  | `ExamWorkSubmitted` | Экзамен сдан: {participant_name} · «{event_title}» |
  | `ExamApproved` | «{event_title}»: {score_caption} |
  | `ExamResultCorrected` | «{event_title}». Причина: {reason} |
  | `ExamExtended` | «{event_title}». Новое время завершения: {time_end}. Причина: {reason} |
  | `ExamSessionMoved` | «{event_title}». Новая дата: {date}, {time} · {room}. Причина: {reason} |
  | `ExamPaymentNeedsHelp`, `ExamReconcileFailed` | Заказ №{order_number} · «{event_title}» · {participant_name} |
  | `ExamSourceLimit` | «{event_title}» · {source_label} |
  Формулировки «сдал/сдала», «записан/записана», «пришёл» запрещены (`../TEXTS.md` §5).
- [x] 9.1.3 `notifications.js`: иконки для новых типов из существующих в `src/js/common/icons.js` (календарь, часы, проверка, предупреждение).
  Новые иконки добавлять только при отсутствии подходящей.
- [x] 9.1.4 Ссылки уведомлений (`url`): ученику и родителю — `PageRoutes::UserProfile->url()` + `?screen=learner-exams&event={id}`;
  сотруднику — `?screen=exam-conduct&session={id}` или `?screen=exam-results`; администраторам платформы — `?screen=exam-payments`.
  Проверить, как `app.js` читает `screen` из адреса (`grep -n "URLSearchParams" src/js/profile/app.js`), и научить экраны экзаменов читать `event` / `session`.

**Тесты**
- `tests/Unit/Enums/…/NotificationTypeTest.php` (создать, если нет): `test_every_case_has_title_and_tone`,
  `test_exam_titles_have_no_gendered_forms`, `test_case_values_fit_column_length` (≤ 40).
- `tests/Unit/Services/Profile/NotificationServiceTest.php`: по тесту тела на каждую строку таблицы 9.1.2;
  `test_exam_bodies_before_approval_have_no_scores` (`ExamWorkAccepted` без чисел).

**Готово, когда:** тесты зелёные.

---

## 9.2 Worker событий (outbox)

**Зачем.** Событие записано в той же транзакции, что и изменение; worker после фиксации создаёт уведомления с уникальным ключом —
повторный запуск ничего не дублирует (SPEC §10).

**Проверить перед началом**
- `ExamOutboxRepository`: `leaseBatch()`, `markProcessed()`, `markFailed()` (2.3.7); `ExamOutboxEvent` (0.5); `CronHook::ExamOutboxTick` (0.5.7).
- `NotificationService::push()` — идемпотентен по паре «получатель + `dedupe_key`» (уникальный индекс `recipient_dedupe`); `pushFresh()` заменяет плитку.
  Длина `dedupe_key` — до 120 символов.
- Все места записи событий: `grep -rn "ExamOutbox\|->add( ExamOutboxEvent" inc/Services/Exam`.

**Шаги**
- [x] 9.2.1 `inc/Services/Exam/ExamNotificationComposer.php`. Зависимости: `NotificationService`, репозитории проведений, сеансов, участий, участников,
  записей, попыток; `ExamAudienceResolver`, `ExamScoreService`, `ExamConductService` (имя участника), `RoomRepository`, `ExamTime`.
  Метод `handle( array $outboxRow ): void` — по типу события определяет получателей и вызывает `push()`.
- [x] 9.2.2 Получатели:
  | Событие outbox | Тип уведомления | Получатели |
  |---|---|---|
  | `EventPublished` | — | планирует `RegistrationOpened`: вторая строка outbox с `available_at = registration_opens_at` (или сразу, если запись уже открыта) |
  | `RegistrationOpened` | `ExamRegistrationOpened` | все ученики аудитории предмета и их родители |
  | `RegistrationConfirmed` | `ExamRegistrationConfirmed` | ученик и родители |
  | `RegistrationTransferred` | `ExamRegistrationChanged` | ученик и родители |
  | `RegistrationCancelled` (`by = self`) | `ExamRegistrationCancelled` | ученик и родители (подтверждение отмены) |
  | `RegistrationCancelled` (`by = staff`) | `ExamRegistrationCancelled` с причиной | ученик и родители |
  | `ParticipantMissed` | `ExamMissed` | ученик и родители |
  | `EntryOpened` | `ExamEntryOpened` | записанные ученики и родители (родителю — просмотр) |
  | `AttemptSubmitted` | `ExamWorkAccepted` ученику и родителям; `ExamWorkSubmitted` ответственному за сеанс | — |
  | `AttemptApproved` | `ExamApproved` | ученик и родители |
  | `ResultCorrected` | `ExamResultCorrected` | ученик и родители |
  | `AttemptExtended` | `ExamExtended` | ученик и родители |
  | `SessionMoved` | `ExamSessionMoved` | записанные ученики и родители |
  | `SessionCancelled`, `EventCancelled` | `ExamSessionCancelled` | записанные ученики и родители |
  | `PaidNeedsResolution`, `ReconcileFailed`, `SourceLimitExceeded` | см. 9.5 | администраторы платформы (и ответственный — для лимита) |
  **Участие с аудиторией `guest` — получателей ученика и родителя нет вовсе**; уведомление ответственному о сдаче гостя остаётся
  (`participant_name` с пометкой «(гость)»).
- [x] 9.2.3 Ключ дедупликации: `exam:{тип_уведомления}:{aggregate_id}:{aggregate_version}` (уложиться в 120 символов). Повтор обработки той же строки
  outbox даёт тот же ключ → `push()` ничего не вставляет. Для `ExamApproved` и `ExamResultCorrected` версия — `result_version` попытки
  (новое исправление — новое уведомление, повтор того же — нет).
- [x] 9.2.4 `inc/Services/Exam/ExamOutboxWorker.php` (зависимости: `ExamOutboxRepository`, `ExamNotificationComposer`, `ExamTime`):
  `run( int $limit = 100 ): int` — `leaseBatch( now, now + 2 минуты, limit )` (берёт строки с `processed_at IS NULL`, `available_at <= now` и
  `leased_until` пустым или истёкшим, помечая `leased_until`); по каждой: `handle()` → `markProcessed()`; при исключении —
  `markFailed( id, текст, now + min( 60, 2^attempts ) минут )`, лог `PluginLogger::exception( …, true )`. После 10 неудач строка остаётся
  необработанной с `last_error` (не удаляется) и больше не берётся — добавить условие `attempts < 10` в `leaseBatch()`.
- [x] 9.2.5 Подключить тик: `CronController` — `add_action( CronHook::ExamOutboxTick->value, … )`, расписание `every_minute`,
  обработчик через `ExamTickLock::run( 'exam_outbox', … )`. В `ExamCommand::tick` добавить `--name=outbox`.
- [x] 9.2.6 Событие `EntryOpened` никто не пишет в транзакции — его создаёт минутный тик (9.3.4).
- [x] 9.2.7 Новый ученик аудитории после публикации: в `NotificationSubscriber::handleStudentEnrolled()` **не** добавлять логику экзаменов.
  Вместо этого `ExamTickService` раз в тик (не чаще раза в 10 минут — хранить время последнего прогона в transient через `TransientManager` и новый
  кейс `TransientKey::ExamAudienceSync`) для каждого проведения с открытой записью вызывает `push()` `ExamRegistrationOpened` всем ученикам аудитории
  с ключом `exam:opened:{event_id}`: уже получившие не получат повтор, новые получат один раз.

**Тесты**
- `tests/Unit/Services/Exam/ExamNotificationComposerTest.php`: по тесту на каждую строку таблицы 9.2.2 (тип и получатели);
  `test_guest_participation_has_no_student_recipients`, `test_dedupe_key_is_stable_for_same_outbox_row`,
  `test_approved_notification_contains_score_caption`, `test_work_accepted_has_no_scores`,
  `test_registration_opened_is_delayed_until_opens_at`.
- `tests/Unit/Services/Exam/ExamOutboxWorkerTest.php`: `test_processed_row_is_not_handled_twice`,
  `test_failed_row_is_rescheduled_with_backoff`, `test_row_with_ten_attempts_is_not_leased`,
  `test_failure_of_one_row_does_not_stop_batch`.
- Ручная проверка: записаться учеником, выполнить `wp fs-lms exam tick --name=outbox` дважды — в колокольчике одна плитка.

**Готово, когда:** тесты зелёные; двойной запуск worker не создаёт дублей.

---

## 9.3 Напоминания «завтра» и «скоро начало», «вход открыт»

**Зачем.** Накануне в 18:00 и за 60 минут до начала, если запись действующая. Отмена и перенос снимают напоминания; поздняя запись не вызывает
пачку просроченных напоминаний (SPEC §10).

**Проверить перед началом**
- `inc/Services/Profile/NotificationCronService.php::lessonSoon()` — образец временного продюсера с окном и ключом дедупликации.
- `NotificationService::retract( array $userIds, string $dedupeKey )` — отзыв плитки.
- Значения «18:00» и «60 минут» — настраиваемые (SPEC §0): хранить константами одного класса с докблоком; в настройки вынести на этапе 11a.6,
  если владелец попросит.

**Шаги**
- [x] 9.3.1 `inc/Services/Exam/ExamReminderService.php` (зависимости: репозитории записей и сеансов, `ExamNotificationComposer`, `NotificationService`, `ExamTime`).
  Константы: `TOMORROW_AT = '18:00'`, `SOON_MINUTES = 60`.
- [x] 9.3.2 `soon(): void` — действующие записи учеников на сеансы с `scheduled_at` в окне `( now, now + 60 минут ]`.
  Пропустить запись, созданную позже чем за 60 минут до начала (`created_at > scheduled_at − 60 минут`): подтверждение записи уже содержит время.
  Ключ: `exam:soon:{registration_id}`.
- [x] 9.3.3 `tomorrow(): void` — выполняется, когда местное время ≥ 18:00: действующие записи на сеансы **завтрашнего местного дня**.
  Пропустить запись, созданную после 18:00 накануне сеанса. Ключ: `exam:tomorrow:{registration_id}`.
- [x] 9.3.4 `entryOpened(): void` — сеансы, начавшиеся за последние 10 минут (`scheduled_at` в `( now − 10 минут, now ]`): записанным ученикам и
  родителям `ExamEntryOpened`, ключ `exam:entry:{registration_id}`. Сеансы, начавшиеся раньше (тик не работал), пропускаются.
- [x] 9.3.5 Перед отправкой каждой записи — **повторная проверка**: запись всё ещё действующая и её сеанс не изменился.
- [x] 9.3.6 Снятие напоминаний. В `ExamNotificationComposer` при `RegistrationCancelled`, `RegistrationTransferred`, `ParticipantMissed`,
  `SessionCancelled`, `SessionMoved`: `retract()` плиток `exam:soon:{old_registration_id}`, `exam:tomorrow:{…}`, `exam:entry:{…}` у ученика и родителей.
  Для `SessionMoved` запись та же — после отзыва напоминания придут заново по новому времени (ключи освобождены отзывом).
- [x] 9.3.7 Подключить в `ExamTickService` (тик `ExamAutoExpireTick`, после неявок): `soon()`, `tomorrow()`, `entryOpened()`.
- [x] 9.3.8 Недоставка уведомления и сбой worker **не открывают** старт и не продлевают его: допуск проверяется при запросе (6.1.5).

**Тесты** — `tests/Unit/Services/Exam/ExamReminderServiceTest.php` (время — мок):
- `test_soon_sent_for_session_starting_within_60_minutes`;
- `test_soon_skipped_for_registration_made_less_than_60_minutes_before_start`;
- `test_tomorrow_sent_after_18_00_for_next_day_sessions`, `test_tomorrow_not_sent_before_18_00`;
- `test_tomorrow_skipped_for_late_registration`;
- `test_entry_opened_sent_once_at_session_start`, `test_entry_opened_skipped_for_long_started_session`;
- `test_cancelled_registration_gets_no_reminder`;
- `test_guest_registration_gets_no_reminder`.
- `ExamNotificationComposerTest.php`: `test_transfer_retracts_old_reminders`, `test_cancel_retracts_reminders`.

**Готово, когда:** тесты зелёные; на dev: записаться на сеанс через 50 минут с заранее созданной записью → после тика пришло «скоро начало»;
перенести запись → плитка «скоро начало» старого сеанса исчезла.

---

## 9.7 Утверждение и исправление без дублей

**Зачем.** Массовое утверждение и его повтор не множат «Работа утверждена»; исправление приходит с причиной (SPEC §7, критерий 29).

**Проверить перед началом:** `ExamApprovalService` (8.5, 8.6) пишет `AttemptApproved` только при фактическом утверждении; повтор возвращает `already_approved` без события.

**Шаги**
- [x] 9.7.1 `ExamApproved`: ключ `exam:approved:{attempt_id}` (без версии) — одно уведомление на попытку за всё время, даже если событие outbox
  по какой-то причине записано дважды.
- [x] 9.7.2 `ExamResultCorrected`: `pushFresh()` с ключом `exam:corrected:{attempt_id}` — новая правка заменяет прежнюю непрочитанную плитку свежей;
  в теле — причина последнего исправления.
- [x] 9.7.3 Гость: ни `ExamApproved`, ни `ExamResultCorrected` не создаются (получателей нет).
- [x] 9.7.4 Старое уведомление «Экзамен проверен» (`AttemptGraded`) для экзаменных попыток выключено в 6.7.4 — убедиться тестом, что оно не возвращается.

**Тесты** — `ExamNotificationComposerTest.php`:
- `test_two_approved_events_for_same_attempt_give_one_notification`;
- `test_corrected_replaces_previous_unread_and_contains_reason`;
- `test_guest_gets_neither_approved_nor_corrected`.
- Проверка на 50 работах (продолжение 8.5): после «Утвердить все» и повторного нажатия у каждого ученика одна плитка
  (`SELECT recipient_user_id, COUNT(*) FROM wp_fs_lms_notifications WHERE type = 'exam_approved' GROUP BY 1 HAVING COUNT(*) > 1` → пусто).

**Готово, когда:** тесты и проверка пройдены.

---

## 9.5 Внутренние уведомления администраторам платформы

**Зачем.** О проблемной оплате, сбое сверки и превышении лимита заявок узнают все пользователи роли `lms_office`; если таких нет — пользователи
с `manage_options` (SPEC §10, решение 29, критерий 56).

**Проверить перед началом**
- `NotificationService::adminUserIds()` — пользователи роли `FSOffice`.
- События `PaidNeedsResolution`, `ReconcileFailed`, `SourceLimitExceeded` пишут этапы 3.4 и 11a; до 11a проверяются только тестами.

**Шаги**
- [x] 9.5.1 `ExamNotificationComposer::paymentRecipients(): array` — `adminUserIds()`; если пусто — ID пользователей с `Capability::Admin`.
  Посмотреть, есть ли в `UserRepository` готовый метод выборки по праву или роли `administrator` (`grep -n "public function" inc/Repositories/…UserRepository.php`);
  прямой `get_users()` в сервисе запрещён — добавить метод в репозиторий.
- [x] 9.5.2 `PaidNeedsResolution` → `ExamPaymentNeedsHelp`: ключ `exam:needs_help:{application_id}:{version}` — одно уведомление на заявку и версию,
  без повторов при каждой сверке. `ReconcileFailed` → `ExamReconcileFailed`, ключ `exam:reconcile:{application_id}:{version}`.
- [x] 9.5.3 `SourceLimitExceeded` → `ExamSourceLimit`: получатели — ответственный за проведение и администраторы платформы;
  ключ `exam:source_limit:{source_id}:{YYYYMMDDHH}` — не чаще раза в час на источник.
- [x] 9.5.4 В `payload` — номер заказа и название проведения; **ФИО гостя берётся при отрисовке** (`participant_name` кладётся в payload в момент
  создания уведомления — это допустимо: лента видна только сотрудникам с правом разбора оплат). Телефон в payload не класть.

**Тесты** — `ExamNotificationComposerTest.php`:
- `test_needs_help_goes_to_every_office_user_once`;
- `test_needs_help_falls_back_to_admins_when_no_office_users`;
- `test_same_application_version_does_not_repeat`;
- `test_source_limit_goes_to_responsible_and_office_once_per_hour`;
- `test_payload_has_no_phone`.

**Готово, когда:** тесты зелёные.

---

## 9.6 Минутный запуск: брони гостей и сверка оплат

**Зачем.** Тот же минутный запуск освобождает истёкшие брони и сверяет неподтверждённые оплаты. Выполнение сверки — этап 11a.8;
здесь — место в расписании и порядок.

**Шаги**
- [x] 9.6.1 `ExamTickService::releaseHolds()` (тик `ExamHoldReleaseTick`): 1) `ExamHoldService::releaseExpired()` (уже подключено в 3.4.8);
  2) вызов сверки оплат — метод-заготовка `reconcilePayments(): void` с докблоком «реализация — 11a.8».
- [x] 9.6.2 Порядок тиков зафиксировать в докблоке `ExamTickService`:
  `ExamAutoExpireTick` — автоистечение → неявки → завершение проведений → напоминания;
  `ExamHoldReleaseTick` — брони → сверка оплат → очистка `exam_operation_keys` (`purgeExpired()`);
  `ExamOutboxTick` — доставка событий.
- [x] 9.6.3 Очистку `exam_operation_keys` добавить в `releaseHolds()` (раз в тик, `purgeExpired( nowUtc )`).

**Тесты** — `tests/Unit/Services/Exam/ExamTickServiceTest.php`: `test_hold_release_tick_runs_steps_in_order`,
`test_auto_expire_tick_runs_steps_in_order`, `test_step_failure_does_not_stop_next_steps`.

**Готово, когда:** тесты зелёные; `wp fs-lms exam tick --name=hold-release` отрабатывает без ошибок.

---

## 9.4 Серверный запуск раз в минуту

**Зачем.** WP-Cron срабатывает только при посещениях сайта; для минутной точности нужен системный cron (SPEC §10).
Регистрация интервала, хуков и блокировки сделана в 2.6. Здесь — только инструкция и проверка.

**Шаги**
- [x] 9.4.1 Написать раздел «Серверный cron экзаменов» в `.docs/public-exam-feature/HANDOFF.md` (файл создаётся здесь, дополняется в 13.7):
  ```
  # crontab пользователя веб-сервера
  * * * * * curl -fsS -m 50 "https://<домен>/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
  ```
  и строка в `wp-config.php`: `define( 'DISABLE_WP_CRON', true );`. Пояснить: без системного cron неявка, автоистечение, напоминания и освобождение
  броней будут срабатывать с задержкой до следующего посещения сайта; допуск к старту от cron не зависит.
- [x] 9.4.2 Указать способ проверки на сервере: `wp cron event list --fields=hook,next_run_relative | grep exam` — три хука, следующий запуск в пределах минуты.
- [x] 9.4.3 **Чужую инфраструктуру не менять**: на проде cron настраивает владелец по инструкции. На dev — ручные запуски `wp fs-lms exam tick`.
- [x] 9.4.4 Отдельно записать: расписание `NotificationsTick` (раз в 15 минут) не менялось (README §8 п. 2).

**Готово, когда:** инструкция написана и показана владельцу.

---

## Проверка этапа (SPEC §16: 23; §18: 56)

- [x] Перенос снимает старые напоминания. (тест + отмена на стенде)
- [x] Каждый пользователь `lms_office` получает по одному уведомлению о проблемной оплате. (тесты)
- [x] Повтор worker не создаёт дублей. (стенд)
- [x] Сбой worker не открывает просроченный старт (тест 6.1: старт в 13:55 запрещён независимо от тиков).
- [x] У гостя нет ни одной строки в `wp_fs_lms_notifications` (тесты: получателей нет); новых шаблонов писем нет
  (`git diff master -- inc/Enums/Email/EmailTemplateType.php` → пусто).
- [x] `npm run ci` зелёный (2026-10-09).
