# Задачи: Личный кабинет преподавателя

> Декомпозиция работ по реализации ЛК преподавателя на основе [`Courses.md`](./Courses.md).
> Учитывает уже реализованный фундамент (Этап 2 «программа группы») и **SPA-оболочку профиля `/profile/`**, собранную из дизайн-хэндоффа `lms-front/project/Teacher Cabinet.html` и встроенную в ядро (per-role).
>
> **Легенда статуса:** ✅ готово · 🟡 частично (есть данные/логика, нет UI/связки) · 🔴 не начато · 🔶 развилка (нужно решение).
> **Слой:** `Lms` — домен (миграции, сервисы, AJAX) · `Profile` — личный кабинет `/profile/`, **часть ядра** (не отключаемый): SPA-оболочка + per-role витрины + презентация. Глубокие экраны (журнал/КТП/проверка) живут в SPA профиля; доменные операции — через AJAX-хуки `Lms`. Кокпит `/group/` упраздняется.

---

## A. Текущее состояние

### A.1. Фундамент (готов, переиспользуем — НЕ переписывать)

| Кусок | Где | Статус |
|---|---|---|
| Группы + `course_id` | `fs_lms_groups`, `CourseAssignmentService` (запись), `ContentUsageService` (чтение) | ✅ |
| Учебные периоды (start/end/holidays/is_current) | `fs_lms_academic_periods` | ✅ |
| Членство учеников | `fs_lms_student_records`, `StudentRecordRepository` (`findActiveByGroupId`, `findActiveByParent`, …) | ✅ |
| Люди / PII | `fs_lms_persons` + `fs_lms_person_documents` (libsodium) | ✅ |
| Программа группы («занятия») | `fs_lms_group_lessons`, `GroupLessonDTO`, `GroupLessonRepository` | ✅ |
| Прогресс по шагам | `fs_lms_lesson_progress`, `LessonProgressService::getStepStatuses()` | ✅ |
| Сдачи работ | `fs_lms_submissions`, `SubmissionRepository::listQueueByGroup()` / `listForGradebookBy*()` | ✅ |
| Проверка + оценка | `SubmissionService::grade()/gradeBatchTask()/returnForRework()`; хуки `SaveGrade`, `GradeBatchTask`, `ReturnSubmission` | ✅ |
| Контрольные/ЕГЭ | `fs_lms_assessment_attempts` + `_answers`, `ExamResultService::buildForStudent()` | ✅ |
| Интерактивные задачи | `fs_lms_task_attempts` | ✅ |
| Сводка результатов | `GradebookService::forGroup()/forStudent()`, `GradebookEntryDTO` (score/maxScore/fraction — **сырые баллы, не 5-балльные оценки**), `GradeSourceRegistry` | 🟡 (конкатенация; среднего нет и не нужно — см. D4) |
| Календарь занятий | `SessionCalendarService::generate()/reflow()`, `ScheduleService::reflow()/pin()`, `MeetingsNormalizer` | 🟡 (есть сервисы, **нет AJAX/UI**) |
| Кокпит группы `/group/?gid=N` | `GroupCockpitController` — программа (assign course, add/remove/reorder/duplicate lesson, schedule, visibility, extra works), ростер, лента событий, очередь проверки + дневник | ✅ |
| Доступ к группе | `GroupAccessGuard::canManage()/isMemberEver()/isParentOf()` | 🟡 (плоская проверка `teacher_id`+admin, без time-bound/замен) |

**Готовые хуки программы:** `AssignCourse`, `AddLessonToProgram`, `RemoveLessonFromProgram`, `ReorderProgram`, `DuplicateProgramLesson`, `SaveLessonSchedule`, `SetLessonExtraWorks`, `SetLessonVisibility`, `GetGroupProgram`, `GetGroupActivity`.
**Готовые хуки проверки/дневника:** `SaveGrade`, `GradeBatchTask`, `ReturnSubmission`, `GetGroupSubmissions`, `GetGradebook`.

### A.2. Профиль `/profile/` — SPA в ядре + per-role резолвер (собрано, данные демо)

> Решения D1/D2 (см. §B) уже реализованы: кабинет — **часть ядра**, маршрут — **`/profile/`**, имя — **«профиль»**, состав — **per-role** через резолвер. Модуль `TeacherCabinet` и маршрут `/cabinet/` удалены.

| Кусок | Где | Статус |
|---|---|---|
| Маршрут + рендер | `ProfileController` (ядро): `template_redirect` (гейты) + `template_include` (полный SPA на `/profile/`); офисные роли → редирект в админку | ✅ |
| Per-role резолвер | `ProfileViewResolver` → `ProfileContext` (роль, `subjectPersonId`, `readOnly`, дети) + `window.fsProfile` (локализация в `Enqueue`) | ✅ скелет |
| Витрины (паттерн «2 формы / 3 роли») | `ProfileViewInterface`; `TeacherProfileView` (Главная/Журнал/КТП); `LearnerProfileView` — ученик **и** родитель (родитель = данные ребёнка + read-only) | ✅ скелет |
| Шаблон-холст | `templates/frontend/profile.php` (статичный каркас; сайдбар/сцену наполняет JS из `fsProfile`) | ✅ |
| Ассеты | `src/scss/profile/*` → `profile.min.css`; `src/js/profile/*` → `profile.min.js`; gulp-задача `styles:profile` | ✅ |
| Экраны препода | `dashboard.js` (стат-плитки, расписание, ворклист, группы), `journal.js` (сетка, поповеры, 5 вариантов), `ktp.js` (банк тем, календарь, drag-drop) | 🟡 **демо-данные** |
| Экраны учащегося | `learner.js` — заглушки (Главная/Курсы/Оценки/Посещаемость) | 🔴 заглушки |

> Экраны препода работают на захардкоженных демо-данных (`src/js/profile/data.js`). ⚠️ Демо-журнал рисует 5-балльные оценки + «средний балл» — **это не наша модель** (см. D4): нужны +/− и сырые баллы. Доменная часть ниже — **замена демо-слоя реальными данными `Lms`** + новые куски (посещаемость, замены, индивидуальные занятия) + наполнение экранов учащегося (Эпик 7).

### A.3. Доменные пробелы (полностью отсутствуют)

`fs_lms_attendance` (бинарная +/−) 🔴 · колонки-эволюция `group_lessons` (`status`, `teacher_id_override`, `kind`, `student_person_id`) 🔴 · `fs_lms_substitutions` + `EffectiveTeacherResolver` + `Capability::ManageSchedule` 🔴 · индивидуальные занятия 🔴 · вывод `reflow` в AJAX 🔴 · time-bound доступ в `GroupAccessGuard` 🔴.

---

## B. Решения до старта (развилки)

> D1/D2 — **зафиксированы и реализованы**. D3–D7 — **зафиксированы** (2026-06-30), реализуются в соответствующих эпиках.

- **D1 — Граница UI и отключаемость. ✅ РЕШЕНО.** Кабинеты **не отключаемы** — это ядро. Кокпит `/group/` **упраздняется**; всё под рукой в одном месте — SPA профиля. Глубокие экраны (журнал/КТП/проверка) живут в SPA; доменные операции идут через AJAX-хуки/сервисы `Lms` (чистая слоистость: контроллеры тонкие, логика в сервисах — не ради отключаемости). Инвариант §4.1 «выключили Cabinet» **снят** (отменяет прежнюю формулировку §6 спеки).
- **D2 — Имя, маршрут, охват. ✅ РЕШЕНО.** Имя — **«профиль»**, маршрут — **`/profile/`** (модуль `TeacherCabinet` и `/cabinet/` удалены, логика — в ядровом `ProfileController`). Паттерн — **per-role витрина, 2 формы на 3 роли**: `TeacherProfileView` (инструменты препода) и `LearnerProfileView` (ученик **и** родитель). Роль выбирает форму; контекст (`ProfileContext`) — охват данных и режим: ученик пишет свои данные, **родитель = тот же экран + данные ребёнка + read-only** (замок — на сервере: нет прав записи + не владелец). Офисные роли (`FSOffice/FSMethodist/FSMarket`) фронт-кабинета не имеют → редирект в админку.
- **D3 — «Занятие». ✅ РЕШЕНО.** Эволюционируем `group_lessons` (не отдельная `session_instance`). **Отработок нет** — всё, что не групповое занятие, это `individual`. Колонки: `status`, `teacher_id_override`, `kind ENUM(group, individual)`, `student_person_id`. **Без `makeup`/`makeup_of_id`.** Индивидуальные не входят в программу группы (`position`) и в `reflow` (привязаны к дате, не к последовательности) → `reflow`/`GetGroupProgram` фильтруют `kind='group'`.
- **D4 — Посещаемость и оценивание. ✅ РЕШЕНО.** Посещаемость **бинарная: присутствовал (+) / отсутствовал (−)** — без late/excused, без баллов и весов. **Оценок (5-балльных) и среднего балла НЕТ.** В журнале — сырые величины: **количество решённых задач** (`task_attempts`) и **баллы за экзамен** (`assessment_attempts`). Расходится с дизайн-моком (там 5-балльные оценки + «средний балл») — у нас не так.
- **D5 — Замена. ✅ РЕШЕНО.** override (разовая, `teacher_id_override`) + grant на период (`substitutions`); резолв на чтении (`EffectiveTeacherResolver`: override › активная замена › `groups.teacher_id`). `groups.teacher_id` не перезаписывать. `GroupAccessGuard::canManage` пускает при активном grant, гаснет по `valid_to`. Назначает `FSOffice` **в админке** (не в `/profile/`); профиль препода лишь отображает «замена до [дата]». Capability — **новая `ManageSchedule` (только офис)**: `ManageLmsTeaching` не годится (её имеет и `FSTeacher`).
- **D6 — Источник тем КТП. ✅ РЕШЕНО.** КТП группы = уроки назначенного курса по порядку (`course_id` → `CourseDTO::lessonIds()`), не «все курсы предмета». **Один курс на группу** (скалярный `course_id`).
- **D7 — lock КТП (§4.2), рубрики (§4.4). ✅ РЕШЕНО: позже (post-v1).** Взвешивание оценок (§3.5) **снято с повестки** — оценок/среднего нет (см. D4), взвешивать нечего.

---

## C. Эпик 1 — Курс↔группа + КТП (вывести `reflow` в AJAX и связать с UI)

> Движок (`SessionCalendarService`) и связка `course_id` есть. Нужны AJAX-обёртки и замена клиентского демо-reflow в `ktp.js` реальными данными.

| ID | Задача | Слой | Статус | Зависит | Затрагивает |
|---|---|---|---|---|---|
| T1.1 | Хук `AjaxHook::ReflowSchedule` → `ProgramCallbacks::ajaxReflowSchedule` → `ScheduleService::reflow(groupId, actorUserId)` (nonce `SaveSchedule` + cap `ManageLmsTeaching` + `canManage`) | Lms | ✅ | — | `AjaxHook`, `ScheduleController`, `ProgramCallbacks` |
| T1.2 | Хук `AjaxHook::PinLesson` → `ScheduleService::pinToDate()` (set `scheduled_at` + `is_pinned` + reflow остального вокруг пина) | Lms | ✅ | — | `ProgramCallbacks`, `ScheduleService::pinToDate()` |
| T1.3 | Перенос даты — покрыт существующим `SaveLessonSchedule` → `ScheduleService::schedule()`; отдельный `MoveLesson` не нужен | Lms | ✅ | — | — |
| T1.4 | Хук `AjaxHook::GetGroupCalendar` → `ScheduleService::getCalendar()` (период, выходные, lessonDays, темы с размещением) + `SessionCalendarService::periodMeta()` | Lms | ✅ | — | `ProgramCallbacks`, `ScheduleService`, `SessionCalendarService` |
| T1.5 | КТП группы = уроки назначенного курса; `getCalendar` отдаёт `assigned` (по `groups.course_id`) для пустого состояния. Наполнение программы из курса — существующий `assign`/`AssignCourse` | Lms | ✅ | D6 | `CourseAssignmentService`, `getCalendar` |
| T1.6 | `ktp.js` переписан на реальные данные: `get_group_calendar` → банк + календарь (мульти-месяц периода); drag → `pin_lesson`; «Распределить» → `reflow_schedule`; демо-`reflow` убран. `fsProfile` несёт nonce `SaveSchedule` + действия + группы препода (`ProfileViewResolver`) | Profile | ✅ | T1.1–T1.5 | `src/js/profile/ktp.js`, `ProfileViewResolver`, `GroupsRepository::findByTeacherId` |
| T1.7 | Переключение группы в КТП (пикер из `fsProfile.groups`) — готово. Назначение курса из КТП — **отложено** (нет endpoint списка курсов; пустое состояние ведёт в админку) | Profile | 🟡 | T1.6 | `ktp.js` |
| T1.8 | (опц. 🔶 D7) Lock КТП после публикации | Lms | 🔴 | — | флаг на `group_lessons`/группе |

> **Эпик 1 готов (кроме T1.7-курс/T1.8).** Бэкенд (T1.1–T1.5): 3 хука зарегистрированы + проверены (`has_action`). Фронт (T1.6): `ktp.js` AJAX-driven, бандл собран без ошибок. `fsProfile` для препода #54 отдаёт группы + nonce + действия (проверено). Сид-данные: группа #1 «Тест-группа 9А» (предмет `test`, курс 16628, 6 тем разложены `reflow` по периоду 2026-03-01…07-12), препод **demoteacher / teacher123** (#54, чистый `lms_teacher`).
> ⚠️ Мульти-ролевой юзер (teacher+methodist) резолвится как staff (`primary()` → methodist) → редирект в админку, а не в кабинет препода. Edge для RBAC: возможно, фронт-кабинет должен предпочитать роль препода. См. [[rbac-role-redesign]].
> ⏳ T1.7 курс-пикер требует endpoint «список курсов предмета» (нет в ядре) — отдельная мелкая задача.

**Acceptance:** препод открывает КТП реальной группы → видит слоты периода с каникулами; «Распределить» раскидывает уроки курса по слотам; перетаскивание закрепляет тему (пин), хвост переразливается; перезагрузка сохраняет результат (данные в БД, не в JS). ✅ путь данных проверен (`getCalendar` → 6 тем размещены); визуально — вход `demoteacher`/`/profile/` → КТП.

---

## D. Эпик 2 — Посещаемость + журнал-сетка

> Журнал-сетка нарисована (демо). Нужна таблица посещаемости и связка ячеек с реальными `attendance` + `gradebook`.

### D.1. Домен посещаемости (`Lms`)

| ID | Задача | Статус | Затрагивает |
|---|---|---|---|
| T2.1 | Таблица `fs_lms_attendance` (uq `group_lesson_id`+`student_person_id`, `is_present`, `marked_by`, `marked_at`) — бинарно +/− | ✅ | `Migration_1_0_0`, `TableName::Attendance` |
| T2.2 | `AttendanceDTO` | ✅ | `inc/DTO/Course/AttendanceDTO.php` |
| T2.3 | `AttendanceRepository` (upsert ON DUPLICATE KEY, listByGroupLesson/Group/Student) | ✅ | `inc/Repositories/WPDBRepositories/AttendanceRepository.php` |
| T2.4 | `AttendanceService` — `mark` / `markAll` («всем present») / `matrixForGroup` | ✅ | `inc/Services/Course/AttendanceService.php` |
| T2.5 | Хуки `SaveAttendance` + `BulkAttendance` → `AttendanceService` (`JournalCallbacks`/`JournalController`) | ✅ | `AjaxHook`, `JournalController`, `JournalCallbacks` |
| T2.6 | Хук `GetGroupJournal` → `JournalService::forGroup` (ростер × занятия(+/−) × работы(сырые баллы), **без оценок/среднего**) | ✅ | `JournalService`, `GradebookService`, `AttendanceService` |

> **Бэкенд Эпика 2 готов** (T2.1–T2.6): таблица создана (через `dbDelta` точечно — ⚠️ `Migration::up()` дропает `groups`/`persons`/`student_records`, поэтому полный reset схемы НЕ запускал, чтобы не снести сид; версия возвращена в `1.0.0`). 3 хука зарегистрированы (`has_action`), `JournalService::forGroup(1)` → 6 занятий. `fsProfile` несёт блок `journal` (nonce+действия) для препода **и офиса**.
> **FSOffice получил фронт-профиль (решение пользователя, расширяет D2):** `ProfileViewResolver::viewFor` отдаёт FSOffice витрину препода, но со **всеми** группами (`findAll`); доступ к любой группе уже даёт `canManage` по `ManageLmsPlatform` — `teacher_id` НЕ переписывается. Редирект-гейт в `ProfileController` теперь = «нет витрины у роли» (методист/маркетолог → админка), `UserRole::isStaff()` удалён. Замены (Эпик 5) делаются в профиле офиса. Проверено: office #55 видит группу #1 (чужую) — путь `findAll` работает.

### D.2. Связка журнала (`Profile`)

| ID | Задача | Статус | Затрагивает |
|---|---|---|---|
| T2.7 | `journal.js` переписан на `GetGroupJournal`: реальный ростер, столбцы занятий (+/−) и работ (сырые баллы); 5-балльные оценки и колонка среднего убраны; группа — из сайдбара (`setJournalGroup`) | ✅ | `src/js/profile/journal.js`, `app.js` |
| T2.8 | Поповер ячейки (Был/Н) → `SaveAttendance`; меню заголовка занятия (Все присутствуют/отсутствуют) → `BulkAttendance`; локальное обновление ячеек | ✅ | `journal.js` |
| T2.9 | «работы/контрольные» — отдельное семейство столбцов из `GradebookService` ✅. Помесячная пагинация — **не делал** (все занятия в одной сетке с гориз. скроллом и липкими колонками); опционально позже | 🟡 | `journal.js` |

**Acceptance:** ✅ журнал реальной группы #1 показывает 6 учеников × 6 занятий; клик по ячейке ставит присутствие (+/−, пишется в `fs_lms_attendance`); меню колонки — массовая отметка; работы — сырые баллы (`GradebookService`); среднего/5-балльных оценок нет. Проверено: `JournalService::forGroup(1)` → 6 students / 6 lessons / 12 attendance marks. Визуально — вход `demoteacher` или `demooffice` → «Журнал».

> **Эпик 2 завершён** (T2.1–T2.8 ✅, T2.9 🟡 без помесячной пагинации). Сид: 6 учеников в группе #1 + посещаемость на 2 занятиях. `works=0` пока нет сдач — заполнится в Эпике 3.

---

## E. Эпик 3 — Проверка работ (связать готовый бэкенд)

> Бэкенд проверки готов (хуки `SaveGrade`/`GradeBatchTask`/`ReturnSubmission`, репозиторий очереди). В профиле нужен экран очереди + связка ворклиста.

| ID | Задача | Слой | Статус | Затрагивает |
|---|---|---|---|---|
| T3.1 | Экран/панель «Проверка» в профиле: очередь `GetGroupSubmissions` (статус `submitted`) по всем группам препода | Profile | 🔴 | новый экран в SPA профиля |
| T3.2 | Действие оценки + фидбек → `SaveGrade` / `GradeBatchTask`; возврат на доработку → `ReturnSubmission` | Profile | 🟡 | хуки готовы, нужен UI |
| T3.3 | Ворклист «Главной» (Эпик 6) ведёт в очередь конкретной работы | Profile | 🔴 | T3.1, T6.x |
| T3.4 | (опц. 🔶 D7) Рубрики/критерии, аннотирование PDF, пир-ревью | Lms | 🔴 | — |

**Acceptance:** препод видит реальную очередь «на проверку», ставит оценку с фидбеком (через `SaveGrade`), работа уходит из очереди; авто-оценивание объективных уже работает (`AutoGradeService`/контрольные).

---

## F. Эпик 4 — Индивидуальные занятия (эволюция `group_lessons`)

> Отработок нет (D3). Всё, что не групповое занятие — `kind='individual'` с одним учеником.

| ID | Задача | Слой | Статус | Затрагивает |
|---|---|---|---|---|
| T4.1 | Колонки `group_lessons`: `status ENUM(scheduled/held/cancelled/moved)`, `teacher_id_override`, `kind ENUM(group/individual)`, `student_person_id` (+ ключи) в `Migration_1_0_0` (up/down/Cleanup) | Lms | 🔴 | `Migration_1_0_0` |
| T4.2 | Расширить `GroupLessonDTO` новыми полями + `fromArray()` | Lms | 🔴 | `GroupLessonDTO` |
| T4.3 | `status='held'` — фиксация «занятие проведено» (план/факт); `cancelled`/`moved` сдвигают нерассказанный хвост в `reflow` (а `kind='individual'` исключён из раскладки) | Lms | 🔴 | `SessionCalendarService::reflow()` (учесть status+kind) |
| T4.4 | Хук `AjaxHook::CreateIndividualLesson` (`kind=individual`, `student_person_id`) | Lms | 🔴 | `AjaxHook`, callbacks |
| T4.5 | UI: создать индивидуальное занятие; быстрое создание из absent-ячейки журнала / карточки ученика | Profile | 🟡 | `journal.js` (демо-кнопка в поповере → переназначить на «индивидуальное») |

**Acceptance:** препод создаёт индивидуальное занятие на одного ученика (`kind='individual'`, `student_person_id`); оно не входит в программу группы и не двигает `reflow`.

---

## G. Эпик 5 — Замена преподавателя (`substitutions` + резолвер + доступ)

| ID | Задача | Слой | Статус | Затрагивает |
|---|---|---|---|---|
| T5.1 | Таблица `fs_lms_substitutions` (group_id, original/substitute_teacher_id, valid_from/to, reason, approved_by) в `Migration_1_0_0` | Lms | 🔴 | `Migration_1_0_0`, `TableName::Substitutions` |
| T5.2 | `SubstitutionDTO` + `SubstitutionRepository` (активные на дату, по substitute) | Lms | 🔴 | DTO/Repo |
| T5.3 | `EffectiveTeacherResolver`: `teacher_id_override` › активная `substitutions` (`valid_from≤D≤valid_to`) › `groups.teacher_id` | Lms | 🔴 | новый сервис |
| T5.4 | Расширить `GroupAccessGuard::canManage()` — time-bound grant (юзер, группа, сегодня); по `valid_to` доступ гаснет сам | Lms | 🔴 | `GroupAccessGuard` |
| T5.5 | `Capability::ManageSchedule` (или офисная) + хук `AjaxHook::AssignSubstitute`; назначает завуч/`FSOffice` (интерфейс в офисе, не в кабинете препода) | Lms | 🔴 🔶 D5 | `Capability`, `RoleManager`, `AjaxHook` |
| T5.6 | «Главная» замещающего: чужие группы на срок grant с маркером «замена до [дата]»; печать списка замен | Profile | 🔴 | dashboard-агрегация |
| T5.7 | Оригинальный препод видит свою группу «замена до [дата]» (read-only в период замены) 🔶 | Profile | 🔴 | dashboard |

**Acceptance:** завуч создаёт замену на период → замещающий видит группу в «Главной» и пускается в журнал; по истечении `valid_to` доступ исчезает без ручной правки; `groups.teacher_id` не перезаписывается.

---

## H. Эпик 6 — «Главная» кабинета (агрегация по всем группам)

> Оболочка и `dashboard.js` нарисованы (демо). Нужна кросс-групповая агрегация реальных данных.

| ID | Задача | Слой | Статус | Затрагивает |
|---|---|---|---|---|
| T6.1 | D1/D2 зафиксированы и реализованы (профиль в ядре, `/profile/`, per-role резолвер) | — | ✅ | — |
| T6.2 | Хук `AjaxHook::GetProfileDashboard` — занятия сегодня/неделя по всем группам препода (effective-teacher), агрегированный ворклист | Profile→Lms | 🔴 | `EffectiveTeacherResolver` (T5.3), `group_lessons`, `SessionCalendarService` |
| T6.3 | Ворклист «заполнить»: незаполненная посещаемость прошедших занятий | Lms | 🔴 | `AttendanceService` (T2.4) |
| T6.4 | Ворклист «проверить»: `SubmissionRepository::listQueueByGroup()` по всем группам | Lms | 🟡 | готовый репозиторий |
| T6.5 | `dashboard.js`: рендер из `GetProfileDashboard`; сайдбар-группы из реальных групп препода; убрать демо | Profile | 🔴 | `src/js/profile/dashboard.js`, `data.js` |
| T6.6 | Стат-плитки из реальных агрегатов (занятий сегодня, на проверке, не заполнено) | Profile | 🔴 | T6.2 |
| T6.7 | Карточка ученика (§4.5): срез по `student_person_id` — посещаемость, задачи, работы, контрольные, прогресс; **без PII** (`ViewPII` только офис) | Profile | 🔴 | gradebook/attendance/progress по ученику |

**Acceptance:** «Главная» показывает реальное расписание препода на сегодня/неделю по всем группам (включая замены); ворклист считает реальные незаполненные журналы и работы на проверке; навигация ведёт в журнал/проверку нужной группы.

---

## I. Эпик 7 — Профиль ученика/родителя (зеркальный, после препода)

> Многое готово (student-cockpit, lesson-player, `ExamResultService`). Профиль в основном собирает. Каркас per-role уже есть: `LearnerProfileView` + `ProfileContext` (ученик self / родитель child+read-only); экраны — заглушки `learner.js`, ждут наполнения.

| ID | Задача | Статус | Источник |
|---|---|---|---|
| T7.1 | Per-role рендер `/profile/` для учащегося — каркас (`LearnerProfileView`, `ProfileContext`, заглушки) | 🟡 | `ProfileViewResolver` (готов) |
| T7.2 | «Главная» ученика: расписание, дедлайны, новые оценки, ДЗ | 🔴 | `group_lessons`, `submissions.due_at`, `assessment_attempts` |
| T7.3 | «Мои оценки» (дневник ученика) | 🟡 | `GradebookService::forStudent()` |
| T7.4 | «Посещаемость» ученика и % | 🔴 | `fs_lms_attendance` (после T2.x) |
| T7.5 | Родитель: те же данные по ребёнку, read-only | 🔴 | + фильтр `parent_person_id` (`findActiveByParent`) |
| T7.6 | 🔶 Модель ДЗ (на `submissions` или поле занятия) | 🔴 | — |

---

## J. Эпик 8 — Сквозное (оценки, качество, доступ)

| ID | Задача | Статус | Примечание |
|---|---|---|---|
| T8.1 | ~~Взвешивание оценок~~ **снято** (оценок и среднего нет — см. D4). Из D7 остаётся post-v1: lock КТП, рубрики | ⛔ N/A | заменено сырыми баллами |
| T8.2 | PHPUnit на новые `*Callbacks` (посещаемость, reflow, замены, индивидуальные) | 🔴 | политика «cover callbacks with tests» |
| T8.3 | Приёмка per-role доступа: препод видит свои группы/инструменты; ученик — только свои данные (read-write); родитель — данные ребёнка (read-only, замок на сервере); офисные роли → редирект в админку | 🔴 | приёмочный чек |
| T8.4 | `npx gulp build` + `styles:check` зелёные; ассеты `profile.min.*` собираются | ✅ (для оболочки) | поддерживать |

---

## K. Порядок (рекомендуемый)

1. **B (решения).** D1/D2 ✅ зафиксированы и реализованы (профиль в ядре, `/profile/`, per-role). Осталось решить D3–D7 перед соответствующими эпиками.
2. **Эпик 1 (КТП + reflow→AJAX)** — самый дешёвый, движок уже есть.
3. **Эпик 2 (посещаемость + журнал)** — ядро ценности препода.
4. **Эпик 3 (проверка)** — бэкенд готов, нужен экран/связка.
5. **Эпик 6 («Главная» агрегация)** — после того как есть что агрегировать.
6. **Эпик 4 (индивидуальные занятия)** → **Эпик 5 (замены)** — доменные расширения.
7. **Эпик 7 (ученик/родитель)**, **Эпик 8 (тесты, доступ, post-v1)**.

> Маппинг на под-этапы `Courses.md` §7: 1→Эпик1, 2→Эпик2, 3→Эпик3, 4→Эпик4, 5→Эпик5, 6→Эпик6+7, 7→Эпик8.
