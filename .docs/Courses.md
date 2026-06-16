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
  • Submission (fs_lms_submissions)         — сдача практики/СР/ДЗ + проверка + балл
  • AssessmentAttempt (fs_lms_assessment_attempts) — попытка: старт/дедлайн server-side, статус, балл
  • AssessmentAnswer (fs_lms_assessment_answers)   — ответ на задание в попытке + балл
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

> Открытый вопрос: per-subject `{key}_lessons` (консистентно с tasks/articles) **vs** единый CPT `fs_lms_lesson` с `subject_key` в мете. Рекомендация — per-subject, т.к. урок ссылается на задания одного предмета.

### Новые таблицы (факты)

Добавляются в `Migration_1_0_0::up()` и `down()` (не отдельным файлом — см. CLAUDE.md), имена через расширение `Enums\TableName`. Черновик DDL:

```sql
-- Программа группы = «курс» + расписание + доставка (заменяет groups.schedule text)
fs_lms_group_lessons (
  id            int unsigned PK,
  group_id      smallint unsigned NOT NULL,   -- → fs_lms_groups.id
  lesson_id     bigint unsigned   NOT NULL,   -- → CPT {key}_lessons (post ID)
  position      smallint unsigned NOT NULL DEFAULT 0,
  scheduled_at  datetime DEFAULT NULL,        -- дата/время занятия
  visibility    enum('hidden','open','archived') NOT NULL DEFAULT 'hidden',
  opened_at     datetime DEFAULT NULL,        -- когда преподаватель открыл доступ
  recording_url varchar(1000) DEFAULT NULL,   -- запись из S3 (этап 5)
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
  attachment_url    varchar(1000) DEFAULT NULL,
  status            enum('assigned','submitted','graded','returned') NOT NULL DEFAULT 'assigned',
  score             decimal(6,2) DEFAULT NULL,
  max_score         decimal(6,2) DEFAULT NULL,
  graded_by_user_id bigint unsigned DEFAULT NULL,
  submitted_at, graded_at, created_at, updated_at,
  KEY student_person_id, KEY group_lesson_id, KEY status
)

-- Попытка прохождения контрольной/экзамена (таймер server-side)
fs_lms_assessment_attempts (
  id                int unsigned PK,
  assessment_id     bigint unsigned NOT NULL,  -- → CPT {key}_assessments
  student_person_id int unsigned NOT NULL,
  group_id          smallint unsigned DEFAULT NULL,
  started_at        datetime NOT NULL,
  deadline_at       datetime NOT NULL,         -- started_at + time_limit, считается на сервере
  submitted_at      datetime DEFAULT NULL,
  status            enum('in_progress','submitted','graded','expired') NOT NULL DEFAULT 'in_progress',
  total_score       decimal(6,2) DEFAULT NULL,
  max_score         decimal(6,2) DEFAULT NULL,
  graded_by_user_id bigint unsigned DEFAULT NULL,
  created_at, updated_at,
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
```

> Внедрение в dev: после правки DDL сбросить `fs_lms_schema_version` в `0.0.0` и перезагрузить страницу WP (см. CLAUDE.md → «Миграции в dev-окружении»).

### Что остаётся в `wp_options`

Глобальные настройки модуля курсов (если появятся) — через `Enums\OptionName` + `OptionsRepositories`. Структура урока/экзамена живёт в post-meta CPT, не в options.

---

## 3. Раскладка по слоям

| Слой | Что добавляется |
|---|---|
| **Enums** | `TableName`: +`GroupLessons`, `Submissions`, `AssessmentAttempts`, `AssessmentAnswers`. `Capability`: +`AuthorLessons`, `GradeWork`, `ManageSchedule` (или переиспользовать `ManageLMSAssignments`). `Nonce`: +`AuthorLesson`, `SaveSchedule`, `SetLessonVisibility`, `SubmitWork`, `GradeWork`, `StartAttempt`, `SubmitAttempt`, `GradeAttempt`. `AjaxHook`: соответствующие cases. `PageRoutes`/`ShortCode`: при необходимости — отдельные view-страницы урока/экзамена |
| **Repositories** (`WPDBRepositories`) | `GroupLessonRepository`, `SubmissionRepository`, `AssessmentAttemptRepository`, `AssessmentAnswerRepository` — CRUD по таблицам |
| **Managers** | `LessonManager`, `AssessmentManager` — обёртки над CPT/мета (CRUD post + meta) |
| **Services** (`Services/Course/`, `Services/Assessment/`) | `LessonAuthoringService`, `ScheduleService`, `LessonVisibilityService`, `SubmissionService`, `GradebookService`, `AssessmentService`, `AttemptService` (старт/таймер/сабмит), `AutoGradeService` (сверка `*_answer` полей), `S3RecordingService` (этап 5) |
| **Controllers** | `LessonController`, `ScheduleController`, `SubmissionController`, `AssessmentController` — только регистрация хуков, делегирование. Расширение `ProfileController` под роле-кабинеты |
| **Controllers/Pages** | по образцу `TaskPageController` (инъекция шаблона через `template_include`) — `LessonPageController`, `AssessmentPageController` |
| **Callbacks** (`Callbacks/Course/`, `Callbacks/Assessment/`) | AJAX-обработчики: авторинг урока, сборка программы группы, видимость, сдача работ, проверка, старт/сабмит/проверка попытки. `Authorizer` + `Sanitizer` + `AjaxResponse` |
| **DTO** (`DTO/Course/`, `DTO/Assessment/`) | `LessonDTO`, `LessonViewDTO`, `GroupLessonDTO`, `ScheduleDTO`, `SubmissionDTO`, `GradeDTO`, `AssessmentDTO`, `AttemptDTO`, `AttemptResultDTO` |
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

Доступ ученика к материалам = `активный student_record в группе` **И** `group_lesson.visibility = open`. Это не WP-capability, а доменная проверка в сервисах.

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

### Этап 2 — Программа группы: расписание и доставка
**Цель:** собрать «курс группы» из уроков и управлять доступом во времени.
**Состав:** таблица `fs_lms_group_lessons` (заменяет `groups.schedule` text — миграция текущего текста опциональна). Конструктор программы (порядок, дата, выбор уроков из банка или новых). Управление видимостью (`hidden`/`open`/`archived`) — «открыть на занятии» / «оставить после». `GroupLessonRepository`, `ScheduleService`, `LessonVisibilityService`. Вывод открытых уроков ученику в кабинете.
**Готово, когда:** преподаватель формирует программу группы и расписание; ученик видит материалы открытого урока во время и после занятия; скрытые уроки недоступны.

### Этап 3 — Сдача работ и прогресс (gradebook)
**Цель:** ученик сдаёт работы, преподаватель проверяет и оценивает.
**Состав:** таблица `fs_lms_submissions`. Сдача практики/СР/ДЗ (текст/вложение). Проверка преподавателем (балл, статус, возврат на доработку). `SubmissionRepository`, `SubmissionService`, `GradebookService`. Отображение статусов и оценок в кабинетах ученика, родителя, преподавателя.
**Готово, когда:** полный цикл `выдано → сдано → проверено/возвращено` с баллами виден всем сторонам.

### Этап 4 — Контрольные и экзамены (assessment-движок)
**Цель:** контрольные на темах и итоговый экзамен с таймером, фиксацией ответов и баллов.
**Состав:** CPT `{key}_assessments` (набор `tasks` + конфиг). Таблицы `fs_lms_assessment_attempts`, `fs_lms_assessment_answers`. `AttemptService` — старт попытки, **server-side `deadline_at`**, авто-сабмит по истечении/`expired` (lazy-проверка по времени запроса + страховочный cron `CronHook`). `AutoGradeService` — авто-проверка полей-ответов (`*_answer`, числовые/строгое сравнение); код/файл → ручная проверка преподавателем. Фронтенд прохождения: таймер синхронизируется с сервером, периодическое сохранение ответов.
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
