# Этап 4. Календарь преподавателя «Назначить экзамен»

Зависимости: этапы 1 и 2 (этап 3 для этого этапа не нужен). Результат: сотрудник создаёт проведение, добавляет сеансы
в календаре, публикует, заводит ссылки для школьных преподавателей; события экзаменов видны на «Главной».

Перед началом прочитать `README.md` и `src/js/CLAUDE.md`. SPEC: §3, §6 «Пригласительная ссылка» и «Перевыпуск ссылки», §7 (меню), §13.
Макет: `../calendar.png`, `../mobile-calendar.png`, `../QA.md` (из каких существующих классов собран экран).

**Порядок:** 4.1 → 4.2 → 4.3 → 4.5 → 4.4 → 4.6 → 4.7.

## Общие правила этапа

- Все новые экраны — в `src/js/profile/exams/`. Сеть — только `createApi( window.fsProfile.exams )`.
- Разметку брать из существующих классов (`../QA.md`): `prof-ktp`, `kp-btn`, `prof-dot`, `prof-theme-bank`, `prof-theme-card`,
  `prof-kal`, `placed-theme`, `prof-ktp-empty`, поповер `prof-grade-pop` + `gp-form`, `fs-confirm-overlay`. Новый CSS-класс —
  только когда аналога нет, и только в `src/scss/profile/components/_exams.scss` (подключить в `profile.scss`).
- Права проверяет сервер. Клиент лишь прячет недоступное.
- Тексты кнопок и заголовков — без рода; даты и числа — подстановкой.


## Статус (проверено 2026-10-04)

**Сделано и проверено:** 4.1–4.7. Юнит-тесты — `ExamEventServiceTest`, `ExamPlanServiceTest`, `ExamRoomServiceTest`, `ExamSourceServiceTest`, `ExamEventCallbacksTest`, `ExamSourceCallbacksTest`,
`RoomAvailabilityServiceTest`, `RoomAssignmentServiceTest`, `SessionCalendarServiceTest`, `IndividualLessonServiceTest`, `TeacherProfileViewTest`, `DashboardServiceTest`, `tests/js/ktp-month-cells.test.mjs`,
`tests/js/exam-common.test.mjs`, `tests/js/exam-sources.test.mjs`; гонка за кабинет — стенд `room-race` (`created=1 conflict=49`).
**Браузер (headless Chrome):** календарь (проведение на 4 и 10 дней через границу месяцев, форма проведения и сеанса, публикация, телефон 390 px без перетаскивания),
блок экзаменов на «Главной» (клик ведёт на «Проведение экзамена»), секция «Ссылки для преподавателей» (добавить, создать, скопировать, перевыпустить через диалог с текстом последствий,
отозвать; в списке нет ключа и хеша).

**Что изменилось по сравнению с текстом этапа:**
- Секция «Ссылки для преподавателей» на сервере **скрыта до этапа 11a** (`TeacherProfileView`: `guestSignupReady = false`, страницы `/exam-signup/` ещё нет); для проверки флаг включался в открытой странице.
- `ExamRoomService::assertFree()` не дублирует проверку занятости, а зовёт `RoomAvailabilityService::isFree( …, $excludeExamSessionId )` — единая точка «свободен ли кабинет» (4.4.1–4.4.2); прямых вызовов `RoomRepository::isBusy()` вне неё нет.
- Экраны `exam-conduct`, `exam-stats`, `exam-results`, `exam-payments` — заглушки по 4.1 (наполнение — этапы 8–12).
- Репозиторий источников — `inc/Repositories/WPDBRepositories/ExamSourceRepository.php` (общий каталог слоя).

---

## 4.1 Раздел меню «Мои экзамены» у сотрудника

**Зачем.** Четыре пункта: «Проведение экзамена», «Статистика», «Назначить экзамен», «Результаты» (SPEC §7).
До своих этапов экраны — заглушки.

**Проверить перед началом**
- `inc/Services/Profile/TeacherProfileView.php::build()` — массивы `$nav` и `$screens`, блок офиса.
- `src/js/profile/app.js`: `SCREENS`, `TOPBAR`, `NAV_ICONS`, `buildSidebar()` (секции «Меню», «Мои группы», «Мои курсы» через
  `sectionHeader()` / `sectionBody()` и `sidebarState`).
- README §6: пункты показываются **по праву**, не по роли (администратор WP без LMS-роли получает витрину офиса).
- README §7.6 — ключи экранов.
- `grep -n "icoCalendar\|icoStar\|icoInbox\|icoDocCheck" src/js/common/icons.js` — какие иконки уже есть.

**Шаги**
- [x] 4.1.1 `TeacherProfileView::build()`: вычислить `$canExams = user_can( $context->wpUserId, Capability::ManageExams->value )` и
  `$canPayments = user_can( …, Capability::ResolveExamPayments->value )`.
  - при `$canExams` — в `$screens` добавить `exam-conduct`, `exam-stats`, `exam-plan`, `exam-results`;
  - при `$canPayments` — `exam-payments`;
  - в возвращаемый массив добавить ключ `examNav` — список `array{key, label}` в порядке SPEC:
    «Проведение экзамена», «Статистика», «Назначить экзамен», «Результаты», и (при `$canPayments`) «Оплаты гостей».
  В общий `$nav` эти пункты **не добавлять**: у них своя секция сайдбара.
- [x] 4.1.2 Там же блок конфига `exams` (только при `$canExams || $canPayments`): `nonce => Nonce::ExamManage->create()`, `actions => array()`
  (наполняется в 4.2–4.6), `subjects` — список `array{key, name}` из `ExamAccessGuard::manageableSubjectKeys()`, `guestSignupReady => false`
  (станет `true` на этапе 11a). Внедрить `ExamAccessGuard` в конструктор витрины.
- [x] 4.1.3 `app.js`: в `sidebarState` добавить `examsCollapsed: false`; в `buildSidebar()` после «Меню» — секция
  `sectionHeader( 'Мои экзамены', 'examsCollapsed' )` + `sectionBody( … )` с пунктами `cfg.examNav` (та же разметка `prof-nav-item` с `data-go`).
  Секции нет, если `cfg.examNav` пуст.
- [x] 4.1.4 `app.js`: добавить в `SCREENS`, `TOPBAR` (`crumb: 'Экзамены'`) и `NAV_ICONS` пять ключей. Рендереры — из новых файлов:
  `src/js/profile/exams/exam-plan.js` (`renderExamPlan`), `exam-conduct.js`, `exam-results.js`, `exam-stats.js`, `exam-payments.js`.
  В четырёх последних пока заглушка: `root.innerHTML = emptyState( … )` с текстом «Раздел в разработке.» (`emptyState` — из `./utils.js`).
- [x] 4.1.5 Селектор предмета на экранах экзаменов скрыт, если предмет один; при нуле предметов — пустое состояние
  «Нет предметов, по которым можно назначить экзамен.» (SPEC §2). Вынести в общий помощник `src/js/profile/exams/exam-common.js`:
  `subjectPickerHtml( subjects, current )`, `currentSubject()`.

**Тесты**
- `tests/Unit/Services/Profile/TeacherProfileViewTest.php` (если теста нет — создать по образцу соседних в `tests/Unit/Services/Profile`):
  `test_exam_nav_present_for_user_with_manage_exams`, `test_exam_nav_absent_without_capability`,
  `test_office_sees_only_payments_item`, `test_admin_without_lms_role_sees_exam_nav`,
  `test_exam_screens_not_in_main_nav`.

**Готово, когда:** тесты зелёные; `npx gulp build`; в кабинете преподавателя (`demoteacher` / `teacher123`) есть секция «Мои экзамены»
с четырьмя пунктами, переход по ним не даёт ошибок в консоли; у ученика секции нет.

---

## 4.2 Календарь по месяцам на общей модели КТП

**Зачем.** Сеансы назначаются в месячном календаре. Вместо группы — предмет, вместо тем — варианты, вместо урока — сеанс.
Календарь КТП переиспользуется, а не копируется (SPEC §3 «Переиспользование календаря»).

**Проверить перед началом**
- `src/js/profile/ktp/ktp-calendar-model.js`: `computeMonths( period )`, `initialCursor()`, `shiftMonth()` — чистые функции.
- `src/js/profile/ktp.js::renderCalendar()` — сборка сетки месяца (пустые ячейки смещения, `kal-cell`, `kal-date`) перемешана с данными КТП.
- `src/js/profile/ktp/ktp-templates.js`: `themeCardHtml()`, `placedThemeHtml()`.
- Перетаскивание: `attachDrag()`, `attachDrop()` в `ktp.js`.
- `tests/js/` — как устроены JS-тесты (`register.mjs`, `*.test.mjs`).

**Шаги**
- [x] 4.2.1 Выделить сборку сетки в чистую функцию в `ktp-calendar-model.js`:
  ```js
  /** @returns {Array<{type:'empty'}|{type:'day', date:string, day:number}>} ячейки месяца с ведущими пустыми */
  export function monthCells( year, month ) { … }
  ```
  `ktp.js::renderCalendar()` перевести на неё **без изменения разметки и поведения** (сравнить HTML сетки до и после на одной группе).
- [x] 4.2.2 Серверная часть. `inc/Callbacks/Exam/ExamEventCallbacks.php` (`extends BaseController`, `use Authorizer; use Sanitizer;`)
  и `inc/Controllers/Exam/ExamController.php` (`extends AjaxController`, в `Init::getServices()`). Экшены (README §4.1):
  | `AjaxHook` | Параметры | Ответ |
  |---|---|---|
  | `GetExamPlan` | `subject_key`, `event_id?` | `events` (проведения предмета: id, title, status, period, version), `event` (выбранное: поля + `sessions`), `variants` (`ExamVariantPolicy::listForSubject()`), `rooms` (активные, с `seats > 0`, разрешающие предмет: id, name, seats) |
  Каждый метод: `$this->authorize( Nonce::ExamManage, Capability::ManageExams )`, затем `ExamAccessGuard::canManageSubject()` /
  `canManageEvent()`; отказ — `$this->fail( ErrorCode::ExamAccess, … )`. Времена в ответе — местные (`ExamTime::toLocal()`).
  Преподаватель видит в `events` **только свои** проведения, глобальный пользователь — все проведения предмета.
- [x] 4.2.3 `exam-plan.js`, экран в три зоны (как КТП): шапка (предмет, проведение, «Настройки проведения», «Опубликовать», легенда),
  слева банк вариантов (`prof-theme-bank` + `prof-theme-card`), справа календарь (`prof-kal`).
  Месяцы — `computeMonths( { from: period_from, to: period_to } )`: календарь показывает **только месяцы периода**, выходные не скрываются,
  переход через месяц и год работает стрелками (`shiftMonth`).
- [x] 4.2.4 Ячейка дня: дни вне периода — класс `no-lesson` и без добавления; внутри периода — список сеансов дня (`placed-theme`: время,
  вариант, кабинет, «занято N из M») и кнопка «+ Сеанс».
- [x] 4.2.5 Перетаскивание варианта из банка на день открывает **ту же форму сеанса** (4.3) с подставленными датой и вариантом.
  Само перетаскивание ничего не сохраняет и не публикует.
- [x] 4.2.6 Телефон (ширина ≤ 720 px): перетаскивания нет; сеанс добавляется кнопкой «+ Сеанс» в ячейке, вариант выбирается в форме.
  Кнопка есть и на компьютере — это равноправный способ.
- [x] 4.2.7 Пустые состояния: нет проведений — `prof-ktp-empty` с кнопкой «Создать проведение»; нет вариантов —
  «Нет опубликованных вариантов этого предмета.»; нет кабинетов с вместимостью — «Укажите вместимость кабинетов в „Настройки → Кабинеты“.»

**Тесты**
- `tests/js/ktp-month-cells.test.mjs`: `monthCells(2026, 9)` (октябрь 2026) — число ведущих пустых ячеек и 31 день;
  февраль високосного года; месяц, начинающийся с понедельника (ноль пустых).
- `tests/Unit/Callbacks/Exam/ExamEventCallbacksTest.php`: `test_get_plan_requires_manage_exams`,
  `test_get_plan_denied_for_foreign_subject`, `test_teacher_gets_only_own_events`,
  `test_rooms_list_excludes_rooms_without_capacity`, `test_times_are_returned_in_site_local_time`.

**Готово, когда:** тесты зелёные (`npm run test:js`, PHPUnit); КТП выглядит и работает как прежде (проверить перетаскивание темы и смену месяца);
календарь экзаменов показывает период 2026-10-30 … 2026-11-08 в двух месяцах с выходными.

---

## 4.3 Форма сеанса

**Зачем.** Одна форма для кнопки «+ Сеанс» и для перетаскивания: время, вариант, кабинет, ответственный (SPEC §3).

**Проверить перед началом**
- `src/js/profile/indi-modal.js` — образец поповера: `#profGradePop`, `gp-form`, `gp-field`, `openGradePopPositioned()`, `closeGradePop()`, `toast()`.
- `ExamEventService::saveSession()` и `deleteSession()` (2.4.6–2.4.7).

**Шаги**
- [x] 4.3.1 Экшены в `ExamEventCallbacks`:
  | `AjaxHook` | Параметры |
  |---|---|
  | `SaveExamSession` | `event_id`, `session_id?`, `date`, `time`, `assessment_id`, `room_id`, `version?` |
  | `DeleteExamSession` | `session_id` |
  Ввод — `requireInt()`, `requireText()`, `sanitizeInt()`. `CodedException` → `$this->fail( $e->errorCode, $e->getMessage() )`,
  `InvalidArgumentException` → `$this->error( … )`. Успех — обновлённый сеанс в местном времени.
- [x] 4.3.2 `src/js/profile/exams/exam-session-form.js` — `openSessionForm( { api, anchor, event, variants, rooms, fixed: { date?, assessmentId? }, edit?, onSaved } )`.
  Поля:
  | Поле | Вид |
  |---|---|
  | Дата | фиксирована из ячейки (текстом) или `input type="date"` с `min`/`max` = период проведения |
  | Время начала | `input type="time"` |
  | Окончание | **только текст**, считается из длительности формата выбранного варианта («до 13:55»); поля ввода нет |
  | Вариант | `select` из `variants`; по умолчанию основной вариант проведения |
  | Кабинет | `select` из `rooms`, подпись «название · N мест»; обязателен |
  | Мест | **только текст** — вместимость выбранного кабинета; поля ввода нет |
  | Ответственный | **только текст** — владелец проведения |
  Длительность формата приходит в `variants[].duration_minutes` (добавить в `ExamVariantPolicy::listForSubject()`).
- [x] 4.3.3 Сохранение: кнопка блокируется на время запроса; ошибка сервера показывается внутри формы (не только `toast`), форма не закрывается,
  введённое сохраняется; успех — закрыть, вызвать `onSaved()`, перерисовать календарь.
- [x] 4.3.4 Правка сеанса — клик по сеансу в ячейке открывает ту же форму в режиме правки с кнопкой «Удалить сеанс» (через `confirmDialog()` из
  `src/js/common/components/confirm-dialog.js`). Если сеанс уже начат (`is_locked`) — поля варианта, даты, времени и кабинета недоступны,
  показан текст «Сеанс уже начат: общие параметры менять нельзя.»
- [x] 4.3.5 Конфликт версии (`X-STALE`): текст «Сеанс изменили в другой вкладке. Обновите календарь.» и кнопка «Обновить».

**Тесты** — `ExamEventCallbacksTest.php`:
- `test_save_session_passes_local_date_and_time_to_service`;
- `test_save_session_returns_coded_error_from_service` — в ответе есть `code`;
- `test_save_session_denied_without_event_scope`;
- `test_delete_session_delegates_to_service`.

**Готово, когда:** тесты зелёные; вручную: добавить сеанс кнопкой и перетаскиванием — открывается одна и та же форма;
кабинет без вместимости в списке отсутствует; занятый кабинет даёт сообщение в форме.

---

## 4.5 «Настройки проведения» и публикация

**Зачем.** Название, период, окно записи, основной вариант; публикация — явное действие после корректного сеанса (SPEC §3).

**Проверить перед началом**
- `ExamEventService::createDraft()`, `updateEvent()`, `publish()`, `cancelEvent()` (2.4).
- Поповер `prof-grade-pop` + `gp-form` (как в 4.3).

**Шаги**
- [x] 4.5.1 Экшены в `ExamEventCallbacks`:
  | `AjaxHook` | Параметры |
  |---|---|
  | `SaveExamEvent` | `event_id?`, `subject_key`, `title`, `description`, `period_from`, `period_to`, `registration_opens_at`, `registration_closes_at`, `default_assessment_id`, `guest_registration_enabled`, `version?` |
  | `PublishExamEvent` | `event_id`, `version` |
  | `CancelExamEvent` | `event_id`, `reason`, `version` |
- [x] 4.5.2 `src/js/profile/exams/exam-event-form.js` — `openEventForm( { api, anchor, subjectKey, event?, variants, onSaved } )`.
  Поля: название; описание для участника (`textarea`); период «с — по» (два `input type="date"`, любые дни, включая выходные и разные месяцы);
  «Запись открыта с» и «по» (`input type="datetime-local"`); основной вариант (`select`); переключатель «Запись гостей по ссылкам школ»
  (виден, только если `cfg.guestSignupReady`). Предмет в форме правки не меняется.
- [x] 4.5.3 Клиентская проверка до отправки (дублирует серверную, не заменяет): название не пусто; «с» ≤ «по»; открытие записи ≤ закрытие.
  Ошибка — у поля, в существующей разметке ошибки `gp-form` (посмотреть в `indi-modal.js`, как показывается ошибка поля).
- [x] 4.5.4 Кнопка «Опубликовать» в шапке экрана: активна для черновика с хотя бы одним сеансом. Перед публикацией — `confirmDialog()`:
  «После публикации ученики предмета увидят экзамен и смогут записываться с даты открытия записи.» Ошибку сервера
  («Добавьте хотя бы один сеанс в будущем.») показать `toast( …, 'err' )`.
- [x] 4.5.5 Статус проведения в шапке — пилюлей `prof-state-pill` с подписью из `ExamEventStatus::label()` (приходит с сервера строкой `status_label`).
- [x] 4.5.6 «Отменить проведение» — пункт меню действий (`openCtxMenu()` из `utils.js`), форма с обязательной причиной. Полная отмена с участниками —
  этап 8.3; до него кнопка доступна только для проведений без записей (сервер отвечает ошибкой, если записи есть — добавить проверку в `cancelEvent()`
  с пометкой `// TODO(8.3): снять ограничение`).

**Тесты** — `ExamEventCallbacksTest.php`:
- `test_save_event_creates_draft_for_own_subject`;
- `test_save_event_rejected_for_foreign_subject`;
- `test_publish_passes_expected_version`;
- `test_publish_stale_version_returns_x_stale`;
- `test_cancel_requires_reason`.

**Готово, когда:** тесты зелёные; e2e через headless CDP (см. память проекта «E2E-проверка через headless CDP»):
создать проведение на 4 дня и на 10 дней через границу месяцев, добавить сеанс, опубликовать; на ширине 390 px добавить сеанс без перетаскивания.

---

## 4.4 Кабинет занят на плановое окно, проверка в обе стороны

**Зачем.** Кабинет занят ровно `[scheduled_at, planned_end_at]`. Экзамен не ставится поверх занятия и другого экзамена, **и занятие не ставится
поверх экзамена**. Одновременные назначения не проходят оба. Поздний старт кабинет не резервирует — только предупреждение преподавателю (SPEC §3, критерий 9).

**Проверить перед началом**
- `ExamRoomService::assertFree()` (2.4.2) уже проверяет «экзамен → экзамен» и «экзамен → занятие».
- Все места, где проверяется занятость кабинета занятием: `grep -rn "isFree\|isBusy\|listFreeRooms" inc --include="*.php"` —
  `RoomAvailabilityService`, `RoomAssignmentService`, `IndividualLessonService`, `GroupCalendarService`, `ScheduleReflowService`. Записать список.
- `RoomRepository::isBusy()` — запрос только по `group_lessons`.

**Шаги**
- [x] 4.4.1 Сделать так, чтобы **единственная точка** «свободен ли кабинет» видела экзамены. В `RoomAvailabilityService` добавить зависимости
  `ExamSessionRepository` и `ExamTime`; в `isFree()` после проверки занятий добавить проверку сеансов:
  `! $this->examSessions->isRoomBusy( $roomId, toUtc( $start ), toUtc( $end ) )`. Параметры `isFree()` — местное время, как сейчас.
  `listFreeRooms()` использует `isFree()` и начинает учитывать экзамены автоматически.
- [x] 4.4.2 Пройти список из проверки. Каждый сервис, который вызывает `RoomRepository::isBusy()` **напрямую**, минуя `RoomAvailabilityService`,
  перевести на `RoomAvailabilityService::isFree()`. Если прямой вызов нужен из-за параметра `excludeGroupId` — он уже есть у `isFree()`.
- [x] 4.4.3 Сериализация назначения. В `ExamEventService::saveSession()` внутри транзакции **перед** `assertFree()` заблокировать строку кабинета:
  в `RoomRepository` добавить `lockForUpdate( int $roomId ): void` (`SELECT id FROM %i WHERE id = %d FOR UPDATE`).
  Два одновременных назначения одного кабинета выполняются по очереди, второе видит первое.
- [x] 4.4.4 Назначение занятия в кабинет тоже должно брать эту блокировку. Найти методы записи кабинета занятию
  (`RoomAssignmentService::assignToLesson()`, `assignToGroup()`, `overrideForRange()`, создание индивидуального занятия) и обернуть
  «проверка + запись» в `inTransaction()` с `lockForUpdate()` в начале. Если метод уже в транзакции — только добавить блокировку.
  Поведение и тексты ошибок этих методов не менять.
- [x] 4.4.5 Предупреждение о позднем старте. В `ExamRoomService` добавить
  `lateStartConflicts( ExamSessionDTO $session, string $latestDeadlineUtc ): array` — занятия и сеансы в этом кабинете в окне
  `( planned_end_at, latestDeadline ]`. Возвращает список `array{kind:'lesson'|'exam', title:string, start:string}`.
  Метод ничего не блокирует и не отменяет. Показ предупреждения — на экране «Проведение экзамена» (этап 8.1); здесь только метод и тест.
- [x] 4.4.6 Дополнительной брони после `planned_end_at` нет: поля `room_reserved_until` не вводить, `isRoomBusy()` считает окно строго
  `[scheduled_at, planned_end_at]`.

**Тесты**
- `tests/Unit/Services/Course/RoomAvailabilityServiceTest.php` (есть ли — `ls tests/Unit/Services/Course | grep -i room`; дописать или создать):
  `test_room_busy_by_exam_session_is_not_free_for_lesson`, `test_exam_window_is_converted_to_utc`,
  `test_room_free_right_after_planned_end` — занятие в 13:55 после экзамена 10:00–13:55 разрешено;
  `test_list_free_rooms_excludes_room_with_exam`.
- `tests/Unit/Services/Exam/ExamRoomServiceTest.php`: `test_late_start_conflicts_lists_lesson_after_planned_end`,
  `test_no_conflicts_when_room_empty_after_end`.
- `ExamEventServiceTest.php`: `test_save_session_locks_room_before_check`.
- Стенд (расширение 3.5): сценарий `room-race` — две параллельные команды `fs-lms exam stand-session --room=<id> --at=<время>` назначают сеанс
  в один кабинет на одно время → создан ровно один.
- Существующие тесты `RoomAssignmentService`, `IndividualLessonService`, `GroupCalendarService` — зелёные без изменения ожиданий.

**Готово, когда:** тесты и сценарий стенда проходят; вручную: поставить сеанс экзамена на время занятия группы — отказ; поставить индивидуальное
занятие в кабинет на время сеанса — отказ, кабинет отсутствует в списке свободных.

---

## 4.6 «Ссылки для преподавателей» — источники приглашений

**Зачем.** Гость попадает на форму только по ссылке источника: школа, класс 9 или 11, ФИО школьного преподавателя.
Ссылку можно создать, скопировать и перевыпустить с подтверждением (SPEC §6, §8). Сама форма — этап 11a; здесь источники и ключи.

**Проверить перед началом**
- DDL `exam_sources` (2.1), `ExamAccessTokenService` (2.5), README §8 п. 1 (хеш ключа — только в `exam_access_tokens`).
- `ExamDirection::grade()` (0.3.1): ЕГЭ — 11, ОГЭ — 9. Направление проведения = направление формата его основного варианта.
- `src/js/common/components/copy-button.js` — готовое копирование в буфер; `confirmDialog()`.
- Журнал: как пишется событие аудита — `grep -rn "LogEvent::ScheduleChanged" inc/Services` (образец вызова диспетчера).
- Секция **скрыта**, пока `cfg.guestSignupReady === false` (до 11a). Серверная часть и тесты делаются сейчас.

**Шаги**
- [x] 4.6.1 `inc/Repositories/WPDBRepositories/ExamSourceRepository.php`: `create`, `find`, `findForUpdate`, `update( …, int $expectedVersion )`,
  `listByEvent( int $eventId ): array`, `bumpGeneration( int $id ): int` (`key_generation = key_generation + 1`, возвращает новое значение).
- [x] 4.6.2 `inc/Services/Exam/ExamSourceService.php`. Зависимости: репозиторий источников, проведений, `ExamAccessGuard`, `ExamAccessTokenService`,
  `ExamFormatRegistry`, `AssessmentManager`, `LogEventDispatcherInterface`, `ExamTime`. Методы (каждый начинает с `canManageEvent()` и права
  `ManageExamGuests`):
  - `save( int $actorUserId, int $eventId, array $input, ?int $sourceId, ?int $expectedVersion ): ExamSourceDTO` — `school_name` и `teacher_name`
    обязательны; `grade` строго `9` или `11` и **равен** `direction->grade()` проведения, иначе
    «Класс не соответствует направлению проведения.»; `school_name_normalized` — нижний регистр, схлопнутые пробелы;
    правка источника не меняет уже созданные заявки (у них свой `source_snapshot`);
  - `issueLink( int $actorUserId, int $sourceId ): string` — только если у источника **ещё нет** действующего ключа; создаёт ключ
    (`ExamTokenPurpose::Invitation`, `target_id = source_id`, `expires_at = registration_closes_at` проведения), `bumpGeneration()`, возвращает URL;
  - `reissueLink( int $actorUserId, int $sourceId ): string` — новый ключ (старый отзывается внутри `issue()`), `bumpGeneration()`,
    `key_revoked_at = null`; запись в журнал: кто, когда, какой источник;
  - `revokeLink( int $actorUserId, int $sourceId ): void` — отзыв без выпуска нового: `key_revoked_at = now`, новые заявки по ссылке невозможны;
    оплаченные записи и действующие брони не трогаются;
  - `setActive( int $actorUserId, int $sourceId, bool $active ): void`.
- [x] 4.6.3 URL формы: `home_url( '/exam-signup/' ) . '?k=' . $plain`. Адрес собирает один приватный метод `signupUrl( string $plain ): string`;
  слаг вынести в `PageRoutes::ExamSignup = 'exam-signup'` (страницу создаёт этап 11a.2).
  **Открытый ключ после ответа нигде не остаётся**: повторно «Скопировать» можно только пока форма открыта (значение держит JS в памяти);
  после закрытия — только «Перевыпустить».
- [x] 4.6.4 `inc/Callbacks/Exam/ExamSourceCallbacks.php`, экшены (право `Capability::ManageExamGuests`, nonce `ExamManage`):
  `GetExamSources` (`event_id`), `SaveExamSource`, `IssueExamSourceLink`, `ReissueExamSourceLink`, `RevokeExamSourceLink`, `ToggleExamSource`.
  В списке источников отдавать: поля источника, `has_link` (bool), `generation`, `active_holds` (число действующих броней — `0` до 11a).
  **Ни хеш, ни ключ в списке не отдаются.** Ответы выдачи и перевыпуска содержат `url` один раз.
  Выдача и копирование ссылок не требуют `ExportPII` и `ManageLmsPlatform` (SPEC §2).
- [x] 4.6.5 Интерфейс: секция «Ссылки для преподавателей» внутри формы «Настройки проведения» (`exam-event-form.js`), видна при
  `cfg.guestSignupReady` и включённом «Запись гостей». Повторяемые строки: школа, класс (значение фиксировано направлением — текстом),
  ФИО преподавателя, переключатель активности, кнопки «Создать ссылку» / «Скопировать» / «Перевыпустить». Вынести в `exam-sources.js`.
- [x] 4.6.6 «Перевыпустить» — только через `confirmDialog()` с текстом последствий (SPEC §6, дословно):
  «Старая ссылка перестанет работать сразу, уже открытые по ней формы потеряют доступ; оплаченные записи и действующие брони сохраняются;
  новую ссылку нужно отправить школе заново.» Кнопка подтверждения — «Перевыпустить». «Скопировать» подтверждения не требует.

**Тесты**
- `tests/Unit/Services/Exam/ExamSourceServiceTest.php`:
  `test_grade_must_match_event_direction` (источник 9 класса для проведения ЕГЭ отклоняется),
  `test_grade_other_than_9_or_11_rejected`, `test_issue_link_returns_url_with_key_and_bumps_generation`,
  `test_issue_link_twice_is_rejected_use_reissue`, `test_reissue_revokes_old_key_and_writes_audit_event`,
  `test_revoke_sets_key_revoked_at`, `test_requires_manage_exam_guests_and_event_scope`,
  `test_editing_source_does_not_touch_existing_applications`.
- `tests/Unit/Callbacks/Exam/ExamSourceCallbacksTest.php`:
  `test_list_never_contains_key_or_hash`, `test_issue_returns_url_once`,
  `test_actions_do_not_require_export_pii`, `test_denied_without_manage_exam_guests`.

**Готово, когда:** тесты зелёные; `grep -rn "token_hash\|plain" inc/Callbacks/Exam/ExamSourceCallbacks.php` не показывает выдачи хеша;
вручную (временно выставив `guestSignupReady = true` на dev): создать источник, выдать ссылку, перевыпустить — модальное подтверждение с текстом последствий.

---

## 4.7 События экзаменов в расписании преподавателя и на «Главной»

**Зачем.** Сеанс экзамена виден преподавателю наравне с занятиями; клик ведёт в «Проведение экзамена» этого сеанса (SPEC §5).
Событие самостоятельное, не фиктивный `group_lesson`.

**Проверить перед началом**
- `inc/Services/Profile/DashboardService.php::build()` — ключи ответа `today`, `week`; `lessonItem()` — поля элемента занятия.
- `src/js/profile/dashboard.js` — как рисуются строки `today` и `week`, какой обработчик клика.
- `ExamSessionRepository` — нужен метод выборки сеансов по ответственному и диапазону дат.

**Шаги**
- [x] 4.7.1 `ExamSessionRepository::listForTeacherBetween( int $userId, bool $all, string $fromUtc, string $toUtc ): array` — сеансы (кроме `cancelled`)
  опубликованных проведений, где `responsible_user_id = $userId` (или все при `$all`), со статусом проведения и названием (JOIN с `exam_events`).
- [x] 4.7.2 `DashboardService::build()`: добавить ключ ответа `exams` — список на сегодня и неделю:
  `array{ kind:'exam', session_id, event_id, title, date, time_start, time_end, room, occupied, capacity, state }`.
  Времена — местные. `state` — тем же правилом, что у занятий (`stateOf()`). Право: только если у пользователя есть `ManageExams`;
  офис без права экзамены на «Главной» не видит. В существующие массивы `today` и `week` экзамены **не подмешивать** — счётчики занятий не меняются.
- [x] 4.7.3 `dashboard.js`: в блоках «Сегодня» и «Неделя» выводить элементы `exams` вместе с занятиями, отсортированными по времени.
  Строка экзамена — та же разметка строки занятия с пометкой «Экзамен» (существующий `prof-chip`); клик → `opts.openExamConduct( sessionId )`.
- [x] 4.7.4 `app.js`: в `SCREENS.dashboard` передать `openExamConduct: ( sid ) => { go( 'exam-conduct' ); openExamConductFor( sid ); }`.
  `openExamConductFor` экспортирует `exam-conduct.js`; пока экран — заглушка, функция только запоминает ID сеанса.
- [x] 4.7.5 «Расписание преподавателя» — это блоки «Сегодня» и «Неделя» на «Главной». В календаре КТП (он по группе) сеансы экзаменов
  не показываются; если владелец попросит — отдельная задача.

**Тесты**
- `tests/Unit/Services/Profile/DashboardServiceTest.php` — дописать: `test_exam_sessions_listed_for_responsible_teacher`,
  `test_exam_sessions_hidden_without_manage_exams`, `test_exam_times_are_local`,
  `test_lesson_counters_unchanged_by_exams`.
- `tests/Integration/Repositories/Exam/ExamSessionRepositoryTest.php`: `test_list_for_teacher_excludes_cancelled_and_draft_events`.

**Готово, когда:** тесты зелёные; на «Главной» преподавателя виден сеанс сегодняшнего дня, клик открывает экран «Проведение экзамена»
(пока заглушку) без ошибок в консоли.

---

## Проверка этапа (SPEC §16: 9, 21)

- [x] PHPUnit: конфликты кабинета в обе стороны, включая «занятие после экзамена» и «экзамен после занятия». — `RoomAvailabilityServiceTest`, `RoomAssignmentServiceTest`, `ExamRoomServiceTest`; стенд `room-race`
- [x] e2e (headless CDP): проведение на 4 и на 10 дней, через границу месяцев; на телефонной ширине сеанс добавляется без перетаскивания. — проведено; телефон 390 px — сеанс добавляется кнопкой «+ Сеанс»
- [x] КТП без регрессий после выделения `monthCells()`. — `tests/js/ktp-month-cells.test.mjs`, экран КТП в браузере
- [x] `npm run ci`, `npx gulp build` — зелёные. — по частям (2026-10-04): `eslint .` и `stylelint` без ошибок, `gulp styles:check` и `gulp build` успешны, PHPUnit в контейнере 2727 тестов без падений, `npm run test:js` 79 тестов; целиком `npm run ci` на Windows-хосте не идёт: `npm test` вызывает `vendor/bin/phpunit`, который хост не запускает
- [x] Список новых CSS-классов этапа записан в `../QA.md` (раздел «Новое»), если они появились. — `QA.md`, раздел «Новые CSS-классы…»
