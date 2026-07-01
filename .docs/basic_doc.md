# Документация плагина FS LMS

## Оглавление

1. [Архитектура плагина](#архитектура-плагина)
2. [Словарь доменных папок](#словарь-доменных-папок-единый-по-слоям)
3. [DI Контейнер](#di-контейнер)
4. [Flow создания таксономии](#flow-создания-таксономии)
5. [Flow создания страниц](#flow-создания-страниц)
6. [Менеджеры и Регистраторы](#менеджеры-и-регистраторы)
7. [Сервисы](#сервисы)
8. [DTO (Data Transfer Objects)](#dto-data-transfer-objects)
9. [Enum (Перечисления)](#enum-перечисления)
10. [Трейты](#трейты)
11. [Контроллеры и Callbacks](#контроллеры-и-callbacks)
12. [Репозитории](#репозитории)
13. [Система зачисления](#система-зачисления)
14. [WPDB Репозитории](#wpdb-репозитории)
15. [Сервисы системы зачисления](#сервисы-системы-зачисления)
16. [Миграции](#миграции)
17. [Согласия на обработку ПД](#согласия-на-обработку-пд)
18. [Клиентская валидация форм](#клиентская-валидация-форм)
19. [Добавление нового контроллера](#добавление-нового-контроллера)
20. [Модульная архитектура](#модульная-архитектура)
21. [Фронтенд-страницы и шорткоды](#фронтенд-страницы-и-шорткоды)
22. [Роли и матрица прав](#роли-и-матрица-прав)
23. [Конфигурация wp-config.php](#конфигурация-wp-configphp)
24. [Конфигурация плагина и таб Конфигурация](#конфигурация-плагина-и-таб-конфигурация)
25. [Бот-защита публичных форм](#бот-защита-публичных-форм)
26. [Логирование и аудит: добавление лог-канала](#логирование-и-аудит-добавление-лог-канала)
27. [Кастомная авторизация: ошибки входа](#кастомная-авторизация-ошибки-входа)
28. [CsvExportService](#csvexportservice)
29. [Управление паролями пользователей](#управление-паролями-пользователей)
30. [Система уведомлений](#система-уведомлений)
31. [Troubleshooting](#troubleshooting)
32. [Система обучения (Этапы 1–4): контент, программа, сдачи, контрольные](#система-обучения-этапы-14-контент-программа-сдачи-контрольные)
33. [Система обучения (MVP-2 «Курсы»): шаги, конструктор, плеер, прогресс, клон, календарь](#система-обучения-mvp-2-курсы-шаги-конструктор-плеер-прогресс-клон-календарь)
34. [Типы задач (Этап 6): интерактивные задания, редактор, проверка, попытки](#типы-задач-этап-6-интерактивные-задания-редактор-проверка-попытки)
35. [Личный кабинет /profile/: вынос в приложение (Telegram / мобилка)](#личный-кабинет-profile-вынос-в-приложение-telegram-web-app--мобилка)

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
├── Callbacks/          # AJAX-обработчики — по доменным папкам*
├── Contracts/          # Интерфейсы (ServiceInterface, etc.)
├── Controllers/        # Контроллеры — по доменным папкам* (+ Builders/Pages/Subscribers)
├── Core/               # Ядро (Container, Init, Activate, Deactivate, Enqueue)
├── DTO/                # Data Transfer Objects — по доменным папкам*
├── Enums/              # Перечисления — по доменным папкам*
├── Managers/           # Менеджеры WP-API — по доменным папкам* (+ Wp)
├── MetaBoxes/          # Конфигурации метабоксов (Fields, Templates)
├── Migrations/         # Миграции БД
├── Modules/            # Изолируемые отключаемые модули (AdSync, SocialAuth)
├── Registrars/         # Регистраторы (фасады для менеджеров)
├── Repositories/       # Репозитории (OptionsRepositories, WPDBRepositories)
├── Services/           # Бизнес-логика — по доменным папкам*
└── Shared/             # Трейты + PluginLogger
    └── Traits/
```

\* Имена доменных папок — **единые во всех слоях**, см. [Словарь доменных папок](#словарь-доменных-папок-единый-по-слоям).

---

## Словарь доменных папок (единый по слоям)

Слои `Controllers/`, `Callbacks/`, `Services/`, `Managers/`, `DTO/`, `Enums/` разложены по
**подпапкам-доменам**. Главное правило: **одно имя папки = одно назначение во всех слоях** —
файл сортируется по тому, *о чём* он (домен), а не по тому, *что* он (тип). Заводишь новый класс —
найди его домен в таблице и положи в одноимённую папку своего слоя.

### Доменные папки

| Папка | Назначение | В каких слоях |
|---|---|---|
| `Auth` | Аутентификация / OAuth: вход, провайдеры, стратегии | Controllers, Callbacks, Services, Enums |
| `Enrollment` | Жизненный цикл заявки: подача, зачисление, отчисление, восстановление | Controllers, Callbacks, Services, DTO, Enums |
| `Application` | Данные/логика самой заявки (детализация `Enrollment`) | Services, DTO |
| `Person` | Люди: ПД (PII), согласия, профиль, пользователи | Controllers, Callbacks, Services, DTO, Enums |
| `Deletion` | Удаление/стирание данных: каскады, гард, обработчики | Controllers, Callbacks, Services |
| `Subject` | Банк предмета: предметы, статьи, кэш/резолв CPT, защита контента | Controllers, Callbacks, Services, Managers, DTO, Enums |
| `Task` | Типы задач: создание, бойлерплейты, метабоксы-шаблоны | Controllers, Callbacks, Services, DTO |
| `Course` | Курсы: уроки, работы, сдачи, модули, шаги, прогресс, журнал, расписание | Controllers, Callbacks, Services, Managers, DTO, Enums |
| `Assessment` | Контрольные: попытки, автопроверка, оценивание | Controllers, Callbacks, Services, Managers, DTO, Enums |
| `Problems` | Банк задач (problems) | Controllers |
| `Group` | Учебные группы: CRUD, кокпит, расписание (сервисы + контроллер) | Controllers, Callbacks, Services |
| `Settings` | Конфигурация плагина | Controllers, Callbacks, Managers, DTO, Enums |
| `Import` | CSV-импорт учеников/данных | Controllers, Callbacks, Services, DTO, Enums |
| `Export` | CSV-экспорт (в т.ч. логов) | Services, DTO, Enums |
| `Log` | Логирование и аудит: каналы, события, писатели | Controllers, Callbacks, Services, DTO, Enums |
| `Email` | Письма и шаблоны | Services, DTO, Enums |

### Служебные папки (специфичны для слоя)

| Папка | Слой | Назначение |
|---|---|---|
| `System` | Controllers, Callbacks, Services | WP-инфраструктура: меню/дашборд (Admin), диспетчер AJAX (Ajax), cron, генерация страниц |
| `Shared` | Services | Stateless-утилиты (`WpClock`, `ThemeCompatService`) |
| `Wp` | Managers, Enums | Обёртки WP-API (Post/CPT/Taxonomy/Term/MetaBox/Menu/Media/Cron) и enum'ы регистрации WP (`AjaxHook`, `Nonce`, `Menu`…) |
| `Access` | Enums | Права и роли: `Capability`, `UserRole`, `AccessLevel` |
| `Security` | Services | PII-крипто, генерация паролей, rate-limit, бот-защита |
| `Captcha` / `CaptchaProviders` | Services | Капча и провайдеры |
| `Template` | Services | Реестр/резолвер шаблонов метабоксов |
| `Builders` / `Pages` / `Subscribers` | Controllers | Сборщики конфигов / контроллеры публичных страниц / подписчики лог-каналов |

### Детализация и исключения

- **`Application` ⊂ `Enrollment`** и **`Task` ⊂ `Subject`** по смыслу, но вынесены в отдельные
  папки там, где классов много (Services / DTO / Callbacks). В `Managers`/`Enums` они не выделены
  (классов мало) и лежат в родительском домене (`TaskManager` → `Managers/Subject`).
- Папка может **отсутствовать** в слое, если у него нет классов этого домена — это нормально,
  имя всё равно закреплено за назначением.
- **`Group` и расписание**: всё групповое — в `Group` (CRUD, кокпит, контроллер расписания,
  сервисы расписания `ScheduleService`/`SessionCalendarService`). Но модель назначенных группе
  уроков `GroupLessonDTO` остаётся в `Course`, т.к. её использует вся логика доступа/видимости/
  гейтинга уроков; а сущность группы `StudentGroupDTO` — в `Enrollment`.

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
| `StudentRecordDTO` | Запись о зачислении (активная и архивная) |
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
| `AjaxHook` | AJAX-хуки с автогенерацией имён (`action()`, `jsAction()`, `callbackMethod()`, `toJsArray()`) |
| `ApplicationStatus` | Статусы заявки (`PendingParent` → `Converted`); метод `canTransitionTo()` — конечный автомат |
| `AuditAction` | Типы записей в `audit_log` |
| `AuthProvider` | Провайдеры OAuth-аутентификации (Google, GitHub, VK) |
| `Capability` | LMS-права (`ManageApplications`, `ViewPII`, `EnrollStudent` и др.) |
| `ConsentType` | Типы согласий на обработку ПД |
| `CronHook` | WP Cron хуки (`ExpireApplications`, `RetentionCleanup`, `RecoveryTick`) |
| `DocumentType` | Типы удостоверяющих документов |
| `EnrollmentStatus` | Статусы зачисления |
| `MenuSlug` | Слаги пунктов меню плагина |
| `MenuTitle` | Названия пунктов меню |
| `Nonce` | Nonce-токены; методы `create()` и `verify()` |
| `OptionName` | Ключи `wp_options` плагина — единственный источник правды |
| `PageRoutes` | Slug-маршруты фронтенд-страниц; методы `url()` и `isCurrent()` |
| `PageTitle` | Заголовки страниц |
| `PiiField` | Поля персональных данных |
| `PostMetaName` | Ключи мета-полей постов |
| `RelationType` | Типы связей опекун ↔ ученик |
| `ShortCode` | Шорткоды плагина; метод `tag()` возвращает `[код]` |
| `TableName` | Имена кастомных таблиц БД; метод `prefixed()` добавляет `$wpdb->prefix` |
| `TaskTemplate` | Типы шаблонов заданий |
| `UserRole` | Роли LMS; методы `label()`, `capabilities()`, `baseCapabilities()` |

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

**Все контроллеры:**

| Контроллер | Базовый класс | Назначение |
|-----------|---|-----------|
| `AdminController` | `BaseController` | Админ-меню, страницы настроек |
| `SubjectController` | `AjaxController` | Предметы, CPT, таксономии |
| `TaskCreationController` | `AjaxController` | Создание и управление заданиями |
| `MetaBoxController` | `AjaxController` | Метабоксы заданий |
| `BoilerplateController` | `AjaxController` | Типовые условия (boilerplates) |
| `BoilerplatePageController` | `BaseController` | Страница редактора boilerplate |
| `AuthController` | `BaseController` | OAuth-аутентификация (HybridAuth) |
| `AuthPageController` | `BaseController` | Страница входа, шорткоды логина |
| `ProfileController` | `BaseController` | Личный кабинет, маршрутизация |
| `UserController` | `BaseController` | Ограничение доступа в WP Admin, редирект после login |
| `ApplyPageController` | `AjaxController` | Страница и форма подачи заявки |
| `ApplicationController` | `AjaxController` | CRUD заявок в админке |
| `EnrollmentController` | `AjaxController` | Зачисление студентов |
| `PiiController` | `AjaxController` | PII-данные, управление представителями, экспорт CSV |
| `ConsentController` | `BaseController` | Согласия на обработку ПД, хук save_post |
| `AcademicPeriodController` | `AjaxController` | Управление учебными периодами |
| `StudentGroupController` | `AjaxController` | Управление группами студентов |
| `CronController` | `BaseController` | Регистрация WP Cron интервалов |
| `RecoveryController` | `BaseController` | Cron-задачи восстановления и retention |
| `TaskPageController` | `BaseController` | Фронтенд-страница задания (template_include) |

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

4. Администратор проверяет заявку и зачисляет
   └─ EnrollmentService::enroll()
      └─ PersonService создаёт WP-пользователя
      └─ PasswordLinkService::generate() — одноразовая ссылка на почту
      └─ RelationshipService::addRepresentative() — связь родитель↔ученик
      └─ EnrollmentRepository::create() — запись о зачислении

5. Аудит
   └─ AuditService::record() пишется на каждом значимом шаге
   └─ PersonReader::read() пишет в pii_access_log при каждом чтении PII
```

### Статусы заявки (`ApplicationStatus`)

| Статус | Описание |
|---|---|
| `pending_parent` | Ожидает заполнения родителем |
| `ready_for_review` | Родитель заполнил, ждёт проверки администратором |
| `enrolling` | Администратор начал оформление документов |
| `converted` | Зачислен (конечный статус) |
| `expired` | JOIN-код истёк, заявка не заполнена (конечный) |
| `trash` | Перемещён в корзину администратором (восстанавливаемо) |

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
- Email хэшируется аналогично (`emailKey()`), с нормализацией (`strtolower(trim())`) — сырой адрес в ключах не хранится
- Приватный `check( $key, $limit, $window = HOUR )` принимает длину окна параметром — для суточных лимитов передаётся `DAY_IN_SECONDS`

Лимиты по умолчанию:

| Действие | Метод | Ключ | Лимит | Окно |
|---|---|---|---|---|
| Подача заявки | `allowApplicationCreation($ip)` | IP | 5 | 1 час |
| Ввод JOIN-кода | `allowJoinAttempt($ip)` | IP | 10 | 1 час |
| Отправка данных родителем | `allowParentSubmit($ip)` | IP | 3 | 1 час |
| Чтение PII | `allowPiiReveal($userId)` | user_id | 100 | 1 час |
| Отправка OTP на email | `allowOtpSendForEmail($email)` | email | 5 | 1 сутки |

Лимит по email (`allowOtpSendForEmail`) дополняет IP-лимит и cooldown: защищает от **email-бомбинга** жертвы при ротации IP и ограничивает число заявок на один адрес. Все `allow*`-методы (кроме `allowPiiReveal`) возвращают `true` без проверки, если включено тестовое окружение (`PluginConfig::isTestEnv()`).

### EmailOtpService

Генерирует, отправляет и верифицирует одноразовые коды подтверждения email (шаг A/B формы заявки).

- `sendCode(string $email)` — генерирует 6-значный код, сохраняет sha256-хэш в transient (TTL 10 мин), отправляет письмо. При новом коде обнуляет счётчик неудачных попыток.
- `verify(string $email, string $code): bool` — сравнивает хэши через `hash_equals()`. Bypass-код берётся из `PluginConfig::otpBypassCode()` (константа `FS_LMS_OTP_BYPASS_CODE` или опция) — при совпадении возвращает `true` без проверки transient.
- `canResend(string $email): bool` — проверяет cooldown (60 сек).
- `invalidate(string $email)` — удаляет transient кода и cooldown.

**Защита от перебора кода (attempt cap):** каждая неверная попытка увеличивает счётчик в отдельном transient (`fs_lms_otp_att_*`). После `MAX_VERIFY_ATTEMPTS` (5) неверных вводов код инвалидируется — дальнейшие попытки бесполезны до повторной отправки. Так 6-значный код (1 млн комбинаций) нельзя перебрать даже при ротации IP.

> Зависимости конструктора: `EmailService` + `PluginConfig`. Bypass-код больше не читается напрямую из константы — только через `PluginConfig`, что позволяет задать его и через таб «Конфигурация» (см. раздел «Конфигурация плагина»).

**Конфигурационные константы (`wp-config.php`):**

| Константа | Назначение                                                                                                                        |
|---|-----------------------------------------------------------------------------------------------------------------------------------|
| `FS_LMS_TEST_ENV` | Тестовое окружение: письмо не отправляется, капча в `ApplicationCallbacks` пропускается. Открывает дебаг-маршрут `/lms/join/000`. |
| `FS_LMS_OTP_BYPASS_CODE` | Постоянный bypass-код: принимается вместо кода с почты в любом окружении. Удобно когда у ученика нет доступа к email.             |

Константы независимы. Без `FS_LMS_TEST_ENV` капча и письмо работают штатно; `FS_LMS_OTP_BYPASS_CODE` при этом всё равно принимается как валидный код.

**Дебаг-маршрут страницы родителя** (`FS_LMS_TEST_ENV`):

`GET /lms/join/000` — рендерит `join.php` с тестовыми данными без обращения к БД. Реализовано в `ApplicationCallbacks::prepareJoinPage()` — перехват до валидации формата и rate limit. В продакшне (без константы) адрес возвращает 404, так как `000` не соответствует формату `JOIN-XXXX-XXXX-XXXX`.

### CaptchaService

Тонкий фасад над `CaptchaProviderInterface`. Следует принципу OCP: смена провайдера не требует изменения сервиса и вызывающего кода.

```
CaptchaService
└── CaptchaProviderInterface
    ├── YandexSmartCaptchaProvider  (текущий провайдер по умолчанию)
    └── NullCaptchaProvider         (оставлен как запасной no-op)
```

- `validate(token, remoteIp): bool` — делегирует в провайдер
- `getSiteKey(): string` — клиентский ключ для фронтенда (пусто = виджет не рендерится)
- `isConfigured(): bool` — оба ключа заданы

**Текущий провайдер — `YandexSmartCaptchaProvider`** (`inc/Services/CaptchaProviders/`). `CaptchaService` тайп-хинтит его напрямую (autowiring), без биндинга интерфейса в контейнере — поэтому работает и в keyless-режиме.

- `validate()` → `POST https://smartcaptcha.yandexcloud.net/validate` (`secret` + `token` + `ip`), успех при `status == ok`.
- **Не настроен** (нет серверного ключа) → `validate()` возвращает `true` (форму держат OTP + honeypot + rate-limit).
- **Fail-open**: при сетевой ошибке / не-200 `validate()` возвращает `true` и пишет warning в `PluginLogger` — недоступность Яндекса не блокирует реальных пользователей.

**Ключи** хранятся как `captcha_site_key` / `captcha_server_key` в `PluginConfig` (опция + приоритет констант `FS_LMS_CAPTCHA_SITE_KEY` / `FS_LMS_CAPTCHA_SERVER_KEY`). Вводятся в таб «Конфигурация».

**Фронтенд (невидимый виджет):** `src/js/frontend/services/captcha.js`.
- `Enqueue` подключает `captcha.js` Яндекса на `/lms/apply` только если задан клиентский ключ; зависит от бандла `fs-lms-frontend-script`, поэтому глобальный колбэк `__fsSmartCaptchaReady` готов к onload.
- Модуль рендерит невидимый виджет в `#fs-captcha-slot` и выдаёт токен по требованию через `getCaptchaToken()` (промис, `execute()` → callback). После каждой попытки — `resetCaptcha()` (токен одноразовый).
- `apply-form.js` делает `await getCaptchaToken()` перед отправкой OTP (и на повторной отправке).

Проверка капчи в `ApplicationCallbacks::ajaxSendOtpCode()` пропускается при включённом тестовом окружении (`PluginConfig::isTestEnv()`).

**Как сменить провайдера капчи (на Turnstile/reCAPTCHA/hCaptcha):**
1. Создать класс в `inc/Services/CaptchaProviders/`, реализующий `CaptchaProviderInterface`.
2. Поменять тайп-хинт в конструкторе `CaptchaService` на новый класс (либо забиндить интерфейс в `Init::run()`).
3. Поправить фронтенд-рендер виджета (`captcha.js`) под API нового провайдера; серверный `ajaxSendOtpCode()` и `CaptchaService` остаются без изменений.

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

### Зачем нужны миграции

Основные данные плагина (предметы, таксономии, шаблоны) хранятся в `wp_options` как сериализованные массивы. Для них миграции не нужны — структура данных определяется PHP-кодом.

Система зачисления работает с другим классом данных: реляционные связи, большие объёмы записей, поиск по хэшам. Для этого созданы **7 выделенных таблиц** в MySQL. `dbDelta()` (WordPress) умеет создавать таблицы и добавлять колонки, но **никогда не удаляет** их — поэтому изменения схемы нужно версионировать явно.

### Структура

```
inc/Migrations/
├── MigrationRunner.php    — оркестратор, отслеживает версию в wp_options
├── Migration_1_0_0.php    — базовая схема: 7 таблиц системы зачисления + секция cleanup
├── Migration_1_0_1.php    — добавлена колонка join_code_enc в applications
└── Migration_1_0_2.php    — изменён тип group_id в enrollments: bigint → varchar(100)
```

Регистрация всех миграций — в `inc/Core/Activate.php`. При добавлении нового файла нужно зарегистрировать его там же.

### MigrationInterface

```php
interface MigrationInterface {
    public function up(): void;        // применить миграцию
    public function down(): void;      // откатить миграцию
    public function version(): string; // semver: '1.0.1'
}
```

### Как работает MigrationRunner

Текущая версия схемы хранится в `wp_options` под ключом `fs_lms_schema_version` (`OptionName::SchemaVersion`).

**`run()`:**
1. Сортирует зарегистрированные миграции по `version_compare`
2. Применяет только те, чья версия выше текущей
3. Обновляет опцию версии после успешного применения

**`rollback()`:** откатывает все миграции в обратном порядке (от новой к старой); удаляет опцию версии.

**`reset()`:** устанавливает версию в `'0.0.0'` — при следующем `run()` все миграции применятся заново. Только для dev-окружения.

`run()` вызывается в `Activate::activate()` (`register_activation_hook`) — таблицы создаются или обновляются при каждой активации плагина.

### Таблицы

| Таблица | Репозиторий | Назначение |
|---|---|---|
| `{prefix}persons` | `PersonRepository` | Персональные данные (зашифрованные) |
| `{prefix}applications` | `ApplicationRepository` | Заявки на зачисление |
| `{prefix}relationships` | `RelationshipRepository` | Связи опекун ↔ ученик |
| `{prefix}enrollments` | `EnrollmentRepository` | Зачисления на предметы |
| `{prefix}consents` | `ConsentRepository` | Согласия на обработку ПД |
| `{prefix}audit_log` | `AuditLogRepository` | Аудит-лог действий |
| `{prefix}pii_access_log` | `PiiAccessLogRepository` | Лог доступа к PII |

### Когда создавать новый файл миграции

**Создавать новый `Migration_1_0_X.php` при:**
- добавлении новой таблицы
- добавлении колонки в существующую таблицу
- изменении типа или дефолта существующей колонки
- добавлении или удалении индекса

**Не создавать новый файл при:**
- удалении колонки — см. секцию "Cleanup" ниже

### Удаление колонки — без нового файла

`dbDelta()` не дропает колонки. Вместо отдельного файла на каждый дроп:

1. Убрать колонку из DDL (`CREATE TABLE`) в `Migration_1_0_0::up()`
2. Добавить `DROP COLUMN IF EXISTS` в секцию "Cleanup" в конце того же `up()`:

```php
// ===== Cleanup: удаление колонок, убранных из схемы =====
// Добавлять сюда при удалении любой колонки вместо создания нового файла миграции.
$wpdb->query( "ALTER TABLE `$applications` DROP COLUMN IF EXISTS `rejected_reason`" );
```

3. Сбросить версию схемы в dev и перезапустить:

```bash
docker exec wp_db mariadb -u root -proot wordpress \
  -e "UPDATE wp_options SET option_value='0.0.0' WHERE option_name='fs_lms_schema_version';"
```

Или вызвать `MigrationRunner::reset()` из кода, затем `run()`. После сброса все миграции выполнятся заново: `1_0_0` пересоздаст таблицы + выполнит cleanup, `1_0_1` и `1_0_2` — применятся идемпотентно (оба проверяют наличие колонки перед изменением).

### Добавление новой миграции — пример

Нужно добавить колонку `notes text NULL` в таблицу `applications`:

**Шаг 1.** Создать `inc/Migrations/Migration_1_0_3.php`:

```php
declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\TableName;

class Migration_1_0_3 implements MigrationInterface {

    public function version(): string {
        return '1.0.3';
    }

    public function up(): void {
        global $wpdb;
        $table = TableName::Applications->prefixed();

        $cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ); // phpcs:ignore
        if ( ! in_array( 'notes', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `notes` text NULL" ); // phpcs:ignore
        }
    }

    public function down(): void {
        global $wpdb;
        $table = TableName::Applications->prefixed();
        $wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN IF EXISTS `notes`" ); // phpcs:ignore
    }
}
```

**Шаг 2.** Зарегистрировать в `inc/Core/Activate.php`:

```php
$migration_runner->register( new Migration_1_0_3() );
```

Runner применит её автоматически при следующей активации плагина.

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
| `new_representative` | `sendNewRepresentativeNotification` | `{display_name}`, `{link}` |

---

## Согласия на обработку ПД

### Архитектура

Каждый тип согласия — это **отдельная WP-страница**. Набор типов динамический: администратор добавляет их через UI в настройках плагина (вкладка «Согласия»). Определения хранятся в `wp_options`.

| Слой | Файл | Роль |
|---|---|---|
| Определения типов | `wp_options[fs_lms_consent_definitions]` | Ключ → название + page_id |
| Источник текста | WP-страница, создаётся при добавлении типа | Редактируемый текст согласия |
| История версий | WP-ревизии WP-страницы | Архив всех версий текста |
| Репозиторий | `inc/Repositories/OptionsRepositories/ConsentDefinitionsRepository.php` | CRUD определений |
| Контроллер | `inc/Controllers/ConsentController.php` | Rewrite rule + template_include |
| Сервис | `inc/Services/ConsentService.php` | Чтение текста, хэширование, фиксация согласий |

### Структура wp_options

```php
// fs_lms_consent_definitions
[
  'pd_processing' => [
    'name'    => 'Согласие на обработку персональных данных',
    'page_id' => 42,
  ],
  // другие типы, добавленные администратором
]
```

### Версионирование

Версия = `sha256(post_content)`, вычисляется на лету — ничего дополнительно в `wp_options` не сохраняется.

| Понятие | Как работает |
|---|---|
| Текущая версия | `hash('sha256', $page->post_content)` |
| История версий | WP-ревизии страницы (`wp_get_post_revisions()`) |
| Идентификатор версии в БД | sha256-хэш текста на момент подписания |

История версий хранится **в штатных ревизиях WordPress** — новая ревизия создаётся при каждом сохранении страницы в редакторе.

### ConsentService API

- `getCurrentVersion(string $typeKey): string` — возвращает sha256-хэш текущего `post_content` страницы согласия
- `getDocumentText(string $typeKey, string $version): string` — возвращает текст по хэшу: сначала проверяет текущую версию, затем перебирает WP-ревизии; выбрасывает `RuntimeException` если не найдено
- `getDefinitionName(string $typeKey): string` — человекочитаемое название типа
- `getPageForType(string $typeKey): ?WP_Post` — WP-страница типа (или `null`)
- `recordSelfConsent(?int $appId, string $typeKey, RequestContextDTO $ctx): int` — фиксирует подписание субъектом; в `consents.version` записывается sha256-хэш актуального текста
- `recordGuardianConsent(?int $appId, string $typeKey, int $forPersonId, RequestContextDTO $ctx): int` — фиксирует подписание законным представителем

Ключ типа — строка (`string`), не `ConsentType` enum. Enum `ConsentType` сохранён только для обратной совместимости с существующими записями в таблице `consents`.

### Активация

`Activate::createDefaultConsentIfNeeded()` при активации плагина проверяет:
- Есть ли определение `pd_processing` в `ConsentDefinitionsRepository`
- Если да — существует ли WP-страница и опубликована ли

Если что-то не так — создаёт (или пересоздаёт) страницу `lms-consent-pd-processing` со статусом `publish` и текстом-рыбой по 152-ФЗ, сохраняет определение. Операция **идемпотентна**.

### Управление в настройках

Вкладка «Согласия» (`settings-5-consents.php`) в FS LMS → Настройки:

- **Таблица** — все определённые типы согласий
- **Аккордеон** — история версий (текущая + WP-ревизии) с датой, sha256-хэшем и ссылкой на архивный просмотр
- **Кнопка «Добавить согласие»** — открывает модальное окно, вводится название и ключ (авто-транслитерация), создаётся черновик WP-страницы + запись в definitions
- **Удалить** — убирает определение из options; WP-страница остаётся (для сохранности истории)

AJAX-хуки (регистрируются в `SettingsController` → `ConsentSettingsCallbacks`):

| Хук | Метод | Действие |
|---|---|---|
| `add_consent_definition` | `ajaxAddConsentDefinition` | Создаёт страницу + определение |
| `delete_consent_definition` | `ajaxDeleteConsentDefinition` | Удаляет определение |
| `lookup_consent_by_hash` | `ajaxLookupConsentByHash` | Ищет версию по sha256 среди всех типов |

### Публичные маршруты

#### Текущий текст: WP-страница напрямую

Для ссылки «Прочитать» в форме join используется обычный `get_permalink($pageId)` — стандартная WP-страница `/lms-consent-pd-processing/` со стилями темы.

#### Архивный просмотр: `/lms/consent/{key}/{hash}/`

Маршрут для просмотра конкретной исторической версии. Используется в аккордеоне настроек.

`ConsentController::loadConsentTemplate()`:
1. Проверяет определение по `$typeKey` через `ConsentService::getPageForType()`
2. Получает текст нужной версии через `ConsentService::getDocumentText($typeKey, $hash)`
3. **Устанавливает WP-запрос как обычную страницу** (`$wp_query->is_page = true`, `queried_object = $page`, `status_header(200)`)
4. Перехватывает `the_content` фильтром → подменяет содержимое версионированным текстом
5. Возвращает шаблон темы (`get_page_template()`) — тема применяет свои стили

Это обеспечивает корректный рендеринг в любой теме без отдельных стилей плагина.

### Flow подписания согласия (join-форма)

```
1. ApplicationCallbacks::prepareJoinPage()
   └── resolveConsentUrl('pd_processing')
       └── get_permalink($pageId) → '/lms-consent-pd-processing/'
   └── set_query_var('fs_lms_consent_url', $url)

2. join.php отображает ссылку «Прочитать» (если URL не пустой)

3. Родитель нажимает «Заключить договор»
   └── ApplicationService::submitParentData()
       └── ConsentService::recordGuardianConsent($appId, 'pd_processing', ...)
           └── getCurrentVersion('pd_processing') → sha256(post_content)
           └── consentRepository->create([..., 'version' => $hash, ...])
```

Если страница согласия не опубликована или не создана — `recordGuardianConsent` выбрасывает `RuntimeException`, которое перехватывается в `ApplicationService` с `error_log` (заявка не прерывается).

### Инварианты

- Каждый тип согласия — отдельная WP-страница; одна страница — один тип
- Версия = sha256 без кэша в options; при изменении текста версия меняется автоматически
- `consents.version` и `consents.document_hash` содержат sha256-хэш текста на момент подписания
- Ключ типа в коде — строка (`'pd_processing'`), не `ConsentType` enum
- Для подписания в формах заявки всегда используется ключ `'pd_processing'`

---

## JavaScript архитектура

### Структура директорий

```
src/js/
├── admin/
│   ├── admin.js          — точка входа; jQuery $(document).ready()
│   ├── _types.js         — JSDoc-типы для window-глобалов (fs_lms_vars, fs_lms_task_data)
│   ├── components/       — только UI, AJAX запрещён (модальные окна, виджеты)
│   ├── services/         — AJAX + бизнес-логика, оркестрирует компоненты
│   └── modules/          — общие утилиты (modal-base, utils, ui-авторегистратор)
├── frontend/
│   ├── frontend.js       — точка входа; чистый DOMContentLoaded
│   ├── components/       — только UI, AJAX запрещён (вкладки, карусели)
│   └── services/         — AJAX + бизнес-логика (apply-form)
└── common/
    ├── common.js         — точка входа
    └── components/       — общие UI-компоненты для обеих сторон
```

### Паттерны экспорта

#### Admin — объектный паттерн (jQuery)

Все файлы в `admin/components/` и `admin/services/` экспортируют объект с методом `init()`:

```js
// src/js/admin/services/my-service.js
import { ConfirmModal } from '../components/confirm-modal.js';

const $ = jQuery;

export const MyService = {
    init() {
        ConfirmModal.init();
        this.bindEvents();
    },
    bindEvents() {
        $( document ).on( 'click', '.my-btn', this.handleClick.bind( this ) );
    },
    handleClick( e ) { ... },
};

// admin.js:
// if ( $( '.my-trigger' ).length ) { MyService.init(); }
```

#### Frontend — функциональный паттерн (pure JS)

Все файлы в `frontend/components/` и `frontend/services/` экспортируют именованную функцию `initX()`:

```js
// src/js/frontend/services/my-form.js
const vars = window.fs_lms_my_vars;

export function initMyForm() {
    if ( ! window.fs_lms_my_vars ) { return; }
    if ( ! document.getElementById( 'my-form' ) ) { return; }
    document.getElementById( 'my-form' ).addEventListener( 'submit', handleSubmit );
}

// frontend.js:
// import { initMyForm } from './services/my-form.js';
// document.addEventListener( 'DOMContentLoaded', () => { initMyForm(); });
```

#### Modules — именованные функции

```js
// src/js/admin/modules/modal-base.js
export function openModal( $modal ) { ... }
export function closeModal( $modal ) { ... }
```

### Правила разделения по директориям

| Директория | Может делать AJAX? | jQuery? | Пример |
|---|---|---|---|
| `admin/components/` | ❌ Нет | ✅ Да | `confirm-modal.js`, `subject-modal.js` |
| `admin/services/` | ✅ Да | ✅ Да | `applications-table.js`, `boilerplates.js` |
| `admin/modules/` | ❌ Нет | ✅ Да | `modal-base.js`, `utils.js` |
| `frontend/components/` | ❌ Нет | ❌ Нет | `task-tabs.js`, `article-carousel.js` |
| `frontend/services/` | ✅ Да | ❌ Нет | `apply-form.js` |
| `common/components/` | ❌ Нет | ✅ Да | `toggle-secret.js`, `badge.js` |

### Авторегистратор компонентов (admin)

`admin/modules/ui.js` использует `require.context` для автоматической загрузки всех файлов из `admin/components/`. Компоненты **не нужно импортировать вручную** в `admin.js` — авторегистратор вызывает их `init()` сам. Сервисы импортируются и инициализируются в `admin.js` вручную.

### Глобальные переменные (window)

Все вызовы `wp_localize_script()` — **только в `Enqueue.php`**, никогда в шаблонах.

| Переменная | Где доступна | Содержимое |
|---|---|---|
| `fs_lms_vars` | все страницы плагина в админке | `ajaxurl`, `ajax_actions`, nonces |
| `fs_lms_task_data` | страницы CPT `_tasks` | `ajax_url`, `nonce`, `subject_key`, `post_type` |
| `fs_lms_apply_vars` | фронтенд `/lms/apply` | `ajax_url`, `actions`, `nonces`, `captcha_key` |
| `fs_lms_applications_vars` | админ `fs_lms_userlist` | `nonces.trash` |

AJAX-экшены доступны через `fs_lms_vars.ajax_actions.camelCaseName` — экспортируются из `AjaxHook::toJsArray()` в формате `['camelCaseName' => 'snake_case_action']`.

### Добавление нового AJAX-модуля

**Admin-сервис:**
1. Создать `src/js/admin/services/my-service.js`, экспортировать `export const MyService = { init() {} }`
2. Добавить `import { MyService } from './services/my-service.js'` в `admin.js`
3. Инициализировать с гардом: `if ( $( '.my-trigger' ).length ) { MyService.init(); }`

**Frontend-сервис:**
1. Создать `src/js/frontend/services/my-feature.js`, экспортировать `export function initMyFeature() {}`
2. Добавить импорт и вызов в `frontend.js` внутри `DOMContentLoaded`

**Локализация переменных:**
1. Добавить `wp_localize_script()` в `Enqueue.php` с условием по `$page` или `$screen->id`
2. Обращаться из JS через `window.fs_lms_*_vars`

---

## Клиентская валидация форм

### Расположение файлов

```
src/js/common/
├── validators/
│   ├── BaseValidator.js                 — базовый класс; нативные HTML5-атрибуты (required, minlength, email, pattern)
│   ├── PhoneValidator.js                — телефон: +7(999)-000-00-00
│   ├── AddressValidator.js             — кириллица, пробелы, дефис
│   ├── CyrillicNameValidator.js         — кириллица, пробелы, дефис + минимум 2 слова
│   ├── LatinOnlyValidator.js            — латиница, цифры, подчёркивание
│   ├── PassportSeriesNumberValidator.js — серия и номер паспорта: XXXX XXXXXX
│   └── index.js                        — реестр ключей: { phone, cyrillic, cyrillicName, latinOnly, passportSN, default }
├── validation-manager.js               — привязка событий, рендер ошибок, validateAll
└── input-masks.js                      — маски ввода: телефон, паспорт, ИНН

src/scss/common/components/
└── _validation.scss          — стили ошибок (.fs-form-group.form-invalid, .fs-field-error)
```

### Архитектура

Система состоит из трёх независимых слоёв:

| Слой | Файл | Ответственность |
|---|---|---|
| Валидатор | `validators/MyValidator.js` | Только логика проверки значения |
| Реестр | `validators/index.js` | Маппинг ключ → экземпляр валидатора |
| Менеджер | `validation-manager.js` | Сканирование DOM, события, рендер ошибок |

### Таблица валидаторов

| Ключ `data-validate` | Класс | Что проверяет |
|---|---|---|
| _(не указан)_ | `BaseValidator` | Только нативные HTML5-атрибуты — см. таблицу ниже |
| `phone` | `PhoneValidator` | Формат `+7(999)-000-00-00`; ровно 11 цифр |
| `cyrillic` | `CyrillicValidator` | Только кириллица (`А–Я а–я Ё ё`), пробелы, дефис |
| `cyrillicName` | `CyrillicNameValidator` | Кириллица, пробелы, дефис **+ минимум 2 слова** (ФИО) |
| `latinOnly` | `LatinOnlyValidator` | Только латиница (`A–Z a–z`), цифры (`0–9`), `_` |
| `passportSN` | `PassportSeriesNumberValidator` | Ровно 4 цифры, пробел, 6 цифр (`4507 123456`) |

**Нативные проверки `BaseValidator` (применяются для всех валидаторов автоматически):**

| Атрибут HTML | Условие срабатывания | Сообщение об ошибке |
|---|---|---|
| `required` | Поле пустое | «Поле обязательно для заполнения.» |
| `type="email"` | Значение не email | «Введите корректный адрес электронной почты.» |
| `minlength` | Длина меньше `minlength` | «Минимальное количество символов: N. Вы ввели: M.» |
| `pattern` | Значение не совпадает с pattern | «Значение заполнено неверно.» |

Пустое поле без `required` всегда считается валидным — кастомные проверки к нему не применяются.

### Контракт валидатора

Все валидаторы наследуют `BaseValidator` и переопределяют `checkCustom`:

```js
// src/js/common/validators/MyValidator.js
import { BaseValidator } from './BaseValidator.js';

export class MyValidator extends BaseValidator {
    checkCustom( value, input ) {
        if ( ! /^\d{10}$/.test( value ) ) {
            return 'Должно быть 10 цифр.';
        }
        return null; // null = поле валидно
    }
}
```

`BaseValidator.validate(input)` автоматически обрабатывает нативные HTML5-атрибуты (`required`, `minlength`, `type="email"`) через `ValidityState` — переопределять их в `checkCustom` не нужно.

### Добавление нового валидатора (3 шага)

**Шаг 1.** Создать файл `src/js/common/validators/InnValidator.js`:

```js
import { BaseValidator } from './BaseValidator.js';

export class InnValidator extends BaseValidator {
    checkCustom( value ) {
        if ( value.length !== 10 && value.length !== 12 ) {
            return 'ИНН должен содержать 10 или 12 цифр.';
        }
        if ( ! /^\d+$/.test( value ) ) {
            return 'ИНН должен содержать только цифры.';
        }
        return null;
    }
}
```

**Шаг 2.** Зарегистрировать в `src/js/common/validators/index.js`:

```js
import { InnValidator } from './InnValidator.js';

export const FieldValidators = {
    phone:        new PhoneValidator(),
    cyrillicName: new CyrillicNameValidator(),
    cyrillic:     new CyrillicValidator(),
    latinOnly:    new LatinOnlyValidator(),
    passportSN:   new PassportSeriesNumberValidator(),
    inn:          new InnValidator(), // ← добавить
    default:      new BaseValidator()
};
```

**Шаг 3.** Добавить `data-validate` к инпуту в шаблоне:

```html
<input type="text" name="inn" data-validate="inn" required>
```

Больше ничего не нужно. Менеджер подхватит атрибут автоматически.

### Привязка к форме

#### Автоматически (через common.js)

Формы с атрибутом `data-fs-validate` или классом `.fs-lms-form` подхватываются глобальным менеджером в `common.js` автоматически при загрузке страницы:

```html
<form data-fs-validate>
    <div class="fs-form-group">
        <input type="tel" data-validate="phone" required>
    </div>
</form>
```

#### Вручную (для форм с AJAX-сабмитом)

Если форма обрабатывается своим JS-модулем (как `apply-form.js`), вызвать `initFormValidation` явно и использовать возвращённую функцию `validateAll()` перед AJAX-запросом:

```js
import { initFormValidation } from '../../common/validation-manager.js';

export function initMyForm() {
    const form = document.getElementById( 'my-form' );
    if ( ! form ) { return; }

    const validateAll = initFormValidation( form ); // привязывает blur + input

    form.addEventListener( 'submit', async ( e ) => {
        e.preventDefault();
        if ( ! validateAll() ) { return; } // показывает ошибки, фокусирует первый невалидный
        // ... AJAX
    } );
}
```

### Требования к разметке

Каждый валидируемый инпут должен быть обёрнут в контейнер с классом `.fs-form-group`:

```html
<div class="fs-form-group">
    <input type="tel" name="phone" data-validate="phone" required>
</div>
```

Менеджер добавляет класс `form-invalid` на контейнер и вставляет `<p class="fs-field-error">` с текстом ошибки. Стили `.fs-form-group.form-invalid` описаны в `src/scss/common/components/_validation.scss` и применяются на всех страницах через `common.min.css`.

На формах с собственной компонентной стилизацией (например, `apply.php`) добавлять `fs-form-group` как дополнительный класс: `class="fs-apply-card__field-group fs-form-group"`.

### Позиционирование сообщения об ошибке

Элемент `.fs-field-error` всегда вставляется внутрь `.fs-form-group` — позиционирование управляется только CSS.

**По умолчанию** (ошибка под инпутом, подходит для модалок):

```html
<div class="fs-form-group">
    <input type="text" data-validate="cyrillicName" required>
</div>
```

**Модификатор `--inline`** (ошибка справа от инпута, для форм на сайте):

```html
<div class="fs-form-group fs-form-group--inline">
    <input type="text" data-validate="cyrillicName" required>
</div>
```

`fs-form-group--inline` переключает контейнер на `display: flex`, инпут занимает оставшееся место (`flex: 1 1 auto`), ошибка прижимается справа (`flex: 0 0 auto`, `white-space: nowrap`).

Если все поля в форме должны быть inline — можно не добавлять модификатор на каждую группу, а задать flex-раскладку через класс самой формы:

```scss
.my-site-form {
  .fs-form-group {
    display: flex;
    align-items: center;
    gap: 10px;

    input, select, textarea { flex: 1 1 auto; min-width: 0; }
    .fs-field-error         { flex: 0 0 auto; margin: 0; white-space: nowrap; }
  }
}
```

Все стили находятся в `src/scss/common/components/_validation.scss`.

### Поведение при валидации

| Событие | Действие |
|---|---|
| `blur` (потеря фокуса) | Запускает валидатор, рендерит ошибку если есть |
| `input` (ввод символа) | Убирает ошибку (мягкий сброс, не перепроверяет) |
| `submit` | Проверяет все поля, показывает все ошибки, фокусирует первый невалидный |

---

### Маски ввода (input-masks.js)

Файл `src/js/common/input-masks.js` содержит функции форматирования ввода (маски). Маски отличаются от валидаторов: маска форматирует значение в процессе набора, валидатор проверяет корректность при blur/submit.

**Экспорты:**

| Функция | Описание |
|---|---|
| `formatPhone(input)` | Форматирует значение `<input>` по маске `+7(XXX)-XXX-XX-XX` |
| `bindPhoneMask(input)` | Навешивает обработчики `focus`, `input`, `keydown` для телефонного поля |
| `formatPassportSN(input)` | Форматирует значение по маске `XXXX XXXXXX` (серия и номер паспорта) |
| `bindInnMask(input)` | Разрешает только цифры, ограничивает длину до 12 символов |

**Использование:**

```js
import { bindPhoneMask, formatPassportSN, bindInnMask } from '../../common/input-masks.js';

bindPhoneMask( document.getElementById( 'fs_phone' ) );
formatPassportSN( document.getElementById( 'fs_passport' ) );
bindInnMask( document.getElementById( 'fs_inn' ) );
```

`bindPhoneMask` вызывает `formatPhone` внутри, отдельный вызов `formatPhone` нужен только для однократного форматирования уже заполненного поля.

**Кто импортирует:**
- `src/js/frontend/services/apply-form.js` — `bindPhoneMask` для поля телефона на странице `/lms/apply`
- `src/js/frontend/services/join-form.js` — `bindPhoneMask` + `formatPassportSN` для формы вступления

**Добавить новую маску:**
1. Добавить экспортируемую функцию в `src/js/common/input-masks.js`
2. Импортировать её в нужном сервисе

---

## Добавление нового контроллера

### BaseController vs AjaxController

| Ситуация | Базовый класс |
|---|---|
| Только WP-хуки (события, фильтры, шорткоды) | `extends BaseController implements ServiceInterface` |
| Нужны AJAX-хуки (`wp_ajax_*`, `wp_ajax_nopriv_*`) | `extends AjaxController` |

`AjaxController` сам реализует `ServiceInterface`. `BaseController` даёт `$plugin_path`, `$plugin_url`, `path()`, `url()` и трейт `AjaxResponse`.

### Шаблон нового AJAX-контроллера

```php
declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\MyCallbacks;
use Inc\Enums\AjaxHook;

class MyController extends AjaxController {

    public function __construct(
        private readonly MyCallbacks $callbacks,
    ) {
        parent::__construct();
    }

    public function register(): void {
        add_action( 'init', array( $this, 'doSomething' ) ); // прочие хуки

        $this->registerAjaxHooks(); // регистрирует всё из ajaxActions() и publicAjaxActions()
    }

    // wp_ajax_{action} — только авторизованные пользователи
    protected function ajaxActions(): array {
        return [
            [ AjaxHook::MyAction, $this->callbacks ],
        ];
    }

    // wp_ajax_{action} + wp_ajax_nopriv_{action} — все пользователи
    protected function publicAjaxActions(): array {
        return [
            [ AjaxHook::MyPublicAction, $this->callbacks ],
        ];
    }
}
```

`AjaxController::registerAjaxHooks()` помечен `final` — не переопределять.

### Шаг 2: регистрация в Init

```php
// inc/Init.php
private static function getServices(): array {
    return [
        // ...
        MyController::class, // DI-контейнер сам создаст все зависимости
    ];
}
```

### Шаг 3: добавить AjaxHook (если нужен новый)

```php
// inc/Enums/AjaxHook.php
case MyAction = 'MyAction';
// Автоматически генерирует:
// WP-хук:  wp_ajax_my_action  (→ action())
// JS-ключ: my_action          (→ jsAction())
// Метод:   ajaxMyAction()     (→ callbackMethod())
```

### Шаг 4: реализовать callback

```php
// inc/Callbacks/MyCallbacks.php
class MyCallbacks extends BaseController {

    public function ajaxMyAction(): void {
        $this->authorize( Nonce::Manager, Capability::Admin ); // nonce + capability
        // ... логика
        $this->success( [ 'key' => 'value' ] );
    }
}
```

---

## Модульная архитектура

Модули — **изолируемые листовые подсистемы**, которые можно выключить (тумблером / константой) или полностью вырезать (удалением каталога + одной строки в `Init`), не ломая ядро.

Когда фичу нужно оформить как модуль:
- её можно безопасно отключить и ядро продолжит работать;
- она имеет собственные настройки в UI (таб или секцию);
- в будущем её может не быть в некоторых инсталляциях.

Обычная фича в ядре (`inc/Controllers/`, `inc/Services/` и т.д.) — если она всегда нужна.

### Структура модуля

```
inc/Modules/MyModule/
├── MyModule.php                    # Bootstrap; единственный класс, известный ядру
├── Config/
│   └── MyModuleConfig.php          # isEnabled() + toggle() + константы опции
├── Controllers/
│   ├── MyModuleController.php      # Рантайм-логика (хуки, маршруты); регистр. только если включён
│   └── MyModuleSettingsController.php  # Настройки UI; регистрируется ВСЕГДА
├── Callbacks/
│   └── MyModuleCallbacks.php       # AJAX-обработчики
├── Services/
│   └── ...                         # Сервисы модуля (namespace Inc\Modules\MyModule\Services)
├── Repositories/
│   └── ...                         # Репозитории модуля (если нужны)
├── Enums/
│   └── ...
├── templates/
│   └── settings-tab.php            # или settings-section.php
└── assets/                         # self-contained скрипты/стили модуля (не в core-бандле)
    └── admin.js
```

### Bootstrap-класс

```php
// inc/Modules/MyModule/MyModule.php
namespace Inc\Modules\MyModule;

use Inc\Contracts\ServiceInterface;
use Inc\Modules\MyModule\Config\MyModuleConfig;
use Inc\Modules\MyModule\Controllers\MyModuleController;
use Inc\Modules\MyModule\Controllers\MyModuleSettingsController;

class MyModule implements ServiceInterface {

    public function __construct(
        private readonly MyModuleSettingsController $settings,
        private readonly MyModuleController         $runtime,
    ) {}

    public function register(): void {
        $this->settings->register(); // ВСЕГДА — чтобы Dashboard знал о модуле и toggle работал в обе стороны

        if ( ! MyModuleConfig::isEnabled() ) {
            return;             // рантайм-контроллер молча не подключается
        }

        $this->runtime->register();
    }
}
```

Добавить в `Init::getServices()` в секцию опциональных модулей:

```php
// inc/Init.php — секция «Опциональные модули»
MyModule::class,
```

### Feature-флаги — три уровня выключения

| Уровень | Способ | Когда |
|---|---|---|
| **1. Жёсткий** | Константа в `wp-config.php`: `define('FS_LMS_MY_MODULE', false)` | Стейдж/прод, нельзя включить из UI |
| **2. Тумблер** | Опция в БД через Dashboard | Обычное вкл/выкл администратором |
| **3. Вырезание** | Удалить каталог `inc/Modules/MyModule/` + строку `MyModule::class` в `Init` | Релиз без фичи |

```php
// inc/Modules/MyModule/Config/MyModuleConfig.php
class MyModuleConfig {

    public const OPTION = 'fs_lms_my_module';

    private const DEFAULTS = [ 'enabled' => false ];

    public static function isEnabled(): bool {
        if ( defined( 'FS_LMS_MY_MODULE' ) ) {
            return (bool) constant( 'FS_LMS_MY_MODULE' );
        }
        $stored = get_option( self::OPTION, [] );
        return (bool) ( $stored['enabled'] ?? false );
    }

    public static function toggle( bool $enabled ): void {
        $current            = get_option( self::OPTION, self::DEFAULTS );
        $current['enabled'] = $enabled;
        update_option( self::OPTION, $current );
    }
}
```

### Правило изоляции

**Ядро никогда не импортирует классы модуля.** Единственная точка связи — строка `MyModule::class` в `Init::getServices()`. Всё остальное общение через WP-хуки:

```
ядро                                 модуль
─────────────────────────────────────────────────────────────
apply_filters('fs_lms_settings_tabs', $tabs)  ←──  addSettingsTab()
apply_filters('fs_lms_dashboard_modules', []) ←──  registerDashboardModule()
do_action('fs_lms_config_sections', $subj)   ←──  renderSection()
do_action('fs_lms_module_toggle_{id}', $on)  ←──  onToggle()
```

Модуль может использовать **публичные** классы ядра (`Nonce`, `Capability`, `BaseController` и т.д.) — это нормально. Ядро — нет.

### Settings-контроллер (всегда активен)

`MyModuleSettingsController` регистрируется независимо от `isEnabled()`. Минимальный набор хуков:

```php
public function register(): void {
    add_filter( 'fs_lms_dashboard_modules', [ $this, 'registerDashboardModule' ] );
    add_action( 'fs_lms_module_toggle_my_module', [ $this, 'onToggle' ] );

    // Опционально: таб в Настройках или секция в Конфигурации
    add_filter( 'fs_lms_settings_tabs', [ $this, 'addSettingsTab' ] );  // если нужен таб
    add_action( 'fs_lms_config_sections', [ $this, 'renderSection' ] ); // если нужна секция
}

public function registerDashboardModule( array $modules ): array {
    $modules[] = [
        'id'           => 'my_module',
        'title'        => 'Мой модуль',
        'description'  => 'Краткое описание, что даёт модуль и что исчезнет при отключении.',
        'enabled'      => MyModuleConfig::isEnabled(),
        'const_locked' => defined( 'FS_LMS_MY_MODULE' ),
        'const_key'    => 'FS_LMS_MY_MODULE',
    ];
    return $modules;
}

public function onToggle( bool $enabled ): void {
    MyModuleConfig::toggle( $enabled );
}

// Вкладка показывается только когда модуль включён
public function addSettingsTab( array $tabs ): array {
    if ( ! MyModuleConfig::isEnabled() ) {
        return $tabs;
    }
    $tabs['tab-X'] = [
        'title' => 'Мой модуль',
        'path'  => FS_LMS_PATH . 'inc/Modules/MyModule/templates/settings-tab.php',
    ];
    return $tabs;
}

// Секция в Конфигурации показывается только когда модуль включён
public function renderSection( array $subjects ): void {
    if ( ! MyModuleConfig::isEnabled() ) {
        return;
    }
    require FS_LMS_PATH . 'inc/Modules/MyModule/templates/settings-section.php';
}
```

### Dashboard: регистрация карточки модуля

Страница **FS LMS → Статистика** (Dashboard) показывает карточки всех модулей. Карточка появляется автоматически при регистрации через `fs_lms_dashboard_modules`.

Поля массива карточки:

| Ключ | Тип | Назначение |
|---|---|---|
| `id` | `string` | Уникальный slug (snake_case); используется в `data-module` и хуке `fs_lms_module_toggle_{id}` |
| `title` | `string` | Заголовок карточки |
| `description` | `string` | Что даёт модуль и что исчезнет при отключении |
| `enabled` | `bool` | Текущее состояние тумблера |
| `const_locked` | `bool` | `true` — тумблер заблокирован, состояние задано константой |
| `const_key` | `string` | Имя константы (`FS_LMS_MY_MODULE`) — отображается в UI |

AJAX-сохранение тумблера — `ModulesDashboardController` (`wp_ajax_fs_lms_toggle_module`). Он вызывает `do_action("fs_lms_module_toggle_{id}", $enabled)` — модуль сохраняет себя сам через `onToggle()`.

### Скрипты модуля

Два варианта в зависимости от контекста.

#### Вариант A — self-contained (рекомендуется для модуля)

Скрипт живёт в `inc/Modules/MyModule/assets/admin.js` (вне `src/js/`, вне Webpack/Gulp-бандла) и подключается только на нужной странице:

```php
// MyModuleSettingsController::register()
add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAssets' ] );

public function enqueueAssets( string $hook ): void {
    // Только на нужной странице, например таба Настройки
    if ( 'fs_lms_page_fs_lms_settings' !== $hook ) {
        return;
    }
    $rel = 'inc/Modules/MyModule/assets/admin.js';
    wp_enqueue_script(
        'fs-lms-my-module',
        $this->url( $rel ),
        [ 'jquery' ],
        (string) filemtime( $this->path( $rel ) ),
        true
    );
    wp_localize_script( 'fs-lms-my-module', 'fsLmsMyModule', [
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => Nonce::Config->create(),
    ] );
}
```

`assets/admin.js` — обычный IIFE-скрипт, jQuery берётся из `window.jQuery`:

```js
( function ( $ ) {
    $( function () {
        // логика модуля
    } );
}( jQuery ) );
```

#### Вариант B — добавить в core-бандл

Используется если UI-компонент нужен на многих страницах или уже есть похожий паттерн в `src/js/admin/services/`. Добавить файл в `src/js/admin/services/my-module.js` и импортировать в `admin.js`:

```js
// src/js/admin/services/my-module.js
const $ = jQuery; // обязательно — $ не в скоупе ES6-модуля

export const MyModuleService = {
    init() { /* ... */ },
};
```

```js
// src/js/admin/admin.js
import { MyModuleService } from './services/my-module.js';
// ...
if ( $( '.js-my-module-trigger' ).length ) {
    MyModuleService.init();
}
```

После добавления запустить сборку: `npx gulp scripts`.

**Правило:** если скрипт нужен только на одной странице модуля — Вариант A. Если пересекается с core-UI (формы, модалки, общие компоненты) — Вариант B.

### Стили модуля

Аналогично скриптам:

| Вариант | Когда | Как |
|---|---|---|
| **SCSS в core-бандле** | Стили нужны на страницах ядра (Dashboard, Settings) или используют core-переменные | Создать `src/scss/admin/components/_my-module.scss`, добавить `@use 'components/my-module';` в `admin.scss`, запустить `npx gulp styles:admin` |
| **Standalone CSS** | Стили нужны только на одной специфичной странице модуля | `inc/Modules/MyModule/assets/admin.css`, подключить через `wp_enqueue_style` рядом со скриптом |

В `_my-module.scss` **обязательно** использовать токены из `src/scss/admin/_variables.scss`:

```scss
@use '../variables' as *;

.fs-my-module-card {
    padding: $spacing-xl;
    border: 1px solid $color-border-input;
    border-radius: $border-radius-l;
}
```

### Существующие модули

| Модуль | Bootstrap | Опция | Константа | Что исчезает при отключении |
|---|---|---|---|---|
| `Inc\Modules\SocialAuth` | `SocialAuthModule` | `fs_lms_plugin_config['social_auth_enabled']` | `FS_LMS_SOCIAL_AUTH` | Вкладка «Авторизация» в Настройках; OAuth-маршруты `/lms-auth/*` |
| `Inc\Modules\AdSync` | `AdSyncModule` | `fs_lms_ad_sync['enabled']` | `FS_LMS_AD_SYNC` | Секция «Синхронизация с доменом (AD)» в Конфигурации; REST-эндпоинты `/ad/jobs`, `/ad/ack` |

---

## Фронтенд-страницы и шорткоды

При активации плагина `PageGeneratorService` автоматически создаёт страницы WordPress (если они ещё не существуют).

### Созданные страницы

| Slug | URL | Шорткод | Контроллер | Назначение |
|---|---|---|---|---|
| `sign-in` | `/sign-in/` | `[fs_lms_login_form]` | `AuthPageController` | Вход в личный кабинет |
| `profile` | `/profile/` | `[fs_lms_profile]` | `ProfileController` | Личный кабинет |
| `apply` | `/apply/` | `[fs_lms_apply_form]` | `ApplyPageController` | Форма подачи заявки |
| `consent` | `/consent/` | — | `ConsentController` | Текст согласия на обработку ПД |

Маршруты централизованы в `PageRoutes`:

```php
PageRoutes::SignIn->url();       // https://site.ru/sign-in/
PageRoutes::SignIn->isCurrent(); // true, если пользователь на этой странице
```

### Шорткоды (`ShortCode` enum)

| Шорткод | `ShortCode::` | Регистрирует |
|---|---|---|
| `[fs_lms_login_form]` | `LoginForm` | `AuthPageController` |
| `[fs_lms_profile]` | `Profile` | `ProfileController` |
| `[fs_lms_apply_form]` | `ApplyForm` | `ApplyPageController` |
| `[fs_lms_register_form]` | `RegisterForm` | зарезервирован |

Все шорткоды возвращают строку через `ob_start()`. Тема уже выводит header/footer — `ThemeCompatService` в шаблонах шорткодов не используется.

### Страница задания (без шорткода)

`/tasks/{slug}` — фронтенд-страница задания подключается через `template_include` в `TaskPageController`. Тема здесь не оборачивает контент, поэтому шаблон обязан использовать `ThemeCompatService::header()` / `ThemeCompatService::footer()`.

### Автоматические редиректы

`ProfileController` регистрирует хук `template_redirect`:
- Незалогиненный на `/profile/` → редирект на `/sign-in/`
- Залогиненный на `/sign-in/` → редирект на `/profile/`

`UserController` через `UserManager` ограничивает доступ LMS-ролей к WP Admin и направляет их после логина на `/profile/`.

---

## Роли и матрица прав

### Роли пользователей (`UserRole`)

| Case | WP slug | Назначение |
|---|---|---|
| `FSOffice` | `lms_office` | Администратор LMS: заявки, зачисление, PII |
| `FSTeacher` | `lms_teacher` | Преподаватель: задания, статистика |
| `FSStudent` | `lms_student` | Ученик: учебные материалы |
| `FSParent` | `lms_parent` | Родитель: просмотр прогресса ребёнка |
| `Student` | `lms_student_free` | Внешний ученик (OAuth, без подписки) |
| `Teacher` | `lms_teacher_free` | Внешний учитель (OAuth, без подписки) |

Роли создаются при активации плагина через `RoleManager`. Матрица прав определена в `UserRole::capabilities()` и автоматически синхронизируется при обновлении плагина.

### Матрица Capability

| Capability | `FSOffice` | `FSTeacher` | Остальные |
|---|---|---|---|
| `manage_options` (WP Admin) | — | — | — |
| `manage_applications` | ✓ | — | — |
| `enroll_student` | ✓ | — | — |
| `view_pii` | ✓ | — | — |
| `manage_persons` | ✓ | — | — |
| `view_lms_stats` | — | ✓ | — |
| `manage_lms_assignments` | — | ✓ | — |

`manage_options` — только у стандартного WP-администратора (роль `administrator`).

### Проверка прав в коде

```php
// В Callback-классах (авторизованный AJAX) — через трейт Authorizer:
$this->authorize( Nonce::Manager, Capability::ManageApplications );

// В публичных AJAX-хуках (nopriv, без capability):
Nonce::ParentSubmit->verify();

// В шаблонах:
if ( current_user_can( Capability::ViewPII->value ) ) { ... }
```

---

## Конфигурация wp-config.php

Все константы плагина задаются в `wp-config.php`.

### Обязательные

Без этих констант плагин не активируется:

| Константа | Назначение |
|---|---|
| `FS_LMS_ENC_KEY` | Ключ шифрования PII — base64, 32 байта (libsodium XSalsa20-Poly1305) |
| `FS_LMS_HASH_SALT` | Соль SHA-256 для поиска по документу без расшифровки |

Генерация ключа:
```bash
php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"
```

```php
// wp-config.php
define( 'FS_LMS_ENC_KEY',   'base64-строка-32-байта' );
define( 'FS_LMS_HASH_SALT', 'произвольная-длинная-строка' );
```
Пример:

```php
define('FS_LMS_ENC_KEY', 'NBOXQlFD5LSlVWuSGrmJvDJEPvhHyPdG1bVDVlDPZKI');

define('FS_LMS_HASH_SALT','d807045d9872d8aeaa629e8c67e3f73c06879756fd7af5c4481c37d726c3fd71');
```

### Опциональные

| Константа | Назначение |
|---|---|
| `FS_LMS_TEST_ENV` | Тестовое окружение: капча/honeypot/rate-limit пропускаются, OTP-письмо не отправляется, открывает `/lms/join/000` |
| `FS_LMS_OTP_BYPASS_CODE` | Постоянный bypass-код OTP — принимается вместо кода с почты в любом окружении |
| `DADATA_API_TOKEN` | API-токен DaData для автодополнения адреса/ФИО в формах `/lms/apply` и `/lms/join` |
| `FS_LMS_CAPTCHA_SITE_KEY` | Клиентский ключ Yandex SmartCaptcha |
| `FS_LMS_CAPTCHA_SERVER_KEY` | Серверный ключ Yandex SmartCaptcha (валидация токена) |
| `FS_LMS_SOCIAL_AUTH` | Модуль SocialAuth (OAuth через Google/VK/GitHub): `true`/`false`. Перекрывает тумблер из БД. По умолчанию (если не задана) — модуль **включён** (backward-compat). |
| `FS_LMS_AD_SYNC` | Модуль AdSync (синхронизация заявок с Active Directory): `true`/`false`. **Перекрывает** тумблер из БД (жёсткое вкл/выкл для стейджа/прод). Если не задана — действует тумблер на Dashboard. |
| `FS_LMS_AD_HMAC_SECRET` | Секрет для HMAC-подписи запросов к Python-сервису AD. В БД **не** хранится. См. `.docs/WpToADTasks.md` и `.docs/FS_LMS_API.md`. |

> **Модульные флаги** задаются на Dashboard (FS LMS → Статистика → Модули) или через константы выше.
> Тумблер из UI сохраняется в БД; константа в `wp-config.php` перекрывает его.
> Подробная архитектура — раздел [Модульная архитектура](#модульная-архитектура) и `.docs/ModularArchitecture.md`.

Константы независимы. `FS_LMS_OTP_BYPASS_CODE` работает даже без `FS_LMS_TEST_ENV`.

Без `DADATA_API_TOKEN` автодополнение молча отключается. Без ключей капчи виджет не рендерится, а `validate()` пропускает (fail-open).

> **Опциональные значения теперь дублируются в БД.** «Мягкая четвёрка» (`DADATA_API_TOKEN`, `FS_LMS_TEST_ENV`, `FS_LMS_OTP_BYPASS_CODE`, оба ключа капчи) также задаётся через админ-таб «Конфигурация» и хранится в `wp_options`. **Приоритет: константа из `wp-config.php` > значение из БД.** Если константа задана — поле в UI заблокировано (бейдж «wp-config»). Ключи шифрования (`FS_LMS_ENC_KEY`/`FS_LMS_HASH_SALT`) в БД **не** хранятся никогда. Подробнее — раздел «Конфигурация плагина».

Пример:

```php
define( 'FS_LMS_TEST_ENV',   true );
define( 'FS_LMS_OTP_BYPASS_CODE',   '0000' );
```

---

## Конфигурация плагина и таб Конфигурация

Помимо констант `wp-config.php`, часть настроек редактируется из админки (**Настройки → Конфигурация**, `tab-7`) и хранится в `wp_options`.

### Компоненты

| Слой | Файл | Роль |
|---|---|---|
| Опция | `OptionName::PluginConfig` → `fs_lms_plugin_config` | Один ключ `wp_options` со структурой-массивом |
| Репозиторий | `PluginConfigRepository` | `get()` (мержит с `DEFAULTS`), `save($partial)` (пишет только известные ключи, autoload=false) |
| Сервис чтения | `PluginConfig` (`Services/Shared/`) | Типизированные геттеры с приоритетом константы над БД; `viewState()` — payload для шаблона |
| Enum констант | `ConfigConstant` | Имена констант + `label()` + `isSecret()` |
| AJAX | `ConfigController` + `ConfigCallbacks` | `ajaxSaveConfig` (`Nonce::Config`, `Capability::Admin`), `ajaxGenerateKey` (генерация ключей шифрования) |
| View | `settings-7-config.php` | Поля «мягкой четвёрки» + ключи капчи + блок генерации ключей шифрования |
| JS | `services/settings/config-settings.js` | Сохранение, генерация ключа, copy-to-clipboard |

### Правило приоритета

`PluginConfig::dadataToken()` и аналоги: **если определена константа `wp-config.php` — возвращается она, иначе значение из БД.** В `viewState()` поле помечается `defined_in_config=true` и `editable=false` → в UI заблокировано с бейджем «wp-config». Ключи шифрования (`FS_LMS_ENC_KEY`/`FS_LMS_HASH_SALT`) в БД не хранятся: `viewState()` отдаёт только `*_set` (bool), значение наружу не уходит.

### Keyless-режим

Если `PiiCryptoService::isAvailable()` вернул `false` (нет/битый `FS_LMS_ENC_KEY`), `fs-lms.php` поднимает **минимальный бутстрап**: только `Enqueue` + `AdminController` + `ConfigController`, плюс admin notice. Так администратор может зайти в таб «Конфигурация», сгенерировать ключи и вставить их в `wp-config.php`, не активируя весь плагин.

### Как добавить новое значение конфигурации

1. **`PluginConfigRepository::DEFAULTS`** — добавить ключ со значением по умолчанию.
2. **`PluginConfig`** — геттер (с override константы, если нужен) + запись в `viewState()`.
3. **`ConfigConstant`** — case, если у значения будет override через `wp-config.php`.
4. **`ConfigCallbacks::ajaxSaveConfig()`** — добавить ключ в массив `save()` (через `Sanitizer`).
5. **`settings-7-config.php`** — поле ввода (бейдж «wp-config» при `defined_in_config`).
6. **`config-settings.js`** — добавить поле в payload `saveConfig()`.

---

## Бот-защита публичных форм

Форма заявки `/lms/apply` защищена слоями (порядок проверки в `ApplicationCallbacks::ajaxSendOtpCode()`):

1. **Nonce** (`Nonce::Apply`).
2. **FormGuardService** — honeypot + тайминг (дёшево, до траты бюджета на капчу/письма).
3. **Rate-limit по IP** (`allowApplicationCreation`).
4. **Капча** Yandex SmartCaptcha (если не test-env).
5. **Rate-limit по email** (`allowOtpSendForEmail`) — анти-бомбинг.
6. **OTP-cooldown** (`canResend`, 60 сек).

Плюс на шаге верификации — **attempt-cap** в `EmailOtpService::verify()` (см. раздел EmailOtpService).

### FormGuardService (`Services/Security/`)

Stateless, без хранения состояния между рендером и сабмитом.

- `honeypotField(): string` — имя скрытого поля-ловушки (`fs_company`). В разметке обёрнуто в `.fs-hp` (скрыто CSS, `aria-hidden`, `tabindex=-1`). Бот заполняет → отказ.
- `timestampToken(): string` — `"{ts}.{hmac}"`, подпись HMAC на `FS_LMS_HASH_SALT`.
- `isHuman($honeypot, $token): bool` — honeypot пуст **и** прошло `[MIN_FILL_SECONDS=3 .. MAX_TOKEN_AGE=1ч]`. В test-env всегда `true`.

**Проводка на фронте:** `Enqueue` кладёт `hp_field` и `form_token` в `fs_lms_apply_vars`; honeypot-`<input>` — статически в `apply.php`; `apply-form.js` форвардит оба значения в `send_otp`.

### Как защитить ещё одну публичную форму

1. В шаблон — honeypot-`<input name="<honeypotField()>">` внутри `.fs-hp`.
2. В локализацию формы — `hp_field` + `form_token` (`FormGuardService` через DI в `Enqueue`).
3. На сабмите (JS) — форвардить honeypot и `form_token`.
4. На сервере (callback) — `if ( ! $this->formGuard->isHuman( $hp, $token ) ) { $this->error(...); }`.

---

## Логирование и аудит: добавление лог-канала

Событийная шина: источник `dispatch()` → `LogEventDispatcher` → подписчики `subscribe()` → writer → лог-репозиторий → таблица.

### Компоненты

| Слой | Где | Роль |
|---|---|---|
| Каталог событий | `Enums/LogEvent` | Словарь доменных событий (`subject.created`, `student.enrolled`, …) |
| Каналы | `Enums/LogChannel` | 8 каналов: `label()` + `tableName()` |
| Шина | `Services/Log/LogEventDispatcher` (`LogEventDispatcherInterface`, singleton) | `subscribe()` / `dispatch()`; изоляция ошибок подписчиков |
| Event-DTO | `DTO/Log/Events/*` | Полезная нагрузка события |
| Подписчики | `Controllers/Subscribers/*` (`ServiceInterface`) | В `register()` подписывают события на writer |
| Writers | `Services/Log/*LogWriter` | Формируют запись и пишут через лог-репозиторий |
| Лог-репозитории | `Repositories/WPDBRepositories/Log/*` | `insert` + `list/count` с фильтрами |
| Отображение | `AdminCallbacks::logsPage()` + `logs-N-*.php` | Таб с фильтрами/сортировкой; экспорт — `CsvExportProvider` |

> Канал **Auth** — особый: пишется не через шину, а через WP-хуки (`wp_login`, `wp_login_failed`, …) в `AuthLogController`.

### Как добавить новый канал

1. **Таблица** — в `Migration_1_0_0::up()` и `down()` + case в `Enums/TableName`. Для dev сбросить `fs_lms_schema_version` в `0.0.0` (перезапуск миграций).
2. **`LogChannel`** — case + `label()` + `tableName()`.
3. **Лог-репозиторий** в `WPDBRepositories/Log/` — `insert()` и `list()/count()` с фильтрами.
4. **Writer** в `Services/Log/`.
5. **События** — case(ы) в `LogEvent` + event-DTO в `DTO/Log/Events/`.
6. **Подписчик** в `Controllers/Subscribers/` (`ServiceInterface`): в `register()` `subscribe()` события → writer. Зарегистрировать в `Init::getServices()`.
7. **Источник** — `$this->logEvents->dispatch( LogEvent::X, new XEvent(...) )` строго **после** успешной операции/коммита транзакции.
8. **UI** (если нужен таб) — шаблон `logs-N-*.php` + ветка в `AdminCallbacks::logsPage()`; для экспорта — `CsvExportProvider`.

### Как поправить существующий канал (добавить поле)

1. Колонка — в `Migration_1_0_0::up()` + строка в секции Cleanup; сбросить версию схемы.
2. Лог-репозиторий — добавить колонку в `insert()` и в выборку `list()`; обновить DTO.
3. Writer — пробросить новое значение.
4. Таб `logs-N-*.php` — колонка в таблице; при необходимости — фильтр и колонка в `CsvExportProvider`.

---

## Кастомная авторизация: ошибки входа

Форма входа (`auth-page.php`) постит на `wp-login.php`. При неверных кредах стандартный WP перерисовывает свою страницу — чтобы пользователь оставался на нашей, ошибки перехватываются.

- **`AuthPageController::redirectFailedLogin()`** на хуке `wp_login_failed` (**приоритет 20** — позже `AuthLogController` на 10, чтобы попытка успела залогироваться до `redirect + exit`).
- Срабатывает **только** если в POST есть скрытый маркер `fs_lms_login` (наша форма) — прямой вход админа на `wp-login.php` не затрагивается.
- Редиректит на `sign-in` с `?login=failed&fs_user=<логин>`. Шаблон показывает единое сообщение «Неверный логин или пароль» (без раскрытия, что именно неверно — анти-энумерация) и префилл поля логина.
- Успешный вход → штатный `redirect_to`.

---

## CsvExportService

**Файл:** `inc/Services/CsvExportService.php`

Генерирует CSV и создаёт одноразовые ссылки для скачивания. Реализует паттерн **Column Projection**: сервис не знает о доменных объектах — вызывающий код передаёт данные и описание колонок через `CsvColumn`.

### Использование

```php
use Inc\DTO\CsvColumn;

// 1. Генерация CSV-строки
$csv = $csvExportService->export(
    rows: $enrollments, // любой iterable: массив, DTO[], генератор
    columns: [
        new CsvColumn( 'ФИО',     fn( $r ) => $r['student_name'] ),
        new CsvColumn( 'Предмет', fn( $r ) => $r['subject'] ),
        new CsvColumn( 'Телефон', fn( $r ) => $r['phone'] ?? '' ),
    ]
);

// 2. Одноразовая ссылка для скачивания
$url = $csvExportService->createDownloadLink( $csv, 'students.csv' );
// URL вида /lms/export/{token}, обрабатывается PiiController
```

Формат: UTF-8 с BOM, разделитель — запятая. BOM нужен для корректного открытия в Excel.

---

## Управление паролями пользователей

**Файл:** `inc/Services/PasswordGeneratorService.php`

### Где хранится пароль

Для каждого LMS-пользователя пароль хранится в **двух местах**:

| Место | Назначение |
|---|---|
| `wp_users.user_pass` | Хэш пароля — используется WordPress при аутентификации |
| `wp_usermeta[fs_lms_enc_password]` | Зашифрованный (libsodium) plaintext — используется для отображения пароля администратором через UI |

`user_pass` — стандартное WP-поле, его изменение немедленно меняет реальный пароль. `fs_lms_enc_password` — только для функции «Раскрыть учётные данные»; восстановить plaintext из хэша невозможно, поэтому он хранится отдельно.

### API сервиса

| Метод | Когда использовать |
|---|---|
| `storeEncrypted(int $userId, string $password)` | Новый пользователь создан с уже известным паролем через `wp_insert_user()` — только сохранить зашифрованную копию в meta, `user_pass` уже установлен |
| `setFromPlain(int $userId, string $password)` | Установить конкретный пароль существующему пользователю (вызывает `wp_set_password()` + записывает meta) |
| `generateAndSet(int $userId)` | Сгенерировать случайный 8-символьный пароль существующему пользователю (вызывает `wp_set_password()` + записывает meta); возвращает plaintext |
| `getCredentials(int $userId)` | Вернуть `['login' => ..., 'password' => ...]` для показа администратору; `null` если meta отсутствует (пароль был сменён вручную) |
| `randomize(int $userId)` | Установить случайный 64-символьный пароль и **удалить** meta — используется при блокировке аккаунта после удаления ПД |

### Установка пароля при зачислении

`EnrollmentService::enroll()` создаёт пользователей вне транзакции. Стратегия зависит от того, новый пользователь или уже существующий:

**Новый пользователь** (основной сценарий):

```php
// Пароль генерируется ДО wp_insert_user()
$password = $dto->loginPassword !== '' ? $dto->loginPassword : wp_generate_password( 8, false );
$userId   = $this->userManager->create( array(
    'user_login' => $login,
    'user_pass'  => $password,   // → сразу попадает в user_pass
    ...
) );
// Только сохранить зашифрованную копию — wp_set_password() НЕ вызывается
$this->passwordGenerator->storeEncrypted( $userId, $password );
```

Это важно: `wp_insert_user()` устанавливает пароль напрямую в БД и **не вызывает** `wp_clear_auth_cookie()`. Если бы мы создавали пользователя с временным паролем и потом вызывали `wp_set_password()` — браузер администратора получил бы `Set-Cookie: expired` и администратор разлогинился бы после зачисления.

**Существующий пользователь** (редкий сценарий — student с тем же email уже зарегистрирован):

```php
// wp_set_password() вызывается, так как пароль нужно обновить существующему аккаунту
$this->passwordGenerator->setFromPlain( $userId, $dto->loginPassword );
// или:
$password = $this->passwordGenerator->generateAndSet( $userId );
```

### Ученик и Родитель: различие источников пароля

| Роль | Источник пароля |
|---|---|
| `FSStudent` | Логин и пароль — то, что ученик ввёл в форме `/lms/apply` (`StudentDataDTO::username`, `loginPassword`) |
| `FSParent` | Логин — email из формы `/lms/join`; пароль — генерируется через `wp_generate_password(8, false)` |

### Смена пароля через WP Admin

Когда администратор меняет пароль пользователя через стандартный интерфейс WordPress, вызывается `wp_update_user()` → `wp_set_password()`. Поле `user_pass` обновляется, но `fs_lms_enc_password` остаётся с прежним значением — `getCredentials()` вернул бы старый, уже недействительный пароль.

Чтобы этого не происходило, `UserController` вешает хук:

```php
add_action( 'profile_update', array( $this->user_manager, 'clearEncryptedPasswordIfChanged' ), 10, 2 );
```

`UserManager::clearEncryptedPasswordIfChanged()` сравнивает хэши `user_pass` до и после сохранения. Если они различаются — удаляет `fs_lms_enc_password`. После этого `getCredentials()` вернёт `null`.

### Регенерация пароля из UI

Если `getCredentials()` возвращает `null`, AJAX-метод `ajaxRevealUserCredentials()` отвечает ошибкой. В модале карточки родителя JS-код показывает кнопку «Сгенерировать новый пароль». Клик вызывает AJAX `regenerate_user_password`:

```
AjaxHook::RegenerateUserPassword → ajaxRegenerateUserPassword()
  → PasswordGeneratorService::generateAndSet(userId)
     → wp_set_password()           ← устанавливает новый пароль в user_pass
     → user_repository->updateMeta ← сохраняет зашифрованную копию в meta
  → success(['password' => $password])
```

После успешного ответа JS заполняет поле пароля в модале и убирает кнопку.

**Nonce:** `Nonce::RevealPii` — уже передаётся в `fs_lms_applications_vars.nonces.revealPii`.  
**Capability:** `ManageApplications`.

### Раскрытие учётных данных

`ajaxRevealUserCredentials()` — AJAX `reveal_user_credentials`:

```
getCredentials(userId)
  ├─ есть meta → расшифровать → вернуть {login, password} + записать pii_access_log
  └─ нет meta  → error('Пароль недоступен...') → JS показывает кнопку «Сгенерировать новый»
```

---

## Система уведомлений

Все уведомления в плагине разделены на четыре механизма по типу ошибки и контексту.

### Обзор: что когда использовать

| Ситуация | Механизм |
|---|---|
| Ошибка валидации поля формы | Нативная браузерная валидация возле поля (`validation-manager.js`) |
| Ошибка сохранения / загрузки / экспорта | Toast (`showToast`) |
| Критическая ошибка внутри открытого модала | Alert-модал (`AlertModal.show`) |
| Встроенное уведомление внутри UI (не AJAX) | `showNotice` / `showModalError` |

---

### 1. Нативная браузерная валидация

**Файлы:** `src/js/common/validators/`, `src/js/common/validation-manager.js`

Управляется системой валидаторов (подробно описана в разделе [Клиентская валидация форм](#клиентская-валидация-форм)). Ошибка рендерится внутри `.fs-form-group` в виде `<p class="fs-field-error">` — рядом с конкретным полем.

Применяется только для ошибок формата ввода: обязательные поля, формат email, телефон, кириллица и т.п. Для AJAX-ошибок этот механизм не используется.

---

### 2. showNotice — WP-style admin notice

**Файл:** `src/js/admin/modules/utils.js`

```js
import { showNotice } from '../modules/utils.js';

showNotice( message, type, $container, options );
```

| Параметр | Тип | Описание |
|---|---|---|
| `message` | `string` | Текст уведомления |
| `type` | `'success' \| 'error' \| 'warning' \| 'info'` | Цветовой вариант |
| `$container` | `JQuery \| null` | Контейнер вставки; по умолчанию `$('body')` |
| `options.autoDismiss` | `boolean` | Автозакрытие для `success` (по умолчанию `true`) |
| `options.autoDismissDelay` | `number` | Задержка в мс (по умолчанию `1000`) |
| `options.escape` | `boolean` | Экранировать HTML (по умолчанию `true`) |

Рендерит стандартную WordPress-плашку `.notice.is-dismissible` с кнопкой закрытия. Используется для серверных ошибок и успехов, когда нет открытого модала — вставляется в `.wrap` страницы или внутрь `.fs-lms-modal-body`.

**Где применяется:**
- Ошибки сервера (не network) при наличии открытого модала: внутрь `$modal.find('.fs-lms-modal-body')`
- Успешные операции вне модалок: внутрь `$row.closest('.wrap')`
- Статические страницы (настройки, шаблоны писем)

**Не использовать** вместо toast для AJAX-ошибок соединения — для этого есть `showToast`.

---

### 3. showModalError / clearModalError — inline-ошибка в модале

**Файл:** `src/js/admin/modules/utils.js`

```js
import { showModalError, clearModalError } from '../modules/utils.js';

showModalError( 'Выберите предмет.', this.$modal ); // показать
clearModalError( this.$modal );                      // скрыть
```

Вставляет `<p class="fs-modal-error">` в начало `.fs-lms-modal-body`. Стили — в `_modal.scss` (`.fs-modal-error`: красная полоса слева, фон `rgba(danger, 0.06)`).

Применяется для ошибок **валидации на уровне данных** внутри модала — когда сервер вернул `success: false` с конкретной причиной (дубликат, неверный диапазон дат и т.п.). Очищается перед каждым новым сохранением через `clearModalError`.

---

### 4. showToast — всплывающее уведомление

**Файл:** `src/js/admin/modules/toast.js`

```js
import { showToast } from '../modules/toast.js';

showToast( message, type, duration );
```

| Параметр | Тип | По умолчанию |
|---|---|---|
| `message` | `string` | — |
| `type` | `'error' \| 'success' \| 'warning' \| 'info'` | `'error'` |
| `duration` | `number \| null` | `error`/`warning` → 4000 мс; остальные → 2500 мс |

Toast появляется в правом нижнем углу экрана, накапливается вертикально, автоматически скрывается. Закрыть вручную — кнопкой `×`. Удержание курсора приостанавливает таймер.

**Z-index:** 1000100 — выше любых модалов и alert-модала.

**SCSS:** `src/scss/admin/components/_toast.scss`.

**Где применяется:** сетевые ошибки (`$.fail()`), т.е. когда запрос вообще не дошёл до сервера. Используется как fallback в `apiError()` и `apiErrorEnhanced()` из `utils.js`:

```js
// utils.js — автоматически при отсутствии onNotify
apiError( 'Failed to save' );           // → showToast('Произошла ошибка при связи с сервером.', 'error')
apiErrorEnhanced( error );              // → showToast(userMessage, 'error')
```

---

### 5. AlertModal — критическая ошибка поверх модала

**Файлы:** `src/js/admin/components/alert-modal.js`, `templates/admin/components/modals/alert-modal.php`

```js
import { AlertModal } from '../components/alert-modal.js';

// Показать — возвращает Promise, резолвится при нажатии ОК или Escape/Enter
await AlertModal.show( 'Произошла критическая ошибка.', 'Ошибка' );
```

| Параметр | Тип | По умолчанию |
|---|---|---|
| `message` | `string` | — |
| `title` | `string` | `'Ошибка'` |

Открывается поверх уже открытых модалов. Единственная кнопка — «ОК». Закрывается также по `Escape` и `Enter`. Не трогает класс `html.modal-open` — блокировка скролла уже выставлена родительским модалом.

**Z-index:** 1000050 — выше обычных модалов (999999), ниже toast.

**SCSS:** модификатор `.fs-lms-alert-modal { z-index: $z-modal-alert }` в `_modal.scss`; структура — та же, что у `confirm-modal`.

**Инициализация:** `AlertModal.init()` вызывается в `admin.js` одним из первых, независимо от страницы:

```js
// admin.js
AlertModal.init(); // кэширует #fs-lms-alert-modal из DOM
```

**HTML-шаблон** подключается глобально в `Enqueue::render_confirm_modal()` (хук `admin_footer`) на всех страницах плагина (`fs_*`, `student_*`).

**Когда использовать:**
- Ошибка состояния, которая блокирует дальнейшие действия и требует осознанного подтверждения пользователем
- Ситуации, когда `showNotice` / `showModalError` не подходят из-за вложенного модала

**Не использовать** для ошибок сети (`$.fail`) — для этого есть `showToast`.

---

### Z-index стека

```
$z-modal-root:  999999  — обычные модальные окна
$z-modal-alert: 1000050 — AlertModal (поверх обычных)
$z-toast:       1000100 — Toast (поверх всего)
```

Переменные — в `src/scss/admin/_variables.scss`.

---

## Передача данных в таблицы и модалки (Userlist)

### Общая схема

Данные проходят два независимых пути к интерфейсу:

```
ПУТЬ 1 — Рендеринг таблицы (PHP, при загрузке страницы)
DB → Repository::findActive...() → PHP-шаблон → HTML-строка таблицы
                                           └── data-* атрибуты на <tr> и кнопках

ПУТЬ 2 — Заполнение модалки (JS, после клика)
<tr data-enrollment="...">  →  немедленный pre-fill модалки (без ожидания)
                ↓
AJAX getPersonData  →  ajaxGetPersonData()  →  fill() поверх pre-fill
```

Двухшаговое заполнение нужно для мгновенного отклика (UX): модалка открывается с данными из строки таблицы сразу, а AJAX догружает PII-данные (они не хранятся в HTML из соображений безопасности).

---

### Данные в строке таблицы учеников

`templates/admin/components/tabs/userlist-tabs/userlist-2-students.php` рендерит строку:

```php
<tr data-enrollment="<?= esc_attr(wp_json_encode($enrollmentData)) ?>"
    data-wp-user-id="<?= esc_attr($wpUserId) ?>">
```

**`$enrollmentData`** — массив для немедленного pre-fill модалки:

| Ключ | Источник |
|---|---|
| `subject`, `group`, `schedule` | `GroupsRepository::findById()` |
| `contract_no`, `contract_date`, `order_no`, `order_date`, `enrolled_at` | `StudentRecordDTO` |
| `student_last_name`, `student_first_name`, `student_middle_name`, `student_birth_date`, `student_school`, `student_grade` | `PersonDTO` |
| `guardian_full_name` | `PersonDTO` родителя (через `findActiveByParent()`) |
| `student_email`, `student_phone` | Пустые строки (PII, грузятся AJAX) |

**Кнопка "Отчислить" в row-actions** несёт `data-expel-enrollments` — JSON-массив всех активных зачислений ученика:

```json
[{ "record_id": 42, "subject_name": "Математика", "group_title": "Мат-1" }, ...]
```

Если у ученика 1 зачисление — expel-модалка открывается без выбора группы; если 2+ — показывается `<select>` с группами.

---

### Заполнение модалки студента

**Модалка:** `templates/admin/components/modals/student-person-modal.php`  
**JS-компонент:** `src/js/admin/modals/student-person-modal.js`  
**Менеджер:** `src/js/admin/managers/student-person-modal-manager.js`

#### Механизм `data-field`

В PHP-шаблоне модалки каждый `<input>` помечен атрибутом `data-field`:

```php
<input type="text" class="fs-person-field" data-field="last_name" readonly>
```

JS-метод `StudentPersonModal.fill(data)` ищет элементы по этому атрибуту и устанавливает их значения:

```js
fill( data ) {
    Object.entries( data ).forEach( ( [ key, val ] ) => {
        this.$el.find( `[data-field="${ key }"]` ).val( val ?? '' );
    } );
}
```

Если `data` содержит ключ, для которого нет `data-field` в DOM — поле молча игнорируется.

#### Шаги заполнения при открытии

1. `student-person-modal-manager.js::_openModal($btn)` вызывается при клике `.js-view-person[data-person-type="student"]`
2. Немедленно: `StudentPersonModal.fill(rowData)` — данные из `$btn.closest('tr').data('enrollment')`
3. Модалка открывается (данные уже видны)
4. Фоновый AJAX: `$.post(..., { action: getPersonData, person_id })`
5. После ответа: `StudentPersonModal.fill(...)` поверх шага 2

#### Поля, которые заполняет AJAX

AJAX-ответ `ajaxGetPersonData()` (`inc/Callbacks/PiiCallbacks.php`):

| JS-ключ в `fill()` | Источник в AJAX-ответе |
|---|---|
| `last_name`, `first_name`, `middle_name` | `enrollments[0].last_name` и т.д. (из `PersonDTO`) |
| `subject`, `group`, `schedule`, `contract_no` | `enrollments[0].subject_name`, `group_title` и т.д. |
| `birth_date`, `school`, `grade` | `enrollments[0]` (из `PersonDTO`) |
| `phone` | `masked_pii.phone` |
| `doc_number` | `masked_pii.doc_number` |
| `inn` | `masked_pii.inn` |
| `email` | `res.data.email` (WP user email) |
| `guardian_name` | `res.data.representatives[0].name` |
| `login`, `password` | `res.data.login`, `res.data.password` |

**Важно:** бэкенд возвращает только **активные** зачисления (`findActiveByStudent()`). Старые отчисленные записи не попадают в `enrollments`. Это исключает перезапись данных о текущей группе данными из старой.

#### Дополнительные зачисления (2+ предметов)

Для второго и далее активных зачислений JS добавляет строки через `StudentPersonModal.addEnrollmentRow()`. Строки клонируются из скрытого PHP-шаблона `.js-spm-extra-enrollment-template` и используют `data-enr-field` вместо `data-field` (чтобы не пересекаться с основными полями).

---

### Как добавить новое поле в таблицу учеников

1. **Убедиться, что данные есть в DTO.** Если поле нужно из `StudentRecordDTO` или `PersonDTO` — оно там уже есть. Если нужна новая колонка в БД — сначала миграция (см. раздел «Миграции»).

2. **Добавить `<th>` в заголовок** в `userlist-2-students.php`:
   ```php
   <th class="column-title"><?php esc_html_e( 'Новое поле', 'fs-lms' ); ?></th>
   ```

3. **Добавить `<td>` в цикл строк**, извлекая значение из DTO:
   ```php
   <td><?php echo esc_html( $record->myNewField ?? '—' ); ?></td>
   ```
   Если нужен JOIN с другой таблицей — добавить репозиторный вызов в том же цикле.

4. **Обновить colspan** в пустом состоянии (`colspan="7"` → увеличить на 1).

5. **Если поле должно обновляться при частичном отчислении** — обновить обработчик `fs:student:expel-partial` в `src/js/admin/services/students-table.js`:
   ```js
   $row.find( 'td' ).eq( N ).html( remaining.map( r => esc( r.my_field || '—' ) ).join( '<br>' ) );
   ```
   Индекс `N` — позиция колонки (0 = чекбокс, 1 = ФИО, 2 = Предмет, ...).

---

### Как добавить новое поле в модалку студента

**A. Если данные есть в PHP при рендере страницы:**

1. Добавить ключ в `$enrollmentData` в PHP-шаблоне таблицы:
   ```php
   'my_field' => $record->myField ?? '',
   ```

2. Добавить `<input data-field="my_field">` в `student-person-modal.php`

3. Добавить ключ в pre-fill в `student-person-modal-manager.js`:
   ```js
   StudentPersonModal.fill({
       ...
       my_field: rowData.my_field || '',
   });
   ```

**B. Если данные нужно получить AJAX (PII или тяжёлые данные):**

1. Добавить поле в массив `$enrollments[]` в `PiiCallbacks::ajaxGetPersonData()`:
   ```php
   'my_field' => $record->myField ?? '',
   ```

2. Добавить `<input data-field="my_field">` в `student-person-modal.php`

3. Добавить в AJAX-fill в менеджере:
   ```js
   StudentPersonModal.fill({
       ...
       my_field: enr.my_field ?? '',
   });
   ```

**Правила:**
- `data-field="..."` — для полей основного зачисления
- `data-enr-field="..."` — для полей дополнительных зачислений (строки 2+)
- PII-поля (маскируемые) поместить в `masked_pii` в PHP и передать через `pii.my_field` в JS
- Поля, не требующие AJAX, помещать только в `$enrollmentData` (экономия запросов)

---

### Как добавить поле в AJAX-ответ `ajaxGetPersonData`

Это общая точка получения данных о персоне. Структура ответа:

```php
$this->success([
    'type'            => 'student' | 'parent' | 'unknown',
    'wp_user_id'      => int,
    'display_name'    => string,
    'login'           => string,
    'email'           => string,
    'password'        => string,
    'masked_pii'      => [ 'doc_number', 'inn', 'phone', 'address' ],
    'representatives' => [ ['archive_id', 'guardian_person_id', 'name', ...] ],
    'dependents'      => [ ['archive_id', 'student_person_id', 'name', ...] ],
    'enrollments'     => [
        [
            'record_id', 'group_id', 'group_title',
            'subject_key', 'subject_name', 'schedule',
            'status_label', 'status_value',
            'enrolled_at', 'terminated_at',
            'contract_no', 'contract_date', 'order_no', 'order_date',
            'last_name', 'first_name', 'middle_name',
            'birth_date', 'school', 'grade',
            'student_name',  // null для студента, имя для родителя
        ]
    ],
]);
```

Чтобы добавить поле в `enrollments`:
1. Убедиться, что оно есть в `StudentRecordDTO` или `PersonDTO`
2. Добавить в блок формирования `$enrollments[]` в `PiiCallbacks.php`:
   ```php
   'my_field' => $record->myField ?? '',
   ```
3. Использовать в JS через `enr.my_field`

Чтобы добавить поле на корневой уровень ответа (не привязанное к зачислению):
```php
$this->success([
    ...
    'my_root_field' => $person->myField ?? '',
]);
```
В JS: `res.data.my_root_field`.

---

### Как добавить поле в ответ отчисления (`remaining_enrollments`)

После частичного отчисления возвращается список оставшихся зачислений. Источник — `ExpulsionCallbacks::mapEnrollments()`. Это влияет на обновление строки таблицы.

Текущая структура `remaining_enrollments`:
```php
[ 'record_id', 'subject_name', 'group_title', 'schedule', 'contract_no' ]
```

Чтобы добавить поле:
1. Добавить в `mapEnrollments()` в `inc/Callbacks/ExpulsionCallbacks.php`
2. Обновить обработчик `fs:student:expel-partial` в `students-table.js` для обновления соответствующей колонки строки

---

## Валидация в модалках и формах в admin-части

Система валидаторов описана в разделе [Клиентская валидация форм](#клиентская-валидация-форм) — там разобраны все встроенные валидаторы и шаги создания нового. Этот раздел — практическое руководство по подключению валидации в конкретных admin-контекстах: jQuery-модалках и AJAX-формах.

---

### Когда автоматически, когда вручную

| Контекст | Способ | Условие |
|---|---|---|
| Публичные фронтенд-формы (apply, join) | Автоматически | `data-fs-validate` на форме |
| jQuery-модалки в админке | Вручную через `initFormValidation()` | Форма в модалке |
| Формы в модалках с AJAX-сабмитом | Вручную с `validateAll()` перед AJAX | Любая модалка с кнопкой "Сохранить" |

**Причина ручного подключения в админке:** `common.js` сканирует DOM при `DOMContentLoaded`. Модалки в HTML есть сразу, но `initFormValidation()` нужно вызывать при инициализации модального JS-компонента, а не при загрузке страницы, чтобы события blur/input правильно работали.

---

### Подключение к модалке таксономий (пример)

Допустим, нужно добавить валидацию к форме `#fs-taxonomy-form` внутри модалки.

#### Шаг 1. Разметка в PHP-шаблоне

Каждое поле оборачивается в `.fs-form-group`. Атрибут `data-validate` — опционально, если нужна кастомная логика помимо HTML5:

```php
<!-- templates/admin/components/modals/taxonomy-modal.php -->
<form id="fs-taxonomy-form">
    <div class="fs-form-group">
        <label><?php esc_html_e( 'Название', 'fs-lms' ); ?></label>
        <input type="text"
               name="name"
               class="regular-text"
               data-validate="cyrillicName"
               required>
    </div>

    <div class="fs-form-group">
        <label><?php esc_html_e( 'Слаг', 'fs-lms' ); ?></label>
        <input type="text"
               name="slug"
               class="regular-text"
               data-validate="latinOnly"
               required
               minlength="2">
    </div>

    <div class="fs-form-group">
        <label><?php esc_html_e( 'Тип', 'fs-lms' ); ?></label>
        <select name="display_type" required>
            <option value="">Выберите тип</option>
            <option value="select">Список</option>
            <option value="checkboxes">Чекбоксы</option>
        </select>
    </div>
</form>
```

Без `data-validate` применяется только `BaseValidator` — проверка `required`, `minlength`, `type="email"` через `ValidityState`.

#### Шаг 2. Подключение в JS-компоненте модалки

```js
// src/js/admin/modals/taxonomy-modal.js
import { openModal, closeModal, bindEsc, unbindEsc } from '../modules/modal-base.js';
import { initFormValidation } from '../../common/validation-manager.js';
import { clearModalError, showModalError } from '../modules/utils.js';

const $ = jQuery;

export const TaxonomyModal = {
    $modal:       null,
    _validateAll: null,  // функция validateAll() от менеджера
    _confirmCbs:  [],
    _initialized: false,

    init() {
        if ( this._initialized ) return;
        this.$modal = $( '#fs-taxonomy-modal' );
        if ( ! this.$modal.length ) return;
        this._initialized = true;

        // Привязываем валидацию к форме внутри модалки
        const form = this.$modal.find( '#fs-taxonomy-form' )[ 0 ];
        this._validateAll = initFormValidation( form );

        this._bindEvents();
    },

    _bindEvents() {
        // Закрытие
        this.$modal.on( 'click', '.fs-lms-modal-backdrop, .js-modal-close', ( e ) => {
            e.preventDefault();
            this.close();
        } );

        // Сабмит через кнопку "Сохранить"
        this.$modal.on( 'click', '.js-taxonomy-save', ( e ) => {
            e.preventDefault();
            this._submit();
        } );
    },

    _submit() {
        clearModalError( this.$modal );

        // Запуск всех валидаторов + показ ошибок + фокус на первое невалидное поле
        if ( ! this._validateAll() ) { return; }

        // Собираем данные только после прохождения валидации
        const formData = {
            name:         this.$modal.find( '[name="name"]' ).val().trim(),
            slug:         this.$modal.find( '[name="slug"]' ).val().trim(),
            display_type: this.$modal.find( '[name="display_type"]' ).val(),
        };

        this._confirmCbs.forEach( cb => cb( formData ) );
    },

    onConfirm( cb ) {
        if ( typeof cb === 'function' ) this._confirmCbs.push( cb );
    },

    open() {
        if ( ! this._initialized ) return;
        this.$modal.find( '#fs-taxonomy-form' )[ 0 ].reset();
        openModal( this.$modal );
        bindEsc( 'taxonomy', () => this.close() );
    },

    close() {
        closeModal( this.$modal );
        unbindEsc( 'taxonomy' );
    },

    showError( message ) {
        showModalError( message, this.$modal );
    },
};
```

#### Шаг 3. Реакция на серверные ошибки в менеджере

```js
// src/js/admin/managers/taxonomy-modal-manager.js
import { TaxonomyModal } from '../modals/taxonomy-modal.js';

export const TaxonomyModalManager = {
    init() {
        TaxonomyModal.init();

        TaxonomyModal.onConfirm( ( formData ) => this._save( formData ) );

        $( document ).on( 'click', '.js-open-taxonomy-modal', () => TaxonomyModal.open() );
    },

    _save( formData ) {
        $.post( fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.storeTaxonomy,
            security: fs_lms_vars.nonces.subject,
            ...formData,
        } )
            .done( ( res ) => {
                if ( res.success ) {
                    TaxonomyModal.close();
                    // обновить таблицу...
                } else {
                    // Серверная ошибка — через showModalError
                    TaxonomyModal.showError( res.data?.message || 'Ошибка сохранения.' );
                }
            } )
            .fail( () => {
                // Сетевая ошибка — через toast (apiError)
                apiError( 'Failed to save taxonomy' );
            } );
    },
};
```

---

### Разграничение: `validateAll()` vs `showModalError`

| Тип ошибки | Механизм | Пример |
|---|---|---|
| Поле пустое / неверный формат | `validateAll()` + `data-validate` | "Поле обязательно" у конкретного поля |
| Логическая ошибка (дубликат, конфликт дат) | `showModalError(msg, $modal)` | "Таксономия с таким слагом уже существует" |
| Сетевая ошибка ($.fail) | `apiError(msg)` → toast | "Ошибка при связи с сервером" |

Клиентская валидация через `validateAll()` не заменяет серверную. На сервере в Callback-классах используйте `requireText()` / `requireInt()` из трейта `Sanitizer` — они выбрасывают исключение при пустых обязательных полях.

---

### Добавление нового валидатора для admin-модалки

Пример: нужно проверять, что номер группы вида `А-1` (кириллическая буква, дефис, цифры).

**Шаг 1.** `src/js/common/validators/GroupCodeValidator.js`:
```js
import { BaseValidator } from './BaseValidator.js';

export class GroupCodeValidator extends BaseValidator {
    checkCustom( value ) {
        if ( ! /^[А-ЯЁа-яё]-\d+$/.test( value ) ) {
            return 'Формат: буква-цифры (например, А-1).';
        }
        return null;
    }
}
```

**Шаг 2.** `src/js/common/validators/index.js`:
```js
import { GroupCodeValidator } from './GroupCodeValidator.js';

export const FieldValidators = {
    ...
    groupCode: new GroupCodeValidator(),
};
```

**Шаг 3.** В PHP-шаблоне модалки:
```php
<div class="fs-form-group">
    <input type="text" name="group_code" data-validate="groupCode" required>
</div>
```

Валидатор работает одинаково на фронтенде и в admin-модалках — он находится в `common/`, что намеренно.

---

### Требования к разметке формы в модалке

```php
<!-- Форма в модалке -->
<form id="my-modal-form">
    <!-- Обязательно: обёртка .fs-form-group для каждого поля -->
    <div class="fs-form-group">
        <label>Название</label>
        <input type="text" name="name" required data-validate="cyrillicName">
        <!-- Менеджер добавит сюда <p class="fs-field-error"> при ошибке -->
    </div>

    <!-- Поле без кастомного валидатора — только required -->
    <div class="fs-form-group">
        <select name="type" required>...</select>
    </div>
</form>
```

Ошибка `<p class="fs-field-error">` вставляется внутрь `.fs-form-group`. Стили из `src/scss/common/components/_validation.scss` применяются и в admin-страницах через `common.min.css`.

**Не нужно** добавлять `data-fs-validate` на форму в admin-модалке — там используется ручная привязка через `initFormValidation(form)`. Атрибут `data-fs-validate` актуален только для публичных форм, которые автоматически подхватываются в `common.js`.

---

## Troubleshooting

Раздел описывает **пошаговую диагностику** типичных проблем. Каждый шаг — точка проверки; найдя разрыв в цепочке, вы останавливаетесь там и исправляете именно этот узел.

---

### Общая схема: браузер → PHP → БД

```
[JS в браузере]
    │  1. обработчик события вызывается?
    │  2. XHR уходит? (Network → XHR)
    ▼
[WordPress AJAX endpoint /wp-admin/admin-ajax.php]
    │  3. action и nonce переданы корректно?
    │  4. хук wp_ajax_{action} зарегистрирован?
    ▼
[PHP Callback]
    │  5. authorize() / verify() проходит?
    │  6. требуемые параметры присутствуют и санитизированы?
    │  7. бизнес-логика выполняется без исключений?
    ▼
[Repository / Service]
    │  8. SQL выполняется без ошибок? ($wpdb->last_error)
    │  9. данные реально записаны в таблицу?
    ▼
[БД]
```

---

### Шаг 1 — AJAX-запрос не уходит с клиента

**Симптом:** при нажатии кнопки/сабмите формы ничего не происходит, Network пуст.

**Проверить:**

1. Открыть DevTools → Console, убедиться, что нет JS-ошибок, ломающих выполнение скрипта.

2. Проверить, что обработчик события вообще вызывается:
```js
// Временно в начале обработчика (service или modal):
console.log('[debug] submit triggered', formData);
```

3. Убедиться, что элемент, на который вешается событие, существует на момент `init()`:
```js
// admin-компоненты:
if ( ! $( '.my-trigger' ).length ) { return; } // guard должен быть!

// frontend-компоненты:
if ( ! document.getElementById( 'my-form' ) ) { return; }
```

4. Проверить, что файл собран после изменений:
```bash
npx gulp scripts   # пересобрать JS
```

5. Проверить, что скрипт вообще подключён на нужной странице (вкладка Sources в DevTools → найти `admin.min.js`).

---

### Шаг 2 — XHR уходит, сервер возвращает `0`, `-1` или `400`

**Симптом:** в Network есть POST на `admin-ajax.php`, ответ `0` или `{"success":false}` с message `"Ошибка доступа"` / пустое тело.

| Ответ | Причина |
|---|---|
| `0` | хук `wp_ajax_{action}` не зарегистрирован |
| `-1` | nonce не прошёл проверку |
| `{"success":false, "data":{"message":"..."}}` | `authorize()` вернул 403 или `$this->error()` был вызван |

**Диагностика:**

**2a. Проверить action:**

В Network → Headers → Form Data: посмотреть поле `action`. Оно должно совпадать с тем, что регистрирует контроллер.

```js
// В JS-сервисе:
console.log('[debug] action =', fs_lms_vars.ajax_actions.myAction);
// Ожидаемый вид: 'my_action' (snake_case от AjaxHook enum)
```

```php
// В контроллере должно быть:
protected function ajaxActions(): array {
    return [
        [ AjaxHook::MyAction, $this->myCallbacks ],
    ];
}
```

Если `AjaxHook::MyAction` отсутствует в enum или контроллер не добавлен в `Init::getServices()` — хук не зарегистрируется и WP вернёт `0`.

**2b. Проверить nonce:**

```js
// Временно в JS перед отправкой:
console.log('[debug] nonce =', fs_lms_vars.nonces.manager);
```

```php
// В callback authorize() принимает два аргумента:
$this->authorize( Nonce::Manager, Capability::Admin );
// Nonce::Manager должен совпадать с тем, что передаёт Enqueue.php в fs_lms_vars.nonces
```

Открыть `inc/Core/Enqueue.php` и проверить, что нужный nonce есть в массиве `nonces`, передаваемом в `wp_localize_script`.

**2c. Проверить, не прилетает ли исключение до ответа:**

Добавить в начало callback-метода:
```php
public function ajaxMyAction(): void {
    $this->authorize( Nonce::Manager, Capability::Admin );
    wp_die( 'checkpoint reached' ); // убрать после отладки
    // ...
}
```

Если в Network появится текст `checkpoint reached` — контроллер достигается. Если нет — проблема в регистрации хука.

---

### Шаг 3 — PHP callback вызывается, но данные не попадают в БД

**Симптом:** AJAX возвращает `{"success":true}`, но запись не появляется в таблице.

**3a. Убедиться, что метод репозитория вообще вызывается:**

```php
public function ajaxSaveEntity(): void {
    $this->authorize( Nonce::Manager, Capability::Admin );

    $name = $this->requireText( 'name' );
    $id   = $this->sanitizeInt( 'period_id' );

    // Временная точка остановки:
    $this->success( [ 'debug_name' => $name, 'debug_id' => $id ] );
    // Если данные пришли — двигаемся дальше
}
```

Если в ответе AJAX правильные данные — проблема ниже, в сервисе или репозитории.

**3b. Проверить $wpdb->last_error:**

```php
// В репозитории или сервисе после insert/update:
$id = $this->applicationRepository->create( $data );
if ( ! $id ) {
    global $wpdb;
    PluginLogger::debug( 'DebugInsert', 'insert failed', [ 'last_error' => $wpdb->last_error ] );
}
```

Лог пишется в `..debug.log` (только если `WP_DEBUG = true`). Читать последние строки:
```bash
docker exec wp_app tail -15 /var/www/html/wp-content/debug.log
```

**3c. Проверить транзакцию:**

Если метод использует `TransactionRunner::inTransaction()` — исключение внутри замыкания откатит транзакцию без видимой ошибки. Обернуть в try/catch:

```php
try {
    $id = $this->inTransaction( function () use ( ... ): int {
        // ...
    } );
} catch ( \Throwable $e ) {
    PluginLogger::exception( 'DebugTransaction', $e, [], true );
    $this->error( $e->getMessage() );
    return;
}
```

**3d. Проверить статусный конечный автомат:**

Для изменения статуса заявки используется `ApplicationStatus::canTransitionTo()`. Если переход запрещён — метод вернёт `false` без записи в БД:

```php
// ApplicationRepository или сервис:
if ( ! $currentStatus->canTransitionTo( $newStatus ) ) {
    // операция молча пропускается
}
```

Проверить текущий статус заявки напрямую в БД:
```bash
docker exec wp_db mariadb -u root -proot wordpress \
  -e "SELECT id, status FROM wp_applications WHERE id = 123;"
```

---

### Шаг 4 — Данные сохранены, но не отображаются в UI

**Симптом:** в БД запись есть, но страница или AJAX-ответ возвращает старые данные.

**4a. OPcache** — PHP-файл изменён, но старая версия осталась в кэше:

```bash
docker restart wp_app
```

**4b. Transient-кэш** — `ContentCacheService` кэширует задания/статьи на 5 минут. Сбросить:

```bash
docker exec wp_db mariadb -u root -proot wordpress \
  -e "DELETE FROM wp_options WHERE option_name LIKE '_transient_fs_lms_%';"
```

**4c. JS-кэш браузера** — пересобрать и сделать hard refresh (Ctrl+Shift+R).

**4d. Проверить, что метод репозитория читает из нужной таблицы:**

```php
// Временно в callback, вместо основного ответа:
$raw = $this->applicationRepository->findById( $id );
$this->success( [ 'debug_raw' => (array) $raw ] );
```

---

### Шаг 5 — PII-данные не расшифровываются / не подтягиваются

**Симптом:** поле возвращается пустым или `null`, хотя в `*_enc` колонке таблицы есть данные.

**5a. Убедиться, что PersonReader используется, а не прямой запрос:**

Все PII-поля зашифрованы. Читать через `PersonReader::read()`:

```php
// ПРАВИЛЬНО:
$dto = $this->personReader->read( $personId, ['full_name', 'phone'], 'admin_card' );
$fullName = $dto->fullName;

// НЕПРАВИЛЬНО — вернёт зашифрованный blob:
$row = $this->personRepository->find( $personId );
$fullName = $row->fullNameEnc; // это base64(encrypted), не читаемо
```

**5b. Проверить, что `person_id` передаётся корректно:**

ФИО родителя привязано к `parent_person_id` в таблице `applications`, а не к `wp_user_id`. Убедиться, что берётся правильный идентификатор:

```php
$app = $this->applicationRepository->findById( $appId );

// Родитель:
$parentPersonId = $app->parentPersonId;   // из таблицы persons
$parentDto = $this->personReader->read( $parentPersonId, ['full_name'], 'reason' );

// Ученик:
$studentPersonId = $app->studentPersonId;
```

**5c. Убедиться, что заявка дошла до нужного статуса:**

`parent_person_id` заполняется только после того, как родитель отправил форму (`submitParentData()`). До этого поле `null`.

```bash
docker exec wp_db mariadb -u root -proot wordpress \
  -e "SELECT id, status, parent_person_id, student_person_id FROM wp_applications WHERE id = 123;"
```

Если `parent_person_id` — NULL, значит родитель ещё не заполнил форму. Это норма для статуса `pending_parent`.

**5d. Проверить ключ шифрования:**

Если `FS_LMS_ENCRYPTION_KEY` не задана в `wp-config.php` или изменилась после записи данных — расшифровка вернёт `false`, `PiiCryptoService` вернёт пустую строку. Проверить константу:

```php
// wp-config.php:
define( 'FS_LMS_ENCRYPTION_KEY', 'ваш_ключ_64_символа_hex' );
```

---

### Разборы реальных кейсов

---

#### Кейс 1: Модалка восстановления из архива не создавала запись в `applications`

**Симптом.** Администратор открывал модалку восстановления заявки, жал «Восстановить», AJAX возвращал `{"success":true}`, но статус заявки в БД не менялся.

**Диагностика по шагам:**

**Шаг 1.** Открыть Network → XHR, найти запрос на `admin-ajax.php`. Посмотреть Form Data — убедиться, что `action` и `app_id` присутствуют.

**Шаг 2.** Проверить `action`. В данном случае действие регистрировалось как `AjaxHook::RestoreApplication`. В JS должно было быть:
```js
action: fs_lms_vars.ajax_actions.restoreApplication
```
Если бы поле имело другое имя — хук не нашёлся бы, ответ `0`.

**Шаг 3.** В callback добавить точку отладки:
```php
public function ajaxRestoreApplication(): void {
    $this->authorize( Nonce::Manager, Capability::ManageApplications );
    $appId = $this->requireInt( 'app_id' );

    $app = $this->applicationRepository->findById( $appId );
    PluginLogger::debug( 'RestoreDebug', 'app found', [
        'id'     => $appId,
        'status' => $app?->status?->value ?? 'null',
    ] );
    // ...
}
```

**Шаг 4 (корень проблемы).** Лог показал `status: "converted"` — заявка была в финальном статусе. `ApplicationStatus::canTransitionTo( ApplicationStatus::ReadyForReview )` вернул `false`. Метод репозитория получил `false` и молча ничего не сделал. Ответ `success: true` уходил потому, что проверка результата update была написана как:

```php
$result = $this->applicationRepository->updateStatus( $appId, ApplicationStatus::ReadyForReview );
// Не было: if (!$result) { $this->error(...); }
$this->success();  // всегда success
```

**Исправление.** Добавить явную проверку и вернуть ошибку пользователю, если переход запрещён.

---

#### Кейс 2: ФИО родителя не подтягивалось в карточку заявки

**Симптом.** В модалке просмотра заявки поля «Имя родителя», «Отчество», «Телефон» были пустыми. Заявка находилась в статусе `ready_for_review`.

**Диагностика по шагам:**

**Шаг 1.** Открыть Network → найти AJAX-запрос загрузки данных заявки (обычно `getApplicationData` или аналог). Посмотреть ответ — есть ли в нём ключи для родителя.

```json
{
  "success": true,
  "data": {
    "student": { "full_name": "Иванов Иван" },
    "parent":  { "full_name": "", "phone": "" }
  }
}
```

Данные приходят пустыми → проблема на сервере, не в JS.

**Шаг 2.** Найти callback, который формирует ответ (например `PersonViewCallbacks::ajaxGetPersonData()`). Добавить отладочный вывод:

```php
$app = $this->applicationRepository->findById( $appId );

$this->success( [
    'debug_parent_person_id'  => $app->parentPersonId,
    'debug_student_person_id' => $app->studentPersonId,
] );
```

**Шаг 3 (корень проблемы).** Ответ показал `debug_parent_person_id: null`. Запрос к БД подтвердил:

```bash
docker exec wp_db mariadb -u root -proot wordpress \
  -e "SELECT parent_person_id FROM wp_applications WHERE id = 45;"
# → NULL
```

`parent_person_id` заполняется в `ApplicationService::submitParentData()`. Проверить аудит-лог:

```bash
docker exec wp_db mariadb -u root -proot wordpress \
  -e "SELECT * FROM wp_audit_log WHERE target_id = 45 ORDER BY created_at ASC;"
```

Лог показал только `create_application`, но не `parent_submitted` — родитель никогда не заполнял форму. Это нормальная ситуация, а не баг: заявку перевели в `ready_for_review` вручную, минуя стандартный flow.

**Вывод.** Причина не в коде, а в данных. Защита: перед попыткой читать PII родителя всегда проверять `$app->parentPersonId !== null`.

```php
if ( null === $app->parentPersonId ) {
    return [ 'parent' => null ]; // явный null, не пустой массив
}
$parentDto = $this->personReader->read( $app->parentPersonId, $fields, 'admin_view' );
```

---

### Быстрая шпаргалка

| Ситуация | Куда смотреть |
|---|---|
| Кнопка не реагирует | Console → JS-ошибки; добавить `console.log` в обработчик |
| Network пустой | Проверить, что `init()` вообще вызывается; есть ли guard на DOM-элемент |
| Ответ `0` | `AjaxHook` не в `ajaxActions()` или контроллер не в `Init::getServices()` |
| Ответ `-1` | Нonce неверный; сравнить `Enqueue.php` и `authorize()` |
| `success:false`, 403 | `Capability` не та; проверить `$this->authorize( Nonce::X, Capability::Y )` |
| `success:true`, но не сохраняется | Проверить `$wpdb->last_error`; статусный автомат; транзакция откатилась |
| PII возвращается пустым | Читать через `PersonReader::read()`, не из сырого DTO; проверить `FS_LMS_ENCRYPTION_KEY` |
| После изменения PHP ничего не изменилось | `docker restart wp_app` (OPcache) |
| Старые данные в кэше | Удалить transients `_transient_fs_lms_%` |

---

### Инструменты

#### Быстрый dump в PHP (только на время отладки)

```php
// В начале callback-метода, вместо основного кода:
$this->success( [ 'debug' => [
    'post'    => $_POST,
    'user_id' => get_current_user_id(),
] ] );
```

Всегда удалять после нахождения проблемы.

#### Логирование через PluginLogger

```php
use Inc\Shared\PluginLogger;

// debug — только при WP_DEBUG=true:
PluginLogger::debug( 'MyCallback', 'reached checkpoint A', [ 'id' => $appId ] );

// warning — всегда пишется:
PluginLogger::warning( 'MyCallback', 'unexpected null', [ 'app_id' => $appId ] );
```

Лог читать:
```bash
docker exec wp_app tail -15 /var/www/html/wp-content/debug.log
```

Формат: `[FS LMS] MyCallback: reached checkpoint A | Context: {"timestamp":"...","user_id":1,"id":42}`

#### Прямой запрос к БД

```bash
# Последние 5 заявок:
docker exec wp_db mariadb -u root -proot wordpress \
  -e "SELECT id, status, parent_person_id FROM wp_applications ORDER BY id DESC LIMIT 5;"

# Аудит конкретной заявки:
docker exec wp_db mariadb -u root -proot wordpress \
  -e "SELECT action, created_at FROM wp_audit_log WHERE target_id = 42 AND target_type = 'application';"

# Последние ошибки репозитория (если включён general_log):
docker exec wp_db mariadb -u root -proot wordpress \
  -e "SHOW GLOBAL STATUS LIKE 'Last_query%';"
```

---

## Система обучения (Этапы 1–4): контент, программа, сдачи, контрольные

Этот раздел описывает, что появилось за четыре этапа разработки LMS: где
что хранится, какие CPT создаются, как это настраивать и как связаны классы.

> ⚠️ **Модель урока и курса переработана в MVP-2** (см. следующий раздел «Система обучения
> (MVP-2 «Курсы»)»). Здесь описана **базовая** модель Этапов 1–4. Главное отличие: урок теперь
> хранит `steps[]` (а не `work_ids[]`), курс — `modules[]` (а не `lesson_ids[]`); поля
> `work_ids`/`lesson_ids` ниже стали **производными** (вычисляются из шагов/модулей). Авторинг
> урока/курса переехал в SPA-конструктор. Доставка (Этапы 2–4) при этом не менялась.

### Главная идея: два разных хранилища

Всё в этой подсистеме делится на **две сущности**, и они хранятся по-разному:

| Что это | Пример | Где хранится |
|---|---|---|
| **Контент** — «что учим» (многоразовый шаблон) | задание, работа, урок, курс, контрольная | **CPT** (пост + `post_meta`) |
| **Факт обучения** — «что произошло в группе» | сдача ученика, попытка контрольной, расписание, событие ленты | **кастомные таблицы** (`$wpdb`) |
| **Настройка** | политика доступа отчисленного | **`wp_options`** |

Правило, которое нельзя нарушать: **контент — только в CPT, факты — только в таблицах.**
Урок не копируется в группу — группа **ссылается** на урок по ID. Поправили урок —
изменение увидели все, кто на него ссылается.

### Цепочка банков контента

Контент переиспользуется по цепочке (каждое звено ссылается на предыдущее, не копирует):

```
задания (tasks) ──► работы (works) ──► уроки (lessons) ──► курсы (courses)
задачи (problems, глобальные) ──┘
контрольные (assessments) ──► ссылаются на задания
```

- **Задание** (`{key}_tasks`) — одна задача (условие/ответ). Уже было до Этапа 1.
- **Работа** (`{key}_works`) — типизированный (`practice`/`independent`/`homework`) упорядоченный
  набор ссылок на задания **и** глобальные задачи + инструкция.
- **Урок** (`{key}_lessons`) — тема + теория + упорядоченные ссылки на работы.
- **Курс** (`{key}_courses`) — упорядоченные ссылки на уроки (шаблон программы).
- **Контрольная** (`{key}_assessments`) — набор заданий + правила (лимит времени, попытки,
  проходной балл, перемешивание).
- **Задача** (`fs_lms_problems`) — глобальный (не привязанный к предмету) банк приватных задач,
  которые можно добавить в работу любого предмета. Не публикуется на фронте.

---

### Какие CPT создаются и как

**Per-subject CPT.** Для каждого предмета (`$key`, напр. `inf`, `math`) регистрируется набор CPT.
Имена собираются через `PostTypeResolver` — **никогда не конкатенируйте строки руками**:

| Метод `PostTypeResolver` | Результат | Назначение |
|---|---|---|
| `tasks($key)`        | `{key}_tasks`        | задания |
| `articles($key)`     | `{key}_articles`     | статьи (теория) |
| `works($key)`        | `{key}_works`        | работы |
| `lessons($key)`      | `{key}_lessons`      | уроки |
| `courses($key)`      | `{key}_courses`      | курсы |
| `assessments($key)`  | `{key}_assessments`  | контрольные |
| `problems()`         | `fs_lms_problems`    | глобальные задачи (без префикса) |

Обратный разбор: `subjectFromWorkPostType('inf_works') === 'inf'` и аналоги. Проверки типа:
`isWorkPostType()`, `isLessonPostType()`, `isCoursePostType()`, `isAssessmentPostType()`,
`isProblemPostType()`, и обобщающий `isBankPostType()` (любой банк контента).

**Где регистрируются.** Все per-subject CPT регистрируются в одном месте —
`SubjectController::registerForSubject()` (вызывается циклом по всем предметам). Каждый CPT
добавляется через `SubjectCPTRegistrar::addStandardType()`, а его конфиг берётся из
`SubjectController::getDefaultCptArgs($type, $subject)`.

В `getDefaultCptArgs()` есть общий блок `$bank_options` — он делает банки «невидимыми» в
стандартном меню WP и переводит права на кастомный тип:

```php
$bank_options = array(
    'show_in_menu'        => false,            // в top-level меню не показываем
    'show_in_rest'        => false,
    'exclude_from_search' => true,
    'capability_type'     => 'fs_lms_content', // права маппятся на manage_lms_assignments
    'map_meta_cap'        => true,
    'has_archive'         => false,
);
```

`match($type)` задаёт для каждого банка склонения (`nom`/`acc`/`gen`/`gender`) и `supports`
(работы/уроки/курсы/контрольные — `title, editor, author`; уроки ещё `thumbnail`). Чтобы
добавить новый банк-CPT, нужно: добавить suffix+helpers в `PostTypeResolver`, ветку в
`getDefaultCptArgs()`, вызов `addStandardType()` в `registerForSubject()`.

**Глобальная задача** `fs_lms_problems` регистрируется отдельно — в `ProblemsController`
(она одна на всю систему, не per-subject), вместе со свободной таксономией `problem_tag`.

**Меню «Обучение».** Поскольку банки скрыты из стандартного меню (`show_in_menu => false`),
единая точка входа — top-level меню «Обучение» (`LearningMenuController`). Вкладки-предметы в нём
формирует `TeacherSubjectsService` (препод видит свои предметы, админ — все).

**Фильтр расширения:** перед регистрацией каждого CPT прогоняется
`apply_filters('fs_lms_cpt_args', $args, $type, $subject)`.

---

### Что лежит в `post_meta` банков

Вся мета банка хранится **одним массивом** под ключом `PostMetaName::Meta` (`fs_lms_meta`) —
как у заданий. `post_title`/`post_content` — нативные поля.

| Банк | `post_title` | `post_content` | Ключи в `fs_lms_meta` |
|---|---|---|---|
| Работа   | название | инструкция (опц.) | `work_type`, `item_ids[]` (ссылки на задания/задачи), `instructions` |
| Урок     | тема     | теория (inline)   | `theory_article_id` (0 = inline), `work_ids[]` |
| Курс     | название | описание          | `lesson_ids[]` |
| Контрольная | название | описание | `task_ids[]`, `time_limit_minutes`, `max_attempts`, `pass_score`, `scoring_policy`, `shuffle` |

Все `*_ids` — **упорядоченные массивы ID-ссылок**, не копии.

---

### Кастомные таблицы (факты обучения)

Имена централизованы в enum `TableName` (метод `prefixed()` добавляет `$wpdb->prefix`),
схема — в `inc/Migrations/Migration_1_0_0.php` (одна монолитная миграция; новые таблицы
добавляются туда же, см. раздел «Миграции»).

| Таблица (`TableName`) | Этап | Простыми словами |
|---|---|---|
| `fs_lms_group_lessons`       | 2 | Программа группы: какие уроки, в каком порядке, когда, кому видно |
| `fs_lms_learning_events`     | 2 | Append-only лента событий обучения (фид группы, таймлайн ученика) |
| `fs_lms_submissions`         | 3 | Сдачи работ учениками + оценки |
| `fs_lms_assessment_attempts` | 4 | Попытки прохождения контрольных |
| `fs_lms_assessment_answers`  | 4 | Ответы внутри попытки (по заданиям) |

**`fs_lms_group_lessons`** — заменяет старое текстовое `groups.schedule`. Ключевые колонки:
`group_id`, `lesson_id` (ссылка на CPT-урок), `position`, `work_ids_snapshot` (JSON —
заморозка `lesson.work_ids` при первой публикации, *copy-on-publish*), `extra_work_ids` (JSON —
доп. работы только для этой группы), `scheduled_at`, `teacher_user_id`,
`visibility` (`hidden`/`open`/`archived`), `opened_at`, `homework_due_at`, `allow_late`,
`recording_url`. Назначенный группе курс хранится в колонке `fs_lms_groups.course_id`.

**`fs_lms_learning_events`** — `subject_key`, `group_id`, `actor_user_id`, `action`
(`course_assigned`, `lesson_published`, `submission_made`, `attempt_submitted` …),
`entity_type`/`entity_id`, `is_public` (виден ли срез ученику/родителю).

**`fs_lms_submissions`** — `student_person_id`, `group_lesson_id`, `work_id`, `work_type`,
`task_id` (опц.), `answer_text`, `attachment_id`, `due_at`,
`status` (`assigned`/`submitted`/`graded`/`returned`), `score`/`max_score`, `feedback`,
`graded_by_user_id`, `submitted_at`/`graded_at`.

**`fs_lms_assessment_attempts`** — `assessment_id`, `student_person_id`, `group_id`,
`attempt_number` (UNIQUE с assessment+student), `started_at`, `deadline_at`, `submitted_at`,
`status` (`in_progress`/`submitted`/`graded`/`expired`), `total_score`/`max_score`.

**`fs_lms_assessment_answers`** — `attempt_id`, `task_id`, `answer_text`, `is_correct`,
`score`/`max_score`, `graded_by_user_id`/`graded_at`.

---

### Настройки в `wp_options`

Подсистема почти не использует опции (всё контентное — в CPT, всё фактическое — в таблицах).
Единственная настройка этих этапов:

| Опция (`OptionName`) | Значения | Репозиторий |
|---|---|---|
| `fs_lms_expulsion_retention_policy` | `retain` (по умолч.) / `block` | `ExpulsionPolicyRepository` |

`retain` — отчисленный сохраняет read-only доступ к пройденному; `block` — теряет доступ
полностью. Читать опцию напрямую через `get_option` нельзя — только через репозиторий.

---

### Связи классов по слоям

Архитектура та же, что во всём плагине: **Controller → Callbacks → Service →
Manager/Repository → DTO/Enum**. Контроллеры только регистрируют хуки, вся логика — в сервисах,
данные — через менеджеры (CPT) и репозитории (таблицы/опции).

**Этап 1 — банки контента (CPT).** На каждый банк: `Field` → `Template` → `MetaBoxController`
(метабокс) + `Manager` (CRUD поста+меты) + `AuthoringService` (кандидаты для селекторов) +
`Callbacks`/`Controller` (AJAX селекторов) + `DTO`.

```
WorkMetaBoxController ─► WorkTemplate ─► (WorkTypeField, TaskRefField …)
WorkController ─► WorkCallbacks ─► WorkAuthoringService ─► WorkManager ─► PostManager ─► WorkDTO
(аналогично Lesson* и Course*)
ContentUsageService     — «кто на меня ссылается» (бейдж + гейт удаления)
ContentLifecycleService — статусы draft/publish/fs_archived
ContentDeletionGuard    — запрещает удаление контента с usage > 0 (предлагает «В архив»)
```

**Этап 2 — программа группы (таблицы).**

```
ScheduleController ─► ProgramCallbacks ─►
    ├─ CourseAssignmentService   (назначить курс → снапшот уроков в group_lessons)
    ├─ ScheduleService           (добавить/убрать/упорядочить/расписание урока)
    ├─ LessonVisibilityService   (hidden/open/archived; copy-on-publish снапшота работ)
    ├─ EffectiveWorksResolver    (эффективные работы = snapshot|lesson.work_ids + extra_work_ids)
    └─ GroupAccessGuard          (доступ препода к группе)
LessonAccessPolicy  ─► AccessLevel (None/Read/ReadSubmit) — что видит ученик
GroupCockpitController — фронт-страница группы (/group/)
LearningEventSubscriber + LearningEventWriter + LearningEventRepository — лента событий
Данные: GroupLessonRepository, LearningEventRepository; DTO: GroupLessonDTO, GroupLessonInputDTO
```

**Этап 3 — сдача работ и журнал (таблицы).**

```
SubmissionController ─► SubmissionCallbacks ─► SubmissionService
    (submit/grade/returnForRework; проверка через LessonAccessPolicy; файлы — MediaManager)
SubmissionController ─► GradingCallbacks   ─► GradebookService
GradebookService ─► GradeSourceRegistry ─► [SubmissionGradeSource, AssessmentGradeSource]
    (источники реализуют GradeSourceInterface — новый источник добавляется в реестр, без правки журнала)
Данные: SubmissionRepository; DTO: SubmissionDTO, SubmissionInputDTO, GradeDTO, GradebookEntryDTO
```

**Этап 4 — контрольные и экзамены (CPT + таблицы).**

```
AssessmentMetaBoxController ─► AssessmentTemplate (настройки контрольной в fs_lms_meta)
AssessmentController  ─► AttemptCallbacks      ─► AttemptService
    (start/saveAnswer/submit/expireIfOverdue; жизненный цикл попытки)
AssessmentController  ─► GradeAttemptCallbacks ─► AutoGradeService
    (авто-проверка по task_answer там, где шаблон позволяет; иначе — ручная)
AssessmentPageController — фронт-страница контрольной (template_include по isAssessmentPostType)
Данные: AssessmentManager (CPT), AssessmentAttemptRepository, AssessmentAnswerRepository
DTO: AssessmentDTO, AttemptDTO, AttemptInputDTO, AttemptAnswerDTO
```

**Ключевые enum'ы подсистемы:** `WorkType` (тип работы), `LessonVisibility`
(hidden/open/archived), `AssignmentPolicy` (append/replace при назначении курса), `AccessLevel`
(None/Read/ReadSubmit), `SubmissionStatus`, `AttemptStatus`, `ScoringPolicy`.

---

### Важные механики (на что обратить внимание)

- **Ссылки, не копии.** Все `*_ids` — ID-ссылки. Поэтому контент с зависимостями нельзя удалить
  физически: `ContentDeletionGuard` блокирует удаление при `usageCount > 0` и предлагает «В архив»
  (`ContentLifecycleService`, статус `fs_archived`). Источник «кто ссылается» — `ContentUsageService`.
- **Copy-on-publish.** При первой публикации урока в группе (`visibility → open`) текущий список
  работ урока «замораживается» в `work_ids_snapshot`. Дальше правки эталонного урока не ломают уже
  открытый группе материал. Эффективный список считает `EffectiveWorksResolver`:
  `(snapshot, если открыт; иначе живой lesson.work_ids) + extra_work_ids`.
- **Доступ ученика** считает `LessonAccessPolicy` по матрице «видимость × статус зачисления × даты ×
  политика ретеншна» и возвращает `AccessLevel`. `hidden` — никому; поздно зашедший видит бэк-каталог,
  но сдаёт только с даты своего зачисления; отчисленный — по `ExpulsionPolicyRepository`.
- **Журнал оценок расширяемый.** `GradebookService` не знает о конкретных источниках — берёт их из
  `GradeSourceRegistry` через интерфейс `GradeSourceInterface`. Добавить источник Этапа 5 = дописать
  его в конструктор реестра, журнал не трогаем (принцип OCP).
- **Авто-проверка контрольных.** `AutoGradeService` сравнивает ответ с полем `task_answer`, если
  шаблон задания это поддерживает; иначе ответ помечается на ручную проверку. Когда все ответы
  оценены — попытка получает статус `graded`.
- **Время — через `ClockInterface`.** Сервисы и попытки не зовут `current_time()` напрямую, а
  получают время из внедрённого `ClockInterface` (биндинг `ClockInterface → WpClock` в `Init`) —
  это делает логику дедлайнов тестируемой.

---

### Фронтенд-страницы

- **Кокпит группы** — `GroupCockpitController` подменяет шаблон по маршруту `PageRoutes::GroupCockpit`
  (`/group/`) через `template_include`; без авторизации — редирект на логин.
- **Страница контрольной** — `AssessmentPageController` подменяет шаблон для одиночной записи CPT
  `{key}_assessments` (`template_include` + `isAssessmentPostType`).

Обе страницы оборачивают вывод через `ThemeCompatService::header()/footer()` (см. одноимённый раздел).

---

## Система обучения (MVP-2 «Курсы»): шаги, конструктор, плеер, прогресс, клон, календарь

> Надстройка над Этапами 1–4. Переосмысляет авторинг: урок — **последовательность шагов**
> (как Stepik), курс — **модули → уроки**; плюс пошаговый плеер ученика, прогресс, гейтинг,
> клон/форк и (бэкенд) календарь занятий. Канон UI — `design_handoff_course_builder/`.
> «Человеческое» объяснение — в `explain.md` (шапка + разделы 8–9).

### Что изменилось в модели

| Было (Этапы 1–4) | Стало (MVP-2) |
|---|---|
| Урок: `fs_lms_meta['work_ids']` | Урок: `fs_lms_meta['steps']` — шаги `{key,type,payload}`; `work_ids` теперь **производное** |
| Курс: `fs_lms_meta['lesson_ids']` | Курс: `fs_lms_meta['modules']` (`{id,title,lesson_ids[]}`); `lesson_ids` **производное** |
| Урок правится метабоксом полей | Урок/курс правятся **SPA-конструктором** (`course-builder.js`) |

**Обратная совместимость доставки:** `LessonDTO::workIds()` и `CourseDTO::lessonIds()` стали
**вычисляемыми** (обходят `steps[]` / `modules[]`). Поэтому код Этапов 2–4
(`EffectiveWorksResolver`, `CourseAssignmentService`, сдачи, журнал) работает без изменений —
он по-прежнему спрашивает «работы урока» / «уроки курса», просто теперь это деривация.

**Типы шага** (`StepType`): `text` (лекция), `video`, `material` (файл/статья), `task`,
`work` (ссылка на работу), `assessment` (ссылка на контрольную). `isInline()` — контент в
`payload`; `isRef()` — ссылка на CPT-сущность по `payload['ref']`.

### Новые классы и за что отвечают

**Контракт модели**

| Класс | Роль | Где менять |
|---|---|---|
| `Enums\StepType` | типы шага + `isInline/isRef/label/options` | новый тип шага — case + хелперы |
| `DTO\Course\StepDTO` | шаг `{key,type,payload}` + `fromList/toList` (round-trip `steps[]`) | формат шага |
| `DTO\Course\ModuleDTO` | модуль `{id,title,lessonIds}` + `fromList/toList` | формат модуля |

**Конструктор курса (admin SPA)**

| Класс | Роль |
|---|---|
| `Controllers\CourseBuilderController` | скрытая страница `fs_lms_course_builder` + редирект нативного редактора курса в SPA |
| `Services\Course\CourseBuilderService` | read/write дерева «курс→модули→уроки→шаги» для JS |
| `Callbacks\Course\CourseBuilderCallbacks` | admin-AJAX конструктора (структура/модули/уроки/мета); регистрируется в `CourseController` |
| `Services\Course\LessonAuthoringService` | `buildSteps()` (валидация + генерация `key`), `getStepCandidates()`, `moveStep()` |
| `src/js/admin/services/course-builder.js` | сам SPA: дерево + редактор шагов (TinyMCE / WP Media / инлайн-создание) |

**Плеер, прогресс, гейтинг (фронт ученика)**

| Класс | Роль |
|---|---|
| `Controllers\LessonPlayerController` | подмена шаблона на `/group/?gid=X&gl=Y` → пошаговый плеер |
| `Services\Course\LessonPlayerService` | view-модель плеера: шаги + гейт (доступ) + статус (прогресс) |
| `Services\Course\LessonProgressService` | прогресс инлайн-шагов (`markViewed/markCompleted`); work/assessment резолвятся из fact-таблиц |
| `Repositories\…\LessonProgressRepository` | таблица `fs_lms_lesson_progress` (upsert по `UNIQUE(person, group_lesson, step_key)`) |
| `Controllers\LessonProgressController` + `Callbacks\Course\LessonPlayerCallbacks` | AJAX `mark_step_progress` (без capability — доступ по членству) |
| `Services\Course\LessonGateResolver` | гейтинг: урок (доступ + дата) + шаг (`payload['gate']`: `none`/`sequential`/`after:<key>`) |

**Клон / форк**

| Класс | Роль |
|---|---|
| `Services\Course\ContentCloneService` | `cloneLesson/Work/Assessment`, `cloneCourse(shallow\|deep)`, `forkLessonForGroup` (meta `forked_from`/`forked_for_group`) |
| `Callbacks\Course\CloneCallbacks` | AJAX клона/форка; регистрируется в `CourseController` |

**Календарь занятий (бэкенд, ещё не подключён к UI)**

| Класс | Роль |
|---|---|
| `Services\Course\SessionCalendarService` | `generate()` — слоты из `group.meetings[] × [period] − holidays`; `reflow()` — перераскладка незакреплённых строк |
| `GroupsRepository::getMeetings/setMeetings` | `groups.meetings` (JSON-расписание) |
| `GroupLessonRepository::applySlots/setPinned/updateSchedule(+endsAt)` | `group_lessons.ends_at`, `is_pinned`, nullable `lesson_id` |
| `DTO\Settings\AcademicPeriodDTO` (+`holidays`) | период обучения + праздники-исключения |

> ⚠️ Сервисы календаря **готовы, но не вызываются** ни из одного контроллера/AJAX. Чтобы
> включить: добавить case(ы) в `AjaxHook`, зарегистрировать в контроллере группы, дёргать
> `ScheduleService::pin()/reflow()` + `SessionCalendarService::generate()`; в кокпите — UI
> редактора `meetings[]` и кнопку «сгенерировать слоты».

### Схема (новое поверх Этапов 1–4)

```
АВТОРИНГ (admin)
  CourseBuilderController ─(страница + редирект)─► course-builder.js (SPA)
     │ AJAX (CourseController → CourseBuilderCallbacks): create_course_draft,
     │      save_course_structure, create_lesson_in_module, update_lesson_meta, save_course_meta
     └ AJAX (LessonController → LessonCallbacks): save_lesson_steps, move_lesson_step,
            get_step_candidates, create_{work|task|assessment|article}_draft
  CourseBuilderService / LessonAuthoringService — сборка дерева / валидация шагов

ДОСТАВКА (front, ученик)
  LessonPlayerController ─► player.php
     LessonPlayerService ─► LessonGateResolver (+LessonAccessPolicy) + LessonProgressService
     AJAX (LessonProgressController → LessonPlayerCallbacks): mark_step_progress
  Прогресс: LessonProgressRepository (fs_lms_lesson_progress)

КЛОН/ФОРК (admin): CourseController → CloneCallbacks → ContentCloneService
КАЛЕНДАРЬ (бэкенд): SessionCalendarService ↔ GroupsRepository.meetings / GroupLessonRepository.slots
```

### Как поддерживать и типичные правки

**Добавить тип шага** (`StepType`):
1. `StepType` — `case` + поведение в `isInline/isRef/label/options/allowedTypesFor`.
2. `LessonAuthoringService::buildSteps()` — per-type санитайз `payload` (трейт `Sanitizer`).
3. Плеер: `LessonPlayerService` (view-модель) + `templates/frontend/lesson-player/player.php` (рендер).
4. Редактор: `course-builder.js` — ветка в `inlineEditor()` (инлайн) или `refEditor()` (ссылка).
5. Если ссылочный и влияет на usage — `ContentUsageService::relationFor()/stepRefs()`.
6. Если это «работа урока» для доставки — учесть в `LessonDTO::workIds()`.

**Добавить AJAX-действие конструктора:** case в `AjaxHook` → метод в `CourseBuilderCallbacks`
(`authorize(Nonce::X, Capability::ManageLMSAssignments)` + `Sanitizer`) → регистрация пары
`[AjaxHook::X, $this->builderCallbacks]` в `CourseController::ajaxActions()` → вызов из
`course-builder.js` через `acts().<camelCase>`.

**Включить календарь занятий:** см. callout выше (хуки + UI кокпита + вызов сервисов).

### Подводные камни (проверять в первую очередь)

- **`Sanitizer` — по имени ключа, не по значению.** `requireKey('subject_key')`, не
  `requireKey($_POST['subject_key'])` (для значений массива — `*Value`-хелперы). Эта ошибка
  ранее уронила 65 AJAX-путей Course/Assessment («Недостаточно данных»). Память
  `sanitizer-trait-is-key-name-based`.
- **`Enqueue` гейтит ассеты по экрану.** Новый экран/CPT → завести `$is_*_cpt`-флаг в
  `Enqueue::enqueue_admin_assets()`, иначе нет наших CSS/JS (детали — `explain.md`, раздел 9.3).
- **`lesson_id` теперь nullable** (слот без урока). Все потребители `GroupLessonDTO->lessonId`
  обязаны иметь null-guard (`LessonPlayerService`, `EffectiveWorksResolver`, `LessonGateResolver` и др.).
- **Покрывать коллбеки тестами** — непокрытый слой коллбеков и скрыл Sanitizer-баг (память `cover-callbacks-with-tests`).

---

## Типы задач (Этап 6): интерактивные задания, редактор, проверка, попытки

Раздел описывает систему, появившуюся в Этапе 6: новые типы заданий с автопроверкой,
data-driven редактор в конструкторе урока, поток сдачи ответа учеником, двухуровневые
настройки шага и историю попыток для преподавателя.

---

### Каталог типов задач (`TaskTemplate`)

Все типы — кейсы enum `Inc\Enums\Subject\TaskTemplate`. Значение кейса — суффикс
`template_type` в `post_meta` (`PostMetaName::TemplateType`).

| `TaskTemplate` | `value` | Проверка | Ключевые поля мета |
|---|---|---|---|
| `Standard`     | `standard_task`       | авто (текст) | `task_condition`, `task_hint` |
| `Triple`       | `triple_task`         | авто (3 поля) | `task_ans_1..3`, `task_hint` |
| `Common`       | `common_standard_task`| авто (текст) | `task_condition`, `task_hint` |
| `Code`         | `code_task`           | ручная | `task_condition`, `task_hint` |
| `FileCode`     | `file_code_task`      | ручная | — |
| `File`         | `file_task`           | ручная | — |
| `TwoFile`      | `two_file_code_task`  | ручная | — |
| `TextSolution` | `text_task`           | ручная | — |
| `Choice`       | `choice_task`         | авто | `task_condition`, `task_options` (`{multiple, options[{id,text,correct}]}`), `task_hint` |
| `Matching`     | `matching_task`       | авто | `task_condition`, `task_pairs` (`{pairs[{left,right}]}`), `task_hint` |
| `Ordering`     | `ordering_task`       | авто | `task_condition`, `task_order_items` (`{items[]}`), `task_hint` |
| `Fill`         | `fill_task`           | авто | `task_condition`, `task_gap_text` (`{text}` — пропуски `[[ответ|синоним]]`), `task_hint` |
| `Audio`        | `audio_task`          | авто (текст) | `task_condition`, `task_audio`, `task_hint` |

**Определить тип шаблона у задания** (по `post_id`):
```php
$type = TaskTemplate::from(
    get_post_meta( $postId, PostMetaName::TemplateType->value, true )
);
```

---

### Как устроены поля шаблона

Каждый шаблон (`inc/MetaBoxes/Templates/`) наследует `BaseTemplate` и объявляет поля через
`get_fields()`. Поле — объект, реализующий `FieldInterface`. Базовые классы полей:

| Класс поля | `editorType()` | Что хранит |
|---|---|---|
| `InputField`      | `text`        | короткая строка |
| `ConditionField`  | `rich_text`   | TinyMCE-условие задачи |
| `OptionsField`    | `options`     | массив вариантов выбора |
| `PairsField`      | `pairs`       | массив пар «левое→правое» |
| `OrderItemsField` | `order_items` | упорядоченный список элементов |
| `GapTextField`    | `gap_text`    | текст с пропусками `[[ответ\|синоним]]` |
| `AudioField`      | `audio`       | URL аудиофайла |
| `HintField`       | `hint`        | текст подсказки |

`BaseTemplate::getEditorSchema()` экспортирует схему шаблона в JS-совместимый массив:
```php
[
    'id'       => 'choice_task',
    'label'    => 'Выбор ответа',
    'category' => 'interactive',
    'fields'   => [
        [ 'key' => 'task_condition', 'label' => 'Условие', 'type' => 'rich_text', 'config' => [] ],
        [ 'key' => 'task_options',   'label' => 'Варианты', 'type' => 'options', 'config' => [] ],
        [ 'key' => 'task_hint',      'label' => 'Подсказка', 'type' => 'hint',  'config' => [] ],
    ],
]
```

`TemplateRegistry::allEditorSchemas()` возвращает схемы всех 13 шаблонов одним массивом,
ключованным по `value`. Этот массив локализуется в JS через `wp_localize_script` под именем
`fs_lms_task_editor_vars.schema`.

---

### Редактирование задач: два режима

#### 1. Admin metabox (CPT task bank)

На экране редактирования CPT `{subject}_tasks` рендерится стандартный WP metabox.
`MetaBoxController` регистрирует его через `MetaBoxRegistrar`. Поля рендерит шаблон (`BaseTemplate`).
Сохранение — через `save_post` хук → `MetaBoxManager::saveFields()`.

#### 2. Inline editor (конструктор урока / step-editor)

При добавлении шага типа «задание» (`StepType::Task`) в конструкторе курса открывается
`TaskEditor` (`src/js/admin/services/task-editor.js`) — модальный JS-компонент:

1. Читает `fs_lms_task_editor_vars.schema` — все шаблоны из PHP уже переданы в JS.
2. Показывает список шаблонов → пользователь выбирает тип.
3. Рендерит поля нужного типа через `_renderFields()` (switch по `field.type`).
4. При сохранении отправляет AJAX `AjaxHook::SaveTaskContent` (`save_task_content`):
   ```js
   { subject_key, template, title, data: JSON, post_id: 0 }
   ```
5. Callback `TaskContentCallbacks::ajaxSaveTaskContent()` создаёт/обновляет CPT-пост
   и вызывает `MetaBoxManager::saveFields()`.
6. Колбэк `onSave(id, title)` обновляет `step.payload.ref` в конструкторе урока.

**Когда локализуется `fs_lms_task_editor_vars`:**
```php
// Enqueue.php
$needs_task_editor = $is_task_cpt || $is_lesson_cpt || $is_work_cpt
                   || $is_course_cpt || str_starts_with( $page, 'fs_subject_' );
```

**Настройки шага** (`step.payload.settings`) правятся в той же панели редактора:
`max_attempts`, `shuffle`, `hint_after_errors`. Хранятся в `step_settings_overrides`
колонке `fs_lms_group_lessons` (двухуровневая система: дефолты в шаге урока → override в
конкретном групповом занятии). Сервис `EffectiveStepSettingsService` мёрджит оба уровня.

---

### Как добавить новый тип задачи

> Чеклист: 6 шагов + при необходимости шаг 7 (автопроверка).

**Шаг 1. Enum** — добавить кейс в `TaskTemplate`:
```php
case MyNew = 'my_new_task';
```

**Шаг 2. Поля** — при необходимости создать `inc/MetaBoxes/Fields/MyNewField.php`:
```php
class MyNewField extends BaseField {
    public function editorType(): string { return 'my_new'; }
    // Реализовать render(), sanitize(), validate()
}
```

**Шаг 3. Шаблон** — создать `inc/MetaBoxes/Templates/MyNewTaskTemplate.php`:
```php
class MyNewTaskTemplate extends BaseTemplate {
    public function get_id(): string   { return TaskTemplate::MyNew->value; }
    public function get_name(): string { return 'Мой новый тип'; }
    public function get_category(): TemplateCategory { return TemplateCategory::Interactive; }
    public function get_fields(): array {
        return [
            'task_condition' => [ 'label' => 'Условие', 'object' => new ConditionField() ],
            'my_new_data'    => [ 'label' => 'Данные',  'object' => new MyNewField() ],
            'task_hint'      => [ 'label' => 'Подсказка', 'object' => new HintField() ],
        ];
    }
}
```

**Шаг 4. Регистрация** — `TemplateRegistry` обнаруживает шаблоны через `TaskTemplate` enum
автоматически (рефлексия: ищет класс `{PascalCase(value)}Template` в `inc/MetaBoxes/Templates/`).
Новый файл сразу виден в `allEditorSchemas()`.

**Шаг 5. JS-рендер полей в `TaskEditor`** — если тип поля новый, добавить ветку в
`_renderFields()` и `_collectFields()` в `src/js/admin/services/task-editor.js`.

**Шаг 6. Виджет в плеере** — добавить ветку в `LessonPlayerService::renderTaskStep()` (PHP
view-модель) и HTML в `templates/frontend/lesson-player/player.php` + JS-логику сборки ответа
в `src/js/frontend/components/task-widgets.js`.

**Шаг 7 (опц.) Автопроверка** — создать `inc/Services/Task/Checkers/MyNewChecker.php`
(реализует `TaskCheckerInterface::check(array $content, mixed $answer): CheckResultDTO`),
зарегистрировать в конструкторе `TaskCheckerRegistry`:
```php
$this->map = [
    ...
    TaskTemplate::MyNew->value => $myNew,
];
```

---

### Поток сдачи ответа учеником

```
Ученик нажимает «Проверить»
  → JS собирает ответ по виджету типа задачи
  → AJAX: AjaxHook::SubmitTaskAnswer  (submit_task_answer)
      { nonce, group_lesson_id, step_key, task_id, answer: JSON }
  → SubmitTaskAnswerCallbacks::ajaxSubmitTaskAnswer()
      Nonce::SubmitTask->verify()
      1. TaskAttemptRepository::countByStep() — проверить лимит попыток
         (с учётом EffectiveStepSettingsService для данной группы)
      2. TaskAttemptRepository::create() — сохранить попытку
      3. TaskCheckerRegistry::has($template) ?
           CheckResultDTO = TaskCheckerRegistry::get($template)->check($content, $answer)
         : null (ручная проверка)
      4. TaskAttemptRepository::update() — записать вердикт + score
      5. AutoGradeService — обновить submission/score если нужно
      6. $this->success([ 'is_correct', 'score', 'max_score', 'item_feedback', ... ])
  → JS: обновляет виджет (зелёный/красный, подсветка по элементам через item_feedback)
```

**`TaskAttemptDTO`** — единица хранения попытки:

| Поле | Тип | Описание |
|---|---|---|
| `studentPersonId` | int | ID person-поста ученика |
| `groupLessonId`   | int | ID строки `fs_lms_group_lessons` |
| `stepKey`         | string | UUID шага в `lesson.steps[]` |
| `taskId`          | int | ID CPT-задачи |
| `attemptNumber`   | int | Номер попытки (1, 2, …) |
| `answer`          | mixed | JSON-декодированный ответ |
| `isCorrect`       | bool\|null | null = ещё не проверено / ручная |
| `score`/`maxScore`| float\|null | Начисленные/возможные баллы |
| `itemFeedback`    | array\|null | Детализация по элементам (пары, позиции, пропуски) |
| `createdAt`       | string | Время попытки |

---

### Двухуровневые настройки шага

Настройки `max_attempts`, `shuffle`, `hint_after_errors` хранятся в двух местах и мёрджатся:

| Уровень | Где хранится | Редактируется |
|---|---|---|
| Шаг урока (дефолт) | `lesson.steps[].payload.settings` (JSON в CPT-мета) | Конструктор курса (`renderStepSettings()` в `step-editor.js`) |
| Групповой урок (override) | `fs_lms_group_lessons.step_settings_overrides` (JSON) | Кнопка ⚙️ в кокпите группы (`ajaxSaveStepSettings`) |

`EffectiveStepSettingsService::resolve(GroupLessonDTO $gl, string $stepKey): StepSettingsDTO`
— мёрджит оба уровня (override побеждает). Только этот метод следует использовать при чтении
настроек шага в `SubmitTaskAnswerCallbacks` и `LessonPlayerService`.

---

### История ответов у преподавателя

В кокпите группы рядом с кнопкой ⚙️ «Настройки» есть кнопка 📋 «Ответы»:

1. Клик → `toggleAnswersPanel()` → список шагов (из того же `GetStepSettings`).
2. По каждому шагу кнопка «Загрузить ответы» → AJAX `AjaxHook::GetTaskAttempts`
   (`get_task_attempts`, `{ group_lesson_id, step_key }`).
3. `TaskAttemptCallbacks::ajaxGetTaskAttempts()` → `TaskAttemptRepository::listByGroupAndStep()`
   → группировка по `studentPersonId` → имя из `get_the_title($sid)`.
4. Ответ: `[{ student_id, student_name, attempts: [{attempt_number, is_correct, score, max_score, created_at}] }]`.
5. JS рендерит список: зелёная/красная полоска слева, попытка#N, счёт, время.

**Права:** `Nonce::StepSettings` + `Capability::ManageLMSAssignments` (тот же нонс, что у настроек шагов).

---

### Автопроверщики: архитектура

`TaskCheckerRegistry` — единственная точка входа. Инжектируется в `SubmitTaskAnswerCallbacks`.

| Чекер | За что отвечает | Алгоритм |
|---|---|---|
| `ChoiceChecker`   | `choice_task`   | Сравнивает sorted IDs правильных вариантов со submitted |
| `MatchingChecker` | `matching_task` | Case-insensitive сравнение карты `left→right`; `itemFeedback` по паре |
| `OrderingChecker` | `ordering_task` | Позиция-за-позицией case-insensitive; `itemFeedback[i]` = bool |
| `FillChecker`     | `fill_task`     | `FillTextParser::checkGap()` per gap; синонимы через `\|`; `itemFeedback[i]` = bool |
| `TextAnswerChecker` | `standard_task`, `common_standard_task`, `audio_task` | Normalize + сравнение строк |
| `TripleAnswerChecker` | `triple_task` | Три поля `task_ans_1..3` — каждое normalize + сравнение |

`CheckResultDTO`: `isCorrect: bool`, `score: float`, `maxScore: float`, `itemFeedback: ?array`.

`TaskCheckerRegistry::has(TaskTemplate)` → `bool` — проверить, есть ли автопроверщик.
`TaskCheckerRegistry::get(TaskTemplate)` → `?TaskCheckerInterface` — получить (null = ручная).

---

## Типы работ и контрольных (Этап 7): WorkType, AssessmentKind, пакетная сдача, грейдбук

Этот раздел описывает механику пакетной сдачи работ, типы контрольных с их ограничениями и способы настройки.

### WorkType — тип работы

Enum `Inc\Enums\Course\WorkType` определяет характер работы. Тип задаётся на самой работе (`{key}_works`, мета `work_type`) и снапшотируется в `submissions.work_type` при сдаче.

```php
enum WorkType: string {
    case Practice    = 'practice';    // Практика
    case Independent = 'independent'; // Самостоятельная работа
    case Homework    = 'homework';    // Домашнее задание
}
```

**Как установить тип:** в `AssessmentTemplate` (метабокс работы в WP-админке) есть select-поле `work_type`. Значение читается через `WorkType::fromValueOrDefault()` при построении `WorkDTO`. Тип влияет на то, как журнал оценок категоризирует сдачи и влияет ли дедлайн из `group_lessons.homework_due_at`.

---

### AssessmentKind — тип контрольной

Enum `Inc\Enums\Assessment\AssessmentKind` определяет режим контрольной. Задаётся в метабоксе контрольной через `AssessmentKindField` (select `kind`).

```php
enum AssessmentKind: string {
    case Control     = 'control';       // Контрольная (по умолчанию)
    case Ege         = 'ege';           // ЕГЭ-вариант
    case EgeComputer = 'ege_computer';  // Компьютерный ЕГЭ
}
```

**Предикаты (методы enum) и что они включают:**

| Метод | Control | Ege | EgeComputer | Что происходит |
|---|---|---|---|---|
| `locksContent()` | ✓ | ✓ | ✓ | `ExamLockService` блокирует весь контент ученика через `LessonGateResolver` |
| `hidesAnswers()` | ✓ | ✓ | ✓ | `ExamPayloadFilter` вырезает правильные ответы из payload плеера |
| `answersOnly()` | ✓ | ✓ | ✓ | Убираются `task_code`, `solution_text`, `explanation` |
| `usesWeightedScore()` | — | ✓ | ✓ | Каждое задание имеет `task_points[id]` баллов |
| `needsSecondaryScore()` | — | ✓ | ✓ | В журнале показывается вторичный балл из `score_map` |
| `expandsComposites()` | — | ✓ | ✓ | `ThreeInOne` → три отдельных под-задания `19`/`20`/`21` |
| `needsCompletenessCheck()` | — | ✓ | ✓ | `EgeCompletenessChecker` предупреждает о не покрытых номерах заданий |

Использование в коде — всегда через предикат, не через `match`:
```php
if ( $kind->usesWeightedScore() ) { /* взвешенные баллы */ }
if ( $kind->needsSecondaryScore() ) { $secondary = $scoreMapSvc->translate(...); }
```

---

### Пакетная сдача работы

`SubmissionService::submitBatch($studentPersonId, $groupLessonId, $workId, $answers, $taskPoints)`:

1. Проверяет доступ ученика через `LessonAccessPolicy`.
2. Загружает работу (`WorkDTO`) — берёт `work_type`, `item_ids`.
3. Вызывает `BatchCheckService::check($answers, $taskPoints, $kind)`:
   - Для каждого задания получает `WP_Post` → `TemplateResolver::resolveEnum()` → нужный `TaskCheckerInterface`.
   - Если `kind->expandsComposites()` и шаблон реализует `expandsForExam()` → разворачивает `ThreeInOne` в три под-ключа `"taskId:19"`.
   - Задания без регистрированного чекера (Code, File) → `hasManual = true`, вердикт `pending`.
4. Создаёт `task_id IS NOT NULL` строки в `submissions` — по одной на задание.
5. Создаёт/обновляет агрегат (`task_id IS NULL`): `score = correctCount`, `max_score = totalCount`.
6. Статус агрегата: `pending_review` если `hasManual`, иначе `submitted`.

**Ручная оценка задания** (`GradeBatchTask`):

```
POST ajax: action=grade_batch_task
  submission_id, score, feedback, security
```

`BatchSubmissionCallbacks::ajaxGradeBatchTask()` → `SubmissionService::gradeBatchTask()`:
- Обновляет пер-тасковую строку в `graded`.
- Пересчитывает агрегат: если все пер-тасковые `graded` → агрегат переходит в `graded`.

---

### Таблица перевода баллов ЕГЭ (score_map)

Только для `kind->needsSecondaryScore() === true` (Ege, EgeComputer).

**Как настроить score_map в UI:**

1. Скопировать таблицу перевода из ФИПИ или Excel (любой разделитель: таб, `;`, `,` или двойной пробел).
2. Вставить в поле `score_map` на странице редактирования контрольной.
3. Нажать «Разобрать» → AJAX `parse_score_map` → `ScoreMapParser::parse()` вернёт массив `{primary: secondary}`.
4. Или нажать «Скопировать из другой работы» → AJAX `copy_score_map` → `ScoreMapCallbacks::ajaxCopyScoreMap()` скопирует таблицу из выбранной ЕГЭ-работы.

**`ScoreMapParser::parse($text)`** — принимает двухколоночный текст:
- Строки разделены `\n` или `\r\n`.
- Пары — таб, `;`, `,` или два и более пробела.
- Нечисловые строки-заголовки игнорируются.
- Возвращает `array<int, int>` primary → secondary, отсортированный по ключу.

**`SecondaryScoreService::translate($primary, $scoreMap)`:**
- `floor($primary)` → ищет точный ключ.
- Нет точного → ближайший меньший ключ.
- Нет покрытия (балл ниже минимума таблицы) → `null`.

---

### Блокировка контента во время экзамена

`ExamLockService` (`Inc\Services\Assessment\ExamLockService`):

```php
$svc->isLocked($studentPersonId);              // bool
$svc->getActiveLockingAttempt($studentPersonId); // ?AttemptDTO
```

Работает так:
1. `AssessmentAttemptRepository::findAnyActive($personId)` — ищет любую `in_progress` и не просроченную попытку.
2. Загружает `AssessmentDTO` для этой попытки.
3. Если `$assessment->kind->locksContent() === true` → ученик заблокирован.

`LessonGateResolver::resolveLesson()` вызывает `ExamLockService` **первым** (до проверки даты и прогресса). Пока блокировка активна → любой урок возвращает `GateState::Locked`.

---

### Журнал оценок: displayType

`GradebookEntryDTO` (возвращается из `ajaxGetGradebook`) содержит поле `display_type` и готовое значение `display_value`:

| `display_type` | Когда | `display_value` |
|---|---|---|
| `fraction` | Агрегат пакетной сдачи работы | `"5/8"` (correct/total) |
| `score` | Экзамен с выставленной оценкой; для ЕГЭ — вторичный балл | `"72"` |
| `pending` | Попытка сдана, ждёт ручной проверки | `"На проверке"` |

В JS нет смысла самостоятельно форматировать — использовать `display_value` напрямую.

---

### Как добавить собственный плеер для типа контрольной

Используется фильтр `AssessmentPageController::RENDERER_FILTER` = `'fs_lms_assessment_renderer'`.

```php
// В своём ServiceInterface::register():
add_filter( 'fs_lms_assessment_renderer', [ $this, 'resolveRenderer' ], 10, 3 );

public function resolveRenderer( string $default, string $kind, string $subjectKey ): string {
    if ( $kind !== 'my_kind' ) { return $default; }
    return plugin_dir_path( __FILE__ ) . 'templates/my-player.php';
}
```

Шаблон получает те же переменные, что и `attempt.php`:
- `$assessment` — `AssessmentDTO`
- `$activeAttempt` — `AttemptDTO|null`
- `$person` — `PersonDTO|null`

Если возвращённый файл не существует — автоматически откат к `attempt.php`.

Эталон реализации — `Inc\Modules\EgeComputer\EgeComputerModule` (флаг `FS_LMS_EGE_COMPUTER`).

---

### Как добавить новый тип контрольной (расширение AssessmentKind)

1. Добавить case в `AssessmentKind` с нужными предикатами.
2. Добавить `label()` и `options()` подхватят автоматически.
3. Если нужен отдельный плеер — создать модуль в `Inc\Modules\`, зарегистрировать его в `Init::getServices()`, внутри `register()` подписаться на `fs_lms_assessment_renderer`.
4. Если нужна отдельная проверка — реализовать логику в `BatchCheckService::check()` через предикаты нового вида.

---

## Личный кабинет /profile/: вынос в приложение (Telegram Web App / мобилка)

Кабинет `/profile/` собран так, что его можно перенести в **Telegram Web App** или **мобильное приложение**
без переписывания бизнес-логики. Здесь — почему это возможно и как сделать.

### Что уже играет в плюс

- **Логика отвязана от транспорта.** Вся «мякоть» — в Services/Repositories/Managers, контроллеры и
  Callbacks тонкие (см. «Контроллеры и Callbacks»). Значит переиспользуется «мозг» — меняется только
  доставка + авторизация.
- **Фронт — фреймворк-независимый vanilla JS.** Каждый экран читает один объект `window.fsProfile` и
  ходит на бэкенд через **единственный шов** `FS_LMS_API`. Telegram Web App и webview-обёртка мобилки —
  это буквально webview с URL, так что `/profile/` едет туда почти как есть.
- **Конфиг уже сериализуется.** `ProfileViewResolver::jsConfig()` собирает весь `fsProfile` как массив —
  его тривиально отдать и JSON-эндпоинтом, а не только инъекцией в HTML.

### Единственный сетевой шов — `FS_LMS_API`

`src/js/profile/api.js` — **одно место**, знающее про admin-ajax + nonce. Экраны (журнал/КТП/проверка)
не делают `fetch` сами, а берут хелпер `createApi(block)` и зовут `api('actionKey', params)`. Полный
контракт транспорта и конфиг-блоков — в `FS_LMS_API.md` → «Клиентский шов `FS_LMS_API`».

Ключевое: транспорт вызывается **через объект** (`FS_LMS_API.request`), поэтому его можно подменить в
рантайме (`window.FS_LMS_API.request = …`) — например, слать Telegram `initData` на REST вместо WP-nonce —
и все экраны подхватят подмену без пересборки.

> **Правило:** новый экран кабинета обязан ходить на бэкенд **только** через `createApi`/`FS_LMS_API`.
> Прямой `fetch`/`XMLHttpRequest` в экране — нельзя (иначе теряется единая точка выноса).

### Три пути (по возрастанию цены)

| Путь | Что делаешь | Цена |
|---|---|---|
| **Telegram Web App** | Кнопка бота открывает `/profile/` в webview + мост `initData → WP-user` | низкая |
| **Webview-обёртка** (Capacitor/TWA) | тот же `/profile/`, обёрнутый в нативный контейнер, токен вместо куки | низко-средняя |
| **Нативка** (RN/Flutter) | свой UI, REST-фасад над теми же Services | высокая (переписывается только фронт) |

### Три шва, которые надо закрыть

Логику (Services/Repositories) **не трогаем** — фасадим транспорт и авторизацию:

1. **Авторизация.** Сейчас WP-cookie-сессия + nonce (`credentials: 'same-origin'`). Для внешнего клиента —
   Telegram `initData` (HMAC от токена бота) или токен (Application Passwords / JWT) → маппинг на WP-юзера.
   Реализуется **отдельным модулем** `Inc\Modules\…` по образцу `SocialAuth`/`AdSync` (флаг + `onToggle`,
   ядро на модуль не ссылается — см. «Модульная архитектура»).
2. **Доставка конфига.** `window.fsProfile` инъектится в HTML сервером. Внешнему клиенту нужен
   bootstrap-эндпоинт, отдающий тот же `ProfileViewResolver::jsConfig()` JSON-ом.
3. **Транспорт.** `admin-ajax.php` + `action` + `security`. Для внешних клиентов — REST-namespace
   (`register_rest_route`), зеркалящий те же `actions` и делегирующий в **те же Callbacks/Services**.

### Пошагово: Telegram Web App (самый дешёвый MVP)

1. Создать бота, включить кнопку Web App с URL на `/profile/`.
2. Модуль `Inc\Modules\TelegramAuth` (тумблер + константа `FS_LMS_TG_BOT_TOKEN`): проверяет подпись
   `initData` (HMAC-SHA256 от `WebAppData`-ключа) → находит/создаёт WP-пользователя → ставит сессию или
   выдаёт токен. Ядро на модуль не ссылается.
3. На клиенте — подменить `window.FS_LMS_API.request` на вариант с `initData`/токеном (пример — в
   `FS_LMS_API.md` §7). Экраны не трогаем.
4. Мелочи webview: тема Telegram (`themeParams`), кнопка «назад», вьюпорт; в `profile.php` убрать жёсткие
   зависимости от WP-хрома, если появятся.

### Чего делать НЕ надо

- Не дублировать логику в REST-контроллерах — они тонкие, зовут существующие Services.
- Не хардкодить транспорт в экранах — только через `FS_LMS_API`.
- Не тащить Telegram/мобильную авторизацию в ядро — это **модуль** (отключаемый лист).

---

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
- **AjaxController** — шаблонный класс для контроллеров с AJAX-хуками
- **Фронтенд-страницы и шорткоды** — автосоздание при активации, PageRoutes, ShortCode
- **Роли и матрицу прав** — UserRole, Capability, автосинхронизация при обновлении
- **Конфигурацию wp-config.php** — обязательные и опциональные константы
- **CsvExportService** — паттерн Column Projection для экспорта данных
- **Управление паролями** — двойное хранение (user_pass + зашифрованный meta), стратегии при зачислении, автоочистка meta при ручной смене, регенерация из UI
- **Систему уведомлений** — четыре механизма: нативная валидация у поля, `showNotice` / `showModalError` для серверных ошибок в UI, `showToast` для сетевых ошибок, `AlertModal` для критических ошибок поверх открытых модалов
- **Troubleshooting** — пошаговая диагностика от браузера до БД, разборы типичных кейсов, шпаргалка по симптомам и инструменты отладки
- **Систему обучения (Этапы 1–4)** — банки контента в CPT (работы/уроки/курсы/контрольные + глобальные задачи), факты обучения в кастомных таблицах (программа группы, сдачи, попытки, лента событий), переиспользование по ссылке, copy-on-publish, матрицу доступа и расширяемый журнал оценок
- **Систему обучения (MVP-2 «Курсы»)** — модель шагов урока (`steps[]`) и модулей курса (`modules[]`), SPA-конструктор курса, пошаговый плеер ученика с прогрессом и гейтингом, клонирование/форк контента и (бэкенд) календарь занятий
- **Типы задач (Этап 6)** — 13 типов заданий (`TaskTemplate`), data-driven inline-редактор в конструкторе курса (`TaskEditor`), автопроверщики per-type (`TaskCheckerRegistry`, `CheckResultDTO`), двухуровневые настройки шага (`EffectiveStepSettingsService`), история попыток для преподавателя в кокпите группы
- **Личный кабинет /profile/ и вынос в приложение** — единственный сетевой шов `FS_LMS_API` (`src/js/profile/api.js`), переопределяемый через `window.FS_LMS_API.request`; три пути переноса кабинета в Telegram Web App / мобилку и три шва (авторизация → модуль, доставка конфига → JSON, транспорт → REST-фасад над теми же Services)
- **Типы работ и контрольных (Этап 7)** — `WorkType` (practice/independent/homework) и их статусы сдачи; `AssessmentKind` (control/ege/ege_computer) с поведенческими предикатами (`locksContent`, `hidesAnswers`, `usesWeightedScore`, `needsSecondaryScore`, `expandsComposites`); пакетная сдача (`BatchCheckService`, агрегатные и пер-тасковые строки); таблица перевода первичный→вторичный (`ScoreMapParser`, `SecondaryScoreService`); блокировка контента во время экзамена (`ExamLockService`, `LessonGateResolver`); журнал оценок с `displayType` (fraction/score/pending); подключение кастомного плеера через фильтр `fs_lms_assessment_renderer`

Все компоненты следуют принципам **SOLID** и используют паттерны проектирования для обеспечения поддерживаемости и расширяемости кода.