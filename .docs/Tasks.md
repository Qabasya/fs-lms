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
| T1.8 | (опц. 🔶 D7) Lock КТП после публикации | Lms | ✅ | Эпик 11 (T11.7) | `groups.program_locked_at` (миграция + `GroupsRepository::setProgramLocked`); `ScheduleService::isProgramLocked/publishProgram/unpublishProgram`; `PublishProgram`/`UnpublishProgram` хуки; `ProgramCallbacks::denyIfProgramLocked` блокирует 8 мутаторов (assign/add/duplicate/remove/reorder/schedule/reflow/pin), НЕ трогает индивид. занятия/видимость/чтение; `getCalendar` отдаёт `locked`/`locked_at`; `ktp.js` бейдж + «Опубликовать/Снять» + off drag/drop/reflow. +5 тестов. Заодно закрыт латентный недогард remove/schedule (не было `canManage` в callback) |

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
| T3.1 | Экран/панель «Проверка» в профиле: очередь `GetGroupSubmissions` (статус `submitted`) по всем группам препода | Profile | ✅ | `src/js/profile/review.js`, `app.js`, `_review.scss` |
| T3.2 | Действие оценки + фидбек → `SaveGrade`; возврат на доработку → `ReturnSubmission` | Profile | ✅ | `review.js` (форма балл/из/коммент + кнопки Оценить/Вернуть) |
| T3.3 | ~~Ворклист «Главной» ведёт в очередь конкретной работы~~ → **переосмыслено в Эпике 10 (D8)**: экран «Проверка работ» заменяется «Сводкой по ученику» (T10.8); вход — ворклист «Главной» ✅ | Profile | ➡️ Эпик 10 | T10.8 |
| T3.4 | (опц. 🔶 D7) Рубрики/критерии, аннотирование PDF, пир-ревью → **Эпик 11 (T11.7)** | Lms | ⛔ Retired (D15) | — |

> **Эпик 3 готов (кроме T3.3-навигация из «Главной» и опц. T3.4).**
> - **Бэкенд:** хуки `GetGroupSubmissions`/`SaveGrade`/`ReturnSubmission` уже были; payload очереди обогащён read-моделью **`ReviewQueueService::forGroup()`** — добавлены `student_name` (снимок ростера, **PII-safe** — как в журнале), `work_type_label`, `lesson_topic`, `max_score`. `GradingCallbacks::ajaxGetGroupSubmissions` теперь делегирует сервису (тонкий callback). Обратная совместимость с legacy `submission.js` сохранена (поля только добавлены).
> - **Фронт:** новый экран **«Проверка работ»** в SPA (`review.js`) — агрегирует очередь по **всем** группам препода (параллельные `get_group_submissions` на `fsProfile.groups`), группирует по группам, карточка = ученик + тип+тема + текст ответа + форма (балл/из/коммент) + Оценить (`save_grade`) / Вернуть (`return_submission`, коммент обязателен). После действия карточка убирается, счётчики пересчитываются. `fsProfile.review = {nonce: GradeWork, actions}` добавлен в `ProfileViewResolver::jsConfig`; nav/screen `review` — в `TeacherProfileView`.
> - **Проверено E2E** (`ReviewQueueService::forGroup(1)`): засеяны 3 сдачи в группу #1 → очередь показывает имена/типы/темы; `grade()`+`returnForRework()` убирают работы (3→1). В очереди осталась 1 сдача (#3, ДЗ) для визуального теста: вход `demoteacher` → «Проверка работ».

**Acceptance:** ✅ препод видит реальную очередь «на проверку» по всем группам, ставит оценку с фидбеком (через `SaveGrade`) или возвращает на доработку (`ReturnSubmission`), работа уходит из очереди; авто-оценивание объективных уже работает (`AutoGradeService`/контрольные).

---

## F. Эпик 4 — Индивидуальные занятия (эволюция `group_lessons`)

> Отработок нет (D3). Всё, что не групповое занятие — `kind='individual'` с одним учеником.

| ID | Задача | Слой | Статус | Затрагивает |
|---|---|---|---|---|
| T4.1 | Колонки `group_lessons`: `kind ENUM(group/individual)`, `status ENUM(scheduled/held/cancelled/moved)`, `student_person_id` (+ ключ `kind_student`) в `Migration_1_0_0` (CREATE + Cleanup). **`teacher_id_override` НЕ добавлял** — эту роль уже играет существующий `teacher_user_id` | Lms | ✅ | `Migration_1_0_0` |
| T4.2 | `GroupLessonDTO`/`GroupLessonInputDTO` + `fromArray()`/`toArray()` (kind/status/studentPersonId); enum-ы `LessonKind`, `LessonStatus` | Lms | ✅ | `GroupLessonDTO`, `GroupLessonInputDTO`, `Enums/Course/LessonKind`, `LessonStatus` |
| T4.3 | `kind='individual'` исключён из раскладки: `reflow()` (счёт), `applySlots()` (skip), `getProgram()` (КТП), `JournalService` (столбцы). `status` (held/cancelled/moved) — колонка+enum есть; логика сдвига хвоста по статусу — post-v1 | Lms | 🟡 | `SessionCalendarService`, `GroupLessonRepository`, `ScheduleService`, `JournalService` |
| T4.4 | Хук `AjaxHook::CreateIndividualLesson` → `ProgramCallbacks::ajaxCreateIndividualLesson` → `ScheduleService::createIndividualLesson()` (валидация членства ученика; `is_pinned`) | Lms | ✅ | `AjaxHook`, `ScheduleController`, `ProgramCallbacks`, `ScheduleService` |
| T4.5 | UI: создание инд. занятия из поповера ячейки журнала («＋ Индивидуальное занятие» → мини-форма дата/время → `create_individual_lesson`) | Profile | ✅ | `journal.js`, `ProfileViewResolver`, `_core.scss` |

> **Эпик 4 готов** (T4.1/T4.2/T4.4/T4.5 ✅, T4.3 🟡 — раскладка исключает `individual`; логика сдвига хвоста по `status` отложена post-v1).
> - **Уточнение по D3:** `teacher_id_override` из спеки = существующая колонка `teacher_user_id` (per-lesson преподаватель); дубль не заводил. Замены (Эпик 5) используют `teacher_user_id` для разового override + таблицу `substitutions` для grant.
> - **Схема:** 3 колонки в CREATE + Cleanup (`ADD COLUMN IF NOT EXISTS`); в dev-БД применены точечным `ALTER` (без reset — сид цел).
> - **E2E проверено:** инд. занятие (`kind=individual`, `student_person_id`, `is_pinned=1`) НЕ входит в программу (6→6) и журнал (6→6), `reflow` не двигает дату; guard членства отклоняет чужого ученика. PHPUnit: `ScheduleServiceTest`+`ProgramCallbacksTest` расширены, вся сюита 529 зелёная.

**Acceptance:** ✅ препод создаёт индивидуальное занятие на одного ученика (`kind='individual'`, `student_person_id`); оно не входит в программу группы и не двигает `reflow`. Визуально — вход `demoteacher` → «Журнал» → клик по ячейке ученика → «＋ Индивидуальное занятие».

---

## G. Эпик 5 — Замена преподавателя (`substitutions` + резолвер + доступ)

| ID | Задача | Слой | Статус | Затрагивает |
|---|---|---|---|---|
| T5.1 | Таблица `fs_lms_substitutions` (group_id, original/substitute_teacher_id, valid_from/to, reason, approved_by) в `Migration_1_0_0` (CREATE + down) | Lms | ✅ | `Migration_1_0_0`, `TableName::Substitutions` |
| T5.2 | `SubstitutionDTO` + `SubstitutionRepository` (`findActiveForGroup`/`findActiveBySubstitute`/`hasActiveGrant`/`listByGroup`/`create`/`delete`) | Lms | ✅ | DTO/Repo |
| T5.3 | `EffectiveTeacherResolver`: `teacher_user_id` (разовый override) › активная `substitutions` (`valid_from≤D≤valid_to`) › `groups.teacher_id` — `forGroup(date)`/`forLesson()` | Lms | ✅ | новый сервис |
| T5.4 | `GroupAccessGuard::canManage()` пускает при активном grant (`hasActiveGrant` по `CURDATE()`); по `valid_to` доступ гаснет сам | Lms | ✅ | `GroupAccessGuard` |
| T5.5 | `Capability::ManageSchedule` (только офис+админ, caps→5.1) + `SubstitutionService` + хуки (`SubstitutionCallbacks`/`Controller`, nonce `Substitution`). **Офисная форма назначения — построена в экране «Замены» (T9.18 ✅)** | Lms | ✅ | `Capability`, `UserRole`, `RoleManager`, `AjaxHook`, `Nonce`, `Init`, `substitutions.js` |
| T5.6 | «Главная» замещающего: чужие группы на срок grant с баннером «вы замещаете … до [дата]» + маркер на карточке группы | Profile | ✅ | сделано в Эпике 6 (`DashboardService.covering` + `covering_until`); печать списка — отложено |
| T5.7 | Оригинальный препод видит свою группу «замена до [дата]» (маркер `covered_until` на карточке). Принудительный read-only журнала в период замены | Profile | ✅ | маркер — Эпик 6; серверный read-only-гейт добавлен в Эпике 11 (T11.7): `GroupAccessGuard::canWriteJournal` — в период активной замены постоянный препод пишет только если нет активной замены; чтение (`canManage`) остаётся |

> **Эпик 5 (домен) готов** (T5.1–T5.4 ✅, T5.5 🟡 — AJAX-слой готов, офисная админ-форма pending; T5.6/T5.7 🔴 — ждут dashboard Эпика 6).
> - **По D5:** резолв на чтении, `groups.teacher_id` НЕ перезаписывается; `teacher_id_override` = существующая `teacher_user_id` (см. Эпик 4). `ManageSchedule` — новая cap только у `FSOffice`+admin (не у `FSTeacher`), caps-версия → `5.1`.
> - **Схема:** таблица в CREATE + down(); в dev-БД создана точечным `CREATE TABLE IF NOT EXISTS` (без reset — сид цел). Caps синхронизированы (`syncCapabilities`).
> - **E2E проверено:** назначил замену substitute=90001 на группу #1 → `EffectiveTeacherResolver::forGroup` = 90001, `canManage(1,90001)=true`; после `valid_to<today` → resolver вернулся к `groups.teacher_id=54`, `canManage=false`; `groups.teacher_id` не тронут. Хуки зарегистрированы (`has_action`). PHPUnit: `EffectiveTeacherResolverTest`/`SubstitutionServiceTest`/`SubstitutionCallbacksTest` + обновлён `GroupAccessGuardTest`; вся сюита 541 зелёная.
> - **Осталось:** офисная UI-форма назначения замен (админ-страница/секция, дергает `assign_substitute`) + dashboard-маркеры «замена до [дата]» (T5.6/T5.7, вместе с Эпиком 6).

**Acceptance:** ✅ (домен) завуч создаёт замену на период → по `EffectiveTeacherResolver`/`canManage` замещающий получает доступ к группе; по истечении `valid_to` доступ исчезает без ручной правки; `groups.teacher_id` не перезаписывается. ⏳ визуальная часть (назначение в офисе + «Главная» замещающего) — с UI-формой и Эпиком 6.

---

## H. Эпик 6 — «Главная» кабинета (агрегация по всем группам)

> Оболочка и `dashboard.js` нарисованы (демо). Нужна кросс-групповая агрегация реальных данных.

| ID | Задача | Слой | Статус | Затрагивает |
|---|---|---|---|---|
| T6.1 | D1/D2 зафиксированы и реализованы (профиль в ядре, `/profile/`, per-role резолвер) | — | ✅ | — |
| T6.2 | Хук `AjaxHook::GetProfileDashboard` → `DashboardService::build()` — занятия сегодня/неделя по всем группам (свои + замены, где юзер замещающий; офис — все), агрегированный ворклист | Profile→Lms | ✅ | `DashboardService`, `DashboardCallbacks`, `ProfileDashboardController` |
| T6.3 | Ворклист «заполнить»: прошедшие групповые занятия без единой отметки посещаемости | Lms | ✅ | `AttendanceService::matrixForGroup` |
| T6.4 | Ворклист «проверить»: `SubmissionRepository::listQueueByGroup()` по всем группам (счётчик на группу) | Lms | ✅ | готовый репозиторий |
| T6.5 | `dashboard.js` переписан на `GetProfileDashboard`; демо (`data.js`) убран; навигация: занятие/группа → журнал, «проверить» → экран «Проверка работ» | Profile | ✅ | `src/js/profile/dashboard.js`, `app.js` |
| T6.6 | Стат-плитки из реальных агрегатов (занятий сегодня, на проверке, не заполнено, групп) | Profile | ✅ | `DashboardService::build().stats` |
| T6.7 | Карточка ученика (§4.5): срез по `student_person_id` — посещаемость, задачи, работы, контрольные; **без PII** → **поглощено Эпиком 10 (T10.8 «Сводка по ученику»)** | Profile | ➡️ Эпик 10 | T10.8 |

> **Эпик 6 готов** (T6.1–T6.6 ✅, T6.7 🔴 — карточка ученика отложена как отдельная фича).
> - **Агрегат:** `DashboardService::build(userId, isOffice)` собирает по набору групп (свои `findByTeacherId` + группы активных замен `findActiveBySubstitute`; офис → `findAll`): расписание сегодня/неделя (state now/soon/done по часам), ворклист «заполнить» (прошедшие занятия без отметок, топ-12) и «проверить» (очередь сдач по группам), стат-плитки, маркеры замен (Эпик 5).
> - **Закрывает Эпик 5 T5.6/T5.7:** payload несёт `covering` (баннер «вы замещаете …») + `covered_until`/`covering_until` на карточках групп («замена до [дата]»).
> - **E2E проверено:** `build(54)` по группе #1 → to_review=1, to_fill=4, groups=1; маркеры: владелец видит `covered_until`, замещающий (90001) — `covering` + чужую группу в списке. Хук зарегистрирован. PHPUnit: `DashboardServiceTest`/`DashboardCallbacksTest`; вся сюита 544 зелёная.

**Acceptance:** ✅ «Главная» показывает реальное расписание на сегодня/неделю по всем группам (включая замены); ворклист считает незаполненные журналы и работы на проверке; клик ведёт в журнал/«Проверку». Визуально — вход `demoteacher` → «Главная».

---

## I. Эпик 7 — Профиль ученика/родителя (зеркальный, после препода)

> Многое готово (student-cockpit, lesson-player, `ExamResultService`). Профиль в основном собирает. Каркас per-role уже есть: `LearnerProfileView` + `ProfileContext` (ученик self / родитель child+read-only); экраны — заглушки `learner.js`, ждут наполнения.

| ID | Задача | Статус | Источник |
|---|---|---|---|
| T7.1 | Per-role рендер `/profile/` учащегося на реальных данных: один endpoint `GetLearnerProfile` → `LearnerService::build(personId)`; `learner.js` переписан (демо-заглушки убраны) | ✅ | `LearnerService`, `LearnerCallbacks`, `LearnerProfileController`, `learner.js` |
| T7.2 | «Главная» ученика: ближайшие занятия, дедлайны (`homework_due_at`), новые оценки | ✅ | `group_lessons`, `GradebookService` |
| T7.3 | «Мои оценки» (дневник): `GradebookService::forStudent()` — сырые баллы (D4), без 5-балльных | ✅ | `GradebookService::forStudent()` |
| T7.4 | «Посещаемость» ученика + % (бинарно) | ✅ | `AttendanceRepository::listByStudent` |
| T7.5 | Родитель: те же экраны по ребёнку, read-only + переключатель детей; серверная проверка «свой ребёнок» (клиентский `student_person_id` не доверяем) | ✅ | `ProfileContext.children`, `LearnerCallbacks` |
| T7.6 | 🔶 Модель ДЗ — пока дедлайны берутся из `group_lessons.homework_due_at`; отдельной сущности ДЗ нет | ➡️ Эпик 12 | T12.2/T12.3 (D13: per-work дедлайны занятия, отдельная сущность не нужна) |

> **Эпик 7 готов** (T7.1–T7.5 ✅, T7.6 ➡️ Эпик 12 — per-work дедлайны, D13).
> - **Бэкенд:** `LearnerService::build(personId)` (read-only) — группы, расписание/дедлайны, дневник (сырые баллы), посещаемость+%. Хук `GetLearnerProfile` **без capability** (у ученика/родителя нет LMS-прав): гейт = нонс `LearnerProfile` + `is_user_logged_in` + авторизация на данные через `ProfileContext` (ученик → только `subjectPersonId`; родитель → `student_person_id` проверяется против списка детей). `fsProfile.learner={nonce,actions}` для ролей `FSStudent/FSParent/Student`.
> - **Фронт:** `learner.js` переписан — 4 экрана (Главная/Мои курсы/Мои оценки/Посещаемость) из одного `getProfile`; у родителя — `prof-child-bar` с `<select>` детей (смена → reload + rerender всех экранов). `ProfileViewResolver` расмокался (снят `final`) для теста колбэка.
> - **E2E проверено:** `LearnerService::build(9001)` (группа #1) → 1 группа, 6 занятий, 1 оценка `8/10`, посещаемость 2/2=100%. Хук зарегистрирован. PHPUnit: `LearnerServiceTest`/`LearnerCallbacksTest` (+`is_user_logged_in` стаб в bootstrap); вся сюита 549 зелёная.

**Acceptance:** ✅ ученик видит свои группы/расписание/оценки/посещаемость (read-only); родитель — те же данные по выбранному ребёнку с серверным замком «только свой ребёнок». Визуально — вход ученика/родителя → `/profile/`.

---

## J. Эпик 8 — Сквозное (оценки, качество, доступ)

| ID | Задача | Статус | Примечание |
|---|---|---|---|
| T8.1 | ~~Взвешивание оценок~~ **снято** (оценок и среднего нет — см. D4). Из D7 остаётся post-v1: lock КТП, рубрики | ⛔ N/A | заменено сырыми баллами |
| T8.2 | PHPUnit на новые `*Callbacks`: посещаемость (`JournalCallbacksTest`), reflow/pin (`ProgramCallbacksTest`), индивидуальные (`ProgramCallbacksTest`), замены (`SubstitutionCallbacksTest`), + Grading/Dashboard/Learner из эпиков 3–7 | ✅ | политика «cover callbacks with tests» |
| T8.3 | Приёмка per-role доступа: `ProfileViewResolverTest` (роль→витрина: препод/офис→препод, ученик/родитель→учащийся, методист/маркетолог→null=редирект в админку); авторизация на данные ученика/родителя — `LearnerCallbacksTest` (замок «только свой ребёнок») | ✅ | приёмочный чек |
| T8.4 | `npx gulp build` + `styles:check` + `lint:js` зелёные; ассеты `profile.min.*` собираются | ✅ | поддерживать |

> **Эпик 8 готов** (T8.1 ⛔ N/A, T8.2–T8.4 ✅).
> - **T8.2 покрытие callback-слоя:** `JournalCallbacksTest` (getJournal/saveAttendance/bulkAttendance + отказы по `canManage`), `ProgramCallbacksTest` расширен (reflow/pin + createIndividual), `SubstitutionCallbacksTest`, `GradingCallbacksTest`, `DashboardCallbacksTest`, `LearnerCallbacksTest`. Сервисы: `ReviewQueueService`/`ScheduleService`/`EffectiveTeacherResolver`/`SubstitutionService`/`DashboardService`/`LearnerService`Test.
> - **T8.3 приёмка доступа:** `ProfileViewResolverTest` фиксирует роль→витрину (офисные back-office роли → null → `ProfileController` редиректит в админку); `LearnerCallbacksTest` проверяет серверный замок (ученик только себя; родитель — только своих детей; клиентский `student_person_id` не доверяем).
> - **Итог:** вся сюита **561 тест зелёная**; `gulp build` + `styles:check` + `npm run lint:js` — чисто.
> - **Осталось post-v1/отдельно:** T6.7 (карточка ученика), T5.7 read-only-гейт журнала в период замены 🔶, T7.6 ДЗ-модель 🔶, D7 (lock КТП, рубрики), офисная UI-форма назначения замен, курс-пикер КТП (T1.7).

---

## L. Эпик 9 — Кабинеты (аудитории): справочник + бронирование по времени

> **Будущее — после ЛК преподавателя (Эпики 1–7).** Физические кабинеты как справочник, управляемый
> **FSOffice в админке**, с контролем занятости по времени. Кабинет привязывается к занятиям (обычно
> **1 раз на учебный год**, при необходимости меняется); индивидуальное занятие препод ставит в **свободный**
> кабинет. Слот кабинета в UI уже нарисован (`dashboard.js` рендерит `lesson.room` — сейчас мок «каб. 305»).

### L.0. Опорные факты (что уже есть — переиспользовать)

- `group_lessons` несёт окно занятия **`scheduled_at` + `ends_at`** → конфликт кабинетов считается на уровне
  материализованных занятий: `A.scheduled_at < B.ends_at AND B.scheduled_at < A.ends_at`. Отдельная таблица
  броней НЕ нужна — достаточно колонки `room_id` на `group_lessons`.
- Меню/CRUD зеркалим у Групп: `Menu::Main` + `Capability::ManageLmsPlatform`; трио
  `GroupsRepository`(WPDB, raw-объекты) + `StudentGroupCallbacks` + `StudentGroupController`; `Nonce::Manager`;
  регистрация контроллера в `Init.php $services`.
- ⚠️ **Предусловие:** `ScheduleService::schedule()` при ручном переносе **не пишет `ends_at`** (его заполняет
  только `SessionCalendarService::generate()/applySlots()`). Для надёжной проверки занятости `ends_at` надо
  писать всегда (или выводить `end = start + duration_min` из `groups.meetings`).

### L.1. Развилки — ✅ ЗАФИКСИРОВАНЫ (2026-07-01)

- **R1 — Модель привязки. ✅ (б):** `groups.room_id` (кабинет-по-умолчанию на год) + `group_lessons.room_id`
  (override/индивидуальные, `NULL` = дефолт группы); эффективный кабинет = `lesson.room_id ?? group.room_id`.
- **R2 — Жёсткость конфликта. ✅:** конфликт по времени — **hard-block** (`RoomAssignmentService::assignToLesson`
  бросает исключение); нехватка мест (`seats`) — **мягкое предупреждение** (возвращается, не блокирует).
- **R3 — Область справочника. ✅:** кабинеты **глобальны** (не привязаны к периоду); занятость per-occurrence
  через `group_lessons` по эффективному кабинету (`COALESCE(gl.room_id, g.room_id)`).
- **UI-развилка (решение пользователя):** «Кабинеты» — **таб на странице «Настройки»** (между «Периоды» и
  «Шаблоны писем»), в стиле таба «Учебные периоды» (серверная таблица + «+» → модалка), **НЕ отдельная
  меню-страница**. Меню-подстраница `Menu::Rooms`/`roomsPage` откачена.

### L.2. Домен (`Lms`)

| ID | Задача | Статус | Затрагивает |
|---|---|---|---|
| T9.1 | Таблица `fs_lms_rooms` (`name`, `seats`, `allowed_subjects` json — пусто=любой, `is_active`, `deleted_at`) + `TableName::Rooms` | ✅ | `Migration_1_0_0`, `TableName` |
| T9.2 | Колонки `room_id` на `group_lessons` + `groups.room_id` (CREATE + Cleanup `ADD COLUMN IF NOT EXISTS`); `roomId` в `GroupLessonDTO`/`InputDTO` | ✅ | `Migration_1_0_0`, DTO |
| T9.3 | `RoomDTO` + `RoomRepository` (WPDB): CRUD (soft-delete) + `isBusy()` (occupancy через `COALESCE(gl.room_id,g.room_id)`) | ✅ | `RoomDTO`, `RoomRepository` |
| T9.4 | `RoomAvailabilityService`: `isFree()`, `listFreeRooms(start,end,subjectKey)` (фильтр по `allowed_subjects`) | ✅ | `RoomAvailabilityService` |
| T9.5 | `ends_at` — решено через COALESCE-fallback (`ends_at` ?? `scheduled_at + 60 мин`) в `isBusy`/`assignToLesson`; правку `schedule()` не требует | ✅ | `RoomRepository::isBusy` |
| T9.6 | `RoomAssignmentService`: `assignToGroup` (дефолт группы + валидация предмета + предупреждение вместимости) / `assignToLesson` (hard-block конфликта) | ✅ | `RoomAssignmentService` |

### L.3. UI FSOffice — **таб на странице «Настройки»** (решение пользователя)

| ID | Задача | Статус | Затрагивает |
|---|---|---|---|
| T9.7 | Таб «Кабинеты» в `settings.php` (между «Периоды» и «Шаблоны писем»); данные из `AdminCallbacks::settingsPage()` (`rooms` + карта «кабинет→группы с расписанием») | ✅ | `settings.php`, `AdminCallbacks` |
| T9.8 | Партиал `settings-9-rooms.php` (таблица Название/Группы/Действия + «+» → модалка) + `room-modal.php` (имя + чекбоксы предметов) — близнец «Учебных периодов»; тултип расписания группы (`.fs-tip`, `WeekDay::formatSchedule`) | ✅ | шаблоны, `_modal.scss` |
| T9.9 | `RoomController` + `RoomCallbacks` (`SaveRoom`/`DeleteRoom`/`GetRooms`/`AssignGroupRoom`, `Nonce::Room`, cap `ManageLmsPlatform`) в `Init`; JS `RoomModal`+`RoomModalManager` (близнецы периодов) | ✅ | `AjaxHook`, `RoomController`, `RoomCallbacks`, `Init`, admin JS |
| T9.10 | Привязка кабинета к группе на год — ✅ через селектор в **модалке группы** (T9.15). Endpoint `AssignGroupRoom` тоже готов (для таба). Осталось только временное (per-lesson) — T9.16 | ✅ | модалка группы (T9.15) |

### L.4. Интеграция с планировщиком и профилем (`Profile`/`Lms`)

| ID | Задача | Статус | Затрагивает |
|---|---|---|---|
| T9.11 | Hard-block конфликта при назначении кабинета занятию (`assignToLesson`) ✅. Проверка при `reflow`/`pin` (перенос дат) — **остаток** (reflow не трогает `room_id`) | 🟡 | `RoomAssignmentService` ✅; `ScheduleService`/reflow — TODO |
| T9.12 | Индивидуальное занятие (Эпик 4) — пикер **свободных** кабинетов (`listFreeRooms`); сервис готов, UI-пикер в поповере журнала — **остаток** | 🔴 | `RoomAvailabilityService` ✅; `journal.js` — TODO |
| T9.13 | Профиль: `dashboard.js` показывает реальный кабинет (`DashboardService` отдаёт `room` = эфф. кабинет). Журнал/КТП — **остаток** | 🟡 | `DashboardService` ✅, `dashboard.js` ✅; `journal.js`/`ktp.js` — TODO |
| T9.14 | PHPUnit: `RoomAvailabilityServiceTest`, `RoomAssignmentServiceTest`, `RoomCallbacksTest` (конфликт окон, `allowed_subjects`, вместимость, CRUD) | ✅ | тесты |

> **Эпик 9 — домен + офисный таб готовы** (L.2 ✅, L.3 ✅ кроме T9.10-UI-назначения, L.4 частично).
> - **UI:** «Кабинеты» — таб «Настроек» (близнец «Учебных периодов»): серверная таблица **Название / Группы / Действия** + «+» → модалка (имя + чекбоксы предметов). Колонка «Группы» показывает группы кабинета; при наведении — тултип расписания (`Вт 09:25-10:10, Пт 11:35-12:20`, `WeekDay::formatSchedule`). CRUD через `RoomModalManager` → `save_room`/`delete_room` → reload.
> - **Домен:** таблица `fs_lms_rooms` + `room_id` на `groups`/`group_lessons`; занятость по `COALESCE(gl.room_id,g.room_id)` с окном `ends_at ?? +60мин`; hard-block конфликта, мягкое предупреждение вместимости, фильтр по `allowed_subjects`.
> - **E2E проверено:** создан кабинет, назначен группе #1 → таб-данные `{name:"Тест-группа 9А", schedule:"Вт 09:25-10:10, Пт 11:35-12:20"}`; `isBusy` = занят в день занятия группы, свободен иначе; хуки `save_room`/`delete_room` зарегистрированы. PHPUnit: вся сюита **574 зелёная**; `gulp build`+`styles:check`+`lint:js` чисто.
> - **Осталово:** UI-назначение кабинета из модалки группы (endpoint `AssignGroupRoom` готов), проверка конфликта при `reflow`/`pin`, пикер свободных кабинетов в инд.занятии (журнал), кабинет в журнале/КТП. Поле `seats` в модалке скрыто по просьбе (в БД остаётся, предупреждение вместимости дремлет).

**Acceptance (домен+таб):** ✅ офис в «Настройки → Кабинеты» заводит кабинет (имя + предметы); таблица показывает группы кабинета с тултипом расписания; редактирование/удаление работают; система (на уровне сервисов) не даёт посадить два занятия в один кабинет в пересекающееся время и предупреждает о нехватке мест; в «Главной» профиля у занятия отображается реальный кабинет.

### L.5. Временные замены «через фронт» — кабинет + педагог (единая модель)

> Ментальная модель (зафиксировано 2026-07-01): **постоянное (на год)** — в модалке группы (`groups.teacher_id` + `groups.room_id`);
> **временное (override на занятие/период)** — «замены». Педагог уже сделан (Эпик 5); кабинет — симметрично.
>
> | Что | Постоянное (год) | Разовое (одно занятие) | На период (диапазон дат) |
> |---|---|---|---|
> | **Педагог** | `groups.teacher_id` (модалка группы) | `group_lessons.teacher_user_id` | `fs_lms_substitutions` (грант) — Эпик 5 ✅ |
> | **Кабинет** | `groups.room_id` (модалка группы) | `group_lessons.room_id` ✅ (`assignToLesson`) | bulk-override `room_id` по занятиям диапазона (TODO) |
>
> Резолв на чтении: педагог — `EffectiveTeacherResolver` (override › замена › `groups.teacher_id`); кабинет — `lesson.room_id ?? group.room_id`.

| ID | Задача | Статус | Затрагивает |
|---|---|---|---|
| T9.15 | Селектор «Кабинет» в модалке группы (рядом с «Преподаватель») → `groups.room_id`; валидация предмета (`RoomDTO::allowsSubject`) в `StudentGroupCallbacks` create+update; список кабинетов из `AdminCallbacks::groupsPage` (`rooms`), `data-room-id` на строке | ✅ | `group-modal.php`, `groups.php`, `group-modal.js`, `group-modal-manager.js`, `StudentGroupCallbacks`, `AdminCallbacks` |
| T9.16 | Замена **кабинета** на период (ремонт): `RoomAssignmentService::overrideForRange(groupId, roomId|null, from, to)` — bulk `group_lessons.room_id` по датам, конфликтные пропускаются в warnings; хук `SetRoomOverride` | ✅ | `RoomAssignmentService`, `SubstitutionCallbacks`, `AjaxHook` |
| T9.17 | Замена **педагога** (болезнь) — форма назначения `substitutions` в экране «Замены» (закрывает отложенный T5.5-UI) | ✅ | `AssignSubstitute`/`RevokeSubstitute` (готовы), `substitutions.js` |
| T9.18 | **Единый экран «Замены»** (офис): `substitutions.js` в SPA профиля — две карточки (педагог + кабинет) на группу; nav/screen только для `FSOffice` (`TeacherProfileView`); данные `GetSubstitutionsData` (замены+преподаватели+кабинеты) | ✅ | `substitutions.js`, `app.js`, `ProfileViewResolver`, `TeacherProfileView`, `SubstitutionController` |

> **Экран «Замены» (T9.16–T9.18) готов** (2026-07-01). Офисный инструмент в SPA профиля.
> - **Бэкенд:** `SubstitutionCallbacks` расширен — `GetSubstitutionsData` (замены + преподаватели `getByRole(FSTeacher)` + активные кабинеты), `SetRoomOverride` → `RoomAssignmentService::overrideForRange` (bulk по датам, hard-block конфликта → skip+warn). Всё под `Nonce::Substitution` + `Capability::ManageSchedule` (офис). Хуки в `SubstitutionController`.
> - **Фронт:** экран `substitutions.js` — пикер группы + карточка «Замена преподавателя» (список активных + форма назначить/снять) + карточка «Замена кабинета» (форма период+кабинет → Заменить/Снять). nav/screen `substitutions` — **только `FSOffice`** (`TeacherProfileView` ветвит по роли; `fsProfile.substitutions` — только офис).
> - **E2E:** office #55 → экран есть (`screens` содержит `substitutions`, actions отдаются); teacher #54 — нет (гейт по роли). `overrideForRange(1, room, 2026-03-01..04-01)` → 6 занятий получили кабинет, `null` — вернул все 6. Хуки зарегистрированы. Тесты: `SubstitutionCallbacksTest`+`RoomAssignmentServiceTest` расширены; сюита **577 зелёная**.

**Acceptance (замены):** ✅ офис из фронта (профиль → «Замены») временно меняет кабинет занятий (ремонт, период) и/или назначает замещающего педагога (болезнь, период); резолв на чтении (`EffectiveTeacherResolver` + эфф. кабинет `lesson.room_id ?? group.room_id`) отдаёт актуальные значения; по `valid_to`/снятию — возврат к дефолтам группы без правки `groups.*`; конфликты кабинета не проходят (пропускаются с предупреждением).

---

## K. Порядок (рекомендуемый)

> **Статус (2026-07-01): Эпики 1–8 — core готов.** B (D1–D7) ✅. Осталось: T6.7 (карточка ученика), офисная UI-форма замен, курс-пикер КТП (T1.7), 🔶 post-v1 (lock КТП/рубрики, ДЗ-модель, read-only-гейт замены) и **Эпик 9 (кабинеты)** — будущее.

1. **B (решения).** D1–D7 ✅ зафиксированы и реализованы.
2. **Эпик 1 (КТП + reflow→AJAX)** ✅ — движок + AJAX.
3. **Эпик 2 (посещаемость + журнал)** ✅ — ядро ценности препода.
4. **Эпик 3 (проверка)** ✅ — экран очереди + связка.
5. **Эпик 6 («Главная» агрегация)** ✅ — кросс-групповая сводка.
6. **Эпик 4 (индивидуальные занятия)** ✅ → **Эпик 5 (замены)** ✅ (домен; офисная UI-форма — остаток).
7. **Эпик 7 (ученик/родитель)** ✅, **Эпик 8 (тесты, доступ)** ✅.
8. **Эпик 9 (кабинеты/аудитории)** 🔴 — будущее; справочник+админка самодостаточны, пикер свободных кабинетов ждёт Эпик 4 (готов).

> Маппинг на под-этапы `Courses.md` §7: 1→Эпик1, 2→Эпик2, 3→Эпик3, 4→Эпик4, 5→Эпик5, 6→Эпик6+7, 7→Эпик8.

---

## M. Эпик 10 — Bugfix / UX-доводка профиля

> Пул правок из ревью живого профиля. Развилки решены (2026-07-01, см. ниже). Бэкенд-фундамент из
> Эпиков 1–9 переиспользуется; в основном это доводка UI + расширение read-моделей журнала/сводки.

### Развилки — ✅ РЕШЕНО (2026-07-01)

- **D8 — Проверка per-ученик.** «Проверка работ» **заменяется** на «Сводка по ученику»: проверка/оценивание
  идут у каждого ученика отдельно (карточки занятий → деталь работы, где ставится оценка `SaveGrade`).
  Вход «что ждёт проверки» остаётся ворклистом «Главной». Очередь `GetGroupSubmissions` не показывается отдельным экраном.
- **D9 — Сокращения типов работ.** Закреплены в enum **`GradeBadge`** ✅ (`inc/Enums/Course/GradeBadge.php`):
  СР/ПР/ДЗ/КР/ЭКЗ; маппинг practice→ПР, independent→СР, homework→ДЗ, control→КР, ege(+computer)→ЭКЗ.
- **D10 — Навигация групп.** Клик по группе в сайдбаре («Мои группы») открывает **ростер** (новый экран «Группы»),
  а не журнал. Журнал — отдельный пункт nav.
- **D11 — Защита журнала.** Редактировать посещаемость можно только у занятий с датой **≤ сегодня** (сегодня и ранее).
- **D12 — Новая палитра расписания/КТП.** Тема по плану — **зелёный**, закреплено — **синий**,
  выходной (пропуск по расписанию) — **красный**, индивидуальное занятие — **фиолетовый**.

### Пререк (общий для T10.5 / T10.8)

| ID | Задача | Статус | Затрагивает |
|---|---|---|---|
| T10.0a | Enum `GradeBadge` (СР/ПР/ДЗ/КР/ЭКЗ) + мапперы из `WorkType`/`AssessmentKind` | ✅ | `inc/Enums/Course/GradeBadge.php` |
| T10.0b | Привязка работ к занятию в read-модели: `?int $groupLessonId` + `?GradeBadge $badge` в `GradebookEntryDTO`; `AttemptDTO` расширен `groupLessonId` (+`fromArray`); заполнено в `SubmissionGradeSource` (`fromWorkType`) и `AssessmentGradeSource` (`fromAssessmentKind`) | ✅ | `GradebookEntryDTO`, `AttemptDTO`, `*GradeSource` |

### Задачи

| ID | Задача | Слой | Статус | Затрагивает |
|---|---|---|---|---|
| T10.1 | Кнопка «Выход»: клик по шестерёнке (`#profUserGear`) → dropdown вверх (`openCtxMenuRaw {up:true}`), пункт «Выход» → `fsProfile.logoutUrl` | Profile | ✅ | `ProfileViewResolver` (`logoutUrl`), `app.js` (`openUserMenu`), `utils.js` (`up`) |
| T10.2 | Кнопка «Вернуться на главную» справа от notifications в топбаре → `home_url` (серверный `<a>`) | Profile | ✅ | `profile.php`, `ProfileViewResolver` (`homeUrl`) |
| T10.3 | Палитра «Расписание/КТП» (D12): план 🟢, закреплено 🔵, выходной 🔴 | Profile | ✅ | `ktp.js` (легенда), `_core.scss` (`.placed-theme`) |
| T10.4 | Индивидуальные занятия в расписании — фиолетовым (D12) | Profile | ✅ | `dashboard.js` (`schedRow`/week-card, `kind='individual'`→`--t-zachet`) |
| T10.5 | Журнал: «+Индивидуальное занятие» убрано; `JournalService` отдаёт `cell_works[glid][pid]=[{badge,value}]` + `types`; в ячейке — посещаемость (+/Н) + inline-работы по типам (`ПР 8/10`, `ДЗ 5/5`); чекбоксы-фильтры типов; столбцы-работы убраны | Profile+Lms | ✅ | `JournalService`, `journal.js`, `_journal.scss` |
| T10.6 | Защита журнала (D11): нельзя ставить присутствие на занятиях с датой > сегодня — гейт `JournalCallbacks::guardNotFuture` + фронт (`isFutureLesson`, поповер не открывается, колонка-замок) | Lms+Profile | ✅ | `JournalCallbacks`, `journal.js`, `_journal.scss` |
| T10.7 | Экран «Группы» (D10): ростер активных учеников (snapshot-имена) + их индивидуальные занятия; создание инд.занятий здесь (поповер дата/время/тема → `createIndividual`); клик по группе в сайдбаре → `openGroupsFor` (ростер, не журнал). Новый `GetGroupRoster`→`GroupRosterService`; конфиг-блок `roster` | Profile+Lms | ✅ | `groups.js`, `app.js`, `_roster.scss`, `GroupRosterService`, `ProgramCallbacks`, `TeacherProfileView`, `ProfileViewResolver` |
| T10.8 | «Проверка работ» → «Сводка по ученику» (D8): селектор группы+ученика → карточки занятий (дата, тема, работы badge+балл), цветная полоса (🟢 посещён / 🟣 индивидуальное / 🔴 пропуск / серый — не отмечено). `review.js` удалён (очередь не показывается); новый `StudentSummaryService`+`GetStudentSummary` (attendance+gradebook по `group_lesson_id`+`kind`); поглощает T6.7 | Profile+Lms | ✅ | `summary.js`, `_summary.scss`, `StudentSummaryService`, `ProgramCallbacks`, `app.js`, `TeacherProfileView`, `ProfileViewResolver` |
| T10.9 | Деталь работы из карточки сводки (модалка): условия задач, ответ ученика, вердикт+баллы, итоги; оценивание (`SaveGrade`/`ReturnSubmission`) для сдач; фолбэк свободного ответа. `WorkDetailService` (submission + attempt) + `GetWorkDetail` (teacher-guard). Правильные ответы НЕ показываются (чекеры/`ExamPayloadFilter` их не отдают) → уточнение в Эпик 11 | Profile+Lms | ✅ | `WorkDetailService`, `GradingCallbacks`, `summary.js` (модалка), `_summary.scss`, `ProfileViewResolver` |

**Acceptance:** профиль имеет выход/возврат-на-главную; расписание в новой палитре с фиолетовыми индивидуальными;
журнал показывает результаты работ в ячейках по типам с фильтрами и не даёт отмечать будущие занятия; «Мои группы» —
ростер + создание индивидуальных; «Сводка по ученику» вместо очереди проверки, с деталью работы и оцениванием.

---

## N. Эпик 11 — Backlog / доводка (после Bugfix)

> Собранные незакрытые хвосты Эпиков 1–9, пересобранные с учётом решений Эпика 10.
> **Поглощены Эпиком 10:** T6.7 (карточка ученика) → T10.8; T3.3 (ворклист→очередь) → вход через ворклист «Главной» + T10.8.

| ID | Было | Задача | Статус |
|---|---|---|---|
| T11.1 | T1.7 | Курс-пикер в КТП (назначение курса из пустого состояния) — нужен endpoint «список курсов предмета» | ✅ (`CourseAssignmentService::coursesForGroup` + `GetSubjectCourses`; `ktp.js` пустое состояние = селект курсов + «Назначить». Отдельный конфиг-блок `courses` с `Nonce::AssignCourse` (assign_course требует его, не SaveSchedule) — заодно починен латентный рассинхрон нонса) |
| T11.2 | T9.13 | Показать эффективный кабинет в журнале/КТП (в «Главной» уже есть) — увязать с переработкой журнала T10.5 | ✅ (`JournalService`/`ScheduleService::getCalendar` отдают `room` = lesson.room_id ?? group.room_id → имя; `journal.js` `.hd-room`, `ktp.js` `.pt-room`) |
| T11.3 | T9.12 | Пикер **свободных** кабинетов при создании индивидуального занятия (`listFreeRooms` готов) — в новом экране «Группы» (T10.7) | ✅ (`ScheduleService::freeRoomsForGroup` + `GetFreeRooms`; `createIndividualLesson` принимает `roomId`; `groups.js` селект кабинета с подгрузкой по дате/времени+предмету) |
| T11.4 | T9.11 | Проверка конфликта кабинета при `reflow`/`pin` (перенос дат группы) | ✅ pin: `pinToDate` hard-block (`isFree`, эфф. кабинет lesson.room_id ?? group.room_id). reflow: `isBusy` получил `excludeGroupId` (исключает всю свою группу — не ловит собственные переносимые занятия); `SessionCalendarService::reflow` снимает кабинет со слота, занятого ДРУГОЙ группой, считает конфликты (лог + возврат `int`) → `ajaxReflowSchedule` отдаёт `room_conflicts` → `ktp.js` тост |
| T11.5 | T2.9 | Помесячная пагинация журнала — пересмотреть после переработки журнала (T10.5); сейчас гориз. скролл | ✅ (клиентская пагинация в `journal.js`: `computeMonths`/`lessonsForMonth`/`monthLabel`/`changeMonth` группируют `data.lessons` по `YYYY-MM`; шапка `.j-monthnav` = `‹`/`›` `.jm-arrow` + метка месяца `.jm-label` (по умолч. текущий месяц, иначе последний прошедший); рендерятся только занятия месяца, `.jm-count` считает их; фолбэк «в этом месяце нет занятий». SCSS: сайзинг стрелок + `.jm-group`. Бэкенд не тронут — `JournalService` отдаёт все занятия) |
| T11.6 | T4.3 | Сдвиг нерассказанного хвоста по `status` (held/cancelled/moved) в `reflow` | ✅ (`GroupLessonRepository::applySlots` учитывает `LessonStatus`: `held` фиксирует свою дату (не переписывается), но ЗАНИМАЕТ слот последовательности → хвост раскладывается после проведённого; `cancelled`/`moved` (`freesSlot()`) слот НЕ тратят → нерассказанный хвост сдвигается вперёд; `scheduled` — раздаётся по слотам. `SessionCalendarService::reflow` считает нехватку слотов только по потребляющим строкам (scheduled+held). +3 теста в `GroupLessonRepositoryTest`. Писателя статусов пока нет — увязка с посещаемостью/UI в T11.7) |
| T11.7 | T1.8, T3.4, T5.7, T7.6 | **post-v1 (🔶 D7):** lock КТП после публикации; рубрики/аннотирование PDF/пир-ревью; серверный read-only-гейт журнала оригинала в период замены; отдельная модель ДЗ | 🟡 частично | **✅ read-only-гейт (T5.7)**: `GroupAccessGuard::canWriteJournal` — запись в журнал (посещаемость/оценки/оценивание попытки) закреплена за фактическим преподом; в период активной замены оригинал → read-only (чтение через `canManage` остаётся). Разведены read/write: журнальные write-callbacks (`JournalCallbacks` посещаемость, `GradingCallbacks` SaveGrade/ReturnSubmission, `GradeAttemptCallbacks`) → `canWriteJournal`; чтение (журнал, деталь работы, очередь, gradebook) и КТП-редактирование (`ProgramCallbacks`) → `canManage`. +5 тестов. **✅ T1.8 lock КТП**: `groups.program_locked_at` + `ScheduleService::isProgramLocked/publish/unpublish` + `ProgramCallbacks::denyIfProgramLocked` (8 мутаторов) + `ktp.js` публикация/бейдж/off-drag. **Остаётся 🔶:** T7.6 → ➡️ Эпик 12 (T12.2/T12.3, D13 — per-work дедлайны, без отдельной сущности); T3.4 рубрики/аннотирование PDF/пир-ревью — отдельное планирование |
| T11.8 | T10.9 | Правильные ответы в детали работы: per-template correct-answer resolver (чекеры отдают только вердикт, `ExamPayloadFilter` вырезает `correct_answer`) — показывать эталон рядом с ответом ученика | ✅ (`CorrectAnswerResolver::resolve(taskId)` читает `fs_lms_meta` по `TaskTemplate`: standard/common/audio→task_answer, triple, choice→correct-опции, matching, ordering, fill→FillTextParser; ручные→null. `WorkDetailService` кладёт `correct`; `summary.js` рендерит «Правильный ответ». +8 тестов) |
| T11.9 | T10.9 | Оценивание попытки экзамена из детали (сейчас `gradable=false`, read-only): проброс `GradeAttempt`/`GradeBatchTask` в модалку сводки | ✅ (пооответно: `WorkDetailService::fromAttempt` даёт `attempt_id`+`task_id`; блок `attemptGrade` (нонс `GradeAttempt`); `summary.js` — контрол балл/верно/коммент на задачу → `gradeAttempt` → `AutoGradeService::finalize` пересчитывает total/status. `GradeAttemptCallbacks` получил `canManage`-guard по `attempt.groupId`) |

> **Эпик 11 закрыт (2026-07-02).** 8 из 9 задач ✅ (T11.1–T11.6, T11.8, T11.9). T11.7 — **частично**: из 4 post-v1 под-задач сделаны **T5.7** (серверный read-only-гейт журнала в период замены — `GroupAccessGuard::canWriteJournal`) и **T1.8** (lock КТП после публикации — `groups.program_locked_at`); осознанно отложены как отдельные greenfield-мини-эпики по решению **D7 (post-v1)**: **T7.6** (отдельная модель ДЗ) и **T3.4** (рубрики / аннотирование PDF / пир-ревью).
>
> **Итог:** кабинеты кабинетов (T11.2–T11.4), UX-доводка журнала (T11.5), status-aware reflow (T11.6), деталь работы с эталоном и оцениванием попытки (T11.8/T11.9), read/write-разделение доступа с read-only оригинала в замене (T5.7) и публикация-lock КТП (T1.8). Заодно закрыты латентные баги: рассинхрон нонса курс-пикера (T11.1), недогард `remove`/`schedule` в `ProgramCallbacks` (T1.8), `isBusy` excludeGroupId (T11.4). PHPUnit **612 зелёных**, ESLint чист, бандлы собраны.
>
> **Остаётся post-v1 (🔶 D7, вне Эпика 11):** T7.6 модель ДЗ → **спланирован в Эпик 12** (T12.2/T12.3, решение D13); T3.4 рубрики/PDF-аннотирование/пир-ревью — отдельное планирование (для рубрик сначала разрешить конфликт с D4 «только сырые баллы»).

**Порядок Эпика 10:** сначала быстрые (T10.1–T10.4, T10.6) → пререк T10.0b → журнал T10.5 → группы T10.7 → сводка+деталь T10.8/T10.9.

---

## O. Эпик 12 — Кабинет: дедлайны работ + доводка КТП/навигации

> Пул от 2026-07-02 (обсуждение). Поглощает **T7.6** (модель ДЗ) из post-v1.
> **T3.4** (рубрики / PDF-аннотирование / пир-ревью) в эпик НЕ входит — отдельное планирование.

### Решения

- **D13 — Модель ДЗ = per-work дедлайны занятия (закрывает T7.6).** Отдельной сущности ДЗ **нет** (осознанно не выбрана). Хранение: `group_lessons.work_deadlines` JSON `{work_id: 'Y-m-d H:i:s'}` — аналог `step_settings_overrides`. По умолчанию дедлайна нет. Статусы **вычисляются, не хранятся**: ученику «Просрочено» при `now > due` без сдачи — решать всё равно можно (hard cutoff нет); сдача после срока → балл + **постоянная** метка «Просрочено» у учителя (`submitted_at > due_at`). Legacy `homework_due_at` остаётся lesson-level фолбэком (не мигрируем), per-work при наличии выигрывает; в UI редактируются только per-work. **Lock КТП (T1.8) дедлайны НЕ блокирует** (delivery, не структура). Метка «Просрочено» — во всех витринах учителя, включая ячейки журнала (`cell_works`).
- **D14 — «Продолжение темы» = связанная строка, НЕ мультидаты и НЕ независимый дубль.** Строка `group_lessons` = одно датированное занятие (посещаемость, статус held/cancelled, слот reflow, кабинет — всё per-дата). Вторая дата темы = новая строка с `continued_from_id` (тот же `lesson_id` — контент общий): КТП считает тему **одной** (банк, счётчик «распределено», номер «№N · 1/2 / 2/2»), журнал — два столбца (заголовок «№N (прод.)»); работы/дедлайны по умолчанию на части 1, у продолжения — свои `extra_works` при желании. `duplicateLesson` (независимая копия) остаётся в бэке, в UI КТП не выносится.

| ID | Источник | Задача | Статус |
|---|---|---|---|
| T12.1 | обсуждение | Админ = суперсет ролей: в «Настройки → Роли» строка администратора — все чекбоксы **checked+disabled** (в БД роли не пишем); `/profile/` админа = офисная витрина (все группы, как FSOffice) вместо редиректа в wp-admin (`ProfileController:78` / `ProfileViewResolver:112`) | ✅ (`UserRole::primaryForCabinet()` — чистый admin без LMS-ролей → `FSOffice`; дуал admin+LMS резолвится как раньше через `primary()`. `ProfileController` берёт роль из `resolver->context()` — единый источник, дублирование `primary()`-вызова убрано. Шаблон «Роли»: `$checked = $is_admin || in_array(...)` — визуально, бэкенд и так блокирует запись роли admin. +5 тестов (`UserRoleTest`). Проверено E2E через `wp eval` на реальном админе (roles=`administrator,tutor_instructor`) → `role=lms_office`, `view=TeacherProfileView`, nav включает `substitutions`) |
| T12.2 | T7.6 | Дедлайны работ — домен (D13): колонка `work_deadlines` + repo/DTO; `SubmissionService` — late per-work (сейчас от `homeworkDueAt`, строки 76/200); `LearnerService` — дедлайны+статусы ученику/родителю; учителю — метка «Просрочено» в сводке/детали/ячейках журнала | ✅ (`group_lessons.work_deadlines` JSON + `GroupLessonRepository::setWorkDeadlines`; `GroupLessonDTO::deadlineForWork($workId)` — per-work, фолбэк на legacy `homeworkDueAt`; `SubmissionService::submit/submitBatch` резолвят через него (был баг: `allowLate=false` блокировал по lesson-level дате даже когда per-work дедлайн в будущем — теперь блокирует по эффективному). Метка «Просрочено»: `GradebookEntryDTO::isLate` (из `SubmissionDTO::isLate()`, уже существовал) → `SubmissionGradeSource` → `JournalService` (`cell_works[].overdue`) + `StudentSummaryService` (`works[].overdue`) + `WorkDetailService` (`due_at`/`is_late` в детали). `LearnerService`: дедлайны стали per-work (не per-lesson), прошедшие НЕ скрываются (помечены `overdue`), уже сданные — не напоминаются (`SubmissionRepository::listByStudentAndGroupLesson`). Фронт: `journal.js`/`.cw.overdue`, `summary.js`/`.sum-work.overdue`+модалка `.smh-late`, `learner.js`/`.prof-dl-overdue`. +14 тестов (`GroupLessonDTOTest` ×8, `SubmissionGradeSourceTest` ×2, +2 `SubmissionServiceTest`, +2 `LearnerServiceTest`). E2E: миграция применена, полный repo→DTO round-trip проверен через `wp eval`. **Вне скоупа:** легаси очередь проверки Group Cockpit (`ReviewQueueService`, уже имеет `is_late`) — отдельная нетронутая страница, не входит в явный список витрин задачи |
| T12.3 | T7.6 | Дедлайны работ — КТП UI: клик по размещённой теме → поповер «работы занятия + datetime-local» (default пусто); AJAX get/save (2 хука); работает и при lock КТП | ✅ (`AjaxHook::GetWorkDeadlines/SaveWorkDeadlines` → `ProgramCallbacks::ajaxGetWorkDeadlines/ajaxSaveWorkDeadlines` (в `schedule`-конфиг-блоке, `Nonce::SaveSchedule`, БЕЗ `denyIfProgramLocked` — работает при lock); get отдаёт эффективные работы (`EffectiveWorksResolver`) + per-work override (не legacy-фолбэк — редактируется только явное значение); save принимает `{work_id:'Y-m-d H:i:s'\|''}` (пустая строка снимает override). `ktp.js`: клик по `.placed-theme` (не блокируется lock, только drag/drop блокируются) → `openCtxMenuRaw`-поповер со списком работ + `datetime-local`; конвертация MySQL↔input формата. +7 тестов (`ProgramCallbacksTest`). E2E: хуки `wp_ajax_get_work_deadlines`/`save_work_deadlines` подтверждены зарегистрированными в реальном WP) |
| T12.4 | обсуждение | КТП: время занятия в ячейке календаря — `periodMeta` → `lessonTimes {date: '16:00–17:30'}` (слоты уже несут `scheduled_at`/`ends_at`) → рендер в `kd-lesson` | ✅ (`SessionCalendarService::periodMeta` строит `lessonTimes[date]` из уже генерируемых слотов (первое совпадение при 2 занятиях в день); `ScheduleService::getCalendar` пробрасывает; `ktp.js` `.kd-lesson` рендерит время вместо «урок»; CSS: nowrap+ellipsis защита от переполнения ячейки. Домен проверен E2E через `wp eval` (реальная генерация слотов на сид-данных, затем откат); класс раньше не имел тестов — добавлен `SessionCalendarServiceTest` (3 теста). Визуально в браузере не проверено — нет доступного browser-tool в этой среде; в БД нет курса/группы для полного рендера непустого состояния КТП) |
| T12.5 | обсуждение | КТП: две темы на одно занятие — ячейка рендерит стек (`byDate` → массив, сейчас перезапись); pin на занятый день разрешён; room-check игнорирует занятия **своей** группы (аналог `excludeGroupId` T11.4); reflow НЕ стекует (1 тема = 1 слот, стек только ручным pin); журнал: 2 столбца на дату — ок | ✅ (`ktp.js` `byDate[ds]` → массив (стек вместо перезаписи), рендер всех тем дня; `.kal-cell` растёт естественно (flex-column). `RoomAvailabilityService::isFree`/`ScheduleService::pinToDate` получили `excludeGroupId` (аналог T11.4) — своя группа не конфликтует сама с собой при пиновке на занятый день; заодно устранена рассинхронизация: `reflow` уже исключал свою группу, `pin` — нет. `reflow`/`applySlots` НЕ стекуют по конструкции (unpinned-строки получают строго уникальные слоты, `$i++` на каждую) — подтверждено чтением кода, изменений не потребовалось. Журнал: колонки уже ключуются по `group_lesson_id`, не по дате — 2 занятия одной даты уже давали 2 столбца, без изменений. +2 теста (`ScheduleServiceTest`, `RoomAvailabilityServiceTest`). E2E через `wp eval`: своя группа — `isFree=true`, чужая — `isFree=false`) |
| T12.6 | обсуждение | Продолжение темы на вторую дату (D14): `continued_from_id` (миграция + DTO/repo), контекст-меню «Продолжить на другую дату» → drag копии, рендер частей «1/2 · 2/2» в КТП и «(прод.)» в журнале, счётчик тем по уникальным | ✅ (`group_lessons.continued_from_id` (миграция) + `GroupLessonDTO::continuedFromId`; `ScheduleService::continueLesson()` — новая ПИННУТАЯ непристроенная строка со связью (отклоняет продолжение уже-продолжения); `ScheduleService::numberThemes()` — origin+continuation получают ОБЩИЙ `n` + `part`/`total_parts` («1/2 · 2/2»), orphan-продолжение (удалённый оригинал) деградирует до самостоятельной темы без падения. `AjaxHook::ContinueProgramLesson` → `ProgramCallbacks::ajaxContinueProgramLesson` (блокируется lock КТП — структурное изменение, не delivery). `ktp.js`: «⋮» на теме (только part=1) → `openThemeActionsMenu` → drag копии из банка; `themeCardHtml`/`placedThemeHtml` рендерят part-tag; счётчик банка дедуплицирует по `n`. `JournalService`: `is_continuation` флаг на столбце → `journal.js` метка «(прод.)». +9 тестов (`ScheduleServiceTest` ×5, `ProgramCallbacksTest` ×4). E2E через `wp eval` на реальных репозиториях: continuedFromId корректно связан, нумерация «1/2·2/2» подтверждена, чейнинг отклонён, очистка чистая) |
| T12.7 | обсуждение | Навигация: скрыть пункт меню «Группы» (экран/роут живы; вход — клик по группе в сайдбаре «Мои группы», переход D10 уже работает, `app.js:92-99`) | ✅ (`TeacherProfileView::build()` — пункт `groups` убран из `$nav`, остался в `$screens` → маршрут/экран живы, JS не тронут. Общий для FSTeacher/FSOffice. +3 теста `TeacherProfileViewTest`) |
| T12.8 | обсуждение | Журнал: дропдаун группы в шапке в стиле КТП (`kp-btn` + `openCtxMenu`; сайдбарный вход остаётся); «Сводка по ученику»: группа и ученик тем же паттерном вместо голых `<select class="sum-select">` | ✅ (журнал: `.jm-group` → `#jGroupBtn` (`kp-btn`) + `openGroupMenu()`, идентично `ktp.js`; sidebar-вход не тронут. Сводка: два `<select>` → `.prof-ktp-pick`+`kp-btn` (группа: те же `GROUP_COLORS`; ученик: `AVA_COLORS`+инициалы, как в `journal.js`), `openCtxMenu` на оба; disabled-состояние при пустом ростере — новый `.kp-btn:disabled` в `_core.scss` (был неиспользуемый `kp-empty` — не тот семантический смысл, не взят). `.sum-select`/`.sum-pick` CSS удалены как мёртвые) |

**Порядок:** quick wins T12.1, T12.4, T12.7, T12.8 → ядро T12.2 → T12.3 → КТП-фичи T12.5, T12.6.

**Acceptance:** админ в `/profile/` видит все группы, в «Ролях» отмечен всеми ролями; у любой работы занятия из КТП ставится свой дедлайн (дата+время); ученик после срока видит «Просрочено», но может решать; учитель видит балл + метку «Просрочено» даже после сдачи; в календаре КТП у занятия время («урок 16:00–17:30»); на одно занятие можно положить две темы; тему можно продолжить на второй день без дубля в банке тем; в меню нет пункта «Группы»; журнал и сводка — с дропдаунами в стиле КТП.

> **Эпик 12 закрыт (2026-07-02).** Все 8 задач ✅. Ход: quick wins (T12.1 админ-суперсет, T12.4 время в ячейке КТП, T12.7 скрыт пункт «Группы», T12.8 дропдауны журнал/сводка) → ядро дедлайнов (T12.2 домен D13 + T12.3 КТП-поповер) → КТП-фичи (T12.5 стек тем, T12.6 продолжение темы D14).
>
> **Заодно закрыты латентные баги:** `pinToDate` не исключал свою группу из room-conflict (T12.5, reflow уже исключал — рассинхрон); `ajaxRemoveLessonFromProgram`/`ajaxSaveLessonSchedule` не проверяли `canManage` (T12.1-adjacent, обнаружено при T1.8); `UserRole::primary()` резолвил чистого administrator в `Student` вместо офисной витрины.
>
> **Итог:** +43 теста за эпик (612 → 655), PHPUnit зелёный на каждом шаге, ESLint чист. Каждая PHP-фича с новой колонкой проверена E2E через `wp eval` на реальных репозиториях (не только моки) — миграции применены и подтверждены в БД.
>
> **Осталось вне эпика:** T3.4 (рубрики/PDF-аннотирование/пир-ревью) — по-прежнему неспланировано; рубрики требуют сначала разрешить конфликт с D4 («только сырые баллы, без оценок»).

---

## P. T3.4 retired → вложение к сдаче (2026-07-02)

> При планировании T3.4 выяснилось: рубрики/критерии и PDF-аннотирование **не отвечают реальной модели** системы — есть только сырые баллы за задачи/экзамен (D4), никакой композитной/взвешенной оценки, которую можно было бы раскладывать на критерии. Пир-ревью тоже не запрошен. Реальная потребность — **вложение (фото) к сдаче**, причём с двух сторон.

- **D15 — T3.4 (рубрики/критерии/PDF-аннотирование/пир-ревью) ретируется, не переносится ни в какой эпик.** Модель оценивания (D4: только сырые баллы) не предполагает композитных/взвешенных оценок — рубрики строить не над чем. PDF-аннотирование и пир-ревью не запрошены пользователем. Реальная потребность из исходного T3.4 — вложение (фото) к сдаче — решена отдельно (см. T13.1) без критериев/рубрик/PDF.

| ID | Задача | Статус |
|---|---|---|
| T13.1 | Вложение (фото) к сдаче задания — 2 сценария: (а) **учитель прикладывает фото к условию задачи** при создании; (б) **ученик прикладывает фото решения** как ответ, видимое учителю при оценивании | ✅ (а) **уже работало, кода не потребовалось**: `ConditionField` — это `wp_editor()` с `media_buttons: true` (родная кнопка «Добавить медиафайл»), вставленное фото сохраняется как `&lt;img&gt;` в `post_content`, проходит `wp_kses_post`, корректно рендерится ученику. (б) **реальный пробел закрыт**: загрузка/хранение уже работали (форма одиночной сдачи → `SubmissionService::submit()` → `MediaManager::uploadFromRequest()` → `attachment_id`), но `WorkDetailService::fromSubmission()` не отдавал `attachmentId` — учитель не видел уже присланное фото. Добавлены `attachment_url`/`attachment_mime` (`MediaManager::url()` + `get_post_mime_type()`), `summary.js` рендерит превью `&lt;img&gt;` для изображений или ссылку «Открыть файл» для остального (jpeg/png/gif/pdf/doc/docx/txt — уже разрешённый whitelist `MediaManager`). Batch/квиз-сдача — вне скоупа (файлов там нет вообще, отдельная задача при потребности). +2 теста (`WorkDetailServiceTest`, новый класс — заодно закрыт файл с нулевым покрытием). PHPUnit 657 зелёных, ESLint чист. E2E через `wp eval`: `MediaManager::url()`/`get_post_mime_type()` подтверждены на реальном WP-вложении |

---

## Q. Эпик 13 — «Задание с развёрнутым ответом» (ручная проверка) + критерии

> Кейс: номера ЕГЭ/ОГЭ с проверкой решения человеком — фото решения по математике (ч.2), презентация/документ (ОГЭ инф. №13), программа `.py` (ОГЭ инф. №15). Ученик прикладывает файлы (массив) и/или пишет текст; учитель прикладывает материалы к условию и эталонное решение (код/текст, ученику не отдаётся); проверка ТОЛЬКО ручная. Заодно — оценивание по критериям.

### Решения

- **D16 — один универсальный тип «Развёрнутый ответ» (`file_answer_task`), не тип-на-предмет.** Различия кейсов (фото/pdf vs pptx/docx vs py) — конфигурация допустимых форматов, не новые типы. Поля авторинга: условие (`ConditionField`, инлайн-картинки уже умеет), **материалы задания** (новое `FileAttachmentsField` — мульти-пикер медиабиблиотеки, ученику отдаются ссылками), **решение для проверяющего** (`solution_text` rich-text + `task_code` — оба ключа уже вырезаются `ExamPayloadFilter`), **критерии** (D17). Ответ ученика = JSON `{"text": "...", "files": [attachment_ids]}` — **двухшаговая загрузка** (отдельный AJAX «загрузить файл ответа» → `attachment_id` → id кладётся в обычный JSON-ответ), поэтому `save_attempt_answer`/batch-сдача остаются JSON-эндпоинтами без multipart, схема `assessment_answers` под файлы не меняется. Ручная проверка «из коробки»: чекер НЕ регистрируется (`TaskCheckerRegistry`/`AutoGradeService::get()` → null → `is_correct=NULL` → pending), `CorrectAnswerResolver` default → null. Хранение — медиабиблиотека WP как есть (публичность по прямому URL принята осознанно, защищённая раздача — отдельная задача при необходимости). `MediaManager`: лимит **20 МБ**, whitelist + webp/heic/pptx/py.
- **D17 — критерии оценивания = point-sum, БЕЗ конфликта с D4.** Официальные критерии ЕГЭ/ОГЭ — разложение сырого балла на слагаемые («К1: 0–2, К2: 0–1», итог = сумма), а НЕ взвешенная рубрика. У задачи типа «Развёрнутый ответ» — опциональный список критериев `{label, max_points}`; учитель ставит баллы по каждому, балл задачи = сумма (те же сырые баллы). **Весов, процентов и перевода в отметку НЕТ** — запрет D4 в силе; критерии — декомпозиция того же сырого балла. Раскладка сохраняется (`criteria_scores` JSON на `assessment_answers` и `submissions`) и видна ученику в результатах («К1: 1/2 · К2: 2/2»). Без критериев — обычное одно поле балла.

| ID | Задача | Статус |
|---|---|---|
| T13.2 | `MediaManager`: лимит 10→20 МБ; whitelist + `webp`/`heic`/`heif` (фото с телефонов), `pptx` (ОГЭ №13), `py` (ОГЭ №15; `mime_content_type` для .py обычно `text/plain` — уже разрешён, добавить `text/x-python` на всякий) | ✅ (+scoped `upload_mimes`-фильтр ТОЛЬКО на время нашей загрузки — `.py`/`.heic` нет в дефолтном `wp_check_filetype`, глобально загрузки не ослаблены; `accept` формы одиночной сдачи расширен) |
| T13.3 | Авторинг: `TaskTemplate::FileAnswer = 'file_answer_task'` + `FileAnswerTaskTemplate` (условие / материалы / решение для проверяющего / критерии); новые поля `FileAttachmentsField` (мульти-пикер по образцу `AudioField` + `wp.media` в `task-fields.js`) и `CriteriaField` (повторяемые строки label+max_points по образцу `OptionsField`); чекер НЕ регистрируется | ✅ (enum case + class()/label(); шаблон с полями `task_condition`/`task_materials`/`solution_text`/`task_code`/`task_criteria` — ключи решения уже в strip-листе `ExamPayloadFilter`; `task-fields.js` `bindMaterials` (wp.media multiple, без дублей) + `bindCriteria` (add/remove/reindex); SCSS на существующих placeholder'ах. +6 тестов (`FileAnswerFieldsTest`). E2E: реестр отдаёт шаблон, чекер = NULL (ручная), санитайзеры отбрасывают мусор) |
| T13.4 | Ученик, плеер урока: `LessonPlayerService::buildWidgetData` → `{type:'file_answer', materials:[{url,name}]}`; виджет в `task-widget.js` (текст + мультизагрузка с чипами, удаление до сдачи); `AjaxHook::UploadAnswerFile` (нонс + членство в группе, `MediaManager`); `collectAnswer` → `{"text","files"}` | ✅ (`AjaxHook::UploadAnswerFile`+`Nonce::UploadAnswerFile` → `SubmissionCallbacks::ajaxUploadAnswerFile` (member-check `isMemberEver` по группе занятия, priv-only — E2E: nopriv НЕ зарегистрирован); отдаёт `{attachment_id,url,name,mime}`. `fs_lms_player_vars` + `upload_action`/`upload_nonce`. `LessonPlayerService`: case FileAnswer + `buildMaterialsData()` (решение/критерии в widget-data НЕ попадают); условие — дефолтная ветка `task_condition`. `task-widget.js`: `buildFileAnswerWidget` — материалы ссылками, textarea, скрытый file-input + «Прикрепить файлы», чипы с удалением, последовательная загрузка со статусом, `collectAnswer` → `{"text","files":[ids]}`; vars берёт из player/assessment-глобала (задел под T13.5). SCSS на фронт-токенах. +4 теста (`SubmissionCallbacksTest`)) |
| T13.5 | Ученик, страница контрольной (`attempt.php` + `assessment.js`): для задач шаблона — file-блок + текст + материалы задания; загрузка тем же `UploadAnswerFile`; сохранение JSON через существующий `save_attempt_answer`; проверить `ege-computer.php` (answersOnly-вид — file-задачи туда не относятся, graceful) | ✅ (у попытки НЕТ `group_lesson_id` (только nullable `group_id`) → `ajaxUploadAnswerFile` получил второй контекст доступа: `attempt_id` → «СВОЯ попытка» (`attempt.studentPersonId === person.id`); `AssessmentPageController::buildTaskViews()` готовит per-task `{template, materials}` (решения/критерии на страницу НЕ отдаются); `attempt.php` — `data-template="file_answer"` + материалы ссылками + file-блок с чипами (+textarea остаётся текстовой частью); `assessment.js` — `answerValue()` (для file-задач ответ = JSON `{"text","files"}`, для остальных — как раньше), `bindFileAnswers()` (upload по attempt_id → чип → автосейв через существующий `save_attempt_answer`); `fs_lms_assessment_vars` + `uploadAnswerFile` action/nonce. `ege-computer` — отдельный feature-flagged модуль answersOnly-вида, file-задачи туда не относятся. +2 теста (own/foreign attempt)) |
| T13.6 | Учитель: `WorkDetailService` (оба пути) парсит `{text,files}` → файлы (url/name/mime) в per-task деталь + `criteria` задачи + сохранённая раскладка; `summary.js` — рендер файлов + criteria-строки в грейдинге (сумма → балл); миграция `criteria_scores` JSON на `assessment_answers`+`submissions`; `GradeAttemptCallbacks`/`gradeBatchTask` принимают раскладку | 🔴 L |
| T13.7 | Ученик видит результат: раскладка по критериям в результате попытки/работы + свои загруженные файлы | 🔴 S–M |
| T13.8 | Валидация: тесты по слоям, миграция в Docker, E2E через `wp eval`, прогон suite | 🔴 S |

**Порядок:** T13.2 → T13.3 → T13.4/T13.5 → T13.6 → T13.7 → T13.8.

**Acceptance:** методист создаёт задачу «Развёрнутый ответ» с материалами (файлы), эталонным решением (код/текст, ученику не видно) и критериями; ученик в уроке и в контрольной видит условие + материалы, пишет текст и/или прикладывает несколько файлов (фото/pdf/pptx/docx/py, до 20 МБ); ответ уходит в pending без автопроверки; учитель в детали работы видит текст + файлы ученика и ставит баллы по критериям (сумма = балл задачи) или один балл, если критериев нет; ученик видит балл и раскладку по критериям.

