# Этап: Личные кабинеты (преподаватель → ученик)

> Черновик спеки. Сначала ЛК преподавателя, затем ЛК ученика/родителя.
> Документ описывает **что строим**, **на какие уже готовые данные ложимся** и **какие доменные куски реально новые**.
> Решения, помеченные 🔶, ещё обсуждаются — см. §9 «Открытые вопросы».

**Легенда статуса:** ✅ есть в коде · 🟡 есть данные/логика, нет UI · 🔴 нет, надо строить · 🔶 развилка.

---

## 0. Контекст и место в архитектуре

Авторинг курса (методист собирает Course → Module → Lesson → Step в Course Builder) — **завершён**.
Этот этап — **работа педагога**: ведение групп по расписанию, журнал, оценивание, индивидуальные занятия и замены. Плюс зеркальный кабинет ученика/родителя.

**Раскладка по модулям (`.docs/ModularArchitecture.md` §2.3, §4.1):**

- **Доменные куски** (посещаемость, «занятие», замена, взвешивание оценок, вывод `reflow` в AJAX, журнал, проверка) → модуль **`Lms` (ядро)**. Кокпит группы (`/group/`) — уже часть `Lms`.
- **`Cabinet` (лист, новый)** — персональная витрина: сквозной календарь по всем группам препода, единый ворклист «заполнить/проверить», домашняя страница ученика. **Только фронт поверх данных `Lms`.** При выключении `Cabinet` ЛМС/кокпит/заявки работают ровно как сейчас (инвариант §4.1).

> Для пользователя это «один кабинет»: `Cabinet` — оболочка и навигация, глубокие экраны (журнал/проверка/КТП) рендерит `Lms`-кокпит и встраивается в навигацию кабинета.

---

## 1. Фундамент (что уже построено)

### Сущности и хранилище

| Сущность | Где | Ключевое | Статус |
|---|---|---|---|
| Группа | `fs_lms_groups` (DB) | `teacher_id`, `meetings` (расписание-шаблон JSON), `subject_key`, `academic_period_id`, `course_id` *(пока не используется)* | ✅ |
| Учебный период | `fs_lms_academic_periods` (option) | `start_date`, `end_date`, `holidays[]`, `is_current` | ✅ |
| Членство ученика | `fs_lms_student_records` | `student_person_id`, `parent_person_id`, `group_id`, `status` | ✅ |
| Человек / PII | `fs_lms_persons` + `fs_lms_person_documents` (шифр.) | ФИО, школа/класс; контакты/документы — libsodium | ✅ |
| Тема в расписании группы («занятие») | `fs_lms_group_lessons` | `lesson_id?`, `position`, `scheduled_at`, `ends_at`, `is_pinned`, `label`, `visibility` | ✅ |
| Прогресс по шагам | `fs_lms_lesson_progress` | статус каждого step по ученику | ✅ |
| Сдачи работ | `fs_lms_submissions` | оценка, статус (assigned/submitted/graded), фидбек, дедлайн | ✅ |
| Контрольные/ЕГЭ | `fs_lms_assessment_attempts` + `_answers` | попытки, баллы, результат | ✅ |
| Интерактивные задачи | `fs_lms_task_attempts` | ответ, верность, пофрагментный фидбек | ✅ |

### Сервисы/контроллеры, на которые опираемся

- **`SessionCalendarService`** 🟡 — `generate(groupId)`: разворачивает `meetings` × период (минус каникулы) в датированные слоты; `reflow(groupId)`: раскидывает темы по слотам **по порядку**, `is_pinned` сохраняют дату. **Не подключён к UI** (нет AJAX).
- **`MeetingsNormalizer`** ✅ — приводит расписание к суперсету `{day,start,end,weekday,time,duration_min}`.
- **`GroupCockpitController`** (`/group/?gid=N`) ✅ — вид препода (программа, ростер, лог событий) и вид ученика; доступ через **`GroupAccessGuard::canManage()/isMemberEver()`**.
- **`SubmissionRepository::listQueueByGroup()`** 🟡 — готовая очередь работ «на проверку».
- **`ExamResultService::buildForStudent()`** ✅ — результат контрольной для ученика (без раскрытия ответов).
- **`LessonProgressService::getStepStatuses()`** ✅ — статусы шагов ученика (works/assessments резолвятся из фактов).
- **`GradebookEntryDTO` + `GradeSourceInterface`/`SubmissionGradeSource`** 🟡 — агрегация оценок (без взвешивания по типу).

> Легаси-группы на `wp_options` (`StudentGroupDTO`/`StudentGroupRepository`) — мёртвая ветка, строим на DB `fs_lms_groups`.

---

## 2. Доменные пробелы (что реально новое)

| Пробел | Статус | Раздел |
|---|---|---|
| **Посещаемость** (present/absent/late/excused, баллы, %) | 🔴 | §3.2 |
| **«Занятие»** как точка привязки посещаемости/замены/отработки (статус held/cancelled, teacher-override, тип, scope) | 🔴 (эволюция `group_lessons`) | §3.1 |
| **Замена преподавателя** (override + grant на период, time-bound доступ) | 🔴 | §3.3 |
| **Индивидуальное / отработочное занятие** | 🔴 | §3.4 |
| **Взвешивание оценок** по типу работы | 🔴 | §3.5 |
| UI: календарь, КТП-распределение, журнал-сетка, экран проверки, карточка ученика | 🔴 | §4 |
| Вывод `SessionCalendarService.reflow()` в AJAX | 🟡→🔴 | §4.2 |

---

## 3. Доменная модель (изменения данных)

> По правилам `CLAUDE.md`: новые таблицы и изменения колонок — в `Migration_1_0_0::up()/down()`, отдельные файлы миграций не создаём. Все имена — через `TableName` enum, доступ — `WPDBRepositories/*`, перенос — DTO, логика — `Services/*`.

### 3.1. «Занятие» — эволюция `fs_lms_group_lessons` 🔶 (развилка #2)

Рекомендация: **не плодить отдельную таблицу `session_instance`**, а расширить `group_lessons` — дата/порядок/пин уже там. `meetings` = шаблон, `group_lessons` = конкретные датированные экземпляры.

Добавляемые колонки:

```sql
ALTER TABLE fs_lms_group_lessons
  ADD COLUMN status              ENUM('scheduled','held','cancelled','moved') NOT NULL DEFAULT 'scheduled',
  ADD COLUMN teacher_id_override INT UNSIGNED NULL,           -- разовая замена на это занятие
  ADD COLUMN kind                ENUM('group','individual','makeup') NOT NULL DEFAULT 'group',
  ADD COLUMN student_person_id   BIGINT UNSIGNED NULL,        -- NULL = вся группа; заполнен = индивидуальное/отработка
  ADD COLUMN makeup_of_id        BIGINT UNSIGNED NULL,        -- ссылка на пропущенное занятие (для отработки)
  ADD KEY (student_person_id), ADD KEY (makeup_of_id);
```

- `scheduled_at` = **плановая** дата (из расписания). `status='held'` фиксирует, что занятие проведено (фактическую дату при переносе несёт `moved` + новый `scheduled_at`). Аналог «план/факт» из МЭШ.
- `kind='group'` + `student_person_id=NULL` — обычное занятие группы (как сейчас).
- `DTO`: расширить `GroupLessonDTO` соответствующими полями.

### 3.2. Посещаемость — новая таблица `fs_lms_attendance` 🔴 🔶 (развилка #6 по представлению)

Одна запись на (занятие, ученик). Моделируем как **отдельное измерение с баллами** (как Moodle), но в журнале **рисуем в ячейке занятия** («Н») — привычка РФ-журнала.

```sql
CREATE TABLE fs_lms_attendance (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_lesson_id   BIGINT UNSIGNED NOT NULL,   -- FK → fs_lms_group_lessons (само «занятие»)
  student_person_id BIGINT UNSIGNED NOT NULL,   -- FK → fs_lms_persons
  status            VARCHAR(16) NOT NULL,        -- present|absent|late|excused (набор конфигурируем)
  points            DECIMAL(4,2) NOT NULL DEFAULT 0,  -- вес статуса → считает %
  reason            VARCHAR(255) NULL,
  marked_by         INT UNSIGNED NOT NULL,       -- кто отметил (для замен/аудита)
  marked_at         DATETIME NOT NULL,
  UNIQUE KEY uq (group_lesson_id, student_person_id),
  KEY (student_person_id)
);
```

- Набор статусов и их баллы — конфиг (по умолчанию present=1, late=0.5, excused=0, absent=0). `%` посещаемости = Σpoints / Σmax.
- `TableName::Attendance`, `AttendanceDTO`, `AttendanceRepository`, `AttendanceService` (bulk «всем present, флипнуть исключения»).

### 3.3. Замена преподавателя — `fs_lms_substitutions` 🔴 🔶 (развилка #3)

**Никогда не перезаписывать `groups.teacher_id`.** Замена — данные.

```sql
CREATE TABLE fs_lms_substitutions (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id              SMALLINT UNSIGNED NOT NULL,
  original_teacher_id   INT UNSIGNED NOT NULL,
  substitute_teacher_id INT UNSIGNED NOT NULL,
  valid_from            DATE NOT NULL,
  valid_to              DATE NOT NULL,
  reason                VARCHAR(255) NULL,
  approved_by           INT UNSIGNED NOT NULL,   -- завуч/офис
  created_at            DATETIME NOT NULL,
  KEY (group_id), KEY (substitute_teacher_id)
);
```

- Две формы замены: **разовая** → `group_lessons.teacher_id_override` (§3.1); **на период** → строка `substitutions`.
- **Кто ведёт занятие D** (резолв на чтении): `teacher_id_override` › активный `substitutions` (`valid_from ≤ D ≤ valid_to`) › `groups.teacher_id`. Сервис `EffectiveTeacherResolver`.
- **Доступ (time-bound RBAC):** `GroupAccessGuard::canManage()` расширяем — пускать, если есть активный grant на (юзер, группа, сегодня). По истечении `valid_to` доступ гаснет сам. Модель ЭлЖур (завуч задаёт срок, доступ к журналу класса; список замен печатается).
- Назначает замену 🔶: рекомендация — завуч/`FSOffice` (новая `Capability::ManageSchedule` или существующая офисная), не сам препод.

### 3.4. Индивидуальные / отработки 🔴 🔶 (развилка #5)

- **Индивидуальное занятие = занятие с одним участником.** Отдельная сущность не нужна — это строка `group_lessons` с `kind='individual'`, `student_person_id=<ученик>`. Переиспользует дату, тему, посещаемость, оценивание.
- **Отработка (makeup)** = `kind='makeup'`, `student_person_id=<ученик>`, `makeup_of_id=<пропущенное занятие>`. Помечает исходный пропуск «закрытым», **не засоряя план группы**.
- Рекомендация: лёгкий путь (`student_person_id` на занятии). Тяжёлый (`session_attendees` для произвольных подгрупп) — только если понадобятся подгруппы.

### 3.5. Взвешивание оценок 🔴

- У работ есть `WorkType` (practice/independent/homework), у контрольных — `AssessmentKind`. Добавляем **вес по типу** (конфиг предмета/группы) → взвешенный средний балл в `GradebookEntryDTO`/`GradeSourceInterface`.
- Ожидание РФ-журнала: контрольная > самостоятельная > ответ. 🔶 включать в v1 или позже.

---

## 4. ЛК преподавателя

**Роль:** `FSTeacher` (`lms_teacher`). **Capabilities:** `ViewLMSStats`, `ManageLMSAssignments` (есть). Доступ к группе — `GroupAccessGuard` (свои группы по `teacher_id` + активные замены).
**Точка входа:** `Cabinet` рендерит `/cabinet/` (новый `PageRoutes::Cabinet`), per-role; глубокие экраны — через `/group/?gid=N` (кокпит `Lms`).

### 4.1. Главная (Cabinet) 🔴
- **Сегодня / неделя**: предстоящие занятия (группа, тема, время, кабинет) — из `group_lessons` всех групп препода (+ замены, где он substitute).
- **Ворклист «заполнить / проверить»**:
  - незаполненная посещаемость по прошедшим занятиям (🔴 после §3.2),
  - работы на проверке — `SubmissionRepository::listQueueByGroup()` 🟡.
- Данные: агрегируем по всем группам, где препод effective-teacher.

### 4.2. Расписание / КТП (назначение тем на даты) 🟡→🔴
Это ключевой сценарий «большая часть автоматом, иногда вручную». Движок есть, нужен UI + AJAX.

- **Авто:** кнопка «Распределить» → `SessionCalendarService.reflow(groupId)` (📌 вывести в AJAX — `AjaxHook::ReflowSchedule`). Темы (`group_lessons.position`) ложатся на слоты по порядку.
- **Вручную:** перетащить тему на дату → `is_pinned=true` + `scheduled_at` (`AjaxHook::PinLesson` / `MoveLesson`); остальное **переразливается вокруг пина**.
- **Привязка по порядку, а не по дате** → отменённое занятие/праздник автоматически сдвигают «нерасказанный хвост».
- Источник тем 🔶 (развилка #1): связать группу с курсом через `course_id` (сейчас берутся «все курсы предмета»). КТП группы = плоский список уроков курса (`CourseDTO::lessonIds()`).
- Опц. 🔶: «опубликовать КТП и заблокировать ручной ввод» (модель Дневник.ру).

### 4.3. Журнал (ученики × занятия/работы) 🔴 UI / данные в основном ✅
Сетка: **ученики — строки**, столбцы двух семейств: **занятия/даты** (посещаемость + отметки) и **работы/контрольные** (оценки). Колонка **средний балл** (взвешенный, §3.5).

| Столбец | Источник | Статус |
|---|---|---|
| Посещаемость | `fs_lms_attendance` | 🔴 (§3.2) |
| Задачи | `fs_lms_task_attempts` | ✅ |
| Работы | `fs_lms_submissions` | ✅ |
| Контрольные/ЕГЭ | `fs_lms_assessment_attempts` + `ExamResultService` | ✅ |
| Прогресс по шагам | `fs_lms_lesson_progress` | ✅ |

- Ростер: `StudentRecordRepository::findActiveByGroupId()`.
- Ввод посещаемости: «всем present → флипнуть исключения», «Н» в ячейке (`AjaxHook::SaveAttendance`). Быстрый клавиатурный ввод — nice-to-have.

### 4.4. Проверка работ 🟡→🔴
- Очередь: `SubmissionRepository::listQueueByGroup()` (статус `submitted`).
- Действие: оценка + фидбек → `SubmissionService` (`AjaxHook::GradeSubmission`). Авто-оценка объективных — уже в `AutoGradeService` (контрольные).
- nice-to-have: рубрики/критерии, аннотирование PDF (Moodle), пир-ревью (Stepik).

### 4.5. Карточка ученика 🔴
Сводка по одному: посещаемость, задачи, работы, контрольные, прогресс. Те же источники, срез по `student_person_id`. PII препод не видит (`ViewPII` — только офис).

### 4.6. Индивидуальные занятия и отработки 🔴
- Создать индивидуальное: `group_lessons` `kind='individual'`, `student_person_id` (`AjaxHook::CreateIndividualLesson`).
- Отработка из пропуска: из карточки ученика/журнала по absent-ячейке → `kind='makeup'` + `makeup_of_id` (`AjaxHook::CreateMakeup`).

### 4.7. Замены 🔴
- Разовая (на занятие) — завуч/офис ставит `teacher_id_override`.
- На период — завуч/офис создаёт `substitutions` (`AjaxHook::AssignSubstitute`). Замещающий видит группы в своей «Главной» на срок grant; `canManage` пускает в журнал; печать списка замен.

---

## 5. ЛК ученика (и родителя)

Многое уже есть (student-cockpit, lesson-player, `ExamResultService` для ученика) — кабинет в основном **собирает и причёсывает**.

**Роль:** `FSStudent`. Точка входа: `/cabinet/` (per-role) или расширение `/profile/`. Родитель (`FSParent`/representative) — read-only по своим детям (`StudentRecordRepository::findActiveByParent`).

| Экран | Содержимое | Источник | Статус |
|---|---|---|---|
| Главная | расписание ученика, ближайшие дедлайны (работы/контрольные), новые оценки, ДЗ | `group_lessons` его групп, `submissions.due_at`, `assessment_attempts` | 🔴 UI |
| Программа / курс | вход в lesson-player, статусы шагов | `LessonPlayerController`, `LessonProgressService` | ✅ |
| Мои оценки | дневник оценок ученика | `GradebookEntryDTO` (срез по ученику) | 🟡 UI |
| Посещаемость | его посещаемость и % | `fs_lms_attendance` | 🔴 (после §3.2) |
| Контрольные/работы | статусы попыток, результаты, сдача | `ExamResultService`, submission-form | ✅/🟡 |
| Родитель | те же данные по ребёнку, read-only | те же + фильтр по `parent_person_id` | 🔴 UI |

> ДЗ (домашнее задание) per-lesson/per-student — РФ-ожидание; ложится на `submissions` (work с дедлайном) и/или поле на `group_lessons`. 🔶 уточнить модель ДЗ.

---

## 6. Модульная раскладка и инвариант §4.1

**В `Lms` (ядро):** §3 целиком (миграции/DTO/репозитории/сервисы), экраны журнала/проверки/КТП в кокпите, `EffectiveTeacherResolver`, расширение `GroupAccessGuard`, вывод `reflow` в AJAX.

**В `Cabinet` (лист, новый `Inc\Modules\Cabinet`):**
- `CabinetModule implements ServiceInterface` (early-return при выключенном флаге `FS_LMS_CABINET` / опции),
- `CabinetPageController` (`/cabinet/`, per-role),
- шаблоны `templates/frontend/cabinet/*`, ассеты модуля,
- **только агрегация и навигация** поверх публичных API `Lms`.

**Чек §4.1:** выключили `Cabinet` → заявки/зачисление/ЛМС/кокпит (`/group/`) работают как сейчас; журнал/посещаемость/замены доступны через кокпит.

---

## 7. Предлагаемая нарезка на под-этапы

1. **Связка курс↔группа** (`course_id`) + КТП-UI: вывести `reflow` в AJAX, экран расписания с авто-распределением и пином.
2. **Посещаемость** (`fs_lms_attendance`) + журнал-сетка (посещаемость + готовые оценки).
3. **Проверка работ** (экран очереди + grade).
4. **Индивидуальные/отработки** (эволюция `group_lessons`).
5. **Замена** (`substitutions` + override + `EffectiveTeacherResolver` + доступ).
6. **`Cabinet`-лист**: «Главная» препода (календарь + ворклист), затем кабинет ученика/родителя.
7. (опц.) Взвешивание оценок, lock КТП, рубрики/аннотации.

---

## 8. Конкурентные референсы (сжато)

- **РФ-электронные журналы (ЭлЖур / Дневник.ру / Сетевой Город)** — наш UX-эталон: сетка журнала, КТП-автозаполнение, «Н» в ячейке, замена со срочным доступом к журналу класса.
- **Moodle** — эталон модели данных: занятия как строки, статусы посещаемости с баллами, sparse-overrides, роль@контекст отдельно от time-boxed доступа.
- **Stepik** — очередь ручной проверки, авто-оценивание, рубрики/пир-ревью; «Табель успеваемости» (ученики × шаги, легенда состояний ячеек).
- **Tutor LMS** — в основном «чего НЕ хватает» в чисто курсовой модели: нет занятий/посещаемости/когорт.

Ключевые расхождения, по которым решаем (см. §9): «Н» в ячейке оценки vs отдельное измерение с баллами; КТП с привязкой к дате+«Распределить» vs нумерованные темы из списка; замена с другим предметом (перестраивает оба журнала) vs только тем же предметом.

---

## 9. Открытые вопросы (развилки)

1. **Курс↔группа** через `course_id` — включаем? *(рек.: да — иначе КТП недетерминирован)*
2. **«Занятие»**: эволюция `group_lessons` *(рек.)* vs отдельная `session_instance`.
3. **Замена**: модель override+grant с резолвом на чтении; назначает завуч/`FSOffice` *(рек.)*.
4. **Граница UI**: глубокие экраны в кокпите (`Lms`), `Cabinet` = персональная агрегация *(рек.: да)*.
5. **Индивидуальные**: лёгкий `student_person_id` *(рек.)* vs `session_attendees`.
6. **Посещаемость**: рендер «Н» в ячейке + модель с баллами; набор статусов.
7. **Взвешивание оценок** — в v1 или позже.
8. **Lock КТП** после публикации (Дневник.ру) — нужен ли.
9. **Модель ДЗ** (на `submissions` или поле на занятии).

---

## 10. Задачи: багфиксы конструктора + Эпик 15 (бесшовные контрольные/экзамены в плеере)

### 10.0 Багфиксы конструктора (вне очереди, перед Эпиком 15)

| ID | Задача | Файлы | Объём |
|---|---|---|---|
| B1 | «Создать задачу» в контрольных/работах должна выглядеть как чип, в стиле «Выбрать из банка» / «Удалить слот» | `src/js/admin/services/slot-builder.js:355-358` (`createBtn.className`, сейчас `button button-primary` — селектор `.button:not(.button-primary)` в `src/scss/admin/components/_slot-builder.scss:127-136` даёт чип-стиль (`cb-ghost-button`/`cb-chip`, `src/scss/admin/_mixins.scss:312-319`, `src/scss/admin/_variables.scss:204-210`) двум другим кнопкам, но явно **исключает** `.button-primary`) · единственная точка кода — используется и `assessment-builder.js:51-53`, и `work-builder.js:22-24` через общий `createSlotBuilder()`, дублировать фикс не нужно | XS |
| B2 | В задании с файлами кнопка выбора файла — переделать текущий мульти-пикер «+ Добавить файлы» в одиночный «Выбрать файл» (выбор/замена/удаление одного файла, а не список) | `inc/MetaBoxes/Fields/FileAttachmentsField.php:49` (кнопка, лейбл) · `src/js/admin/services/task-fields.js:262-313` (`bindMaterials()`, `wp.media` мульти-пикер — переделать под `multiple:false` режим) · паттерн для одиночного файла уже есть рядом — `bindAudio()` в том же файле, `task-fields.js:193-236` (select/replace/remove для одного вложения) · поле сохраняется в `fs_lms_meta[task_materials][attachment_ids][]` (`BaseField::get_field_name()`) — при переходе на одиночный файл уточнить формат меты | S |

### 10.1 Эпик 15 — контекст и развилка

Сейчас в плеере (`/group/?gl=`) шаг-«контрольная» (`step-assessment.php`) — статичная карточка с кнопкой, которая уводит на **отдельную** страницу (`AssessmentPageController` → `templates/frontend/assessment/attempt.php`), отрендеренную **внутри темы сайта** (`ThemeCompatService::header()/footer()`) со старыми классами `frontend.scss`. Плеер же — полностью изолированный SPA-шелл: свой `<html>` без темы, свои токены (`src/scss/player/_variables.scss`), свой бандл (`player.min.css/js`), включается флагом `fs_lms_is_player_route`.

**Выбран Вариант 1** (обсуждено и согласовано): не переносить прохождение контрольной внутрь DOM плеера, а оставить текущий CPT/attempt-флоу (таймер, автосохранение, `ExamLockService`, проверка — всё рабочее) как отдельный переход по URL, но **полностью снять тему сайта** и одеть страницу в шелл/токены плеера — тогда переход ощущается как часть одного продукта, а не «уход с сайта». Отклонённая альтернатива — встраивание прохождения прямо в DOM плеера (полноценный «exam mode» без навигации) — отклонена как дублирующая уже рабочую логику таймера/автосохранения/блокировки из `assessment.js` без архитектурного выигрыша.

### 10.2 Эпик 15 — задачи

| ID | Задача | Файлы | Статус |
|---|---|---|---|
| T15.1 | Убрать `ThemeCompatService::header()/footer()` со страницы контрольной, дать ей собственный bare-`<html>` шелл (без темы, `wp_head()/wp_footer()` только） — по образцу `templates/frontend/lesson-player/player.php` | `inc/Controllers/Pages/AssessmentPageController.php` (строки ~124-127, вызов рендера) · новый `templates/frontend/assessment/attempt-shell.php` (шапка/обёртка) | 🔴 |
| T15.2 | Изолированный бандл для страницы контрольной на токенах плеера — новый SCSS-энтрипоинт, `@use`-ит `player/_variables.scss` и общие компоненты (`card16`, чипы, кнопки), не тянет `frontend.scss` | новый `src/scss/assessment/assessment.scss` (или `src/scss/player/components/_assessment-attempt.scss`, если решаем буквально шарить `player.min.css`, см. 🔶 ниже) · `gulpfile`/`styles:*` таск · `inc/Core/Enqueue.php` — новая ветка регистрации вместо текущей `enqueue_frontend_assets()` (~строка 539) | 🔴 |
| T15.3 | Топбар страницы контрольной в стиле плеера — «Вернуться» ссылка (как `.s-back` в `player.php`), без рельсы/степ-ленты (это не урок) | `templates/frontend/assessment/attempt-shell.php` · SCSS из T15.2 | 🔴 |
| T15.4 | Переверстать `attempt.php` (все 4 состояния: старт/активная попытка/просрочено/результат) на компоненты плеера вместо `fs-attempt-*`/`fs-btn--*` из `frontend.scss` | `templates/frontend/assessment/attempt.php` (203 строки, все состояния) | 🔴 |
| T15.5 | `assessment.js` (таймер/автосохранение/сабмит/файлы/результат) оставить как есть по логике, переподключить к новой разметке/классам из T15.4, грузить отдельно от `frontend.min.js` | `src/js/frontend/services/assessment.js` · подключение в `Enqueue.php` | 🔴 |
| T15.6 | В плеере: карточка `step-assessment.php` — кнопка «Перейти к контрольной» → «Начать контрольную» (per ТЗ), стиль привести к workbar-паттерну `step-work.php` (бейдж/прогресс/кнопка) для визуальной параллели «работы vs контрольной» | `templates/frontend/lesson-player/partials/step-assessment.php` · SCSS `src/scss/player/components/_step-work.scss` (переиспользовать паттерн, не дублировать) | 🔴 |
| T15.7 | Обратная навигация: с новой страницы контрольной — возврат в исходный урок/шаг плеера (deep-link `?gl=&step=`), а не просто на `/profile/`. Нужно явно прокинуть referring lesson/step в URL при переходе из step-assessment.php и прочитать на возврате | `templates/frontend/lesson-player/partials/step-assessment.php` (формирование ссылки) · `templates/frontend/assessment/attempt-shell.php` (кнопка «Вернуться») | 🔴 |
| T15.8 | `ExamLockService`/`LessonGateResolver` — убедиться, что блокировка контента на время активной запирающей попытки (экзамен) не нуждается в правках после смены шелла (проверка сработает на уровне плеера/`/profile/`, сама страница контрольной уже единственный экран без сайт-навигации) | `inc/Services/Assessment/ExamLockService.php`, `inc/Services/Course/LessonGateResolver.php` — только регрессионная проверка, без изменений кода, если гипотеза верна | 🔴 (проверить) |
| T15.9 | `ege-computer.php` (альтернативный скин модуля EgeComputer) — решить, тянем ли его тем же рефакторингом в этом эпике или выносим отдельным тикетом модуля | `templates/frontend/assessment/ege-computer.php` | 🔶 см. §10.3 |

### 10.3 Открытые вопросы Эпика 15 (🔶)

1. **Бандл**: отдельный `assessment.scss`, переиспользующий токены плеера через `@use` (изоляция версий, чуть больше кода), vs буквально грузить `player.min.css` на странице контрольной (гарантированная идентичность стиля, но связывает бандлы). *Рек.: отдельный энтрипоинт с `@use` — соответствует текущему паттерну «у каждой поверхности свой бандл» (player/profile).*
2. **`ege-computer.php`**: рефакторить в этом эпике или отдельным тикетом модуля EgeComputer (фича-флаг) — решить перед T15.9.
3. **Обратная навигация (T15.7)**: возврат строго в исходный `?gl=&step=` или достаточно fallback на `/profile/`, если реферер не сохранён (например прямой заход по ссылке из уведомления) — уточнить ожидаемое поведение edge-case.
