# Документация плагина FS LMS

## Оглавление

1. [Архитектура плагина](#архитектура-плагина)
2. [DI Контейнер](#di-контейнер)
3. [Flow создания таксономии](#flow-создания-таксономии)
4. [Flow создания страниц](#flow-создания-страниц)
5. [Менеджеры и Регистраторы](#менеджеры-и-регистраторы)
6. [Сервисы](#сервисы)
7. [DTO (Data Transfer Objects)](#dto-data-transfer-objects)
8. [Enum (Перечисления)](#enum-перечисления)
9. [Трейты](#трейты)
10. [Контроллеры и Callbacks](#контроллеры-и-callbacks)
11. [Репозитории](#репозитории)

---

## Архитектура плагина

Плагин построен на основе **паттернов проектирования**:

- **Dependency Injection (DI)** — внедрение зависимостей через конструктор
- **Service Locator** — централизованное получение сервисов через контейнер
- **Repository** — абстракция доступа к данным
- **DTO** — типобезопасная передача данных между слоями
- **Controller-Callback** — разделение логики обработки и регистрации хуков
- **Manager-Registrar** — разделение низкоуровневой регистрации и конфигурации

### Структура директорий

```
inc/
├── Callbacks/          # Методы-обработчики для WordPress хуков
├── Contracts/          # Интерфейсы (ServiceInterface, etc.)
├── Controllers/        # Контроллеры (оркестрация компонентов)
├── Core/               # Ядро (Container, Activate, Deactivate, Enqueue)
├── DTO/                # Data Transfer Objects
├── Enums/              # Перечисления (константы)
├── Managers/           # Низкоуровневые менеджеры регистрации
├── MetaBoxes/          # Конфигурации метабоксов
├── Registrars/         # Регистраторы (фасады для менеджеров)
├── Repositories/       # Репозитории для работы с данными
├── Services/           # Бизнес-логика и сложные операции
└── Shared/             # Общие компоненты (Traits)
    └── Traits/
```

---

## DI Контейнер

**Расположение:** `inc/Core/Container.php`

### Назначение

DI-контейнер с **автопроводкой (autowiring)** для управления зависимостями. Автоматически анализирует конструкторы классов и рекурсивно создаёт все необходимые зависимости.

### Реализованные паттерны

- **Service Locator** — централизованное получение сервисов
- **Lazy Singleton** — объекты создаются один раз при первом запросе
- **Dependency Injection** — автоматическое внедрение зависимостей через конструктор

### Как использовать

```php
// Создание контейнера
$container = new Container();

// Получение сервиса с автоматическим внедрением зависимостей
$admin = $container->get(AdminController::class);

// Контейнер автоматически создаст все зависимости:
// - MenuRegistrar
// - SettingsRegistrar
// - AdminCallbacks
// - SubjectsMenuBuilder
```

### Механизм работы

1. **Проверка кэша** — если объект уже создан, возвращает его
2. **Анализ класса** через Reflection API
3. **Проверка возможности создания** (не абстрактный, не интерфейс)
4. **Рекурсивное создание зависимостей** для параметров конструктора
5. **Создание объекта** с внедрёнными зависимостями
6. **Кэширование** для последующих вызовов

### Пример использования в Init.php

```php
public static function run(): void {
    $container = new Container();

    foreach (self::getServices() as $class) {
        $service = $container->get($class);

        if ($service instanceof ServiceInterface) {
            $service->register();
        }
    }
}
```

### Ограничения

- Не поддерживает встроенные типы (string, int, bool) без значений по умолчанию
- Не поддерживает union-типы и mixed
- Все зависимости должны быть классами с именованными типами

---

## Flow создания новых типов

Создание объектов нового типа (предметы, таксономии, метабоксы и т.д.) проходит через **5 слоёв архитектуры**. Далее пример создания таксономий:

### 1. Репозиторий (`TaxonomyRepository`)

**Файл:** `Inc/Repositories/WPDBRepositories/TaxonomyRepository.php`

**Назначение:** CRUD-операции с данными таксономий в БД (таблица `wp_options`).

```php
class TaxonomyRepository {
    private string $option_name = OptionName::TAXONOMY->value;

    // Сохранение таксономии
    public function save(TaxonomyDataDTO $dto): bool {
        $all = $this->getRaw();
        $all[$dto->subject_key][$dto->slug] = $dto->toArray();
        return update_option($this->option_name, $all);
    }

    // Получение всех таксономий предмета
    public function getBySubject(string $subject_key): array {
        // Возвращает TaxonomyDataDTO[]
    }

    // Удаление таксономии
    public function remove(string $subject_key, string $tax_slug): bool {
        // ...
    }
}
```

**Ответственность:**
- Работа с опцией `fs_lms_custom_taxonomies` в `wp_options`
- Преобразование массивов в DTO и обратно
- Группировка по предметам
- Каскадное удаление

### 2. Менеджер (`TaxonomyManager`)

**Файл:** `inc/Managers/TaxonomyManager.php`

**Назначение:** Низкоуровневая регистрация таксономий через WordPress API.

```php
class TaxonomyManager {
    public function register(array $taxonomies): void {
        add_action('init', function () use ($taxonomies) {
            foreach ($taxonomies as $slug => $data) {
                register_taxonomy(
                    $slug,           // Слаг таксономии
                    $data['post_types'],
                    $data['args']
                );
            }
        });
    }
}
```

**Ответственность:**
- Инкапсуляция вызова `register_taxonomy()`
- Регистрация через хук `init`
- Не содержит бизнес-логики

### 3. Регистратор (`SubjectTaxonomyRegistrar`)

**Файл:** `inc/Registrars/SubjectTaxonomyRegistrar.php`

**Назначение:** Фасад с Fluent Interface для формирования конфигураций таксономий.

```php
class SubjectTaxonomyRegistrar {
    private TaxonomyManager $manager;
    private array $taxonomies = array();

    // Добавление таксономии с полным контролем
    public function addTaxonomy(string $slug, array|string $post_types, array $args): self {
        $this->taxonomies[$slug] = [
            'post_types' => $post_types,
            'args' => $args
        ];
        return $this; // Fluent Interface
    }

    // Хелпер для стандартной иерархической таксономии
    public function addStandardTaxonomy(
        string $slug,
        array|string $post_types,
        string $plural,
        string $singular,
        string $display_type = 'select'
    ): self {
        return $this->addTaxonomy($slug, $post_types, [
            'labels' => [...],
            'hierarchical' => false,
            'show_ui' => true,
            'meta_box_cb' => $this->buildMetaBoxCallback($display_type),
            // ...
        ]);
    }

    // Выполнение регистрации
    public function register(): void {
        $this->manager->register($this->taxonomies);
    }
}
```

**Ответственность:**
- Накопление конфигураций перед регистрацией
- Предоставление хелперов для типовых таксономий
- Fluent Interface для цепочки вызовов

### 4. Callbacks (`TaxonomySettingsCallbacks`)

**Файл:** `inc/Callbacks/TaxonomySettingsCallbacks.php`

**Назначение:** Обработка AJAX-запросов для CRUD операций с таксономиями.

```php
class TaxonomySettingsCallbacks {
    use AjaxResponse; // Трейт для унифицированных ответов

    public function __construct(
        private readonly TaxonomyRepository $taxonomies
    ) {}

    // Создание таксономии
    public function ajaxStoreTaxonomy(): void {
        // Валидация данных из $_POST
        $dto = TaxonomyDataDTO::fromArray($slug, $data, $subject_key);

        // Сохранение через репозиторий
        $result = $this->taxonomies->save($dto);

        // Унифицированный ответ
        $this->respond($result, 'Ошибка сохранения');
    }

    // Обновление таксономии
    public function ajaxUpdateTaxonomy(): void {
        // ...
    }

    // Удаление таксономии
    public function ajaxDeleteTaxonomy(): void {
        // ...
    }
}
```

**Ответственность:**
- Приём и валидация данных из AJAX-запроса
- Создание DTO из входящих данных
- Вызов методов репозитория
- Отправка JSON-ответа

### 5. Контроллер (`SubjectController`)

**Файл:** `inc/Controllers/SubjectController.php`

**Назначение:** Оркестрация всех компонентов и регистрация в Init через DI.

```php
class SubjectController extends AjaxController {
    use NumericSorter;

    public function __construct(
        private readonly SubjectRepository $subjects,
        private readonly SubjectCPTRegistrar $cpt_registrar,
        private readonly SubjectTaxonomyRegistrar $tax_registrar,
        private readonly TaxonomyRepository $taxonomies,
        private readonly TaxonomySettingsCallbacks $taxonomy_callbacks,
        // ... другие зависимости
    ) {
        parent::__construct();
    }

    public function register(): void {
        // Регистрация CPT и таксономий
        $this->registerCptsAndTaxonomies();

        // Регистрация AJAX-хуков
        $this->registerAjaxHooks();

        // Настройка сортировки терминов
        $this->setupTermSorting();
    }

    private function registerCptsAndTaxonomies(): void {
        foreach ($this->subjects->readAll() as $subject) {
            $this->registerForSubject($subject);
        }

        // Выполнение отложенной регистрации
        $this->cpt_registrar->register();
        $this->tax_registrar->register();
    }

    protected function ajaxActions(): array {
        return [
            [AjaxHook::StoreTaxonomy, $this->taxonomy_callbacks],
            [AjaxHook::UpdateTaxonomy, $this->taxonomy_callbacks],
            [AjaxHook::DeleteTaxonomy, $this->taxonomy_callbacks],
        ];
    }
}
```

**Ответственность:**
- Внедрение всех зависимостей через конструктор
- Координация регистраторов и callbacks
- Регистрация в Init.php через массив сервисов

### Схема потока

```
┌─────────────────┐
│  AJAX Request   │
│  (JavaScript)   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Controller     │ ←─── DI Container создаёт все зависимости
│  (SubjectCtrl)  │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Callbacks      │ ←─── Обрабатывает запрос, создаёт DTO
│  (TaxonomyCb)   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Repository     │ ←─── Сохраняет в wp_options
│  (TaxonomyRepo) │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Registrar      │ ←─── Формирует конфигурацию
│  (TaxRegistrar) │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Manager        │ ←─── Вызывает register_taxonomy()
│  (TaxManager)   │
└─────────────────┘
```

---

## Flow создания страниц

Создание новой административной страницы включает **4 основных компонента**:

### 1. View (Template)

**Расположение:** `templates/admin/`

**Пример:** `templates/admin/groups.php`

```php
<?php
/**
 * Template: Groups Page
 *
 * @var array $groups Данные групп
 */
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Управление группами</h1>

    <button id="open-group-modal" class="page-title-action">
        Добавить группу
    </button>

    <!-- Таблица групп -->
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Название</th>
                <th>Курс</th>
                <th>Студентов</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($groups as $group): ?>
            <tr>
                <td><?php echo esc_html($group->name); ?></td>
                <td><?php echo esc_html($group->course); ?></td>
                <td><?php echo count($group->students); ?></td>
                <td>
                    <button class="edit-group" data-id="<?php echo $group->id; ?>">
                        Изменить
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Подключение модального окна -->
<?php include_once __DIR__ . '/components/modals/group-modal.php'; ?>
```

**Ответственность:**
- Отображение HTML-разметки страницы
- Получение данных через контроллер
- Подключение модальных окон

### 2. AdminCallbacks

**Файл:** `inc/Callbacks/AdminCallbacks.php`

**Назначение:** Методы-коллбеки для отрисовки страниц.

```php
class AdminCallbacks {
    use TemplateRenderer; // Трейт для рендеринга шаблонов

    public function __construct(
        private readonly StudentGroupService $group_service
    ) {}

    // Отрисовка страницы групп
    public function groupsPage(): void {
        $groups = $this->group_service->getAllGroups();

        $this->render('admin/groups.php', [
            'groups' => $groups
        ]);
    }

    // Отрисовка страницы настроек
    public function settingsPage(): void {
        $this->render('admin/settings.php');
    }
}
```

**Ответственность:**
- Получение данных от сервисов/репозиториев
- Передача данных в шаблон
- Вызов трейта TemplateRenderer для рендеринга

### 3. AdminController

**Файл:** `inc/Controllers/AdminController.php`

**Назначение:** Регистрация страниц меню в WordPress.

```php
class AdminController extends BaseController implements ServiceInterface {
    public function __construct(
        private readonly MenuRegistrar $menu_registrar,
        private readonly SettingsRegistrar $settings_registrar,
        private readonly AdminCallbacks $callbacks,
        private readonly SubjectsMenuBuilder $subjects_menu_builder
    ) {
        parent::__construct();
    }

    public function register(): void {
        $pages = $this->buildMainPages();
        $subpages = $this->buildAllSubPages();

        $this->menu_registrar->addPages($pages)
                            ->addSubPages($subpages)
                            ->register();

        // Регистрация настроек
        $auth_settings = [
            [
                'option_group' => 'fs_lms_auth_group',
                'option_name' => 'fs_lms_auth_settings',
                'callback' => null,
            ],
        ];

        $this->settings_registrar->addSettings($auth_settings)->register();
    }

    private function buildMainPages(): array {
        return [
            [
                'page_title' => 'FS LMS Dashboard',
                'menu_title' => 'FS LMS',
                'capability' => Capability::ADMIN->value,
                'menu_slug' => MenuSlug::MAIN->value,
                'callback' => [$this->callbacks, 'adminDashboard'],
                'icon_url' => 'dashicons-welcome-learn-more',
                'position' => 4,
            ],
        ];
    }

    private function buildAllSubPages(): array {
        return [
            // Страница групп
            [
                'parent_slug' => MenuSlug::MAIN->value,
                'page_title' => 'Управление группами',
                'menu_title' => 'Группы',
                'capability' => Capability::ADMIN->value,
                'menu_slug' => 'fs_lms_groups',
                'callback' => [$this->callbacks, 'groupsPage'],
            ],
            // Страница пользователей
            [
                'parent_slug' => MenuSlug::MAIN->value,
                'page_title' => 'Список пользователей',
                'menu_title' => 'Пользователи',
                'capability' => Capability::ADMIN->value,
                'menu_slug' => 'fs_lms_userlist',
                'callback' => [$this->callbacks, 'userlistPage'],
            ],
        ];
    }
}
```

**Ответственность:**
- Конфигурация пунктов меню
- Привязка callbacks к страницам
- Регистрация через MenuRegistrar

### 4. Модальное окно (если требуется)

#### View модального окна

**Файл:** `templates/admin/components/modals/group-modal.php`

```php
<?php
/**
 * Modal: Group Editor
 */
?>

<div id="group-modal" class="fs-modal" style="display:none;">
    <div class="fs-modal-overlay"></div>
    <div class="fs-modal-content">
        <header class="fs-modal-header">
            <h2>Редактирование группы</h2>
            <button class="close-modal">&times;</button>
        </header>

        <form id="group-form">
            <input type="hidden" name="group_id" value="">

            <div class="form-field">
                <label>Название группы</label>
                <input type="text" name="name" required>
            </div>

            <div class="form-field">
                <label>Курс</label>
                <select name="course">
                    <option value="1">1 курс</option>
                    <option value="2">2 курс</option>
                </select>
            </div>

            <div class="form-actions">
                <button type="submit" class="button-primary">Сохранить</button>
                <button type="button" class="button cancel">Отмена</button>
            </div>
        </form>
    </div>
</div>
```

#### JavaScript: Модальное окно

**Файл:** `src/js/admin/components/group-modal.js`

```javascript
import { ModalBase } from '../modules/modal-base.js';

export class GroupModal extends ModalBase {
    constructor() {
        super('#group-modal');
        this.form = document.getElementById('group-form');
        this.initListeners();
    }

    initListeners() {
        this.form.addEventListener('submit', (e) => this.handleSubmit(e));
    }

    open(groupId = null) {
        if (groupId) {
            this.loadGroupData(groupId);
        } else {
            this.form.reset();
        }
        super.open();
    }

    async handleSubmit(e) {
        e.preventDefault();

        const formData = new FormData(this.form);
        const action = groupId ? 'updateStudentGroup' : 'saveStudentGroup';

        try {
            const response = await this.sendAjax(action, formData);
            if (response.success) {
                this.close();
                // Обновить таблицу
                window.groupManager.refreshTable();
            }
        } catch (error) {
            console.error('Ошибка:', error);
        }
    }
}
```

#### JavaScript: Менеджер модального окна

**Файл:** `src/js/admin/services/group-modal-manager.js`

```javascript
import { GroupModal } from '../components/group-modal.js';

export class GroupModalManager {
    constructor() {
        this.modal = new GroupModal();
        this.initTriggers();
    }

    initTriggers() {
        // Кнопка "Добавить группу"
        document.getElementById('open-group-modal')?.addEventListener('click', () => {
            this.modal.open();
        });

        // Кнопки редактирования в таблице
        document.querySelectorAll('.edit-group').forEach(button => {
            button.addEventListener('click', (e) => {
                const groupId = e.target.dataset.id;
                this.modal.open(groupId);
            });
        });
    }

    refreshTable() {
        // Перезагрузка таблицы групп
        location.reload();
    }
}

// Инициализация
document.addEventListener('DOMContentLoaded', () => {
    new GroupModalManager();
});
```

### Схема потока создания страницы

```
┌─────────────────┐
│  AdminController│
│  (register())   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  MenuRegistrar  │ ←─── add_menu_page(), add_submenu_page()
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  AdminCallbacks │ ←─── groupsPage(), settingsPage()
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Template       │ ←─── templates/admin/groups.php
│  (View)         │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Modal View     │ ←─── templates/admin/components/modals/
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  JS Component   │ ←─── src/js/admin/components/group-modal.js
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  JS Manager     │ ←─── src/js/admin/services/group-modal-manager.js
└─────────────────┘
```

---

## Менеджеры и Регистраторы

### В чём разница?

| Характеристика | Менеджер | Регистратор |
|---------------|----------|-------------|
| **Уровень** | Низкоуровневый | Высокоуровневый |
| **Ответственность** | Прямой вызов WordPress API | Формирование конфигураций |
| **Паттерн** | Service | Facade + Fluent Interface |
| **Зависимости** | Нет | Зависит от менеджера |
| **Переиспользование** | Универсальный | Специфичный для домена |

### Когда использовать Менеджер?

**Используйте Менеджер, когда:**
- Нужен прямой вызов WordPress API (`register_taxonomy`, `register_post_type`)
- Требуется инкапсуляция системных вызовов
- Нет необходимости в сложной конфигурации
- Компонент должен быть переиспользуемым

**Примеры:**
- `TaxonomyManager` — вызывает `register_taxonomy()`
- `PostManager` — вызывает `wp_insert_post()`, `update_post_meta()`
- `TermManager` — вызывает `wp_insert_term()`, `wp_set_post_terms()`
- `MetaBoxManager` — вызывает `add_meta_box()`
- `MenuManager` — вызывает `add_menu_page()`, `add_submenu_page()`

### Когда использовать Регистратор?

**Используйте Регистратор, когда:**
- Нужно накопить конфигурации перед регистрацией
- Требуются хелперы для типовых сценариев
- Нужен Fluent Interface для цепочки вызовов
- Есть специфичная бизнес-логика конфигурирования

**Примеры:**
- `SubjectTaxonomyRegistrar` — формирует конфиги таксономий для предметов
- `SubjectCPTRegistrar` — формирует конфиги CPT для предметов
- `MenuRegistrar` — накапливает страницы перед регистрацией
- `MetaBoxRegistrar` — конфигурирует метабоксы
- `SettingsRegistrar` — регистрирует настройки WordPress Settings API

### Пример взаимодействия

```php
// Регистратор зависит от менеджера
class SubjectTaxonomyRegistrar {
    private TaxonomyManager $manager;
    private array $taxonomies = array();

    // Накопление конфигураций
    public function addStandardTaxonomy(...): self {
        $this->taxonomies[$slug] = [/* конфиг */];
        return $this;
    }

    // Делегирование менеджеру
    public function register(): void {
        $this->manager->register($this->taxonomies);
    }
}

// Менеджер выполняет регистрацию
class TaxonomyManager {
    public function register(array $taxonomies): void {
        add_action('init', function() use ($taxonomies) {
            foreach ($taxonomies as $slug => $data) {
                register_taxonomy($slug, $data['post_types'], $data['args']);
            }
        });
    }
}
```

---

## Сервисы

**Расположение:** `inc/Services/`

### Назначение

Сервисы содержат **бизнес-логику** и координируют работу нескольких репозиториев/менеджеров для выполнения сложных операций.

### Группировка сервисов

#### 1. Предметно-ориентированные сервисы

**Директория:** `inc/Services/Subject/`

- **SubjectImportService** — импорт предмета из JSON
- **SubjectExportService** — экспорт предмета в JSON
- **SubjectDeletionService** — каскадное удаление предмета

**Пример использования:**
```php
class SubjectImportService {
    public function __construct(
        private readonly SubjectRepository $subjects,
        private readonly TaxonomyRepository $taxonomies,
        private readonly MetaBoxRepository $metaboxes,
        private readonly BoilerplateRepository $boilerplates,
        private readonly TermManager $terms,
        private readonly PostManager $posts,
    ) {}

    public function import(array $data): string {
        // 1. Создание предмета
        $this->subjects->save(new SubjectDTO($key, $name));

        // 2. Импорт таксономий
        $this->importTaxonomies($key, $data['taxonomies']);

        // 3. Импорт метабоксов
        $this->importMetaboxes($key, $data['metaboxes']);

        // 4. Импорт boilerplate
        $this->importBoilerplates($key, $data['boilerplates']);

        // 5. Импорт терминов и постов
        $this->importTerms($data['terms']);
        $this->importPosts($data['posts']);

        return $name;
    }
}
```

#### 2. Сервисы сущностей

- **AcademicPeriodService** — управление учебными периодами
- **ArticleService** — операции со статьями
- **StudentGroupService** — управление группами студентов

#### 3. Инфраструктурные сервисы

- **ContentCacheService** — кеширование контента
- **PageGeneratorService** — генерация страниц
- **PostTypeResolver** — разрешение типов постов
- **ThemeCompatService** — совместимость с темой

#### 4. Сервисы аутентификации

**Директория:** `inc/Services/AuthService/`

- Стратегии аутентификации (Google, VK, GitHub)

#### 5. Сервисы задач

**Директория:** `inc/Services/Task/`

- Логика работы с заданиями

#### 6. Сервисы шаблонов

**Директория:** `inc/Services/Template/`

- Управление шаблонами страниц (заданий)

### Характеристики сервисов

- **Не реализуют ServiceInterface** — не регистрируются напрямую в Init
- **Внедряются через DI** — используются контроллерами и другими сервисами
- **Содержат бизнес-логику** — не просто CRUD, а сложные операции
- **Координируют несколько компонентов** — репозитории, менеджеры, DTO

---

## DTO (Data Transfer Objects)

**Расположение:** `inc/DTO/`

### Назначение

DTO обеспечивают **типобезопасную передачу данных** между слоями приложения.

### Принципы

- **readonly классы** — неизменяемость после создания
- **Фабричные методы** — `fromArray()`, `toArray()`
- **Типизированные свойства** — строгая типизация всех полей
- **Бизнес-логики нет** — только хранение и преобразование данных

### Список DTO

| DTO | Назначение |
|-----|-----------|
| `AcademicPeriodDTO` | Данные учебного периода |
| `PostViewDTO` | Данные для отображения поста |
| `PostsListTableDTO` | Данные для таблицы постов |
| `StudentEnrollmentDTO` | Запись о зачислении студента |
| `StudentGroupDTO` | Данные группы студентов |
| `SubjectDTO` | Данные предмета |
| `SubjectViewDTO` | Данные для отображения предмета |
| `TaskMetaDTO` | Мета-данные задания |
| `TaskTemplateAssignmentDTO` | Привязка шаблона к заданию |
| `TaskTypeBoilerplateDTO` | Типовое условие задания |
| `TaskTypeDTO` | Тип задания |
| `TaxonomyDataDTO` | Данные таксономии |
| `TermViewDTO` | Данные для отображения термина |
| `UserDTO` | Данные пользователя |

### Пример: TaxonomyDataDTO

```php
readonly class TaxonomyDataDTO {
    public function __construct(
        public string $slug,
        public string $name,
        public string $subject_key,
        public string $display_type = 'select',
        public bool $is_protected = false,
        public bool $is_required = false,
        public array $post_types = array()
    ) {}

    // Преобразование в массив для БД
    public function toArray(): array {
        return [
            'name' => $this->name,
            'display_type' => $this->display_type,
            'is_required' => $this->is_required,
        ];
    }

    // Создание из массива
    public static function fromArray(string $slug, array $data, string $subject_key = ''): self {
        return new self(
            slug: $slug,
            name: $data['name'] ?? '',
            subject_key: $subject_key ?: ($data['subject_key'] ?? ''),
            display_type: $data['display_type'] ?? 'select',
            is_protected: $data['is_protected'] ?? false,
            is_required: (bool) ($data['is_required'] ?? false),
            post_types: $data['post_types'] ?? array()
        );
    }
}
```

### Где используются DTO?

1. **Репозитории → Контроллеры** — передача данных из БД
2. **Callbacks → Репозитории** — сохранение данных из AJAX
3. **Контроллеры → Views** — передача данных в шаблоны
4. **Сервисы** — координация между компонентами

---

## Enum (Перечисления)

**Расположение:** `inc/Enums/`

### Назначение

Enum предоставляют **типобезопасные константы** для различных аспектов плагина.

### Список Enum

| Enum | Назначение |
|------|-----------|
| `AjaxHook` | AJAX-хуки с автогенерацией имён |
| `AuthProvider` | Провайдеры аутентификации |
| `Capability` | Роли и возможности WordPress |
| `MenuSlug` | Слаги пунктов меню |
| `MenuTitle` | Названия пунктов меню |
| `Nonce` | Nonce-токены для безопасности |
| `OptionName` | Имена опций в wp_options |
| `PageRoutes` | Маршруты страниц |
| `PageTitle` | Заголовки страниц |
| `PostMetaName` | Имена мета-полей постов |
| `ShortCode` | Шорткоды |
| `TaskTemplate` | Шаблоны заданий |
| `UserRole` | Роли пользователей |

### Пример: AjaxHook

```php
enum AjaxHook: string {
    case StoreSubject = 'StoreSubject';
    case UpdateSubject = 'UpdateSubject';
    case DeleteSubject = 'DeleteSubject';

    // Автогенерация имени для wp_ajax_*
    public function action(): string {
        return 'wp_ajax_' . $this->toSnakeCase();
    }

    // Имя для JavaScript
    public function jsAction(): string {
        return $this->toSnakeCase();
    }

    // Имя метода коллбека
    public function callbackMethod(): string {
        return 'ajax' . $this->value;
    }

    // Массив для передачи в JS
    public static function toJsArray(): array {
        $actions = [];
        foreach (self::cases() as $case) {
            $actions[lcfirst($case->name)] = $case->jsAction();
        }
        return $actions;
    }
}
```

### Использование в коде

```php
// Регистрация AJAX-хука
add_action(AjaxHook::StoreSubject->action(), [$callbacks, 'ajaxStoreSubject']);

// Передача в JavaScript
wp_localize_script('fs-lms-admin', 'fsLmsAjax', [
    'actions' => AjaxHook::toJsArray()
]);

// Использование в контроллере
protected function ajaxActions(): array {
    return [
        [AjaxHook::StoreSubject, $this->crud_callbacks],
        [AjaxHook::UpdateSubject, $this->crud_callbacks],
    ];
}
```

### Преимущества Enum

- **Типобезопасность** — защита от опечаток
- **Автодополнение** — IDE подсказывает значения
- **Централизация** — все константы в одном месте
- **Методы** — возможность добавлять логику

---

## Трейты

**Расположение:** `inc/Shared/Traits/`

### Назначение

Трейты предоставляют **переиспользуемое поведение** для классов.

### Список трейтов

| Трейт | Назначение | Где применяется |
|-------|-----------|----------------|
| `AjaxResponse` | Унификация AJAX-ответов | Callbacks классы |
| `Authorizer` | Проверка прав доступа | Контроллеры, Callbacks |
| `ErrorHandler` | Обработка ошибок | Сервисы, Контроллеры |
| `NumericSorter` | Числовая сортировка терминов | SubjectController |
| `Sanitizer` | Санитизация данных | Callbacks, Сервисы |
| `SlugGenerator` | Генерация слагов | Сервисы, Репозитории |
| `TaxonomySeeder` | Сидирование таксономий | Сервисы импорта |
| `TemplateRenderer` | Рендеринг шаблонов | Callbacks классы |

### Пример: AjaxResponse

```php
trait AjaxResponse {
    protected function respond(
        mixed $result,
        string $error_msg = 'Произошла ошибка',
        string $success_msg = '',
        array $extra_data = array()
    ): void {
        if (!$result) {
            $this->error($error_msg);
        }

        $response = $extra_data;

        if ($success_msg) {
            $response['message'] = $success_msg;
        }

        if (is_array($result)) {
            $response = array_merge($response, $result);
        }

        wp_send_json_success($response);
    }

    protected function error(string $message, array $context = array()): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[FS LMS AJAX Error] %s: %s | Context: %s',
                get_class($this),
                $message,
                wp_json_encode($context)
            ));
        }

        wp_send_json_error(array_merge(['message' => $message], $context));
    }

    protected function success(array $data = array()): void {
        wp_send_json_success($data);
    }
}
```

### Применение трейта

```php
class TaxonomySettingsCallbacks {
    use AjaxResponse; // Добавляет методы respond(), error(), success()

    public function ajaxStoreTaxonomy(): void {
        $dto = TaxonomyDataDTO::fromArray($slug, $data);
        $result = $this->taxonomies->save($dto);

        // Используем метод из трейта
        $this->respond($result, 'Ошибка сохранения таксономии');
    }
}
```

### Пример: TemplateRenderer

```php
trait TemplateRenderer {
    protected function render(string $template, array $data = []): void {
        $path = FS_LMS_PLUGIN_PATH . "templates/{$template}";

        if (!file_exists($path)) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        extract($data);
        include $path;
    }
}

// Применение
class AdminCallbacks {
    use TemplateRenderer;

    public function groupsPage(): void {
        $groups = $this->group_service->getAllGroups();
        $this->render('admin/groups.php', ['groups' => $groups]);
    }
}
```

---

## Контроллеры и Callbacks

### Контроллеры

**Расположение:** `inc/Controllers/`

**Назначение:** Оркестрация компонентов, регистрация хуков, внедрение зависимостей.

**Основные контроллеры:**

| Контроллер | Назначение |
|-----------|-----------|
| `AdminController` | Админ-меню, страницы настроек |
| `SubjectController` | Предметы, CPT, таксономии |
| `AuthController` | Аутентификация пользователей |
| `TaskCreationController` | Создание заданий |
| `MetaBoxController` | Метабоксы заданий |
| `BoilerplateController` | Типовые условия |

**Структура контроллера:**

```php
class SubjectController extends AjaxController {
    use NumericSorter;

    // Внедрение зависимостей
    public function __construct(
        private readonly SubjectRepository $subjects,
        private readonly SubjectCPTRegistrar $cpt_registrar,
        private readonly TaxonomySettingsCallbacks $taxonomy_callbacks,
        // ...
    ) {
        parent::__construct();
    }

    // Точка входа
    public function register(): void {
        $this->registerCptsAndTaxonomies();
        $this->registerAjaxHooks();
    }

    // Список AJAX-действий
    protected function ajaxActions(): array {
        return [
            [AjaxHook::StoreTaxonomy, $this->taxonomy_callbacks],
        ];
    }
}
```

### Callbacks

**Расположение:** `inc/Callbacks/`

**Назначение:** Методы-обработчики для WordPress хуков и AJAX.

**Основные callbacks:**

| Callbacks | Назначение |
|-----------|-----------|
| `AdminCallbacks` | Отрисовка админ-страниц |
| `SubjectCrudCallbacks` | CRUD предметов |
| `SubjectDataCallbacks` | Получение данных |
| `TaxonomySettingsCallbacks` | Управление таксономиями |
| `TemplateManagerCallbacks` | Управление шаблонами |
| `AuthCallbacks` | Обработка аутентификации |

**Структура callbacks:**

```php
class TaxonomySettingsCallbacks {
    use AjaxResponse;

    public function __construct(
        private readonly TaxonomyRepository $taxonomies
    ) {}

    public function ajaxStoreTaxonomy(): void {
        // Валидация
        // Создание DTO
        // Сохранение
        // Ответ
        $this->respond($result, 'Ошибка');
    }
}
```

---

## Репозитории

**Расположение:** `Inc/Repositories/WPDBRepositories/`

### Назначение

Репозитории инкапсулируют **доступ к данным** (БД, опции, мета-поля). Содержат в себе методы CRUD (create+update совмещены) операций и приватные методы-хелперы.

### Основные репозитории

| Репозиторий | Таблица/Опция | Назначение |
|------------|---------------|-----------|
| `SubjectRepository` | `fs_lms_subjects` | Предметы |
| `TaxonomyRepository` | `fs_lms_custom_taxonomies` | Таксономии |
| `UserRepository` | `wp_users`, `wp_usermeta` | Пользователи |
| `ArticleRepository` | `wp_posts` (CPT) | Статьи |
| `BoilerplateRepository` | `fs_lms_boilerplates` | Типовые условия |
| `MetaBoxRepository` | `fs_lms_metaboxes` | Привязки шаблонов |
| `SettingsRepository` | `wp_options` | Настройки |
| `AcademicPeriodRepository` | `fs_lms_academic_periods` | Учебные периоды |
| `StudentGroupRepository` | `fs_lms_student_groups` | Группы студентов |

### Пример репозитория

```php
class TaxonomyRepository {
    private string $option_name = OptionName::TAXONOMY->value;

    private function getRaw(): array {
        $all = get_option($this->option_name, array());
        return is_array($all) ? $all : array();
    }

    public function readAll(): array {
        $result = [];
        foreach ($this->getRaw() as $subject_key => $taxonomies) {
            $result[$subject_key] = [];
            foreach ($taxonomies as $slug => $data) {
                $result[$subject_key][] = TaxonomyDataDTO::fromArray($slug, $data, $subject_key);
            }
        }
        return $result;
    }

    public function save(TaxonomyDataDTO $dto): bool {
        $all = $this->getRaw();
        $all[$dto->subject_key][$dto->slug] = $dto->toArray();
        return update_option($this->option_name, $all);
    }

    public function remove(string $subject_key, string $tax_slug): bool {
        $all = $this->getRaw();
        unset($all[$subject_key][$tax_slug]);
        if (empty($all[$subject_key])) {
            unset($all[$subject_key]);
        }
        return update_option($this->option_name, $all);
    }
}
```

### Принципы репозиториев

- **Одна ответственность** — один репозиторий = одна сущность
- **DTO на границах** — вход и выход через DTO
- **Инкапсуляция** — скрытие деталей хранения
- **Бизнес-логики нет** — только CRUD операции

---

## Заключение

Данная документация описывает архитектуру плагина FS LMS, включая:

- **DI контейнер** для автоматического внедрения зависимостей
- **Flow создания таксономий** через 5 слоёв архитектуры
- **Flow создания страниц** с модальными окнами
- **Разделение Менеджеров и Регистраторов**
- **Группировку Сервисов** по назначению
- **DTO и Enum** для типобезопасности
- **Трейты** для переиспользуемого поведения

Все компоненты следуют принципам **SOLID** и используют паттерны проектирования для обеспечения поддерживаемости и расширяемости кода.