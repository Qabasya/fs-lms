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
12. [Система зачисления](#система-зачисления)
13. [WPDB Репозитории](#wpdb-репозитории)
14. [Сервисы системы зачисления](#сервисы-системы-зачисления)
15. [Миграции](#миграции)
16. [Согласия на обработку ПД](#согласия-на-обработку-пд)

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

## ThemeCompatService

**Файл:** `inc/Services/ThemeCompatService.php`

Статический сервис совместимости с классическими и блочными (FSE) темами WordPress.

Блочные темы не имеют `header.php` / `footer.php`, поэтому прямой вызов `get_header()` / `get_footer()` выдаёт Deprecated-предупреждение. `ThemeCompatService` определяет тип темы и вызывает нужный API.

### Использование в шаблонах

**Все frontend-шаблоны плагина** обязаны использовать `ThemeCompatService` вместо `get_header()` / `get_footer()`:

```php
use Inc\Services\ThemeCompatService;

ThemeCompatService::header(); // вместо get_header()
// ... контент страницы ...
ThemeCompatService::footer(); // вместо get_footer()
```

### Логика

| Тип темы | `header()` | `footer()` |
|---|---|---|
| Классическая | `get_header()` | `get_footer()` |
| Блочная (FSE) | `openHtmlSkeleton()` + `block_template_part('header')` | `block_template_part('footer')` + `wp_footer()` + `</body></html>` |

`openHtmlSkeleton()` выводит `<!DOCTYPE html>`, `<head>`, `wp_head()`, `<body>`.

### Где применяется

- `templates/frontend/single-task.php`
- Все новые публичные страницы, подключаемые через `template_include` (минуя шорткод)

### Не применяется

Шаблоны, рендеримые через шорткод (`apply.php`, `auth-page.php`, `profile.php`), **не вызывают** `ThemeCompatService` — тема WordPress уже выводит header/footer вокруг шорткода.

### Не использовать

`get_header()` / `get_footer()` — **не вызывать напрямую** в шаблонах плагина.

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

## Система зачисления

Система зачисления реализует полный жизненный цикл приёма ученика в LMS — от первичного обращения до активного зачисления на предметы.

### Flow зачисления

```
1. Менеджер создаёт заявку (ApplicationRepository)
   └─ Генерируется JOIN-код (JoinCodeService)
   └─ Ссылка отправляется родителю

2. Родитель переходит по JOIN-ссылке
   └─ Проверяется формат и срок действия кода
   └─ Проверяется RateLimit (IP, 10 попыток/час)
   └─ Проверяется CAPTCHA

3. Родитель заполняет данные ученика и подписывает согласие
   └─ PersonService::createOrFindBy() — идемпотентное создание person
   └─ ConsentService::record() — фиксация согласия с IP/UA
   └─ ApplicationRepository::linkPersons() — привязка person к заявке

4. Администратор проверяет заявку и принимает решение
   └─ Approve → EnrollmentService::enroll()
      └─ PersonService создаёт WP-пользователя
      └─ PasswordLinkService::generate() — одноразовая ссылка на почту
      └─ RelationshipService::addRepresentative() — связь родитель↔ученик
      └─ EnrollmentRepository::create() — запись о зачислении
   └─ Reject → заявка отклоняется с причиной

5. Аудит
   └─ AuditService::record() пишется на каждом значимом шаге
   └─ PersonReader::read() пишет в pii_access_log при каждом чтении PII
```

### Статусы заявки

| Статус | Описание |
|---|---|
| `pending_parent` | Ожидает заполнения родителем |
| `pending_review` | Ожидает проверки администратором |
| `approved` | Принята, зачисление выполнено |
| `rejected` | Отклонена с указанием причины |
| `expired` | JOIN-код истёк, заявка не заполнена |

### Инварианты

- JOIN-код хранится только в виде хэша; сырой код не логируется
- PII всегда шифруется до записи в БД (sodium_crypto_secretbox)
- Поиск по документу — по хэшу, не по зашифрованному полю
- Один ученик не может быть зачислен на один предмет дважды в одном периоде (UNIQUE KEY)
- Согласие фиксируется до любых операций с персональными данными

---

## WPDB Репозитории

### Почему не wp_options

Существующие репозитории (`SubjectsRepository`, `TaxonomyRepository` и т.д.) хранят данные в `wp_options` как сериализованные массивы. Для небольших справочных данных это удобно: не нужны миграции, данные атомарно обновляются одним вызовом.

Система зачисления работает с другим классом данных:
- **Реляционные связи** (заявка → person, person → enrollment, опекун ↔ ученик)
- **Временны́е диапазоны** (valid_from / valid_to у relationship)
- **Большие объёмы записей** (тысячи заявок, строк аудита)
- **Поиск по хэшам** (doc_number_hash, join_code_hash) — требует индексов

Хранить всё это в `wp_options` невозможно без потери производительности и целостности. Поэтому созданы **7 выделенных таблиц** и отдельный слой WPDB-репозиториев.

### Таблицы

| Таблица | Класс репозитория | Назначение |
|---|---|---|
| `{prefix}persons` | `PersonRepository` | Персональные данные (зашифрованные) |
| `{prefix}applications` | `ApplicationRepository` | Заявки на зачисление |
| `{prefix}relationships` | `RelationshipRepository` | Связи опекун ↔ ученик |
| `{prefix}enrollments` | `EnrollmentRepository` | Зачисления на предметы |
| `{prefix}consents` | `ConsentRepository` | Согласия на обработку ПД |
| `{prefix}audit_log` | `AuditLogRepository` | Аудит-лог действий |
| `{prefix}pii_access_log` | `PiiAccessLogRepository` | Лог доступа к PII |

### Паттерн WPDB-репозитория

Все WPDB-репозитории расположены в `inc/Repositories/WPDBRepositories/` и следуют единому паттерну:

```php
class PersonRepository {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'persons';
    }

    public function find(int $id): ?PersonDTO {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );
        return $row ? PersonDTO::fromRow($row) : null;
    }

    public function create(array $data): int {
        global $wpdb;
        $wpdb->insert($this->table, $data);
        return (int) $wpdb->insert_id;
    }
}
```

**Ключевые правила:**
- Все параметры в запросах — через `$wpdb->prepare()` с плейсхолдерами `%d`, `%s`, `%f`
- Нет raw-интерполяции пользовательских данных в SQL
- Имя таблицы не параметризуется (используется только внутри класса)
- Результат всегда преобразуется в DTO или массив DTO — голые массивы наружу не передаются

### Идемпотентные операции

`RelationshipRepository::createIfNotExists()` использует `INSERT IGNORE` + fallback SELECT:

```php
public function createIfNotExists(array $data): int {
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$this->table} (guardian_person_id, student_person_id, valid_from, ...)
         VALUES (%d, %d, %s, ...)",
        ...
    ));
    if ($wpdb->rows_affected > 0) {
        return (int) $wpdb->insert_id;
    }
    // Запись уже существует — находим её
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$this->table} WHERE guardian_person_id = %d AND student_person_id = %d AND valid_from = %s",
        ...
    ));
}
```

---

## Сервисы системы зачисления

Все сервисы расположены в `inc/Services/` и являются `readonly`-классами (кроме тех, кто использует трейты с `$this`-мутацией).

### AuditService

Единственная точка записи в `audit_log`. Использует трейт `RequestContextProvider` для автоматического сбора IP/UA.

- `record(action, targetType, targetId, details)` — для аутентифицированных действий; автоматически определяет `actor_user_id` и `actor_role` через `get_current_user_id()` и `UserManager::find()`
- `recordAnonymous(...)` — для публичных эндпоинтов (заполнение формы родителем)

Детали (`details`) пишутся как JSON. **Никогда не содержат PII, ключей или хэшей** — только идентификаторы и типы операций.

### JoinCodeService

Генерирует одноразовые коды вида `JOIN-XXXX-XXXX-XXXX`.

- Алфавит: `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` — исключены визуально похожие символы (0/O, 1/I)
- В БД хранится только `hash($code)` — сырой код нигде не сохраняется
- `isValidFormat(string $code): bool` — валидация формата без проверки существования
- `hash(string $code): string` — делегирует в `PiiCryptoService::hash()`

### PasswordLinkService

Генерирует и инвалидирует одноразовые ссылки установки пароля.

- `generate(int $userId): string` — вызывает `UserManager::generatePasswordResetKey()`, строит URL, пишет аудит
- `invalidate(int $userId): void` — сбрасывает `user_activation_key` через `UserManager::clearActivationKey()`
- `getDefaultTtl(): int` — возвращает TTL через фильтр `password_reset_expiration` (базовый: 24 ч, для LMS-ролей расширяется до 48 ч в контроллере)

Все WordPress-вызовы (`get_userdata`, `get_password_reset_key`, `wp_update_user`) инкапсулированы в `UserManager` — сервис никогда не обращается к WP API напрямую.

### RateLimitService

Фиксированное окно (fixed-window) через WP transients.

- Хранит `['count' => N, 'reset_at' => timestamp]` внутри transient — TTL transient'а устанавливается равным длине окна только при создании
- Это гарантирует, что окно не сдвигается при каждом инкременте (в отличие от sliding window)
- IP хэшируется: `hash('sha256', $ip . FS_LMS_HASH_SALT)` — IP в transient-ключах не хранится

Лимиты по умолчанию:

| Действие | Лимит | Окно |
|---|---|---|
| Подача заявки | 5 | 1 час |
| Ввод JOIN-кода | 10 | 1 час |
| Отправка данных родителем | 3 | 1 час |
| Чтение PII | 100 | 1 час |

### EmailOtpService

Генерирует, отправляет и верифицирует одноразовые коды подтверждения email (шаг A/B формы заявки).

- `sendCode(string $email)` — генерирует 6-значный код, сохраняет sha256-хэш в transient (TTL 10 мин), отправляет письмо. Если определена `FS_LMS_TEST_ENV` — сразу возвращает `return`, письмо не отправляется.
- `verify(string $email, string $code): bool` — сравнивает хэши через `hash_equals()`. Если определена `FS_LMS_OTP_BYPASS_CODE` и `$code === FS_LMS_OTP_BYPASS_CODE` — возвращает `true` без проверки transient (работает в любом окружении).
- `canResend(string $email): bool` — проверяет cooldown (60 сек).
- `invalidate(string $email)` — удаляет transient кода и cooldown.

**Конфигурационные константы (`wp-config.php`):**

| Константа | Назначение                                                                                                                        |
|---|-----------------------------------------------------------------------------------------------------------------------------------|
| `FS_LMS_TEST_ENV` | Тестовое окружение: письмо не отправляется, капча в `ApplicationCallbacks` пропускается. Открывает дебаг-маршрут `/lms/join/000`. |
| `FS_LMS_OTP_BYPASS_CODE` | Постоянный bypass-код: принимается вместо кода с почты в любом окружении. Удобно когда у ученика нет доступа к email.             |

Константы независимы. Без `FS_LMS_TEST_ENV` капча и письмо работают штатно; `FS_LMS_OTP_BYPASS_CODE` при этом всё равно принимается как валидный код.

**Дебаг-маршрут страницы родителя** (`FS_LMS_TEST_ENV`):

`GET /lms/join/000` — рендерит `join.php` с тестовыми данными без обращения к БД. Реализовано в `ApplicationCallbacks::prepareJoinPage()` — перехват до валидации формата и rate limit. В продакшне (без константы) адрес возвращает 404, так как `000` не соответствует формату `JOIN-XXXX-XXXX-XXXX`.

### CaptchaService

Тонкий фасад над `CaptchaProviderInterface`. Следует принципу OCP: добавление нового провайдера (reCAPTCHA v3, Turnstile, hCaptcha) не требует изменения сервиса.

```
CaptchaService
└── CaptchaProviderInterface
    ├── NullCaptchaProvider   (fallback, isConfigured() → false)
    └── RecaptchaProvider     (пример реализации, подключается в DI)
```

- `validate(token, remoteIp): bool` — делегирует в провайдер
- `getSiteKey(): string` — ключ для фронтенда
- `isConfigured(): bool` — фронтенд показывает виджет только если `true`

Проверка капчи в `ApplicationCallbacks::ajaxSendOtpCode()` пропускается если в `wp-config.php` определена константа `FS_LMS_TEST_ENV`.

### PersonService

Управляет жизненным циклом записей в таблице `persons`.

- `createOrFindBy(array $rawData): int` — идемпотентный поиск по `doc_number_hash` перед созданием; шифрует все PII-поля до записи
- `update(int $id, array $rawData): void` — шифрует изменённые поля; в аудит пишет только имена полей (не значения)
- `softDelete(int $id): void` — проставляет `deleted_at`; запись остаётся в БД для аудита
- `anonymize(int $id): void` — обнуляет все `*_enc` поля; вызывается retention job, не пишет в аудит

Константы `ENCRYPTED_FIELDS` и `HASH_FIELDS` — декларативная карта: `rawData key → DB column`. Добавление нового зашифрованного поля — изменение только константы.

### PersonReader

Единственный авторизованный путь для чтения PII. Каждое чтение автоматически логируется в `pii_access_log`.

```php
$dto = $personReader->read(
    personId: $id,
    fields:   ['full_name', 'doc_number'],
    reason:   'admin_card_view',
);
// $dto->fullName, $dto->pass — расшифрованные значения
```

- Запрос только конкретных полей (`fields`) — принцип минимального доступа
- `reason` — обязательная строка; попадает в `pii_access_log.access_reason`
- NULL-поля (незаполненные) возвращаются как `''` без исключений

### RelationshipService

Управляет связями опекун ↔ ученик.

- `addRepresentative(...)`: идемпотентное создание через `RelationshipRepository::createIfNotExists()`
- `replaceRepresentative(oldId, newGuardianPersonId, newType)`: атомарная замена через `TransactionRunner::inTransaction()` — terminate(old) + create(new) в одной транзакции
- `terminate(id, reason)`: проставляет `valid_to = TODAY`
- `canRepresent(guardianWpUserId, studentPersonId): bool`: авторизация родителя к данным ребёнка на уровне приложения

Инвариант активной связи: `valid_from <= TODAY AND (valid_to IS NULL OR valid_to > TODAY)`.

---

## Миграции

### Расположение

```
inc/
└── Migrations/
    ├── MigrationRunner.php    # Оркестратор
    └── Migration_1_0_0.php   # Первая монолитная миграция
```

### MigrationInterface

```php
interface MigrationInterface {
    public function up(): void;
    public function down(): void;
    public function version(): string; // semver: '1.0.0'
}
```

### MigrationRunner

Отслеживает текущую версию схемы в опции `fs_lms_schema_version` (`OptionName::SchemaVersion`).

- `register(MigrationInterface $migration)` — добавляет миграцию в пул
- `run()` — сортирует по `version_compare`, применяет только те, что выше текущей версии; обновляет опцию после успешного применения
- `rollback()` — откатывает все в обратном порядке; удаляет опцию версии

Запуск происходит в хуке `plugins_loaded` (или `register_activation_hook`) — до инициализации репозиториев, которые обращаются к уже созданным таблицам.

### Migration_1_0_0

Монолитная первая миграция. Создаёт все 7 таблиц системы зачисления через `dbDelta()`. `down()` удаляет таблицы в обратном порядке (от зависимых к основным), чтобы не нарушить возможные FK-ограничения будущих версий.

Версия `1.0.0` — базовая схема. При изменении схемы добавляется новый класс `Migration_X_Y_Z` — Runner применит его автоматически при следующем запуске плагина.

---

<!-- Раздел перенесён выше в "Согласия на обработку ПД" -->

---

## Email-шаблоны (Strategy pattern)

### Проблема

Тексты писем нельзя хардкодить в `EmailService`: администратор должен иметь возможность редактировать их через UI без деплоя, а разработчик — менять дефолты через PHP-файлы.

### Архитектура

```
EmailTemplateInterface          ← контракт стратегии (inc/Contracts/)
├── PhpEmailTemplate            ← читает templates/emails/{type}.php
└── WpOptionsEmailTemplate      ← читает wp_options, fallback → PhpEmailTemplate
```

`EmailService` зависит от `WpOptionsEmailTemplate` (внедряется DI-контейнером). Сам сервис не знает, откуда пришли тексты.

### EmailTemplateInterface

```php
interface EmailTemplateInterface {
    public function get(string $type, array $vars = []): EmailTemplateData;
}
```

Один метод возвращает `EmailTemplateData` с `subject` и `body` — оба уже готовы для `wp_mail()`.

### PhpEmailTemplate

Загружает `templates/emails/{type}.php`. Каждый файл должен вернуть массив:

```php
// templates/emails/otp_code.php
<?php
return [
    'subject' => 'Код подтверждения — FS LMS',
    'body'    => '<p>Ваш код: <strong>' . esc_html($code) . '</strong>.</p>',
];
```

Переменные из `$vars` доступны через `extract()` до `include`.

### WpOptionsEmailTemplate

Хранит шаблоны в `wp_options` (ключ `fs_lms_email_templates`):

```php
[
  'otp_code' => [
    'subject' => 'Код: FS LMS',
    'body'    => '<p>Ваш код: <strong>{code}</strong>.</p>',
  ],
]
```

Переменные подставляются как `{key}` → `esc_html(value)`. Если для типа нет записи в options — делегирует в `PhpEmailTemplate`.

### Добавление нового типа письма

1. Создать `templates/emails/{type}.php`, вернуть массив с `subject` и `body`.
2. Добавить метод в `EmailService`, вызвать `$this->template->get('{type}', $vars)`.
3. Документировать плейсхолдеры — они отобразятся в UI вкладки "Шаблоны писем".

### Редактирование через UI

Вкладка "Шаблоны писем" в настройках плагина (реализуется через `EmailTemplateSettingsCallbacks`):
- Показывает текущий текст (из options или из PHP-файла как дефолт)
- Сохраняет изменения в `OptionName::EmailTemplates` (`fs_lms_email_templates`)
- Кнопка "Сбросить к умолчанию" удаляет запись из options → автоматический fallback на PHP-файл

### Существующие типы

| Тип | Метод EmailService | Плейсхолдеры |
|---|---|---|
| `otp_code` | `sendOtpCode` | `{code}` |
| `password_setup` | `sendPasswordSetup` | `{link}`, `{display_name}` |
| `application_confirmation` | `sendApplicationConfirmation` | `{join_url}`, `{expires_at}` |
| `application_ready` | `sendApplicationReadyNotification` | — |
| `rejection` | `sendRejectionNotification` | `{reason}` |
| `new_representative` | `sendNewRepresentativeNotification` | `{display_name}`, `{link}` |

---

## Согласия на обработку ПД

### Архитектура

Текст согласия хранится непосредственно в содержимом WordPress-страницы с slug `consent` (создаётся при активации). Администратор редактирует его через стандартный редактор — без отдельного пункта в меню плагина.

| Слой | Файл | Роль |
|---|---|---|
| Источник текста | WP-страница slug `consent` (`post_content`) | Редактируемый текст согласия |
| Мета-хранилище | `wp_options[fs_lms_consent_page_meta]` | Текущий sha256-хэш и дата обновления |
| Контроллер | `inc/Controllers/ConsentController.php` | Хук `save_post` + rewrite rule |
| Сервис | `inc/Services/ConsentService.php` | Чтение текста, хранение хэша, фиксация |

### Структура wp_options

```php
fs_lms_consent_page_meta = [
  'hash'       => 'a3f1...e9d2',   // sha256 от post_content
  'updated_at' => '2025-01-15T10:30:00+03:00',
]
```

### Обновление версии

При сохранении страницы `consent` в WordPress-редакторе:

1. `ConsentController::handleConsentPageSave(int $postId, WP_Post $post)` срабатывает на хук `save_post`
2. Делегирует в `ConsentService::onConsentPageSaved(WP_Post $post)`
3. Сервис проверяет: не автосохранение, тип `page`, статус `publish`, slug `consent`
4. Вычисляет `sha256(post_content)` и записывает хэш + дату в `ConsentPageMeta`

История текстов сохраняется через **встроенные ревизии WordPress** (`wp_revisions`).

### ConsentService API

- `getCurrentVersion(ConsentType $type): string` — возвращает sha256-хэш из `ConsentPageMeta` (хэш = идентификатор версии)
- `getDocumentText(ConsentType $type, string $version): string` — возвращает `post_content` WP-страницы (параметры сигнатуры сохранены для обратной совместимости с `ConsentController`)
- `getDocumentHash(ConsentType $type, string $version): string` — возвращает sha256 из `ConsentPageMeta`
- `onConsentPageSaved(WP_Post $post): void` — пересчитывает мету при сохранении страницы
- `recordSelfConsent(...)` / `recordGuardianConsent(...)` — фиксируют подписание; в поле `version` таблицы `consents` записывается текущий sha256-хэш

### Версионирование

`version` в таблице `consents` = sha256-хэш текста на момент подписания. Это позволяет:
- Доказать, что конкретная версия текста существовала (хэш не изменить задним числом)
- Восстановить точный текст через ревизии WordPress по дате подписания

### Публичная страница

Администратор открывает `/consent` — стандартную WP-страницу. Шорткод не нужен: страница содержит `post_content` напрямую.

`ConsentController` также регистрирует маршрут `/lms/consent/{type}/{version}` для отображения текста по ссылке из записей аудита — возвращает актуальный `post_content` страницы.

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
- **Систему зачисления** с шифрованием PII, аудитом и реляционными таблицами
- **Email-шаблоны** через Strategy pattern с поддержкой редактирования в UI
- **Согласия на обработку ПД** с версионированием через sha256-хэш WP-страницы и ревизии WordPress

Все компоненты следуют принципам **SOLID** и используют паттерны проектирования для обеспечения поддерживаемости и расширяемости кода.