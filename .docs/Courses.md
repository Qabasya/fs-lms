# Courses.md — Развитие LMS: курсы и личные кабинеты

Документ описывает дальнейшее развитие плагина по этапам. Текущий охват — **очные курсы** и **личные кабинеты**. Онлайн-курсы (self-paced) и переиспользуемые курсы-шаблоны вынесены в раздел «Будущее» и здесь детально не прорабатываются.

---

## 0. Контекст и зафиксированные решения

### Что уже есть (фундамент)

| Сущность | Хранилище | Роль для курсов |
|---|---|---|
| **Subject** (предмет) | `wp_options` (`fs_lms_subjects_list`) + CPT `{key}_tasks`, `{key}_articles`, таксономия `{key}_task_number` | Дисциплина и **контент-библиотека**: задания и статьи переиспользуются уроками и экзаменами |
| **Group** (группа) | таблица `fs_lms_groups` (`subject_key`, `academic_period_id`, `teacher_id`, `name`, `schedule` text) | Очная когорта = **«запуск» программы**. `schedule` (сейчас text) формализуется в расписание уроков |
| **student_records** | таблица `fs_lms_student_records` | Факт обучения (ученик↔родитель↔группа). Вся механика зачисления/отчисления/архива переиспользуется без изменений |
| **persons / person_documents** | таблицы | Идентификация и PII. Курсы не дублируют, только ссылаются по `person_id` |
| **Роли** | `FSTeacher`, `FSStudent`, `FSParent`, `FSOffice` | У преподавателя уже есть `ViewLMSStats`, `ManageLMSAssignments` |
| **Личный кабинет** | `PageRoutes::UserProfile` (`/profile`), `ShortCode::Profile`, `ProfileController` | Каркас кабинета уже существует — расширяется, не создаётся с нуля |

> Находка: в `ThreeInOneTemplate` есть комментарий *«в экзаменационной работе будем разделять»*. Библиотека `tasks` изначально задумана как источник и для уроков, и для экзаменов — экзамен-движок **переиспользует задания**, а не заводит отдельные «вопросы».

### Принятые решения (по итогам обсуждения)

1. **Переиспользуемый атом — урок (занятие), а не курс целиком.** У каждой очной группы своя программа («курс группы»), собранная из своих уроков + вставок уже существующих уроков из общего банка.
2. **Отдельной верхнеуровневой сущности `Course` для очных групп не вводим.** «Курс группы» = упорядоченный список уроков, прикреплённый к группе (таблица `fs_lms_group_lessons`). Это же расписание и доставка.
3. **Онлайн-ученики откладываются.** Фокус — очные, проходящие через текущие формы `apply` + `join`.
4. **Урок не обязан состоять из заданий.** Может быть просто занятие (тема + теория), а может быть привязан к типу заданий — тогда в практику / самостоятельную / домашнюю работу вставляются существующие `tasks`.
5. **Хранение: CPT для контента + кастомные таблицы для фактов** — строго по текущему расколу архитектуры.
6. **Задания вставляются в урок ссылкой (`task_id`), не копией.** Задание остаётся единым
   `{key}_tasks` и переиспользуется и в уроках, и в экзаменах; правка в библиотеке отражается
   везде. «Создать новое» из урока создаёт реальный пост в библиотеке (не inline-блок).
   Видимость банка — весь предмет, но фильтр «мои задания» (`post_author`) по умолчанию.
7. **«Коллекции» заданий = пользовательская таксономия** поверх номеров заданий (тематические
   наборы, напр. «Циклы Python») — для удобного выбора в селекторе урока. Без нового слоя.
8. **Доступ к материалам = членство (грант), а не подписка; роль при отчислении не меняется.**
   Зачисление даёт доступ к бэк-каталогу группы; отчисление по умолчанию **сохраняет** read-only
   доступ к пройденному. Гейт — по `student_record` через единый `LessonAccessPolicy` (не по роли).
   Кабинет по умолчанию не блокируется; `retain_after_expulsion` — админ-политика. Детали — §4.

### Ориентир из практики: Tutor LMS

Разобрали зрелый WordPress-LMS **Tutor LMS** ([доки](https://docs.themeum.com/tutor-lms/)) как
референс по авторингу и переиспользованию контента. Ключевой механизм — **Content Bank**:
централизованный банк переиспользуемых уроков, вопросов и заданий, сгруппированных в **Collections**;
контент **линкуется** в курсы, не копируется (рядом виден счётчик «используется в N курсах» —
[Content Bank docs](https://docs.themeum.com/tutor-lms/content-bank/)).

| Tutor LMS | Что это | Наш аналог |
|---|---|---|
| Course | self-paced курс | программа **группы** (`fs_lms_group_lessons`) — но у нас когорта + расписание |
| Topic | раздел/глава | (опц. на будущее) секции в программе группы |
| Lesson | урок (текст/видео/вложения) | CPT `{key}_lessons` |
| Quiz | авто-проверка, таймер, типы вопросов | Assessment / экзамен (Этап 4) |
| Assignment | ручная проверка, файл, баллы, дедлайн | Submission / практика-СР-ДЗ (Этап 3) |
| Content Bank | банк переиспользуемого контента | библиотека `{key}_tasks` + кастомные таксономии |
| Collection | тематический набор («Beginner Python») | терм пользовательской таксономии на `{key}_tasks` |

**Что подтвердилось в нашем плане:**
- Переиспользование **по ссылке, а не копией** (Content Bank линкует, есть счётчик использований) →
  наша модель «задание-ссылка» (решение 6) верна.
- Чистый раскол **Quiz (авто, таймер) vs Assignment (ручная проверка, файл, дедлайн)** → наш
  раскол Submission (Этап 3) ↔ AssessmentAttempt (Этап 4).
- **Content Drip** (открытие уроков по времени/последовательности) → наш `visibility` +
  `scheduled_at` / `opened_at` и §7 #3 (привязка публикации к расписанию).

**Что взяли:**
- **Collections = таксономия** на заданиях (решение 7) — тематические наборы поверх номеров.
- **Счётчик «используется в N»** уроках/экзаменах — препод видит «зону поражения» перед правкой
  общего задания (read-модель по `group_lessons` + meta экзаменов; реализуемо с Этапа 2).
- **Модалка «создать прямо в конструкторе»** — у нас уже есть модалка создания задания;
  переиспользуем её из урока с авто-прикреплением ссылки.

**Что сознательно НЕ берём:**
- Tutor — **self-paced курс**; мы — очные **когорты с расписанием**. Их Course ≈ наш «онлайн-курс
  из будущего» (§6), а не программа группы.
- У Tutor видео — часть урока-определения; у нас видео — **запись конкретного занятия**
  (`group_lessons.recording_url`, Этап 5), а урок-определение переиспользуется. Для живого
  обучения наш раскол «определение ↔ проведение» строже и корректнее.

### Принцип из best practices

Разделять **«определение»** и **«проведение»**:
- Урок и экзамен — переиспользуемые *определения* (CPT).
- Программа группы с расписанием и видимостью — *проведение во времени* (таблица).
- Попытка ученика — отдельная сущность с **server-side таймером** (клиентскому таймеру доверять нельзя — он дрейфует).

Источники: [Moodle Course module](https://docs.moodle.org/dev/Course_module), [Moodle course formats](https://docs.moodle.org/502/en/Course_formats), [The Exam Engine](https://dev.to/insight105/the-exam-engine-206c), [LMS quiz engine best practices](https://www.commlabindia.com/blog/built-in-lms-quiz-engine).

---

## 1. Доменная модель

```
Subject (предмет + библиотека: tasks + articles)
  ├─ Lesson (CPT {key}_lessons) — переиспользуемый банк уроков
  │     • тема • теория (rich / ссылка на article)
  │     • практика / СР / ДЗ = свободный контент И/ИЛИ ссылки на tasks
  │     • опц. привязка к типу задания
  └─ Assessment (CPT {key}_assessments) — контрольная / экзамен
        • упорядоченный набор tasks (+ баллы за задание)
        • конфиг: лимит времени, попытки, окно доступности, проходной балл

Group (fs_lms_groups) + course_id? — НЕТ. Программа = прикреплённые уроки:
  └─ GroupLesson (fs_lms_group_lessons) — «курс группы» + расписание + доставка
        group_id × lesson_id × порядок × дата × видимость × запись(S3)

Факты обучения (таблицы):
  • Submission (fs_lms_submissions)         — сдача практики/СР/ДЗ + дедлайн (due_at) + проверка + балл
  • AssessmentAttempt (fs_lms_assessment_attempts) — попытка: attempt_number, старт/дедлайн server-side, статус, балл
  • AssessmentAnswer (fs_lms_assessment_answers)   — ответ на задание в попытке + балл
  • CourseActivityLog (fs_lms_course_activity_log) — лента действий по группе (один канал, разрез по group_id)

Gradebook (журнал успеваемости) — НЕ таблица: read-model поверх submissions + attempts (см. §2).
```

### Связи

- `Subject (1) → (N) Lesson` — урок принадлежит предмету (банк per-subject), ссылается на задания/статьи этого предмета.
- `Group (1) → (N) GroupLesson (N) → (1) Lesson` — один урок может стоять в программах нескольких групп; программа группы — упорядоченный список.
- `GroupLesson (1) → (N) Submission` — сдачи работ привязаны к конкретному занятию группы и ученику.
- `Assessment (1) → (N) AssessmentAttempt (1) → (N) AssessmentAnswer` — переиспользуемое определение → попытки → ответы.
- Все ученики/преподаватели — через `persons.id` и `persons.wp_user_id`; доступ ученика к материалам гейтится через `student_records` (активное зачисление в группу), **не** через capability.

---

## 2. Хранение

### Новые CPT (контент, авторинг преподавателем)

Регистрируются тем же механизмом, что `{key}_tasks` / `{key}_articles` (`SubjectCPTRegistrar` + `CPTManager`, фильтр `fs_lms_cpt_args`), per-subject:

- **`{key}_lessons`** — урок. Мета через `PostMetaName::Meta`, поля по образцу `MetaBoxes/Templates`:
  - `topic` — тема занятия
  - `theory` — теория (rich-text или ссылка на `{key}_articles`)
  - `task_type` — опц. привязка к типу задания (таксономия/мета)
  - `practice[]`, `independent[]`, `homework[]` — каждый бакет: свободный контент + список `task` ID (`PostTypeResolver::tasks($key)`)
- **`{key}_assessments`** — контрольная/экзамен. Мета:
  - `tasks[]` — упорядоченные ID заданий + баллы за каждое
  - `time_limit`, `attempts_allowed`, `available_from`, `available_until`, `pass_score`, `shuffle`, `scoring_policy` (`highest` / `last` / `first`)
  - `scope` — на тему (привязка к уроку) или итоговый экзамен

> Решение: per-subject `{key}_lessons` / `{key}_assessments` (консистентно с tasks/articles, без рефактора отгруженного кода). «Взрыв CPT» при десятках предметов снимается **конфигом, а не сменой архитектуры**:
> - **меню** — все subject-CPT `show_in_menu => false` + одна сводная страница «Курсы» → меню не растёт;
> - **права** — общий `capability_type => 'fs_lms_content'` + `map_meta_cap => true` на всех → один набор прав, не per-subject;
> - **REST** — `show_in_rest => false` пока не используется → нет раздувания;
> - **поиск** — `exclude_from_search => true` (контент гейтится доменно).
>
> Неустранимый остаток — N× `register_post_type` на `init`; ничтожен на масштабе центра (таксономии `{key}_task_number` уже множатся так же). Группа односубъектна (`groups.subject_key`) → запросы уроков/экзаменов скоупятся по предмету нативным `post_type`-запросом без meta-join. Кросс-предметная отчётность (журнал/дашборд) живёт на fact-таблицах (`submissions`/`attempts`) — раскол CPT её не касается.

### Новые таблицы (факты)

Добавляются в `Migration_1_0_0::up()` и `down()` (не отдельным файлом — см. CLAUDE.md), имена через расширение `Enums\TableName`. Черновик DDL:

```sql
-- Программа группы = «курс» + расписание + доставка (заменяет groups.schedule text)
fs_lms_group_lessons (
  id              int unsigned PK,
  group_id        smallint unsigned NOT NULL,   -- → fs_lms_groups.id
  lesson_id       bigint unsigned   NOT NULL,   -- → CPT {key}_lessons (post ID)
  position        smallint unsigned NOT NULL DEFAULT 0,
  scheduled_at    datetime DEFAULT NULL,        -- дата/время занятия
  teacher_user_id bigint(20) unsigned DEFAULT NULL, -- кто вёл занятие (WP user; замена/со-препод ≠ groups.teacher_id)
  visibility      enum('hidden','open','archived') NOT NULL DEFAULT 'hidden',
  opened_at       datetime DEFAULT NULL,        -- когда открыли доступ (на будущее: авто по scheduled_at)
  homework_due_at datetime DEFAULT NULL,        -- дедлайн ДЗ = источник снапшота в submissions.due_at
  allow_late      tinyint(1) NOT NULL DEFAULT 1, -- принимать ли сдачу после дедлайна
  recording_url   varchar(1000) DEFAULT NULL,   -- запись из S3 (только видео, этап 5)
  created_by_user_id bigint(20) unsigned DEFAULT NULL, -- кто собрал программу
  updated_by_user_id bigint(20) unsigned DEFAULT NULL,
  created_at, updated_at,
  KEY group_id, KEY lesson_id, KEY (group_id, position)
)

-- Сдача практики/СР/ДЗ
fs_lms_submissions (
  id                int unsigned PK,
  student_person_id int unsigned NOT NULL,    -- → fs_lms_persons.id
  group_lesson_id   int unsigned NOT NULL,    -- → fs_lms_group_lessons.id
  work_type         enum('practice','independent','homework') NOT NULL,
  task_id           bigint unsigned DEFAULT NULL,  -- если сдача по конкретному заданию
  answer_text       longtext DEFAULT NULL,
  attachment_id     bigint unsigned DEFAULT NULL,  -- WP Media Library (не S3; п.7 #6)
  due_at            datetime DEFAULT NULL,    -- снапшот дедлайна на выдаче (из group_lessons.homework_due_at); правится для индив. продления
  status            enum('assigned','submitted','graded','returned') NOT NULL DEFAULT 'assigned',
  score             decimal(6,2) DEFAULT NULL,
  max_score         decimal(6,2) DEFAULT NULL,
  feedback          text DEFAULT NULL,            -- комментарий проверки (возврат/оценка)
  graded_by_user_id bigint unsigned DEFAULT NULL,
  submitted_at, graded_at, created_at, updated_at,
  KEY student_person_id, KEY group_lesson_id, KEY status
  -- is_late вычисляется: submitted_at > due_at (не хранится)
)

-- Попытка прохождения контрольной/экзамена (таймер server-side)
fs_lms_assessment_attempts (
  id                int unsigned PK,
  assessment_id     bigint unsigned NOT NULL,  -- → CPT {key}_assessments
  student_person_id int unsigned NOT NULL,
  group_id          smallint unsigned DEFAULT NULL,
  attempt_number    smallint unsigned NOT NULL, -- 1,2,3… без COUNT; для scoring_policy highest/last/first
  started_at        datetime NOT NULL,
  deadline_at       datetime NOT NULL,         -- started_at + time_limit, считается на сервере
  submitted_at      datetime DEFAULT NULL,
  status            enum('in_progress','submitted','graded','expired') NOT NULL DEFAULT 'in_progress',
  total_score       decimal(6,2) DEFAULT NULL,
  max_score         decimal(6,2) DEFAULT NULL,
  graded_by_user_id bigint unsigned DEFAULT NULL,
  created_at, updated_at,
  UNIQUE KEY attempt (assessment_id, student_person_id, attempt_number), -- закрывает гонку двойного старта
  KEY assessment_id, KEY student_person_id, KEY status
)

-- Ответ на задание внутри попытки
fs_lms_assessment_answers (
  id                int unsigned PK,
  attempt_id        int unsigned NOT NULL,     -- → fs_lms_assessment_attempts.id
  task_id           bigint unsigned NOT NULL,
  answer_text       longtext DEFAULT NULL,
  is_correct        tinyint(1) DEFAULT NULL,   -- NULL = требует ручной проверки
  score             decimal(6,2) DEFAULT NULL,
  max_score         decimal(6,2) DEFAULT NULL,
  graded_by_user_id bigint unsigned DEFAULT NULL,
  graded_at         datetime DEFAULT NULL,
  KEY attempt_id, KEY task_id
)

-- Журнал активности по группе (новый лог-канал, по образцу fs_lms_audit_log)
fs_lms_course_activity_log (
  id            int unsigned PK,
  group_id      smallint unsigned NOT NULL,  -- скоуп «журнала группы»
  actor_user_id bigint(20) unsigned DEFAULT NULL,
  actor_role    varchar(50) DEFAULT NULL,
  action        varchar(40) NOT NULL,        -- lesson_published | submission_made | submission_graded | attempt_started | attempt_submitted | lesson_added_to_program | schedule_changed | recording_attached
  entity_type   varchar(30) DEFAULT NULL,
  entity_id     varchar(100) DEFAULT NULL,
  is_public     tinyint(1) NOT NULL DEFAULT 1, -- виден ли срез ученику/родителю (свои события + публикации, §4)
  created_at    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (group_id, created_at), KEY actor_user_id
)
-- НЕ таблица-на-группу: один канал, разрез по group_id. «Журнал группы» = WHERE group_id=X ORDER BY created_at DESC + пагинация.
-- Имена акторов резолвит LogNameResolver (как в существующих логах).
```

> Внедрение в dev: после правки DDL сбросить `fs_lms_schema_version` в `0.0.0` и перезагрузить страницу WP (см. CLAUDE.md → «Миграции в dev-окружении»).

### Журнал успеваемости (gradebook) — read-model, без таблицы

Балл — **единый источник**: `submissions.score` / `assessment_attempts.total_score`. Журнал НЕ материализуется в отдельную таблицу (иначе двойная запись и рассинхрон при переоценке — боль Moodle `grade_grades`). `GradebookService` собирает `GradebookEntryDTO` UNION-запросом из обеих fact-таблиц; `group_id` берётся у submission через `group_lesson`, у attempt — напрямую.

```
GradebookEntryDTO
  student_person_id
  group_id
  source_type   // submission | assessment | manual
  source_id
  title         // тема урока / название экзамена — резолв на чтении
  category      // practice | independent | homework | assessment | manual
  score / max_score
  graded_at
```

Таблица `fs_lms_grade_entries` (`source_type='manual'`, `source_id=NULL`) заводится **отдельным шагом и только** под оценки без источника (ручная корректировка, посещаемость, бонусы). Тогда журнал = UNION(submissions, attempts, manual). Каждая оценка принадлежит ровно одной таблице — принцип единого источника сохранён.

### Что остаётся в `wp_options`

Глобальные настройки модуля курсов (если появятся) — через `Enums\OptionName` + `OptionsRepositories`. Структура урока/экзамена живёт в post-meta CPT, не в options.

---

## 3. Раскладка по слоям

| Слой | Что добавляется |
|---|---|
| **Enums** | `TableName`: +`GroupLessons`, `Submissions`, `AssessmentAttempts`, `AssessmentAnswers`, `CourseActivityLog`. `Capability`: **переиспользуем** `ManageLMSAssignments` + `Admin` — новые caps НЕ вводим (п.7 #2). `Nonce`: +`AuthorLesson`, `SaveSchedule`, `SetLessonVisibility`, `SubmitWork`, `GradeWork`, `StartAttempt`, `SubmitAttempt`, `GradeAttempt`. `AjaxHook`: соответствующие cases. `PageRoutes`/`ShortCode`: страница группы (кокпит преподавателя) + view-страницы урока/экзамена |
| **Repositories** (`WPDBRepositories`) | `GroupLessonRepository`, `SubmissionRepository`, `AssessmentAttemptRepository`, `AssessmentAnswerRepository` — CRUD. `Log/CourseActivityLogRepository` — лента активности группы (по образцу `Log/AuditLogRepository`). Gradebook — **без** репозитория-таблицы (read-model через UNION в сервисе) |
| **Managers** | `LessonManager`, `AssessmentManager` — обёртки над CPT/мета (CRUD post + meta) |
| **Services** (`Services/Course/`, `Services/Assessment/`) | `LessonAuthoringService`, `ScheduleService`, `LessonVisibilityService`, `SubmissionService`, `GradebookService` (**read-model**: UNION submissions+attempts → `GradebookEntryDTO`), `AssessmentService`, `AttemptService` (старт/таймер/сабмит), `AutoGradeService` (сверка `*_answer` полей), `S3RecordingService` (видео, этап 5) |
| **Controllers** | `LessonController`, `ScheduleController`, `SubmissionController`, `AssessmentController`, `GroupCockpitController` (фронт-страница группы) — только регистрация хуков. `Subscribers/CourseActivitySubscriber` — запись лога активности по доменным событиям. Расширение `ProfileController` под роле-кабинеты |
| **Controllers/Pages** | по образцу `TaskPageController` (инъекция шаблона через `template_include`) — `LessonPageController`, `AssessmentPageController` |
| **Callbacks** (`Callbacks/Course/`, `Callbacks/Assessment/`) | AJAX-обработчики: авторинг урока, сборка программы группы, видимость, сдача работ, проверка, старт/сабмит/проверка попытки. `Authorizer` + `Sanitizer` + `AjaxResponse` |
| **DTO** (`DTO/Course/`, `DTO/Assessment/`) | `LessonDTO`, `LessonViewDTO`, `GroupLessonDTO`, `ScheduleDTO`, `SubmissionDTO`, `GradeDTO`, `GradebookEntryDTO`, `CourseActivityDTO`, `AssessmentDTO`, `AttemptDTO`, `AttemptResultDTO` |
| **Registrars** | расширение `SubjectCPTRegistrar` — регистрация `{key}_lessons`, `{key}_assessments` |
| **MetaBoxes** | новые `Templates`/`Fields` для урока (бакеты практика/СР/ДЗ со вставкой tasks) и экзамена (список заданий + баллы + конфиг) |
| **Migrations** | новые таблицы в `Migration_1_0_0::up()`/`down()` |
| **JS** | admin: авторинг урока, конструктор программы группы (drag-n-drop порядок, выбор уроков из банка), конфиг экзамена. frontend: кабинет, прохождение экзамена (server-sync таймер), сдача работ. Валидация через `common/validators` |
| **Services** регистрация | все новые сервисы/контроллеры — в `Init::getServices()`, реализуют `ServiceInterface` |

---

## 4. Роли и кабинеты

| Роль | Видит в кабинете (`/profile`) | Права |
|---|---|---|
| **FSStudent** | свои группы → программа → открытые уроки (тема, теория, практика/СР/ДЗ), статусы своих работ и оценки, доступные контрольные/экзамены и результаты, записи занятий | доступ гейтится активным `student_records`; своя сдача работ и попытки |
| **FSTeacher** | свои группы (`groups.teacher_id`), банк уроков предмета, конструктор программы группы и расписание, проверка работ и попыток, создание контрольных/экзаменов | `AuthorLessons`, `ManageSchedule`, `GradeWork` (или `ManageLMSAssignments`) |
| **FSParent** | прогресс/оценки своих детей (read-only) | через связь родитель→ученик в `student_records` |
| **FSOffice** | без изменений (заявки, зачисление, PII) | существующие права |

**Доступ к материалам = членство в группе (`student_record`), а не подписка.** Зачисление — грант
доступа к библиотеке материалов группы; статус записи и даты задают окно видимости. Доменная проверка
в сервисах — не WP-capability и **не роль** (роль `FSStudent` сохраняется после отчисления).

### Политика доступа: membership = грант, не подписка

«Подписка» дала бы только будущие публикации и отобрала бы всё при отмене — а нужно наоборот:
поздний ученик видит **бэк-каталог**, отчисленный **сохраняет** пройденное. Поэтому — модель
**членства**. Два **разных** гейта:

- **Чтение контента** (смотреть урок): широкий, переживает отчисление.
- **Сдача/запись** (submit, попытка экзамена): только пока `status='active'`.

Единый резолвер `LessonAccessPolicy(student_record, group_lesson) → none | read | read+submit` —
одно место для всей матрицы:

| Статус записи | Чтение урока | Сдача |
|---|---|---|
| `active` | любой видимый урок группы (**весь бэк-каталог**) | да, если `opened_at >= enrolled_at` (без обязательств задним числом) |
| терминальный (`expelled`/`finished`/`transferred`), политика **retain** (дефолт) | видимые уроки с `opened_at <= expelled_at` | нет |
| то же, политика **block** | доступ к материалам закрыт (см. кабинет ниже) | нет |

- «Видимый» = `group_lesson.visibility ∈ {open, archived}` (`hidden` ученику недоступен).
- **Поздний ученик**: чтение — весь бэк-каталог; обязательства/просрочки — только с `enrolled_at`
  (нижняя граница есть у сдач, у чтения её нет).
- **Граница архива при отчислении** — `opened_at <= expelled_at` (что реально было ему опубликовано
  как члену), не дата занятия.
- **Пример:** отчислен из A и зачислен в B 1 ноября → остаются уроки A, опубликованные ему до
  1 ноября; в B появляется весь бэк-каталог до 1 ноября (без просрочек) и дальше публикуется как
  обычному ученику.

**Жизненный цикл аккаунта ≠ членства.** Аккаунт `wp_user` при отчислении **не удаляется**, роль
**не меняется** — везде гейтим по `student_record` через резолвер, а не по роли. Личный кабинет по
умолчанию **не блокируется**: что ученик прошёл (за что заплатил) остаётся с read-only доступом
бессрочно. Но политику задаёт администратор — флаг `retain_after_expulsion` (глобально или на группу):
`retain` (дефолт: кабинет + архив) либо `block` (кабинет/доступ закрывается). Это «универсальный
метод»: код всегда спрашивает политику, не хардкодит поведение.

**Страница группы на фронте (кокпит преподавателя).** Отдельная фронт-страница (`PageRoutes`, `ThemeCompatService`), гейт: `groups.teacher_id == current_user_id` (или `Admin`). Содержит: программу + расписание, переключатели видимости, ростер, журнал успеваемости и ленту активности группы. Преподаватель — фронтовая роль, не сотрудник wp-admin. Тяжёлый CRUD (создание групп, зачисление, PII) остаётся в админке. Ученик/родитель видят отфильтрованный срез ленты (свои события + публикации).

---

## 5. Этапы реализации

Порядок — по зависимостям; каждый этап самостоятельно поставляем.

### Этап 0 — Личные кабинеты (роле-дашборды)
**Цель:** превратить `/profile` в роле-ориентированный кабинет — «полки», которые наполняются дальше.
**Состав:** расширение `ProfileController` + шаблоны по ролям (`ThemeCompatService` для публичных страниц). Преподаватель видит свои группы; ученик — свои группы (из `student_records`); родитель — детей.
**Готово, когда:** каждая роль после входа видит свой дашборд с актуальным списком групп/детей; данные берутся через существующие репозитории.

### Этап 1 — Уроки (банк, CPT `{key}_lessons`)
**Цель:** переиспользуемый авторинг уроков преподавателем.
**Состав:** CPT `{key}_lessons` + метабоксы (тема, теория, бакеты практика/СР/ДЗ). Вставка существующих `tasks` в бакеты (когда урок привязан к типу заданий) — UI-селектор задач из библиотеки предмета. `LessonManager`, `LessonAuthoringService`, `LessonDTO`.
**Готово, когда:** преподаватель создаёт урок как со свободным контентом, так и с вставленными заданиями; урок сохраняется в банк предмета и доступен для переиспользования.

### Этап 2 — Программа группы: расписание, доставка, кокпит
**Цель:** собрать «курс группы» из уроков, управлять доступом во времени, дать преподавателю фронт-страницу группы.
**Состав:** таблица `fs_lms_group_lessons` (заменяет `groups.schedule` text). Конструктор программы (порядок, дата, `teacher_user_id` занятия, выбор уроков из банка или новых). Управление видимостью (`hidden`/`open`/`archived`). `GroupLessonRepository`, `ScheduleService`, `LessonVisibilityService`. Фронт-страница группы (`GroupCockpitController`, гейт по `groups.teacher_id`). Старт лог-канала: `fs_lms_course_activity_log` + `CourseActivityLogRepository` + `CourseActivitySubscriber` (события расписания/видимости). Вывод открытых уроков ученику в кабинете.
**Готово, когда:** преподаватель формирует программу и расписание на фронт-странице группы; ученик видит материалы открытого урока; скрытые недоступны; действия пишутся в ленту группы.

### Этап 3 — Сдача работ и прогресс (gradebook)
**Цель:** ученик сдаёт работы, преподаватель проверяет и оценивает.
**Состав:** таблица `fs_lms_submissions` (вложения — WP Media Library, `attachment_id`). Дедлайны: `group_lessons.homework_due_at` → снапшот в `submissions.due_at`; флаг `allow_late`; «просрочено» вычисляется (`submitted_at > due_at`). Сдача практики/СР/ДЗ. Проверка преподавателем (балл, статус, возврат). `SubmissionRepository`, `SubmissionService`. `GradebookService` — **read-model** (UNION submissions+attempts → `GradebookEntryDTO`), без таблицы оценок. События сдачи/проверки → лента активности. Отображение в кабинетах ученика/родителя/преподавателя.
**Готово, когда:** полный цикл `выдано → сдано (со сроком) → проверено/возвращено` с баллами виден всем; журнал успеваемости строится из fact-таблиц.

### Этап 4 — Контрольные и экзамены (assessment-движок)
**Цель:** контрольные на темах и итоговый экзамен с таймером, фиксацией ответов и баллов.
**Состав:** CPT `{key}_assessments` (набор `tasks` + конфиг). Таблицы `fs_lms_assessment_attempts` (`attempt_number` + `UNIQUE(assessment_id, student_person_id, attempt_number)`), `fs_lms_assessment_answers`. `AttemptService` — старт попытки (инкремент `attempt_number` без COUNT), **server-side `deadline_at`**, авто-сабмит по истечении/`expired` (lazy-проверка по времени запроса + страховочный cron `CronHook`). `AutoGradeService` — авто-проверка полей-ответов (`*_answer`, числовые/строгое сравнение); код/файл → ручная проверка преподавателем. События старта/сабмита → лента активности. Фронтенд прохождения: таймер синхронизируется с сервером, периодическое сохранение ответов.
**Готово, когда:** ученик проходит контрольную/экзамен с обратным отсчётом; ответы и баллы фиксируются; авто-проверяемые задания оцениваются автоматически, остальные — преподавателем; результат попадает в gradebook.

### Этап 5 — Записи занятий из S3
**Цель:** автоподгрузка записи к занятию.
**Состав:** `S3RecordingService`, запись в `group_lessons.recording_url`; привязка по группе+дате. Зависит от инфраструктуры S3 (доступы, именование файлов).
**Готово, когда:** запись занятия автоматически появляется в открытом уроке группы.

---

## 6. Будущее (вне текущего охвата)

- **Онлайн-курсы (self-paced):** режим `delivery_mode=online` у группы либо отдельный путь записи; drip-доступ по прогрессу вместо календаря.
- **Переиспользуемый курс-шаблон (`Course`):** верхнеуровневая программа, копируемая в группу при создании (когда понадобится тиражировать целый курс, а не отдельные уроки).
- **Аналитика/отчёты по успеваемости**, экспорт оценок.

---

## 7. Открытые вопросы

1. `{key}_lessons` per-subject **vs** единый `fs_lms_lesson` с `subject_key` (рекомендация — per-subject).  -> Ответ: per-subject
2. Новые capability отдельно (`AuthorLessons`/`GradeWork`/`ManageSchedule`) **vs** переиспользовать `ManageLMSAssignments`.  -> Ответ: переиспользуем базовые для учителя и администратора
3. Миграция текущего текстового `groups.schedule` в `fs_lms_group_lessons` — нужна ли (или начинаем с чистого расписания). -> Ответ: расписание группы = расписание занятий. Нужно будет в будущем привязать публикацию (открытие доступа для учеников) к этому расписанию.
4. Хранение ответов попытки: отдельная таблица `assessment_answers` (рекомендация, удобно для проверки) **vs** JSON в `attempts`.  -> Ответ: отдельная таблица
5. Нужна ли шифровка/анонимизация ответов экзамена (PII-уровень) — по умолчанию нет, ответы не ПДн. -> Ответ: не нужна
6. Вложения работ/записи: WP Media Library **vs** прямые ссылки на S3. -> Ответ: через s3 только видео, все остальные файлы - WP Media Library
7. GradebookEntry — таблица **или** read-model? -> Ответ: read-model (UNION submissions+attempts → `GradebookEntryDTO`), без таблицы. Отдельная `fs_lms_grade_entries` — позже и только под ручные/бонусные оценки без источника.
8. «Журнал группы» — отдельная таблица на каждую группу? -> Ответ: нет (анти-паттерн). Один канал `fs_lms_course_activity_log`, разрез по `group_id`. Реюз лог-инфраструктуры (repository + subscriber).
9. Страница группы на фронте? -> Ответ: да, кокпит преподавателя (гейт по `groups.teacher_id`); тяжёлый CRUD остаётся в админке.
10. «Взрыв CPT» при десятках предметов — менять модель? -> Ответ: нет, остаёмся per-subject; симптомы (меню/права/REST/поиск) снимаем конфигом (`show_in_menu=false` + единое меню, общий `capability_type`, `show_in_rest=false`, `exclude_from_search`). Без рефактора tasks/articles.
11. Дедлайны ДЗ — где хранить? -> Ответ: источник `group_lessons.homework_due_at` + `allow_late`; снапшот в `submissions.due_at` (для индив. продления); «просрочено» вычисляется.
12. Преподаватель конкретного занятия. -> Ответ: `group_lessons.teacher_user_id` (WP user, **НЕ** person — преподаватели не персоны). `created_by/updated_by_user_id` — только на fact-таблицах; у CPT (`lessons`/`assessments`) — нативные `post_author`/`post_modified`.
