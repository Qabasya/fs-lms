# Этап 5. «Мои экзамены» ученика и родителя: запись

Зависимости: этапы 1, 3, 4. Результат: ученик видит проведения своего предмета, записывается, переносит и отменяет запись;
родитель смотрит данные ребёнка без изменяющих действий. Сдача ещё не открыта: кнопка «Приступить» серая до этапа 6.

Перед началом прочитать `README.md`. SPEC: §2, §4, §5 целиком, «Выбор даты учеником — карусель».
Макет: `../student.png`, `../parent.png`, `../mobile-student.png`, `../mobile-parent.png`, `../QA.md`.

**Порядок:** 5.1 → 5.2 → 5.3 → 5.4 → 5.5 → 5.6.

## Общие правила этапа

- Сервер отдаёт для каждой карточки готовое **состояние** и **список разрешённых действий**. Клиент ничего не вычисляет по датам
  и не решает, какую кнопку показать.
- Родитель = тот же экран с `readOnly`. Запрет изменяющих действий проверяется **на сервере**, а не скрытием кнопок.
- Разметка — из существующих классов «Моих курсов»: `sc-tabs`, `sc-tab`, `prof-card sc-hero`, `sc-row`, `sc-pill`, `sc-notice`,
  `prof-child-bar`. Единственный новый элемент этапа — карточка сеанса в карусели (см. 5.3).
- Баллы, эталоны и решения на этом этапе не отдаются вовсе: результат появится на этапе 7.


## Статус (проверено 2026-10-04)

**Сделано и проверено:** 5.1–5.6. Юнит-тесты поимённо по спецификации — `LearnerExamsServiceTest` (44), `LearnerExamCallbacksTest` (25), `LearnerProfileViewTest`, `ProfileViewResolverTest`, `LearnerServiceTest`,
`tests/js/exam-slot-carousel.test.mjs`, `tests/js/exam-result.test.mjs`.
**Браузер (headless Chrome), ученик ОГЭ и ЕГЭ:** все состояния карточки, запись, смена сеанса, «Отмена выбора», отмена и повторная запись; предупреждение `lesson_overlap`;
перетаскивание карусели мышью не выбирает сеанс, обычный клик выбирает; строка экзамена в расписании «Главной» открывает карточку; ученик ОГЭ не видит проведение ЕГЭ.
**Родитель:** панель ребёнка и чип «Только просмотр», пояснение, ни одной кнопки записи/сдачи, в конфиге нет действий записи, прямой POST на `register_for_exam`/`change_exam_registration`/`cancel_exam_registration` → `X-ACCESS`, записей в базе не прибавилось.

**Что изменилось по сравнению с текстом этапа (код — правда):**
- Ответ `RegisterForExam`/`ChangeExamRegistration`/`CancelExamRegistration` — список карточек `LearnerExamsService::build()` **и** `warnings` операции (раньше предупреждение формировал сервис, но до клиента оно не доходило — найдено и исправлено, тест `test_register_passes_lesson_overlap_warning_to_the_client`).
- Кнопка подтверждения называется «Подтвердить запись» и в режиме смены, сворачивающая кнопка — «Отмена выбора» (по SPEC), а не «Подтвердить смену»/«Отмена».
- Клик по экзамену в расписании: `learner.js` получает `openExam` колбэком из `app.js` (`openLearnerExam()` из `learner-exams.js`), прямого импорта экрана в экран нет.
- Карусель живёт на общей оболочке `course-tabs.js` (`syncCourseTabs( …, { stepByCard } )`, перетаскивание мышью — у всех лент `.sc-tabs`); чистые функции `isDrag()`/`arrowStep()` покрыты JS-тестом.
- `resultCaption( result )` — в `exam-result.js` (не в `exam-common.js`).
- Найдено в браузере и исправлено: `#exNotice` с `hidden` оставался видимым пустым синим блоком (`.sc-notice { display: flex }` перебивал атрибут) — добавлено `.exam-notice[hidden] { display: none }`.

---

## 5.1 Пункт меню «Мои экзамены» и экран

**Зачем.** Отдельный пункт меню ученика и родителя, виден всегда; при отсутствии проведений — пустое состояние (SPEC §5).

**Проверить перед началом**
- `inc/Services/Profile/LearnerProfileView.php::build()` — `nav` и `screens`.
- `inc/Services/Profile/ProfileViewResolver.php::jsConfig()` — блок `learner` (`nonce`, `actions`) для ученика и родителя.
- `src/js/profile/learner.js`: `childBar()`, `wireChild()`, `isParent()`, переменная `childId`, `rerenderAll()`.
- `src/js/profile/app.js`: `SCREENS`, `TOPBAR`, `NAV_ICONS`.
- `inc/Callbacks/Profile/LearnerCallbacks.php` — образец: `Nonce::…->verify()`, `is_user_logged_in()`,
  `$ctx->resolveSubjectPersonId( $this->sanitizeInt( 'student_person_id' ) )`.

**Шаги**
- [x] 5.1.1 `LearnerProfileView::build()`: в `nav` после «Мои курсы» добавить `array( 'key' => 'learner-exams', 'label' => 'Мои экзамены' )`,
  в `screens` — `learner-exams`. Без условий: пункт виден всегда.
- [x] 5.1.2 `ProfileViewResolver::jsConfig()`: для ученика и родителя добавить блок
  `exams => array( 'nonce' => Nonce::ExamLearner->create(), 'actions' => array( 'getExams' => AjaxHook::GetLearnerExams->jsAction(), … ) )`.
  Действия записи (`register`, `change`, `cancel`) отдавать **только ученику**; у родителя их в конфиге нет.
- [x] 5.1.3 `inc/Callbacks/Exam/LearnerExamCallbacks.php` (`extends BaseController`, `use Sanitizer;`), зарегистрировать в `ExamController::ajaxActions()`.
  Первый экшен — `GetLearnerExams` (`student_person_id?`): nonce `ExamLearner`, вход обязателен, личность — через
  `ProfileViewResolver::context()` и `resolveSubjectPersonId()` (ученик получает себя, родитель — только своего ребёнка).
  Ответ — `LearnerExamsService::build( $personId )` (5.2).
- [x] 5.1.4 `src/js/profile/exams/learner-exams.js`: `export function renderLearnerExams( root )`. Загрузка —
  `createApi( window.fsProfile.exams )( 'getExams', childId ? { student_person_id: childId } : {} )`.
  Переключатель ребёнка: вынести `childBar()`, `wireChild()`, `isParent()` и хранение `childId` из `learner.js` в
  `src/js/profile/learner-child.js` и использовать в обоих файлах (сейчас они приватные; копировать нельзя). Смена ребёнка перерисовывает
  и экраны `learner-*`, и «Мои экзамены».
- [x] 5.1.5 `app.js`: `SCREENS['learner-exams'] = renderLearnerExams`, `TOPBAR['learner-exams'] = { crumb: 'Обучение', title: 'Мои экзамены' }`,
  иконка в `NAV_ICONS`.
- [x] 5.1.6 Пустое состояние (нет проведений): карточка «Экзаменов пока нет. Когда преподаватель назначит экзамен, он появится здесь.»

**Тесты**
- `tests/Unit/Services/Profile/LearnerProfileViewTest.php` (создать, если нет): `test_exams_item_always_present_for_student_and_parent`.
- `tests/Unit/Services/Profile/ProfileViewResolverTest.php` — дописать: `test_parent_config_has_no_mutating_exam_actions`,
  `test_student_config_has_register_change_cancel`.
- `tests/Unit/Callbacks/Exam/LearnerExamCallbacksTest.php`: `test_get_exams_requires_login`,
  `test_student_param_ignored_for_student`, `test_parent_gets_only_own_child`.

**Готово, когда:** тесты зелёные; у ученика и родителя на dev пункт «Мои экзамены» есть и при отсутствии проведений показывает пустое состояние.

---

## 5.2 Плитки и карточка: состояния

**Зачем.** Лента плиток проведений и карточка выбранного экзамена со всеми состояниями SPEC §5.

**Проверить перед началом**
- `learner.js`: `scRenderTabs()`, `scRenderHero()` — разметка плитки и карточки курса.
- `src/js/profile/course-tabs.js`: `courseTabsShell( id )`, `syncCourseTabs( tabs )`.
- `ExamAudienceResolver::subjectKeysForStudent()` (1.1.4), репозитории проведений, сеансов, участий, записей.
- Формат проведения — `ExamFormatRegistry::for( kind основного варианта )`.

**Шаги**
- [x] 5.2.1 `inc/Services/Exam/LearnerExamsService.php`. Зависимости: `ExamAudienceResolver`, репозитории проведений, сеансов, участников, участий,
  записей; `ExamFormatRegistry`, `AssessmentManager`, `RoomRepository`, `ExamTime`.
  Метод `build( int $personId ): array` — список карточек проведений:
  - проведения в статусах `published`, `completed`, `cancelled` по предметам `subjectKeysForStudent()`;
  - **плюс** проведения, где у ученика уже есть участие (историческая запись не исчезает при смене группы, SPEC §2);
  - черновики не показываются никогда.
- [x] 5.2.2 Состояние карточки — приватный метод `resolveState(...)`, одна ветка на строку:
  | `state` | Условие | `actions` |
  |---|---|---|
  | `event_cancelled` | проведение `cancelled` | — |
  | `not_open` | участия или записи нет, `now < registration_opens_at` | — |
  | `open` | записи нет, запись открыта, есть сеанс со свободным местом | `register` |
  | `full` | записи нет, запись открыта, мест нет ни в одном будущем сеансе | — |
  | `closed` | записи нет, `now >= registration_closes_at` или будущих сеансов нет | — |
  | `registered` | запись есть, `now < scheduled_at` | `change`, `cancel` (если окно записи открыто) |
  | `entry_open` | запись есть, `scheduled_at <= now < planned_end_at`, попытки нет | `start` (включается на этапе 6) |
  | `in_progress` | попытка в процессе | `resume` (этап 6) |
  | `awaiting_approval` | попытка сдана, не утверждена | — |
  | `approved` | попытка утверждена | `results` (этап 7) |
  | `cancelled_by_staff` | последняя запись отменена сотрудником, действующей нет | `register`, если запись открыта и есть места |
  | `missed` | последняя запись `missed`, действующей нет | `register`, если запись открыта и есть места |
  Состояния `in_progress`, `awaiting_approval`, `approved` на этом этапе недостижимы (попыток нет), но ветки и тесты пишутся сейчас:
  читать `current_attempt_id` и статус попытки через `AssessmentAttemptRepository::find()`.
- [x] 5.2.3 Поля карточки: `event_id`, `title`, `description`, `subject_key`, `direction` (`ege` / `oge`), `state`, `state_label`, `actions`,
  `period_from`, `period_to`, `registration_opens_at`, `registration_closes_at`, `registration` (если есть: `registration_id`, `session_id`,
  `date`, `weekday`, `time_start`, `time_end`, `room`), `last_reason` (причина отмены сотрудником или проведения), `previous_date`
  (дата пропущенного сеанса), `sessions` (см. 5.3), `teacher_name` (владелец проведения — для сообщения «обратитесь к преподавателю»).
  Все времена — местные. **Никаких баллов, эталонов, решений, списка заданий.**
- [x] 5.2.4 `learner-exams.js`: плитки — `courseTabsShell( 'exTabs' )` и разметка `sc-tab`: чип предмета, название, подпись.
  Подпись плитки (для несданных — состояние и дата):
  | `state` | Подпись |
  |---|---|
  | `not_open` | «Запись с {дата}» |
  | `open` | «Запись открыта» |
  | `full` | «Свободных мест нет» |
  | `closed` | «Запись закрыта» |
  | `registered`, `entry_open` | «{дата}, {время}» |
  | `in_progress` | «Выполняется» |
  | `awaiting_approval` | «Ожидает утверждения» |
  | `missed` | «Экзамен пропущен» |
  | `cancelled_by_staff` | «Запись отменена» |
  | `event_cancelled` | «Проведение отменено» |
  Подпись с итогом для `approved` — этап 7.4.
- [x] 5.2.5 Карточка — `prof-card sc-hero` (разметка как у `scRenderHero()`): название, строка даты/времени/места, справа блок действий.
  Действие и пояснение по состояниям:
  | `state` | Кнопка | Пояснение (`sc-hint` / `sc-notice`) |
  |---|---|---|
  | `not_open` | «Записаться» (неактивна, `sc-dis`) | «Запись откроется {дата и время}» |
  | `open` | «Записаться» | — |
  | `full` | — | «Свободных мест нет. Обратитесь к преподавателю: {ФИО}.» Без листа ожидания |
  | `closed` | — | «Запись закрыта. Обратитесь к преподавателю: {ФИО}.» |
  | `registered` | «Приступить» (серая, `sc-dis`) + «Сменить сеанс», «Отменить запись» | «Кнопка станет активной в {время начала}. Смена и отмена записи — до начала выбранного сеанса.» |
  | `entry_open` | «Приступить» (синяя; до этапа 6 — серая) | «Начать можно до {плановый конец}» |
  | `in_progress` | «Продолжить» | «Завершение в {личный дедлайн}» |
  | `awaiting_approval` | — | «Работа сдана и ожидает утверждения преподавателем.» |
  | `cancelled_by_staff` | «Записаться», если доступно | «Запись отменена. Причина: {причина}» |
  | `missed` | «Записаться», если доступно | «Экзамен пропущен, запись на {дата} аннулирована.» |
  | `event_cancelled` | — | «Проведение отменено. Причина: {причина}» |
  Время в текстах — только подстановкой из ответа сервера.
- [x] 5.2.6 Отступы между `prof-card` и плитками на экране — токеном в `_exams.scss` (пункт 7 «Нового» в `../QA.md`).
  Жёлтая пилюля статуса (пункт 4 `QA.md`) — модификатор существующего `prof-state-pill`, цвет токеном.

**Тесты** — `tests/Unit/Services/Exam/LearnerExamsServiceTest.php`, по тесту на состояние:
`test_state_not_open`, `test_state_open`, `test_state_full_when_all_future_sessions_full`, `test_state_closed`,
`test_state_registered_with_change_and_cancel`, `test_state_entry_open_at_scheduled_time`,
`test_state_in_progress`, `test_state_awaiting_approval_has_no_scores`, `test_state_approved`,
`test_state_cancelled_by_staff_shows_reason_and_allows_new_registration`, `test_state_missed`,
`test_state_event_cancelled`; плюс `test_student_sees_only_events_of_own_subjects`,
`test_oge_student_does_not_see_ege_event`, `test_draft_event_is_hidden`,
`test_event_with_existing_participation_stays_visible_after_leaving_group`,
`test_payload_contains_no_scores_or_answers` — в JSON нет ключей `score`, `total_score`, `correct`, `tasks`.

**Готово, когда:** тесты зелёные; на dev карточка показывает состояние `open` для опубликованного проведения `inf_ege` у ученика группы `КЕГЭ-1`.

---

## 5.3 Запись: карусель сеансов, подтверждение, перенос, отмена

**Зачем.** «Записаться» раскрывает сеансы; выбор плитки ничего не бронирует; запись создаётся кнопкой «Подтвердить запись».
При гонке за последнее место — понятный отказ и обновлённые плитки (SPEC §4, §5, «карусель»).

**Проверить перед началом**
- `ExamRegistrationService::register()`, `change()`, `cancelBySelf()` (этап 3).
- README §7.3 — коды `X-FULL`, `X-HELD`, `X-CLOSED`, `X-CONFLICT`.
- `src/js/profile/api.js::request()` — при ошибке бросает `Error` только с текстом; код ошибки (`json.data.code`) теряется.
- Карусель «Моих курсов»: `course-tabs.js` (стрелки, скрытие стрелок на границах, прокрутка).

**Шаги**
- [x] 5.3.1 `api.js`: при ошибке сохранять код — `const err = new Error( … ); err.code = json?.data?.code || ''; err.ref = json?.data?.ref || ''; throw err;`.
  Существующие вызовы читают только `e.message` и не ломаются.
- [x] 5.3.2 `LearnerExamCallbacks` — три экшена (nonce `ExamLearner`):
  | `AjaxHook` | Параметры |
  |---|---|
  | `RegisterForExam` | `session_id`, `request_key` |
  | `ChangeExamRegistration` | `session_id` (новый), `request_key` |
  | `CancelExamRegistration` | `event_id`, `request_key` |
  В каждом: вход обязателен; `$ctx = resolver->context( userId )`; **если `$ctx->readOnly` — `$this->fail( ErrorCode::ExamAccess, 'Запись доступна только самому ученику.' )`
  и выход**; личность — `$ctx->personId` (клиентский `student_person_id` не читать вовсе). `CodedException` → `fail()` с кодом.
  Успех — заново собранный список карточек (`LearnerExamsService::build()`) плюс `warnings` операции (`lesson_overlap`), чтобы клиент перерисовал экран одним ответом.
- [x] 5.3.3 `LearnerExamsService`: поле `sessions` карточки — будущие сеансы проведения (не `cancelled`):
  `session_id`, `date`, `weekday`, `time_start`, `time_end`, `room`, `free` (свободных мест), `capacity`, `selectable` (bool: есть место и сеанс в будущем),
  `is_current` (сеанс действующей записи). Заполненные сеансы остаются в списке с `selectable = false`.
- [x] 5.3.4 Карусель сеансов. Обёртка и стрелки — `courseTabsShell()` / `syncCourseTabs()` (без копии логики). Карточка сеанса — **новый элемент**
  (`../QA.md`, «Новое» п. 1): класс `exam-slot` в `_exams.scss`, крупно дата, день недели, время, кабинет, «осталось N мест»
  (склонение — `plural()` из `utils.js`). На компьютере видны три карточки и край четвёртой, на телефоне — одна и край следующей.
  Листание стрелками по одной карточке, перетаскивание мышью и касанием с доводкой; **перетаскивание не выбирает сеанс** (клик после сдвига
  больше нескольких пикселей игнорируется). Если все карточки помещаются — стрелок нет. Выбор доступен с клавиатуры (`button`, `aria-pressed`).
- [x] 5.3.5 Поведение:
  - «Записаться» раскрывает карусель под карточкой и кнопку «Подтвердить запись» (неактивна, пока сеанс не выбран);
  - клик по карточке сеанса только отмечает выбор; запрос не отправляется;
  - «Подтвердить запись» → `register` с `request_key` (генерируется один раз при раскрытии карусели: `crypto.randomUUID()`;
    повторное нажатие шлёт тот же ключ). Кнопка блокируется на время запроса;
  - «Сменить сеанс» раскрывает ту же карусель с отмеченным текущим сеансом; появляются «Подтвердить запись» и **«Отмена выбора»**,
    которая сворачивает карусель и оставляет действующую запись; подтверждение — `confirmDialog( 'Сменить запись на {дата, время}?' )`, затем `change`;
  - «Отменить запись» — `confirmDialog( 'Отменить запись на экзамен? Место освободится.' )`, затем `cancel`.
- [x] 5.3.6 Ошибки по коду:
  | Код | Что делает клиент |
  |---|---|
  | `X-FULL` | перезагрузить список (`getExams`), сбросить выбор, показать у карусели «Это место только что заняли. Выберите другой сеанс.» Запись не создана |
  | `X-HELD` | то же, текст с сервера («…часть мест удерживается до оплаты. Попробуйте позже.») |
  | `X-CLOSED` | перезагрузить карточку (состояние станет `closed`), показать текст сервера |
  | `X-CONFLICT` | показать текст сервера, выбор сохранить |
  | иное | текст сервера и код `ref` для скриншота |
- [x] 5.3.7 Предупреждение `lesson_overlap` из ответа (`warnings`) — `sc-notice` под карточкой: «В это время у вас занятие по расписанию.» Запись при этом создана.

**Тесты**
- `tests/Unit/Callbacks/Exam/LearnerExamCallbacksTest.php`:
  `test_register_delegates_with_person_of_current_user`, `test_register_ignores_client_person_id`,
  `test_register_returns_x_full_code`, `test_change_and_cancel_delegate`,
  `test_parent_cannot_register`, `test_parent_cannot_change`, `test_parent_cannot_cancel` — у каждого `code = X-ACCESS` и сервис записи **не вызван**;
  `test_guest_user_denied`.
- `LearnerExamsServiceTest.php`: `test_sessions_list_marks_full_sessions_not_selectable`, `test_sessions_exclude_cancelled_and_past`.
- `tests/js/exam-slot-carousel.test.mjs` — чистые функции `isDrag( dx )` и `arrowStep()` из `course-tabs.js` (общая оболочка вкладок и карусели; перетаскивание мышью работает и у вкладок курсов).

**Готово, когда:** тесты зелёные; вручную: запись, смена сеанса, «Отмена выбора», отмена записи; в двух браузерах два ученика на последнее место —
один записан, второй видит обновлённые плитки и сообщение.

---

## 5.4 Родитель: только просмотр, запрет на сервере

**Зачем.** Родитель видит проведения и запись ребёнка, но не записывает, не отменяет и не начинает экзамен — в том числе прямым запросом (SPEC §2, критерий 10).

**Проверить перед началом:** 5.1–5.3 выполнены. Запреты в коллбеках уже стоят (5.3.2).

**Шаги**
- [x] 5.4.1 `LearnerExamsService::build()` получает второй параметр `bool $readOnly`. При `true` в каждой карточке `actions = array()`,
  а `state` остаётся прежним. Коллбек передаёт `$ctx->readOnly`.
- [x] 5.4.2 `learner-exams.js`: при `isParent()` показывать `prof-child-bar` с чипом «Только просмотр» (уже есть в `childBar()`), кнопки действий
  не рисовать (их и так нет в `actions`), под карточкой — `sc-notice`: «Записывается и сдаёт экзамен сам ученик из своего кабинета.»
- [x] 5.4.3 Родитель с двумя детьми: переключатель ребёнка перезагружает «Мои экзамены» для выбранного ребёнка; чужой `student_person_id`
  в запросе заменяется ребёнком по умолчанию (`resolveSubjectPersonId()`), ошибок и чужих данных нет.
- [x] 5.4.4 Прямой POST от имени родителя на `register_for_exam`, `change_exam_registration`, `cancel_exam_registration` отклоняется
  (проверка стоит до чтения параметров запроса).

**Тесты**
- `LearnerExamsServiceTest.php`: `test_read_only_cards_have_no_actions_but_keep_state`.
- `LearnerExamCallbacksTest.php`: `test_parent_request_for_foreign_child_returns_own_child` (не чужие данные и не ошибка с чужим ID).
- e2e (headless CDP, вход родителем): в ответе `get_learner_exams` у всех карточек `actions = []`; прямой `fetch` на `register_for_exam`
  возвращает `success: false`, `code: 'X-ACCESS'`; в базе запись не появилась
  (`SELECT COUNT(*) FROM wp_fs_lms_exam_registrations` до и после).

**Готово, когда:** тесты и e2e пройдены.

---

## 5.5 События экзамена в расписании ученика и родителя

**Зачем.** Экзамен виден в расписании как самостоятельное событие; клик ученика ведёт к карточке со стартом, у родителя — к просмотру (SPEC §5).

**Проверить перед началом**
- `inc/Services/Profile/Learner/LearnerScheduleSection.php::upcoming()` — формирует `upcoming` из `allLessons`.
- `inc/Services/Profile/LearnerService.php::build()` — `upcoming` обрезается до 6 элементов.
- `learner.js::schedRow()` — строка расписания; ссылка только при `player_url`.
- `inc/DTO/Profile/LearnerDashboardDTO.php` — поля и `toArray()`.

**Шаги**
- [x] 5.5.1 `LearnerExamsService::upcomingEvents( int $personId ): array` — действующие записи ученика на будущие (и идущие сейчас) сеансы:
  `array{ kind:'exam', event_id, title, date, start, end, room, state }`, времена местные.
- [x] 5.5.2 `LearnerService::build()`: получить события экзаменов, слить с `upcoming` занятий, отсортировать по дате и времени, затем обрезать до 6.
  У элементов экзамена обязательны поля, которые читает `schedRow()`: `date`, `start`, `group_name` (сюда — «Экзамен»), `topic` (название проведения),
  `room_name`, `kind = 'exam'`, `event_id`.
- [x] 5.5.3 `learner.js::schedRow()`: для `l.kind === 'exam'` строка-кнопка с `data-exam-event="${l.event_id}"` и пометкой «Экзамен»
  (существующий `prof-sub-tag`); обработчик клика — `go( 'learner-exams' )` и выбор плитки этого проведения.
  Для перехода `learner-exams.js` экспортирует `openLearnerExam( eventId )`; `learner.js` получает его через колбэк из `app.js`
  (как `summaryLink()`), а не прямым импортом экрана в экран.
- [x] 5.5.4 Родитель: тот же клик открывает карточку ребёнка без кнопок запуска.
- [x] 5.5.5 После старта попытки (этап 6) строка показывает ещё и личный дедлайн («до {время}») — поле `deadline` добавить в 5.5.1 сейчас (`null`, пока попытки нет).

**Тесты**
- `LearnerExamsServiceTest.php`: `test_upcoming_events_include_only_active_registrations`, `test_upcoming_events_are_local_time`.
- `tests/Unit/Services/Profile/LearnerServiceTest.php` (если есть — дописать): `test_exam_event_is_merged_into_upcoming_in_time_order`,
  `test_upcoming_still_limited_to_six`.

**Готово, когда:** тесты зелёные; на «Главной» ученика с записью виден экзамен, клик открывает «Мои экзамены» на нужной карточке.

---

## 5.6 ОГЭ: те же экраны и правила

**Зачем.** Всё, что сделано для ЕГЭ, обязано работать для проведений ОГЭ; отличается только представление итога (SPEC §5 «Баллы ОГЭ»).

**Проверить перед началом**
- На dev есть группа `КОГЭ-1` предмета `inf_oge`. Нужен опубликованный вариант вида «Компьютерный ОГЭ» этого предмета
  (`ExamVariantPolicy::listForSubject( 'inf_oge' )` не пуст). Если вариантов нет — попросить владельца указать работу для проверки.
- `ExamFormatDTO` ОГЭ: `secondaryMax = null`, `gradeMax = 5`, `primaryMax = 21`, длительность 150 минут.

**Шаги**
- [x] 5.6.1 Пройти 4.2–4.5 и 5.1–5.5 на проведении ОГЭ: создать, опубликовать, записаться учеником группы `КОГЭ-1`, сменить сеанс, отменить.
  Каждое расхождение с ЕГЭ записать и исправить в общем коде, без ветки `if ( oge )` в интерфейсе.
- [x] 5.6.2 Плановый конец сеанса ОГЭ = начало + 150 минут (берётся из формата, см. 2.4.6) — проверить в форме сеанса и в карточке.
- [x] 5.6.3 В карточку (5.2.3) добавить блок `format`: `direction`, `unit_count`, `primary_max`, `secondary_max`, `grade_max`, `duration_minutes`.
  Клиент использует его для подписей («{N} заданий», «{часы и минуты}») — числа только подстановкой.
- [x] 5.6.4 Подпись итога на плитке (значения появились на этапе 7.4; функция живёт в `exam-result.js`, а не в `exam-common.js`):
  `resultCaption( result )` (направление и максимумы — внутри `result`) → для ЕГЭ «{вторичный} из {secondary_max}», для ОГЭ «{первичный} из {primary_max}, отметка {отметка}».
  Формулировка «из 100» для ОГЭ не используется.
- [x] 5.6.5 Ученик ОГЭ не видит проведения ЕГЭ и наоборот (проверка аудитории — 1.1; здесь e2e).

**Тесты**
- `LearnerExamsServiceTest.php`: `test_oge_card_has_grade_format_and_no_secondary_max`.
- `tests/js/exam-result.test.mjs`: `resultCaption` для ЕГЭ и ОГЭ (отдельного файла `exam-result-caption.test.mjs` нет).
- e2e: ученик `inf_ege` видит только проведения `inf_ege`, ученик `inf_oge` — только свои.

**Готово, когда:** тесты и e2e пройдены; сценарий 5.6.1 на ОГЭ проходит без правок интерфейса под направление.

---

## Проверка этапа (SPEC §16: 10, 22)

- [x] PHPUnit на коллбеки (запись, отмена, родитель) и на все состояния карточки. — `LearnerExamCallbacksTest`, `LearnerExamsServiceTest`
- [x] e2e: ученик видит только проведения своего предмета; двое на последнее место — один записан; родитель не может записать ни через интерфейс, ни прямым запросом. — проведено для ОГЭ и ЕГЭ; последнее место — стенд `last-seat`
- [x] Все состояния SPEC §5 (кроме зависящих от попытки) показаны на dev и имеют правильные действия. — по тестам на каждое состояние и в браузере (open, registered, entry_open, in_progress, awaiting_approval, approved)
- [x] `npm run ci`, `npx gulp build` — зелёные. Новые элементы занесены в `../QA.md`. — по частям (2026-10-04): `eslint .` и `stylelint` без ошибок, `gulp styles:check` и `gulp build` успешны, PHPUnit в контейнере 2727 тестов без падений, `npm run test:js` 79 тестов; целиком `npm run ci` на Windows-хосте не идёт: `npm test` вызывает `vendor/bin/phpunit`, который хост не запускает

**Конец M1.** Выпускать запись без сдачи можно, только если все назначенные сеансы стоят после выпуска M2:
иначе ученик записан, а кнопка «Приступить» не работает.
