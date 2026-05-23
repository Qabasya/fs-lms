# Паттерны проектирования и принципы SOLID

## Содержание

1. [Принципы SOLID](#принципы-solid)
2. [Паттерны проектирования](#паттерны-проектирования)
3. [Архитектурная согласованность](#архитектурная-согласованность)

---

## Принципы SOLID

### S — Single Responsibility Principle

Каждый класс выполняет строго одну функцию (имеет одну обязанность)

**Как реализовано:**

Слои жёстко разделены по обязанностям:

| Слой | Обязанность |
|---|---|
| `Controllers/` | Регистрация WP-хуков (`add_action`, `add_filter`) |
| `Callbacks/` | Обработка AJAX: санитизация → вызов сервиса → ответ |
| `Services/` | Бизнес-логика, не знает о WP-хуках и HTTP |
| `Repositories/` | Чтение/запись `wp_options` (CRUD), возвращает и принимает DTO |
| `Managers/` | Обёртка над WP API (`get_posts`, `update_post_meta`) - низкоуровневая инкапсуляция WP методов |
| `DTO/` | Передача данных между слоями, без логики |

**Пример корректного разделения:**

`StudentGroupCallbacks::ajaxSaveStudentGroup()` только проверяет nonce, собирает данные и делегирует. Вся логика генерации слага, проверки коллизий и сохранения — в `StudentGroupService::createGroup()`. Проверка nonce и ajax ответы выполняются трейтами.

**Слабые места:**

`AdminCallbacks` накапливает коллбеки для всех страниц плагина (`adminDashboard`, `settingsPage`, `groupsPage`, `userlistPage`, `boilerplatePage`). Технически каждый метод не нарушает SRP, но класс растёт с каждой новой страницей. Далее разделим логику.

---

### O — Open/Closed Principle

Классы открыты для расширения, закрыты для модификации.

**Как реализовано:**

`AjaxController` — абстрактный базовый класс. Метод `registerAjaxHooks()` помечен `final` — его нельзя переопределить. Дочерние классы добавляют поведение, переопределяя `ajaxActions()` и `publicAjaxActions()`:

```php
// StudentGroupController — добавляет два хука без изменения AjaxController
protected function ajaxActions(): array {
    return [
        [AjaxHook::SaveStudentGroup,   $this->student_group_callbacks],
        [AjaxHook::DeleteStudentGroup, $this->student_group_callbacks],
    ];
}
```

`TemplateRegistry` расширяется через WP-фильтр без изменения кода:
```php
$candidates = apply_filters('fs_lms_register_templates', $builtin);
```

`SubjectController` регистрирует хук `fs_lms_cpt_args`, позволяющий модифицировать параметры CPT извне.

---

### L — Liskov Substitution Principle

Подклассы можно использовать вместо базовых классов.

**Как реализовано:**

Все контроллеры (`StudentGroupController`, `SubjectController`, `TaskCreationController` и др.) расширяют `AjaxController` и реализуют `ServiceInterface`. `Init::run()` вызывает `->register()` на каждом, не зная их конкретных типов — это и есть LSP в действии.

`GoogleAuthStrategy`, `VkAuthStrategy`, `GithubAuthStrategy` реализуют `AuthStrategyInterface`. `AuthService::processUserFromSocialProfile()` принимает `AuthProvider` и получает стратегию из реестра — конкретный провайдер не важен для вызывающего кода.

---

### I — Interface Segregation Principle

Интерфейсы не должны заставлять реализовывать ненужные методы. На данном этапе интерфейсы редко используются. В будущем планируется внедрение большего числа интерфейсов.

**Как реализовано:**

В `inc/Contracts/` четыре узкоспециализированных интерфейса:

- `ServiceInterface` — только `register(): void`
- `FieldInterface` — только `render(\WP_Post, string, string, mixed): void`
- `MenuBuilderInterface` — только `buildPages(): array` и `buildSubPages(): array`
- `AuthStrategyInterface` — только `getProvider()`, `login()`, `authenticate()`

Ни один класс не реализует больше одного интерфейса. 

---

### D — Dependency Inversion Principle

Зависимости от абстракций, а не от конкретных реализаций.

**Как реализовано:**

Все зависимости инжектируются через конструктор — нет ни одного `new` внутри методов бизнес-логики. `Container::get()` разрешает граф зависимостей через Reflection автоматически.

`AuthController` зависит от `AuthStrategyRegistry` (реестра), а не от конкретных стратегий. `TemplateResolver` зависит от `MetaBoxRepository`, а не от конкретного хранилища.

**Нюанс:** Репозитории напрямую вызывают `get_option()`, `update_option()` — WordPress-функции. Полная инверсия потребовала бы абстракции над WP API, что в контексте WordPress-плагина избыточно.

---

## Паттерны проектирования

### Dependency Injection + Autowiring Container

**Что это:** Механизм автоматической передачи зависимостей между объектами без ручного создания.

**Проблема:** Без DI каждый класс создаёт зависимости сам — жёсткая связность, нет переиспользования.

**Реализация:** `Inc\Core\Container` использует `ReflectionClass` для анализа конструктора и рекурсивно строит граф объектов. Каждый экземпляр сохраняется в `$instances` — повторный `get()` возвращает тот же объект (Lazy Singleton).

```php
// Container::get() вызывается для AdminController
// Reflection видит: нужны MenuRegistrar, SettingsRegistrar, AdminCallbacks, SubjectsMenuBuilder
// Каждый из них рекурсивно разрешается со своими зависимостями
$admin = $container->get(AdminController::class);
```

**Ограничение:** Не умеет разрешать built-in типы (`string`, `int`) без значений по умолчанию. Поэтому все конструкторы классов используют только type-hinted объекты или параметры с дефолтами.

---

### Service Registry (Init)

**Что это:** Централизованный список всех сервисов приложения.

**Проблема:** Порядок инициализации компонентов должен быть явным и управляемым.

**Реализация:** `Inc\Init::getServices()` возвращает упорядоченный список классов. `Init::run()` создаёт Container, итерирует список и вызывает `register()` на каждом сервисе:

```php
public static function getServices(): array {
    return [
        Enqueue::class,
        AdminController::class,
        SubjectController::class,
        // ...13 сервисов
    ];
}
```

Добавление нового функционала = добавление одного класса в этот список.

---

### Repository

**Что это:** Инкапсуляция логики доступа к данным за единым интерфейсом.

**Проблема:** Прямые вызовы `get_option()` / `update_option()` в сервисах и контроллерах делают код хрупким.

**Реализация:** Все репозитории в `inc/Repositories/` читают и пишут только через `get_option` / `update_option`, инкапсулируя структуру данных. Ключи опций — только через `OptionName` enum.

```php
// SubjectRepository::readAll() — вызывающий код не знает о wp_options
public function readAll(): array {
    return array_map(
        fn(array $item) => SubjectDTO::fromArray($item),
        $this->getRaw()   // get_option(OptionName::SUBJECTS->value, [])
    );
}
```

Репозитории возвращают DTO, а не сырые массивы — это граница между слоем хранения и слоем бизнес-логики.

---

### DTO (Data Transfer Object)

**Что это:** Иммутабельный объект для передачи данных между слоями без логики.

**Проблема:** Сырые ассоциативные массивы не типизированы — опечатка в ключе не даёт ошибку, а возвращает `null`.

**Реализация:** Все DTO в `inc/DTO/` — `readonly` классы. Обязательные методы: `fromArray()` (фабрика) и `toArray()` (сериализация).

```php
readonly class SubjectDTO {
    public function __construct(
        public string $key,
        public string $name,
    ) {}

    public static function fromArray(array $data): self {
        return new self(key: $data['key'] ?? '', name: $data['name'] ?? '');
    }
}
```

DTO используются как аргументы методов (доступ через `->name`, а не `['name']`) и как переменные в PHP-шаблонах.

---

### Service Layer

**Что это:** Слой бизнес-логики, изолированный от деталей доставки (HTTP, WP hooks) и хранения.

**Проблема:** Бизнес-логика в коллбеках — невозможно переиспользовать и тестировать.

**Реализация:** Сервисы в `inc/Services/` — stateless (или `readonly`) классы:

- `StudentGroupService` — генерирует слаг, проверяет коллизии, создаёт DTO, вызывает репозиторий
- `AuthService` — полный цикл OAuth: поиск по social ID → поиск по email → регистрация → логин
- `ContentCacheService` — инвалидация транзиентов по типу поста
- `SubjectDeletionService`, `SubjectImportService`, `SubjectExportService` — разбиты по операциям

Сервисы не вызывают `add_action`, не читают `$_POST`, не отправляют JSON.

---

### Template Method

**Что это:** Базовый класс определяет алгоритм, оставляя конкретные шаги подклассам.

**Проблема:** Дублирование инфраструктурного кода в каждом контроллере.

**Реализация №1 — `AjaxController`:**

```php
// Алгоритм фиксирован в базовом классе (final)
final protected function registerAjaxHooks(): void {
    foreach ($this->ajaxActions() as [$hook, $callback]) {
        add_action($hook->action(), [$callback, $hook->callbackMethod()]);
    }
    // ...публичные хуки аналогично
}

// Подкласс определяет только ЧТО регистрировать
// StudentGroupController:
protected function ajaxActions(): array {
    return [
        [AjaxHook::SaveStudentGroup,   $this->student_group_callbacks],
        [AjaxHook::DeleteStudentGroup, $this->student_group_callbacks],
    ];
}
```

**Реализация №2 — `BaseTemplate`:**

`BaseTemplate::render(\WP_Post $post)` загружает мета-данные, итерирует `$this->fields`, делегирует рендер каждому полю. Конкретные шаблоны (`StandardTaskTemplate`, `CodeTaskTemplate` и др.) определяют только состав полей и идентификатор через абстрактные `get_id()` и `get_name()`.

---

### Strategy

**Что это:** Семейство взаимозаменяемых алгоритмов с единым интерфейсом.

**Проблема:** `if ($provider === 'google') { ... } elseif ($provider === 'vk') { ... }` — нерасширяемо.

**Реализация №1 — Auth Strategies:**

`AuthStrategyInterface` (`getProvider()`, `login()`, `authenticate()`) реализуют `GoogleAuthStrategy`, `VkAuthStrategy`, `GithubAuthStrategy`. `AuthStrategyRegistry` выдаёт нужную стратегию по `AuthProvider` enum. `AuthController` не знает о конкретных провайдерах.

**Реализация №2 — Template Resolver:**

`TemplateResolver::resolveId(\WP_Post)` реализует приоритетную цепочку: привязка в БД → мета-поле поста → значение по умолчанию. `MetaBoxController` использует результат для выбора объекта `BaseTemplate`, не зная о деталях выбора.

---

### Builder

**Что это:** Пошаговая сборка сложного объекта через серию методов.

**Проблема:** Сборка конфигураций меню и данных страниц задания слишком сложна для одного места.

**Реализация №1 — `SubjectsMenuBuilder`:**

Реализует `MenuBuilderInterface`. Читает предметы из `SubjectRepository` (кэширует в `$this->subjects`), строит конфигурационные массивы для `MenuRegistrar`. `AdminController` не знает о структуре данных предметов:

```php
$this->menu_registrar
    ->addPages($this->buildMainPages())
    ->addSubPages($this->buildAllSubPages())  // включает buildSubPages() билдера
    ->register();
```

**Реализация №2 — `TaskDataBuilder`:**

Собирает полный массив данных для frontend-шаблона задания: `post`, `subject`, `content`, `files`, `tags`, `articles`, `navigation`. Каждый раздел — отдельный приватный метод. `TaskPageController` вызывает только `getTaskData(int $post_id)`.

---

### Registry

**Что это:** Централизованное хранилище объектов, доступное по ключу.

**Проблема:** Нужно находить стратегию или шаблон по идентификатору без перебора условий.

**Реализация №1 — `AuthStrategyRegistry`:**

Хранит `array<string, AuthStrategyInterface>`, где ключ — `AuthProvider->value`. Инициализируется через DI (все три стратегии инжектируются в конструктор). Метод `get(?AuthProvider): ?AuthStrategyInterface`.

**Реализация №2 — `TemplateRegistry`:**

Инициализируется через `TaskTemplate::cases()` (enum как источник списка классов), создаёт объекты через `new $class()`. Расширяется через `apply_filters('fs_lms_register_templates', $builtin)`. Метод `get(string $template_id): ?BaseTemplate` с fallback на стандартный шаблон.

---

### Observer (WordPress Hooks)

**Что это:** Объекты подписываются на события и получают уведомления при их наступлении.

**Проблема:** Слабая связанность между компонентами — `ContentCacheService` не должен вызываться напрямую из кода сохранения постов.

**Реализация:** WordPress `add_action` / `add_filter` — это реализация паттерна Observer. Все подписки регистрируются в `register()` контроллеров:

```php
// SubjectController::register()
add_action('save_post', [$this->cache_service, 'clearRecentContentCache'], 10, 2);
add_action('delete_post', [$this->cache_service, 'clearCacheOnDelete']);
add_action('admin_notices', [$this->page_callbacks, 'showRequiredTaxNotice']);
```

`AjaxHook` enum инкапсулирует генерацию имён хуков, устраняя ручные строки:
```php
// AjaxHook::SaveStudentGroup->action() → 'wp_ajax_save_student_group'
// AjaxHook::SaveStudentGroup->callbackMethod() → 'ajaxSaveStudentGroup'
add_action($hook->action(), [$callback, $hook->callbackMethod()]);
```

---

### Singleton (Lazy, через Container)

**Что это:** Один экземпляр класса на всё время жизни приложения.

**Проблема:** Репозитории и сервисы должны создаваться один раз, независимо от количества мест использования.

**Реализация:** `Container::$instances` — кэш созданных объектов. При повторном `get($class)` возвращается кэшированный экземпляр. Это не классический Singleton (нет статического метода `::getInstance()`), а Lazy Singleton, управляемый контейнером — тестируемый вариант.

---

### Factory Method (статические фабрики в DTO)

**Что это:** Фабричный метод для создания объекта из внешних данных.

**Проблема:** Десериализация сырых массивов из `wp_options` в типизированные объекты должна быть централизована.

**Реализация:** Каждый DTO имеет `static function fromArray(array $data): self` с защитными значениями по умолчанию:

```php
public static function fromArray(array $data): self {
    return new self(
        id:         (string) ($data['id']         ?? ''),
        title:      (string) ($data['title']       ?? ''),
        period_id:  (string) ($data['period_id']   ?? ''),
        subject_id: (string) ($data['subject_id']  ?? ''),
        teacher_id: (int)    ($data['teacher_id']  ?? 0),
    );
}
```

Вызывается в репозиториях при чтении — вызывающий код всегда получает типизированный объект.

---

### Enum как типизированные константы с поведением

**Что это:** PHP 8.1 backed enum с методами — не просто набор значений, а объекты с поведением.

**Проблема:** Строковые константы (`'fs_lms_manager_nonce'`, `'wp_ajax_save_student_group'`) разбросаны по коду — легко ошибиться, нет IDE-подсказок.

**Реализации:**

`AjaxHook` — генерирует все связанные имена из одного PascalCase значения:
```php
case SaveStudentGroup = 'SaveStudentGroup';
// ->action()          → 'wp_ajax_save_student_group'
// ->noPrivAction()    → 'wp_ajax_nopriv_save_student_group'
// ->jsAction()        → 'save_student_group'
// ->callbackMethod()  → 'ajaxSaveStudentGroup'
// ::toJsArray()       → ['saveStudentGroup' => 'save_student_group', ...]
```

`Nonce` — инкапсулирует создание и верификацию:
```php
Nonce::Manager->create()            // wp_create_nonce('fs_lms_manager_nonce')
Nonce::Manager->verify('security')  // check_ajax_referer('fs_lms_manager_nonce', 'security')
```

`OptionName` — единый источник всех ключей `wp_options`. `Capability` — типизированные права. `UserRole` — роли с `->label()`. `TaskTemplate` — идентификаторы шаблонов с `->class()` и `fromDatabase()`.

---

### Traits как компонуемое поведение

**Что это:** Горизонтальное переиспользование кода без наследования.

**Проблема:** Несколько классов нуждаются в одинаковом поведении, но не могут наследоваться от одного предка.

**Трейты в проекте:**

| Трейт | Методы | Использование |
|---|---|---|
| `AjaxResponse` | `respond()`, `error()`, `success()` | Все Callbacks + BaseController |
| `Authorizer` | `authorize(Nonce, Capability)` | Все Callbacks |
| `Sanitizer` | `sanitizeText/Key/Int/Html/Bool`, `requireText/Key/Int` | Все Callbacks |
| `TemplateRenderer` | `render(string, array\|object)` | AdminCallbacks, SubjectPageCallbacks |
| `NumericSorter` | `addNumericSort(hook, field, condition)` | SubjectController |
| `SlugGenerator` | `slugify(string, string)`, `isValidSlug(string)`, `transliterate(string)` | StudentGroupService |
| `TaxonomySeeder` | заполнение начальных данных таксономий | Activate |
| `ErrorHandler` | обработка ошибок | — |

Архитектурный принцип: трейты не содержат зависимостей на конкретные классы — только на интерфейсы WP (`wp_send_json_*`, `check_ajax_referer`) или чистую логику. `Sanitizer` читает `$_POST`/`$_GET` — компромисс, оправданный контекстом Callback-классов.

---

### Facade

**Что это:** Упрощённый интерфейс над сложной подсистемой.

**Проблема:** WordPress API многословен и имеет разрозненные точки входа.

**Реализация:**

`BaseController::path()` и `url()` — фасад над `plugin_dir_path()` / `plugin_dir_url()` + нормализацией слешей. Все классы, расширяющие `BaseController`, используют `$this->path('assets/css/admin.min.css')` вместо ручной конкатенации.

`Nonce` enum — фасад над `wp_create_nonce()` и `check_ajax_referer()`. Одна строка вместо двух с запоминанием имени нонса.

`AjaxHook::toJsArray()` — фасад для `wp_localize_script`: генерирует весь массив AJAX-действий для JS из единого источника.

---

### TemplateRenderer (View-слой)

**Что это:** Загрузчик PHP-шаблонов с передачей переменных через `extract()`.

**Проблема:** Прямые `require` в коллбеках с ручной подготовкой переменных — нет единой точки загрузки, нет защиты от несуществующих файлов.

**Реализация:** `Shared\Traits\TemplateRenderer::render(string $template_name, array|object $data)` вычисляет путь через `$this->path("templates/{$template_name}.php")`, проверяет `file_exists()`, если `$data` — объект, помещает в `['data' => $object]`, иначе `extract($data)`, затем `require $file`.

```php
// AdminCallbacks::groupsPage()
$this->render('admin/groups', [
    'subjects'         => $this->subjects->readAll(),  // SubjectDTO[]
    'academic_periods' => $this->periods->readAll(),   // array[]
    'groups'           => $groups_dtos,                // StudentGroupDTO[]
    'teachers'         => $teachers,                   // WP_User[]
]);
```

Шаблон получает типизированные переменные, готовые к `foreach`.

---

## Архитектурная согласованность

### Как паттерны взаимодействуют

Типичный lifecycle AJAX-запроса:

```
JS (fs_lms_vars.ajax_actions.saveStudentGroup)
    → WordPress (wp_ajax_save_student_group)
    → AjaxController регистрировал хук через AjaxHook::SaveStudentGroup->action()
    → StudentGroupCallbacks::ajaxSaveStudentGroup()
        → Authorizer::authorize(Nonce::Manager)
        → Sanitizer::requireText('title'), requireKey('period_id'), ...
        → StudentGroupService::createGroup(title, period_id, subject_id, teacher_id)
            → SlugGenerator::slugify(title, 'group')
            → StudentGroupRepository::getById() (проверка коллизий)
            → StudentGroupRepository::save(StudentGroupDTO)
        → AjaxResponse::respond(result, error_msg, success_msg)
```

Каждый слой знает только о соседнем. Трейты (`Authorizer`, `Sanitizer`, `AjaxResponse`) компонуются в Callback-слое без наследования.

---

### Отклонения от архитектуры

**`AdminCallbacks` растёт** с каждой новой страницей (сейчас: dashboard, settings, groups, userlist, boilerplate). При добавлении следующих страниц стоит разбить на отдельные Callback-классы по доменам.

**`AjaxHook` enum** генерирует имена хуков и имена методов автоматически из PascalCase значения. Это неочевидно для новых участников: добавление нового `case` в enum автоматически означает, что метод `ajax{CaseName}()` должен существовать в нужном Callback-классе. Несоответствие имён приведёт к PHP-ошибке только в момент вызова.

**Трейты `Sanitizer` и `Authorizer`** читают из суперглобальных (`$_POST`, `$_GET`) — это допустимо только в Callback-слое. Использование этих трейтов в других контекстах нарушает архитектуру.

**`SubjectController`** — самый тяжёлый контроллер (14 зависимостей в конструкторе). Это оправдано: он оркестрирует регистрацию CPT, таксономий, числовой сортировки, кэша и восьми групп AJAX-хуков для всей предметной области. Добавлять новые обязанности сюда не стоит.

**`TemplateRenderer::render()`** принимает `array|object`, но шаблоны ожидают конкретные переменные. Соответствие нигде не проверяется статически — только `@var` docblock в шаблоне. Это приемлемый компромисс для WordPress-архитектуры.
