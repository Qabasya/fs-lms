# Tasks.md — Декомпозиция этапов реализации LMS

Рабочая декомпозиция этапов из [`Courses.md`](./Courses.md) на конкретные задачи.
Для каждой задачи указано: **что создаётся**, **с чем связано** (зависимости и точки интеграции),
**какие методы/сигнатуры** нужны и **критерий готовности**.

Документ ведётся по мере прохождения этапов. Сейчас детализированы **Этап 1 — Банки контента
(работы, уроки, курсы) + меню «Обучение»**, **Этап 2 — Программа группы** и
**Этап 3 — Сдача работ и прогресс**.

> **Переработка Этапа 1.** Ранняя версия делала урок с инлайн-бакетами `practice/independent/homework`
> (`task_ids` прямо в мете урока). По решению ([`Courses.md` §0](./Courses.md) №3–5) введён промежуточный
> банк **работ** (`{key}_works`) и банк **курсов** (`{key}_courses`); урок теперь ссылается на работы,
> а не на задачи. Уже отгруженный код урока (`TaskBucketField`, `TaskTypeField`, `LessonTemplate`,
> `lesson-bucket-service.js`) идёт в **переработку** — помечено `[rework]` у соответствующих задач.

Легенда статусов: `[ ]` не начато · `[~]` в работе · `[x]` готово.

---

## Этап 1 — Банки контента: работы, уроки, курсы + меню «Обучение»

### Цель этапа

Переиспользуемая **библиотека контента предмета** — цепочка банков `tasks → works → lessons → courses`
(см. [`Courses.md` §0–§1](./Courses.md)). Каждый банк — per-subject CPT, связь по ссылке, не копией.
На этом этапе создаются **определения** (банки) + единое меню «Обучение»; привязка курса к группе,
расписание и доставка — Этап 2.

Сущности этапа:
- **Работа** (`{key}_works`) — типизированный (`practice|independent|homework`) упорядоченный пул
  ссылок на задания + инструкция. Переиспользуется в уроках.
- **Урок** (`{key}_lessons`) — тема + теория (inline / ссылка на статью) + упорядоченные ссылки на
  **работы** (не на задачи). Переиспользуется в курсах. Может быть без работ.
- **Курс** (`{key}_courses`) — упорядоченные ссылки на уроки (шаблон). Назначается группе — Этап 2.

### Готово, когда (Definition of Done)

- Меню «Обучение» (top-level) с сабменю **Курсы / Уроки / Работы / Задания / Статьи**; subject-CPT
  убраны из top-level (`show_in_menu=false`). Меню по умолчанию скоупится под предметы препода
  (через его группы), но чужое не прячет ([`Courses.md` §0 №12, §7 #17](./Courses.md)).
- Преподаватель создаёт **работу**: задаёт тип, прикрепляет задания **ссылкой** (выбор по номеру из
  библиотеки или «создать новое» → реальный `{key}_tasks` → авто-ссылка), пишет инструкцию.
- Создаёт **урок**: тема + теория, прикрепляет **работы** ссылкой (селектор работ предмета),
  упорядочивает. Урок без работ (просто занятие) сохраняется без ошибок.
- Создаёт **курс**: упорядоченный список уроков ссылкой.
- Селекторы по умолчанию показывают **«мои»** (по `post_author`) с переключением на весь банк предмета;
  для заданий — опц. фильтр по «коллекции».
- Связь — ссылка: правка задания/работы/урока отражается во всех потребителях.
- Контент с зависимостями **нельзя удалить** физически (только `archived`); orphan удаляется штатно.
  Гейт удаления и бейдж «используется в N» — на едином `ContentUsageService`.

---

### Доменная модель банков

Все три банка — CPT, регистрируются per-subject тем же механизмом, что `{key}_tasks` /
`{key}_articles`. Хранение строго по текущему расколу: **контент в CPT (пост + post-meta)**,
факты обучения — в таблицах (этапы 2–4). Вся meta (кроме `post_title`/`post_content`) — одним
массивом в `PostMetaName::Meta` (`fs_lms_meta`), как у заданий.

**Работа `{key}_works`** — `post_title` = название работы:
```php
// get_post_meta( $work_id, PostMetaName::Meta->value, true )
[
    'work_type'    => 'practice',   // WorkType: practice|independent|homework
    'task_ids'     => [],           // упорядоченные ссылки на {key}_tasks (не копии)
    'instructions' => '',           // опц. свободный текст (что делать)
]
```

**Урок `{key}_lessons`** — `post_title` = тема, `post_content` = теория (inline):
```php
[
    'theory_article_id' => 0,       // опц. ссылка на {key}_articles (0 = inline-теория)
    'work_ids'          => [],      // упорядоченные ссылки на {key}_works (ТОЛЬКО работы, не задачи)
]
```

**Курс `{key}_courses`** — `post_title` = название, `post_content` = описание:
```php
[
    'lesson_ids' => [],             // упорядоченные ссылки на {key}_lessons
    // 'sections' => [],            // опц. секции/темы — на будущее (Courses.md §6)
]
```

| Поле | Где хранится | Примечание |
|---|---|---|
| название работы/курса, тема урока | `post_title` | нативно |
| теория урока (inline) | `post_content` (`supports: editor`) | переопределяется `theory_article_id` |
| описание курса | `post_content` | нативно |
| ссылки `task_ids` / `work_ids` / `lesson_ids` | meta `PostMetaName::Meta` | упорядоченные массивы ID, **ссылки** не копии |
| автор | `post_author` | нативно ([`Courses.md` §7 #12](./Courses.md)) |

### Решения и допущения этапа

1. **Авторинг — в wp-admin** через нативные экраны CPT + метабоксы, как у `tasks`/`articles`.
   Доступ к ним — через меню «Обучение» (T1.20–T1.22). Фронтовый кокпит ([`Courses.md` §4](./Courses.md)) —
   сборка программы группы (Этап 2), а не авторинг контента.
2. **У каждого банка один фиксированный шаблон метабокса** (`WorkTemplate`/`LessonTemplate`/
   `CourseTemplate`), без per-term резолва. НЕ переиспользуем `TaskTemplate` enum / `TemplateRegistry`
   / `TemplateResolver` (они для выбора шаблона задания по номеру).
3. **Capability — переиспользуем** `Capability::ManageLMSAssignments`, новые caps НЕ вводим
   ([`Courses.md` §7 #2](./Courses.md)). Все три CPT — `capability_type => 'fs_lms_content'` +
   `map_meta_cap => true`, право мапится на `manage_lms_assignments`.
4. **Работа типизированная** ([`Courses.md` §7 #13](./Courses.md)): `work_type` на работе. Enum
   `WorkType` вводится здесь, в фундаменте (раньше планировался на Этапе 3 — переносим).
5. **Урок ссылается на работы, не на задачи** ([`Courses.md` §7 #14](./Courses.md)). `[rework]`
   прежних `TaskBucketField`/`TaskTypeField`/`LessonTemplate`: на уроке селектор **работ**; фильтр
   по типу задания переезжает в селектор **заданий внутри работы**. «Тема»=`post_title`,
   «теория»=`post_content`.
6. **Прикрепление ссылкой, не копией** (Вариант А, модель Tutor Content Bank): задание→работа,
   работа→урок, урок→курс. «Создать новое» из конструктора = существующая/новая модалка → реальный
   пост в банке → авто-ссылка (T1.23).
7. **Видимость банков — весь предмет, фильтр «мои» (`post_author`) по умолчанию**
   ([`Courses.md` §0 №10](./Courses.md)). Жёсткой стены «только свои» внутри предмета нет.
8. **Меню «Обучение» — мягкий скоуп по предметам препода** ([`Courses.md` §7 #17](./Courses.md)):
   `TeacherSubjectsService` (препод → предметы через `groups.teacher_id`) задаёт дефолтные
   вкладки-предметы; **без** `current_screen`-guard (цель — облегчить выбор, не скрыть чужое).
9. **«Коллекции» = пользовательская таксономия на `{key}_tasks`** (`TaxonomyRepository` уже есть),
   напр. «Циклы Python». Селектор заданий в работе фильтрует по коллекции — без нового слоя (T1.24).
10. **Под Python нужен шаблон задания «условие + ответ + решение».** `StandardTaskTemplate` = условие
    + ответ. Берём `CodeTaskTemplate` / `TaskTextSolution` либо заводим шаблон с полем «решение» (T1.25).

> **Что взято из Tutor LMS:** ссылочное переиспользование (Content Bank) на всех трёх уровнях,
> «коллекции» как тематические наборы, счётчик «используется в N» (T1.26), модалка «создать в
> конструкторе». Что НЕ берём: Tutor — self-paced **курс**; у нас курс — шаблон, назначаемый когорте
> + расписание (Этап 2), видео — запись занятия (Этап 5).

---

### Задачи

#### Слой Enums / фундамент

##### T1.1 — `PostTypeResolver`: резолв CPT работ и курсов
- **Файл:** `inc/Services/PostTypeResolver.php` (расширение существующего; `lessons()` уже есть).
- **Назначение:** единая точка формирования slug `{key}_works` / `{key}_courses` и обратного разбора —
  без ручной конкатенации (правило `CLAUDE.md`).
- **Связи:** `SubjectController` (регистрация), мета-бокс-контроллеры, менеджеры, поля (предмет из `$post->post_type`).
- **Методы (добавить; по образцу `lessons()`):**
  ```php
  public const string WORKS_SUFFIX   = '_works';
  public const string COURSES_SUFFIX = '_courses';
  public static function works( string $subject_key ): string;            // "{key}_works"
  public static function courses( string $subject_key ): string;          // "{key}_courses"
  public static function isWorkPostType( string $post_type ): bool;
  public static function isCoursePostType( string $post_type ): bool;
  public static function subjectFromWorkPostType( string $post_type ): string;
  public static function subjectFromCoursePostType( string $post_type ): string;
  ```
- **Готово:** `works('math') === 'math_works'`, `courses('math') === 'math_courses'`, обратный разбор стабилен.

##### T1.2 — `WorkType` enum
- **Файл:** `inc/Enums/WorkType.php` (новый; раньше планировался на Этапе 3 — переносим в фундамент).
- **Кейсы:** `Practice='practice'`, `Independent='independent'`, `Homework='homework'` + `label()`.
- **Связи:** `WorkTypeField` (T1.11), `WorkDTO`, `submissions.work_type` (Этап 3 берёт у работы).
- **Готово:** типобезопасный словарь типов работ — единый источник значений.

##### T1.3 — `Nonce`: `AuthorWork`, `AuthorCourse`
- **Файл:** `inc/Enums/Nonce.php` (`AuthorLesson` уже есть).
- **Назначение:** защита admin-AJAX селекторов работы/курса. Сохранение метабоксов — существующий
  `Nonce::SaveMeta` (как у заданий), отдельный save-nonce не нужен.
- **Добавить:** `case AuthorWork = 'fs_lms_author_work';`, `case AuthorCourse = 'fs_lms_author_course';`
- **Готово:** `->create()`/`->verify()` доступны.

##### T1.4 — `AjaxHook`: хуки селекторов банков
- **Файл:** `inc/Enums/AjaxHook.php` (секции `// ==== Работы ====`, `// ==== Курсы ====`;
  `GetLessonArticles` уже есть). `[rework]` `GetLessonTaskCandidates` → `GetLessonWorkCandidates`.
- **Добавить:**
  ```php
  case GetWorkTaskCandidates   = 'get_work_task_candidates';   // задания предмета: task_type, collection, scope(mine|subject), search
  case GetLessonWorkCandidates = 'get_lesson_work_candidates'; // работы предмета: work_type, scope, search   [rework]
  case GetCourseLessonCandidates = 'get_course_lesson_candidates'; // уроки предмета: scope, search
  ```
  Типы заданий отдаёт существующий `AjaxHook::GetTaskTypes` — переиспользуем.
- **Готово:** хуки в `AjaxHook::toJsArray()`, доступны фронту.

##### T1.5 — Capability банков (решение, без нового enum)
- **Файл:** — (решение здесь; правка прав — `RoleManager`, T1.7).
- **Назначение:** зафиксировать `ManageLMSAssignments` для всех трёх CPT.
- **Связи:** `capability_type => 'fs_lms_content'` + `map_meta_cap => true` → мета-права мапятся на
  `manage_lms_assignments` у `FSTeacher`/админа (версионируется `fs_lms_caps_version` в `Init`).
- **Готово:** преподаватель редактирует работы/уроки/курсы; новых caps в БД нет.

---

#### Слой Registrars — регистрация CPT + права

##### T1.6 — Регистрация CPT `{key}_works`, `{key}_courses` + скрытие банков из top-level
- **Файлы:** `inc/Controllers/SubjectController.php` (`registerForSubject()` + `getDefaultCptArgs()`).
  `SubjectCPTRegistrar`/`CPTManager` — **без изменений** (`addStandardType()` универсален).
- **Назначение:** регистрировать `{key}_works` и `{key}_courses` в одной очереди с tasks/articles/lessons.
  `{key}_lessons` уже регистрируется — его `options` не трогаем.
- **Связи:** два новых вызова `addStandardType()`; проходит фильтр `fs_lms_cpt_args`;
  `CPTManager::register()` (`register_post_type` на `init`) менять не нужно.
- **Конфиг (`options`) для works/courses** (как lessons — против «взрыва CPT»):
  ```php
  'supports'            => [ 'title', 'editor', 'author' ],
  'show_in_menu'        => false,    // меню «Обучение» (T1.8–T1.10)
  'show_in_rest'        => false,
  'exclude_from_search' => true,
  'capability_type'     => 'fs_lms_content',
  'map_meta_cap'        => true,
  'has_archive'         => false,
  ```
- **`getDefaultCptArgs()`** — ветки `match`: `'works'` (`nom:Работа, gender:feminine`),
  `'courses'` (`nom:Курс, gender:masculine`).
- **Скрыть существующие банки из top-level:** в ветках `'tasks'`/`'articles'` добавить
  `'show_in_menu' => false` (сейчас дефолт `true` → отдельные top-level меню на каждый предмет).
  Теперь все пять контент-CPT доступны только через «Обучение».
- **Готово:** после reload зарегистрированы `{key}_works`/`{key}_courses`; tasks/articles исчезли из
  top-level wp-admin; редакторы доступны по прямому URL.

##### T1.7 — Мапинг мета-прав CPT на роли
- **Файл:** `inc/Managers/RoleManager.php` (`syncCapabilities()`).
- **Назначение:** `FSTeacher` + админ получают производные `fs_lms_content`-права (мапятся на
  `manage_lms_assignments`).
- **Связи:** вызывается из `Init::run()` при смене `fs_lms_caps_version` — версию **поднять**.
  Lessons уже использует `capability_type => 'fs_lms_content'`; works/courses — тот же тип, отдельной
  правки набора прав не требуют, но проверить на dev (см. Открытые вопросы #2).
- **Готово:** преподаватель открывает списки/редакторы работ/уроков/курсов без «недостаточно прав».

---

#### Меню «Обучение» (единая точка входа в банки)

##### T1.8 — `Menu` enum: top-level «Обучение» + сабменю банков
- **Файл:** `inc/Enums/Menu.php` (по образцу `Menu::Subjects`).
- **Добавить:** top-level `Learning` (slug `fs_lms_learning`, заголовок «Обучение»,
  cap `manage_lms_assignments`, icon `dashicons-welcome-learn-more`) + кейсы сабменю
  `LearningCourses` / `LearningLessons` / `LearningWorks` (Задания/Статьи — тем же приёмом).
- **Готово:** enum-кейсы со слагами/заголовками доступны билдеру меню.

##### T1.9 — `TeacherSubjectsService` (препод → предметы)
- **Файл:** `inc/Services/Course/TeacherSubjectsService.php`.
- **Назначение:** вернуть предметы, которые препод реально ведёт — дефолтные вкладки меню (мягкий
  скоуп, [`Courses.md` §7 #17](./Courses.md)). Админ — все предметы.
- **Связи:** `GroupsRepository` (группы по `teacher_id` → `subject_key`), `SubjectRepository`.
- **Методы:**
  ```php
  public function subjectsForUser( int $userId ): array;     // SubjectDTO[]: предметы препода; Admin — все
  public function defaultSubjectKey( int $userId ): string;  // первый предмет (активная вкладка)
  ```
- **Готово:** препод одного предмета → один предмет; мультипредметник → все свои; админ → все.

##### T1.10 — `LearningMenuController` (регистрация меню + страницы-сабменю)
- **Файл:** `inc/Controllers/LearningMenuController.php` (`ServiceInterface`; меню — через
  `MenuRegistrar`/`MenuManager`, как `AdminController`).
- **Назначение:** зарегистрировать top-level «Обучение» + 5 сабменю; каждая страница рендерит
  **вкладки-предметы** (из `TeacherSubjectsService`), под активной вкладкой — список банка предмета.
- **Связи / реализация:**
  - Список банка — ссылка на нативный `edit.php?post_type={key}_{works|lessons|courses|tasks|articles}`
    (экран скрыт из меню, но доступен по URL → бесплатные редактор/поиск/bulk/«Добавить»); страница
    сабменю = переключатель предметов + переход на нужный нативный экран.
  - Мягкий скоуп: вкладки = только предметы препода; **без** `current_screen`-guard (чужое по прямому
    URL доступно осознанно, [`Courses.md` §7 #17](./Courses.md)).
  - cap всех пунктов — `manage_lms_assignments`.
- **Готово:** в wp-admin есть «Обучение» → Курсы/Уроки/Работы/Задания/Статьи; русист по умолчанию видит
  вкладку «Русский», питонист — «Python»; список ведёт на нативный экран нужного CPT.

---

#### Банк работ (`{key}_works`)

##### T1.11 — Поля работы: `WorkTypeField` + `TaskRefField`
- **Файлы:** `inc/MetaBoxes/Fields/WorkTypeField.php`, `inc/MetaBoxes/Fields/TaskRefField.php`
  (новые, `extends BaseField implements FieldInterface`).
- **Назначение:**
  - `WorkTypeField` — `<select>` типа работы (`WorkType`: practice/independent/homework).
  - `TaskRefField` — **упорядоченный список ссылок на задания** (`task_id`-чипы). Чипы двумя путями:
    **(а) из библиотеки** — ввод номера/поиск → выпадающий список (T1.21); **(б) создать новое** —
    модалка задания (T1.23) → новый `{key}_tasks` → авто-чип. Хранится ссылка, не копия.
- **Связи:**
  - Оба используются в `WorkTemplate` (T1.12).
  - `TaskRefField::render()` → предмет через `PostTypeResolver::subjectFromWorkPostType()`; контейнер
    с `data-subject` для JS-селектора (T1.21); ID — скрытыми инпутами `fs_lms_meta[task_ids][]`.
  - `sanitize()` из `MetaBoxManager::saveFields()`.
- **Методы (`FieldInterface`):** `render(\WP_Post $post, string $id, string $label, mixed $value): void;`
  `sanitize()` — `WorkTypeField`: валидный `WorkType::value`; `TaskRefField`:
  `array_values(array_map('intval', array_filter(...)))`.
- **Готово:** тип сохраняется как `work_type`; задания — упорядоченный `task_ids[]`; порядок переживает reload.

##### T1.12 — `WorkTemplate` + `WorkMetaBoxController`
- **Файлы:** `inc/MetaBoxes/Templates/WorkTemplate.php` (`extends BaseTemplate`),
  `inc/Controllers/WorkMetaBoxController.php` (`extends BaseController implements ServiceInterface`,
  `use Authorizer`; аналог `LessonMetaBoxController`).
- **Назначение:** форма работы (секции «Тип», «Инструкция», «Задания»); навесить метабокс на все
  `{key}_works`, отрисовать, сохранить meta.
- **Связи / хуки:**
  - Поля: `work_type` → `WorkTypeField`, `instructions` → textarea-поле, `task_ids` → `TaskRefField`.
  - `add_meta_boxes` → `MetaBoxRegistrar::add()` для всех `{key}_works`
    (`SubjectRepository::readAll()` + `PostTypeResolver::works`).
  - `save_post` → `isWorkPostType`, `authorizePostSave(Nonce::SaveMeta, $id)`,
    `MetaBoxManager::saveFields($id, PostMetaName::Meta->value, $raw, $template->get_fields())`.
- **Конструктор (DI):** `SubjectRepository`, `MetaBoxRegistrar`, `MetaBoxManager`, `WorkTemplate`.
- **Готово:** на экране работы — метабокс; сохранение пишет корректный `fs_lms_meta`; чужие CPT не затронуты.

##### T1.13 — `WorkDTO` + `WorkManager`
- **Файлы:** `inc/DTO/Course/WorkDTO.php` (`readonly`), `inc/Managers/WorkManager.php` (по образцу `TaskManager`).
- **Назначение:** типобезопасная передача + CRUD работы (пост `PostManager` + meta `MetaBoxManager`).
- **DTO-поля:** `id`, `subjectKey`, `title`, `workType` (`WorkType`), `taskIds[]`, `instructions`, `authorId`, `status`.
- **Методы DTO:** `fromPost(\WP_Post, array $meta)`, `fromArray()`, `toArray()`, `isEmpty()` (нет задач).
- **Методы Manager:** `create(string $subjectKey, WorkDTO): int`, `update(int, WorkDTO): bool`,
  `get(int): ?WorkDTO`, `getBankBySubject(string, array $args=[]): array`, `delete(int): bool`.
- **Готово:** работа создаётся/читается/обновляется; `getBankBySubject()` отдаёт работы предмета.

##### T1.14 — `WorkAuthoringService` + `WorkCallbacks` + `WorkController`
- **Файлы:** `inc/Services/Course/WorkAuthoringService.php`, `inc/Callbacks/Course/WorkCallbacks.php`
  (`use Authorizer; use Sanitizer;`), `inc/Controllers/WorkController.php` (`extends AjaxController`).
- **Назначение:** кандидаты заданий для селектора + AJAX-обработчик + регистрация хука.
- **Связи / методы:**
  - `WorkAuthoringService::getTaskCandidates(string $subjectKey, int $taskTypeTermId=0,
    int $collectionTermId=0, string $scope='mine', string $search=''): array` (`{id, number, title, author}`);
    `validateTaskIds(string $subjectKey, array $taskIds): array` (отбросить чужие/несуществующие).
  - `WorkCallbacks::ajaxGetWorkTaskCandidates()` —
    `authorize(Nonce::AuthorWork, Capability::ManageLMSAssignments)` → `success(list)`.
  - `WorkController::ajaxActions()` → `[[AjaxHook::GetWorkTaskCandidates, $cb]]`.
- **Готово:** селектор отдаёт только задания нужного предмета (и типа/коллекции, если заданы);
  неавторизованный — 403.

---

#### Банк уроков (`{key}_lessons`) — `[rework]`

> Урок реализован в ранней версии Этапа 1 с инлайн-бакетами. Здесь — переработка под ссылки на
> **работы**: `TaskBucketField`/`TaskTypeField` убираются, появляется селектор работ. `LessonManager`
> и `LessonMetaBoxController` сохраняются (меняется только форма meta и набор полей).

##### T1.15 — `[rework]` Поля урока: `ArticleRefField` + `WorkRefField`
- **Файлы:** `ArticleRefField.php` (уже есть — без изменений), `WorkRefField.php` (новый),
  **удалить** `TaskBucketField`, `TaskTypeField`.
- **Назначение:**
  - `ArticleRefField` — опц. выбор статьи `{key}_articles` (теория ссылкой; переопределяет inline).
  - `WorkRefField` — **упорядоченный список ссылок на работы** (`work_id`-чипы): выбор из банка работ
    предмета (поиск по названию/типу, T1.21) или «создать новую» (T1.23). Хранится ссылка, не копия.
- **Связи:** `WorkRefField::render()` → предмет через `PostTypeResolver::subjectFromLessonPostType()`;
  ID — скрытыми инпутами `fs_lms_meta[work_ids][]`. Списки подаются из сервиса (не прямой WP-вызов в поле).
- **Методы:** `render(...)`; `sanitize()` → `ArticleRefField`: `(int)`; `WorkRefField`:
  `array_values(array_map('intval', array_filter(...)))`.
- **Готово:** урок ссылается на работы (упорядоченно) и опц. на статью; бакеты заданий удалены.

##### T1.16 — `[rework]` `LessonTemplate` / `LessonDTO` / `LessonManager` / `LessonAuthoringService` / `LessonCallbacks`
- **Файлы:** существующие урочные классы — переработать под `work_ids`.
- **`LessonTemplate`:** секции «Теория (источник)» (`ArticleRefField`) + «Работы» (`WorkRefField`).
  Убрать секции «Тип заданий / Практика / СР / ДЗ». `get_id()='lesson'`.
- **`LessonDTO`:** поля `id`, `subjectKey`, `topic`(=title), `theoryHtml`(=content), `theoryArticleId`,
  **`workIds[]`** (вместо `taskType`/`practice`/`independent`/`homework`), `authorId`, `status`;
  `isEmpty()` = нет работ.
- **`LessonManager`:** сигнатуры без изменений (`create`/`update`/`get`/`getBankBySubject`/`delete`) —
  меняется только форма meta (`work_ids`).
- **`LessonAuthoringService`:** `getTaskCandidates()` → **`getWorkCandidates(string $subjectKey,
  string $workType='', string $scope='mine', string $search=''): array`** (`{id, title, work_type, author}`);
  `validateWorkIds(string $subjectKey, array $workIds): array`; `getArticles()` остаётся.
- **`LessonCallbacks`/`LessonController`:** `ajaxGetLessonTaskCandidates` → **`ajaxGetLessonWorkCandidates`**
  (`Nonce::AuthorLesson`); `GetLessonArticles` остаётся; хук в `ajaxActions()` обновить.
- **Готово:** урок собирается из работ; селектор работ отдаёт работы предмета; старые task-bucket-пути удалены.

---

#### Банк курсов (`{key}_courses`)

##### T1.17 — `LessonRefField` + `CourseTemplate` + `CourseMetaBoxController`
- **Файлы:** `inc/MetaBoxes/Fields/LessonRefField.php`, `inc/MetaBoxes/Templates/CourseTemplate.php`
  (`extends BaseTemplate`), `inc/Controllers/CourseMetaBoxController.php` (по образцу `LessonMetaBoxController`).
- **Назначение:**
  - `LessonRefField` — **упорядоченный список ссылок на уроки** (`lesson_id`-чипы, drag-drop): выбор из
    банка уроков предмета (поиск, T1.21) или «создать новый» (T1.23). Хранится ссылка.
  - `CourseTemplate` — форма курса: описание (`post_content`) + секция «Уроки» (`LessonRefField`).
    `get_id()='course'`. (Секции/темы `sections[]` — на будущее, не в этой задаче.)
  - `CourseMetaBoxController` — навесить метабокс на все `{key}_courses`, рендер, save
    (`isCoursePostType`, `Nonce::SaveMeta`, `MetaBoxManager::saveFields`).
- **Связи:** `LessonRefField::render()` → предмет через `PostTypeResolver::subjectFromCoursePostType()`;
  ID — `fs_lms_meta[lesson_ids][]`. Список уроков из сервиса (T1.18).
- **Готово:** курс собирается из упорядоченных уроков; meta `lesson_ids` сохраняется; чужие CPT не затронуты.

##### T1.18 — `CourseDTO` + `CourseManager` + `CourseAuthoringService` + `CourseCallbacks` + `CourseController`
- **Файлы:** `inc/DTO/Course/CourseDTO.php`, `inc/Managers/CourseManager.php`,
  `inc/Services/Course/CourseAuthoringService.php`, `inc/Callbacks/Course/CourseCallbacks.php`
  (`use Authorizer; use Sanitizer;`), `inc/Controllers/CourseController.php` (`extends AjaxController`).
- **DTO-поля:** `id`, `subjectKey`, `title`, `descriptionHtml`(=content), `lessonIds[]`, `authorId`, `status`.
- **`CourseManager`:** `create`/`update`/`get`/`getBankBySubject`/`delete` (по образцу `LessonManager`).
  `getBankBySubject()` — потребитель Этапа 2 (назначение курса группе).
- **`CourseAuthoringService`:** `getLessonCandidates(string $subjectKey, string $scope='mine',
  string $search=''): array` (`{id, topic, author}`); `validateLessonIds(string, array): array`.
- **`CourseCallbacks::ajaxGetCourseLessonCandidates()`** — `authorize(Nonce::AuthorCourse,
  Capability::ManageLMSAssignments)`. `CourseController::ajaxActions()` → `[[AjaxHook::GetCourseLessonCandidates, $cb]]`.
- **Готово:** курс CRUD-ится; селектор уроков отдаёт уроки предмета; неавторизованный — 403.

---

#### Слой Init / Enqueue / JS / SCSS

##### T1.19 — Регистрация сервисов
- **Файл:** `inc/Init.php` (`getServices()`).
- **Добавить:** `LearningMenuController`, `WorkMetaBoxController`, `WorkController`,
  `CourseMetaBoxController`, `CourseController`, `ContentDeletionGuard` (+ уже есть
  `LessonMetaBoxController`/`LessonController`). Все реализуют `ServiceInterface`; зависимости type-hinted.
- **Готово:** `Init::run()` поднимает все сервисы без ошибок DI.

##### T1.20 — Локализация и ассеты банков
- **Файл:** `inc/Core/Enqueue.php` (единственное место `wp_localize_script`).
- **Назначение:** на экранах `{key}_works` / `{key}_lessons` / `{key}_courses` отдать фронту nonces +
  actions; admin-бандл общий.
- **Связи:** в `fs_lms_vars.nonces` — `authorWork` / `authorLesson` / `authorCourse`; экшены идут через
  `AjaxHook::toJsArray()`. Гейт по `get_current_screen()->post_type` через
  `PostTypeResolver::isWorkPostType()/isLessonPostType()/isCoursePostType()`.
- **Готово:** на странице банка доступны соответствующий nonce и нужные `ajax_actions`.

##### T1.21 — Admin JS: конструкторы-селекторы банков
- **Файлы (src):** общий движок селектора-чипов + три тонкие обёртки:
  - `src/js/admin/services/ref-selector.js` — переиспользуемая логика (AJAX-кандидаты, чипы,
    drag-drop порядок, синхрон скрытых инпутов; jQuery object-pattern).
  - `work-task-selector.js` (задания в работе), `[rework]` `lesson-work-selector.js` (работы в уроке,
    заменяет `lesson-bucket-service.js`), `course-lesson-selector.js` (уроки в курсе).
  - `src/js/admin/modals/ref-picker.js` — модалка выбора (UI-only, авто-загрузка `modules/ui.js`).
- **Связи:** читают `fs_lms_vars.ajax_actions.{getWorkTaskCandidates|getLessonWorkCandidates|
  getCourseLessonCandidates}` + соответствующий nonce; init в `admin.js` с guard по контейнеру.
  Импорт `_types.js`. **Никакого inline-JS.**
- **Готово:** в каждом банке: поиск → чип → drag-drop порядок → сохранить → значения переживают reload.

##### T1.22 — Admin SCSS: вёрстка селекторов/чипов/модалки
- **Файлы:** `src/scss/admin/components/_ref-selector.scss` (общий) + `[rework]` обобщить
  `_lesson-metabox.scss`. Импорт в `admin.scss`.
- **Связи:** только токены `src/scss/admin/_variables.scss`; нет хардкод-значений и `style=""`.
  Недостающие токены — сперва в `_variables.scss`.
- **Готово:** метабоксы банков консистентны; `npx gulp styles:admin` без ошибок.

---

#### Слой переиспользования (по мотивам Tutor LMS Content Bank)

##### T1.23 — «Создать новое» прямо из конструктора (переиспользование модалок)
- **Файлы:** `inc/Callbacks/Task/TaskCreationCallbacks.php` (доработка ответа по контексту),
  модалки создания работы/урока (по образцу `task-modal.php`), JS-склейка (T1.21).
- **Назначение:** путь «(б) создать новое» на каждом уровне: задание из работы, работа из урока,
  урок из курса — не уходя с конструктора; сразу вернуть `id` для чипа.
- **Связи:**
  - Задание из работы: переиспользует существующую модалку + `TaskManager::createNewTask()`;
    `ajaxCreateTask()` — режим «вернуть id без редиректа» (флаг `context=work`) →
    `success(['id'=>..., 'number'=>..., 'title'=>...])`; модалка предзаполняется `subject_key`.
  - Работа из урока / урок из курса: лёгкие модалки «название + (для работы) тип» →
    `WorkManager::create`/`LessonManager::create` (черновик) → `success(id, title)`; полноценный
    авторинг — на экране банка.
  - JS ловит ответ → чип в активный селектор.
- **Готово:** из любого конструктора создаётся реальный пост в банке и прикрепляется ссылкой без перезагрузки.

##### T1.24 — «Коллекции»: тематическая таксономия заданий + фильтр в селекторе работы
- **Файлы:** конфиг таксономий (`TaxonomyRepository`/`SubjectTaxonomyRegistrar` — **уже есть**),
  `WorkAuthoringService::getCollections()`, JS-фильтр селектора заданий (T1.21).
- **Назначение:** тематические наборы заданий («Циклы Python», «Рекурсия») поверх номеров — как
  Collections в Tutor. Препод тегает задания, **селектор заданий в работе** фильтрует по коллекции.
- **Связи:** коллекция = терм пользовательской таксономии на `{key}_tasks` — новый слой не нужен;
  `GetWorkTaskCandidates` принимает `collection` (T1.4), сервис добавляет WHERE по терму.
- **Готово:** в селекторе работы есть фильтр «коллекция»; выбор сужает список кандидатов.

##### T1.25 — Шаблон задания «условие + ответ + решение»
- **Файлы:** `inc/MetaBoxes/Templates/...` (+ `inc/Enums/TaskTemplate.php`), либо переиспользование
  `CodeTaskTemplate` / `TaskTextSolution`.
- **Назначение:** под «задачка на циклы Python» нужно поле **решения** помимо условия и ответа
  (`StandardTaskTemplate` его не имеет).
- **Связи:** встаёт в существующую систему (`TaskTemplate` enum + `TemplateRegistry`, фильтр
  `fs_lms_register_templates`) — живёт на стороне заданий, работа/урок его не знают.
- **Готово:** при «создать новое» из работы доступен тип с полями условие/ответ/решение.

##### T1.26 — `ContentUsageService` (usage read-model) + бейдж «используется в N»
- **Файл:** `inc/Services/Course/ContentUsageService.php` + бейдж в списках банков.
- **Назначение:** единый источник «кто на меня ссылается» — питает и **бейдж** (UX), и **гейт удаления**
  (T1.28). Поэтому сервис — **core Этапа 1** (не опциональный); UI-бейдж может дозревать.
- **Связи (источники ссылок, расширяются по этапам, архитектура как `GradeSourceInterface`):**
  - задание ← `work.task_ids` (+ Этап 3: `submissions.task_id`, Этап 4: `assessment.tasks`);
  - работа ← `lesson.work_ids` (+ Этап 2: `group_lessons.work_ids_snapshot`/`extra_work_ids`, Этап 3: `submissions.work_id`);
  - урок ← `course.lesson_ids` (+ Этап 2: `group_lessons.lesson_id`);
  - курс ← `groups.course_id` (Этап 2).
- **Методы:**
  ```php
  public function usageCount( string $type, int $postId ): int;   // 0 = orphan (удаляемо)
  public function usageList( string $type, int $postId ): array;  // потребители (для бейджа/диалога)
  ```
- **Готово:** у сущности банка виден счётчик; `usageCount()` доступен гейту удаления (T1.28). Полный охват
  delivery-источников дозревает с Этапами 2–4 (T2.25), контракт сервиса стабилен с Этапа 1.

##### T1.27 — Жизненный цикл контента: `draft` / `publish` / `archived`
- **Файлы:** `inc/Services/Course/ContentLifecycleService.php`; регистрация статуса `fs_archived`
  (`register_post_status`) для всех 4 банк-CPT (в `SubjectController`/`Registrar`).
- **Назначение:** ретайр контента без удаления. **Инвариант:** ссылка резолвится, **пока существует
  пост**; статус влияет только на видимость в **селекторах**, ссылок не рвёт и доставленное не трогает.
- **Состояния:**
  - `draft` (нативный) — черновик; в т.ч. результат «создать новое» из конструктора (T1.23).
  - `publish` (нативный) — активен, предлагается в селекторах.
  - `fs_archived` (кастомный) — убран из селекторов для **новых** ссылок; существующие ссылки и
    snapshot-доставка резолвятся; возвращается в `publish`.
- **Связи:** селекторы кандидатов (T1.14/T1.16/T1.18) фильтруют `post_status=publish` (опц. тоггл
  «показать архивные»); `usageList` (T1.26) показывает потребителей независимо от статуса.
- **Методы:** `archive( int $postId ): void`, `unarchive( int $postId ): void`.
- **Готово:** архивный элемент исчезает из селекторов, но работает во всех существующих ссылках и в
  доставленных группах; возврат в `publish` работает.

##### T1.28 — Гейт удаления: нет физического удаления при зависимостях
- **Файл:** `inc/Controllers/ContentDeletionGuard.php` (`ServiceInterface`) — хуки на удаление банк-CPT.
- **Назначение:** запретить trash/force-delete контента с `usage > 0` ([`Courses.md` §0 №16](./Courses.md));
  направить препода в «Архив».
- **Связи / хуки:**
  - `pre_trash_post` / `pre_delete_post` → если `is{Work|Lesson|Course}PostType`/`isTaskPostType` и
    `ContentUsageService::usageCount > 0` → вернуть `false` (блок) + admin-notice «используется в N: …».
    Жёсткая гарантия целостности (в т.ч. при удалении по прямому URL).
  - `post_row_actions` / `bulk_actions` на нативных списках банков (T1.10) → подменить «Удалить» на
    «В архив» (`ContentLifecycleService::archive`) для референсного контента.
  - orphan (`usageCount == 0`) → нативный WP trash/delete без помех.
- **Готово:** референсное задание/работу/урок/курс нельзя удалить (только архив); orphan удаляется штатно;
  удаление референсного по прямому URL блокируется фильтром.

##### T1.29 — CPT `fs_lms_problems`: глобальный банк приватных задач
- **Файл:** `inc/Controllers/ProblemsController.php` + `PostTypeResolver` helpers.
- **Назначение:** глобальный (не per-subject) банк задач, которые можно добавить в работу любого предмета.
  Не публикуются на фронте ([`Courses.md` §0 №19](./Courses.md)). Примеры: «Создайте профиль в GitHub»,
  «Абстрактная задача без привязки к заданию ЕГЭ».
- **CPT:**
  - Имя: `fs_lms_problems` (единственный, без per-subject prefix).
  - `show_in_menu => false`, `exclude_from_search => true`, `show_in_rest => false`.
  - `capability_type => 'fs_lms_content'`, `map_meta_cap => true`.
  - `post_title` = формулировка задачи, `post_content` = условие/инструкция.
- **Таксономия `problem_tag`** — свободная (аналог WP-тегов, не иерархическая). Термы: «Git», «Сортировка»,
  «Python-базовые» и т.п. Несколько тегов на задачу. Используется как фильтр в селекторе работы (аналог
  коллекций T1.24). Предмет к задаче не привязывается — он известен из работ-потребителей.
- **Usage-бейдж:** колонка «Используется в N работах» через `ContentUsageService` — ссылки на работы.
- **`PostTypeResolver` helpers:**
  - `PostTypeResolver::problems()` → `'fs_lms_problems'`
  - `PostTypeResolver::isProblemPostType( string $pt ): bool`
- **Меню:** добавить пункт «Задачи» в меню «Обучение» (рядом с Заданиями предметов), `LearningMenuController`.
- **Шаблоны редактора:** зарегистрировать `fs_lms_problems` как поддерживаемый post type в `TemplateRegistry`.
  Шаблон хранится в `PostMetaName::TemplateType` — та же мета-ключ, что у `{key}_tasks`. Все существующие
  шаблоны (файл, код, ответ) доступны без изменений. Будущие шаблоны-тесты (чекбоксы/радио) — новый класс
  в `inc/MetaBoxes/Templates/`, регистрируется в реестре; `fs_lms_problems` подхватывает автоматически.
  Выбор шаблона — метабокс на экране редактирования (не в `DraftCreatorModal`).
- **Готово:** CPT зарегистрирован; препод создаёт задачу в «Задачи» с тегами и шаблоном (файл/код/ответ);
  задача не видна на фронте; usage-бейдж показывает N работ.

##### T1.30 — `WorkDTO.task_ids` → `item_ids`: единый список элементов работы
- **Файлы:** `WorkDTO`, `WorkManager`, `inc/MetaBoxes/Templates/WorkTemplate.php`, `WorkRefField`,
  `WorkAuthoringService`, `ContentUsageService`.
- **Суть:** `task_ids: int[]` → `item_ids: int[]` — упорядоченный список WP post ID; каждый ID может
  указывать на `{key}_tasks` или `fs_lms_problems` ([`Courses.md` §0 №20](./Courses.md)).
- **Изменения:**
  - `WorkDTO`: поле `task_ids` переименовать в `item_ids`; `fromArray()` / `toArray()` обновить.
  - `WorkManager::create/update`: ключ мета `task_ids` → `item_ids`.
  - `WorkTemplate` / `WorkRefField`: `data-ref-type` расширить — поле теперь принимает и задания, и задачи
    (см. T1.31); `input[name]` атрибут обновить с `task_ids[]` → `item_ids[]`.
  - `ContentUsageService`: при подсчёте usage проверять `get_post_type($id)` — задачи (`fs_lms_problems`)
    учитывать отдельно от заданий (`{key}_tasks`).
- **Миграция:** только dev-окружение; сбросить `fs_lms_schema_version` → `0.0.0`, перезагрузить.
- **Готово:** существующие работы читают `item_ids`; `WorkDTO` не содержит `task_ids`; тесты зелёные.

##### T1.31 — Селектор работы: поддержка задач (`fs_lms_problems`) рядом с заданиями
- **Файлы:** `inc/Callbacks/Course/WorkCallbacks.php`, `AjaxHook`, `ref-selector.js`.
- **Суть:** конструктор работы должен позволять добавлять как публичные задания `{key}_tasks`, так и
  приватные задачи `fs_lms_problems` из единого поля `item_ids` ([`Courses.md` §0 №18, №20](./Courses.md)).
- **Поиск кандидатов:** новый AJAX-хук `GetWorkItemCandidates` (заменяет/дополняет `GetWorkTaskCandidates`):
  ищет по `subject_key` в `{key}_tasks` + по всем `fs_lms_problems`, объединяет результат, возвращает
  `[{id, title, type: 'task'|'problem'}]`. Фильтр «коллекция» (T1.24) применяется только к типу `task`.
- **JS:** `ref-selector.js` → в `REF_MAP` тип `item` (или объединить с `task`); в дропдауне отображать
  тип визуально (бейдж «Задание» / «Задача»); `_addChip` записывает `post_type` как data-атрибут чипа
  (пригодится Этапу 3 для рендера на фронте).
- **«Создать»:** кнопка «Создать задание» → прежний flow (task-modal); добавить вторую кнопку «Создать задачу»
  → открывает `DraftCreatorModal` с `refType='problem'` + новый `ajaxCreateProblemDraft`.
- **Готово:** в конструкторе работы можно добавить и `{key}_tasks`, и `fs_lms_problems`; тип видно
  по бейджу в чипе; сохранение пишет смешанный `item_ids[]`.

---

### Порядок реализации (по зависимостям)

Снизу вверх по цепочке (`tasks` уже есть): сначала **работы**, потом **уроки** (rework), потом **курсы**;
меню можно поднять рано (оно лишь оборачивает экраны CPT).

1. **T1.1–T1.5** фундамент: `PostTypeResolver` (works/courses), `WorkType`, `Nonce`, `AjaxHook`, cap-решение.
2. **T1.6 + T1.7** регистрация CPT works/courses + скрытие банков из top-level + права → редактируемые CPT.
3. **T1.8–T1.10** меню «Обучение» + `TeacherSubjectsService` → единая точка входа (можно параллельно с 2).
4. **T1.11–T1.14** банк **работ**: поля → шаблон/метабокс → DTO/Manager → Service/Callbacks/Controller +
   часть T1.20–T1.22 (Enqueue/JS/SCSS) → работа собирается из заданий (**треть DoD**).
5. **T1.25 → T1.23 (задание)** шаблон с решением → «создать задание» из работы.
6. **T1.24** коллекции-фильтр в селекторе работы.
7. **T1.15–T1.16** `[rework]` банк **уроков** под `work_ids` → урок собирается из работ (**вторая треть DoD**).
8. **T1.17–T1.18** банк **курсов** → курс собирается из уроков (**третья треть DoD**).
9. **T1.19** регистрация сервисов — по мере появления контроллеров.
10. **T1.26–T1.28** `ContentUsageService` (core) → жизненный цикл (`draft`/`publish`/`archived`) → гейт
    удаления. Usage/бейдж — частично сейчас (банк-меты), полный охват delivery-источников — **после Этапа 2** (T2.25).
11. **T1.29** CPT `fs_lms_problems` + `PostTypeResolver` helpers + пункт меню «Задачи».
12. **T1.30** переименование `task_ids` → `item_ids` в WorkDTO/Manager/Template + `ContentUsageService`.
13. **T1.31** расширение селектора работы: единый поиск заданий + задач, бейдж типа, кнопка «Создать задачу».

> Контрольные точки DoD: после шага 4 работает банк работ; после 7 — уроки из работ; после 8 — курсы из уроков.
> После шага 13 работа принимает как задания (`{key}_tasks`), так и задачи (`fs_lms_problems`) из единого поля.
> Меню (шаг 3) делает всё это доступным под предмет препода.

### Критерии приёмки этапа (проверка вручную)

- [ ] Для каждого предмета зарегистрированы CPT `{key}_works`, `{key}_lessons`, `{key}_courses`.
- [ ] В wp-admin есть меню «Обучение» → Курсы/Уроки/Работы/Задания/Статьи; tasks/articles исчезли из top-level.
- [ ] Русист по умолчанию видит вкладку своего предмета; питонист — своего; админ — все (мягкий скоуп).
- [ ] Преподаватель (`FSTeacher`, `manage_lms_assignments`) создаёт/редактирует работу, урок, курс.
- [ ] **Работа:** тип + задания из библиотеки через селектор; «создать новое» создаёт реальный `{key}_tasks`
      и прикрепляет ссылкой без перезагрузки; порядок задач переживает reload.
- [ ] **Урок:** ссылается на работы (упорядоченно); сохраняется и без работ (просто занятие); опц.
      теория-ссылка на статью (`theory_article_id`).
- [ ] **Курс:** ссылается на уроки (упорядоченно, drag-drop).
- [ ] Прикрепление — ссылка: правка задания/работы/урока отражается во всех потребителях.
- [ ] Селекторы по умолчанию показывают «мои» (`post_author`), переключаются на весь банк предмета;
      для заданий — фильтр «коллекция» (если включён T1.24).
- [ ] Референсный контент (задание в работе, работа в уроке, урок в курсе) **нельзя удалить** — вместо
      «Удалить» предлагается «В архив»; удаление по прямому URL блокируется (`pre_trash_post`/`pre_delete_post`).
- [ ] Orphan-контент (`usage = 0`) удаляется штатно (trash → delete).
- [ ] `archived`-элемент исчезает из селекторов, но продолжает резолвиться в существующих ссылках.
- [ ] Данные лежат в `post_title`/`post_content`/`fs_lms_meta` — таблиц на этом этапе не добавлено.
- [ ] `npm run lint:js` и `npx gulp build` проходят.

### Тесты этапа 1 (PHPUnit)

Конвенция (как в существующих `tests/`): PHPUnit 12, `tests/Unit/...` (изолированные сервисы, зависимости —
моки; WP-функции застабаны в `tests/bootstrap.php`), `tests/Integration/...` (репозитории через
`tests/Support/FakeWpdb.php`: `queueVar`/`queueResults` + `lastQuery()`). Запуск:
`vendor/bin/phpunit --testsuite Unit|Integration`; имена методов — `test_...`. Действует и для Этапов 2–3.

**Unit (`tests/Unit/`):**
- `Services/PostTypeResolverTest` (расширить) — `works()`/`courses()`, `isWork/isCoursePostType`,
  `subjectFromWork/CoursePostType` (round-trip).
- `Services/Course/WorkAuthoringServiceTest` — `getTaskCandidates` отдаёт **только задания своего предмета**;
  `scope='mine'` фильтрует по `post_author`; `validateTaskIds` отбрасывает чужие/несуществующие.
- `Services/Course/LessonAuthoringServiceTest` — `getWorkCandidates` (свой предмет, фильтр по типу),
  `validateWorkIds` отбрасывает чужие.
- `Services/Course/CourseAuthoringServiceTest` — `getLessonCandidates`, `validateLessonIds`.
- `Services/Course/TeacherSubjectsServiceTest` — препод → его предметы (через `groups.teacher_id`),
  Admin → все, `defaultSubjectKey`.
- `Services/Course/ContentUsageServiceTest` — `usageCount` суммирует источники (`work.task_ids`→задание,
  `lesson.work_ids`→работа, `course.lesson_ids`→урок); orphan = 0; `usageList` отдаёт потребителей.
- `Services/Course/ContentLifecycleServiceTest` — `archive/unarchive` меняют статус; `fs_archived` вне
  кандидатов селектора, но резолвится в существующих ссылках.
- `DTO/Course/WorkDTOTest` + `LessonDTOTest` + `CourseDTOTest` — `fromPost()→toArray()` стабилен; форма meta
  (`task_ids`/`work_ids`/`lesson_ids`); `isEmpty()`; **урок хранит `work_ids`, не `task_ids`**.
- `MetaBoxes/Fields/*` sanitize — `TaskRefField`/`WorkRefField`/`LessonRefField` → `int[]` (фильтр пустых,
  порядок); `WorkTypeField` → валидный `WorkType`.

**Integration (`tests/Integration/`, `FakeWpdb`):**
- `Controllers/ContentDeletionGuardTest` — `pre_trash_post`/`pre_delete_post` → `false` при `usageCount>0`
  (мок `ContentUsageService`) и пропуск при `0`.
- `Managers/WorkManagerTest` (+ Lesson/Course) — `create()` пишет `post_type=works($key)`, статус `draft`,
  meta через `MetaBoxManager`; `get()` round-trip; `getBankBySubject()` скоупит по предмету и `status=publish`.

**Готово:** оба сьюта зелёные; покрыты инварианты — изоляция кандидатов по предмету, `validate*Ids`,
урок→работы, archived вне селектора, **запрет удаления при usage>0**.

### Открытые вопросы этапа

**Решено в этой итерации** (см. «Решения и допущения»): работа типизированная; урок ссылается на
работы, не на задачи; курс — банк-шаблон; меню «Обучение» с мягким скоупом; всё по ссылке.
`[rework]` урочного кода Этапа 1 учтён в T1.15–T1.16, T1.21–T1.22.

1. **Авторинг — wp-admin (через «Обучение») или фронт?** Принято: wp-admin (консистентно с
   tasks/articles). Если преподаватель не должен видеть wp-admin — фронтовый авторинг выносится
   отдельной задачей (вне Этапа 1). Связано с [`Courses.md` §4](./Courses.md).
2. **`map_meta_cap` для `fs_lms_content`.** Проверить на dev, что мета-права works/courses сводятся к
   `manage_lms_assignments` у `FSTeacher` (lessons уже проверены); иначе — явный маппинг в `RoleManager` (T1.7).
3. **Запрос списков из поля метабокса.** Поля не зовут WP API напрямую (правило слоёв): кандидаты
   прокидываются из `*AuthoringService` через шаблон/конструктор поля. Финализировать при T1.11/T1.15/T1.17.
4. **Список банка в меню — нативный `edit.php` vs кастомный `WP_List_Table`.** Принято: ссылка на
   нативный экран (бесплатные редактор/поиск/bulk). Кастомная таблица — позже, если понадобится
   сводный кросс-предметный вид.
5. **Инструкции/настройки работы.** `instructions` — сейчас; `settings` (`max_score`, дефолтный
   дедлайн-офсет) — задел под Этап 3, наполняется там.

---

## Этап 2 — Программа группы: назначение курса, расписание, доставка, кокпит

### Цель этапа

Назначить группе **курс** (снапшот списка уроков, Этап 1), управлять программой/доступом во времени
и дать преподавателю **фронт-страницу группы (кокпит)**. `fs_lms_group_lessons` заменяет текстовое
`groups.schedule`; `groups.course_id` хранит назначенный курс-шаблон.

### Готово, когда (Definition of Done)

- Преподаватель **назначает группе курс** из банка предмета → его уроки снапшотятся в программу;
  далее правит программу независимо от шаблона. Может и собирать программу вручную (добавить урок из банка).
- Задаёт **порядок** (drag-drop), **дату занятия**, **преподавателя занятия**; усиливает урок для
  группы доп. работой (**`extra_work_ids`** — дельта, не правка общего урока).
- Управляет **видимостью** урока: `hidden` / `open` / `archived`.
- Ученик-член группы видит материалы **видимого** урока (`open`/`archived`), включая бэк-каталог;
  эффективные работы = `lesson.work_ids + extra_work_ids`; `hidden` недоступен. Отчисленный по
  умолчанию сохраняет read-only доступ к пройденному.
- Действия (назначение курса, добавление в программу, расписание, публикация) пишутся в **ленту
  активности группы**; ученик/родитель видят отфильтрованный срез (свои события + публикации).

### Зависимости

- **Этап 1** (CPT `{key}_lessons` + `{key}_courses`, `{key}_works`; `LessonManager`, `CourseManager`,
  `WorkManager`, DTO) — программа ссылается на уроки; назначение — на курс; дельта — на работы.
- Существующее переиспользуем без изменений: `GroupsRepository` (гейт `teacher_id`),
  `StudentRecordRepository` (`findActiveByGroupId`, `findActiveByStudent`, `findActiveByParent`),
  лог-инфраструктура (`LogEventDispatcher` → subscriber → writer → repo), `ThemeCompatService`,
  `PageGeneratorService`, `LogNameResolver`.
- **Этап 0** (роле-кабинеты) НЕ требуется: кокпит самодостаточен (без `gid` показывает список
  групп преподавателя). Срез ленты ученику — минимальный read-only вывод; полноценный кабинет — Этап 0/3.

### Доменная модель

Две новые факт-таблицы (контент остаётся в CPT уроков Этапа 1). DDL в стиле `Migration_1_0_0`
(`dbDelta` + `TableName::X->prefixed()`):

```sql
-- Программа группы = снапшот курса + расписание + доставка (заменяет groups.schedule text).
-- + колонка fs_lms_groups.course_id (назначенный курс-шаблон; провенанс / «сбросить к шаблону»).
CREATE TABLE {prefix}fs_lms_group_lessons (
  id                 int unsigned        NOT NULL AUTO_INCREMENT,
  group_id           smallint unsigned   NOT NULL,            -- → fs_lms_groups.id
  lesson_id          bigint unsigned     NOT NULL,            -- → CPT {key}_lessons (post ID); снапшот списка из курса
  position           smallint unsigned   NOT NULL DEFAULT 0,
  work_ids_snapshot  longtext            DEFAULT NULL,        -- JSON: заморозка lesson.work_ids при публикации (NULL = не открыт → живой урок)
  extra_work_ids     longtext            DEFAULT NULL,        -- JSON: доп. работы ТОЛЬКО для группы (дельта усиления)
  scheduled_at       datetime            DEFAULT NULL,
  teacher_user_id    bigint(20) unsigned DEFAULT NULL,        -- кто вёл занятие (WP user, ≠ groups.teacher_id)
  visibility         enum('hidden','open','archived') NOT NULL DEFAULT 'hidden',
  opened_at          datetime            DEFAULT NULL,
  homework_due_at    datetime            DEFAULT NULL,        -- потребитель: Этап 3 (snapshot → submissions.due_at)
  allow_late         tinyint(1)          NOT NULL DEFAULT 1,  -- потребитель: Этап 3
  recording_url      varchar(1000)       DEFAULT NULL,        -- потребитель: Этап 5
  created_by_user_id bigint(20) unsigned DEFAULT NULL,
  updated_by_user_id bigint(20) unsigned DEFAULT NULL,
  created_at         datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY group_id (group_id),
  KEY lesson_id (lesson_id),               -- для бейджа «используется в N» (T1.26 / T2.25)
  KEY group_position (group_id, position)
);
-- Эффективные работы строки = (opened? work_ids_snapshot : lesson.work_ids) + extra_work_ids.
-- work_ids_snapshot заполняется при первой публикации (open) — copy-on-publish (решение 8 ниже).

-- Единая append-only лента доменных событий обучения (один канал на всё). НЕ источник баллов (решение 9).
-- Фид группы / таймлайн ученика / аналитика — запросы по этой таблице.
CREATE TABLE {prefix}fs_lms_learning_events (
  id            int unsigned        NOT NULL AUTO_INCREMENT,
  subject_key   varchar(50)         DEFAULT NULL,    -- предмет (кросс-предметная аналитика); NULL = вне предмета
  group_id      smallint unsigned   DEFAULT NULL,    -- NULL = событие вне группы (правка банка, курс-шаблон)
  actor_user_id bigint(20) unsigned DEFAULT NULL,
  actor_role    varchar(50)         DEFAULT NULL,
  action        varchar(40)         NOT NULL,    -- course_assigned | lesson_added_to_program | lesson_removed | schedule_changed | lesson_published | lesson_hidden | recording_attached(Э5) | submission_*(Э3) | attempt_*(Э4)
  entity_type   varchar(30)         DEFAULT NULL,
  entity_id     varchar(100)        DEFAULT NULL,
  is_public     tinyint(1)          NOT NULL DEFAULT 1,  -- виден ли срез ученику/родителю
  created_at    datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY group_created (group_id, created_at),
  KEY subject_created (subject_key, created_at),
  KEY actor_user_id (actor_user_id)
);
```

### Решения и допущения этапа

1. **Программа = факт-таблица `fs_lms_group_lessons`**, заменяет `groups.schedule` text. Миграция
   старого текста не нужна — начинаем с чистого расписания ([`Courses.md` §7 #3](./Courses.md)).
   `groups.schedule` пока **оставляем** (не дропаем) до подтверждения, что нигде не читается.
2. **Назначение курса группе = снапшот** ([`Courses.md` §7 #15](./Courses.md)): `CourseAssignmentService`
   (T2.28) bulk-инсертит строки `group_lessons` по `course.lesson_ids`; `groups.course_id` фиксирует
   шаблон. Дальше программа группы **независима** (правка курса-шаблона не дёргает живые группы).
   M:N урок↔группа: один урок-определение стоит в программах многих групп; дата/видимость/преподаватель
   занятия / `extra_work_ids` — на строке `group_lesson`, не на уроке.
3. **Кокпит — фронт-страница** (`template_redirect` + `ThemeCompatService::header()/footer()`),
   гейт `groups.teacher_id == current_user_id` **ИЛИ** `Capability::Admin`. Тяжёлый CRUD (создание
   групп, зачисление, PII) остаётся в админке ([`Courses.md` §4](./Courses.md)).
4. **Доступ ученика = членство (`student_record`), а не «active + open».** Зачисление — грант доступа
   к материалам группы; единый `LessonAccessPolicy` (T2.26) учитывает статус + даты + политику
   ретеншена. Чтение переживает отчисление (`retain`, дефолт); сдача — только пока `active`. Гейт
   доменный, **не** по роли/capability. Детали — [`Courses.md` §4](./Courses.md).
5. **Лента — новый лог-канал поверх существующей инфраструктуры** (`LogEventDispatcher` →
   `LearningEventSubscriber` → `LearningEventWriter` → `LearningEventRepository`), один
   канал, разрез по `group_id` ([`Courses.md` §7 #8](./Courses.md)). Срез ученику — `is_public=1` +
   свои события. Имена акторов резолвит `LogNameResolver`.
6. **`homework_due_at` / `allow_late` / `recording_url`** создаются в DDL сейчас, но потребляются
   позже (Этап 3 — дедлайны ДЗ; Этап 5 — записи). На Этапе 2 в UI — только дата занятия и видимость.
7. **Усиление группы = `extra_work_ids` на строке** ([`Courses.md` §7 #16](./Courses.md)): доп. работы
   только для этой группы (JSON-массив `work_id`). Крупное расхождение программы — форк урока на стороне
   банка (Этап 1).
8. **Содержимое урока — copy-on-publish** ([`Courses.md` §0 №14, §7 #18](./Courses.md)): пока строка
   `hidden`, `work_ids` живые (правки эталона долетают); при `open` снапшотятся в
   `group_lessons.work_ids_snapshot` (рядом с `opened_at`, T2.13) и дальше для группы неизменны.
   `EffectiveWorksResolver` (T2.29) даёт единую базу для UI ученика, кокпита и сдач (Этап 3):
   `(opened ? snapshot : lesson.work_ids) + extra_work_ids`. Теория — живая. «Подтянуть новую версию» —
   отдельное опц. действие (`refreshFromLesson`).
9. **`fs_lms_learning_events` — единая лента событий, НЕ источник баллов** ([`Courses.md` §0 №15,
   §7 #19](./Courses.md)). Один append-only канал на всё (повышен из «ленты группы»; `group_id`/`subject_key`
   nullable): фид группы = `WHERE group_id=X`, таймлайн ученика, аналитика — запросы по нему. Текущие
   баллы — fact-таблицы (`submissions`/`attempts`) за gradebook read-моделью; security/PII — отдельный
   `fs_lms_audit_log`. Каждый мутирующий сервис дополнительно эмитит событие через `LogEventDispatcher`.

---

### Задачи

#### Слой Enums / фундамент

##### T2.1 — `TableName`: +2 таблицы
- **Файл:** `inc/Enums/TableName.php`.
- **Добавить:** `case GroupLessons = 'fs_lms_group_lessons';`, `case LearningEvents = 'fs_lms_learning_events';`
- **Связи:** используются репозиториями (T2.8–T2.9), миграцией (T2.6–T2.7), `LogChannel` (T2.5).
- **Готово:** `TableName::GroupLessons->prefixed()` отдаёт имя с префиксом.

##### T2.2 — `Nonce`: программа + видимость + назначение курса
- **Файл:** `inc/Enums/Nonce.php`.
- **Добавить:** `case AssignCourse = 'fs_lms_assign_course';`, `case SaveSchedule = 'fs_lms_save_schedule';`
  (add/remove/reorder/дата/extra_work_ids), `case SetLessonVisibility = 'fs_lms_set_lesson_visibility';`
- **Связи:** `ProgramCallbacks` (T2.19) — `authorize(Nonce::SaveSchedule, Capability::ManageLMSAssignments)`;
  назначение курса — `authorize(Nonce::AssignCourse, …)`. Локализация в `Enqueue` для фронта кокпита.
- **Готово:** nonces создаются/проверяются.

##### T2.3 — `AjaxHook`: операции программы
- **Файл:** `inc/Enums/AjaxHook.php` (секция `// ==== Программа группы ====`).
- **Добавить:**
  ```php
  case AssignCourse            = 'assign_course';        // снапшот курса в программу группы
  case AddLessonToProgram      = 'add_lesson_to_program';
  case RemoveLessonFromProgram = 'remove_lesson_from_program';
  case ReorderProgram          = 'reorder_program';      // массив id в новом порядке
  case SaveLessonSchedule      = 'save_lesson_schedule'; // scheduled_at + teacher_user_id
  case SetLessonExtraWorks     = 'set_lesson_extra_works'; // extra_work_ids строки программы
  case SetLessonVisibility     = 'set_lesson_visibility';
  case GetGroupProgram         = 'get_group_program';    // программа группы для рендера/обновления
  case GetGroupActivity        = 'get_group_activity';   // лента с пагинацией
  ```
- **Связи:** регистрируются в `ScheduleController::ajaxActions()` (T2.17); JS читает из `fs_lms_vars`/кокпит-vars.
- **Готово:** хуки в `AjaxHook::toJsArray()`.

##### T2.4 — `PageRoutes` + `ShortCode`: страница кокпита
- **Файл:** `inc/Enums/PageRoutes.php` (+ `ShortCode.php`, если контент через шорткод).
- **Добавить:** `case GroupCockpit = 'group';` Параметр группы — query-arg `?gid=N` (страница одна,
  ресурс параметризован), гейт по `gid` + teacher.
- **Связи:** `PageGeneratorService` создаёт страницу (как `profile`/`apply`); `GroupCockpitController`
  (T2.18) ловит `PageRoutes::GroupCockpit->isCurrent()`.
- **Готово:** `/group/?gid=5` резолвится; `isCurrent()` true на странице.

##### T2.5 — `LogChannel` + `LogEvent`: канал событий обучения
- **Файлы:** `inc/Enums/LogChannel.php`, `inc/Enums/LogEvent.php`.
- **Добавить:**
  - `LogChannel::LearningEvents` → `label()` «События обучения», `tableName()` → `TableName::LearningEvents`.
  - `LogEvent`: `CourseAssigned`, `LessonAddedToProgram`, `LessonRemovedFromProgram`, `ScheduleChanged`,
    `ExtraWorksChanged`, `LessonPublished`, `LessonHidden` (на будущее — `RecordingAttached`, `SubmissionMade`…).
- **Связи:** диспетчеризуются из `ScheduleService`/`LessonVisibilityService`; ловит `LearningEventSubscriber`.
- **Готово:** enum-кейсы доступны; канал виден инфраструктуре логов.

---

#### Слой Migrations

##### T2.6 / T2.7 — Таблицы `group_lessons` и `learning_events`
- **Файл:** `inc/Migrations/Migration_1_0_0.php` — блоки `dbDelta(...)` в `up()` + строки в `down()`
  (по правилу `CLAUDE.md`: **не** отдельный файл миграции).
- **Назначение:** развернуть DDL из «Доменной модели».
- **Связи:** имена через `TableName` (T2.1).
- **Внедрение (dev):** сбросить `fs_lms_schema_version` в `0.0.0` и перезагрузить страницу WP
  (CLAUDE.md → «Миграции в dev-окружении»).
- **Готово:** обе таблицы созданы с индексами; `down()` их дропает.

---

#### Слой Repositories

##### T2.8 — `GroupLessonRepository`
- **Файл:** `inc/Repositories/WPDBRepositories/GroupLessonRepository.php` (по образцу `GroupsRepository`).
- **Назначение:** CRUD строк программы + упорядоченные выборки + перестановка + видимость.
- **Связи:** `\wpdb` + `TableName::GroupLessons`; принимает/отдаёт `GroupLessonDTO`/`GroupLessonInputDTO` (T2.10).
- **Методы:**
  ```php
  public function listByGroup( int $groupId ): array;                 // ORDER BY position ASC → GroupLessonDTO[]
  public function listOpenByGroup( int $groupId ): array;             // visibility='open' (для ученика)
  public function find( int $id ): ?GroupLessonDTO;
  public function add( GroupLessonInputDTO $dto ): int;               // position = nextPosition()
  public function nextPosition( int $groupId ): int;                  // MAX(position)+1
  public function reorder( int $groupId, array $orderedIds ): void;   // bulk update position
  public function updateSchedule( int $id, ?string $scheduledAt, ?int $teacherUserId ): bool;
  public function setVisibility( int $id, string $visibility, ?string $openedAt ): bool;
  public function remove( int $id ): bool;
  public function countUsageByLesson( int $lessonId ): int;           // для бейджа T2.25
  public function deleteAllByGroup( int $groupId ): int;              // каскад при удалении группы
  ```
- **Готово:** программа группы читается по порядку; reorder/visibility/schedule пишутся точечно.

##### T2.9 — `Log/LearningEventRepository`
- **Файл:** `inc/Repositories/WPDBRepositories/Log/LearningEventRepository.php` (по образцу `Log/AuditLogRepository`).
- **Назначение:** запись и чтение ленты событий обучения; неизменяемый журнал (`update()` бросает).
- **Связи:** `TableName::LearningEvents`; `LearningEventDTO`/`InputDTO` (T2.11).
- **Методы:**
  ```php
  public function create( LearningEventInputDTO $dto ): int;
  public function listByGroup( int $groupId, int $page, int $perPage ): array;            // фид кокпита: WHERE group_id
  public function listByGroupPublic( int $groupId, int $actorUserId, int $page, int $perPage ): array; // срез ученика: is_public OR actor=self
  public function listByActor( int $actorUserId, int $page, int $perPage ): array;        // таймлайн ученика/препода
  public function countByGroup( int $groupId ): int;
  ```
- **Готово:** фид группы и таймлайн актора листаются с пагинацией; срез ученика отдаёт публичные + свои события.

---

#### Слой DTO

##### T2.10 — `GroupLessonDTO` + `GroupLessonInputDTO`
- **Файл:** `inc/DTO/Course/GroupLessonDTO.php`, `inc/DTO/Course/GroupLessonInputDTO.php` (`readonly`).
- **Поля:** `id`, `groupId`, `lessonId`, `position`, `workIdsSnapshot` (`?int[]`, NULL = не опубликован),
  `extraWorkIds[]`, `scheduledAt`, `teacherUserId`, `visibility`, `openedAt`, `homeworkDueAt`, `allowLate`,
  `recordingUrl`, `createdByUserId`, `updatedByUserId`.
- **Методы:** `fromArray()`, `toArray()` (Input — только пишущие поля); `work_ids_snapshot`/`extra_work_ids` — JSON ↔ `int[]`.
- **Готово:** round-trip стабилен; Input даёт массив для `$wpdb->insert`.

##### T2.11 — `LearningEventDTO` + `InputDTO` + `LearningEvent`
- **Файлы:** `inc/DTO/Log/LearningEventDTO.php`, `inc/DTO/Log/LearningEventInputDTO.php`,
  `inc/DTO/Log/Events/LearningEvent.php` (реализует `LogEventInterface`, как `EntityChangedEvent`).
- **Назначение:** read/write записи ленты + payload события для диспетчера.
- **Поля Event:** `subjectKey`, `groupId` (оба nullable), `actorUserId`, `action`, `entityType`, `entityId`, `isPublic`.
- **Готово:** событие диспетчеризуется; запись пишется/читается.

---

#### Слой Services

##### T2.12 — `ScheduleService`
- **Файл:** `inc/Services/Course/ScheduleService.php`.
- **Назначение:** бизнес-логика сборки программы: добавить урок из банка, убрать, переставить,
  задать дату/преподавателя занятия. Диспетчеризует `LogEvent` после успешной записи.
- **Связи:** `GroupLessonRepository` (T2.8), `LessonManager` (валидность урока/предмета группы),
  `GroupsRepository` (предмет/период группы), `LogEventDispatcherInterface` (события → лента).
- **Методы:**
  ```php
  public function addLesson( int $groupId, int $lessonId, int $actorUserId ): int;
  public function removeLesson( int $groupLessonId, int $actorUserId ): void;
  public function reorder( int $groupId, array $orderedIds, int $actorUserId ): void;
  public function schedule( int $groupLessonId, ?string $scheduledAt, ?int $teacherUserId, int $actorUserId ): void;
  public function getProgram( int $groupId ): array; // list<GroupLessonDTO + резолв темы урока>
  ```
- **Готово:** программа собирается/правится; каждое мутирующее действие пишет событие в ленту;
  урок чужого предмета в группу не добавляется.

##### T2.13 — `LessonVisibilityService`
- **Файл:** `inc/Services/Course/LessonVisibilityService.php`.
- **Назначение:** смена видимости (`hidden`/`open`/`archived`), фиксация `opened_at` **и заморозка
  `work_ids_snapshot`** при первой публикации (copy-on-publish, решение 8); доменный гейт доступа ученика.
- **Связи:** `GroupLessonRepository`, `LessonManager` (текущие `lesson.work_ids` для снапшота),
  `StudentRecordRepository::existsActive()`, `LogEventDispatcherInterface` (`LessonPublished`/`LessonHidden`).
- **Методы:**
  ```php
  public function setVisibility( int $groupLessonId, string $visibility, int $actorUserId ): void;
  // open && work_ids_snapshot IS NULL → snapshot = lesson.work_ids; ставит opened_at. Снапшот «липкий»
  //   (повторный open не перезатирает). Доступ ученика — НЕ здесь: LessonAccessPolicy (T2.26).
  public function refreshFromLesson( int $groupLessonId, int $actorUserId ): void; // опц.: перезаписать снапшот текущим уроком
  ```
- **Готово:** при публикации набор работ замораживается; правка эталона не меняет уже открытый урок группы;
  `refreshFromLesson` осознанно подтягивает новую версию; доступ ученика резолвит `LessonAccessPolicy` (T2.26).

##### T2.14 — `LearningEventWriter`
- **Файл:** `inc/Services/Log/LearningEventWriter.php` (по образцу существующих `*LogWriter`).
- **Назначение:** событие → `LearningEventInputDTO` (резолв `actor_role`) → `repo->create()`.
- **Связи:** `LearningEventRepository`; вызывается из `LearningEventSubscriber`.
- **Готово:** запись ленты создаётся из события.

##### T2.15 — `GroupAccessGuard`
- **Файл:** `inc/Services/Course/GroupAccessGuard.php`.
- **Назначение:** грубый гейт уровня группы: `canManage(groupId, userId)` (`teacher_id == userId || Admin`),
  `isMemberEver(groupId, personId)` (любая запись, не только `active` — для retained-доступа),
  `isParentOf(groupId, personId)`. Тонкий гейт «этот урок, read/submit» — `LessonAccessPolicy` (T2.26).
- **Связи:** `GroupsRepository`, `StudentRecordRepository`. Используется `GroupCockpitController`,
  `ProgramCallbacks`, student-view (T2.21).
- **Готово:** гейт переиспользуется во всех точках входа группы; нет дублирования проверки.

---

#### Слой Subscribers / Controllers / Callbacks

##### T2.16 — `LearningEventSubscriber`
- **Файл:** `inc/Controllers/Subscribers/LearningEventSubscriber.php` (по образцу `EnrollmentAuditSubscriber`).
- **Назначение:** подписать обработчик на `LogEvent::*` программы/видимости → `LearningEventWriter`.
- **Связи:** `LogEventDispatcherInterface`, `LearningEventWriter`. Реализует `ServiceInterface`.
- **Готово:** доменные события группы материализуются в ленту.

##### T2.17 — `ScheduleController` (AJAX программы)
- **Файл:** `inc/Controllers/ScheduleController.php` (`extends AjaxController`).
- **Назначение:** зарегистрировать AJAX-хуки T2.3 (для авторизованных).
- **Связи:** конструктор — `ProgramCallbacks` (T2.19); `ajaxActions()` → пары `[AjaxHook, $callback]`.
- **Готово:** `wp_ajax_*` программы зарегистрированы.

##### T2.18 — `GroupCockpitController` (фронт-страница)
- **Файл:** `inc/Controllers/GroupCockpitController.php` (`ServiceInterface`, `use TemplateRenderer`).
- **Назначение:** отрисовать кокпит группы на фронте.
- **Связи / хуки:**
  - `template_redirect` → если `PageRoutes::GroupCockpit->isCurrent()`: прочитать `gid`,
    `GroupAccessGuard::canManage()`; нет доступа → редирект/403; нет `gid` → список групп
    преподавателя (`GroupsRepository::findByFilters(..., teacherId)`).
  - Полностраничный рендер через `ThemeCompatService::header()` … `footer()` (НЕ `get_header()`).
  - Данные: программа (`ScheduleService::getProgram`), ростер (`StudentRecordRepository::findActiveByGroupId`
    + `LogNameResolver::personName`), лента (`LearningEventRepository::listByGroup` + `LogNameResolver`).
- **Готово:** преподаватель открывает свою группу, видит программу/ростер/ленту; чужую — нет.

##### T2.19 — `Callbacks/Course/ProgramCallbacks`
- **Файл:** `inc/Callbacks/Course/ProgramCallbacks.php` (`use Authorizer; use Sanitizer;`).
- **Назначение:** AJAX-обработчики операций программы и видимости.
- **Связи:** делегирует `CourseAssignmentService` (T2.28) / `ScheduleService` / `LessonVisibilityService` /
  `EffectiveWorksResolver` (T2.29); `GroupAccessGuard` для проверки владения группой (nonce+cap проверяют
  право, guard — принадлежность конкретной группы).
- **Методы:** `ajaxAssignCourse`, `ajaxAddLessonToProgram`, `ajaxRemoveLessonFromProgram`,
  `ajaxReorderProgram`, `ajaxSaveLessonSchedule`, `ajaxSetLessonExtraWorks`, `ajaxSetLessonVisibility`,
  `ajaxGetGroupProgram`, `ajaxGetGroupActivity`.
- **Готово:** все операции проходят nonce+cap+guard; ответы через `success()/error()`.

---

#### Слой Templates / JS / SCSS / Student-surface / Init

##### T2.20 — Шаблон кокпита (фронт)
- **Файлы:** `templates/frontend/group-cockpit/*.php` (program-builder, schedule, roster, activity-feed).
- **Назначение:** вёрстка кокпита; экранирование вывода (`esc_html`/`esc_attr`).
- **Связи:** данные из `GroupCockpitController`; никакого inline JS/CSS.
- **Готово:** панели рендерятся с реальными данными группы.

##### T2.21 — Срез открытых уроков ученику/родителю
- **Файлы:** минимальный read-only вывод (шорткод `[fs_lms_group_lessons]` или вкладка в `profile`).
- **Назначение:** ученик видит открытые уроки своих групп (тема, теория, материалы),
  родитель — read-only по детям. Срез ленты — `listByGroupPublic`.
- **Связи:** `LessonAccessPolicy::visibleLessonsForStudent` (T2.26 — бэк-каталог + ретеншн, не только
  `active`), `StudentRecordRepository` (все группы ученика / детей родителя), `LessonViewDTO` (резолв
  работ через `EffectiveWorksResolver` T2.29 — урок + дельта группы).
- **Готово:** ученик видит видимые уроки своих групп (вкл. бэк-каталог и архив после отчисления по
  политике `retain`); `hidden` недоступен по прямому URL; доступ резолвит `LessonAccessPolicy`.

##### T2.22 — Front JS: конструктор программы + лента
- **Файлы:** `src/js/frontend/services/group-cockpit.js` (+ components) — **pure-JS function pattern**
  (фронт, не jQuery-объект; правило `CLAUDE.md`).
- **Назначение:** drag-drop порядок (→ `ReorderProgram`), добавить урок из банка, дата-пикеры
  занятия (→ `SaveLessonSchedule`), переключатели видимости (→ `SetLessonVisibility`), пагинация ленты.
- **Связи:** инициализация в `frontend.js` с guard `if (!document.getElementById('fs-group-cockpit')) return;`.
- **Готово:** все действия кокпита работают без перезагрузки.

##### T2.23 — SCSS кокпита
- **Файл:** `src/scss/frontend/components/_group-cockpit.scss` (импорт в `frontend.scss`).
- **Связи:** только токены `src/scss/frontend/_variables.scss`; недостающие — сперва в `_variables`.
- **Готово:** кокпит свёрстан консистентно; `npx gulp styles:frontend` без ошибок.

##### T2.24 — Регистрация сервисов
- **Файл:** `inc/Init.php` (`getServices()`).
- **Добавить:** `ScheduleController::class`, `GroupCockpitController::class`, `LearningEventSubscriber::class`.
- **Готово:** `Init::run()` поднимает их без ошибок DI.

##### T2.25 — `ContentUsageService`: +delivery-источники (созревание T1.26/T1.28)
- **Файлы:** новые источники в `ContentUsageService` (Этап 1) + бейдж в списках банков.
- **Назначение:** добавить delivery-источники теперь, когда есть `group_lessons`: урок ← `lesson_id`,
  работа ← `work_ids_snapshot`/`extra_work_ids`, курс ← `groups.course_id`. Гейт удаления (T1.28) и бейдж
  **автоматически** учитывают их — отдельной правки гейта не нужно (контракт `usageCount` стабилен).
- **Связи:** `GroupLessonRepository::countUsageByLesson` + чтение snapshot/extra/`groups.course_id`.
  Полный охват (вкл. экзамены) — после Этапа 4.
- **Готово:** урок/работа/курс, занятые в живых группах, попадают в usage → их нельзя удалить, пока стоят
  в программе; бейдж показывает охват по группам.

##### T2.26 — `LessonAccessPolicy` + политика `retain_after_expulsion`
- **Файлы:** `inc/Services/Course/LessonAccessPolicy.php`, `inc/Enums/AccessLevel.php` (`none|read|read_submit`),
  `inc/Enums/OptionName.php` (+ ключ настройки), настройка в админке (Settings).
- **Назначение:** **единый** резолвер доступа ученика к уроку — **членство, не подписка**. Заменяет
  наивный `isOpenForStudent = existsActive` из T2.13. Вся матрица «статус × даты × видимость ×
  read/write × политика» — в одном месте ([`Courses.md` §4](./Courses.md)).
- **Связи:** `StudentRecordRepository` (**все** записи ученика в группе, не только `active`),
  `GroupLessonRepository` (`visibility`, `opened_at`), `OptionsRepositories` (флаг политики),
  `PersonRepository::findByWpUserId`. Потребители: student-surface (T2.21), `SubmissionService` (Этап 3).
- **Правила:**
  - видимость: `visibility ∈ {open, archived}` (`hidden` — никогда ученику);
  - `active` → read = любой видимый урок (весь бэк-каталог); submit = `opened_at >= enrolled_at`;
  - терминальный статус + `retain` (дефолт) → read = видимые с `opened_at <= expelled_at`; submit = нет;
  - терминальный + `block` → материалы закрыты (кабинет — отдельный гейт, T2.27).
- **Методы:**
  ```php
  public function resolve( StudentRecordDTO $record, GroupLessonDTO $lesson ): AccessLevel;
  public function canRead( int $studentPersonId, int $groupLessonId ): bool;    // по всем записям ученика в группе
  public function canSubmit( int $studentPersonId, int $groupLessonId ): bool;
  public function visibleLessonsForStudent( int $studentPersonId, int $groupId ): array; // GroupLessonDTO[] с учётом окна
  ```
- **Готово:** поздний ученик видит бэк-каталог без просрочек; отчисленный (`retain`) — архив до
  `expelled_at`; `block` закрывает; нигде нет гейта по роли.

##### T2.27 — Гейт кабинета по политике (`retain` vs `block`)
- **Файлы:** `ProfileController` / student-surface — проверка `retain_after_expulsion` на входе в кабинет.
- **Назначение:** реализовать админ-выбор «оставлять кабинет vs блокировать» для полностью
  терминальных учеников (ни одной активной записи). **По умолчанию — не блокировать** (read-only;
  «личный кабинет не блокируется» — жёсткое требование).
- **Связи:** настройка из T2.26; `StudentRecordRepository` (статусы записей). **Роль не меняем.**
- **Готово:** при `block` терминальный ученик не входит в кабинет; при `retain` — входит, видит архив.

---

#### Слой назначения курса / эффективных работ

##### T2.28 — `CourseAssignmentService` (назначение курса группе = снапшот)
- **Файл:** `inc/Services/Course/CourseAssignmentService.php`.
- **Назначение:** снапшот курса в программу группы — bulk-инсерт строк `group_lessons` по
  `course.lesson_ids`; запись `groups.course_id`.
- **Связи:** `CourseManager` (Этап 1: `lesson_ids` курса), `GroupLessonRepository` (T2.8: bulk add +
  `nextPosition`), `GroupsRepository` (`course_id`; проверка `course.subjectKey == group.subject_key`),
  `LogEventDispatcherInterface` (`CourseAssigned` → лента). Гейт — `GroupAccessGuard::canManage`.
- **Методы:**
  ```php
  public function assign( int $groupId, int $courseId, int $actorUserId ): int; // → число добавленных строк
  // политика при непустой программе (append | replace) — параметр; см. Открытые вопросы #7
  ```
- **Готово:** уроки курса появляются в программе по порядку; `groups.course_id` записан; курс чужого
  предмета не назначается; действие в ленте.

##### T2.29 — `EffectiveWorksResolver` (работы строки = база + дельта)
- **Файл:** `inc/Services/Course/EffectiveWorksResolver.php`.
- **Назначение:** единая точка вычисления **эффективного** набора работ строки программы —
  **база** = `work_ids_snapshot`, если урок уже опубликован (copy-on-publish, T2.13), иначе живой
  `lesson.work_ids`; **+** `extra_work_ids` (дельта группы). Резолв в `WorkDTO`, порядок сохраняется.
- **Связи:** `LessonManager`/`WorkManager` (резолв ссылок), `GroupLessonRepository`
  (`work_ids_snapshot`, `extra_work_ids`). Потребители: UI ученика (T2.21, `LessonViewDTO`), кокпит,
  `SubmissionService` (Этап 3 — что можно сдавать).
- **Методы:**
  ```php
  public function resolve( GroupLessonDTO $row ): array;   // WorkDTO[]: (snapshot ?? lesson.work_ids) + extra, упорядоченно
  public function setExtraWorks( int $groupLessonId, array $workIds, int $actorUserId ): void; // валидация предмета + лог ExtraWorksChanged
  ```
- **Готово:** опубликованный урок группы стабилен (снапшот); неопубликованный — живой; дельта правится точечно.

---

### Порядок реализации (по зависимостям)

1. **T2.1, T2.6/T2.7** — TableName + миграция (вкл. `extra_work_ids`, `groups.course_id`) → таблицы есть.
2. **T2.10, T2.8** — DTO + `GroupLessonRepository` → программа читается/пишется.
3. **T2.2, T2.3, T2.12, T2.13, T2.15** — Nonce/AjaxHook + сервисы программы/видимости/гейт.
4. **T2.28, T2.29** — `CourseAssignmentService` (снапшот курса) + `EffectiveWorksResolver` (урок+дельта).
5. **T2.17, T2.19** — AJAX-контроллер + коллбеки → операции программы/назначения/дельты работают.
6. **T2.4, T2.18, T2.20, T2.22, T2.23** — страница + кокпит + шаблон + JS + SCSS (вкл. UI назначения
   курса и `extra_work_ids`) → **половина DoD**.
7. **T2.5, T2.11, T2.14, T2.16** — лог-канал → действия пишутся в ленту (параллельно с 3–5).
8. **T2.26, T2.27** — `LessonAccessPolicy` + гейт кабинета (членство, ретеншн) — основа доступа ученика.
9. **T2.21** — срез ученику (через policy + `EffectiveWorksResolver`) → **вторая половина DoD**.
10. **T2.24** — регистрация сервисов; **T2.25** — бейдж использований.

### Критерии приёмки этапа (проверка вручную)

- [ ] Созданы таблицы `fs_lms_group_lessons`, `fs_lms_learning_events`; в `fs_lms_groups` — колонка `course_id`.
- [ ] Преподаватель открывает `/group/?gid=<своя>`; чужую группу — нет (редирект/403).
- [ ] **Назначение курса** группе снапшотит его уроки в программу по порядку; `groups.course_id` записан.
- [ ] Курс/урок чужого предмета в группу не добавляется.
- [ ] **`extra_work_ids`:** доп. работа добавляется только этой группе; в другой группе того же урока её нет.
- [ ] Ученик видит работы урока + доп. работы своей группы одним списком (`EffectiveWorksResolver`).
- [ ] **copy-on-publish:** правка `work_ids` эталона **не** меняет уже опубликованный (`open`) урок в группе;
      ещё-не-открытый (`hidden`) урок и новая группа видят новую версию. Сдачи по работам не сиротеют.
- [ ] Добавление урока из банка, drag-drop порядок, дата занятия — сохраняются.
- [ ] Видимость `hidden`/`open`/`archived` переключается; `opened_at` ставится при первой публикации.
- [ ] Ученик-член видит видимые уроки (вкл. бэк-каталог); `hidden` недоступен по прямому URL.
- [ ] Отчисленный (политика `retain`) видит архив до `expelled_at`; при `block` — кабинет/доступ закрыт.
- [ ] Поздний ученик видит прошлые уроки без «фантомных просрочек».
- [ ] Действия пишутся в ленту группы; ученик/родитель видят только `is_public` + свои события.
- [ ] Ростер группы и лента рендерят человекочитаемые имена (`LogNameResolver`).
- [ ] `groups.schedule` не используется для нового расписания (источник — `group_lessons`).
- [ ] `npm run lint:js` и `npx gulp build` проходят.

### Тесты этапа 2 (PHPUnit)

(конвенция — см. «Тесты этапа 1».)

**Unit (`tests/Unit/Services/Course/`):**
- `CourseAssignmentServiceTest` — `assign()` снапшотит `course.lesson_ids` в `group_lessons` **по порядку**
  (`position`), пишет `groups.course_id`, диспатчит `CourseAssigned`; **отклоняет курс другого предмета**;
  политика непустой программы (`append` по умолчанию).
- `LessonVisibilityServiceTest` — **copy-on-publish**: первый `open` морозит `work_ids_snapshot` из
  `lesson.work_ids` + ставит `opened_at`; повторный `open` снапшот **не перезатирает** (липкий);
  диспатч `LessonPublished`/`LessonHidden`; `refreshFromLesson` перезаписывает осознанно.
- `EffectiveWorksResolverTest` — `opened` → `work_ids_snapshot + extra_work_ids`; `hidden` →
  `lesson.work_ids + extra_work_ids` (живой); **порядок сохраняется**; правка эталона не меняет
  опубликованную строку.
- `LessonAccessPolicyTest` — вся матрица (§4): `active` → чтение всего бэк-каталога, сдача при
  `opened_at>=enrolled_at` (поздний ученик без «фантомных просрочек»); терминальный+`retain` → чтение до
  `expelled_at`, сдача нет; `block` → none; `hidden` недоступен всегда.
- `GroupAccessGuardTest` — `canManage` (`teacher_id==user` ИЛИ Admin), `isMemberEver`, `isParentOf`.
- `ScheduleServiceTest` — add/remove/reorder диспатчат события; урок чужого предмета не добавляется.

**Integration (`tests/Integration/Repositories/`, `FakeWpdb`):**
- `GroupLessonRepositoryTest` — `listByGroup` сортирует по `position`; `reorder`/`setVisibility`/
  `updateSchedule` пишут точечно; `nextPosition`=MAX+1; `countUsageByLesson`; JSON round-trip
  `work_ids_snapshot`/`extra_work_ids`.
- `Log/LearningEventRepositoryTest` — `create`; `listByGroup` строит `WHERE group_id`; `listByGroupPublic` —
  `is_public=1 OR actor=self`; `listByActor`; журнал неизменяем (`update()` бросает).

**Готово:** зелёные сьюты; покрыты copy-on-publish (заморозка + липкость + формула эффективных работ),
матрица доступа, снапшот-назначение (порядок + кросс-предмет), неизменяемость и срезы ленты.

### Открытые вопросы этапа

1. **Параметризация кокпита: `?gid=N` vs rewrite `/group/{id}`.** Принято: query-arg `?gid=N`
   (без rewrite-правил, консистентно с `is_page`). Пересмотреть, если нужны «красивые» URL.
2. **Авторинг урока (admin, Этап 1) vs управление программой (фронт-кокпит, Этап 2).** Разные
   поверхности у одной роли. Если преподаватель не должен заходить в wp-admin — авторинг урока
   позже переезжает в кокпит (связано с [Этап 1, Открытый вопрос #1](#открытые-вопросы-этапа)).
3. **Привязка публикации к расписанию.** Сейчас видимость — ручная. На будущее: авто-`open` по
   `scheduled_at` через cron (`CronHook`) — [`Courses.md` §7 #3](./Courses.md). Вне Этапа 2.
4. **Дроп `groups.schedule`.** Оставлен на Этапе 2; удалить отдельным шагом после проверки, что
   столбец нигде не читается (правило `CLAUDE.md`: удаление колонки — через DDL + cleanup-секцию).
5. **Срез ученику: шорткод vs вкладка профиля.** Зависит от того, появится ли Этап 0 раньше;
   на Этапе 2 достаточно минимального шорткода.
6. **Бессрочный `retain` vs GDPR-удаление.** «Вечный» read-only доступ к пройденному удлиняет
   жизненный цикл ПДн (ответы/сдачи). Согласовать с retention/anonymize-машинерией: что побеждает
   при запросе на удаление. Вне Этапа 2, но влияет на политику.
7. **Повторное назначение курса непустой программе — `append` vs `replace`.** Если в `group_lessons`
   уже есть строки: дописывать уроки курса (`append`) или заменять программу (`replace`, с потерей
   ручных правок/`extra_work_ids`)? Рекомендация — `append` по умолчанию + явный «заменить» с
   подтверждением. Финализировать при T2.28.

---

## Этап 3 — Сдача работ и прогресс (gradebook)

### Цель этапа

Ученик сдаёт работы (практика / СР / ДЗ), преподаватель проверяет и оценивает; журнал
успеваемости строится **read-моделью** поверх фактов, без отдельной таблицы оценок.

### Готово, когда (Definition of Done)

- Полный цикл `выдано → сдано (со сроком) → проверено / возвращено` с баллами виден всем ролям.
- Ученик сдаёт ответ текстом и/или файлом (WP Media Library); видит статус, балл, комментарий.
- Преподаватель в кокпите проверяет очередь сдач: ставит балл, возвращает на доработку.
- Дедлайн: снапшот `group_lessons.homework_due_at` → `submissions.due_at`; «просрочено»
  вычисляется (`submitted_at > due_at`); `allow_late=0` блокирует сдачу после срока.
- Журнал успеваемости строится из fact-таблиц (`GradebookService` — UNION-источники).

### Зависимости

- **Этап 1** — `work_id` сдачи ссылается на `{key}_works` (тип/задания работы — `WorkManager`); `task_id` —
  на `{key}_tasks`; тема урока — `LessonManager`. `WorkType` enum существует с Этапа 1.
- **Этап 2** — `fs_lms_group_lessons` (`group_lesson_id`, `homework_due_at`, `allow_late`), `EffectiveWorksResolver`
  (что можно сдавать = `lesson.work_ids + extra_work_ids`), кокпит (панель проверки), лента активности
  (`LearningEvent` + subscriber), `GroupAccessGuard`.
- Существующее: `PersonRepository::findByWpUserId()` (текущий WP-юзер → `person_id`),
  `StudentRecordRepository` (гейт активной записи, дети родителя), `LogNameResolver`.

### Доменная модель

Одна новая факт-таблица. Журнал оценок (gradebook) — **read-model, без таблицы** (единый источник
балла — `submissions.score`; дублирующую таблицу не заводим, [`Courses.md` §7 #7](./Courses.md)).

```sql
CREATE TABLE {prefix}fs_lms_submissions (
  id                int unsigned        NOT NULL AUTO_INCREMENT,
  student_person_id int unsigned        NOT NULL,            -- → fs_lms_persons.id
  group_lesson_id   int unsigned        NOT NULL,            -- → fs_lms_group_lessons.id (через него → group_id)
  work_id           bigint unsigned     NOT NULL,            -- → CPT {key}_works (какую работу сдают)
  work_type         enum('practice','independent','homework') NOT NULL, -- снапшот из работы
  task_id           bigint unsigned     DEFAULT NULL,        -- опц. сдача по конкретному заданию внутри работы (NULL = вся работа)
  answer_text       longtext            DEFAULT NULL,
  attachment_id     bigint unsigned     DEFAULT NULL,        -- WP Media Library (не S3; §7 #6)
  due_at            datetime            DEFAULT NULL,        -- снапшот из group_lessons.homework_due_at; правится для индив. продления
  status            enum('assigned','submitted','graded','returned') NOT NULL DEFAULT 'assigned',
  score             decimal(6,2)        DEFAULT NULL,
  max_score         decimal(6,2)        DEFAULT NULL,
  feedback          text                DEFAULT NULL,        -- комментарий проверки (возврат/оценка)
  graded_by_user_id bigint unsigned     DEFAULT NULL,
  submitted_at      datetime            DEFAULT NULL,
  graded_at         datetime            DEFAULT NULL,
  created_at        datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY student_person_id (student_person_id),
  KEY group_lesson_id (group_lesson_id),
  KEY status (status)
  -- is_late НЕ хранится: вычисляется submitted_at > due_at
);
```

Read-model журнала (резолв на чтении, не материализуется):

```
GradebookEntryDTO
  studentPersonId · groupId · sourceType(submission|assessment|manual) · sourceId
  title (тема урока/название работы) · category(practice|independent|homework|assessment)
  score · maxScore · gradedAt
```

### Решения и допущения этапа

1. **Создание сдачи — ленивое (lazy).** Строка появляется при **первой сдаче ученика**
   (`status='submitted'`), `due_at` снапшотится из `group_lessons.homework_due_at` в этот момент.
   «Выдано» = открытый урок с **работой** в эффективном наборе (Этап 2 `visibility=open` +
   `EffectiveWorksResolver`), а не пред-созданная строка. Статус `assigned` зарезервирован под опц.
   пред-назначение / индив. продление дедлайна (отдельный шаг, см. Открытые вопросы #1).
2. **Одна строка на (ученик × group_lesson × work_id × task_id).** Дедуп — на уровне сервиса
   (`findForWork` перед insert; `returned` → повторная сдача обновляет ту же строку в `submitted`).
3. **Вложения — WP Media Library** через `attachment_id` ([`Courses.md` §7 #6](./Courses.md)).
   Нужен новый `MediaManager` — в плагине **нет** обёрток над аплоадом (проверено).
4. **`person_id`, не `wp_user_id`.** Сдающего резолвим `PersonRepository::findByWpUserId(get_current_user_id())`;
   право на сдачу гейтит `LessonAccessPolicy::canSubmit` (Этап 2 T2.26) — только `active` **И**
   `opened_at >= enrolled_at` (без обязательств задним числом). Чтение своих прошлых сдач сохраняется у
   отчисленного (`retain`). Журнал/просрочки скоупятся окном членства, **не** ростер × все уроки.
5. **Gradebook — read-model с источниками.** `GradebookService` агрегирует `GradeSourceInterface`;
   на Этапе 3 один источник — `SubmissionGradeSource`; `AssessmentGradeSource` добавится на Этапе 4
   (это и есть «UNION submissions+attempts» из [`Courses.md` §2](./Courses.md), собранный в сервисе).
6. **Балл ученика — не PII экзамена**, но ответы/файлы доступа гейтятся доменно. Прямой URL файла
   из Media Library публичен — ограничение известно (см. Открытые вопросы #3).

---

### Задачи

#### Слой Enums / фундамент

##### T3.1 — `TableName::Submissions`
- **Файл:** `inc/Enums/TableName.php`. **Добавить:** `case Submissions = 'fs_lms_submissions';`
- **Готово:** `TableName::Submissions->prefixed()` доступен.

##### T3.2 — `SubmissionStatus` enum
- **Файл:** `inc/Enums/SubmissionStatus.php` (новый, по образцу `EnrollmentStatus`).
- **Кейсы:** `Assigned='assigned'`, `Submitted='submitted'`, `Graded='graded'`, `Returned='returned'` + `label()`.
- **Связи:** `SubmissionDTO`, `SubmissionService`, UI-статусы.
- **Готово:** типобезопасные статусы вместо строк.

##### T3.3 — `WorkType` enum — **уже создан на Этапе 1 (T1.2)**
- **Файл:** `inc/Enums/WorkType.php` (существует с Этапа 1).
- **Кейсы:** `Practice='practice'`, `Independent='independent'`, `Homework='homework'` + `label()`.
- **Связи:** тип живёт на CPT `{key}_works`; `submissions.work_type` — **снапшот** из работы при сдаче
  (не отдельный источник). На Этапе 3 только переиспользуем — нового кода не нужно.
- **Готово:** сдача берёт `work_type` у своей работы (`work_id`).

##### T3.4 — `Nonce`: сдача + проверка
- **Файл:** `inc/Enums/Nonce.php`. **Добавить:** `case SubmitWork = 'fs_lms_submit_work';`,
  `case GradeWork = 'fs_lms_grade_work';`
- **Связи:** студент-сдача — `Nonce::SubmitWork->verify()` (без capability, гейт доменный);
  препод-проверка — `authorize(Nonce::GradeWork, Capability::ManageLMSAssignments)`.
- **Готово:** nonces работают.

##### T3.5 — `AjaxHook`: операции сдачи/проверки
- **Файл:** `inc/Enums/AjaxHook.php` (секция `// ==== Сдача работ ====`).
- **Добавить:**
  ```php
  case SubmitWork          = 'submit_work';            // студент: текст + (опц.) файл
  case SaveGrade           = 'save_grade';             // препод: балл + статус graded
  case ReturnSubmission    = 'return_submission';      // препод: вернуть на доработку
  case GetGroupSubmissions = 'get_group_submissions';  // препод: очередь проверки группы
  case GetMySubmissions    = 'get_my_submissions';     // студент: свои сдачи по уроку
  case GetGradebook        = 'get_gradebook';          // журнал: group/student срез
  ```
- **Готово:** хуки в `toJsArray()`.

##### T3.6 — `LogEvent`: события сдачи (переиспользуют канал Этапа 2)
- **Файл:** `inc/Enums/LogEvent.php`. **Добавить:** `SubmissionMade`, `SubmissionGraded`, `SubmissionReturned`.
- **Связи:** диспетчеризуются из `SubmissionService`; ловит **тот же** `LearningEventSubscriber`
  (Этап 2 T2.16) — расширить его подписки; payload — существующий `LearningEvent` (Этап 2 T2.11),
  отдельный Event DTO не нужен.
- **Готово:** сдача/проверка пишутся в ленту группы (`action=submission_made|submission_graded`).

---

#### Слой Migrations / Repositories / DTO

##### T3.7 — Таблица `fs_lms_submissions`
- **Файл:** `inc/Migrations/Migration_1_0_0.php` — `dbDelta()` в `up()` + строка в `down()`.
- **Внедрение (dev):** сбросить `fs_lms_schema_version` в `0.0.0`, перезагрузить WP.
- **Готово:** таблица создана с индексами; `down()` дропает.

##### T3.8 — `SubmissionRepository`
- **Файл:** `inc/Repositories/WPDBRepositories/SubmissionRepository.php`.
- **Назначение:** CRUD сдач + выборки для очереди проверки, кабинета ученика и журнала.
- **Связи:** `TableName::Submissions`; `SubmissionDTO`/`SubmissionInputDTO`. Часть запросов
  джойнит `fs_lms_group_lessons` для резолва `group_id`.
- **Методы:**
  ```php
  public function create( SubmissionInputDTO $dto ): int;
  public function update( int $id, array $data ): bool;            // grade/return/resubmit
  public function find( int $id ): ?SubmissionDTO;
  public function findForWork( int $studentPersonId, int $groupLessonId, int $workId, ?int $taskId ): ?SubmissionDTO; // дедуп
  public function listByStudentAndGroupLesson( int $studentPersonId, int $groupLessonId ): array;
  public function listQueueByGroup( int $groupId, array $statuses = ['submitted'] ): array;   // JOIN group_lessons
  public function listForGradebookByGroup( int $groupId ): array;       // graded → строки журнала
  public function listForGradebookByStudent( int $studentPersonId ): array;
  ```
- **Готово:** очередь проверки и журнал собираются запросами; дедуп работает.

##### T3.9 — `SubmissionDTO` + `SubmissionInputDTO`
- **Файл:** `inc/DTO/Course/SubmissionDTO.php`, `inc/DTO/Course/SubmissionInputDTO.php` (`readonly`).
- **Поля:** все колонки + вычисляемый геттер `isLate(): bool` (`submitted_at > due_at`).
- **Готово:** round-trip; `isLate()` корректен.

##### T3.10 — `GradeDTO` + `GradebookEntryDTO`
- **Файлы:** `inc/DTO/Course/GradeDTO.php` (вход проверки: `score`, `maxScore`, `feedback`, `status`),
  `inc/DTO/Course/GradebookEntryDTO.php` (строка журнала, см. «Доменную модель»).
- **Готово:** проверка принимает `GradeDTO`; журнал отдаёт `GradebookEntryDTO[]`.

---

#### Слой Managers / Services

##### T3.11 — `MediaManager`
- **Файл:** `inc/Managers/MediaManager.php` (новый — обёртка над WP Media API, т.к. её нет).
- **Назначение:** загрузка файла сдачи в Media Library, удаление, получение URL/метаданных.
- **Связи:** `media_handle_upload()` / `wp_delete_attachment()` / `wp_get_attachment_url()`
  (требует `wp-admin/includes/{media,file,image}.php`). Никаких прямых вызовов вне этого менеджера.
- **Методы:**
  ```php
  public function uploadFromRequest( string $fileKey, int $postParent = 0 ): int; // → attachment_id, throws на ошибке
  public function delete( int $attachmentId ): bool;
  public function url( int $attachmentId ): string;
  ```
- **Готово:** файл сдачи попадает в Media Library; возвращается `attachment_id`; валидация типа/размера.

##### T3.12 — `SubmissionService`
- **Файл:** `inc/Services/Course/SubmissionService.php`.
- **Назначение:** сдача, проверка, возврат — вся бизнес-логика.
- **Связи:** `SubmissionRepository`, `MediaManager`, `GroupLessonRepository` (дедлайн/`allow_late`),
  `EffectiveWorksResolver` (валидность работы в строке) / `WorkManager` (тип работы), `GroupAccessGuard`
  (гейт), `PersonRepository` (резолв person), `LogEventDispatcherInterface` (события).
- **Методы:**
  ```php
  public function submit( int $studentPersonId, int $groupLessonId, int $workId, ?int $taskId,
                          string $answerText, ?string $fileKey ): int;
  // валидность: work_id в эффективном наборе строки (EffectiveWorksResolver); work_type — снапшот из работы;
  // LessonAccessPolicy::canSubmit (active + opened_at>=enrolled_at); снапшот due_at;
  // allow_late=0 && now>due → отказ; dedupe (findForWork); status submitted; событие SubmissionMade
  public function grade( int $submissionId, GradeDTO $grade, int $teacherUserId ): void;  // событие SubmissionGraded
  public function returnForRework( int $submissionId, string $feedback, int $teacherUserId ): void; // SubmissionReturned
  ```
- **Готово:** цикл сдача→проверка→возврат работает; дедлайн/late-логика соблюдается; события в ленте.

##### T3.13 — `GradebookService` (read-model)
- **Файл:** `inc/Services/Course/GradebookService.php` + `inc/Contracts/GradeSourceInterface.php`
  + `inc/Services/Course/SubmissionGradeSource.php`.
- **Назначение:** собрать журнал из источников-фактов (без таблицы). На Этапе 3 — один источник.
- **Связи:** `GradeSourceInterface` (`entriesForGroup`, `entriesForStudent` → `GradebookEntryDTO[]`);
  `SubmissionGradeSource` поверх `SubmissionRepository` (+ резолв темы через `LessonManager`).
  Этап 4 добавит `AssessmentGradeSource` без правки `GradebookService`.
- **Методы:**
  ```php
  public function forGroup( int $groupId ): array;       // GradebookEntryDTO[] — UNION всех источников
  public function forStudent( int $studentPersonId ): array;
  ```
- **Готово:** журнал строится из submissions; добавление источника — без изменения сервиса.

---

#### Слой Controllers / Callbacks

##### T3.14 — `SubmissionController` (AJAX)
- **Файл:** `inc/Controllers/SubmissionController.php` (`extends AjaxController`).
- **Назначение:** регистрация AJAX-хуков T3.5 (все для авторизованных: и студент, и препод залогинены).
- **Связи:** конструктор — `SubmissionCallbacks` + `GradingCallbacks`; `ajaxActions()`.
- **Готово:** `wp_ajax_*` сдачи/проверки зарегистрированы.

##### T3.15 — `Callbacks/Course/SubmissionCallbacks` (студент)
- **Файл:** `inc/Callbacks/Course/SubmissionCallbacks.php` (`use Sanitizer;`; **без** `Authorizer` —
  у студента нет capability).
- **Назначение:** `ajaxSubmitWork`, `ajaxGetMySubmissions`.
- **Связи:** `Nonce::SubmitWork->verify()` (без capability) + доменный гейт `GroupAccessGuard::isStudentOf`;
  делегирует `SubmissionService`; person — `PersonRepository::findByWpUserId(get_current_user_id())`.
- **Готово:** студент сдаёт только в свои активные группы; чужой `group_lesson_id` → отказ.

##### T3.16 — `Callbacks/Course/GradingCallbacks` (преподаватель)
- **Файл:** `inc/Callbacks/Course/GradingCallbacks.php` (`use Authorizer; use Sanitizer;`).
- **Назначение:** `ajaxSaveGrade`, `ajaxReturnSubmission`, `ajaxGetGroupSubmissions`, `ajaxGetGradebook`.
- **Связи:** `authorize(Nonce::GradeWork, Capability::ManageLMSAssignments)` + `GroupAccessGuard::canManage`
  (владение конкретной группой); делегирует `SubmissionService`/`GradebookService`.
- **Готово:** проверять может только препод своей группы (или Admin).

---

#### Слой Templates / JS / SCSS / Init

##### T3.17 — UI сдачи (ученик) — расширение open-lesson view (Этап 2 T2.21)
- **Файлы:** `templates/frontend/...` — форма сдачи в открытом уроке: per работа (эффективный набор,
  T2.29) / опц. per задание — textarea ответа + file input + кнопка; отображение статуса/балла/
  комментария после проверки.
- **Связи:** данные через `GetMySubmissions`; сабмит через `SubmitWork` (FormData с файлом).
- **Готово:** ученик сдаёт и видит результат; просроченные (`allow_late=0`) — без кнопки сдачи.

##### T3.18 — UI проверки + журнал (преподаватель) в кокпите (Этап 2)
- **Файлы:** панель «Проверка работ» (очередь `status=submitted`) + форма оценки (балл/макс/возврат);
  таблица журнала группы (ученики × работы × баллы).
- **Связи:** `GetGroupSubmissions`, `SaveGrade`, `ReturnSubmission`, `GetGradebook`; имена — `LogNameResolver`.
- **Готово:** препод проверяет из очереди; журнал группы отображается из fact-данных.

##### T3.19 — Журнал ученику/родителю
- **Файлы:** срез журнала: ученик — свои оценки (`GradebookService::forStudent`); родитель — по детям
  (`StudentRecordRepository::findActiveByParent` → дети → их журналы), read-only.
- **Связи:** минимальный вывод (шорткод/вкладка), как срез Этапа 2.
- **Готово:** ученик/родитель видят оценки; родитель — только своих детей.

##### T3.20 — Frontend JS + SCSS
- **Файлы:** `src/js/frontend/services/submission.js` (pure-JS): сабмит с файлом (FormData), действия
  проверки, рендер журнала; валидация через `common/validators`. SCSS — `src/scss/frontend/components/_submissions.scss` (токены).
- **Готово:** сдача/проверка/журнал работают без перезагрузки; `gulp build` чистый.

##### T3.21 — Регистрация + расширение подписчика
- **Файлы:** `inc/Init.php` (+ `SubmissionController::class`); `LearningEventSubscriber` (Этап 2 T2.16) —
  подписать на `SubmissionMade`/`SubmissionGraded`/`SubmissionReturned`.
- **Готово:** сервис поднят; события сдачи материализуются в ленту.

---

### Порядок реализации (по зависимостям)

1. **T3.1–T3.3, T3.7** — enums + таблица.
2. **T3.9, T3.10, T3.8** — DTO + `SubmissionRepository`.
3. **T3.11, T3.12** — `MediaManager` + `SubmissionService` (ядро сдачи/проверки).
4. **T3.4, T3.5, T3.14–T3.16, T3.21** — nonce/hook + контроллер + коллбеки + регистрация → AJAX-цикл.
5. **T3.17, T3.18, T3.20** — UI ученика и кокпита → **основная часть DoD** (сдал → проверено/возвращено).
6. **T3.6** + расширение подписчика — события в ленту (можно параллельно с 3–4).
7. **T3.13, T3.19** — `GradebookService` + срезы ученику/родителю → журнал успеваемости.

### Критерии приёмки этапа (проверка вручную)

- [ ] Создана таблица `fs_lms_submissions`.
- [ ] Ученик сдаёт текст и файл; файл попадает в Media Library, `attachment_id` сохранён.
- [ ] `due_at` снапшотится из `group_lessons.homework_due_at`; `allow_late=0` блокирует сдачу после срока.
- [ ] «Просрочено» отображается из вычисления `submitted_at > due_at` (не из колонки).
- [ ] Преподаватель своей группы ставит балл (graded) и возвращает на доработку (returned + комментарий).
- [ ] Возвращённую работу ученик пересдаёт — обновляется та же строка, не плодится дубль.
- [ ] Журнал группы/ученика/родителя строится из `submissions` (без таблицы оценок).
- [ ] Чужую группу препод не проверяет; ученик не сдаёт не в свою активную группу.
- [ ] События сдачи/проверки видны в ленте группы (Этап 2).
- [ ] `npm run lint:js` и `npx gulp build` проходят.

### Тесты этапа 3 (PHPUnit)

(конвенция — см. «Тесты этапа 1».)

**Unit (`tests/Unit/Services/Course/`):**
- `SubmissionServiceTest` — `submit()`: **`work_id` обязан быть в эффективном наборе строки**
  (`EffectiveWorksResolver`), иначе отказ; `work_type` снапшотится из работы; `due_at` снапшотится из
  `group_lessons.homework_due_at`; `allow_late=0 && now>due` → отказ; дедуп (`findForWork`: `returned`→
  пересдача обновляет ту же строку); гейт `LessonAccessPolicy::canSubmit`; диспатч `SubmissionMade`.
  `grade()`/`returnForRework()` — балл/статус + события.
- `DTO/Course/SubmissionDTOTest` — `isLate()` = `submitted_at > due_at` (граничные значения).
- `Services/Course/GradebookServiceTest` — `forGroup`/`forStudent` собирают из источников
  (`GradeSourceInterface`); на Этапе 3 один источник; добавление источника не меняет сервис; **журнал — из
  фактов, не из ленты событий**.
- `Managers/MediaManagerTest` — `uploadFromRequest` валидирует тип/размер, возвращает `attachment_id`
  (моки WP-media).

**Integration (`tests/Integration/Repositories/`, `FakeWpdb`):**
- `SubmissionRepositoryTest` — `create`; `findForWork` строит предикат по
  `student_person_id+group_lesson_id+work_id(+task_id)` (дедуп); `listQueueByGroup` джойнит `group_lessons`
  и фильтрует `status`; `listForGradebookBy*` отдаёт `graded`.

**Готово:** зелёные сьюты; покрыты запрет сдачи вне эффективного набора, снапшот дедлайна + late,
дедуп по `work_id`, gradebook как read-model поверх фактов.

### Открытые вопросы Этапа 3

1. **Lazy vs пред-назначение сдач.** Принято: lazy (строка при первой сдаче). Статус `assigned` и
   индивидуальные продления дедлайна (`due_at` на ученика) — отдельный шаг, если понадобится контроль
   «кто не сдал» по пред-созданным строкам.
2. **Дедуп одной работы.** Принято: на уровне сервиса (`findForWork` перед insert), т.к. `task_id`
   nullable ломает SQL `UNIQUE`. Альтернатива — `task_id NOT NULL DEFAULT 0` + UNIQUE-ключ.
3. **Приватность файлов сдачи.** Media Library отдаёт публичный URL. Если нужна защита — отдельный
   контролируемый эндпоинт скачивания (проверка доступа) поверх `attachment_id`; вне Этапа 3.
4. **`fs_lms_grade_entries` (ручные/бонусные оценки).** Не в Этапе 3 — заводится позже как третий
   источник `GradeSourceInterface` ([`Courses.md` §2](./Courses.md)).
5. **Балл по заданию vs по работе.** На Этапе 3 поддержано и то, и другое (`task_id` NULL = за всю
   работу). Политика максимального балла/агрегации в журнале — уточнить при UI журнала (T3.18).
