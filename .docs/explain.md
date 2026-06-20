# ⚡ ТЕКУЩИЙ СТАТУС (2026-06-19) — читать первым

> Точка входа для следующего разработчика. Ниже по документу (раздел
> `# Courses (Этапы 1–4): как это устроено`) — подробный разбор **базовой модели**
> Этапов 1–4. Здесь, в шапке, — что реально работает **сейчас**, после перехода на
> **MVP-2** (курс → модули → уроки → **шаги**, как Stepik).
>
> **Источники правды:** модель — `.docs/Courses.md` (секции `★`); задачи — `.docs/Tasks.md`;
> кейсы ручного тестирования — раздел **«8. Кейсы для ручного тестирования»** в конце файла.
>
> **Интеграция с Active Directory** (модуль `Inc\Modules\AdSync`, создание доменных учёток по заявкам):
> концепция и этапы — `.docs/WpToADTasks.md`; REST-контракт + пример клиента на Python — `.docs/FS_LMS_API.md`.

## Что сделано (итог Этапов 1–4 + MVP-2)

Все задачи Этапов **1–4** и переработки **1.5 (MVP-2)** закрыты (`[x]` в `Tasks.md`).
PHPUnit — **410 тест-методов**.

| Блок | Статус | Суть |
|---|---|---|
| Этап 1 — банки контента | ✅ | CPT задание→работа→урок→курс + контрольная; меню «Обучение»; связи по ссылке |
| Этап 2 — программа группы | ✅ | назначение курса (снапшот), расписание, видимость, доступ, кокпит |
| Этап 3 — сдачи + журнал | ✅ | ученик сдаёт → препод проверяет → журнал оценок (read-model) |
| Этап 4 — контрольные | ✅ | серверный таймер, попытки, авто-проверка, cron-страховка |
| MVP-2 — модель шагов | ✅ | урок = `steps[]`; курс = `modules[]`; `StepType`/`StepDTO`/`ModuleDTO` |
| MVP-2 — конструктор курса | ✅ | **SPA** (Stepik) `admin.php?page=fs_lms_course_builder` — канон авторинга |
| T1.5.12 — плеер урока | ✅ | пошаговый фронт-плеер `/group/?gid=X&gl=Y` + прогресс/гейтинг |
| T1.5.11 — клон/форк | ✅ | клон урока/работы/контрольной/курса + форк урока под группу |
| T2.30–34 — календарь занятий | ⚠️ только бэкенд | `meetings[]`, holidays, генерация слотов, pin/reflow, авто-открытие — **сервисы готовы, AJAX/UI ещё нет** |

## Текущая модель (ключевая механика MVP-2)

- **Курс** = пост `{key}_courses` + meta `modules[]` (`{id, title, lesson_ids[]}`). Авторинг —
  только через **конструктор** (`course-builder.js`); нативный редактор курса редиректит в SPA.
- **Урок** = пост `{key}_lessons` + meta `steps[]` — упорядоченный массив `{key, type, payload}`.
  Типы шага: `text` (лекция, TinyMCE), `video`, `material` (файл из Медиатеки **или** ссылка на
  статью), `work` (ссылка/инлайн-создание), `assessment` (ссылка/инлайн-создание), `task`.
- **Обратная совместимость доставки:** `LessonDTO::workIds()` — **производное** от work-шагов,
  поэтому сдачи/прогресс Этапов 2–4 работают без переписывания.
- **Группа ← курс:** назначение делает **снапшот** уроков курса в `fs_lms_group_lessons`
  (см. «Как привязывается группа» ниже). Дальше группа живёт независимо от шаблона.

## Что работает в админке

- **Меню «Обучение»** (`LearningMenuController`): Курсы · Уроки · Работы · Банк задач ·
  Задания · Статьи — нативные таблицы WP с таб-баром предметов.
- **Конструктор курса** (SPA): дерево модули→уроки слева + редактор шагов справа;
  drag-reorder модулей/уроков/шагов; инлайн-создание работ/контрольных; выбор файлов
  (WP Media) и материалов из библиотеки; TinyMCE для лекций; автосохранение.
- **Метабоксы** банков: работа (`WorkMetaBoxController`), контрольная
  (`AssessmentMetaBoxController`) — селекторы-ссылок на задания. Урок собирается
  через step-builder / конструктор.
- Предметы, таксономии, бойлерплейты, группы, заявки, зачисление, PII, e-mail-шаблоны.

## Что в админке ещё дорабатывается

1. **UI календаря занятий (T2.30–34)** — бэкенд (`SessionCalendarService`, `meetings`,
   holidays, `pin`/`reflow`, авто-открытие по `scheduled_at`) готов, но **не подключён**
   к AJAX/экрану. Нужно: редактор `meetings[]` группы, кнопка «сгенерировать слоты»,
   pin/reflow в кокпите.
2. **Чистка старых метабоксов** `WorkTemplate`/`CourseTemplate`/`LessonTemplate` —
   курс/урок переехали в конструктор; часть старых полей может писать «мёртвую» мету.
3. **Полиш конструктора** — мелкие UX-доработки (валидация, пустые состояния списков).

## Что работает на фронте

| Страница | Кто видит | Что внутри |
|---|---|---|
| `/group/` | препод/ученик | список «Мои группы» |
| `/group/?gid=N` | **препод** (`canManage`) | кокпит: программа, ростер, активность, **назначение курса**, очередь проверки, журнал |
| `/group/?gid=N` | **ученик** (`isMemberEver`) | кабинет: его уроки / работы / сдачи |
| `/group/?gid=N&gl=M` | ученик | **пошаговый плеер урока** (шаги + прогресс + гейтинг) |
| одиночная контрольная | ученик | прохождение с серверным таймером и автосохранением |

## Как привязывается группа к курсу

1. Преподаватель открывает кокпит `/group/?gid=N`.
2. В блоке «Назначить курс» выбирает курс + политику (`append` — дописать / `replace` — заменить).
3. Кнопка → AJAX `assign_course` → `ProgramCallbacks::ajaxAssignCourse` →
   `CourseAssignmentService::assign()`.
4. Сервис **копирует** `course->lessonIds()` (разворот `modules[]`) построчно в
   `fs_lms_group_lessons` (`visibility='hidden'`, снапшот пустой), пишет `groups.course_id`,
   событие `CourseAssigned` в ленту.
5. Дальше препод правит программу точечно: добавить/убрать/переставить уроки, дата/препод
   занятия, видимость (первое открытие = «заморозка» состава работ), доп. работы группы.

## Как создаётся курс

1. «Обучение → Курсы» → **«Добавить»** (нативный `post-new.php` курса) → авто-редирект в
   **конструктор** (`CourseBuilderController` ловит `load-post-new.php`).
2. В конструкторе: задать название курса → добавить **модули** → в модуль добавить **уроки**
   (создать новый или взять из банка) → у урока собрать **шаги**.
3. Шаг-лекция — текст (TinyMCE); видео — ссылка; материал — файл/статья; практика/тест —
   ссылка на Work/Assessment из библиотеки **или** инлайн-создание черновика.
4. Всё сохраняется по AJAX (`save_course_structure`, `save_lesson_steps`,
   `create_lesson_in_module`, `create_work_draft`, …). Правка существующего курса —
   тем же конструктором.

## Ключевые узлы MVP-2 (куда смотреть)

| Узел | Роль |
|---|---|
| `inc/Controllers/CourseBuilderController.php` | страница SPA + редиректы нативного редактора курса |
| `inc/Callbacks/Course/CourseBuilderCallbacks.php` | AJAX конструктора (структура, модули, уроки, мета) |
| `inc/Services/Course/CourseBuilderService.php` | сборка дерева курса для SPA |
| `src/js/admin/services/course-builder.js` | сам SPA-конструктор (дерево + редактор шагов) |
| `inc/Enums/StepType.php`, `inc/DTO/Course/{StepDTO,ModuleDTO}.php` | контракт модели шагов/модулей |
| `inc/Controllers/LessonPlayerController.php` + `templates/frontend/lesson-player/player.php` | пошаговый плеер ученика |
| `inc/Services/Course/{LessonProgressService,LessonGateResolver}.php` | прогресс шагов + гейтинг |
| `inc/Services/Course/ContentCloneService.php` + `inc/Callbacks/Course/CloneCallbacks.php` | клон/форк контента |
| `inc/Services/Course/SessionCalendarService.php` | генерация/reflow слотов (бэкенд календаря) |

---

# Courses (Этапы 1–4): как это устроено
Этот документ объясняет, что происходит в модуле «Курсы» (Courses)
плагина FS LMS на этапах 1–4.
---

## 0. Как читать этот документ

В коде используется несколько слоёв. Чтобы не запутаться, держи в голове одну
фразу:

> **Контроллер слушает → Коллбек принимает запрос → Сервис думает → Менеджер/Репозиторий пишет в базу → DTO переносит данные между ними.**

Когда увидишь незнакомый класс, посмотри на его суффикс — он почти всегда говорит
о роли:

| Суффикс / папка | Роль | Можно ли тут бизнес-логику? |
|---|---|---|
| `*Controller` (`inc/Controllers/`) | **Регистрирует хуки WordPress** (`add_action`, `add_filter`). Больше ничего. | ❌ Нет |
| `*Callbacks` (`inc/Callbacks/`) | **Обработчик AJAX**: проверяет права, чистит вход, зовёт сервис, отдаёт JSON. | Чуть-чуть (оркестрация) |
| `*Service` (`inc/Services/`) | **Бизнес-логика**: правила, расчёты, проверки. | ✅ Да, тут её место |
| `*Manager` (`inc/Managers/`) | **Обёртка над WP-API** для постов/мета (`wp_insert_post`, `get_post_meta`). | ❌ Только CRUD |
| `*Repository` (`inc/Repositories/`) | **Доступ к собственным таблицам БД** (`$wpdb`). | ❌ Только CRUD |
| `*DTO` (`inc/DTO/`) | **Контейнер данных** (Data Transfer Object). Просто набор полей. | ❌ Никогда |
| `*Enum` (`inc/Enums/`) | **Типизированные константы** (имена опций, хуков, прав). | ❌ Нет |

Главное правило проекта (из `CLAUDE.md`):

> Контроллеры **не** содержат логику и **не** дёргают WP-API напрямую.
> Никаких прямых `WP_Query`, `get_posts`, `update_option`, `update_post_meta`
> в контроллерах — всё через Менеджеры/Репозитории.

---

## 1. Пять понятий, без которых дальше не понять

### 1.1. CPT — Custom Post Type (пользовательский тип записи)

WordPress хранит «записи» (`wp_posts`). Обычно это «Статьи» и «Страницы». Но
WordPress позволяет завести **свои типы** записей — это и есть CPT. Урок, работа,
курс, контрольная в нашем плагине — это всё **посты особого типа**.

Типы у нас **per-subject** (на каждый предмет свои). Если предмет имеет ключ
`math`, то для него регистрируются типы:

```
math_tasks        — задания
math_articles     — статьи (теория)
math_works        — работы
math_lessons      — уроки
math_courses      — курсы
math_assessments  — контрольные / экзамены
```

Плюс один **глобальный** тип `fs_lms_problems` — банк приватных задач, общий для
всех предметов.

### 1.2. Связь «по ссылке», а не «копией»

Урок не **копирует** в себя работы. Он хранит **список их ID** (номеров постов).
Работа хранит список ID заданий. Курс — список ID уроков. Это как ярлык на файл,
а не его дубликат.

```
Курс  ──хранит── [id урока 5, id урока 8, id урока 12]
Урок 5 ──хранит── [id работы 20, id работы 21]
Работа 20 ──хранит── [id задания 100, id задания 101]
```

Поправил задание №100 — оно поменялось **везде**, где на него ссылаются. Это
называется моделью «банка контента» (Content Bank).

### 1.3. Где хранится «начинка» поста — `fs_lms_meta`

У каждого поста-урока/работы/курса есть заголовок (`post_title`) и иногда текст
(`post_content`). А весь структурированный конфиг (списки ID, тип работы и т.п.)
лежит в **одном** ключе мета-данных:

```php
PostMetaName::Meta->value === 'fs_lms_meta'
```

То есть `get_post_meta($id, 'fs_lms_meta', true)` вернёт **массив** со всей
начинкой. Например, для работы:

```php
[
    'work_type'    => 'homework',
    'item_ids'     => [100, 101, 205],   // задания + задачи
    'instructions' => 'Сделать до пятницы',
]
```

### 1.4. DI-контейнер: классы создаются сами

Ты почти нигде не увидишь `new SomeService(...)`. Вместо этого классы
**объявляют свои зависимости в конструкторе**, а специальный «контейнер»
(`Inc\Core\Container`) сам создаёт и подставляет их. Это называется
**autowiring** (автосвязывание).

```php
class CourseCallbacks extends BaseController {
    public function __construct(
        private readonly CourseAuthoringService $authoringService, // ← контейнер сам создаст и передаст
    ) {
        parent::__construct();
    }
}
```

Чтобы класс «ожил» при старте плагина, его нужно один раз вписать в список
`Init::getServices()` (`inc/Init.php`). Контейнер пройдёт по списку, создаст
каждый объект и вызовет у него метод `register()`.

### 1.5. Как одна строчка превращается в AJAX-хук

Все AJAX-действия описаны в одном enum — `Inc\Enums\AjaxHook`. Одна строка enum
**автоматически** разворачивается в три имени:

```php
case GetCourseLessonCandidates = 'get_course_lesson_candidates';
```

| Метод enum | Что вернёт | Для чего |
|---|---|---|
| `->action()` | `wp_ajax_get_course_lesson_candidates` | имя хука в PHP (`add_action`) |
| `->jsAction()` | `get_course_lesson_candidates` | значение `action` в JS-запросе |
| `->callbackMethod()` | `ajaxGetCourseLessonCandidates` | имя метода в коллбеке |

Базовый класс `AjaxController` берёт пары `[AjaxHook, объект-коллбек]` из метода
`ajaxActions()` дочернего контроллера и сам регистрирует хуки:

```php
// AjaxController::registerAjaxHooks()
add_action( $hook->action(), [ $callback, $hook->callbackMethod() ] );
//          wp_ajax_get_course_lesson_candidates  →  CourseCallbacks::ajaxGetCourseLessonCandidates()
```

Запомни этот механизм — он повторяется на **каждом** этапе.

---

## 2. Большая картина: что такое «Курсы»

До этого модуля плагин умел работать с **людьми**: заявки, зачисление, группы,
персональные данные. Модуль «Курсы» добавляет то, ради чего всё затевалось —
**учебный контент и процесс обучения**.

Идея делится на две половины (это ключевой архитектурный принцип):

```
┌─────────────────────────────┐        ┌──────────────────────────────┐
│  ОПРЕДЕЛЕНИЕ (что учим)      │        │  ПРОВЕДЕНИЕ (как ведём группу)│
│  — переиспользуемые шаблоны  │        │  — конкретная группа во       │
│  — это CPT (посты)           │        │    времени                    │
│                              │        │  — это строки в таблицах БД   │
│  Task → Work → Lesson →      │ ──▶    │  fs_lms_group_lessons         │
│         Course               │ назна- │  fs_lms_submissions           │
│  + Assessment                │ чаем   │  fs_lms_assessment_attempts   │
└─────────────────────────────┘        └──────────────────────────────┘
        Этап 1                              Этапы 2, 3, 4
```

- **Этап 1** строит «библиотеку» — банки переиспользуемого контента и меню для их
  редактирования.
- **Этап 2** «выдаёт» курс конкретной группе: копирует список уроков в таблицу,
  добавляет расписание, видимость, контроль доступа, фронт-страницу преподавателя.
- **Этап 3** даёт ученику **сдавать работы**, а преподавателю — **проверять**;
  появляется журнал оценок.
- **Этап 4** добавляет **контрольные/экзамены** с таймером, авто-проверкой и
  страховочным cron'ом.

---

## 3. Словарь сущностей

| Сущность | Где живёт | Простыми словами |
|---|---|---|
| **Task** (задание) | CPT `{key}_tasks` | Атом: условие/ответ. Публичное, видно на сайте. |
| **Problem** (задача) | CPT `fs_lms_problems` | То же, но приватное и общее для всех предметов. |
| **Work** (работа) | CPT `{key}_works` | Набор заданий + тип (практика/СР/ДЗ). |
| **Lesson** (урок) | CPT `{key}_lessons` | Тема + теория + список работ. |
| **Course** (курс) | CPT `{key}_courses` | Упорядоченный список уроков. Шаблон. |
| **Assessment** (контрольная) | CPT `{key}_assessments` | Список заданий + настройки (лимит времени, попытки). |
| **Group** (группа) | таблица `fs_lms_groups` | Когорта учеников у преподавателя по предмету. |
| **GroupLesson** | таблица `fs_lms_group_lessons` | Урок **в программе конкретной группы** (снапшот). |
| **Submission** (сдача) | таблица `fs_lms_submissions` | Факт сдачи работы учеником. |
| **Attempt** (попытка) | таблица `fs_lms_assessment_attempts` | Попытка прохождения контрольной. |
| **LearningEvent** | таблица `fs_lms_learning_events` | Запись в ленте активности («что произошло»). |

Цепочка ссылок (главное, что нужно держать в голове):

```
Task/Problem  ◀── item_ids ──  Work  ◀── work_ids ──  Lesson  ◀── lesson_ids ──  Course
                                                          ▲
                                            theory_article_id │ (опц. ссылка на статью)
                                                          Article
```

---

# ЭТАП 1 — Банки контента + меню «Обучение»

**Цель этапа:** дать преподавателю возможность из админки создавать работы из
заданий, уроки из работ, курсы из уроков — и всё это переиспользовать по ссылке.

## 1.1. Как регистрируются типы-страницы (CPT)

Все шесть CPT каждого предмета регистрирует **один** класс — `SubjectController`.

```
SubjectController::register()
  └─ registerCptsAndTaxonomies()
       ├─ SubjectRepository::readAll()              // прочитать все предметы из wp_options
       └─ для каждого предмета: registerForSubject($subject)
              └─ для works/lessons/courses/assessments/tasks/articles:
                   SubjectCPTRegistrar::addStandardType( $cpt_slug, $label, $labels, $options )
       └─ SubjectCPTRegistrar::register()           // один раз: на хук 'init'
              └─ CPTManager::register([...])  ──▶  register_post_type($slug, $args)  (WP API)
```

Аргументы CPT собираются в `SubjectController::getDefaultCptArgs($type, $subject)`.
Для всех «банков» используется общий блок настроек `$bank_options`:

```php
'show_in_menu'        => false,            // ← НЕ показывать как отдельный пункт меню
'show_in_rest'        => false,
'exclude_from_search' => true,
'capability_type'     => 'fs_lms_content', // ← права не «как у постов сайта», а свои
'map_meta_cap'        => true,
'has_archive'         => false,
```

**Почему `show_in_menu => false`?** Чтобы в админ-меню не было шести пунктов на
каждый предмет (при 10 предметах это 60 пунктов — «взрыв меню»). Вместо этого
есть **одно** меню «Обучение» (см. 1.7), которое ведёт на нативные экраны этих
CPT.

`PostTypeResolver` (`inc/Services/PostTypeResolver.php`) — маленький помощник,
который строит и разбирает имена CPT, чтобы нигде не было «склейки строк руками»:

```php
PostTypeResolver::works('math')                    // → 'math_works'
PostTypeResolver::courses('math')                  // → 'math_courses'
PostTypeResolver::isCoursePostType('math_courses') // → true
PostTypeResolver::subjectFromCoursePostType('math_courses') // → 'math'
```

## 1.2. Менеджеры: чтение и запись постов

`WorkManager`, `LessonManager`, `CourseManager` — почти близнецы. Каждый зависит
**только** от `PostManager` (универсальной обёртки над WP-функциями постов).

Разберём на `CourseManager` (`inc/Managers/CourseManager.php`):

| Метод | Что принимает | Что делает |
|---|---|---|
| `create(string $subjectKey, CourseDTO $dto): int` | ключ предмета + DTO | `wp_insert_post` (тип `{key}_courses`, статус `draft`), затем пишет мету. Возвращает ID. |
| `update(int $id, CourseDTO $dto): bool` | ID + DTO | Обновляет заголовок/контент + мету. |
| `get(int $id): ?CourseDTO` | ID | Читает пост + мету → собирает `CourseDTO`. |
| `getBankBySubject(string $key, array $args): CourseDTO[]` | предмет + фильтры | Возвращает все курсы предмета (для списков/селекторов). |
| `delete(int $id): bool` | ID | Жёсткое удаление поста. |

Приватный `saveMeta()` пишет начинку в `fs_lms_meta`:

```php
// CourseManager  → ['lesson_ids' => $dto->lessonIds]
// LessonManager  → ['theory_article_id' => ..., 'work_ids' => ...]
// WorkManager    → ['work_type' => ..., 'item_ids' => ..., 'instructions' => ...]
```

`PostManager` (общий для всех) скрывает WP-функции. Самый важный его метод —
`search()`: это «движок чтения» для всех селекторов.

```php
PostManager::search(string $post_type, array $opts = []): WP_Post[]
// opts: status (по умолч. ['publish','draft']), author, search (→ s),
//       tax_query, limit, orderby, order  →  обёртка над get_posts()
```

## 1.3. DTO: что переносится между слоями

DTO — это «коробка с данными», `readonly` (после создания не меняется). У каждого
есть фабрики `fromPost()` (собрать из WP-поста), `fromArray()` и `toArray()`.

```php
// WorkDTO
int    $id;
string $subjectKey;
string $title;        // ← post_title
WorkType $workType;   // enum: Practice|Independent|Homework
int[]  $itemIds;      // ссылки на задания/задачи
string $instructions;
int    $authorId;
string $status;

// LessonDTO:  topic, theoryHtml, int $theoryArticleId (0 = теория прямо в уроке), int[] workIds
// CourseDTO:  title, descriptionHtml, int[] lessonIds
```

`WorkType` (`inc/Enums/WorkType.php`) — backed-enum со значениями
`practice|independent|homework`, у него есть `label()` (русская подпись) и
`options()` (для выпадающего списка).

## 1.4. Метабоксы: конструктор работы/урока/курса

Когда преподаватель открывает экран редактирования (например) курса, под
редактором появляется **метабокс** — блок с полями. За него отвечает
`CourseMetaBoxController` (`inc/Controllers/CourseMetaBoxController.php`):

```
register()
  ├─ add_action('add_meta_boxes', handleAddMetaBoxes)
  │     └─ для всех {key}_courses:  MetaBoxRegistrar::add('fs_lms_course_metabox',
  │                                   'Программа курса', renderMetaboxContent, $course_post_types)
  └─ add_action('save_post', handleCourseSave)

renderMetaboxContent($post)
  ├─ wp_nonce_field(Nonce::SaveMeta, 'fs_lms_meta_nonce')   // защитный токен
  └─ CourseTemplate::render($post)                          // нарисовать поля

handleCourseSave($post_id)
  ├─ пропустить автосохранение
  ├─ проверить, что это {key}_courses (PostTypeResolver::isCoursePostType)
  ├─ authorizePostSave(Nonce::SaveMeta, $post_id)           // проверить nonce + права
  └─ MetaBoxManager::saveFields($post_id, 'fs_lms_meta',
                                $_POST['fs_lms_meta'], $template->get_fields())
```

**Что такое Template и Field?**

- **Template** (`CourseTemplate`, `LessonTemplate`, `WorkTemplate`) — описывает
  **набор полей** метабокса. Метод `get_fields()` возвращает карту
  `['field_id' => ['label' => ..., 'object' => <поле>]]`.

  ```
  WorkTemplate   → work_type (WorkTypeField) + instructions (TextareaField) + item_ids (TaskRefField)
  LessonTemplate → theory_article_id (ArticleRefField) + work_ids (WorkRefField)
  CourseTemplate → lesson_ids (LessonRefField)
  ```

- **Field** — отдельное поле (умеет `render()` нарисовать себя и `sanitize()`
  очистить ввод). Самое интересное поле — **`RefSelectField`** (абстрактное):
  это и есть **селектор ссылок** (поиск + «чипсы»-теги выбранного + drag-drop
  порядок). От него наследуются:

  ```
  TaskRefField   (в работе)  — выбирает задания/задачи
  WorkRefField   (в уроке)   — выбирает работы
  LessonRefField (в курсе)   — выбирает уроки
  ```

  Каждый выбранный элемент рендерится как «чип» со скрытым `<input>`:
  `name="fs_lms_meta[lesson_ids][]" value="<id>"`. Когда форма сохраняется, эти
  скрытые поля и есть список ID.

Сохранение идёт через `MetaBoxManager::saveFields()`: он проходит по полям
шаблона, у каждого зовёт `sanitize()` и собирает итоговый массив в `fs_lms_meta`.

## 1.5. Сервисы авторинга: откуда селектор берёт кандидатов

Когда в селекторе уроков курса ты начинаешь печатать — нужно показать **список
доступных уроков**. Эту выборку делают `*AuthoringService`:

| Сервис | Главный метод | Что возвращает в JS |
|---|---|---|
| `WorkAuthoringService` | `getItemCandidates($key, $collection, $scope, $search)` | задания + задачи: `[{id, title, author, type}]` |
| `LessonAuthoringService` | `getWorkCandidates($key, $workType, $scope, $search)` | работы: `[{id, title, work_type, author}]` |
| `CourseAuthoringService` | `getLessonCandidates($key, $scope, $search)` | уроки: `[{id, title, author}]` |

`scope` бывает `mine` (только мои — фильтр по `author = current_user_id`) или
`subject` (все по предмету). Все методы внутри зовут `PostManager::search()`.

Ещё два важных сервиса этапа 1:

- **`TeacherSubjectsService`** — определяет, какие предметы показывать
  преподавателю. `subjectsForUser($userId)`: админ видит все; преподаватель — те
  предметы, по которым у него есть группы (мягкий скоуп — чужое не прячет
  жёстко). Используется в меню «Обучение».

- **`ContentUsageService`** — отвечает на вопрос «где используется этот контент?»
  (счётчик «используется в N»). `usageList('work', $id)` найдёт все уроки, в
  `work_ids` которых есть этот ID. Нужен, чтобы случайно не удалить контент, на
  который ссылаются.

- **`ContentLifecycleService`** — «архивирование». `archive($id)` ставит посту
  статус `fs_archived`: он пропадает из селекторов (новые ссылки на него не
  создашь), но **старые ссылки продолжают работать** — пост-то существует.

## 1.6. Полная цепочка одного AJAX-запроса (селектор)

Соберём всё вместе. Преподаватель печатает в селекторе заданий внутри **работы**:

```
1. БРАУЗЕР  src/js/admin/services/ref-selector.js
   RefSelector видит data-ref-type="item" → по карте REF_MAP берёт
   action='get_work_item_candidates', nonceKey='authorWork'
   $.post(ajaxurl, { action, security: nonces.authorWork,
                     subject_key, scope:'mine', search, collection })
        │
        ▼
2. WordPress поднимает хук  wp_ajax_get_work_item_candidates
        │
        ▼
3. WorkController::ajaxActions()  ←── здесь зарегистрирована пара
   [AjaxHook::GetWorkItemCandidates, $this->callbacks]
        │  (AjaxController сам сделал add_action на ajaxGetWorkItemCandidates)
        ▼
4. WorkCallbacks::ajaxGetWorkItemCandidates()
   ├─ $this->authorize( Nonce::AuthorWork, Capability::ManageLMSAssignments )  // nonce + права
   ├─ requireKey / sanitizeKey / sanitizeText  входных параметров
   └─ WorkAuthoringService::getItemCandidates($subject_key, $collection, $scope, $search)
        │
        ▼
5. WorkAuthoringService  →  PostManager::search('math_tasks' + 'fs_lms_problems', ...)
        │
        ▼
6. Назад: $this->success($candidates)  →  wp_send_json_success(...)
        │
        ▼
7. БРАУЗЕР  RefSelector рисует выпадающий список; клик по пункту → добавляет «чип»
   со скрытым input fs_lms_meta[item_ids][]
```

Эта схема (**JS → wp_ajax_* → Controller → Callback → Service → Manager → JSON**)
— скелет всех взаимодействий во всех четырёх этапах. Дальше будем менять только
имена классов.

## 1.7. Меню «Обучение»: какие страницы регистрируются

`LearningMenuController` (`inc/Controllers/LearningMenuController.php`) регистрирует
**одно** верхнеуровневое меню и сабменю:

```
Обучение  (slug fs_lms_learning, иконка «выпускник», позиция 4)
  ├─ Курсы           → renderCourses()
  ├─ Уроки           → renderLessons()
  ├─ Работы          → renderWorks()
  ├─ Банк задач      → renderProblems()
  ├─ Задания предмета→ renderTasks()
  └─ Статьи предмета → renderArticles()
```

Все пункты защищены правом `Capability::ManageLMSAssignments`. Регистрация идёт
через `MenuRegistrar` (накопитель страниц с fluent-интерфейсом) →
`MenuManager` → `add_menu_page()` / `add_submenu_page()`.

Каждая страница `renderBank($type, $slug)` делает простую вещь:

```
1. TeacherSubjectsService::subjectsForUser()  → список предметов (вкладки)
2. рисует вкладки-предметы (?fs_subject=math)
3. по активной вкладке резолвит CPT (PostTypeResolver::courses('math') = 'math_courses')
4. рисует кнопки «Открыть список» (edit.php?post_type=math_courses)
                и «Добавить»      (post-new.php?post_type=math_courses)
```

То есть меню «Обучение» — это **навигатор**, который ведёт на **нативные
экраны WordPress** скрытых CPT. Сам контент редактируется на стандартных
экранах WP (с нашими метабоксами из 1.4).

---

# ЭТАП 2 — Программа группы (назначение курса, расписание, доступ, кокпит)

**Цель этапа:** взять курс-шаблон и «выдать» его конкретной группе. С этого
момента у группы появляется собственная **программа** во времени, не зависящая от
шаблона.

## 2.1. Главная идея: снапшот вместо ссылки

Курс — это шаблон. Когда мы назначаем курс группе, мы **копируем список его уроков
в таблицу** `fs_lms_group_lessons` (по одной строке на урок). Это называется
**снапшот**.

Почему копия, а не ссылка? Потому что у группы своя жизнь: преподаватель меняет
порядок, даты, добавляет работы только этой группе. Если бы это была ссылка,
правка курса-шаблона ломала бы уже идущие группы. Снапшот **развязывает**
проведение от шаблона.

```
Course (шаблон)            CourseAssignmentService::assign()         fs_lms_group_lessons
lesson_ids = [5, 8, 12]   ──────────────────────────────────▶   (строки для группы 3)
                            копируем каждый урок в строку         ┌──────────────────────┐
                                                                  │ group_id=3 lesson=5  │
                                                                  │ group_id=3 lesson=8  │
                                                                  │ group_id=3 lesson=12 │
                                                                  └──────────────────────┘
```

## 2.2. Новые таблицы

Создаются в `inc/Migrations/Migration_1_0_0.php` (не отдельным файлом — так
заведено в проекте).

**`fs_lms_group_lessons`** — одна строка = один урок в программе группы:

| Колонка | Смысл |
|---|---|
| `id` | ID строки (это и есть «group_lesson_id», на него все ссылаются) |
| `group_id` | какая группа |
| `lesson_id` | какой урок-шаблон скопирован |
| `position` | порядок в программе |
| `work_ids_snapshot` | **JSON-заморозка** списка работ. `NULL` = урок ещё не открывали |
| `extra_work_ids` | доп. работы **только для этой группы** (усиление) |
| `scheduled_at` | дата/время занятия |
| `teacher_user_id` | кто ведёт это занятие (может отличаться от препода группы) |
| `visibility` | `hidden` / `open` / `archived` |
| `opened_at` | когда открыли доступ |
| `homework_due_at` | дедлайн ДЗ |
| `allow_late` | принимать ли после дедлайна |

Плюс в таблицу `fs_lms_groups` добавляется колонка `course_id` — «какой курс
назначен» (чтобы можно было «сбросить к шаблону»).

**`fs_lms_learning_events`** — лента событий (см. 2.7).

## 2.3. Репозиторий программы

`GroupLessonRepository` (`inc/Repositories/WPDBRepositories/GroupLessonRepository.php`)
— доступ к таблице `fs_lms_group_lessons` через `$wpdb`. Ключевые методы:

| Метод | Назначение |
|---|---|
| `add(GroupLessonInputDTO $dto): int` | вставить строку, вернуть ID |
| `listByGroup(int $groupId): GroupLessonDTO[]` | все строки группы по порядку |
| `listOpenByGroup(int $groupId): GroupLessonDTO[]` | только видимые (`open`/`archived`) — для ученика |
| `find(int $id): ?GroupLessonDTO` | одна строка |
| `nextPosition(int $groupId): int` | следующий порядковый номер |
| `reorder(int $groupId, array $orderedIds)` | переставить порядок |
| `updateSchedule(int $id, ?$scheduledAt, ?$teacherUserId)` | дата + препод занятия |
| `setVisibility(int $id, string $vis, ?$openedAt)` | сменить видимость |
| `setWorkIdsSnapshot(int $id, array $ids)` | заморозить список работ |
| `setExtraWorkIds(int $id, array $ids)` | доп. работы группы |
| `remove(int $id)` / `deleteAllByGroup(int $groupId)` | удаление |

**Запомни предикат `GroupLessonDTO::isPublished()`** — он возвращает
`work_ids_snapshot !== null`. Это **единый признак** «урок уже открывали». Его
используют и видимость, и расчёт работ, и доступ.

## 2.4. Назначение курса группе

`CourseAssignmentService::assign()` (`inc/Services/Course/CourseAssignmentService.php`):

```
assign(int $groupId, int $courseId, int $actorUserId, AssignmentPolicy $policy = Append): int
  1. group  = GroupsRepository::findById($groupId)        // проверить, что есть
     course = CourseManager::get($courseId)
  2. ПРОВЕРКА: course->subjectKey === group->subject_key  // нельзя курс чужого предмета
  3. если policy === Replace → GroupLessonRepository::deleteAllByGroup($groupId)
  4. position = GroupLessonRepository::nextPosition($groupId)
     для каждого lessonId из course->lessonIds:
        GroupLessonRepository::add(new GroupLessonInputDTO(groupId, lessonId, position++))
        // новые строки по умолчанию: visibility='hidden', snapshot=null (ещё не открыты)
  5. GroupsRepository::update($groupId, ['course_id' => $courseId])
  6. dispatcher->dispatch(LogEvent::CourseAssigned, new LearningEvent(...))  // в ленту
  возвращает: сколько строк добавлено
```

Откуда берётся `course->lessonIds`? `CourseManager::get()` читает мету
`fs_lms_meta` поста-курса и собирает `CourseDTO` с полем `lessonIds`.

## 2.5. Расписание

`ScheduleService` (`inc/Services/Course/ScheduleService.php`) — точечные правки
программы:

| Метод | Что делает | Событие в ленту |
|---|---|---|
| `addLesson($groupId, $lessonId, $actor)` | добавить один урок из банка | `LessonAddedToProgram` |
| `removeLesson($groupLessonId, $actor)` | убрать строку | `LessonRemovedFromProgram` |
| `reorder($groupId, $orderedIds, $actor)` | переставить порядок | `ScheduleChanged` |
| `schedule($groupLessonId, $date, $teacher, $actor)` | дата + препод занятия | `ScheduleChanged` |
| `getProgram($groupId): array` | прочитать программу `[{row, topic}]` | — |

## 2.6. Видимость и «заморозка при публикации» (copy-on-publish)

`LessonVisibilityService::setVisibility()` (`inc/Services/Course/LessonVisibilityService.php`)
— здесь живёт тонкий, но важный момент:

```
setVisibility($groupLessonId, $visibility, $actor):
  row = GroupLessonRepository::find(...)
  если $visibility === 'open'  И  НЕ row->isPublished():    // открываем ВПЕРВЫЕ
       lesson = LessonManager::get(row->lessonId)
       GroupLessonRepository::setWorkIdsSnapshot(id, lesson->workIds)  // ← ЗАМОРАЗКА
       openedAt = clock->now()
  GroupLessonRepository::setVisibility(id, $visibility, openedAt)
  событие: LessonPublished (open) либо LessonHidden
```

**Зачем замораживать?** Пока урок `hidden`, его список работ читается «живым» из
урока-шаблона (правки эталона долетают — это фаза подготовки). Но как только урок
**открыли** ученикам — состав работ фиксируется в `work_ids_snapshot`. Дальше
правки шаблона уже не меняют то, что выдали ученикам (иначе сдачи «осиротели бы»).

Отдельный метод `refreshFromLesson()` — осознанно «подтянуть новую версию» в живую
группу (перезаписать снапшот).

## 2.7. EffectiveWorksResolver — «какие работы реально в этом уроке у этой группы»

`EffectiveWorksResolver::resolve(GroupLessonDTO $row): WorkDTO[]`
(`inc/Services/Course/EffectiveWorksResolver.php`). Правило в одну формулу:

```
база  = row->isPublished() ? row->workIdsSnapshot          // открыт → замороженный список
                           : LessonManager::get(row->lessonId)->workIds   // не открыт → живой
итог  = array_unique( база + row->extraWorkIds )           // + усиление группы, без дублей
        → каждый ID превращаем в WorkDTO через WorkManager::get()
```

То есть: **(заморозка или живой список) + доп. работы группы**. Этот резолвер
дальше используют и кокпит, и сдача работ (этап 3).

## 2.8. Доступ: два разных «охранника»

Очень важно не путать два класса:

**`GroupAccessGuard`** (`inc/Services/Course/GroupAccessGuard.php`) — это охрана
**управления** (преподаватель/админ):

```php
canManage(int $groupId, int $userId): bool   // true, если Admin ИЛИ group->teacher_id === userId
isMemberEver(int $groupId, int $personId): bool  // был ли человек когда-либо в группе
isParentOf(int $groupId, int $parentPersonId): bool
```

**`LessonAccessPolicy`** (`inc/Services/Course/LessonAccessPolicy.php`) — это
охрана **ученика** к конкретному уроку. Возвращает уровень `AccessLevel`:
`None` / `Read` / `ReadSubmit`. Решение принимается **по членству в группе**
(`student_record`), а **не по роли**:

```
resolve(StudentRecordDTO $record, GroupLessonDTO $lesson): AccessLevel
  1. урок hidden → None
  2. запись active:
        урок открыт на/после даты зачисления → ReadSubmit (можно сдавать)
        иначе                                → Read (бэк-каталог: видит, но не сдаёт)
  3. запись терминальная (отчислен):
        политика block  → None
        политика retain → Read, если урок открыли до отчисления; иначе None
```

Удобные обёртки: `canRead()`, `canSubmit()`, `visibleLessonsForStudent()`.

## 2.9. Лента событий (Learning Events)

Каждое значимое действие пишется в **append-only** ленту (только добавление,
правок нет). Это **не** источник баллов — просто хроника «что произошло».

```
Сервис (CourseAssignmentService / ScheduleService / LessonVisibilityService)
   └─ dispatcher->dispatch( LogEvent::X, new LearningEvent(...) )   // после записи в БД
        │
        ▼
LearningEventSubscriber::handle(LearningEvent $e)     // подписчик слушает событие
        └─ LearningEventWriter::record($e)
              ├─ UserManager  → определить роль актора
              └─ LearningEventRepository::create(LearningEventInputDTO)
                    └─ INSERT в fs_lms_learning_events
```

`LearningEventRepository::update()` намеренно **бросает исключение** — ленту
нельзя редактировать. Читается она методами `listByGroup()`, `countByGroup()`
и т.п. (например, для блока «Активность» в кокпите).

## 2.10. Кокпит: фронт-страница `/group/`

Это **первая фронтовая страница** модуля. Регистрирует её
`GroupCockpitController` (`inc/Controllers/GroupCockpitController.php`) через фильтр
`template_include` (подменяет шаблон темы):

```
register(): add_filter('template_include', loadTemplate)

loadTemplate($template):
  - не страница 'group' (PageRoutes::GroupCockpit)      → вернуть тему как есть
  - не залогинен                                        → редирект на логин
  - ?gid=0 (нет группы)                                 → renderGroupList()  «Мои группы»
  - GroupAccessGuard::canManage(gid, user) == true      → renderCockpit()         (ПРЕПОДАВАТЕЛЬ)
  - person есть И GroupAccessGuard::isMemberEver(...)    → renderStudentCockpit()  (УЧЕНИК)
  - иначе                                               → редирект на главную
```

Все рендеры оборачивают вывод в `ThemeCompatService::header()` / `footer()`
(нельзя `get_header()` напрямую — блочные темы его не имеют).

`renderCockpit()` (для преподавателя) собирает данные и подключает
`templates/frontend/group-cockpit/cockpit.php`:

```
group     = GroupsRepository::find(gid)
program   = ScheduleService::getProgram(gid)                 // уроки + темы
roster    = StudentRecordRepository::findActiveByGroupId(gid)// ученики
events    = LearningEventRepository::listByGroup(gid, 1, 20) // лента
```

В `cockpit.php` четыре блока: **Программа** (уроки + бейдж видимости + кнопки),
**Ученики**, **Активность** (лента), а также «посадочные места»
`#fs-grading-queue` и `#fs-gradebook-container` — их наполнят этапы 3/4.

## 2.11. Цепочки AJAX этапа 2

Все действия программы регистрирует `ScheduleController`, обрабатывает
`ProgramCallbacks` (`inc/Callbacks/Course/ProgramCallbacks.php`). Каждый коллбек
делает `authorize(Nonce::X, Capability::ManageLMSAssignments)` и дополнительно
проверяет `GroupAccessGuard::canManage()`.

| Действие в браузере | AjaxHook (JS action) | Nonce | Метод коллбека | → Сервис |
|---|---|---|---|---|
| Назначить курс | `assign_course` | `AssignCourse` | `ajaxAssignCourse` | `CourseAssignmentService::assign` |
| Добавить урок | `add_lesson_to_program` | `SaveSchedule` | `ajaxAddLessonToProgram` | `ScheduleService::addLesson` |
| Убрать урок | `remove_lesson_from_program` | `SaveSchedule` | `ajaxRemoveLessonFromProgram` | `ScheduleService::removeLesson` |
| Переставить | `reorder_program` | `SaveSchedule` | `ajaxReorderProgram` | `ScheduleService::reorder` |
| Дата/препод | `save_lesson_schedule` | `SaveSchedule` | `ajaxSaveLessonSchedule` | `ScheduleService::schedule` |
| Доп. работы | `set_lesson_extra_works` | `SaveSchedule` | `ajaxSetLessonExtraWorks` | `EffectiveWorksResolver::setExtraWorks` |
| Видимость | `set_lesson_visibility` | `SetLessonVisibility` | `ajaxSetLessonVisibility` | `LessonVisibilityService::setVisibility` |
| Лента (ещё) | `get_group_activity` | `SaveSchedule` | `ajaxGetGroupActivity` | `LearningEventRepository::listByGroup` |

JS-сторона — `src/js/frontend/services/group-cockpit.js` (читает
`window.fs_lms_cockpit_vars`): клик по кнопке видимости циклит
`hidden→open→archived→hidden`, кнопка «Загрузить ещё» подгружает ленту.

---

# ЭТАП 3 — Сдача работ и журнал оценок

**Цель этапа:** ученик сдаёт работу (текст + файл), преподаватель проверяет
(балл/статус/комментарий), всё стекается в **журнал оценок** (gradebook).

## 3.1. Таблица `fs_lms_submissions`

Одна строка = одна сдача работы учеником:

| Колонка | Смысл |
|---|---|
| `student_person_id` | кто сдал (по `persons.id`, **не** по WP-user) |
| `group_lesson_id` | в каком уроке программы |
| `work_id` | какую работу сдают |
| `work_type` | **снапшот** типа работы на момент сдачи |
| `answer_text` | текст ответа |
| `attachment_id` | ID файла в WP Media Library |
| `due_at` | **снапшот** дедлайна (из `group_lessons.homework_due_at`) |
| `status` | `assigned` / `submitted` / `graded` / `returned` |
| `score`, `max_score` | баллы |
| `feedback` | комментарий проверяющего |
| `graded_by_user_id`, `submitted_at`, `graded_at` | кто/когда |

`is_late` (просрочено) **не хранится** — вычисляется в `SubmissionDTO::isLate()`
как `submitted_at > due_at`.

`SubmissionStatus` (enum) умеет `label()` (рус. подпись) и `isTerminal()` (true
только для `Graded`).

## 3.2. SubmissionService — цикл сдачи

`SubmissionService` (`inc/Services/Course/SubmissionService.php`) — сердце этапа.
Зависимости: `SubmissionRepository`, `GroupLessonRepository`,
`EffectiveWorksResolver`, `WorkManager`, `MediaManager`, `LessonAccessPolicy`,
`LogEventDispatcher`, `ClockInterface`.

```
submit($studentPersonId, $groupLessonId, $workId, $taskId, $answerText, $fileKey): int
  1. ДОСТУП:  LessonAccessPolicy::canSubmit(...)  // должно быть ReadSubmit (см. 2.8)
  2. row = GroupLessonRepository::find(groupLessonId)
     эффективные работы = EffectiveWorksResolver::resolve(row)
     проверить, что $workId реально в этом наборе
  3. work = WorkManager::get($workId)
  4. ДЕДЛАЙН: если !allowLate И now > dueAt → бросить ошибку (поздно)
  5. ФАЙЛ: если есть → MediaManager::uploadFromRequest($fileKey) → attachmentId
  6. UPSERT: SubmissionRepository::findForWork(...) есть?
        да  → update (новый ответ, status='submitted', submitted_at=now)
        нет → create(SubmissionInputDTO) со СНАПШОТАМИ work_type и due_at
  7. событие LogEvent::SubmissionMade в ленту
  возвращает: ID сдачи
```

Проверка/возврат (для преподавателя):

```
grade($submissionId, GradeDTO $grade, $teacherUserId):
   SubmissionRepository::update(... status='graded', score, max_score, feedback, graded_at=now)
   событие SubmissionGraded

returnForRework($submissionId, $feedback, $teacherUserId):
   update(... status='returned', feedback)   // вернуть на доработку
   событие SubmissionReturned
```

`MediaManager` (`inc/Managers/MediaManager.php`) — загрузка файла:
`uploadFromRequest($fileKey)` проверяет MIME (jpeg/png/gif/pdf/doc/docx/txt) и
размер (до 10 МБ), затем зовёт `media_handle_upload()` и возвращает `attachment_id`.

## 3.3. Журнал оценок — это «read-model», а не таблица

Очень важная идея: **журнал не хранится отдельной таблицей**. Он **собирается на
лету** из источников. Так нет «двойной записи» и рассинхрона.

Механизм построен на интерфейсе:

```php
interface GradeSourceInterface {
    entriesForGroup(int $groupId): GradebookEntryDTO[];
    entriesForStudent(int $studentPersonId): GradebookEntryDTO[];
}
```

```
GradebookService (зависит от GradeSourceRegistry)
   forGroup($groupId):
      foreach (GradeSourceRegistry::all() as $source)
          собрать $source->entriesForGroup($groupId)
      вернуть всё вместе

GradeSourceRegistry (композиционный корень — здесь перечислены все источники):
   [ SubmissionGradeSource,        // сдачи работ (этап 3)
     AssessmentGradeSource ]       // попытки контрольных (этап 4)
```

`SubmissionGradeSource` берёт сданные-проверенные работы и превращает каждую в
`GradebookEntryDTO` (студент, группа, `sourceType='submission'`, тема урока, балл,
дата). 

**Почему это красиво:** на этапе 4 добавили новый источник
`AssessmentGradeSource` просто в список реестра — и журнал автоматически стал
показывать ещё и оценки за контрольные. `GradebookService` при этом **не
менялся** (это принцип Open/Closed — открыт для расширения, закрыт для изменения).

## 3.4. Цепочки AJAX этапа 3

Регистрирует `SubmissionController`. Обработчики разнесены по двум коллбекам:
ученик — `SubmissionCallbacks`, преподаватель — `GradingCallbacks`.

**Ученик сдаёт работу:**

```
submission.js (FormData с файлом!) ──action=submit_work, security=nonces.submitWork──▶
  wp_ajax_submit_work
   └─ SubmissionController → SubmissionCallbacks::ajaxSubmitWork()
        ├─ Nonce::SubmitWork->verify()        // только nonce (без capability — доступ проверит сервис)
        ├─ person = PersonRepository::findByWpUserId(current_user_id)
        ├─ fileKey = 'submission_file' (если есть)
        └─ SubmissionService::submit(...)
              └─ SubmissionRepository::create()/update() + MediaManager::uploadFromRequest()
```

**Преподаватель оценивает:**

```
submission.js ──action=save_grade, security=nonces.gradeWork──▶
  wp_ajax_save_grade
   └─ SubmissionController → GradingCallbacks::ajaxSaveGrade()
        ├─ $this->authorize( Nonce::GradeWork, Capability::ManageLMSAssignments )
        ├─ доп. проверка: GroupAccessGuard::canManage(group, user)   // именно его группа
        └─ SubmissionService::grade($id, new GradeDTO(score, maxScore, feedback), userId)
```

> ⚠️ Тонкость: nonce для всех учительских действий — **`GradeWork`** (а не
> «SaveGrade»). Один nonce покрывает оценку, возврат, очередь и журнал.

Остальные хуки: `return_submission` (вернуть на доработку),
`get_group_submissions` (очередь проверки), `get_my_submissions` (мои сдачи),
`get_gradebook` (журнал).

## 3.5. Кабинеты: ученик vs преподаватель

Помнишь, в этапе 2 `GroupCockpitController::loadTemplate()` ветвился? Вот разница:

- **Преподаватель** → `cockpit.php`: программа + ростер + лента + **живые
  AJAX-блоки** «Проверка работ» (очередь) и «Журнал оценок» по всей группе.
- **Ученик** → `student-cockpit.php`: только **его** уроки (видимые!), работы и
  его сдачи. По каждой работе шаблон смотрит статус:
  - `graded` → результат только для чтения (балл + комментарий);
  - `returned` → «вернули на доработку» + снова форма сдачи;
  - `submitted` → «сдано, ждёт проверки»;
  - нет сдачи → форма (`partials/submission-form.php`).

JS (`submission.js`) отправляет форму через **`FormData`** (потому что файл),
у преподавателя — отдельные функции загрузки очереди/журнала/оценки.

---

# ЭТАП 4 — Контрольные и экзамены (assessment-движок)

**Цель этапа:** контрольная с **таймером**, фиксацией ответов, авто-проверкой
простых заданий и ручной — сложных. Результат попадает в тот же журнал.

## 4.1. Сущность Assessment

Контрольная — это CPT `{key}_assessments` (регистрируется тем же
`SubjectController`, что и остальные банки). Её настройки задаются в метабоксе
`AssessmentMetaBoxController` через `AssessmentTemplate`:

```
task_ids            — список заданий (AssessmentTaskRefField — тот же селектор-ссылок)
time_limit_minutes  — лимит времени (0 = без лимита)
max_attempts        — сколько попыток (0 = без ограничений)
pass_score          — проходной балл
scoring_policy      — highest / last / first   (пока хранится, но не используется)
shuffle             — перемешивать ли          (пока хранится, но не используется)
```

`AssessmentManager::get($id)` читает мету и собирает `AssessmentDTO`.

## 4.2. Таблицы попыток

**`fs_lms_assessment_attempts`** — попытка прохождения:

| Колонка | Смысл |
|---|---|
| `assessment_id` | какая контрольная |
| `student_person_id` | кто проходит |
| `group_id` | для журнала |
| `attempt_number` | номер попытки (1, 2, 3…) |
| `started_at` | старт |
| `deadline_at` | **дедлайн, посчитанный сервером** (это и есть таймер) |
| `submitted_at` | когда сдал |
| `status` | `in_progress` / `submitted` / `graded` / `expired` |
| `total_score`, `max_score` | итоговый балл |
| **UNIQUE (assessment_id, student_person_id, attempt_number)** | защита от двойного клика |

**`fs_lms_assessment_answers`** — ответ на одно задание в попытке:
`attempt_id`, `task_id`, `answer_text`, `is_correct` (NULL = нужна ручная
проверка), `score`, `max_score`.

`AttemptStatus::isTerminal()` → true для `Graded`/`Expired`.

## 4.3. AttemptService — почему серверу нельзя доверять браузеру

`AttemptService` (`inc/Services/Assessment/AttemptService.php`) — главная мысль
этапа: **таймер считает сервер, а не браузер**. Часы на компьютере ученика можно
перевести — поэтому им верить нельзя.

```
start($studentPersonId, $assessmentId, $groupId): AttemptDTO
  1. assessment = AssessmentManager::get(...)        // нет → ошибка
  2. ЛИМИТ ПОПЫТОК: если attemptsAllowed>0 и countByAssessmentAndStudent >= лимит → ошибка
  3. ДЕДЛАЙН СЧИТАЕТ СЕРВЕР:  deadlineAt = now + timeLimit минут
                              (timeLimit=0 → +100 лет, т.е. без лимита)
  4. attempt_number = nextAttemptNumber()  // MAX(attempt_number)+1, без COUNT
  5. create(); если вернулось 0 (сработал UNIQUE-ключ — двойной клик) → ошибка
  6. событие AttemptStarted
  возвращает: AttemptDTO

saveAnswer($attemptId, $taskId, $answerText, $studentPersonId):
  requireActiveAttempt(...)                          // см. ниже
  AssessmentAnswerRepository::upsert(...)            // сохранить/обновить ответ

submit($attemptId, $studentPersonId): AttemptDTO
  requireActiveAttempt(...)
  update(status='submitted', submitted_at)
  событие AttemptSubmitted
  return AutoGradeService::gradeAttempt(...)         // сразу авто-проверка

expireIfOverdue($attemptId): bool                    // «ленивое» истечение
  если status=in_progress И isExpired(now) → status='expired', событие, true
```

Сердце защиты — приватный `requireActiveAttempt()`: на **каждом** save/submit он
проверяет, что попытка существует, принадлежит этому ученику, ещё `in_progress`
и **не просрочена** (`expireIfOverdue`). Так серверное время — единственный
авторитет.

## 4.4. AutoGradeService — авто-проверка

`AutoGradeService` (`inc/Services/Assessment/AutoGradeService.php`) проверяет
ответы автоматически — но только простые:

```
gradeAttempt(AttemptDTO): AttemptDTO
  для каждого ответа:
     шаблон задания НЕ из [Standard, Triple, Common] → ручная проверка (max=1, балл позже ставит препод)
     иначе → сравнить с мета 'task_answer':
             точное совпадение (без регистра, с trim) → score=1, is_correct=1
             иначе                                     → score=0, is_correct=0
  persistTotals(): если все авто → status='graded'
                   если есть ручные → status='submitted' (ждёт препода)
```

То есть авто-проверка сейчас — **бинарное точное сравнение строк** (1 балл за
задание) для трёх шаблонов. Всё остальное проверяет человек.

`finalize()` пересчитывает итог после того, как преподаватель проставил оценку
вручную одному из ответов.

## 4.5. Cron-страховка

Что, если ученик просто закрыл вкладку с незаконченной попыткой? Чтобы она не
висела вечно `in_progress`, есть **двухуровневое** истечение:

1. **Лениво** — `AttemptService::expireIfOverdue()` срабатывает на любом запросе
   к попытке (см. 4.3).
2. **Cron** — на случай, если запросов больше не будет:

```
CronHook::ExpireAttempts = 'fs_lms_expire_attempts'
CronController::register()  → wp_schedule_event(..., 'hourly', ...)
CronController::handleExpireAttempts()
   └─ AssessmentAttemptRepository::expireOverdue()
        UPDATE ... SET status='expired' WHERE status='in_progress' AND deadline_at < NOW()
```

## 4.6. Журнал: добавляем источник

Как и обещали в 3.3 — этап 4 просто добавил `AssessmentGradeSource` в
`GradeSourceRegistry`. Он берёт попытки (`listByGroupForGradebook`) и мапит их в
`GradebookEntryDTO` с `sourceType='attempt'`, `category='assessment'`. Журнал
автоматически объединил сдачи работ и контрольные.

## 4.7. Фронт: страница прохождения

`AssessmentPageController` (`inc/Controllers/Pages/AssessmentPageController.php`)
через `template_include` подменяет шаблон, **когда открыта одиночная запись типа
контрольной** (`is_singular()` + `isAssessmentPostType`):

```
loadTemplate($template):
   person       = PersonRepository::findByWpUserId(current_user_id)
   activeAttempt= AssessmentAttemptRepository::findActive(person->id, assessment->id)
   now          = clock->now()
   ThemeCompatService::header(); include attempt.php; footer(); exit;
```

`templates/frontend/assessment/attempt.php` показывает одно из состояний: не
залогинен / идёт попытка (форма + таймер) / время истекло / кнопка «начать».

JS `src/js/frontend/services/assessment.js`:
- **таймер** парсит `data-deadline` (серверный `deadline_at`) и тикает каждую
  секунду; в 0 — сам отправляет форму (сервер всё равно перепроверит);
- **автосохранение** ответов раз в ~3 сек (debounce) → `save_attempt_answer`;
- **submit** → `submit_attempt`; **start** → `start_attempt` + перезагрузка.

## 4.8. Цепочки AJAX этапа 4

Регистрирует `AssessmentController`. Ученические действия — `AttemptCallbacks`,
учительское — `GradeAttemptCallbacks`.

| Действие | AjaxHook | Nonce | Метод | → Сервис |
|---|---|---|---|---|
| Начать попытку | `start_attempt` | `StartAttempt` | `ajaxStartAttempt` | `AttemptService::start` |
| Сохранить ответ | `save_attempt_answer` | `StartAttempt`* | `ajaxSaveAttemptAnswer` | `AttemptService::saveAnswer` |
| Сдать | `submit_attempt` | `SubmitAttempt` | `ajaxSubmitAttempt` | `AttemptService::submit` → `AutoGradeService` |
| Оценить (препод) | `grade_attempt` | `GradeAttempt` | `ajaxGradeAttempt` | `AssessmentAnswerRepository::upsert` → `AutoGradeService::finalize` |

\* сохранение ответа переиспользует nonce `StartAttempt`.

Ученические хуки проверяют **только** `Nonce::X->verify()` (без capability —
доступ к попытке проверяется по владельцу). Учительский `grade_attempt` —
единственный с `authorize(Nonce::GradeAttempt, Capability::ManageLMSAssignments)`.

> 🛠️ Исправленный баг этапа 4: `GradeAttemptCallbacks` писал комментарий
> преподавателя в колонку `grader_note`, которой не было в таблице
> `fs_lms_assessment_answers` — из-за чего запись падала, а комментарий терялся.
> Колонка добавлена в миграцию (`grader_note text DEFAULT NULL`), а поле
> `graderNote` — в `AttemptAnswerDTO`, чтобы сохранённый комментарий можно было
> прочитать обратно.

---

## 5. Сводка: какие страницы регистрируются (всё вместе)

### Админские

| Страница | Кто регистрирует | Как |
|---|---|---|
| Меню «Обучение» + 6 сабменю | `LearningMenuController` | `add_menu_page` / `add_submenu_page` |
| Экраны списка/редактора CPT (`math_works`, `math_lessons`, `math_courses`, `math_assessments` …) | `SubjectController` | `register_post_type` (скрыты из меню, открываются из «Обучение») |
| Метабокс работы | `WorkMetaBoxController` | `add_meta_boxes` |
| Метабокс урока | `LessonMetaBoxController` | `add_meta_boxes` |
| Метабокс курса | `CourseMetaBoxController` | `add_meta_boxes` |
| Метабокс контрольной | `AssessmentMetaBoxController` | `add_meta_boxes` |

### Фронтовые

| Страница | Кто регистрирует | Условие |
|---|---|---|
| `/group/?gid=N` — кокпит преподавателя | `GroupCockpitController` | страница `group` + `canManage` |
| `/group/?gid=N` — кабинет ученика | `GroupCockpitController` | страница `group` + `isMemberEver` |
| `/group/` — список «Мои группы» | `GroupCockpitController` | страница `group`, без `gid` |
| Страница прохождения контрольной | `AssessmentPageController` | открыта одиночная запись `{key}_assessments` |

Обе фронт-страницы используют `template_include` (подмена шаблона темы) и
`ThemeCompatService` (совместимость с блочными темами).

---

## 6. Шпаргалка: ключевые классы и за что отвечают

```
ЭТАП 1 (банки контента)
  SubjectController ............ регистрирует все 6 CPT на предмет
  PostTypeResolver ............. строит/разбирает имена CPT
  Work/Lesson/CourseManager .... CRUD постов + мета (fs_lms_meta)
  Work/Lesson/CourseDTO ........ контейнеры данных
  *AuthoringService ............ выборка кандидатов для селекторов
  RefSelectField (+ наследники)  поле-селектор ссылок (чипсы + поиск)
  *MetaBoxController ........... метабоксы-конструкторы
  LearningMenuController ....... меню «Обучение»
  ContentUsageService ......... «используется в N»
  ContentLifecycleService ..... архивирование (fs_archived)

ЭТАП 2 (программа группы)
  CourseAssignmentService ...... назначить курс → снапшот в таблицу
  GroupLessonRepository ........ таблица fs_lms_group_lessons
  ScheduleService .............. порядок, даты, добавить/убрать урок
  LessonVisibilityService ...... видимость + заморозка работ (copy-on-publish)
  EffectiveWorksResolver ....... какие работы реально в уроке у группы
  LessonAccessPolicy ........... доступ УЧЕНИКА (none/read/read_submit)
  GroupAccessGuard ............. доступ УПРАВЛЕНИЯ (препод/админ)
  GroupCockpitController ....... фронт-страница /group/
  LearningEvent* ............... лента активности (append-only)

ЭТАП 3 (сдача + журнал)
  SubmissionService ............ сдать / оценить / вернуть
  SubmissionRepository ......... таблица fs_lms_submissions
  MediaManager ................. загрузка файла в Media Library
  GradeSourceInterface ......... контракт источника оценок
  SubmissionGradeSource ........ оценки из сдач
  GradebookService + Registry .. журнал как read-model (UNION источников)
  Submission/GradingCallbacks .. AJAX ученика / преподавателя

ЭТАП 4 (контрольные)
  AssessmentManager ............ чтение контрольной (CPT + мета)
  AttemptService ............... старт/сохранение/сдача, СЕРВЕРНЫЙ таймер
  AutoGradeService ............. авто-проверка (точное сравнение)
  Assessment*Repository ........ таблицы attempts / answers
  AssessmentGradeSource ........ оценки из попыток → в журнал
  CronController ............... страховочное истечение попыток
  AssessmentPageController ..... фронт-страница прохождения
  Attempt/GradeAttemptCallbacks  AJAX ученика / преподавателя
```

---

## 7. Что достигнуто на данном этапе проекта (после системы зачисления)

До модуля «Курсы» плагин умел работать **с людьми**: принимать заявки, зачислять
учеников, вести группы, хранить персональные данные. То есть это была **CRM
учебного центра** — кто, куда и когда записан.

Этапы 1–4 «Курсов» превратили её в **полноценную LMS** — систему, в которой
реально **идёт обучение**:

1. **Появилась библиотека учебного контента** (этап 1). Преподаватель собирает
   из переиспользуемых кирпичиков цепочку `задание → работа → урок → курс`,
   причём всё связано по ссылке: правка задания отражается всюду. Управление —
   из одного меню «Обучение», без «взрыва» админ-меню.

2. **Курс можно выдать группе** (этап 2). Назначение делает снапшот уроков в
   программу группы; дальше группа живёт независимо от шаблона. Есть расписание,
   управление видимостью с «заморозкой» состава работ при открытии, усиление
   программы для конкретной группы, и — впервые — **фронтовая страница-кокпит**
   для преподавателя. Доступ ученика к материалам определяется **членством в
   группе**, а не ролью, через единый `LessonAccessPolicy`. Все действия пишутся
   в ленту активности.

3. **Заработал учебный цикл «сдал → проверили»** (этап 3). Ученик сдаёт работу с
   файлом, преподаватель ставит балл/возвращает на доработку, у каждого свой
   кабинет. Появился **журнал оценок** — собирается на лету из источников
   (read-model), без отдельной таблицы и риска рассинхрона.

4. **Добавился экзамен-движок** (этап 4): контрольные с **серверным таймером**
   (клиенту не доверяем), несколькими попытками с защитой от гонок, авто-проверкой
   простых заданий и страховочным cron'ом для зависших попыток. Результаты
   автоматически влились в общий журнал — благодаря расширяемой архитектуре
   источников оценок.

**Архитектурно** всё это построено по единым правилам проекта: строгое
разделение слоёв (Controller → Callback → Service → Manager/Repository → DTO),
контент — в CPT, факты обучения — в собственных таблицах, доступ — через nonce +
capability, расширение — через интерфейсы (новый источник оценок добавляется без
правки журнала). Это значит, что следующие этапы (например, записи занятий из S3
или онлайн-курсы) можно «довешивать», не ломая уже сделанное.

> Где почитать дальше: подробный план и принятые архитектурные решения —
> в `.docs/Courses.md` (этот документ — его «человеческое» объяснение для
> новичка).


---

## 8. Кейсы для ручного тестирования (поиск багов)

> Прогонять на сайте `http://localhost:8080` под ролями **админ** и **преподаватель**
> (для управления) и **ученик** (для фронта). После правок PHP — `docker restart wp_app`
> (OPcache). Логи: `[FS LMS]` в `wp-content/debug.log` (последние 15 строк).
>
> **Особое внимание** к AJAX-коллбекам: 2026-06-19 был системный баг — все коллбеки
> Course/Assessment падали на первой строке (`Недостаточно данных`) из-за неверного вызова
> `Sanitizer`. Любой сценарий, где «кнопка ничего не делает» или «вечный спиннер», —
> в первую очередь смотреть Network → ответ AJAX (`success:false, data:"Недостаточно данных"`).

### A. Конструктор курса (авторинг)

| # | Шаги | Ожидаемо | Красные флаги |
|---|---|---|---|
| A1 | Обучение → Курсы → «Добавить» | Редирект на `admin.php?page=fs_lms_course_builder&subject=<key>` | Открылся нативный редактор поста вместо SPA |
| A2 | Ввести название курса → «Создать» | Курс создан, появилось дерево модулей | `Недостаточно данных`; курс не сохранился (проверить `wp_posts`) |
| A3 | «Добавить модуль» → переименовать | Модуль виден, имя сохраняется (статус «Все изменения сохранены») | Имя слетает после перезагрузки |
| A4 | В модуль «Добавить урок» (новый) | Урок создан и привязан к модулю | Урок создан, но не попал в модуль (см. `save_course_structure`) |
| A5 | Перетащить модуль / урок (drag-reorder) | Порядок сохраняется после F5 | Порядок откатывается; ошибка в `save_course_structure` |
| A6 | Открыть существующий курс из таблицы (Edit) | Редирект в конструктор с деревом курса | Пустое дерево при наличии модулей |

### B. Урок и типы шагов (главное для регрессий)

| # | Шаги | Ожидаемо | Красные флаги |
|---|---|---|---|
| B1 | Добавить шаг **Лекция** → набрать текст в TinyMCE | RTE-тулбар работает, текст сохраняется (автосейв) | Нет тулбара (TinyMCE не инициализировался); текст не сохраняется |
| B2 | Переключиться на другой шаг и обратно | Предыдущий TinyMCE корректно уничтожен, новый — чистый | Дубли редакторов, «прилипший» контент чужого шага |
| B3 | Добавить шаг **Видео** → вставить ссылку + описание | Поля сохраняются | — |
| B4 | Шаг **Материал** → «Медиатека» → выбрать файл | Имя файла отображается, `attachment_id` сохранён | Окно медиатеки не открылось (`wp.media` не загружен) |
| B5 | Шаг **Материал** → «Из библиотеки статей» | Подхватывается статья предмета, заголовок виден | `#ref` вместо заголовка после F5 |
| B6 | Шаг **Практика (work)** → «Создать и прикрепить» (инлайн) | Создаётся черновик работы, сразу прикреплён | `Недостаточно данных`; кнопка залипла disabled |
| B7 | Шаг **Тест (assessment)** → инлайн-создание | Создаётся черновик контрольной, прикреплён | то же, что B6 |
| B8 | Практика/Тест → «Выбрать из библиотеки» → поиск | Поиск находит существующие work/assessment | Пустой список при наличии контента (`get_step_candidates`) |
| B9 | Перезагрузить урок с ref-шагами | Заголовки work/assessment/article читаются (не «#ref») | «#ref» до клика — баг резолва заголовков |
| B10 | Переместить шаг между уроками (если доступно в UI) | Шаг переносится, `move_lesson_step` ок | Шаг дублируется/пропадает |

### C. Банки и связи «по ссылке»

| # | Шаги | Ожидаемо | Красные флаги |
|---|---|---|---|
| C1 | Создать задание (`{key}_tasks`), затем работу из него | Работа ссылается на задание (selector-чипы) | — |
| C2 | Изменить задание → открыть работу | Правка видна везде, где задание используется | Контент «закопирован», правка не долетела |
| C3 | Проверить «используется в N» (ContentUsageService) | Счётчик корректен | Удаление контента, на который ссылаются, без предупреждения |
| C4 | Архивировать контент (`fs_archived`) | Пропал из селекторов, старые ссылки живы | Старые ссылки сломались |

### D. Назначение группе + программа (кокпит препода)

| # | Шаги | Ожидаемо | Красные флаги |
|---|---|---|---|
| D1 | `/group/?gid=N` под преподом группы | Открылся кокпит (программа/ростер/активность) | Редирект на главную (проверить `canManage`) |
| D2 | Выбрать курс + `append` → «Назначить курс» | Уроки курса появились в программе (снапшот в `fs_lms_group_lessons`) | `Недостаточно данных`; строки не создались |
| D3 | Назначить другой курс с политикой `replace` | Старые строки заменены новыми | Дубли вместо замены |
| D4 | Назначить курс **чужого предмета** | Отказ (предмет курса ≠ предмет группы) | Назначился — дыра в проверке |
| D5 | Добавить отдельный урок из банка / убрать / переставить | Программа меняется, события в ленте | Порядок не сохраняется |
| D6 | Задать дату/преподавателя занятия | Сохранилось; видно в программе | — |

### E. Доступ и видимость

| # | Шаги | Ожидаемо | Красные флаги |
|---|---|---|---|
| E1 | Урок `hidden` → ученик открывает `/group/?gid=N` | Урока не видно | Скрытый урок виден ученику |
| E2 | Переключить видимость `hidden→open` (первое открытие) | Состав работ **замораживается** в `work_ids_snapshot` | Снапшот пуст; правка шаблона потом меняет выданное |
| E3 | После открытия изменить урок-шаблон | У группы состав работ НЕ меняется | Сдачи «осиротели» из-за смены состава |
| E4 | Ученик, зачисленный позже даты урока | Видит как бэк-каталог (`Read`), сдавать нельзя | Может сдать в недоступный урок |

### F. Плеер урока + прогресс/гейтинг

| # | Шаги | Ожидаемо | Красные флаги |
|---|---|---|---|
| F1 | Ученик: `/group/?gid=N&gl=M` | Открылся пошаговый плеер | Пустой ответ / 200 без тела (проверить шаблон) |
| F2 | Пройти шаги по очереди | Прогресс пишется (`mark_step_progress`), статусы меняются | Прогресс не сохраняется |
| F3 | Шаг с гейтом `sequential` без выполнения предыдущего | Шаг заблокирован | Доступен в обход гейта |
| F4 | Урок с `scheduled_at` в будущем | Заблокирован до даты | Доступен раньше времени |

### G. Сдача работ + проверка + журнал

| # | Шаги | Ожидаемо | Красные флаги |
|---|---|---|---|
| G1 | Ученик сдаёт работу: текст + файл | `submit_work` ок, статус «сдано» | Файл не прикрепился (MIME/размер); `Недостаточно данных` |
| G2 | Повторная сдача (до проверки) | Перезапись ответа (upsert), не дубль | Создалась вторая строка сдачи |
| G3 | Сдача после дедлайна при `allow_late=0` | Отказ «поздно» | Приняли просрочку |
| G4 | Препод: очередь проверки → поставить балл | `save_grade` ок, статус `graded` | Балл не сохранился |
| G5 | Препод: «вернуть на доработку» с комментарием | `returned`, у ученика снова форма | Возврат без комментария прошёл |
| G6 | Открыть журнал оценок группы | Сдачи отображаются (read-model) | Пусто при наличии оценок |

### H. Контрольные (таймер/попытки/авто-проверка)

| # | Шаги | Ожидаемо | Красные флаги |
|---|---|---|---|
| H1 | Ученик «Начать попытку» | `start_attempt`; дедлайн считает **сервер** | Дедлайн из браузера; двойной клик = 2 попытки |
| H2 | Отвечать → автосохранение (~3 сек) | `save_attempt_answer` ок | Ответы теряются при сдаче |
| H3 | Дождаться нуля таймера | Авто-сабмит; сервер перепроверяет время | Можно отвечать после нуля |
| H4 | Сдать контрольную с простыми заданиями | Авто-проверка (точное сравнение), `graded` | Авто-балл неверный |
| H5 | Контрольная со «сложными» заданиями | Статус `submitted` (ждёт препода) | Авто-проставила балл ручному заданию |
| H6 | Превысить лимит попыток | Отказ | Лишняя попытка |
| H7 | Бросить попытку, подождать час (или дёрнуть cron) | `expired` через cron-страховку | Висит `in_progress` вечно |
| H8 | Препод оценивает ручной ответ + комментарий | Балл + `grader_note` сохранились | Комментарий теряется (регресс старого бага) |

### I. Клон / форк

| # | Шаги | Ожидаемо | Красные флаги |
|---|---|---|---|
| I1 | Клонировать урок/работу/контрольную/курс | Создаётся независимая копия | Копия ссылается на оригинал (общая мета) |
| I2 | Форк урока под группу | Форк виден группе, скрыт из общей библиотеки | Форк «протёк» в общий банк (`getBankBySubject`) |

### J. Smoke-регрессия по AJAX (после Sanitizer-бага)

Быстрый прогон: для **каждого** действия из таблиц A–I открыть DevTools → Network и убедиться,
что ответ `success: true`. Любой `success:false` с `"Недостаточно данных"` при заполненной
форме = коллбек дёргает key-based `Sanitizer`-метод значением вместо имени ключа
(см. память `sanitizer-trait-is-key-name-based`). Минимальный чек-лист хуков:

- `create_course_draft`, `save_course_structure`, `create_lesson_in_module`,
  `update_lesson_meta`, `save_course_meta`
- `save_lesson_steps`, `move_lesson_step`, `get_step_candidates`
- `create_work_draft`, `create_assessment_draft`, `create_article_draft`, `create_task_draft`
- `assign_course`, `add_lesson_to_program`, `set_lesson_visibility`, `save_lesson_schedule`
- `submit_work`, `save_grade`, `return_submission`, `get_gradebook`
- `start_attempt`, `save_attempt_answer`, `submit_attempt`, `grade_attempt`
- `clone_lesson`, `clone_work`, `clone_assessment`, `clone_course`, `fork_lesson_for_group`

> Регрессии лучше закрывать PHPUnit-тестами на коллбеки (память `cover-callbacks-with-tests`) —
> именно непокрытый слой коллбеков скрыл баг на 65 сайтах.

---

## 9. Гайд: как менять страницы-банки (текст, колонки таблицы)

> Речь про экраны `wp-admin/edit.php?post_type={key}_courses|_lessons|_works|_tasks|_articles`
> и глобальный «Банк задач» `edit.php?post_type=fs_lms_problems`. Это **нативные таблицы
> записей WordPress** — отдельного «экрана настроек» у них нет, всё меняется в коде.

### 9.0. Карта: что чем управляется

| Элемент страницы | Файл | Что правит |
|---|---|---|
| Текст-описание над таблицей (per-subject банки) | `LearningMenuController::bankDescription()` (`match`) | строка описания по типу банка |
| Текст-описание «Банка задач» (глобальный) | `templates/admin/components/problems-bank-notice.php` | статичный HTML нотиса |
| Обёртка/вёрстка нотиса | `templates/admin/components/bank-notice.php` | классы `fs-lms-learning-notice` / `fs-lms-bank-intro` |
| Таб-бар предметов | `templates/admin/components/subject-bank-tabs.php` | вкладки предметов |
| Стили нотиса/табов | `src/scss/admin/components/_learning-bank.scss` | визуал (после правки — `npx gulp styles:admin`) |
| Какие CPT считаются «банком» | `LearningMenuController::bankTypeForPostType()` | распознавание экрана |
| Аргументы CPT (labels, supports, права) | `SubjectController::getDefaultCptArgs()` | поля редактора, метки |
| **Подключение CSS/JS на экране** | `Enqueue::enqueue_admin_assets()` | ⚠️ см. 9.3 — частый источник «стили не применились» |
| Колонки таблицы | отдельный Controller с хуками `manage_{cpt}_posts_*` | состав/значения колонок |

### 9.1. Изменить текст над таблицей

**Per-subject банк** (курсы/уроки/работы/задания/статьи) — правится одна строка в
`inc/Controllers/LearningMenuController.php`, метод `bankDescription()`:

```php
private function bankDescription( string $type ): string {
    return match ( $type ) {
        'articles' => __( 'Банк статей предмета. Справочные материалы для учеников.', 'fs-lms' ),
        // ← изменить текст здесь
        ...
    };
}
```

**Глобальный «Банк задач»** — текст лежит прямо в шаблоне
`templates/admin/components/problems-bank-notice.php` (статичный HTML, без параметра).

Меняешь только вёрстку/классы нотиса — `templates/admin/components/bank-notice.php`;
визуал — `_learning-bank.scss` (затем `npx gulp styles:admin`).

### 9.2. Изменить состав колонок таблицы

Колонки нативной таблицы настраиваются хуками WordPress. **Регистрировать их можно
только в Controller** (правило проекта), данные тянуть **через Manager/Service**, не
прямыми WP-запросами. Эталон — `ProblemsController` (колонка «Тип шаблона»):

```php
add_filter( "manage_{$cpt}_posts_columns",        [ $this, 'addColumns' ] );
add_action( "manage_{$cpt}_posts_custom_column",  [ $this, 'renderColumn' ], 10, 2 );
add_filter( "manage_edit-{$cpt}_sortable_columns",[ $this, 'sortableColumns' ] ); // опц.
add_action( 'pre_get_posts',                      [ $this, 'applyColumnSort' ] );  // опц.
```

```php
public function addColumns( array $columns ): array {
    $result = array();
    foreach ( $columns as $key => $label ) {
        if ( 'date' === $key ) {            // вставляем перед «Дата»
            $result['my_col'] = 'Моя колонка';
        }
        $result[ $key ] = $label;
    }
    return $result;
}

public function renderColumn( string $column, int $post_id ): void {
    if ( 'my_col' !== $column ) { return; }
    echo esc_html( /* значение через инжектированный Manager/Service */ );
}
```

> ⚠️ **Разница глобального и per-subject CPT.** `fs_lms_problems` — один тип, хук
> регистрируется один раз (как в `ProblemsController`). Задания/статьи/уроки/работы/курсы —
> **per-subject** (`{key}_tasks` и т.д.), поэтому хук нужно вешать **на каждый** CPT
> предмета в цикле по `SubjectRepository::readAll()` (см. пример ниже).

### 9.3. ⚠️ Ловушка: «стили не применились» на новом экране-банке

`Enqueue::enqueue_admin_assets()` подключает `admin.min.css`/`admin.min.js`
**только** если экран опознан как страница плагина или один из банк-CPT. На `edit.php`
GET-параметра `page` нет, поэтому экран опознаётся по флагам:

```php
$is_task_cpt    = $screen && PostTypeResolver::isTaskPostType( $screen->post_type );
$is_article_cpt = $screen && PostTypeResolver::isArticlePostType( $screen->post_type );
// ... остальные банки
if ( ! $is_plugin_page && ! $is_task_cpt && ! $is_article_cpt && /* ... */ ) {
    return; // ← ассеты НЕ подключатся
}
```

**Если добавляешь новый экран-банк или CPT** — заведи соответствующий `$is_*_cpt`-флаг
и включи его в это условие, иначе нотис/таблица будут без наших стилей (стандартный
белый notice с серой полоской). Так было со статьями (исправлено 2026-06-19) и ранее
с «Банком задач».

### 9.4. Пример «под ключ»: колонка «Используется в курсе» для банка заданий

Задача: на `edit.php?post_type={key}_tasks` показать, в каком(их) **курсе(ах)**
используется задание. Связь обратная: `Задание ←(item_ids) Работа ←(work-шаг) Урок
←(модуль) Курс`. Готовый обходчик — `ContentUsageService::usageList($type, $id)`
(`task→works`, `work→lessons`, `lesson→courses`; один «прыжок» за вызов).

Новый контроллер `inc/Controllers/TaskUsageColumnController.php`:

```php
<?php
declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Course\ContentUsageService;
use Inc\Services\PostTypeResolver;

class TaskUsageColumnController extends BaseController implements ServiceInterface {

    public function __construct(
        private readonly SubjectRepository   $subjects,
        private readonly ContentUsageService $usage,
    ) {
        parent::__construct();
    }

    public function register(): void {
        // per-subject: вешаем хуки на КАЖДЫЙ {key}_tasks
        foreach ( $this->subjects->readAll() as $subject ) {
            $cpt = PostTypeResolver::tasks( $subject->key );
            add_filter( "manage_{$cpt}_posts_columns",       array( $this, 'addColumns' ) );
            add_action( "manage_{$cpt}_posts_custom_column", array( $this, 'renderColumn' ), 10, 2 );
        }
    }

    public function addColumns( array $columns ): array {
        $result = array();
        foreach ( $columns as $key => $label ) {
            if ( 'date' === $key ) {
                $result['fs_used_in_course'] = 'Используется в курсе';
            }
            $result[ $key ] = $label;
        }
        return $result;
    }

    public function renderColumn( string $column, int $post_id ): void {
        if ( 'fs_used_in_course' !== $column ) {
            return;
        }

        // task → works → lessons → courses (дедуп по id)
        $courses = array();
        foreach ( $this->usage->usageList( 'task', $post_id ) as $work ) {
            foreach ( $this->usage->usageList( 'work', $work['id'] ) as $lesson ) {
                foreach ( $this->usage->usageList( 'lesson', $lesson['id'] ) as $course ) {
                    $courses[ $course['id'] ] = $course['title'];
                }
            }
        }

        echo $courses ? esc_html( implode( ', ', $courses ) ) : '—';
    }
}
```

Зарегистрировать в `Init::getServices()` (добавить `TaskUsageColumnController::class`).

**Замечания:**
- Хочешь показать **работы** (один прыжок, дёшево) вместо курсов — оставь только
  `usageList( 'task', $post_id )` и выведи `array_column( ..., 'title' )`.
- Обратный обход идёт по 3 уровням CPT (N×M запросов на строку). Для больших банков
  кешируй результат (`set_transient`) и сбрасывай на `save_post_{cpt}` / `delete_post`
  (как `ContentCacheService`), либо ограничь колонку малыми списками.
- Вычисляемую колонку «используется в» **нельзя** сортировать SQL-ом из коробки
  (значения нет в БД) — сортировку `pre_get_posts` оставляем только для мета-колонок
  (пример — `ProblemsController::applyColumnSort()`).

### 9.5. Чек-лист правок

1. Текст нотиса → `LearningMenuController::bankDescription()` (или `problems-bank-notice.php`).
2. Колонки → отдельный Controller с `manage_{cpt}_posts_*`; per-subject — цикл по `readAll()`.
3. Данные колонки → через Manager/Service (напр. `ContentUsageService`), не прямой `WP_Query`.
4. Новый экран/CPT → добавить `$is_*_cpt`-флаг в `Enqueue::enqueue_admin_assets()` (9.3).
5. Стили → `_learning-bank.scss` → `npx gulp styles:admin`.
6. Контроллер → зарегистрировать в `Init::getServices()`.
7. Покрыть логику данных тестом (память `cover-callbacks-with-tests`).
