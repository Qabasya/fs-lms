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

1. **Четыре переиспользуемых банка-определения per-subject: `tasks → works → lessons → courses`.**
   Каждый уровень ссылается на предыдущий и переиспользуется на следующем. Все четыре — CPT,
   регистрируются тем же механизмом, что `{key}_tasks`/`{key}_articles`.
2. **Курс — переиспользуемый шаблон (CPT `{key}_courses`), а не таблица.** *Разворот прежнего
   решения* (раньше «верхнеуровневый Course не вводим»): теперь у нас есть **банк курсов**.
   Курс = упорядоченный список уроков. Группе курс **назначается** — список уроков
   **снапшотится** в `fs_lms_group_lessons` (проведение), и дальше группа правит свою программу
   независимо от шаблона. Это тот «переиспользуемый курс-шаблон», что раньше лежал в §6 «Будущее».
3. **Работа — переиспользуемый типизированный пул заданий (CPT `{key}_works`).** Тип работы
   `practice | independent | homework` живёт на самой работе. Урок ссылается на работы, работа —
   на задания. *Заменяет инлайн-бакеты урока* из прежней версии Этапа 1.
4. **Урок содержит только работы, не отдельные задачи.** Задачи всегда упакованы в работу.
   Урок = тема + теория (inline или ссылка на статью) + упорядоченные ссылки на работы.
5. **Per-group усиление — дельта `extra_work_ids` на строке `group_lessons`** (слой проведения),
   а не правка общего урока. Группа-2 «сильнее» → её строка программы несёт +1 работу; общий урок
   чист, группа-1 не задета. Крупное расхождение программы — **форк урока** (дубликат-вариант).
6. **Связь по ссылке (`*_id`), не копией.** Правка задания/работы/урока отражается везде, где он
   стоит (модель Tutor Content Bank). Исключение — снапшот списка уроков курса в группу (намеренная
   развязка проведения от шаблона). «Создать новое» из конструктора создаёт реальный пост в банке.
7. **Онлайн-ученики откладываются.** Фокус — очные, проходящие через текущие формы `apply` + `join`.
8. **Урок не обязан содержать работы.** Может быть просто занятие (тема + теория) — работы необязательны.
9. **Хранение: CPT для контента + кастомные таблицы для фактов** — строго по текущему расколу архитектуры.
10. **Видимость банков — весь предмет, фильтр «мои» (`post_author`) по умолчанию.** Жёсткой стены
    «только свои» внутри предмета нет (итоговый экзамен собирается из общего пула предмета).
11. **«Коллекции» заданий = пользовательская таксономия** поверх номеров заданий (тематические
    наборы, напр. «Циклы Python») — для удобного выбора в селекторе работы. Без нового слоя.
12. **Меню «Обучение» — одно top-level + сабменю на каждый банк** (Курсы / Уроки / Работы / Задания /
    Статьи), cap `manage_lms_assignments`. Все subject-CPT `show_in_menu => false`. **Мягкий** скоуп
    по предметам препода (через его группы): меню по умолчанию показывает предметы препода, чтобы
    облегчить выбор, — но чужое не прячет (без `current_screen`-guard). Детали — §3, §5 Этап 1.
13. **Доступ к материалам = членство (грант), а не подписка; роль при отчислении не меняется.**
    Зачисление даёт доступ к бэк-каталогу группы; отчисление по умолчанию **сохраняет** read-only
    доступ к пройденному. Гейт — по `student_record` через единый `LessonAccessPolicy` (не по роли).
    Кабинет по умолчанию не блокируется; `retain_after_expulsion` — админ-политика. Детали — §4.
14. **Содержимое урока замораживается при публикации (copy-on-publish), не при назначении.** Пока строка
    программы `hidden` — `work_ids` читаются из живого урока (правки эталона долетают, это фаза подготовки).
    При переводе в `open` набор работ снапшотится в `group_lessons.work_ids_snapshot` (триггер — `opened_at`)
    и дальше для этой группы неизменен: доставленное стабильно, сдачи не сиротеют. Канонические правки текут
    в будущие группы и ещё-не-открытые уроки. Эффективные работы строки =
    `(opened ? work_ids_snapshot : lesson.work_ids) + extra_work_ids`. **Теория остаётся живой** (к ней не
    привязаны сдачи). «Подтянуть новую версию в живую группу» — отдельное опц. действие (перезапись снапшота).
15. **Событийная лента ≠ источник баллов.** `fs_lms_learning_events` — одна append-only таблица всех
    доменных событий обучения (фид, таймлайн, аудит, engagement-аналитика); повышена из «ленты группы»,
    `group_id`/`subject_key` nullable. Текущее состояние и **баллы** — fact-таблицы (`submissions`/`attempts`),
    gradebook — read-model поверх них (§7 #7); правки баллов идут в факты, не в ленту. Security/PII-аудит —
    отдельный `fs_lms_audit_log` (другая приватность/retention). Каждый мутирующий сервис **дополнительно**
    эмитит событие в ленту через `LogEventDispatcher`. «Пять таблиц для отчёта» → 1 лента + 2 факта за read-моделью.
16. **Контент не удаляется физически при наличии ссылок; ретайр — через `archived`.** Инвариант:
    ссылка (`task↔work↔lesson↔course` + delivery: `group_lessons`, snapshot, `submissions`) резолвится,
    **пока существует пост**; статус (`draft`/`publish`/`archived`) влияет только на видимость в
    **селекторах** и НЕ рвёт ссылки. Поэтому удаление (trash/force-delete) **запрещено при usage > 0**
    (единый `ContentUsageService`, он же питает бейдж §7 #19/T1.26). Жизненный цикл: `draft` → `publish`
    ⇄ `archived` (убран из селекторов для новых ссылок; существующие ссылки и доставленное продолжают
    резолвиться). Физическое удаление — только для orphan (`usage = 0`), нативным WP trash. Гейт —
    `pre_trash_post`/`pre_delete_post` + подмена row-action «Удалить» → «В архив».
17. **Capability-модель банков: новые банки на `fs_lms_content`, задания тоже; статьи — нативный `post`.**
    Уроки / работы / курсы / **задания** — `capability_type => 'fs_lms_content'` + `map_meta_cap => true`;
    препод (`lms_teacher`) и админ имеют весь набор `fs_lms_content` → **полный авторинг, включая публикацию**,
    без выдачи широких WP-прав (`publish_posts` и т.п. на все типы постов сайта). **Статьи остаются на
    дефолтных `post`-правах**: у препода есть `edit_posts`, но нет `publish_posts` → он создаёт только
    **черновики** статей (публикует админ) — осознанное различие задание↔статья при одной роли. Авторинг
    заданий идёт через существующую модалку (название → тип → бойлерплейт), статьи — нативный редактор.
    Раздел **«Предметы» открыт преподу** (`ManageLMSAssignments`) для ведения контента и таксономий своего
    предмета; **создание/удаление самих предметов остаётся за админом** (`manage_options`, каскад опасен).
    «Обучение» содержит только новые банки (Курсы/Уроки/Работы); Задания/Статьи живут в «Предметах», не дублируются.
18. **Поток контента: Курс → Урок → Работа → Элемент (задание или задача).** Курс = упорядоченные
    уроки; урок = теория + упорядоченные работы; работа = упорядоченный список элементов двух видов:
    **(а)** публичное задание с сайта (`{key}_tasks`) или **(б)** приватная задача из банка задач
    (`fs_lms_problems`). Студент видит единый упорядоченный список «кружочков» — элементы разного вида
    отрисовываются по-разному (публичное задание vs приватная задача), но порядок единый.
19. **Банк задач `fs_lms_problems` — глобальный CPT, вне предметов, вне фронта.** В отличие от
    `{key}_tasks`, `fs_lms_problems` не привязан к конкретному предмету: одну задачу «Создайте профиль в
    GitHub» можно добавить и в работу по Информатике, и в работу по Python. CPT регистрируется один раз
    (без per-subject prefixing), `show_in_rest=false`, `exclude_from_search=true` — студенты его не видят.
    Меню — в «Обучение» рядом с Заданиями. `capability_type='fs_lms_content'` — те же права, что у уроков и работ.
    **Метаданные задачи:** автор — нативный `post_author`; тематика — свободная таксономия `problem_tag`
    (аналог WP-тегов, не иерархическая; термы вроде «Git», «Сортировка», «Python-базовые»; несколько тегов
    на задачу). Таксономия используется как фильтр в селекторе работы. **Usage:** `ContentUsageService`
    показывает «используется в N работах» + ссылки на работы-потребители; предмет выводить не нужно —
    он читается из работ. Иерархические категории не вводим — при небольшом банке overhead не оправдан.
    **Шаблоны редактора:** `fs_lms_problems` использует ту же систему `TemplateRegistry` / `TemplateResolver` /
    `BaseTemplate` + `FieldInterface`, что и `{key}_tasks`. Тип шаблона хранится в `PostMetaName::TemplateType`.
    Доступны те же шаблоны (с файлом, с кодом, просто ответ); будущие шаблоны-тесты (чекбоксы, радио) добавляются
    в тот же реестр без нового слоя. Выбор шаблона — в метабоксе на экране редактирования задачи (не в модалке
    создания, там только название).
20. **`WorkDTO.task_ids` → `item_ids: int[]` — единый упорядоченный список WP post ID.** Тип элемента
    определяется динамически через `get_post_type($id)`: `{key}_tasks` → публичное задание, `fs_lms_problems`
    → приватная задача. Переименование затрагивает `WorkDTO`, `WorkManager`, `WorkTemplate`, `ContentUsageService`
    (должен считать usage задач в проблемах отдельно от usage задач в работах). В `item_ids` элементы разных
    типов могут чередоваться в любом порядке — без разделения на группы.

### Ориентир из практики: Tutor LMS

Разобрали зрелый WordPress-LMS **Tutor LMS** ([доки](https://docs.themeum.com/tutor-lms/)) как
референс по авторингу и переиспользованию контента. Ключевой механизм — **Content Bank**:
централизованный банк переиспользуемых уроков, вопросов и заданий, сгруппированных в **Collections**;
контент **линкуется** в курсы, не копируется (рядом виден счётчик «используется в N курсах» —
[Content Bank docs](https://docs.themeum.com/tutor-lms/content-bank/)).

| Tutor LMS | Что это | Наш аналог |
|---|---|---|
| Course | self-paced курс | CPT `{key}_courses` — **шаблон** (упорядоченные уроки); группе **назначается** (снапшот в `fs_lms_group_lessons` = когорта + расписание) |
| Topic | раздел/глава | (опц.) секции внутри курса / программы группы |
| Lesson | урок (текст/видео/вложения) | CPT `{key}_lessons` (тема + теория + ссылки на работы) |
| Quiz | авто-проверка, таймер, типы вопросов | Assessment / экзамен (Этап 4) |
| Assignment (определение) | задание с ручной проверкой, баллами, дедлайном | **Work** — CPT `{key}_works` (типизированный пул заданий) |
| Assignment (сдача) | сдача ученика | Submission (Этап 3) |
| Content Bank | банк переиспользуемого контента | банки `{key}_tasks` / `{key}_works` / `{key}_lessons` / `{key}_courses` |
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
Subject (предмет) — четыре банка-определения (CPT), все per-subject, связь по ссылке:

  Task   (CPT {key}_tasks)    — атом: условие / ответ / решение (ЕГЭ или своё, напр. Python)
    ▲ ссылается
  Work   (CPT {key}_works)    — РАБОТА: тип (practice|independent|homework)
    │                            + упорядоченные ссылки на tasks + инструкция/настройки.
    ▲ ссылается                  Переиспользуется в разных уроках.
  Lesson (CPT {key}_lessons)  — УРОК: тема + теория (inline / ссылка на article)
    │                            + упорядоченные ссылки на works. ТОЛЬКО работы, не задачи.
    ▲ ссылается                  Переиспользуется в разных курсах. Может быть без работ.
  Course (CPT {key}_courses)  — КУРС: упорядоченные ссылки на lessons (+ опц. секции).
                                 Шаблон; тиражируется в группы.

  Article (CPT {key}_articles) — теория (источник для Lesson.theory_article_id)
  Assessment (CPT {key}_assessments) — контрольная/экзамен: набор tasks + конфиг (Этап 4)

Проведение во времени (таблицы):

  Group (fs_lms_groups) ──назначить Course──► снапшот списка уроков в:
    GroupLesson (fs_lms_group_lessons) — строка программы группы:
        group_id × lesson_id × порядок × дата × видимость × extra_work_ids(дельта) × запись(S3)

Факты обучения (таблицы):
  • Submission (fs_lms_submissions)         — сдача работы (work_id) + дедлайн (due_at) + проверка + балл
  • AssessmentAttempt (fs_lms_assessment_attempts) — попытка: attempt_number, старт/дедлайн server-side, статус, балл
  • AssessmentAnswer (fs_lms_assessment_answers)   — ответ на задание в попытке + балл
  • LearningEvent (fs_lms_learning_events)   — append-only лента ВСЕХ доменных событий обучения
                                               (фид/таймлайн/аналитика; group_id, subject_key nullable; НЕ источник баллов — §0 №15)

Gradebook (журнал успеваемости) — НЕ таблица: read-model поверх submissions + attempts (см. §2).
```

### Связи

- `Task ← Work ← Lesson ← Course` — цепочка по ссылке (`*_id`), все четыре банка принадлежат предмету
  (per-subject CPT); каждый ссылается только на сущности своего предмета.
- `Work (N) ← (M) Lesson` — одна работа стоит в нескольких уроках; `Lesson (N) ← (M) Course` — один
  урок в нескольких курсах. Переиспользование — по ссылке (правка отражается везде).
- `Course (1) ──назначить──► снапшот в Group` — список уроков курса копируется в `fs_lms_group_lessons`
  при назначении; дальше программа группы независима от шаблона (правка курса не дёргает живые группы).
- `Group (1) → (N) GroupLesson (N) → (1) Lesson` — программа группы = упорядоченные строки.
  Эффективные работы строки = `lesson.work_ids + group_lesson.extra_work_ids` (дельта усиления группы).
- `GroupLesson (1) → (N) Submission` — сдачи работ привязаны к конкретной строке программы и ученику.
- `Assessment (1) → (N) AssessmentAttempt (1) → (N) AssessmentAnswer` — определение → попытки → ответы.
- Все ученики/преподаватели — через `persons.id` и `persons.wp_user_id`; доступ ученика к материалам
  гейтится через `student_records` (членство в группе), **не** через capability.

---

## 2. Хранение

### Новые CPT (контент, авторинг преподавателем)

Регистрируются тем же механизмом, что `{key}_tasks` / `{key}_articles` (`SubjectCPTRegistrar` + `CPTManager`, фильтр `fs_lms_cpt_args`), per-subject. Все — `show_in_menu => false` + единое меню «Обучение» (§3); конфиг против «взрыва CPT» — ниже.

- **`fs_lms_problems`** — банк приватных задач (глобальный, **не** per-subject). `post_title` = формулировка задачи,
  `post_content` = условие/инструкция. Нет таксономий. `show_in_rest=false`, `exclude_from_search=true` (фронт не видит).
  `capability_type='fs_lms_content'`. Меню «Обучение» → «Задачи» (рядом с Заданиями предметов).
  Одна задача может ссылаться из работ любого предмета — без копирования. (§0 №19)
- **`{key}_works`** — работа (типизированный пул элементов). `post_title` = название работы. Мета через `PostMetaName::Meta`:
  - `work_type` — `practice | independent | homework` (enum `WorkType`)
  - `item_ids[]` — **упорядоченные WP post ID** элементов двух видов: публичные задания (`{key}_tasks`) и
    приватные задачи (`fs_lms_problems`). Тип определяется через `get_post_type($id)`. Замена `task_ids[]` (§0 №20).
  - `instructions` — опц. свободный текст (что делать, на что обратить внимание)
  - `settings` — опц. (на будущее: `max_score`, дефолтный дедлайн-офсет; потребитель — Этап 3)
- **`{key}_lessons`** — урок. `post_title` = тема, `post_content` = теория (inline). Мета:
  - `theory_article_id` — опц. ссылка на `{key}_articles` (переопределяет inline-теорию)
  - `work_ids[]` — **упорядоченные ссылки** на `{key}_works`. Урок содержит **только работы**, не задачи.
    Может быть пустым (просто занятие: тема + теория).
- **`{key}_courses`** — курс (шаблон). `post_title` = название курса, `post_content` = описание. Мета:
  - `lesson_ids[]` — **упорядоченные ссылки** на `{key}_lessons`
  - опц. `sections[]` — секции/темы (группировка уроков; на будущее, аналог Tutor Topic)
- **`{key}_assessments`** — контрольная/экзамен (Этап 4). Мета:
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
-- Программа группы = снапшот курса + расписание + доставка (заменяет groups.schedule text)
-- Провенанс: fs_lms_groups получает колонку course_id (какой курс назначен; для «сбросить к шаблону»).
fs_lms_group_lessons (
  id              int unsigned PK,
  group_id        smallint unsigned NOT NULL,   -- → fs_lms_groups.id
  lesson_id       bigint unsigned   NOT NULL,   -- → CPT {key}_lessons (post ID); снапшот списка из курса
  position        smallint unsigned NOT NULL DEFAULT 0,
  work_ids_snapshot longtext DEFAULT NULL,      -- JSON: заморозка lesson.work_ids при публикации (NULL = ещё не открыт → живой урок)
  extra_work_ids  longtext DEFAULT NULL,        -- JSON: доп. работы ТОЛЬКО для этой группы (дельта усиления)
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
-- Эффективные работы строки = (opened? work_ids_snapshot : lesson.work_ids) + extra_work_ids.
-- work_ids_snapshot заполняется при первой публикации (open) — copy-on-publish (§0 №14).
-- Назначение курса группе = bulk-insert строк по lesson_ids курса (снапшот списка), затем группа независима.

-- Сдача работы (практика/СР/ДЗ)
fs_lms_submissions (
  id                int unsigned PK,
  student_person_id int unsigned NOT NULL,    -- → fs_lms_persons.id
  group_lesson_id   int unsigned NOT NULL,    -- → fs_lms_group_lessons.id
  work_id           bigint unsigned NOT NULL, -- → CPT {key}_works (какую работу сдают)
  work_type         enum('practice','independent','homework') NOT NULL, -- снапшот из работы
  task_id           bigint unsigned DEFAULT NULL,  -- опц. сдача по конкретному заданию внутри работы
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

-- Единая append-only лента доменных событий обучения (новый лог-канал, по образцу fs_lms_audit_log).
-- НЕ источник баллов (§0 №15): текущие баллы — fact-таблицы (submissions/attempts) + gradebook read-model.
fs_lms_learning_events (
  id            int unsigned PK,
  subject_key   varchar(50) DEFAULT NULL,    -- предмет (кросс-предметная аналитика); NULL = вне предмета
  group_id      smallint unsigned DEFAULT NULL, -- NULL = событие вне группы (правка банка, курс-шаблон)
  actor_user_id bigint(20) unsigned DEFAULT NULL,
  actor_role    varchar(50) DEFAULT NULL,
  action        varchar(40) NOT NULL,        -- course_assigned | lesson_published | submission_made | submission_graded | attempt_started | attempt_submitted | lesson_added_to_program | schedule_changed | recording_attached
  entity_type   varchar(30) DEFAULT NULL,
  entity_id     varchar(100) DEFAULT NULL,
  is_public     tinyint(1) NOT NULL DEFAULT 1, -- виден ли срез ученику/родителю (свои события + публикации, §4)
  created_at    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (group_id, created_at), KEY (subject_key, created_at), KEY actor_user_id
)
-- Один канал на всё (не таблица-на-группу/предмет). Фид группы = WHERE group_id=X ORDER BY created_at DESC;
-- таймлайн = WHERE actor_user_id=Y; аналитика = агрегат по action/subject_key/created_at + пагинация.
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
| **Enums** | `TableName`: +`GroupLessons`, `Submissions`, `AssessmentAttempts`, `AssessmentAnswers`, `LearningEvents`. `WorkType`: `practice/independent/homework`. `Capability`: **переиспользуем** `ManageLMSAssignments` + `Admin` — новые caps НЕ вводим (п.13 #2/§7). `Nonce`: +`AuthorWork`, `AuthorLesson`, `AuthorCourse`, `AssignCourse`, `SaveSchedule`, `SetLessonVisibility`, `SubmitWork`, `GradeWork`, `StartAttempt`, `SubmitAttempt`, `GradeAttempt`. `AjaxHook`: соответствующие cases. `Menu`: top-level «Обучение» + сабменю-кейсы. `PostTypeResolver`: `works()`/`courses()`. `PageRoutes`/`ShortCode`: кокпит группы + view-страницы |
| **Repositories** (`WPDBRepositories`) | `GroupLessonRepository`, `SubmissionRepository`, `AssessmentAttemptRepository`, `AssessmentAnswerRepository` — CRUD. `Log/LearningEventRepository` — единая лента событий обучения (по образцу `Log/AuditLogRepository`). Gradebook — **без** репозитория-таблицы (read-model UNION в сервисе). Банки (works/lessons/courses) — через Managers поверх CPT, не WPDB-репо |
| **Managers** | `WorkManager`, `LessonManager`, `CourseManager`, `AssessmentManager` — обёртки над CPT/мета (CRUD post + meta) |
| **Services** (`Services/Course/`, `Services/Assessment/`) | `WorkAuthoringService`, `LessonAuthoringService`, `CourseAuthoringService` (резолв ссылок/кандидатов селекторов), `TeacherSubjectsService` (препод → предметы через группы, для скоупа меню), `CourseAssignmentService` (снапшот курса в `group_lessons`), `ScheduleService`, `LessonVisibilityService`, `EffectiveWorksResolver` (`lesson.work_ids + extra_work_ids`), `SubmissionService`, `GradebookService` (**read-model**: UNION submissions+attempts → `GradebookEntryDTO`), `AssessmentService`, `AttemptService`, `AutoGradeService`, `S3RecordingService` (Этап 5) |
| **Controllers** | `LearningMenuController` (top-level «Обучение» + сабменю банков, скоуп по предметам препода), `WorkController`, `LessonController`, `CourseController`, `ScheduleController`, `SubmissionController`, `AssessmentController`, `GroupCockpitController` — регистрация хуков. Метабоксы — `WorkMetaBoxController`/`LessonMetaBoxController`/`CourseMetaBoxController`. `Subscribers/LearningEventSubscriber`. Расширение `ProfileController` под роле-кабинеты |
| **Controllers/Pages** | по образцу `TaskPageController` (`template_include`) — `LessonPageController`, `AssessmentPageController` |
| **Callbacks** (`Callbacks/Course/`, `Callbacks/Assessment/`) | AJAX: авторинг работы (селектор заданий), урока (селектор работ), курса (селектор уроков), назначение курса группе, сборка/видимость программы, сдача/проверка, попытки. `Authorizer` + `Sanitizer` + `AjaxResponse` |
| **DTO** (`DTO/Course/`, `DTO/Assessment/`) | `WorkDTO`, `LessonDTO`, `CourseDTO`, `LessonViewDTO`, `GroupLessonDTO`, `ScheduleDTO`, `SubmissionDTO`, `GradeDTO`, `GradebookEntryDTO`, `LearningEventDTO`, `AssessmentDTO`, `AttemptDTO`, `AttemptResultDTO` |
| **Registrars** | расширение `SubjectCPTRegistrar` — регистрация `{key}_works`, `{key}_lessons`, `{key}_courses`, `{key}_assessments` (все `show_in_menu => false`); регистрация статуса `fs_archived` |
| **Жизненный цикл / удаление** | `ContentUsageService` (usage read-model по всем источникам ссылок — банк-меты + delivery-факты), `ContentLifecycleService` (archive/unarchive, статус `fs_archived`), `ContentDeletionGuard` (`pre_trash_post`/`pre_delete_post` + подмена row-action) — общие для всех 4 банков; §0 №16 |
| **MetaBoxes** | `Templates`/`Fields`: работа (тип + селектор заданий), урок (селектор работ), курс (упорядоченный селектор уроков), экзамен (список заданий + баллы + конфиг) |
| **Migrations** | новые таблицы + колонка `groups.course_id` в `Migration_1_0_0::up()`/`down()` |
| **JS** | admin: конструкторы банков (селекторы заданий/работ/уроков, drag-drop порядок), назначение курса группе; frontend: кокпит, прохождение экзамена (server-sync таймер), сдача работ. Валидация через `common/validators` |
| **Services** регистрация | все новые сервисы/контроллеры — в `Init::getServices()`, реализуют `ServiceInterface` |

---

## 4. Роли и кабинеты

| Роль | Видит в кабинете (`/profile`) | Права |
|---|---|---|
| **FSStudent** | свои группы → программа → открытые уроки (тема, теория, практика/СР/ДЗ), статусы своих работ и оценки, доступные контрольные/экзамены и результаты, записи занятий | доступ гейтится активным `student_records`; своя сдача работ и попытки |
| **FSTeacher** | «Обучение» (курсы / уроки / работы своего предмета) + «Предметы» (задания и статьи-**черновики** + таксономии предмета); конструктор программы группы и расписание (кокпит), проверка работ и попыток, создание контрольных/экзаменов. Создание/удаление предметов — нет (только админ) | `ManageLMSAssignments` (переиспользуем; новые caps не вводим). Задания/уроки/работы/курсы — `fs_lms_content`; статьи — дефолтный `post` (draft-only). См. §0 №17 |
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

### Этап 1 — Банки контента: работы, уроки, курсы + меню «Обучение»
**Цель:** переиспользуемая библиотека контента предмета (`tasks → works → lessons → courses`) с единым меню.
**Состав:** три новых CPT (`{key}_works`, `{key}_lessons`, `{key}_courses`) + метабоксы-конструкторы (работа: тип + селектор заданий; урок: селектор работ; курс: упорядоченный селектор уроков) — всё по ссылке, не копией. `WorkManager`/`LessonManager`/`CourseManager` + `*AuthoringService` + DTO. Меню «Обучение» (top-level + сабменю на банк), все subject-CPT `show_in_menu=false`, мягкий скоуп по предметам препода (`TeacherSubjectsService`); существующие `{key}_tasks`/`{key}_articles` тоже прячутся из top-level и переезжают в это меню.
**Готово, когда:** преподаватель из меню «Обучение» (по умолчанию отфильтрованного под его предмет) создаёт работу из заданий, урок из работ, курс из уроков; всё переиспользуется по ссылке.

### Этап 2 — Программа группы: назначение курса, расписание, доставка, кокпит
**Цель:** назначить группе курс (снапшот уроков), управлять программой/доступом во времени, дать преподавателю фронт-страницу группы.
**Состав:** таблица `fs_lms_group_lessons` (заменяет `groups.schedule` text) + колонка `groups.course_id`. **Назначение курса группе** — `CourseAssignmentService` (bulk-снапшот `course.lesson_ids` в строки программы; далее группа независима от шаблона). Конструктор программы (порядок, дата, `teacher_user_id` занятия, добавить урок из банка, **`extra_work_ids` — доп. работы только для группы**) + `EffectiveWorksResolver` (`lesson.work_ids + extra_work_ids`). Видимость (`hidden`/`open`/`archived`). `GroupLessonRepository`, `ScheduleService`, `LessonVisibilityService`. Фронт-кокпит (`GroupCockpitController`, гейт `groups.teacher_id`). Лог-канал `fs_lms_learning_events` + repo + `LearningEventSubscriber`. Вывод открытых уроков ученику.
**Готово, когда:** преподаватель назначает группе курс и/или собирает программу вручную, задаёт расписание/видимость, усиливает урок для группы доп. работой; ученик видит материалы открытого урока (с учётом дельты); скрытые недоступны; действия пишутся в ленту.

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
- **Секции/темы внутри курса (`sections[]`, аналог Tutor Topic):** группировка уроков в курсе; на Этапе 1 курс — плоский список уроков, секции — позже.
- **Аналитика/отчёты по успеваемости**, экспорт оценок.

> Прежний пункт «переиспользуемый курс-шаблон (`Course`)» **вытащен из «Будущего» в активную модель** (§0 решение 2): курс теперь — CPT-банк `{key}_courses`, назначается группе снапшотом.

---

## 7. Открытые вопросы

1. Банки (`works`/`lessons`/`courses`) per-subject **vs** единые CPT с `subject_key` (рекомендация — per-subject, как tasks/articles).  -> Ответ: per-subject для всех банков
2. Новые capability отдельно (`AuthorLessons`/`GradeWork`/`ManageSchedule`) **vs** переиспользовать `ManageLMSAssignments`.  -> Ответ: переиспользуем базовые для учителя и администратора
3. Миграция текущего текстового `groups.schedule` в `fs_lms_group_lessons` — нужна ли (или начинаем с чистого расписания). -> Ответ: расписание группы = расписание занятий. Нужно будет в будущем привязать публикацию (открытие доступа для учеников) к этому расписанию.
4. Хранение ответов попытки: отдельная таблица `assessment_answers` (рекомендация, удобно для проверки) **vs** JSON в `attempts`.  -> Ответ: отдельная таблица
5. Нужна ли шифровка/анонимизация ответов экзамена (PII-уровень) — по умолчанию нет, ответы не ПДн. -> Ответ: не нужна
6. Вложения работ/записи: WP Media Library **vs** прямые ссылки на S3. -> Ответ: через s3 только видео, все остальные файлы - WP Media Library
7. GradebookEntry — таблица **или** read-model? -> Ответ: read-model (UNION submissions+attempts → `GradebookEntryDTO`), без таблицы. Отдельная `fs_lms_grade_entries` — позже и только под ручные/бонусные оценки без источника.
8. «Журнал группы» — отдельная таблица на каждую группу? -> Ответ: нет (анти-паттерн). **Одна** таблица `fs_lms_learning_events` (повышена из «ленты группы» до канонической ленты событий обучения; `group_id`/`subject_key` nullable). Фид группы = `WHERE group_id=X`; таймлайн ученика и аналитика — запросы по ней. Реюз лог-инфраструктуры (repository + subscriber). Граница «события ≠ баллы» — §0 №15.
9. Страница группы на фронте? -> Ответ: да, кокпит преподавателя (гейт по `groups.teacher_id`); тяжёлый CRUD остаётся в админке.
10. «Взрыв CPT» при десятках предметов — менять модель? -> Ответ: нет, остаёмся per-subject; теперь 5 контент-CPT на предмет (tasks/works/lessons/courses + assessments), но симптомы (меню/права/REST/поиск) снимаются конфигом (`show_in_menu=false` + единое меню «Обучение», общий `capability_type`, `show_in_rest=false`, `exclude_from_search`). Неустранимый остаток — N×5 `register_post_type` на `init`; ничтожен на масштабе центра.
11. Дедлайны ДЗ — где хранить? -> Ответ: источник `group_lessons.homework_due_at` + `allow_late`; снапшот в `submissions.due_at` (для индив. продления); «просрочено» вычисляется.
12. Преподаватель конкретного занятия. -> Ответ: `group_lessons.teacher_user_id` (WP user, **НЕ** person — преподаватели не персоны). `created_by/updated_by_user_id` — только на fact-таблицах; у CPT (`lessons`/`assessments`) — нативные `post_author`/`post_modified`.
13. Работа типизированная (тип на работе) **vs** problem-set + роль на уроке. -> Ответ: типизированная — `work_type` (`practice|independent|homework`) живёт на самой работе; `submissions` ссылается на `work_id`, тип берёт у работы.
14. Урок содержит работы + россыпь задач **vs** только работы. -> Ответ: только работы. Задачи всегда упакованы в работу; в урок попадают через работу.
15. Назначение курса группе — снапшот **vs** ссылка. -> Ответ: снапшот `course.lesson_ids` в `fs_lms_group_lessons` при назначении; группа правит программу независимо, правка курса-шаблона не дёргает живые группы. `groups.course_id` — провенанс / «сбросить к шаблону».
16. Per-group усиление урока — где. -> Ответ: дельта `group_lessons.extra_work_ids` (слой проведения), не правка общего урока. Эффективные работы = `lesson.work_ids + extra_work_ids`. Крупное расхождение — форк урока.
17. Изоляция предметов в меню — жёсткая **vs** мягкая. -> Ответ: мягкая. Меню «Обучение» по умолчанию показывает предметы препода (через `groups.teacher_id` → `subject_key`), но чужое по прямому URL не прячет (без `current_screen`-guard) — цель «облегчить выбор», не «скрыть». Внутри предмета стены тоже нет (#6/п.10).
18. Правка урока, который уже идёт в группе — живой **vs** снапшот. -> Ответ: **copy-on-publish** (§0 №14): содержимое (`work_ids`) живое пока строка `hidden`, замораживается в `group_lessons.work_ids_snapshot` при `open`. Доставленное стабильно (сдачи не сиротеют), правки эталона летят в будущие/неоткрытые. Теория остаётся живой; «подтянуть новую версию в живую группу» — отдельное опц. действие (перезапись снапшота).
19. Единая таблица событий **vs** сбор данных из 5 таблиц. -> Ответ: одна `fs_lms_learning_events` (append-only) — спайн для фида/таймлайна/аудита/engagement-аналитики; пишется через `LogEventDispatcher`. **НЕ источник баллов** (§0 №15): текущие баллы — fact-таблицы (`submissions`/`attempts`) за gradebook read-моделью; security/PII — отдельный `fs_lms_audit_log`. Так «5 таблиц» схлопываются в 1 ленту + 2 факта за read-моделью.
20. Удаление контента с зависимостями (`task→work→lesson→course`). -> Ответ: **физическое удаление запрещено при usage > 0** (§0 №16). Ссылка жива, пока существует пост; статус (`draft`/`publish`/`archived`) только скрывает из селекторов, ссылок не рвёт. Ретайр референсного контента — `archived`; удаление (trash→delete) — только для orphan. Источник usage — `ContentUsageService` (банк-меты + delivery-факты, расширяется по этапам), он же бейдж T1.26. Гейт — `pre_trash_post`/`pre_delete_post` + подмена «Удалить» → «В архив» в нативных списках.
21. Права преподавателя на авторинг: задания vs статьи; где живут. -> Ответ (§0 №17): уроки/работы/курсы/**задания** — `capability_type=fs_lms_content` (+`map_meta_cap`), препод имеет весь набор → создаёт и **публикует**. **Статьи** — дефолтные `post`-права: препод имеет `edit_posts`, но не `publish_posts` → **только черновики** (публикует админ). Раздел «Предметы» открыт преподу (`ManageLMSAssignments`) для контента + таксономий; **создание/удаление предметов — только админ**. «Обучение» = Курсы/Уроки/Работы; Задания/Статьи — в «Предметах» (без дубля). `authorize()` по умолчанию требует `Admin` — поэтому коллбеки данных/таксономий/модалки заданий явно понижены до `ManageLMSAssignments`.
22. Нужны ли «задачи» как отдельная сущность, или достаточно только `{key}_tasks`? -> Ответ (§0 №18–20): да, вводим `fs_lms_problems` (глобальный приватный банк задач). Мотивация: одна задача («Создайте профиль в GitHub») нужна в работах по разным предметам — без копирования вручную; при этом она не должна появляться на фронте (нет публичного предмета «Python»). `{key}_tasks` остаются — это публичные задания с сайта; `fs_lms_problems` — приватные задачи из банка. Работа принимает оба вида через единый `item_ids[]`; тип читается через `get_post_type($id)`. Студент видит один упорядоченный список; рендер варьируется по типу.
