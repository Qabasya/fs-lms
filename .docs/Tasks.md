# Tasks.md — Декомпозиция этапов реализации LMS

Рабочая декомпозиция этапов из [`Courses.md`](./Courses.md) на конкретные задачи.
Для каждой задачи указано: **что создаётся**, **с чем связано** (зависимости и точки интеграции),
**какие методы/сигнатуры** нужны и **критерий готовности**.

Документ ведётся по мере прохождения этапов. Сейчас детализированы **Этап 1 — Уроки**,
**Этап 2 — Программа группы** и **Этап 3 — Сдача работ и прогресс**.

Легенда статусов: `[ ]` не начато · `[~]` в работе · `[x]` готово.

---

## Этап 1 — Уроки (банк, CPT `{key}_lessons`)

### Цель этапа

Переиспользуемый авторинг уроков преподавателем. Урок — **атом программы** (см.
[`Courses.md` §0, решение 1](./Courses.md)): у каждой очной группы своя программа собирается
из уроков. На этом этапе создаётся только **банк уроков предмета** (определение); привязка к
группе, расписание и доставка — Этап 2.

### Готово, когда (Definition of Done)

- Преподаватель создаёт урок как со **свободным контентом** (тема + теория), так и с
  **прикреплёнными заданиями** из библиотеки предмета в бакеты «практика / СР / ДЗ».
- Задания прикрепляются **ссылкой** (`task_id`): либо выбором из библиотеки (по номеру), либо
  созданием нового задания прямо из урока (модалка → реальный `{key}_tasks` → авто-ссылка).
- По умолчанию селектор показывает **«мои задания»** (по `post_author`) с переключением на весь
  банк предмета; опц. фильтр по «коллекции» (теме).
- Урок сохраняется в банк предмета (`{key}_lessons`) и доступен для переиспользования.
- Урок может существовать **без заданий** (просто занятие) — бакеты необязательны
  ([`Courses.md` §0, решение 4](./Courses.md)).

---

### Доменная модель урока

Урок — **CPT `{key}_lessons`**, регистрируется per-subject тем же механизмом, что
`{key}_tasks` / `{key}_articles`. Хранение строго по текущему расколу: **контент в CPT
(пост + post-meta)**, факты обучения — в таблицах (появятся на этапах 2–4).

Маппинг полей урока на WP-сущности (нативный путь, согласовано с CPT `articles`,
который уже `supports: title, editor, thumbnail`):

| Поле урока (из [`Courses.md` §2](./Courses.md)) | Где хранится | Примечание |
|---|---|---|
| `topic` — тема занятия | `post_title` | нативно; тема = заголовок поста |
| `theory` — теория (rich-text) | `post_content` (`supports: editor`) | нативный редактор |
| `theory` — ссылка на статью | meta `theory_article_id` | опц. переопределяет inline-теорию ссылкой на `{key}_articles` |
| `task_type` — привязка к типу задания | meta `task_type` | опц. `term_id` таксономии `{key}_task_number` |
| `practice[]` / `independent[]` / `homework[]` | meta-бакеты | каждый: `{ content, task_ids[] }`; `task_ids` — **ссылки** на `{key}_tasks`, не копии (модель Tutor Content Bank) |
| автор урока | `post_author` (нативно) | у CPT — `post_author`/`post_modified`, **не** fact-таблицы ([`Courses.md` §7 #12](./Courses.md)) |

Вся meta урока (кроме `post_title`/`post_content`) пишется одним массивом в
`PostMetaName::Meta` (`fs_lms_meta`) — как у заданий. Структура meta:

```php
// get_post_meta( $lesson_id, PostMetaName::Meta->value, true )
[
    'theory_article_id' => 0,            // int, 0 = inline-теория из post_content
    'task_type'         => 0,            // int term_id или 0
    'practice'          => [ 'content' => '', 'task_ids' => [] ],
    'independent'       => [ 'content' => '', 'task_ids' => [] ],
    'homework'          => [ 'content' => '', 'task_ids' => [] ],
]
```

### Решения и допущения этапа

1. **Авторинг — в wp-admin** через нативный экран CPT + метабокс, как у `tasks`/`articles`.
   Фронтовый кокпит преподавателя ([`Courses.md` §4](./Courses.md)) — сборка программы (Этап 2),
   а не авторинг контента урока. См. [Открытые вопросы](#открытые-вопросы-этапа) #1.
2. **У урока один фиксированный шаблон метабокса** (`LessonTemplate`), без per-term резолва.
   Поэтому НЕ переиспользуем `TaskTemplate` enum / `TemplateRegistry` / `TemplateResolver`
   (они решают задачу выбора шаблона задания по номеру). Урок проще — одна форма.
3. **Capability — переиспользуем** `Capability::ManageLMSAssignments` (у преподавателя уже есть),
   новые caps НЕ вводим ([`Courses.md` §7 #2](./Courses.md)). CPT отдаётся под
   `capability_type => 'fs_lms_content'` + `map_meta_cap => true`, право мапится на
   `manage_lms_assignments`.
4. **«Тема» = `post_title`, «теория» = `post_content`** (см. таблицу выше) — вместо отдельных
   meta `topic`/`theory`. Нативнее, даёт человекочитаемый список уроков и встроенный редактор.
5. **Задания — отдельные переиспользуемые `{key}_tasks`, прикрепляются ссылкой (`task_id`), не копией**
   (Вариант А). «Создать новое» прямо из урока = существующая модалка создания задания
   (`TaskCreationController` / `templates/admin/components/modals/task-modal.php`) → реальный пост
   `{key}_tasks` → авто-прикрепление ссылкой в бакет (T1.21). Подтверждено моделью **Tutor LMS
   Content Bank** (контент линкуется в курсы, не копируется; рядом со счётчиком «используется в N»).
6. **Видимость банка — весь предмет, но фильтр «мои задания» (`post_author`) по умолчанию**
   (Вариант 1). Жёсткой стены «только свои» нет: итоговый экзамен (Этап 4) собирается из общего
   пула заданий предмета (как Quiz из Content Bank в Tutor — авторство трекается, но не изолирует).
7. **«Коллекции» = пользовательская таксономия на `{key}_tasks`** (механизм `TaxonomyRepository`
   уже есть), напр. «Циклы Python». Селектор бакета умеет фильтровать по коллекции — **без нового
   слоя** (наш аналог Tutor Collections). См. T1.22.
8. **Под Python нужен шаблон задания «условие + ответ + решение».** Текущий `StandardTaskTemplate`
   = условие + ответ (без решения). Либо берём существующий код-шаблон (`CodeTaskTemplate` /
   `TaskTextSolution`), либо заводим шаблон с полем «решение». См. T1.24.

> **Что взято из Tutor LMS** (разбор доков): ссылочное переиспользование контента (Content Bank),
> «коллекции» как тематические наборы, счётчик «используется в N» (T1.23), модалка «создать прямо
> в конструкторе». Что НЕ берём: Tutor — self-paced **курс**; у нас очные **когорты + расписание**,
> а видео — запись занятия (Этап 5), не часть урока-определения.

---

### Задачи

#### Слой Enums / фундамент

##### T1.1 — `PostTypeResolver`: резолв CPT уроков
- **Файл:** `inc/Services/PostTypeResolver.php` (расширение существующего).
- **Назначение:** единая точка формирования slug `{key}_lessons` и обратного разбора —
  чтобы нигде не конкатенировать строки вручную (правило из `CLAUDE.md`).
- **Связи:** используется в `SubjectController` (регистрация CPT), `LessonMetaBoxController`,
  `LessonManager`, полях метабокса (определить предмет из `$post->post_type`).
- **Методы (добавить):**
  ```php
  public const string LESSONS_SUFFIX = '_lessons';
  public static function lessons( string $subject_key ): string;          // "{key}_lessons"
  public static function isLessonPostType( string $post_type ): bool;     // str_ends_with(..., '_lessons')
  public static function subjectFromLessonPostType( string $post_type ): string;
  ```
- **Готово:** `PostTypeResolver::lessons('math') === 'math_lessons'`;
  `subjectFromLessonPostType('math_lessons') === 'math'`.

##### T1.2 — `Nonce::AuthorLesson`
- **Файл:** `inc/Enums/Nonce.php` (добавить case).
- **Назначение:** защита admin-AJAX урока (селектор заданий, выборки статей/типов).
  Сохранение метабокса использует существующий `Nonce::SaveMeta` (как у заданий) —
  отдельный nonce для save не нужен.
- **Связи:** `LessonCallbacks` (`authorize(Nonce::AuthorLesson, ...)`), JS-локализация в `Enqueue`.
- **Добавить:** `case AuthorLesson = 'fs_lms_author_lesson';`
- **Готово:** `Nonce::AuthorLesson->create()` / `->verify()` доступны.

##### T1.3 — `AjaxHook`: хуки селектора заданий урока
- **Файл:** `inc/Enums/AjaxHook.php` (добавить cases в новую секцию `// ==== Уроки ====`).
- **Назначение:** AJAX для конструктора бакетов — подтянуть задания предмета (опц. по типу),
  статьи предмета, типы заданий.
- **Связи:** регистрируются в `LessonController::ajaxActions()`; имена методов-коллбеков
  выводятся автоматически (`ajax{Case}`); JS читает их из `fs_lms_vars.ajax_actions`.
- **Добавить:**
  ```php
  case GetLessonTaskCandidates = 'get_lesson_task_candidates'; // params: task_type, collection, scope(mine|subject), search
  case GetLessonArticles       = 'get_lesson_articles';        // статьи предмета (для theory_article_id)
  ```
  Типы заданий (`task_type`) уже отдаёт существующий `AjaxHook::GetTaskTypes`
  (`TaskCreationCallbacks::ajaxGetTaskTypes`) — переиспользуем, новый case не нужен.
- **Готово:** хуки видны в `AjaxHook::toJsArray()` и доступны фронту.

##### T1.4 — Capability урока (решение, без кода-enum)
- **Файл:** — (решение задокументировано здесь; правка в `RoleManager`, см. T1.6).
- **Назначение:** зафиксировать переиспользование `ManageLMSAssignments` для CPT уроков.
- **Связи:** `RoleManager::syncCapabilities()` (версионируется `fs_lms_caps_version` в `Init`).
  При `capability_type => 'fs_lms_content'` + `map_meta_cap => true` мета-права
  (`edit_fs_lms_content`, `publish_fs_lms_contents`, …) должны мапиться на
  `manage_lms_assignments` у ролей `FSTeacher`/администратора.
- **Готово:** преподаватель с `manage_lms_assignments` редактирует уроки; новых caps в БД не добавлено.

---

#### Слой Registrars / Controllers — регистрация CPT

##### T1.5 — Регистрация CPT `{key}_lessons`
- **Файлы:** `inc/Controllers/SubjectController.php` (расширить `registerForSubject()` и
  `getDefaultCptArgs()`). `SubjectCPTRegistrar` и `CPTManager` — **без изменений**
  (`addStandardType()` уже универсален).
- **Назначение:** для каждого предмета регистрировать третий CPT — уроки — в одной очереди
  с `tasks`/`articles`.
- **Связи:**
  - `SubjectController::registerForSubject()` уже вызывает `addStandardType()` для tasks/articles —
    добавить третий вызов для `PostTypeResolver::lessons($key)`.
  - Проходит через фильтр `fs_lms_cpt_args` (как tasks/articles) — внешняя модификация лейблов.
  - `CPTManager::register()` навешивает `register_post_type` на `init` — менять не нужно.
- **Конфиг CPT (`options`)** — реализует решения [`Courses.md` §2](./Courses.md) против «взрыва CPT»:
  ```php
  'supports'           => [ 'title', 'editor', 'author', 'thumbnail' ],
  'show_in_menu'       => false,        // сводное меню «Курсы» — Этап 2
  'show_in_rest'       => false,        // REST пока не нужен
  'exclude_from_search'=> true,         // доступ гейтится доменно (student_records)
  'capability_type'    => 'fs_lms_content',
  'map_meta_cap'       => true,
  'has_archive'        => false,
  ```
- **Методы:**
  ```php
  // SubjectController::registerForSubject(object $subject): void — добавить блок:
  $lesson_cpt  = PostTypeResolver::lessons( $key );
  $lesson_args = $this->getDefaultCptArgs( 'lessons', $subject );
  $this->cpt_registrar->addStandardType( $lesson_cpt, 'Уроки', $lesson_args['labels'], $lesson_args['options'] );

  // SubjectController::getDefaultCptArgs(): добавить ветку match для 'lessons'
  'lessons' => [
      'labels'  => [ 'nom' => 'Урок', 'acc' => 'урок', 'gen' => 'урока', 'gender' => 'masculine' ],
      'options' => [ 'supports' => [ 'title', 'editor', 'author', 'thumbnail' ], /* + конфиг выше */ ],
  ],
  ```
- **Готово:** после reload страницы WP CPT `{key}_lessons` зарегистрирован для каждого предмета;
  виден через `get_post_types()`; редактор поста доступен (title + editor).

##### T1.6 — Мапинг мета-прав CPT на роли
- **Файл:** `inc/Managers/RoleManager.php` (метод `syncCapabilities()` или его конфиг ролей).
- **Назначение:** выдать `FSTeacher` + администратору производные права `fs_lms_content`
  (либо заставить `map_meta_cap` сводиться к `manage_lms_assignments`).
- **Связи:** вызывается из `Init::run()` при смене `fs_lms_caps_version` — **поднять версию**
  (`'1.0'` → `'1.1'`), иначе sync не выполнится.
- **Готово:** преподаватель открывает список/редактор уроков без «недостаточно прав».

---

#### Слой MetaBoxes — форма урока

##### T1.7 — Поле `TaskBucketField` (бакет «практика/СР/ДЗ»)
- **Файл:** `inc/MetaBoxes/Fields/TaskBucketField.php` (новый, `extends BaseField implements FieldInterface`).
- **Назначение:** композитное поле бакета: опц. **инструкция** (textarea, напр. срок/комментарий) +
  **упорядоченный список ссылок на задания** (`task_id`-чипы). Чипы добавляются двумя путями:
  **(а) из библиотеки** — ввод номера → выпадающий список (T1.19); **(б) создать новое** — модалка
  создания задания (T1.21) → новый `{key}_tasks` → авто-чип. Хранится ссылка (`task_id`), не копия.
- **Связи:**
  - Используется в `LessonTemplate` трижды (practice/independent/homework).
  - `render()` получает `$post` → предмет через `PostTypeResolver::subjectFromLessonPostType()`;
    рисует контейнер с `data-subject` и `data-bucket` для JS-селектора (T1.14).
  - ID заданий хранятся скрытыми инпутами `fs_lms_meta[<bucket>][task_ids][]`.
  - `sanitize()` вызывается из `MetaBoxManager::saveFields()`.
- **Методы (сигнатуры `FieldInterface`):**
  ```php
  public function render( \WP_Post $post, string $id, string $label, mixed $value ): void;
  // $value = [ 'content' => string, 'task_ids' => int[] ]
  public function sanitize( mixed $value ): mixed;
  // → [ 'content' => $this->sanitizeEditorContent(...), 'task_ids' => array_map('intval', array_filter(...)) ]
  ```
- **Готово:** поле рендерит контент + чипы; после сохранения meta-бакет содержит чистый
  `{content, task_ids[]}`; пустой бакет сохраняется как `{content:'', task_ids:[]}`.

##### T1.8 — Поле `TaskTypeField` (привязка к типу заданий)
- **Файл:** `inc/MetaBoxes/Fields/TaskTypeField.php` (новый).
- **Назначение:** опц. `<select>` термина таксономии `{key}_task_number` — задаёт, из какого
  типа заданий селектор бакетов подбирает кандидатов.
- **Связи:** `render()` тянет термины через `PostTypeResolver::getTaskTaxonomy($subject)` +
  `get_terms()` (или делегирует `TermManager`, чтобы не звать WP API в поле — см. примечание).
  Значение `term_id` управляет фильтром в `GetLessonTaskCandidates` (T1.11).
- **Методы:** `render(...)`, `sanitize()` → `(int) $value`.
- **Примечание:** чтобы не нарушать «без прямых WP-вызовов вне Managers», запрос терминов
  лучше прокинуть в поле готовым списком (через конструктор/`LessonTemplate`) либо вынести в
  `LessonAuthoringService::getTaskTypes()`. Зафиксировать в реализации.
- **Готово:** селект показывает типы заданий предмета; сохраняется `task_type` как `term_id`.

##### T1.9 — Поле `ArticleRefField` (теория ссылкой на статью)
- **Файл:** `inc/MetaBoxes/Fields/ArticleRefField.php` (новый; по образцу `LinkField`).
- **Назначение:** опц. выбор статьи `{key}_articles` как источника теории (переопределяет
  inline `post_content`).
- **Связи:** список статей подаётся как в T1.8 (через сервис, не прямой WP-вызов из поля).
- **Методы:** `render(...)`, `sanitize()` → `(int) $value`.
- **Готово:** селект статей предмета; сохраняется `theory_article_id`.

##### T1.10 — Шаблон `LessonTemplate`
- **Файл:** `inc/MetaBoxes/Templates/LessonTemplate.php` (новый, `extends BaseTemplate`).
- **Назначение:** единая форма метабокса урока. Группирует поля по секциям (как
  `ThreeInOneTemplate`): «Теория (источник)», «Тип заданий», «Практика», «СР», «ДЗ».
- **Связи:**
  - Поля: `theory_article_id` → `ArticleRefField`, `task_type` → `TaskTypeField`,
    `practice`/`independent`/`homework` → `TaskBucketField`.
  - `render( \WP_Post $post )` переопределяется для секционной вёрстки; `get_fields()`
    возвращает плоскую карту полей для `MetaBoxManager::saveFields()`.
  - Чтение значений — из `PostMetaName::Meta` (как в `BaseTemplate::render()`).
- **Методы:**
  ```php
  public function __construct();                  // заполнить $this->fields
  public function get_id(): string;               // 'lesson'
  public function get_name(): string;             // 'Урок'
  public function render( \WP_Post $post ): void;  // секционный вывод
  // get_fields() — наследуется из BaseTemplate
  ```
- **Готово:** метабокс показывает все секции с текущими значениями; пустой урок рендерится без ошибок.

##### T1.11 — `LessonMetaBoxController` (регистрация + рендер + сохранение метабокса)
- **Файл:** `inc/Controllers/LessonMetaBoxController.php` (новый, `extends BaseController
  implements ServiceInterface`, `use Authorizer`). Аналог `MetaBoxController`, но без резолвера.
- **Назначение:** навесить метабокс урока на все CPT `{key}_lessons`, отрисовать `LessonTemplate`,
  сохранить meta.
- **Связи / хуки:**
  - `add_action('add_meta_boxes', ...)` → регистрация через `MetaBoxRegistrar::add()` для всех
    `{key}_lessons` (собрать список из `SubjectRepository::readAll()` + `PostTypeResolver::lessons`).
  - `add_action('save_post', 'handleLessonSave')` → проверка `isLessonPostType`, `authorizePostSave(Nonce::SaveMeta, $id)`,
    `MetaBoxManager::saveFields($id, PostMetaName::Meta->value, $raw, $template->get_fields())`.
  - `wp_nonce_field( Nonce::SaveMeta->value, 'fs_lms_meta_nonce' )` в рендере (как в `MetaBoxController`).
- **Конструктор (DI):** `SubjectRepository`, `MetaBoxRegistrar`, `MetaBoxManager`, `LessonTemplate`.
- **Методы:**
  ```php
  public function register(): void;
  public function handleAddMetaBoxes(): void;
  public function renderMetaboxContent( \WP_Post $post ): void;
  public function handleLessonSave( int $post_id ): void;
  ```
- **Готово:** на экране редактирования урока виден метабокс; сохранение пишет корректный
  `fs_lms_meta`; чужие CPT (tasks/articles) не затронуты.

---

#### Слой Manager / Service / DTO

##### T1.12 — `LessonDTO`
- **Файл:** `inc/DTO/Course/LessonDTO.php` (новый, `readonly class`, как `TaskMetaDTO`).
- **Назначение:** типобезопасная передача данных урока между слоями (read/write).
- **Связи:** строится из `\WP_Post` + meta; используется `LessonManager`, `LessonAuthoringService`,
  позже — `GroupLesson`/кабинеты (Этап 2).
- **Поля:** `id`, `subjectKey`, `topic` (=title), `theoryHtml` (=content), `theoryArticleId`,
  `taskType`, `practice`, `independent`, `homework` (каждый бакет — `['content'=>..., 'task_ids'=>...]`),
  `authorId`, `status`.
- **Методы:**
  ```php
  public static function fromPost( \WP_Post $post, array $meta ): self;
  public static function fromArray( array $data ): self;
  public function toArray(): array;
  public function isEmpty(): bool;   // нет заданий ни в одном бакете (просто занятие)
  ```
- **Готово:** round-trip `fromPost()→toArray()` стабилен; пустой урок корректно отражается.

##### T1.13 — `LessonManager`
- **Файл:** `inc/Managers/LessonManager.php` (новый). По образцу `TaskManager`.
- **Назначение:** CRUD урока = пост (`PostManager`) + meta (`MetaBoxManager`); выборки банка.
- **Связи:** делегирует `PostManager` (insert/update/get/delete поста) и `MetaBoxManager`
  (`saveFields`/`saveMeta`). Никаких прямых `WP_Query`/`update_post_meta` (правило `CLAUDE.md`).
- **Методы:**
  ```php
  public function create( string $subjectKey, LessonDTO $dto ): int;     // post_type = lessons($key), status 'draft'
  public function update( int $lessonId, LessonDTO $dto ): bool;
  public function get( int $lessonId ): ?LessonDTO;
  public function getBankBySubject( string $subjectKey, array $args = [] ): array; // list<LessonDTO> для конструктора программы (Этап 2)
  public function delete( int $lessonId ): bool;
  ```
- **Готово:** урок создаётся/читается/обновляется через менеджер; `getBankBySubject()` отдаёт
  опубликованные уроки предмета.

##### T1.14 — `LessonAuthoringService`
- **Файл:** `inc/Services/Course/LessonAuthoringService.php` (новый, `Services/Course/`).
- **Назначение:** бизнес-логика авторинга: валидация и резолв вставляемых заданий, подготовка
  кандидатов для селектора, проверка принадлежности заданий предмету.
- **Связи:** используется `LessonCallbacks` (T1.15); читает задания через `PostManager`/
  репозиторий; знает соответствие `task_type` (term) → задания.
- **Методы:**
  ```php
  // $scope: 'mine' (по post_author, по умолчанию — Вариант 1) | 'subject' (весь банк предмета)
  public function getTaskCandidates(
      string $subjectKey, int $taskTypeTermId = 0, int $collectionTermId = 0,
      string $scope = 'mine', string $search = ''
  ): array;                                                                                  // list of {id, number, title, author}
  public function getArticles( string $subjectKey ): array;                                 // для ArticleRefField
  public function getTaskTypes( string $subjectKey ): array;                                // для TaskTypeField (через TaskTypeService)
  public function getCollections( string $subjectKey ): array;                              // термины таксономии-коллекции (T1.22)
  public function validateTaskIds( string $subjectKey, array $taskIds ): array;             // отфильтровать чужие/несуществующие
  ```
- **Готово:** селектор получает только задания нужного предмета (и типа, если задан);
  валидатор отбрасывает посторонние ID.

---

#### Слой Callbacks / Controller — AJAX селектора

##### T1.15 — `LessonCallbacks` (admin-AJAX)
- **Файл:** `inc/Callbacks/Course/LessonCallbacks.php` (новая подпапка `Callbacks/Course/`).
- **Назначение:** обработчики AJAX для конструктора бакетов.
- **Связи:** `use Authorizer; use Sanitizer;` (наследует `AjaxResponse` через `BaseController`).
  Делегирует `LessonAuthoringService`. Авторизация: `authorize(Nonce::AuthorLesson, Capability::ManageLMSAssignments)`.
- **Методы (имена строго `ajax{Case}` под `AjaxHook`):**
  ```php
  public function ajaxGetLessonTaskCandidates(): void; // subject_key + (опц.) task_type → success(list)
  public function ajaxGetLessonArticles(): void;       // subject_key → success(list)
  // типы заданий отдаёт существующий TaskCreationCallbacks::ajaxGetTaskTypes
  ```
- **Готово:** запросы возвращают корректные списки; неавторизованный/без права получает 403.

##### T1.16 — `LessonController` (регистрация AJAX)
- **Файл:** `inc/Controllers/LessonController.php` (новый, `extends AjaxController`).
- **Назначение:** зарегистрировать AJAX-хуки селектора (только для авторизованных).
- **Связи:** конструктор принимает `LessonCallbacks`; `ajaxActions()` возвращает пары
  `[AjaxHook, $callback]`. (Метабокс регистрирует отдельный `LessonMetaBoxController` — T1.11.)
- **Методы:**
  ```php
  protected function ajaxActions(): array; // [ [AjaxHook::GetLessonTaskCandidates, $cb], [AjaxHook::GetLessonArticles, $cb] ]
  ```
- **Готово:** хуки `wp_ajax_get_lesson_*` зарегистрированы.

---

#### Слой Init / Enqueue / JS / SCSS

##### T1.17 — Регистрация сервисов
- **Файл:** `inc/Init.php` (`getServices()`).
- **Назначение:** включить новые контроллеры в бутстрап DI.
- **Связи:** добавить `LessonController::class` и `LessonMetaBoxController::class` в массив
  (рядом с `MetaBoxController`/`TaskCreationController`). Оба реализуют `ServiceInterface`.
- **Готово:** `Init::run()` поднимает оба сервиса без ошибок DI (все зависимости — type-hinted).

##### T1.18 — Локализация и ассеты урока
- **Файл:** `inc/Core/Enqueue.php` (единственное место `wp_localize_script`).
- **Назначение:** на экранах `{key}_lessons` отдать фронту nonce + actions; подключить
  admin-бандл (он общий — отдельного бандла не нужно).
- **Связи:** добавить в `fs_lms_vars` (или отдельный объект) `nonces.authorLesson =
  Nonce::AuthorLesson->create()`; экшены уже идут через `AjaxHook::toJsArray()`. Гейт по
  `get_current_screen()->post_type` через `PostTypeResolver::isLessonPostType()`.
- **Готово:** на странице урока в консоли доступны `fs_lms_vars.nonces.authorLesson` и нужные `ajax_actions`.

##### T1.19 — Admin JS: конструктор бакетов + селектор заданий
- **Файлы (src):**
  - `src/js/admin/services/lesson-bucket-service.js` — AJAX + логика (jQuery, object-pattern).
  - `src/js/admin/modals/lesson-task-picker.js` — модалка выбора задания (UI-only,
    авто-загружается `modules/ui.js`).
- **Назначение:** добавлять/удалять/упорядочивать задания в бакетах, синхронизировать скрытые
  инпуты `fs_lms_meta[<bucket>][task_ids][]`.
- **Связи:** читает `fs_lms_vars.ajax_actions.getLessonTaskCandidates` и `.authorLesson`;
  инициализация в `admin.js` с guard'ом `if ($('.fs-lms-lesson-bucket').length) {...}`.
  Импорт `_types.js` для типов глобалов. **Никакого inline-JS** (правило `CLAUDE.md`).
- **Готово:** в метабоксе можно искать задание → добавить чип → сохранить → значения переживают reload.

##### T1.20 — Admin SCSS: вёрстка бакетов/чипов/модалки
- **Файл:** `src/scss/admin/components/_lesson-metabox.scss` (новый, импорт в `admin.scss`).
- **Назначение:** стили секций урока, чипов заданий, модалки селектора.
- **Связи:** только токены из `src/scss/admin/_variables.scss`; нет хардкод-значений и `style=""`
  (правила `CLAUDE.md`). Недостающие токены — сперва добавить в `_variables.scss`.
- **Готово:** метабокс выглядит консистентно с остальной админкой; `npx gulp styles:admin` без ошибок.

---

#### Слой переиспользования заданий (по мотивам Tutor LMS Content Bank)

##### T1.21 — «Создать задание» прямо из урока (переиспользование модалки)
- **Файлы:** `inc/Callbacks/Task/TaskCreationCallbacks.php` (доработка), `src/js/admin/...`
  (склейка), `templates/admin/components/modals/task-modal.php` (переиспользование, без копий).
- **Назначение:** реализовать путь «(б) создать новое» из T1.7 — препод создаёт реальный
  `{key}_tasks`, не уходя с урока, и сразу получает `task_id` для чипа.
- **Связи:**
  - Переиспользует существующую модалку и `TaskManager::createNewTask()` — **новую логику создания
    задания не плодим**.
  - Текущий `ajaxCreateTask()` отдаёт `redirect` на экран правки. Для урока нужен режим «вернуть
    id без редиректа»: добавить флаг (`context=lesson`) → `success(['id'=>$newId, 'number'=>..., 'title'=>...])`.
  - JS ловит ответ → добавляет чип в активный бакет; модалка предзаполняется `subject_key`
    (+ опц. `task_type`/коллекция из контекста урока).
- **Методы:** правка `TaskCreationCallbacks::ajaxCreateTask()` (ветка ответа по контексту).
- **Готово:** из бакета открывается модалка → создаётся задание в библиотеке → чип появляется
  без перезагрузки; задание видно и в общем списке заданий предмета.

##### T1.22 — «Коллекции»: тематическая таксономия заданий + фильтр в селекторе
- **Файлы:** конфиг таксономий (механизм `TaxonomyRepository` / `SubjectTaxonomyRegistrar` —
  **уже есть**), `LessonAuthoringService::getCollections()` (T1.14), JS-фильтр селектора (T1.19).
- **Назначение:** тематические наборы заданий («Циклы Python», «Рекурсия») поверх номеров —
  как Collections в Tutor. Препод тегает задания, селектор фильтрует по коллекции.
- **Связи:**
  - Коллекция = терм пользовательской таксономии на `{key}_tasks` (тот же путь, что текущие
    кастомные таксономии заданий) — **новый слой не нужен**.
  - `GetLessonTaskCandidates` уже принимает `collection` (T1.3); сервис добавляет WHERE по терму.
- **Готово:** в селекторе есть фильтр «коллекция»; выбор сужает список кандидатов; механика
  переиспользует существующие таксономии.

##### T1.23 — (опц., созревает на Этапе 2) Бейдж «используется в N»
- **Файл:** read-модель использования задания (сервис) + бейдж в списке заданий.
- **Назначение:** перед правкой общего задания препод видит «затронет N уроков/экзаменов»
  (фича Tutor: счётчик использований).
- **Связи:** считает ссылки на `task_id` в бакетах `group_lessons` (таблица — **Этап 2**) и в meta
  экзаменов (**Этап 4**). До появления `group_lessons` показывать нечего → **отложено до Этапа 2**.
- **Готово:** у задания виден счётчик использований; клик раскрывает список уроков/экзаменов.

##### T1.24 — Шаблон задания «условие + ответ + решение»
- **Файлы:** `inc/MetaBoxes/Templates/...` (+ `inc/Enums/TaskTemplate.php`), либо переиспользование
  `CodeTaskTemplate` / `TaskTextSolution`.
- **Назначение:** под пример «задачка на циклы Python» нужно поле **решения** помимо условия и
  ответа (`StandardTaskTemplate` его не имеет).
- **Связи:** новый шаблон встаёт в существующую систему (`TaskTemplate` enum + `TemplateRegistry`,
  фильтр `fs_lms_register_templates`) — урок его не знает, он живёт на стороне заданий.
- **Готово:** при «создать новое» из урока доступен тип с полями условие/ответ/решение.

---

### Порядок реализации (по зависимостям)

1. **T1.1** `PostTypeResolver::lessons` → база для всего.
2. **T1.5 + T1.6** регистрация CPT + права → есть редактируемый CPT.
3. **T1.2, T1.3** Nonce + AjaxHook → фундамент AJAX.
4. **T1.7–T1.10** поля + `LessonTemplate` → форма.
5. **T1.11** `LessonMetaBoxController` → метабокс рендерится и сохраняется (уже можно
   создать урок со свободным контентом — **половина DoD**).
6. **T1.12–T1.14** DTO + Manager + Service → программная модель урока.
7. **T1.15, T1.16** Callbacks + Controller → AJAX селектора.
8. **T1.18–T1.20** Enqueue + JS + SCSS → рабочий селектор «из библиотеки» по номеру.
9. **T1.24 → T1.21** шаблон с решением → «создать новое» из урока (**вторая половина DoD**).
10. **T1.22** коллекции-фильтр → удобная навигация по банку.
11. **T1.17** регистрация сервисов → можно делать на шаге 5 (как только появятся контроллеры).
12. **T1.23** бейдж «используется в N» — **отложено до Этапа 2** (нужна таблица `group_lessons`).

> Контрольная точка после шага 5: урок-«просто занятие» (тема+теория, без заданий) полностью
> работает. Шаги 6–8 добавляют вставку заданий из библиотеки.

### Критерии приёмки этапа (проверка вручную)

- [ ] Для каждого предмета в системе зарегистрирован CPT `{key}_lessons`.
- [ ] Преподаватель (роль `FSTeacher`, право `manage_lms_assignments`) создаёт и редактирует урок.
- [ ] Урок сохраняется только с темой и теорией (без заданий) — без ошибок.
- [ ] В бакеты «практика/СР/ДЗ» добавляются существующие задания предмета через селектор;
      сохранённые ссылки (`task_id`) переживают перезагрузку.
- [ ] «Создать новое» из бакета создаёт реальный `{key}_tasks` и прикрепляет ссылкой без перезагрузки.
- [ ] Прикрепление — ссылка: правка задания в библиотеке отражается во всех уроках, где оно стоит.
- [ ] Селектор по умолчанию показывает «мои задания» (`post_author`), переключается на весь банк предмета.
- [ ] При заданном «типе заданий» селектор предлагает только задания этого типа.
- [ ] (если включено T1.22) фильтр по «коллекции» сужает список кандидатов.
- [ ] Опц. теория-ссылка на статью (`theory_article_id`) сохраняется и читается.
- [ ] Данные лежат в `post_title` / `post_content` / `fs_lms_meta` — таблиц на этом этапе не добавлено.
- [ ] `npm run lint:js` и `npx gulp build` проходят.

### Открытые вопросы этапа

**Решено в этой итерации** (см. «Решения и допущения» 5–8): задания — ссылки на `{key}_tasks`
(Вариант А, не inline); видимость — весь предмет с фильтром «мои» по умолчанию (Вариант 1);
«коллекции» = пользовательская таксономия; нужен шаблон задания «условие + ответ + решение».

1. **Авторинг урока: wp-admin или фронт?** Принято: wp-admin (консистентно с tasks/articles).
   Если преподаватель не должен видеть wp-admin — фронтовый авторинг выносится отдельной задачей
   (вне Этапа 1). Связано с [`Courses.md` §4](./Courses.md) (преподаватель — фронтовая роль).
2. **Теория = `post_content` vs meta `theory`.** Принято: `post_content` (нативный редактор).
   Расхождение с буквальным списком полей в [`Courses.md` §2](./Courses.md) — осознанное (нативнее).
3. **Запрос терминов/статей из поля метабокса.** Поля не должны звать WP API напрямую
   (правило слоёв). Решение: списки прокидываются из `LessonAuthoringService` через
   `LessonTemplate`/конструктор поля. Финализировать при реализации T1.8/T1.9.
4. **`map_meta_cap` для `fs_lms_content`.** Проверить на dev, что производные мета-права
   действительно сводятся к `manage_lms_assignments` у `FSTeacher`; иначе — явный маппинг в
   `RoleManager` (T1.6).

---

## Этап 2 — Программа группы: расписание, доставка, кокпит

### Цель этапа

Собрать «курс группы» из уроков (Этап 1), управлять доступом во времени и дать преподавателю
**фронт-страницу группы (кокпит)**. Структура `fs_lms_group_lessons` заменяет текстовое поле
`groups.schedule`.

### Готово, когда (Definition of Done)

- Преподаватель на фронт-странице группы формирует программу: добавляет уроки из банка предмета,
  задаёт **порядок** (drag-drop), **дату занятия** и **преподавателя занятия**.
- Управляет **видимостью** урока: `hidden` / `open` / `archived`.
- Ученик-член группы видит материалы **видимого** урока (`open`/`archived`), включая бэк-каталог;
  `hidden` недоступен. Отчисленный по умолчанию сохраняет read-only доступ к пройденному.
- Действия (добавление в программу, изменение расписания, публикация) пишутся в **ленту
  активности группы**; ученик/родитель видят отфильтрованный срез (свои события + публикации).

### Зависимости

- **Этап 1** (CPT `{key}_lessons`, `LessonManager`, `LessonDTO`) — программа ссылается на уроки.
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
-- Программа группы = «курс» + расписание + доставка (заменяет groups.schedule text)
CREATE TABLE {prefix}fs_lms_group_lessons (
  id                 int unsigned        NOT NULL AUTO_INCREMENT,
  group_id           smallint unsigned   NOT NULL,            -- → fs_lms_groups.id
  lesson_id          bigint unsigned     NOT NULL,            -- → CPT {key}_lessons (post ID)
  position           smallint unsigned   NOT NULL DEFAULT 0,
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
  KEY lesson_id (lesson_id),               -- для бейджа «используется в N» (T1.23 / T2.25)
  KEY group_position (group_id, position)
);

-- Лента активности группы (новый лог-канал, один на все группы, разрез по group_id)
CREATE TABLE {prefix}fs_lms_course_activity_log (
  id            int unsigned        NOT NULL AUTO_INCREMENT,
  group_id      smallint unsigned   NOT NULL,
  actor_user_id bigint(20) unsigned DEFAULT NULL,
  actor_role    varchar(50)         DEFAULT NULL,
  action        varchar(40)         NOT NULL,    -- lesson_added_to_program | lesson_removed | schedule_changed | lesson_published | lesson_hidden | recording_attached(Э5) | submission_*(Э3) | attempt_*(Э4)
  entity_type   varchar(30)         DEFAULT NULL,
  entity_id     varchar(100)        DEFAULT NULL,
  is_public     tinyint(1)          NOT NULL DEFAULT 1,  -- виден ли срез ученику/родителю
  created_at    datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY group_created (group_id, created_at),
  KEY actor_user_id (actor_user_id)
);
```

### Решения и допущения этапа

1. **Программа = факт-таблица `fs_lms_group_lessons`**, заменяет `groups.schedule` text. Миграция
   старого текста не нужна — начинаем с чистого расписания ([`Courses.md` §7 #3](./Courses.md)).
   `groups.schedule` пока **оставляем** (не дропаем) до подтверждения, что нигде не читается.
2. **M:N урок↔группа.** Один урок-определение стоит в программах многих групп; дата/видимость/
   преподаватель занятия — на строке `group_lesson`, не на уроке.
3. **Кокпит — фронт-страница** (`template_redirect` + `ThemeCompatService::header()/footer()`),
   гейт `groups.teacher_id == current_user_id` **ИЛИ** `Capability::Admin`. Тяжёлый CRUD (создание
   групп, зачисление, PII) остаётся в админке ([`Courses.md` §4](./Courses.md)).
4. **Доступ ученика = членство (`student_record`), а не «active + open».** Зачисление — грант доступа
   к материалам группы; единый `LessonAccessPolicy` (T2.26) учитывает статус + даты + политику
   ретеншена. Чтение переживает отчисление (`retain`, дефолт); сдача — только пока `active`. Гейт
   доменный, **не** по роли/capability. Детали — [`Courses.md` §4](./Courses.md).
5. **Лента — новый лог-канал поверх существующей инфраструктуры** (`LogEventDispatcher` →
   `CourseActivitySubscriber` → `CourseActivityLogWriter` → `CourseActivityLogRepository`), один
   канал, разрез по `group_id` ([`Courses.md` §7 #8](./Courses.md)). Срез ученику — `is_public=1` +
   свои события. Имена акторов резолвит `LogNameResolver`.
6. **`homework_due_at` / `allow_late` / `recording_url`** создаются в DDL сейчас, но потребляются
   позже (Этап 3 — дедлайны ДЗ; Этап 5 — записи). На Этапе 2 в UI — только дата занятия и видимость.

---

### Задачи

#### Слой Enums / фундамент

##### T2.1 — `TableName`: +2 таблицы
- **Файл:** `inc/Enums/TableName.php`.
- **Добавить:** `case GroupLessons = 'fs_lms_group_lessons';`, `case CourseActivityLog = 'fs_lms_course_activity_log';`
- **Связи:** используются репозиториями (T2.8–T2.9), миграцией (T2.6–T2.7), `LogChannel` (T2.5).
- **Готово:** `TableName::GroupLessons->prefixed()` отдаёт имя с префиксом.

##### T2.2 — `Nonce`: программа + видимость
- **Файл:** `inc/Enums/Nonce.php`.
- **Добавить:** `case SaveSchedule = 'fs_lms_save_schedule';` (add/remove/reorder/дата),
  `case SetLessonVisibility = 'fs_lms_set_lesson_visibility';`
- **Связи:** `ProgramCallbacks` (T2.19) — `authorize(Nonce::SaveSchedule, Capability::ManageLMSAssignments)`;
  локализация в `Enqueue` для фронта кокпита.
- **Готово:** nonces создаются/проверяются.

##### T2.3 — `AjaxHook`: операции программы
- **Файл:** `inc/Enums/AjaxHook.php` (секция `// ==== Программа группы ====`).
- **Добавить:**
  ```php
  case AddLessonToProgram      = 'add_lesson_to_program';
  case RemoveLessonFromProgram = 'remove_lesson_from_program';
  case ReorderProgram          = 'reorder_program';      // массив id в новом порядке
  case SaveLessonSchedule      = 'save_lesson_schedule'; // scheduled_at + teacher_user_id
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

##### T2.5 — `LogChannel` + `LogEvent`: канал активности
- **Файлы:** `inc/Enums/LogChannel.php`, `inc/Enums/LogEvent.php`.
- **Добавить:**
  - `LogChannel::CourseActivity` → `label()` «Журнал активности групп», `tableName()` → `TableName::CourseActivityLog`.
  - `LogEvent`: `LessonAddedToProgram`, `LessonRemovedFromProgram`, `ScheduleChanged`,
    `LessonPublished`, `LessonHidden` (на будущее — `RecordingAttached`, `SubmissionMade`…).
- **Связи:** диспетчеризуются из `ScheduleService`/`LessonVisibilityService`; ловит `CourseActivitySubscriber`.
- **Готово:** enum-кейсы доступны; канал виден инфраструктуре логов.

---

#### Слой Migrations

##### T2.6 / T2.7 — Таблицы `group_lessons` и `course_activity_log`
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

##### T2.9 — `Log/CourseActivityLogRepository`
- **Файл:** `inc/Repositories/WPDBRepositories/Log/CourseActivityLogRepository.php` (по образцу `Log/AuditLogRepository`).
- **Назначение:** запись и чтение ленты группы; неизменяемый журнал (`update()` бросает).
- **Связи:** `TableName::CourseActivityLog`; `CourseActivityLogDTO`/`InputDTO` (T2.11).
- **Методы:**
  ```php
  public function create( CourseActivityLogInputDTO $dto ): int;
  public function listByGroup( int $groupId, int $page, int $perPage ): array;            // лента кокпита
  public function listByGroupPublic( int $groupId, int $actorUserId, int $page, int $perPage ): array; // срез ученика: is_public OR actor=self
  public function countByGroup( int $groupId ): int;
  ```
- **Готово:** лента группы листается с пагинацией; срез ученика отдаёт публичные + свои события.

---

#### Слой DTO

##### T2.10 — `GroupLessonDTO` + `GroupLessonInputDTO`
- **Файл:** `inc/DTO/Course/GroupLessonDTO.php`, `inc/DTO/Course/GroupLessonInputDTO.php` (`readonly`).
- **Поля:** `id`, `groupId`, `lessonId`, `position`, `scheduledAt`, `teacherUserId`, `visibility`,
  `openedAt`, `homeworkDueAt`, `allowLate`, `recordingUrl`, `createdByUserId`, `updatedByUserId`.
- **Методы:** `fromArray()`, `toArray()` (Input — только пишущие поля).
- **Готово:** round-trip стабилен; Input даёт массив для `$wpdb->insert`.

##### T2.11 — `CourseActivityLogDTO` + `InputDTO` + `CourseActivityEvent`
- **Файлы:** `inc/DTO/Log/CourseActivityLogDTO.php`, `inc/DTO/Log/CourseActivityLogInputDTO.php`,
  `inc/DTO/Log/Events/CourseActivityEvent.php` (реализует `LogEventInterface`, как `EntityChangedEvent`).
- **Назначение:** read/write записи ленты + payload события для диспетчера.
- **Поля Event:** `groupId`, `actorUserId`, `action`, `entityType`, `entityId`, `isPublic`.
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
- **Назначение:** смена видимости (`hidden`/`open`/`archived`), фиксация `opened_at` при первой
  публикации; доменный гейт доступа ученика.
- **Связи:** `GroupLessonRepository`, `StudentRecordRepository::existsActive()`,
  `LogEventDispatcherInterface` (`LessonPublished`/`LessonHidden`).
- **Методы:**
  ```php
  public function setVisibility( int $groupLessonId, string $visibility, int $actorUserId ): void;
  // Доступ ученика к уроку — НЕ здесь: см. LessonAccessPolicy (T2.26). Гейт по членству
  // (status + даты + политика retain), а НЕ по бинарному `active` + visibility.
  ```
- **Готово:** публикация/скрытие работают и логируются; доступ ученика резолвит `LessonAccessPolicy` (T2.26).

##### T2.14 — `CourseActivityLogWriter`
- **Файл:** `inc/Services/Log/CourseActivityLogWriter.php` (по образцу существующих `*LogWriter`).
- **Назначение:** событие → `CourseActivityLogInputDTO` (резолв `actor_role`) → `repo->create()`.
- **Связи:** `CourseActivityLogRepository`; вызывается из `CourseActivitySubscriber`.
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

##### T2.16 — `CourseActivitySubscriber`
- **Файл:** `inc/Controllers/Subscribers/CourseActivitySubscriber.php` (по образцу `EnrollmentAuditSubscriber`).
- **Назначение:** подписать обработчик на `LogEvent::*` программы/видимости → `CourseActivityLogWriter`.
- **Связи:** `LogEventDispatcherInterface`, `CourseActivityLogWriter`. Реализует `ServiceInterface`.
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
    + `LogNameResolver::personName`), лента (`CourseActivityLogRepository::listByGroup` + `LogNameResolver`).
- **Готово:** преподаватель открывает свою группу, видит программу/ростер/ленту; чужую — нет.

##### T2.19 — `Callbacks/Course/ProgramCallbacks`
- **Файл:** `inc/Callbacks/Course/ProgramCallbacks.php` (`use Authorizer; use Sanitizer;`).
- **Назначение:** AJAX-обработчики операций программы и видимости.
- **Связи:** делегирует `ScheduleService`/`LessonVisibilityService`; `GroupAccessGuard` для проверки
  владения группой (nonce+cap проверяют право, guard — принадлежность конкретной группы).
- **Методы:** `ajaxAddLessonToProgram`, `ajaxRemoveLessonFromProgram`, `ajaxReorderProgram`,
  `ajaxSaveLessonSchedule`, `ajaxSetLessonVisibility`, `ajaxGetGroupProgram`, `ajaxGetGroupActivity`.
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
  `active`), `StudentRecordRepository` (все группы ученика / детей родителя), `LessonViewDTO` (резолв задач из бакетов).
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
- **Добавить:** `ScheduleController::class`, `GroupCockpitController::class`, `CourseActivitySubscriber::class`.
- **Готово:** `Init::run()` поднимает их без ошибок DI.

##### T2.25 — Бейдж «используется в N» (созревание T1.23)
- **Файлы:** read-модель использования + бейдж в списке уроков банка (Этап 1).
- **Назначение:** теперь, когда есть `group_lessons`, показать, в скольких группах стоит урок;
  (задания — `GroupLessonRepository::countUsageByLesson` через бакеты — дозреет с учётом meta заданий).
- **Связи:** `GroupLessonRepository::countUsageByLesson`. Полный охват (уроки+экзамены) — после Этапа 4.
- **Готово:** у урока в банке виден счётчик использований в группах.

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

### Порядок реализации (по зависимостям)

1. **T2.1, T2.6/T2.7** — TableName + миграция → таблицы есть.
2. **T2.10, T2.8** — DTO + `GroupLessonRepository` → программа читается/пишется.
3. **T2.2, T2.3, T2.12, T2.13, T2.15** — Nonce/AjaxHook + сервисы программы/видимости/гейт.
4. **T2.17, T2.19** — AJAX-контроллер + коллбеки → операции программы работают.
5. **T2.4, T2.18, T2.20, T2.22, T2.23** — страница + кокпит + шаблон + JS + SCSS → **половина DoD**
   (преподаватель формирует программу/расписание/видимость).
6. **T2.5, T2.11, T2.14, T2.16** — лог-канал → действия пишутся в ленту (можно делать параллельно с 3–4).
7. **T2.26, T2.27** — `LessonAccessPolicy` + гейт кабинета (членство, ретеншн) — основа доступа ученика.
8. **T2.21** — срез ученику (через policy) → **вторая половина DoD** (ученик видит видимые уроки).
9. **T2.24** — регистрация сервисов (по мере появления контроллеров).
10. **T2.25** — бейдж использований.

### Критерии приёмки этапа (проверка вручную)

- [ ] Созданы таблицы `fs_lms_group_lessons`, `fs_lms_course_activity_log`.
- [ ] Преподаватель открывает `/group/?gid=<своя>`; чужую группу — нет (редирект/403).
- [ ] Добавление урока из банка, drag-drop порядок, дата занятия — сохраняются.
- [ ] Видимость `hidden`/`open`/`archived` переключается; `opened_at` ставится при первой публикации.
- [ ] Ученик-член видит видимые уроки (вкл. бэк-каталог); `hidden` недоступен по прямому URL.
- [ ] Отчисленный (политика `retain`) видит архив до `expelled_at`; при `block` — кабинет/доступ закрыт.
- [ ] Поздний ученик видит прошлые уроки без «фантомных просрочек».
- [ ] Действия пишутся в ленту группы; ученик/родитель видят только `is_public` + свои события.
- [ ] Ростер группы и лента рендерят человекочитаемые имена (`LogNameResolver`).
- [ ] `groups.schedule` не используется для нового расписания (источник — `group_lessons`).
- [ ] `npm run lint:js` и `npx gulp build` проходят.

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

- **Этап 1** — `task_id` в сдаче ссылается на `{key}_tasks`; тема работы резолвится через `LessonManager`.
- **Этап 2** — `fs_lms_group_lessons` (`group_lesson_id`, `homework_due_at`, `allow_late`),
  кокпит (панель проверки), лента активности (`CourseActivityEvent` + subscriber), `GroupAccessGuard`.
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
  work_type         enum('practice','independent','homework') NOT NULL,
  task_id           bigint unsigned     DEFAULT NULL,        -- сдача по конкретному заданию (NULL = по бакету)
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
   «Выдано» = открытый урок с бакетом (Этап 2 `visibility=open`), а не пред-созданная строка.
   Статус `assigned` зарезервирован под опц. пред-назначение / индив. продление дедлайна
   (отдельный шаг, см. [Открытые вопросы](#открытые-вопросы-этапа-3) #1).
2. **Одна строка на (ученик × group_lesson × work_type × task_id).** Дедуп — на уровне сервиса
   (`existsForWork` перед insert; `returned` → повторная сдача обновляет ту же строку в `submitted`).
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

##### T3.3 — `WorkType` enum
- **Файл:** `inc/Enums/WorkType.php` (новый).
- **Кейсы:** `Practice='practice'`, `Independent='independent'`, `Homework='homework'` + `label()`.
- **Связи:** общий словарь для бакетов урока (Этап 1) и `submissions.work_type`; ретрофит ключей
  бакетов Этапа 1 на этот enum (необязательно, но желательно — единый источник значений).
- **Готово:** бакеты и сдачи используют один enum.

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
- **Связи:** диспетчеризуются из `SubmissionService`; ловит **тот же** `CourseActivitySubscriber`
  (Этап 2 T2.16) — расширить его подписки; payload — существующий `CourseActivityEvent` (Этап 2 T2.11),
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
  public function findForWork( int $studentPersonId, int $groupLessonId, string $workType, ?int $taskId ): ?SubmissionDTO; // дедуп
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
  `GroupAccessGuard` (гейт), `PersonRepository` (резолв person), `LogEventDispatcherInterface` (события).
- **Методы:**
  ```php
  public function submit( int $studentPersonId, int $groupLessonId, string $workType, ?int $taskId,
                          string $answerText, ?string $fileKey ): int;
  // LessonAccessPolicy::canSubmit (active + opened_at>=enrolled_at); снапшот due_at;
  // allow_late=0 && now>due → отказ; dedupe; status submitted; событие SubmissionMade
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
- **Файлы:** `templates/frontend/...` — форма сдачи в открытом уроке: per задание/бакет — textarea
  ответа + file input + кнопка; отображение статуса/балла/комментария после проверки.
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
- **Файлы:** `inc/Init.php` (+ `SubmissionController::class`); `CourseActivitySubscriber` (Этап 2 T2.16) —
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
5. **Балл по заданию vs по бакету.** На Этапе 3 поддержано и то, и другое (`task_id` NULL = по бакету).
   Политика максимального балла/агрегации в журнале — уточнить при UI журнала (T3.18).
