# FS LMS — документация разработчика

> Единый вводный документ для разработчика. Читается сверху вниз:
> от запуска окружения и архитектуры ядра — через данные, админку и публичную часть —
> к системе обучения, SPA-конструктору курса и SPA личного кабинета `/profile/`.
>
> Состояние: ветка `stage_11`, включая незакоммиченную работу (preview-плеер, посещаемость,
> замены, кабинеты, индивидуальные занятия). Детальные спеки соседних подсистем — в конце,
> раздел «Карта остальной документации».

---

## Оглавление

**Часть I. Обзор и старт**
1. [Что такое FS LMS и из чего он состоит](#1-что-такое-fs-lms-и-из-чего-он-состоит)
2. [Окружение разработчика и сборка](#2-окружение-разработчика-и-сборка)

**Часть II. Ядро**
3. [Жизненный цикл плагина: fs-lms.php → Init → DI](#3-жизненный-цикл-плагина-fs-lmsphp--init--di)
4. [DI-контейнер](#4-di-контейнер)
5. [Слои и раскладка по папкам](#5-слои-и-раскладка-по-папкам)
6. [Строительные блоки: Contracts, Enums, DTO, Трейты](#6-строительные-блоки-contracts-enums-dto-трейты)
7. [Контроллеры, Callbacks и AJAX-паттерн](#7-контроллеры-callbacks-и-ajax-паттерн)

**Часть III. Данные**
8. [Три хранилища данных](#8-три-хранилища-данных)
9. [Репозитории](#9-репозитории)
10. [Собственные таблицы: полный список](#10-собственные-таблицы-полный-список)
11. [Миграции](#11-миграции)
12. [Менеджеры и Регистраторы](#12-менеджеры-и-регистраторы)

**Часть IV. Админка**
13. [Админ-меню и страницы](#13-админ-меню-и-страницы)
14. [Модальные окна](#14-модальные-окна)
15. [JS-архитектура: бандлы и правила](#15-js-архитектура-бандлы-и-правила)
16. [Уведомления в админке](#16-уведомления-в-админке)
17. [Клиентская валидация форм](#17-клиентская-валидация-форм)
18. [Метабоксы и поля заданий](#18-метабоксы-и-поля-заданий)

**Часть V. Публичная часть и безопасность**
19. [Публичные страницы и маршруты](#19-публичные-страницы-и-маршруты)
20. [Роли и права](#20-роли-и-права)
21. [Бот-защита публичных форм](#21-бот-защита-публичных-форм)

**Часть VI. Сервисные подсистемы**
22. [Зачисление и PII (обзор)](#22-зачисление-и-pii-обзор)
23. [Логирование и аудит](#23-логирование-и-аудит)
24. [Email-шаблоны](#24-email-шаблоны)
25. [Конфигурация плагина](#25-конфигурация-плагина)
26. [Опциональные модули](#26-опциональные-модули)

**Часть VII. Система обучения**
27. [Модель: контент vs факты](#27-модель-контент-vs-факты)
28. [Банки контента и CPT](#28-банки-контента-и-cpt)
29. [Курс: модули, уроки, шаги](#29-курс-модули-уроки-шаги)
30. [Конструктор курса — admin-SPA](#30-конструктор-курса--admin-spa)
31. [Доставка: программа группы и доступ](#31-доставка-программа-группы-и-доступ)
32. [Плеер урока и прогресс](#32-плеер-урока-и-прогресс)
33. [Типы задач и автопроверка](#33-типы-задач-и-автопроверка)
34. [Работы и контрольные](#34-работы-и-контрольные)
35. [Расписание, КТП, посещаемость, замены, кабинеты](#35-расписание-ктп-посещаемость-замены-кабинеты)

**Часть VIII. Личный кабинет /profile/ — SPA**
36. [Архитектура кабинета](#36-архитектура-кабинета)
37. [Экраны кабинета](#37-экраны-кабинета)
38. [Сетевой шов FS_LMS_API](#38-сетевой-шов-fs_lms_api)
39. [Как добавить новый экран кабинета](#39-как-добавить-новый-экран-кабинета)
40. [Перенос кабинета в Telegram / мобильное приложение](#40-перенос-кабинета-в-telegram--мобильное-приложение)

**Часть IX. Практика**
41. [Troubleshooting](#41-troubleshooting)
42. [Карта остальной документации](#42-карта-остальной-документации)

---

# Часть I. Обзор и старт

## 1. Что такое FS LMS и из чего он состоит

FS LMS — WordPress-плагин, реализующий полноценную LMS онлайн-школы. Он покрывает весь путь:

```
Заявка (/apply/, родитель /lms/join/…) → зачисление в группу → банки контента по предметам
→ конструктор курса (модули → уроки → шаги) → доставка группе по расписанию (КТП)
→ плеер ученика, сдачи, контрольные/ЕГЭ → журнал, посещаемость, замены
→ личные кабинеты (/profile/): преподаватель, офис, ученик, родитель
```

**Технологический стек:**

- PHP 8+ — `declare(strict_types=1)` в каждом файле, типизированные параметры/возвраты, только ООП;
  активно используются backed-enum и `readonly`-классы.
- Composer PSR-4: namespace `Inc\` → каталог `inc/`. Точка входа — `fs-lms.php`.
- Собственный DI-контейнер с autowiring (`Inc\Core\Container`).
- JS — ES6-модули, сборка Gulp + Webpack + Babel. Пять независимых бандлов (см. §2 и §15).
  Админка — jQuery, публичная часть — чистый JS.
- SCSS — пять бандлов, дизайн-токены в `_variables.scss` каждого бандла.
- Данные — три хранилища: `wp_options`, CPT + `post_meta`, собственные таблицы (24 шт., см. §10).
- Локальная разработка — Docker (см. §2).

**Крупные подсистемы плагина:**

| Подсистема | Что делает | Ключевые разделы |
|---|---|---|
| Ядро | DI, регистрация сервисов, ассеты, активация | §3–7 |
| Предметы и банки контента | Динамические CPT на предмет: задания, статьи, работы, уроки, курсы, контрольные | §28 |
| Зачисление | Заявки, PII c шифрованием, согласия, пароли, зачисление в группы | §22 |
| Обучение | Программа группы, плеер, сдачи, автопроверка, журнал, КТП | §27–35 |
| Личные кабинеты | SPA `/profile/` (препод/офис/ученик/родитель) + плеер урока | §36–40 |
| Служебные | Логи и аудит (9 каналов), email-шаблоны, конфигурация, CSV-экспорт | §23–25 |
| Опциональные модули | SocialAuth, AdSync, EgeComputer, DaData, SmartCaptcha | §26 |

Термины, которые встречаются постоянно:

- **Предмет (subject)** — единица каталога («информатика», «математика»); под каждый предмет
  динамически регистрируются свои CPT (`inf_tasks`, `inf_lessons`, …).
- **Банк контента** — CPT-хранилище многоразовых материалов (задания/работы/уроки/курсы/контрольные).
- **Группа** — учебная группа (таблица `fs_lms_groups`); ей назначается курс и расписание.
- **Занятие** — строка `fs_lms_group_lessons`: датированный слот программы группы
  (обычное, индивидуальное).
- **Person** — человек (ученик/родитель) в таблице `fs_lms_persons`; его PII зашифрованы
  в `fs_lms_person_documents`.

## 2. Окружение разработчика и сборка

### Docker

Плагин работает внутри Docker; каталог плагина смонтирован как volume — PHP-правки применяются
сразу, но **OPcache может держать старый байткод**.

```bash
# сервисы: wp_app (WordPress, :8080), wp_db (MariaDB), wp_phpmyadmin (:8081)
docker restart wp_app     # после PHP-изменений, если поведение «не изменилось»

# прямой запрос к БД:
docker exec wp_db mariadb -u root -proot wordpress -e "SELECT ..."

# WP-CLI — отдельный сервис wpcli (профиль cli), из каталога со стеком:
docker compose run --rm wpcli wp plugin list
```

### Сборка фронтенда

```bash
npx gulp build            # всё: 5 JS-бандлов + 5 CSS-бандлов
npx gulp watch            # вотчер (JS + admin/frontend/profile/player SCSS)
npx gulp scripts          # только JS (все бандлы одним прогоном webpack)
npx gulp styles:admin     # и аналогично styles:frontend / styles:common / styles:profile / styles:player
npx gulp styles:check     # строгая проверка компиляции всех SCSS (для CI, падает с exit != 0)

npm run lint:js           # ESLint
npm run fix:js            # ESLint --fix
```

Бандлы (вход → выход):

| Бандл | JS | CSS | Где подключается |
|---|---|---|---|
| `admin` | `src/js/admin/admin.js` → `assets/js/admin.min.js` | `src/scss/admin/admin.scss` → `assets/css/admin.min.css` | страницы плагина в wp-admin |
| `frontend` | `src/js/frontend/frontend.js` → `frontend.min.js` | `src/scss/frontend/frontend.scss` → `frontend.min.css` | публичные страницы (кроме кабинета и плеера) |
| `common` | `src/js/common/common.js` → `common.min.js` | `src/scss/common/common.scss` → `common.min.css` | и админка, и публичная часть |
| `profile` | `src/js/profile/profile.js` → `profile.min.js` | `src/scss/profile/profile.scss` → `profile.min.css` | только `/profile/` (изолирован) |
| `player` | `src/js/player/player.js` → `player.min.js` | `src/scss/player/player.scss` → `player.min.css` | только плеер урока / preview (изолирован) |

Какой бандл на какой странице грузится — решает единственный класс `Inc\Core\Enqueue` (§15).

### Обязательные константы wp-config.php

Без них плагин не стартует полностью:

| Константа | Назначение |
|---|---|
| `FS_LMS_ENC_KEY` | Ключ шифрования PII — base64, 32 байта (libsodium) |
| `FS_LMS_HASH_SALT` | Соль SHA-256 для поиска по документам/email без расшифровки |

```bash
php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"   # генерация ключа
```

**Keyless-режим.** Если ключей нет или они битые (`PiiCryptoService::isAvailable() === false`),
`fs-lms.php` поднимает *минимальный* бутстрап: только `Enqueue` + `AdminController` +
`ConfigController` + admin-notice. Администратор может зайти в таб «Конфигурация»,
сгенерировать ключи кнопкой и вставить их в `wp-config.php` — не активируя остальной плагин.

### Опциональные константы

Все опциональные значения имеют «двойника» в БД (таб «Конфигурация», §25) — **константа
из `wp-config.php` всегда приоритетнее** значения из БД.

| Константа | Назначение |
|---|---|
| `FS_LMS_TEST_ENV` | Тестовое окружение: OTP-письмо не шлётся, капча/honeypot/rate-limit пропускаются, открывается дебаг-маршрут `/lms/join/000` |
| `FS_LMS_OTP_BYPASS_CODE` | Постоянный bypass-код OTP (работает и без TEST_ENV) |
| `DADATA_API_TOKEN` | Токен DaData (модуль DaData) |
| `FS_LMS_CAPTCHA_SITE_KEY` / `FS_LMS_CAPTCHA_SERVER_KEY` | Ключи Yandex SmartCaptcha (модуль SmartCaptcha) |
| `FS_LMS_SOCIAL_AUTH` | Жёсткое вкл/выкл модуля SocialAuth (не задана → включён) |
| `FS_LMS_AD_SYNC`, `FS_LMS_AD_HMAC_SECRET` | Модуль AdSync + секрет HMAC для REST |
| `FS_LMS_EGE_COMPUTER` | Модуль EgeComputer (не задана → включён) |
| `FS_LMS_DADATA`, `FS_LMS_SMART_CAPTCHA` | Жёсткое вкл/выкл соответствующих модулей |

### Отладка

- Лог плагина — `wp-content/debug.log` (при `WP_DEBUG = true`); все записи грепаются по `[FS LMS]`.
  Читать только хвост: `docker exec wp_app tail -15 /var/www/html/wp-content/debug.log`.
- В коде — только `Inc\Shared\PluginLogger` (§23), никогда `error_log()` напрямую.

---

# Часть II. Ядро

## 3. Жизненный цикл плагина: fs-lms.php → Init → DI

### fs-lms.php

```
fs-lms.php
 ├─ guard: if (!defined('ABSPATH')) exit;
 ├─ define('FS_LMS_PATH', plugin_dir_path(__FILE__))      ← единственная константа пути
 ├─ require vendor/autoload.php                            ← Composer PSR-4 (Inc\ → inc/)
 ├─ register_activation_hook   → Inc\Core\Activate::activate
 ├─ register_deactivation_hook → Inc\Core\Deactivate::deactivate
 └─ PiiCryptoService::isAvailable() ?
      ├─ нет → admin-notice + минимальный бутстрап (Enqueue, AdminController, ConfigController)
      └─ да  → Inc\Init::run()
```

### Activate / Deactivate

`Activate::activate()` (выполняется при активации плагина, идемпотентно):

1. создаёт LMS-роли — `RoleManager::registerAll()`;
2. планирует cron-задачи (`ExpireApplications`, `RetentionCleanup` — daily; `RecoveryTick` — каждые 15 минут);
3. запускает миграции — `MigrationRunner` (§11);
4. генерирует служебные страницы через `PageGeneratorService`: SignIn, Apply, UserProfile,
   GroupCockpit, CoursePreview (§19);
5. создаёт дефолтное согласие `pd_processing` (§22);
6. `flush_rewrite_rules()`.

`Deactivate` симметрично снимает роли и cron и сбрасывает rewrite-правила.

### Init::run()

`Inc\Init` — Service Registry: единственное место, где перечислено всё, что регистрируется.

```php
public static function run(): void {
    $container = new Container();
    $container->bind( ClockInterface::class, WpClock::class );
    $container->bind( LogEventDispatcherInterface::class, LogEventDispatcher::class );

    foreach ( self::getServices() as $class ) {
        $service = $container->get( $class );
        if ( $service instanceof ServiceInterface ) {
            $service->register();
        }
    }

    // синхронизация capabilities: выполняется один раз при смене версии
    if ( get_option( 'fs_lms_caps_version' ) !== '5.1' ) {
        $container->get( RoleManager::class )->registerAll();
        update_option( 'fs_lms_caps_version', '5.1' );
    }
}
```

Каждый элемент `getServices()` — класс, реализующий `ServiceInterface` (один метод
`register(): void`, внутри — только регистрация WP-хуков). **Добавить новый сервис в систему =
добавить его класс в этот массив.** Контейнер сам создаст все зависимости конструктора.

Состав `getServices()` (сгруппировано как в файле; в скобках — за что отвечает):

```
— Ядро и админка —
Enqueue, AdminController, ModulesDashboardController

— Предметы и банки контента —
SubjectController (предметы, CPT, таксономии), MetaBoxController (метабоксы заданий),
LearningMenuController (меню «Обучение»), LessonMetaBoxController, LessonController,
WorkMetaBoxController, WorkController, CourseController, CourseBuilderController,
CourseMetaBoxController, AssessmentMetaBoxController, ProblemsController (глобальный банк задач),
ContentDeletionGuard (гейт удаления банков), TaskCreationController,
TaskPageController, AssessmentPageController, BoilerplateController

— Пользователи, зачисление, настройки —
UserController, ApplyPageController, ProfileController, StudentGroupController (легаси),
CronController, ConsentController, ApplicationController, EnrollmentController, PiiController,
RecoveryController, ExpulsionController, DeletionController, ImportController,
ConfigController, SettingsController, LogsController

— Подписчики лог-каналов —
AuthLogController, EntityAuditSubscriber, PostEntityAuditController, EnrollmentAuditSubscriber,
PiiAccessSubscriber, DataChangeSubscriber, ConsentChangeSubscriber, EmailSubscriber,
DeletionSubscriber, ExportServiceBootstrap

— Этап 2: программа группы и кабинеты —
ScheduleController (AJAX программы/КТП), SubstitutionController (замены),
RoomController (кабинеты), ProfileDashboardController («Главная» кабинета),
LearnerProfileController (кабинет ученика/родителя), JournalController (журнал/посещаемость),
LessonPlayerController (плеер урока), CoursePreviewController (preview-плеер),
GroupCockpitController (/group/), LessonProgressController (прогресс шагов),
LearningEventSubscriber (лента событий)

— Этап 3: сдачи и контрольные —
SubmissionController, AssessmentController

— Опциональные модули (вырезаются удалением каталога + этой строки) —
SocialAuthModule, AdSyncModule, EgeComputerModule, DaDataModule, SmartCaptchaModule
```

> Важно: версий у плагина две, и они независимы. `fs_lms_schema_version` — версия схемы БД
> (миграции, §11), `fs_lms_caps_version` — версия матрицы прав (константа в `Init::run()`,
> поднимаешь её при изменении `UserRole::capabilities()`).

## 4. DI-контейнер

**Файл:** `inc/Core/Container.php`. Паттерны: Service Locator + Lazy Singleton + autowiring.

Как работает `get(MyClass::class)`:

1. если объект уже создавался — вернуть из кэша (`$instances`);
2. `ReflectionClass` анализирует конструктор;
3. каждый параметр с классовым type-hint рекурсивно создаётся тем же `get()`;
4. объект создаётся, кэшируется, возвращается.

`bind(Abstract::class, Concrete::class)` — регистрирует реализацию интерфейса. Сейчас
биндингов два (в `Init::run()`): `ClockInterface → WpClock` и
`LogEventDispatcherInterface → LogEventDispatcher`. Если сервису нужно время — внедряй
`ClockInterface`, а не вызывай `current_time()`: так логика дедлайнов тестируема.

Ограничения (важно при написании конструкторов):

- встроенные типы (`string`, `int`, `bool`, `array`) — только со значением по умолчанию;
- union-типы и `mixed` не разрешаются;
- все зависимости должны быть классами/интерфейсами с именованным типом.

```php
// Правильно: всё создастся автоматически
public function __construct(
    private readonly GroupLessonRepository $groupLessons,
    private readonly LessonAccessPolicy $access,
    private readonly ClockInterface $clock,
) {}
```

## 5. Слои и раскладка по папкам

### Слои

```
inc/
├── Core/          # ядро: Container, Activate, Deactivate, Enqueue, BaseController
├── Contracts/     # интерфейсы (12 шт., §6)
├── Controllers/   # регистрируют WP-хуки, оркестрируют остальные слои. Логики не содержат
├── Callbacks/     # обработчики AJAX-запросов (только они)
├── Services/      # бизнес-логика; stateless; о WP-хуках не знают
├── Repositories/  # доступ к данным: OptionsRepositories (wp_options) и WPDBRepositories (таблицы)
├── Managers/      # обёртки над WP API (посты, термины, роли, cron, media…)
├── Registrars/    # фасады-накопители конфигураций перед регистрацией (меню, CPT, метабоксы)
├── DTO/           # readonly-объекты переноса данных между слоями
├── Enums/         # типобезопасные константы (хуки, опции, таблицы, статусы…)
├── MetaBoxes/     # поля (Fields/) и шаблоны (Templates/) метабоксов заданий
├── Migrations/    # MigrationRunner + Migration_1_0_0
├── Modules/       # опциональные отключаемые модули (§26)
└── Shared/        # PluginLogger + Traits/ (11 трейтов)
```

Направление зависимостей — сверху вниз: `Controller → Callbacks → Service →
Repository/Manager → DTO/Enum`. Правила, которые **нельзя** нарушать:

- Контроллер не содержит бизнес-логики и прямых вызовов WP API.
- `WP_Query`, `get_posts`, `update_option`, `update_post_meta` напрямую — запрещены;
  только через Repositories/Managers.
- Сервисы не вызывают `add_action`/`add_filter` — это дело контроллеров.
- Новые слои не изобретаем.

### Словарь доменных папок (единый по слоям)

Слои `Controllers/`, `Callbacks/`, `Services/`, `Managers/`, `DTO/`, `Enums/` разложены по
**подпапкам-доменам**, и имя папки означает одно и то же во всех слоях. Файл кладётся по тому,
*о чём* он (домен), а не по тому, *что* он (тип). Заводишь класс — найди домен в таблице
и положи в одноимённую папку своего слоя.

| Папка | Назначение | Встречается в слоях |
|---|---|---|
| `Subject` | Предметы и их банки: CPT, таксономии, кэш, импорт/экспорт предмета | Controllers, Callbacks, Services, Managers, DTO, Enums |
| `Task` | Задания: создание, шаблоны, бойлерплейты, чекеры | Controllers, Callbacks, Services, DTO (+ enum'ы в `Enums/Subject`) |
| `Course` | Курсы/уроки/работы/шаги, конструктор, доставка, плеер, прогресс, сдачи, журнал, кабинеты | Controllers, Callbacks, Services, Managers, DTO, Enums |
| `Assessment` | Контрольные/экзамены: попытки, автопроверка, результаты, ЕГЭ-механики | Controllers, Callbacks, Services, Managers, DTO, Enums |
| `Problems` | Глобальный (без предмета) банк задач | Controllers |
| `Group` | Учебные группы: расписание, журнал, замены, кокпит | Controllers, Callbacks, Services |
| `Profile` | Личный кабинет: витрины, дашборд, кабинет ученика | Controllers, Callbacks, Services, DTO |
| `Enrollment` | Жизненный цикл заявки: подача, зачисление, отчисление, восстановление | Controllers, Callbacks, Services, DTO, Enums |
| `Application` | Логика самой заявки (детализация `Enrollment`) | Services, DTO |
| `Person` | Люди: PII, согласия, пользователи, представители | Controllers, Callbacks, Services, Managers, DTO, Enums |
| `Deletion` | Каскадные удаления и их обработчики | Controllers, Callbacks, Services |
| `Import` / `Export` | CSV-импорт учеников / CSV-экспорт (в т.ч. логов) | Controllers, Callbacks, Services, DTO, Enums |
| `Log` | Логирование и аудит: шина, каналы, писатели | Controllers, Callbacks, Services, DTO, Enums |
| `Email` | Письма и шаблоны | Services, DTO, Enums |
| `Settings` | Конфигурация плагина | Controllers, Callbacks, DTO, Enums |
| `Auth` | Аутентификация (enum'ы; сам OAuth — в модуле SocialAuth) | Enums |

Служебные папки, специфичные для слоя:

| Папка | Слой | Что внутри |
|---|---|---|
| `System` | Controllers, Callbacks, Services | WP-инфраструктура: `AdminController` (меню), `AjaxController` (база AJAX), `CronController`, `ModulesDashboardController`, `PageGeneratorService` |
| `Pages` | Controllers | Контроллеры публичных страниц: `ApplyPageController`, `TaskPageController`, `AssessmentPageController`, `BoilerplatePageController` |
| `Subscribers` | Controllers | 10 подписчиков лог-каналов (§23) |
| `Builders` | Controllers | Сборщики конфигов меню |
| `Wp` | Managers, Enums | Обёртки WP API (`PostManager`, `TermManager`, `CPTManager`, `MetaBoxManager`, `MenuManager`, `CronManager`, `MediaManager`, `TaxonomyManager`) и enum'ы WP-регистраций (`AjaxHook`, `Nonce`, `PageRoutes`, `ShortCode`, `Menu`, `CronHook`, `PostMetaName`, `MetaKeys`) |
| `Access` | Enums | `Capability`, `UserRole`, `AccessLevel` |
| `Security` | Services | `PiiCryptoService`, `PasswordGeneratorService`, `RateLimitService`, `FormGuardService` |
| `Captcha` / `CaptchaProviders` | Services | Фасад капчи + `NullCaptchaProvider` (реальный провайдер — модуль SmartCaptcha) |
| `Shared` | Services | Stateless-утилиты: `WpClock`, `ThemeCompatService`, `PluginConfig` |
| `Template` | Services | `TemplateRegistry` + `TemplateResolver` (шаблоны метабоксов) |

Замечания:

- Папка может отсутствовать в слое, если классов этого домена там нет — имя всё равно
  закреплено за назначением.
- `Application ⊂ Enrollment` и `Task ⊂ Subject` по смыслу; вынесены отдельно там, где классов много.
- Модель занятия `GroupLessonDTO` живёт в `DTO/Course` (её использует вся логика доступа
  и доставки), хотя контроллер расписания — в `Controllers/Group`.

## 6. Строительные блоки: Contracts, Enums, DTO, Трейты

### Contracts (inc/Contracts/, 12 интерфейсов)

| Интерфейс | Контракт | Кто реализует |
|---|---|---|
| `ServiceInterface` | `register(): void` | всё, что перечислено в `Init::getServices()` |
| `MigrationInterface` | `up()`, `down()`, `version()` | `Migration_1_0_0` |
| `ClockInterface` | `now()` | `WpClock` (биндинг в `Init`) |
| `LogEventDispatcherInterface` | `subscribe()`, `dispatch()` | `LogEventDispatcher` (биндинг в `Init`) |
| `LogEventInterface` | маркер payload лог-события | event-DTO в `DTO/Log/Events/` |
| `CaptchaProviderInterface` | `validate()`, `getSiteKey()`, `isConfigured()` | `NullCaptchaProvider`, Yandex-провайдер модуля SmartCaptcha |
| `TaskCheckerInterface` | `check(content, answer): CheckResultDTO` | автопроверщики задач (§33) |
| `GradeSourceInterface` | `entriesForGroup()`, `entriesForStudent()` | источники оценок журнала (§34) |
| `FieldInterface` | `render()`, `sanitize()` | поля метабоксов (§18) |
| `CsvExportProviderInterface` | `columns()`, `rows()`, `filename()` | провайдеры CSV-экспорта |
| `ProfileViewInterface` | `build(ProfileContext): array` | витрины кабинета (§36) |
| `EmailTemplateInterface` | `get(type, vars): EmailTemplateData` | стратегии писем (§24) |

### Enums (inc/Enums/)

Все «магические строки» плагина — это backed-enum. Раскладка по подпапкам:
`Access`, `Assessment`, `Auth`, `Course`, `Email`, `Enrollment`, `Export`, `Import`, `Log`,
`Person`, `Settings`, `Subject`, `Ui`, `Wp`.

Ключевые enum'ы, с которыми работаешь каждый день:

| Enum | Где | Что содержит |
|---|---|---|
| `AjaxHook` | `Wp` | **189 кейсов** — все AJAX-действия; генерирует имена хуков/методов (§7) |
| `Nonce` | `Wp` | **54 кейса**; `create()` и `verify()` |
| `PageRoutes` | `Wp` | slug'и публичных страниц + `url()`, `isCurrent()` (§19) |
| `ShortCode` | `Wp` | шорткоды; `tag()` возвращает `[код]` |
| `OptionName` | `Settings` | **все** ключи `wp_options` плагина (15 кейсов) — единственный источник правды |
| `TableName` | `Settings` | **все** собственные таблицы (24 кейса); `prefixed()` добавляет `$wpdb->prefix` |
| `Capability` | `Access` | 13 прав (§20) |
| `UserRole` | `Access` | 8 ролей + матрица прав `capabilities()` (§20) |
| `StepType`, `WorkType`, `LessonVisibility`, `GateState`, `ProgressStatus`, `SubmissionStatus` | `Course` | модель обучения (§29–34) |
| `AssessmentKind`, `AttemptStatus`, `ScoringPolicy` | `Assessment` | контрольные (§34) |
| `TaskTemplate`, `TemplateCategory` | `Subject` | типы заданий (§33) |
| `ApplicationStatus`, `EnrollmentStatus`, `ExpulsionReasons` | `Enrollment` | зачисление (§22) |
| `LogChannel`, `LogEvent`, `AuditAction` | `Log` | логирование (§23) |
| `EmailTemplateType` | `Email` | типы писем (§24) |
| `CronHook` | `Wp` | cron-задачи |
| `Icon` | `Ui` | SVG-иконки PHP-шаблонов; `svg( $size )` — готовая разметка (JS-зеркало — `src/js/common/icons.js`, §15) |

Правило: **никогда не пиши строковый ключ там, где есть enum** — ни имя опции, ни таблицы,
ни AJAX-экшена, ни мета-ключа (`PostMetaName`).

### DTO (inc/DTO/)

`readonly`-классы для типобезопасного переноса данных между слоями. Принципы:

- фабрики `fromArray()` / `fromRow()` и сериализация `toArray()` / `toList()`;
- никакой бизнес-логики (максимум — производные геттеры вроде `LessonDTO::workIds()`);
- репозиторий наружу отдаёт DTO, а не сырые массивы.

Раскладка повторяет доменные папки: `DTO/Course` (17 классов: `CourseDTO`, `LessonDTO`,
`StepDTO`, `ModuleDTO`, `GroupLessonDTO`, `SubmissionDTO`, `AttendanceDTO`, `RoomDTO`,
`SubstitutionDTO`, …), `DTO/Person`, `DTO/Enrollment`, `DTO/Task`, `DTO/Assessment`,
`DTO/Log` (пары `*DTO`/`*InputDTO` каждого канала) и т.д.

Конвенция: `XxxDTO` — то, что читаем из хранилища; `XxxInputDTO` — то, что пишем.

### Трейты (inc/Shared/Traits/, 11 шт.)

| Трейт | Назначение |
|---|---|
| `AjaxResponse` | `$this->success($data)` / `$this->error($msg)` / `respond()` — обёртки `wp_send_json_*` с логированием в WP_DEBUG. Подмешан в `BaseController` — в Callbacks не редекларируй |
| `Authorizer` | `$this->authorize(Nonce::X, Capability::Y)` — nonce + право одним вызовом, JSON 403 при провале. Обязателен в admin-AJAX callbacks |
| `Sanitizer` | Безопасное чтение `$_POST`/`$_GET`: `sanitizeText/Key/Int/Bool/Html/EditorContent`, строгие `requireText/Int/Key` (бросают при пустом). **Аргумент — имя ключа**, не значение! |
| `TemplateRenderer` | `$this->render('admin/page.php', $dataOrDTO)` — подключает шаблон из `templates/`, распаковывает данные |
| `ErrorHandler` | `sendError()` — сам выбирает `wp_send_json_error` (AJAX) или `wp_die` (HTTP). Только для контроллеров с обоими типами запросов |
| `TransactionRunner` | `$this->inTransaction(fn() => …)` — транзакция `$wpdb` c ROLLBACK при исключении |
| `RequestContextProvider` | Собирает `RequestContextDTO` (IP/UA/actor) для аудита |
| `NumericSorter` | Числовая сортировка терминов таксономий |
| `SlugGenerator` | Генерация WP-совместимых слагов |
| `TaxonomySeeder` | Сидирование таксономий терминами |
| `TidiesCoreMetaBoxes` | Чистит экран банк-CPT от лишних стандартных метабоксов |

Плюс не-трейт `Inc\Shared\PluginLogger` — статическое логирование (§23).

**Правила использования:**

- в admin-AJAX callback: `authorize(Nonce::X, Capability::Y)` — никогда `check_ajax_referer()`
  или `current_user_can()` напрямую;
- в публичном (nopriv) AJAX: `Nonce::X->verify()` (у `authorize()` право обязательно);
- ввод — только методами `Sanitizer`, вывод — `esc_html`/`esc_attr` в шаблонах;
- ответы — только `$this->success()` / `$this->error()`, без `wp_send_json_*` и `echo/die`.

## 7. Контроллеры, Callbacks и AJAX-паттерн

### BaseController и AjaxController

`Inc\Core\BaseController` — **инфраструктурная** база (не доменная): даёт `$plugin_path`,
`$plugin_url`, хелперы `path()`/`url()` и трейт `AjaxResponse`. Наследование от него не
выражает никакого родства между классами.

`Inc\Controllers\System\AjaxController extends BaseController` — база контроллеров с AJAX:

| Ситуация | Базовый класс |
|---|---|
| Только WP-хуки (фильтры, шорткоды, template_include) | `extends BaseController implements ServiceInterface` |
| Есть AJAX-экшены | `extends AjaxController` (сам реализует `ServiceInterface`) |

### AjaxHook: одна enum-кейс — три имени

```php
// inc/Enums/Wp/AjaxHook.php  (значение хранится в snake_case)
case SaveAttendance = 'save_attendance';
```

| Метод | Результат | Использование |
|---|---|---|
| `->action()` | `wp_ajax_save_attendance` | регистрация хука |
| `->noPrivAction()` | `wp_ajax_nopriv_save_attendance` | публичный хук |
| `->jsAction()` | `save_attendance` | поле `action` в POST |
| `->callbackMethod()` | `ajaxSaveAttendance` | имя метода в Callbacks-классе |
| `::toJsArray()` | `['saveAttendance' => 'save_attendance', …]` | экспорт всех экшенов в JS (`fs_lms_vars.ajax_actions`) |

### Полный цикл AJAX-запроса

```
JS: $.post(ajaxurl, { action: fs_lms_vars.ajax_actions.saveAttendance,
                      security: <nonce>, ...params })
        │
WP:  хук wp_ajax_save_attendance
        │      (зарегистрирован контроллером через AjaxHook::SaveAttendance)
        ▼
Callbacks::ajaxSaveAttendance()
        ├─ $this->authorize( Nonce::X, Capability::Y )   ← или Nonce::X->verify() для nopriv
        ├─ $id = $this->requireInt( 'group_lesson_id' )   ← Sanitizer
        ├─ $result = $this->service->…( $dto )            ← вся логика в сервисе
        └─ $this->success( [...] ) / $this->error( '…' )  ← AjaxResponse
```

### Шаблон нового AJAX-контроллера

```php
declare( strict_types=1 );

namespace Inc\Controllers\Group;

use Inc\Callbacks\Group\MyCallbacks;
use Inc\Controllers\System\AjaxController;
use Inc\Enums\Wp\AjaxHook;

class MyController extends AjaxController {

    public function __construct( private readonly MyCallbacks $callbacks ) {
        parent::__construct();
    }

    public function register(): void {
        $this->registerAjaxHooks(); // финальный метод: регистрирует ajaxActions() + publicAjaxActions()
    }

    /** wp_ajax_{action} — только авторизованные */
    protected function ajaxActions(): array {
        return [
            [ AjaxHook::MyAction, $this->callbacks ],
        ];
    }

    /** wp_ajax_{action} + wp_ajax_nopriv_{action} — все */
    protected function publicAjaxActions(): array {
        return [];
    }
}
```

### Чеклист «добавить AJAX-действие»

1. **Enum:** кейс в `AjaxHook` (PascalCase-имя, snake_case-значение).
2. **Контроллер:** пара `[AjaxHook::X, $callbacks]` в `ajaxActions()` (или `publicAjaxActions()`).
   Если контроллер новый — добавить его в `Init::getServices()`.
3. **Callback:** метод `ajaxX()` в Callbacks-классе домена: `authorize` → `Sanitizer` →
   сервис → `success/error`.
4. **JS:** вызвать `fs_lms_vars.ajax_actions.x` (админка) или добавить экшен в локализацию
   нужной страницы в `Enqueue` (публичная часть, §15) / в конфиг-блок `fsProfile` (кабинет, §39).

Живой пример пары «контроллер + callbacks»: `Controllers\Course\CourseBuilderController` +
`Callbacks\Course\CourseBuilderCallbacks` (конструктор курса, §30).

> В `Callbacks/` не должно быть HTTP-«страничной» логики, в `Controllers/` — бизнес-логики.
> Если callback разросся — выноси логику в сервис домена.

---

# Часть III. Данные

## 8. Три хранилища данных

Каждый вид данных живёт в своём хранилище. Прежде чем что-то сохранять, определи класс данных:

| Хранилище | Что храним | Пример | Доступ через |
|---|---|---|---|
| `wp_options` (структурированные массивы) | Небольшие справочники и настройки | список предметов, таксономии, email-шаблоны, конфиг плагина, учебные периоды | `Repositories/OptionsRepositories/*` |
| CPT + `post_meta` | **Контент** — многоразовые материалы | задание, статья, работа, урок, курс, контрольная | `Managers/*` (PostManager, LessonManager, …) |
| Собственные таблицы | **Факты и связи** — большие объёмы, реляции, индексы | заявки, persons, зачисления, сдачи, попытки, посещаемость, логи | `Repositories/WPDBRepositories/*` |

Правила:

- Мета контента — **одним массивом** под ключом `PostMetaName::Meta` (`fs_lms_meta`);
  `post_title`/`post_content` — нативные поля. В term meta и разрозненные post meta не пишем.
- `wp_options`-данные — всегда структурированный массив; **никогда не перезаписывай опцию
  целиком**, если меняется один ключ (читай → правь ключ → сохраняй).
- Контент не копируется — на него **ссылаются по ID** (§27).

## 9. Репозитории

### OptionsRepositories (13 классов)

Работают с одной опцией `wp_options` каждая; ключ — только из `OptionName`.
`SubjectRepository`, `TaxonomyRepository`, `MetaBoxRepository`, `BoilerplateRepository`,
`AcademicPeriodRepository`, `EmailTemplatesRepository`, `ConsentDefinitionsRepository`,
`PluginConfigRepository`, `ExpulsionPolicyRepository`, `ArticleRepository`, `UserRepository`,
`StudentGroupRepository`*, `StudentPeriodMatrixRepository`*.

> \* Группы на `wp_options` (`StudentGroup*`) — **легаси, мёртвая ветка**. Реальные группы —
> таблица `fs_lms_groups` и `GroupsRepository`. Не используй wp_options-группы в новом коде.

Типовой вид:

```php
class TaxonomyRepository {
    private string $option_name = OptionName::Taxonomy->value;

    private function getRaw(): array {
        $all = get_option( $this->option_name, array() );
        return is_array( $all ) ? $all : array();
    }

    public function save( TaxonomyDataDTO $dto ): bool {
        $all = $this->getRaw();
        $all[ $dto->subject_key ][ $dto->slug ] = $dto->toArray();
        return update_option( $this->option_name, $all );
    }
}
```

### WPDBRepositories (15 классов + Log/)

Работают с собственными таблицами: `ApplicationRepository`, `PersonRepository`,
`PersonDocumentsRepository`, `StudentRecordRepository`, `ConsentRepository`, `GroupsRepository`,
`GroupLessonRepository`, `SubmissionRepository`, `AssessmentAttemptRepository`,
`AssessmentAnswerRepository`, `LessonProgressRepository`, `TaskAttemptRepository`,
`AttendanceRepository`, `SubstitutionRepository`, `RoomRepository` + подпапка `Log/`
(9 лог-репозиториев, §23).

Единый паттерн:

```php
class RoomRepository {

    private string $table;

    public function __construct() {
        $this->table = TableName::Rooms->prefixed(); // $wpdb->prefix . 'fs_lms_rooms'
    }

    public function find( int $id ): ?RoomDTO {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ? RoomDTO::fromRow( $row ) : null;
    }
}
```

Ключевые правила WPDB-репозиториев:

- параметры — только через `$wpdb->prepare()` (`%d`, `%s`, `%f`); интерполяция
  пользовательских данных в SQL запрещена;
- имя таблицы — из `TableName::X->prefixed()`, наружу не параметризуется;
- наружу — DTO или массив DTO, не сырые строки;
- идемпотентные вставки — `INSERT IGNORE` + fallback-SELECT, upsert'ы — по UNIQUE-ключу;
- многотабличные операции — через `TransactionRunner::inTransaction()`.

## 10. Собственные таблицы: полный список

Все имена — в enum `Inc\Enums\Settings\TableName` (24 кейса), схема — в `Migration_1_0_0`.
Ниже сгруппировано по назначению (реальные имена с префиксом: `wp_fs_lms_*`).

### Люди и зачисление

| Таблица | Репозиторий | Назначение / ключевые колонки |
|---|---|---|
| `fs_lms_persons` | `PersonRepository` | Человек, нечувствительные поля: `wp_user_id`, ФИО, `birth_date`, `is_student`, `school`, `grade`, `expelled_at` |
| `fs_lms_person_documents` | `PersonDocumentsRepository` | **Весь PII, зашифрован**: `email_enc/hash`, `phone_enc/hash`, `doc_number_enc/hash`, `inn_enc/hash`, `address_enc` (UNIQUE `person_id`) |
| `fs_lms_applications` | `ApplicationRepository` | Заявки: `student/parent_person_id`, `status` (см. §22), `subject_key`, `join_code_hash/enc/expires_at`, `student_email_hash`, `converted_record_id` |
| `fs_lms_student_records` | `StudentRecordRepository` | **Факт обучения**: `student_person_id`, `parent_person_id`, `group_id`, snapshot ФИО/школы/класса, `contract_no/date`, `order_no/date`, `status` (`active/finished/expelled/transferred`), `enrolled_at/by`, `expelled_at/by` |
| `fs_lms_consents` | `ConsentRepository` | Согласия на обработку ПД: `consent_type`, `version` (sha256 текста), `ip_address`, `user_agent`, `signed_for_person_id` |
| `fs_lms_groups` | `GroupsRepository` | Группы: `subject_key`, `academic_period_id`, `name`, `teacher_id`, `meetings` (JSON-расписание), `course_id`, `room_id`, `program_locked_at` |

> Обрати внимание: связь «родитель ↔ ученик» хранится прямо в `student_records.parent_person_id`
> (отдельной таблицы relationships больше нет), а PII вынесен из `persons`
> в `person_documents`.

### Обучение (факты)

| Таблица | Репозиторий | Назначение / ключевые колонки |
|---|---|---|
| `fs_lms_group_lessons` | `GroupLessonRepository` | **Занятие** — слот программы группы: `group_id`, `lesson_id` (nullable!), `label`, `position`, `work_ids_snapshot`, `extra_work_ids`, `scheduled_at`/`ends_at`, `is_pinned`, `kind` (`group/individual`), `status` (`scheduled/held/cancelled/moved`), `student_person_id` (для индивидуальных), `room_id`, `visibility` (`hidden/open/archived`), `homework_due_at`, `work_deadlines` (JSON), `step_settings_overrides` (JSON), `continued_from_id`, `recording_url` |
| `fs_lms_lesson_progress` | `LessonProgressRepository` | Прохождение шагов: `student_person_id`, `group_lesson_id`, `step_key`, `status` (`locked/available/viewed/completed/failed`); UNIQUE (student, group_lesson, step_key) |
| `fs_lms_submissions` | `SubmissionRepository` | Сдачи работ: `work_id`, `work_type`, `task_id` (NULL = агрегат), `answer_text`, `attachment_id`, `due_at`, `status` (`assigned/submitted/graded/returned`), `score/max_score`, `feedback` |
| `fs_lms_assessment_attempts` | `AssessmentAttemptRepository` | Попытки контрольных: `assessment_id`, `attempt_number`, `started_at`, `deadline_at`, `status` (`in_progress/submitted/graded/expired`), `total/max_score`; UNIQUE (assessment, student, attempt_number) |
| `fs_lms_assessment_answers` | `AssessmentAnswerRepository` | Ответы попытки по заданиям: `attempt_id`, `task_id`, `is_correct`, `score/max_score`, `criteria_scores` (JSON), `grader_note` |
| `fs_lms_task_attempts` | `TaskAttemptRepository` | Попытки интерактивных задач: `step_key`, `task_id`, `attempt_number`, `answer` (JSON), `is_correct`, `item_feedback` (JSON) |
| `fs_lms_attendance` | `AttendanceRepository` | Посещаемость: `group_lesson_id`, `student_person_id`, `is_present`, `marked_by/at`; UNIQUE (занятие, ученик) |
| `fs_lms_substitutions` | `SubstitutionRepository` | Замены преподавателя: `group_id`, `original/substitute_teacher_id`, `valid_from/valid_to`, `approved_by` |
| `fs_lms_rooms` | `RoomRepository` | Кабинеты: `name`, `seats`, `allowed_subjects`, `is_active` |

### Логи и аудит (9 каналов + лента обучения)

| Таблица | Канал |
|---|---|
| `fs_lms_audit_log` | Аудит действий зачисления |
| `fs_lms_entity_audit_log` | Аудит действий с сущностями (CPT/термины) |
| `fs_lms_pii_access_log` | Каждый доступ к PII |
| `fs_lms_data_change_log` | Изменения PII (старое/новое значение — зашифрованы) |
| `fs_lms_consent_change_log` | Изменения согласий |
| `fs_lms_export_log` | Экспорт CSV |
| `fs_lms_email_log` | Отправка писем |
| `fs_lms_auth_log` | Вход/выход/ошибки аутентификации |
| `fs_lms_learning_events` | Append-only лента событий обучения (`subject_key`, `group_id`, `action`, `entity_type/id`, `is_public`) |

> Модуль AdSync создаёт свою таблицу-очередь сам (`AdSchema::ensure()`), минуя основной
> MigrationRunner — модульные данные принадлежат модулю (§26).

## 11. Миграции

**Файлы:** `inc/Migrations/MigrationRunner.php` + `inc/Migrations/Migration_1_0_0.php` —
сейчас миграция **одна, консолидированная** (отдельные файлы `1_0_1`, `1_0_2`… упразднены).

Как работает:

- версия схемы — `wp_options['fs_lms_schema_version']` (`OptionName::SchemaVersion`), дефолт `0.0.0`;
- `MigrationRunner::run()` сортирует зарегистрированные миграции по `version_compare` и
  применяет те, что новее текущей версии; в конце обновляет опцию;
- запускается **только при активации плагина** (`Activate::activate()`), не на каждой загрузке;
- `reset()` сбрасывает версию в `0.0.0` (dev), `rollback()` откатывает `down()`.

`Migration_1_0_0::up()` состоит из трёх частей:

1. **Reset legacy** — дропает старые таблицы прежних архитектур (`fs_lms_relationships`,
   `fs_lms_enrollments`, `fs_lms_expelled_archive`, `fs_lms_archive`);
2. **`dbDelta`** — создаёт все 24 таблицы;
3. **Cleanup-секция** — `ALTER TABLE … ADD COLUMN IF NOT EXISTS` / `DROP COLUMN IF EXISTS` /
   переименования: доводит схему на уже существующих установках (у которых `dbDelta`
   не пересоздаёт таблицы).

### Как менять схему (dev-процесс)

**Добавить таблицу или колонку** — не создавай новый файл миграции:

1. Таблица → добавь `CREATE TABLE` в `up()` и `DROP` в `down()` + кейс в `TableName`.
   Колонка → добавь её в `CREATE TABLE` **и** строку `ADD COLUMN IF NOT EXISTS` в Cleanup-секцию.
2. Сбрось версию схемы и перезапусти миграции:

```bash
docker exec wp_db mariadb -u root -proot wordpress \
  -e "UPDATE wp_options SET option_value='0.0.0' WHERE option_name='fs_lms_schema_version';"
# затем деактивировать/активировать плагин (или перезагрузить страницу, если проект
# настроен на автопрогон) — миграция выполнится заново, идемпотентно
```

**Удалить колонку:**

1. Убери её из `CREATE TABLE` в `up()`;
2. Добавь `DROP COLUMN IF EXISTS` в Cleanup-секцию того же `up()`;
3. Сбрось версию схемы (команда выше).

`dbDelta` колонок не удаляет — поэтому и существует Cleanup-секция.

## 12. Менеджеры и Регистраторы

| | Менеджер (`Managers/`) | Регистратор (`Registrars/`) |
|---|---|---|
| Уровень | низкий — прямой вызов WP API | высокий — фасад с накоплением конфигураций |
| Состояние | нет | копит массив конфигов до `register()` |
| Пример | `TaxonomyManager` → `register_taxonomy()` | `SubjectTaxonomyRegistrar` → формирует конфиги всех таксономий предмета |

**Менеджеры** (`Managers/Wp` — 8: `PostManager`, `TermManager`, `TaxonomyManager`, `CPTManager`,
`MetaBoxManager`, `MenuManager`, `CronManager`, `MediaManager`; `Managers/Person` — `UserManager`,
`RoleManager`, `UserBehaviorManager`; `Managers/Course` — `CourseManager`, `LessonManager`,
`WorkManager`; `Managers/Assessment`, `Managers/Subject`) инкапсулируют вызовы WP:
`wp_insert_post`, `wp_set_password`, `add_meta_box`, `wp_schedule_event`… Сервис никогда
не дёргает WP API сам — только через менеджер (это и делает сервисы тестируемыми).

**Регистраторы** (4: `MenuRegistrar`, `MetaBoxRegistrar`, `SubjectCPTRegistrar`,
`SubjectTaxonomyRegistrar`) — fluent-фасады: `addPage()->addSubPages()->register()`.
Используются контроллерами, делегируют менеджерам.

```php
// Регистратор копит и делегирует:
$this->tax_registrar
    ->addStandardTaxonomy( "{$key}_task_number", $postTypes, 'Номера', 'Номер' )
    ->register(); // внутри: TaxonomyManager::register() → add_action('init', …)
```

---

# Часть IV. Админка

## 13. Админ-меню и страницы

### Кто регистрирует меню

- `AdminController` (`Controllers/System/`) — top-level меню «FS LMS» и его подстраницы
  (Статистика/Dashboard, Пользователи, Группы, Настройки, Логи…). Конфиги страниц собираются
  билдерами (`Controllers/Builders/`) и регистрируются через `MenuRegistrar`
  (`add_menu_page` / `add_submenu_page` внутри `MenuManager`).
- `LearningMenuController` (`Controllers/Course/`) — top-level меню **«Обучение»**: банки
  контента скрыты из стандартного меню WP (`show_in_menu => false`), и это меню — единая
  точка входа в них. Вкладки-предметы строит `TeacherSubjectsService` (преподаватель видит
  свои предметы, админ — все).
- `ModulesDashboardController` — карточки модулей на Dashboard (§26).

### Анатомия админ-страницы

```
AdminController::register()
   └─ MenuRegistrar::addPages([...])->addSubPages([...])->register()
        └─ callback страницы = метод Callbacks-класса
             └─ use TemplateRenderer; $this->render('admin/groups.php', $data)
                  └─ templates/admin/groups.php   ← HTML + esc_html/esc_attr
```

Шаблоны админки лежат в `templates/admin/`:

```
templates/admin/
├── *.php                      # страницы (groups.php, course-builder.php, learning/…)
├── components/                # переиспользуемые куски
│   ├── UI/ui_renderers.php    # рендереры дизайн-системы: render_fs_toggle, render_fs_badge,
│   │                          #   render_fs_card, render_fs_field, render_fs_empty…
│   ├── modals/                # PHP-разметка модальных окон
│   └── tabs/                  # вкладки страниц: settings-tabs/ (settings-1-subjects-manager…
│                              #   settings-9-rooms), subject-tabs/, userlist-tabs/, logs-…
```

Страницы с вкладками (Настройки, Userlist, Логи, страница предмета) устроены одинаково:
шаблон-обёртка рисует `h2.nav-tab-wrapper`, актуальная вкладка подключает
`components/tabs/<группа>/<N-имя>.php`.

**Дизайн-система админки**: единые примитивы `.fs-btn` (`--primary/--secondary/--danger/--ghost/--link`),
`.fs-card`, `.fs-field`, `.fs-empty`, `.fs-page-header`, `.fs-filter-bar` + PHP-рендереры в
`components/UI/ui_renderers.php` и JS-хелперы `fsBadge()`/`fsEmpty()` (`admin/modules/ui-helpers.js`).
Новая разметка в админке обязана использовать эти примитивы и токены из
`src/scss/admin/_variables.scss`. Правила и план консолидации — `.docs/UI.md`.

### Чеклист «добавить админ-страницу»

1. Шаблон `templates/admin/my-page.php` (только разметка + `esc_*`).
2. Метод в Callbacks-классе (обычно `AdminCallbacks` или доменный): получает данные
   у сервиса, вызывает `$this->render(...)`.
3. Пункт меню в `AdminController` (конфиг: `page_title`, `menu_title`, `capability` —
   из `Capability`, `menu_slug`, `callback`).
4. Если нужен JS — гейт по странице в `Enqueue::enqueue_admin_assets()` (§15) и,
   при необходимости, локализация переменных там же.

## 14. Модальные окна

Реальный (актуальный) паттерн трёхслойный, все admin-модалки устроены одинаково:

| Слой | Где | Ответственность |
|---|---|---|
| PHP-разметка | `templates/admin/components/modals/*.php` | HTML модалки; поля с `data-field="…"` |
| JS-модалка | `src/js/admin/modals/**` | Только UI: open/close, fill(), reset. **AJAX запрещён** |
| JS-менеджер | `src/js/admin/managers/**` | Оркестрация: открытие по клику, сбор данных, AJAX |
| JS-сервис | `src/js/admin/services/**` | Табличные операции, «тяжёлый» AJAX |

Общие утилиты — `src/js/admin/modules/modal-base.js`: `openModal($modal)`, `closeModal($modal)`,
`bindEsc(key, cb)` / `unbindEsc(key)`.

```js
// Модалка (src/js/admin/modals/…): объектный паттерн, только UI
export const RoomModal = {
    $modal: null,
    init() {
        this.$modal = $( '#fs-room-modal' );
        if ( ! this.$modal.length ) { return; }
        // … события закрытия, onConfirm-колбэки
    },
    open() { openModal( this.$modal ); },
    close() { closeModal( this.$modal ); },
    fill( data ) {
        Object.entries( data ).forEach( ( [ k, v ] ) =>
            this.$modal.find( `[data-field="${ k }"]` ).val( v ?? '' ) );
    },
};
```

Механика `data-field` — стандарт заполнения: PHP помечает инпуты `data-field="last_name"`,
JS-метод `fill(data)` раскладывает объект по этим атрибутам. Для «вторых и далее» повторяемых
блоков используется `data-enr-field` (чтобы не пересекаться с основными полями).

Двухшаговое заполнение больших модалок (карточка ученика): сначала мгновенный pre-fill из
`data-*`-атрибутов строки таблицы (`<tr data-enrollment="…">`), затем фоновый AJAX
(`getPersonData`) докладывает PII — их в HTML не кладут из соображений безопасности.

Auto-loader: `admin/modules/ui.js` через `require.context` сам загружает и инициализирует
**все** файлы из `admin/modals/` — импортировать модалку вручную в `admin.js` не нужно.
Менеджеры и сервисы импортируются в `admin.js` явно, с гейтом по DOM.

Глобальные модалки `confirm-modal.php` и `alert-modal.php` выводятся на всех страницах
плагина хуком `admin_footer` (`Enqueue::render_confirm_modal()`).

## 15. JS-архитектура: бандлы и правила

### Дерево src/js

```
src/js/
├── common/                    # бандл common — общее для админки и публичной части
│   ├── common.js              # entry: инициализация общих компонентов + валидации
│   ├── icons.js               # ЕДИНСТВЕННЫЙ источник SVG-иконок всех бандлов (см. ниже)
│   ├── validation-manager.js  # §17
│   ├── input-masks.js         # маски: телефон, паспорт, ИНН
│   ├── validators/            # 9 валидаторов (§17)
│   └── components/            # badge, toggle, toggle-secret, copy-button, confirm-dialog, toast, tooltip
│
├── admin/                     # бандл admin (jQuery)
│   ├── admin.js               # entry: $(document).ready, гейты по DOM
│   ├── _types.js              # JSDoc-типы window-глобалов
│   ├── modules/               # утилиты: modal-base, utils, toast, ui (авто-loader), ui-helpers
│   ├── modals/                # UI модалок (без AJAX) — авто-загрузка через ui.js
│   ├── managers/              # оркестраторы модалок
│   └── services/              # AJAX + бизнес-логика: tables/*, settings/*,
│                              #   course-builder, step-editor, task-editor, work-builder,
│                              #   assessment-builder, slot-builder, boilerplates, import-csv…
│
├── frontend/                  # бандл frontend (чистый JS, без jQuery)
│   ├── frontend.js            # entry: DOMContentLoaded
│   ├── components/            # UI: task-tabs, article-carousel, task-widget
│   └── services/              # AJAX: apply-form, join-form, group-cockpit, submission,
│                              #   assessment, captcha, dadata-*
│
├── profile/                   # бандл profile — SPA личного кабинета (§36–39)
└── player/                    # бандл player — плеер урока (§32)
```

### Паттерны экспорта (не смешивать!)

```js
// admin/* — объектный паттерн, jQuery:
export const MyService = {
    init() { this.bindEvents(); },
    bindEvents() { $( document ).on( 'click', '.js-x', … ); },
};
// admin.js:  if ( $( '.js-x' ).length ) { MyService.init(); }

// frontend/* — функциональный паттерн, pure JS:
export function initMyFeature() {
    if ( ! document.getElementById( 'my-root' ) ) { return; }
    …
}
// frontend.js:  initMyFeature();

// modules/* — именованные функции-утилиты:
export function openModal( $modal ) { … }
```

Правила по директориям:

| Директория | AJAX | jQuery |
|---|---|---|
| `admin/modals/`, `admin/modules/` | нет | да |
| `admin/managers/`, `admin/services/` | да | да |
| `frontend/components/` | нет | нет |
| `frontend/services/` | да | нет |
| `common/**` | нет | по факту используется в admin-контексте |
| `profile/**`, `player/**` | только через свой шов (§38) / свои actions | нет |

### Иконки (SVG): common/icons.js + enum Icon

Инлайновые `<svg>` в JS-шаблонных строках и PHP-шаблонах **запрещены** — иконки берутся
из двух единственных источников (по одному на язык):

| Язык | Источник | Использование |
|---|---|---|
| JS (все бандлы) | `src/js/common/icons.js` | `import { icoCheck } from '../common/icons.js'; icoCheck( 16 )` → строка `<svg>…</svg>` |
| PHP (templates/) | enum `Inc\Enums\Ui\Icon` | `Icon::Check->svg( 16 )` (+ `phpcs:ignore EscapeOutput` при echo) |

Правила:

- фабрики — именованные экспорты (`icoCheck`, `icoChevronRight`, `icoCaret`, `icoPlus`…);
  Webpack tree-shak'ит неиспользуемое, поэтому импорт из любого бандла бесплатен;
- размер — аргумент (`icoCheck( 34 )` для empty-state), цвет — `currentColor` от родителя;
  у шевронов есть второй аргумент цвета (`icoChevronRight( 18, 'var(--muted-2)' )`),
  у карета — класс (`icoCaret( 12, 'kp-caret' )`);
- глифы типов шагов урока — `STEP_GLYPHS` / `stepIcon( ui )` там же; их делят конструктор
  курса (`admin/services/step-editor.js`) и плеер (`player/icons.js` → `typeIco` красит глиф
  цветом типа). `player/icons.js` — только мета типов (TYPES/typeMeta) и фасад `ICO`;
- нужна новая иконка — добавь фабрику в `icons.js` (и/или кейс в enum `Icon`), не рисуй SVG
  по месту; общие глифы JS- и PHP-источников должны визуально совпадать;
- размер по умолчанию у PHP-кейсов зашит в `Icon::defaultSize()` — `svg()` без аргумента.

### Кто что подключает: Enqueue.php

Все `wp_enqueue_*` и **все** `wp_localize_script()` — только в `inc/Core/Enqueue.php`
(никогда в шаблонах). Логика:

- **Админка** — бандлы `common` + `admin` подключаются на страницах плагина (`fs_*`,
  `student_*`) и на наших CPT (task/lesson/work/assessment/course/problems/article).
  Новый экран/CPT → добавь флаг `$is_*_cpt` в `enqueue_admin_assets()`, иначе твоего JS/CSS
  там не будет.
- **Публичная часть** — три взаимоисключающих ветки в `enqueue_frontend_assets()`:
  1. фильтр `fs_lms_is_player_route` === true → **только** бандл `player` (+ MathJax CDN);
  2. залогинен и на `/profile/` → **только** бандл `profile`;
  3. иначе — общий стек `common` + `frontend`.

### Window-глобалы (все локализации)

| Переменная | Где доступна | Что внутри |
|---|---|---|
| `fs_lms_vars` | все админ-страницы плагина | `ajaxurl`, `ajax_actions` (все 189 из `AjaxHook::toJsArray()`), `nonces{subject, manager, expulsion, deleteGroup, deletePeriod, hardDeleteStudent, config, authorLesson, authorWork, authorAssessment, authorCourse, room}`, `coursePreviewUrl` |
| `fs_lms_task_data` | CPT задания/работы, страницы `fs_subject_*` | `ajax_url`, `security`, `subject_key`, `post_type`, `required_taxonomies` |
| `fs_lms_lesson_vars` | CPT урока | `ajax_url`, `subject_key`, `nonces.authorLesson` |
| `fs_lms_task_editor_vars` | task/lesson/work/course CPT + `fs_subject_*` | `schema` (все схемы шаблонов задач), `actions{saveTaskContent, getTaskEditorForm}`, `nonces.taskContent` |
| `fs_lms_applications_vars` | страница `fs_lms_userlist` | `nonces{trash, edit, review, enroll, manager, revealPii, updatePerson, exportPii, deletePii, restoreFromArchive, selectExistingParent, removeParentAssignment}` |
| `fs_lms_apply_vars` | `/apply/` | `ajax_url`, `hp_field`, `form_token`, `actions`, `nonces` (+ `captcha_key` дописывает модуль SmartCaptcha через фильтр `fs_lms_apply_vars`) |
| `fs_lms_join_vars` | маршрут `fs_lms_page=join` | `actions{submit_parent, check_email}`, `nonces` (+ `dadata_token` — модуль DaData, фильтр `fs_lms_join_vars`) |
| `fs_lms_cockpit_vars` | `/group/` | actions/nonces программы группы: visibility, reorder, schedule, step-settings, task-attempts… |
| `fs_lms_submission_vars` | `/group/` | actions/nonces сдач: submitWork, saveGrade, getGradebook… |
| `fs_lms_assessment_vars` | одиночная страница контрольной | actions/nonces попыток: startAttempt, submitAttempt, uploadAnswerFile… |
| `fs_lms_player_vars` | плеер | `actions{markStep, submitTask, submitBatchWork}` + nonces (§32) |
| `fsProfile` | `/profile/` | конфиг SPA кабинета из `ProfileViewResolver::jsConfig()` (§36) |

`fs_lms_vars` и `fs_lms_task_data` типизированы в `src/js/admin/_types.js` — импортируй его
в admin-файлах, использующих глобалы.

### Чеклист «добавить JS-модуль»

1. Файл в правильную директорию по таблице выше, правильный паттерн экспорта.
2. Админ-сервис: `import` + гейт в `admin.js`. Модалка: просто положи в `admin/modals/` —
   авто-loader подхватит. Frontend: `import` + вызов в `frontend.js`.
3. Нужны данные из PHP → локализация в `Enqueue` (не в шаблоне!).
4. `npx gulp scripts`.

## 16. Уведомления в админке

Четыре механизма, каждый под свой тип ошибки:

| Ситуация | Механизм | Файл |
|---|---|---|
| Ошибка формата поля | валидатор + `.fs-field-error` у поля | §17 |
| Логическая ошибка в открытой модалке («слаг занят») | `showModalError(msg, $modal)` / `clearModalError($modal)` | `admin/modules/utils.js` |
| Результат операции на странице (не в модалке) | `showNotice(message, type, $container, options)` — WP-плашка `.notice` | `admin/modules/utils.js` |
| Сетевая ошибка (`$.fail`) и фоновые ошибки | `showToast(message, type, duration)` — всплывашка справа-внизу | `admin/modules/toast.js` |
| Критическая ошибка поверх модалки | `await AlertModal.show(message, title)` | `admin/modals/alert-modal.js` |

Z-index стек (переменные в `src/scss/admin/_variables.scss`):
`$z-modal-root: 999999` < `$z-modal-alert: 1000050` < `$z-toast: 1000100`.

Правило выбора: ошибка **данных** → в модалке `showModalError`, на странице `showNotice`;
ошибка **сети** → `showToast`; ошибка, требующая осознанного «ОК» поверх модалки → `AlertModal`.

## 17. Клиентская валидация форм

**Файлы:** `src/js/common/validators/` + `src/js/common/validation-manager.js`
+ маски `src/js/common/input-masks.js`. Стили — только
`src/scss/common/components/_validation.scss`.

### Валидаторы (реестр `validators/index.js`)

| Ключ `data-validate` | Класс | Проверяет |
|---|---|---|
| *(не указан)* | `BaseValidator` | только нативные HTML5-атрибуты: `required`, `type="email"`, `minlength`, `pattern` |
| `phone` | `PhoneValidator` | `+7(999)-000-00-00`, 11 цифр |
| `cyrillicName` | `CyrillicNameValidator` | кириллица + минимум 2 слова (ФИО) |
| `cyrillicDigits` | `CyrillicDigitsValidator` | кириллица и цифры |
| `address` | `AddressValidator` | адресные символы |
| `schoolName` | `SchoolNameValidator` | название школы |
| `latinOnly` | `LatinOnlyValidator` | латиница, цифры, `_` |
| `passportSN` | `PassportSeriesNumberValidator` | `4507 123456` |
| `inn` | `InnValidator` | 10/12 цифр |

Механика: `validateField(input)` сначала гоняет нативные проверки (`ValidityState`), затем
`checkCustom(value, input)` каждого указанного валидатора (`data-validate` может содержать
несколько ключей через пробел). Пустое необязательное поле всегда валидно. Ошибка рендерится
как `<p class="fs-field-error">` внутрь обёртки `.fs-form-group` (+класс `form-invalid`).

### Подключение

```html
<!-- 1) Автоматически: формы с data-fs-validate или .fs-lms-form подхватывает common.js -->
<form data-fs-validate>
  <div class="fs-form-group">
    <input type="tel" data-validate="phone" required>
  </div>
</form>
```

```js
// 2) Вручную (AJAX-формы и admin-модалки): в init() компонента
import { initFormValidation } from '../../common/validation-manager.js';
const validateAll = initFormValidation( form );  // вешает blur/input
form.addEventListener( 'submit', ( e ) => {
    e.preventDefault();
    if ( ! validateAll() ) { return; }   // показал ошибки, сфокусировал первое поле
    // …AJAX
} );
```

События: `blur` — проверка поля; `input` — мягкий сброс ошибки; submit —
`validateAll()` по всем полям.

### Новый валидатор — 3 шага

1. `src/js/common/validators/MyValidator.js` — наследуй `BaseValidator`, переопредели
   `checkCustom(value, input)` (верни строку ошибки или `null`).
2. Зарегистрируй в `validators/index.js`: `myKey: new MyValidator()`.
3. Поставь `data-validate="myKey"` на инпут (в обёртке `.fs-form-group`). Всё.

Клиентская валидация не заменяет серверную: в callbacks обязательны `requireText()` /
`requireInt()` из `Sanitizer`.

## 18. Метабоксы и поля заданий

**Источник истины полей задания — PHP:** `inc/MetaBoxes/Fields/*` (17 классов полей,
реализуют `FieldInterface`, наследуют `BaseField`) и `inc/MetaBoxes/Templates/*`
(17 шаблонов, наследуют `BaseTemplate`). **Не строить поля задания в JS.**

- Шаблон = набор полей: `get_fields()` возвращает `['task_condition' => ['label' => …,
  'object' => new ConditionField()], …]`.
- Поле знает, как себя `render()` и `sanitize()`; для inline-редактора дополнительно
  `editorType()`/`editorConfig()` — тип виджета в JS-схеме.
- `TemplateRegistry` (Services/Template) собирает шаблоны по enum `TaskTemplate`
  (рефлексией: `{PascalCase(value)}Template`); `allEditorSchemas()` отдаёт схемы всех
  шаблонов в JS (`fs_lms_task_editor_vars.schema`).

Два режима редактирования одной и той же разметки:

| Режим | Как рендерится | Как сохраняется |
|---|---|---|
| Нативный метабокс на CPT-экране | `MetaBoxController` → `MetaBoxRegistrar` → `BaseTemplate::render()` | хук `save_post` → `MetaBoxManager::saveFields()` |
| Inline-модалка в конструкторе (TaskEditor) | тот же HTML по AJAX `GetTaskEditorForm`; поведение вешает `task-fields.js` | AJAX `SaveTaskContent` → тот же `MetaBoxManager::saveFields()` |

Оба пути пишут в `post_meta['fs_lms_meta']` (`PostMetaName::Meta`). Как добавить новый
**тип задачи** целиком — см. §33.

---

# Часть V. Публичная часть и безопасность

## 19. Публичные страницы и маршруты

### PageRoutes

Все slug'и публичных страниц — в `Inc\Enums\Wp\PageRoutes` (методы `url()`, `isCurrent()`):

| Case | URL | Что это | Кто обслуживает |
|---|---|---|---|
| `SignIn` | `/sign-in/` | Вход в кабинет | шорткод `[fs_lms_login_form]` (регистрирует модуль SocialAuth — `SocialAuthPageController`), редиректы — `ProfileController` |
| `Apply` | `/apply/` | Заявка ученика | `ApplyPageController` (шорткод `[fs_lms_apply_form]`) |
| `UserProfile` | `/profile/` | SPA личного кабинета | `ProfileController` (§36) |
| `ConsentPage` | `/consent/` | Текст согласия | `ConsentController` |
| `GroupCockpit` | `/group/?gid=N` | Кокпит группы; с `?gl=` — плеер урока | `GroupCockpitController` / `LessonPlayerController` |
| `CoursePreview` | `/course-preview/?course=N` | Preview-плеер курса для авторов | `CoursePreviewController` |

Страницы создаются автоматически при активации (`PageGeneratorService`, идемпотентно).
Дополнительно существует rewrite-маршрут родительской формы `/lms/join/{JOIN-код}`
(шаблон `templates/frontend/join.php`; в тестовом окружении открыт дебаг-адрес `/lms/join/000`).

### Три способа отдать публичную страницу

1. **Шорткод** (`ShortCode` enum) — страница-обёртка темы выводит header/footer сама:
   `[fs_lms_login_form]`, `[fs_lms_apply_form]`. Шаблон шорткода **не** вызывает
   `ThemeCompatService`.
2. **`template_include` с хромом темы** — страница задания `/tasks/{slug}`
   (`TaskPageController`), страница контрольной (`AssessmentPageController`), кокпит группы.
   Такие шаблоны обязаны использовать `ThemeCompatService::header()/footer()` вместо
   `get_header()/get_footer()` (FSE-темы не имеют header.php — сервис сам выбирает API).
3. **Полностью изолированная страница** — свой `<!DOCTYPE html>` без темы:
   `templates/frontend/profile.php` (кабинет) и `templates/frontend/lesson-player/player.php`
   (плеер). Тут нет ни темы, ни `ThemeCompatService` — только `wp_head()`/`wp_footer()`
   и свой бандл (§15).

### Редиректы

`ProfileController` (`template_redirect`): незалогиненный на `/profile/` → `/sign-in/`;
залогиненный на `/sign-in/` → `/profile/`; роль без витрины кабинета (методист/маркетолог) →
в `wp-admin`; отчисленный при политике `block` — гейт. `UserController` ограничивает LMS-ролям
доступ в wp-admin и ведёт их после логина на `/profile/`.

## 20. Роли и права

### Роли (`Inc\Enums\Access\UserRole`, 8 шт.)

| Case | WP slug | Кто это |
|---|---|---|
| `FSOffice` | `lms_office` | Офис/администратор LMS: заявки, зачисление, PII, замены |
| `FSMethodist` | `lms_methodist` | Методист: авторинг контента |
| `FSMarket` | `lms_market` | Маркетолог: статьи, статистика |
| `FSTeacher` | `lms_teacher` | Преподаватель: ведение групп, журнал, проверка |
| `FSStudent` | `lms_student` | Ученик |
| `FSParent` | `lms_parent` | Родитель (read-only кабинет по детям) |
| `Student` | `lms_student_free` | Внешний ученик (OAuth, без подписки) |
| `Teacher` | `lms_teacher_free` | Внешний учитель |

Матрица прав задаётся в `UserRole::capabilities()` и применяется `RoleManager`'ом.
Синхронизация — через `fs_lms_caps_version` в `Init::run()` (§3): изменил матрицу →
подними версию. Мультироль поддерживается нативно (union прав); назначение ролей —
`UserManager::addRole()/removeRole()`, не `set_role()`.

### Capabilities (`Inc\Enums\Access\Capability`, 13 шт.)

| Группа | Права |
|---|---|
| Администрирование | `Admin` (`manage_options` — только WP-администратор), `ManageLmsPlatform`, `ManageLmsRoles` |
| Авторинг и проведение | `AuthorLmsCourses` (курсы/уроки/работы/контрольные/задачи), `ManageLmsArticles`, `ManageLmsTeaching` (журнал, оценивание), `ManageSchedule` (замены/расписание — офис) |
| Статистика | `ViewLMSStats` |
| Заявки | `ManageApplications`, `EnrollStudent` |
| PII | `ViewPII`, `ExportPII`, `ManagePersons` |

Проверка прав:

```php
$this->authorize( Nonce::Manager, Capability::ManageApplications ); // admin-AJAX
Nonce::ParentSubmit->verify();                                      // публичный AJAX
current_user_can( Capability::ViewPII->value );                     // в шаблонах
```

Гейтим всегда по **capability**, не по имени роли. Доступ к конкретной *группе* — отдельный
слой: `GroupAccessGuard::canManage()/isMemberEver()` (владение объектом: свой `teacher_id`
или активная замена). Целевая модель прав и план дробления — `.docs/Roles.md`.

## 21. Бот-защита публичных форм

Форма заявки `/apply/` защищена слоями (порядок проверки в callbacks отправки OTP):

1. **Nonce** (`Nonce::Apply`);
2. **`FormGuardService`** (`Services/Security/`) — honeypot + тайминг: `honeypotField()`
   (скрытое поле-ловушка в `.fs-hp`), `timestampToken()` (`{ts}.{hmac}` на `FS_LMS_HASH_SALT`),
   `isHuman($honeypot, $token)` — поле пусто и прошло от 3 сек до 1 часа;
3. **Rate-limit по IP** (`RateLimitService` — fixed-window на transients; IP/email хранятся
   только хэшами);
4. **Капча** (если включён модуль SmartCaptcha; при недоступности провайдера — fail-open);
5. **Rate-limit по email** — анти-бомбинг адреса;
6. **OTP-cooldown** (60 сек) и **attempt-cap** — после 5 неверных вводов код инвалидируется.

В тестовом окружении (`FS_LMS_TEST_ENV`) слои 2–4 пропускаются, письмо не шлётся.

Чтобы защитить новую публичную форму: honeypot-`<input>` в шаблон → `hp_field` + `form_token`
в локализацию (через `FormGuardService` в `Enqueue`) → форвард обоих значений в JS-сабмите →
`isHuman()` на сервере до любых дорогих операций.

---

# Часть VI. Сервисные подсистемы

## 22. Зачисление и PII (обзор)

Полный жизненный цикл: заявка → проверка → зачисление → обучение → отчисление/восстановление.

### Данные

- **Заявка** — `fs_lms_applications`. Статусы (`ApplicationStatus`, конечный автомат
  `canTransitionTo()`): `pending_parent → ready_for_review → enrolling → converted`;
  терминальные `expired`, `trash`.
- **Человек** — `fs_lms_persons` (несекретное: ФИО, школа, класс) +
  `fs_lms_person_documents` (весь PII зашифрован libsodium: email/телефон/документ/ИНН/адрес,
  рядом sha256-хэши для поиска без расшифровки).
- **Зачисление** — `fs_lms_student_records`: ученик ↔ группа + снапшот ФИО/школы/класса,
  договор/приказ, `status` (`active/finished/expelled/transferred`), `parent_person_id`
  (связь с родителем — прямо здесь).
- **Согласие** — `fs_lms_consents` (версия = sha256 текста на момент подписания).

### Ключевые сервисы

| Сервис | Роль |
|---|---|
| `PiiCryptoService` (`Security/`) | Шифрование/дешифрование PII (`FS_LMS_ENC_KEY`), хэши (`FS_LMS_HASH_SALT`); `isAvailable()` — гейт keyless-режима |
| `PersonReader` (`Person/`) | **Единственный** путь чтения PII: `read(personId, fields, reason)` — каждое чтение логируется в `pii_access_log`. Прямое чтение `*_enc`-колонок бессмысленно (вернётся шифроблоб) |
| `EmailOtpService` (`Email/`) | OTP-коды подтверждения email: отправка, cooldown, verify c attempt-cap, bypass-код из конфига |
| `RateLimitService` (`Security/`) | Лимиты действий по IP/email/user (см. §21) |
| `PasswordGeneratorService` (`Security/`) | Пароли LMS-пользователей: `user_pass` + зашифрованная копия в usermeta `fs_lms_enc_password` для «Раскрыть учётные данные»; при ручной смене пароля копия автоматически удаляется (`profile_update`-хук) |
| `CsvExportService` (`Export/`) | CSV + одноразовые ссылки скачивания (Column Projection: колонки описывает вызывающий код) |

### Согласия на обработку ПД

Каждый тип согласия — **WP-страница** (текст правится в редакторе, история — штатные ревизии).
Определения (`тип → page_id`) — в `wp_options['fs_lms_consent_definitions']`
(`ConsentDefinitionsRepository`). Версия согласия = `sha256(post_content)` — считается на лету;
в `consents.version` пишется хэш на момент подписания. Управление — вкладка «Согласия»
в Настройках; архивные версии обслуживает `ConsentController`. При активации создаётся
дефолтный тип `pd_processing`.

### Инварианты (не нарушать)

- JOIN-код хранится только хэшем; сырой код не логируется.
- PII шифруется **до** записи; поиск по документу/email — по хэшу.
- Аудит-записи (`audit_log`) не содержат PII — только ID и типы операций.
- Согласие фиксируется до любых операций с ПД.
- Читать PII — только через `PersonReader::read()` с указанием `reason`.

Троблшутинг типовых проблем зачисления — §41.

## 23. Логирование и аудит

### PluginLogger — отладочное логирование

```php
use Inc\Shared\PluginLogger;

PluginLogger::debug( 'Context', 'message', $data );      // только при WP_DEBUG
PluginLogger::warning( 'Context', 'message', $data );    // всегда
PluginLogger::exception( 'Context', $e, $extra, true );  // исключения; true = писать всегда
```

Формат: `[FS LMS] CONTEXT: message | Context: {timestamp, user_id, ip, …}`.
`error_log()` напрямую — запрещён.

### Событийная шина и каналы аудита

Доменные события идут через шину: источник вызывает
`$this->logEvents->dispatch( LogEvent::X, new XEvent(…) )` (строго **после** успешной
операции/коммита), подписчик передаёт событие писателю, писатель — лог-репозиторию.

```
dispatch() → LogEventDispatcher → Controllers/Subscribers/* → Services/Log/*LogWriter
           → Repositories/WPDBRepositories/Log/* → таблица канала
```

Каналы (enum `LogChannel` → таблицы из §10): аудит зачисления, аудит сущностей, PII-доступ,
изменения PII, изменения согласий, экспорт, email, аутентификация, лента обучения.
Канал **Auth** — особый: пишется не через шину, а с WP-хуков (`wp_login`,
`wp_login_failed`) в `AuthLogController`.

Просмотр — страница «Логи» (табы `logs-N-*.php` + фильтры), экспорт — через
`CsvExportProviderInterface`.

**Добавить канал:** таблица в `Migration_1_0_0` + кейс `TableName` → кейс `LogChannel` →
лог-репозиторий в `WPDBRepositories/Log/` → writer в `Services/Log/` → кейсы `LogEvent` +
event-DTO → подписчик в `Controllers/Subscribers/` (+ в `Init::getServices()`) →
`dispatch()` в источнике → таб в UI при необходимости.

## 24. Email-шаблоны

Strategy-паттерн:

```
EmailTemplateInterface
├── PhpEmailTemplate        ← дефолты из templates/emails/{type}.php
└── WpOptionsEmailTemplate  ← правки администратора в wp_options, fallback → PHP-файл
```

`EmailService` шлёт все письма через `wp_mail()`, тип — enum `EmailTemplateType`
(`OtpCode`, `PasswordSetup`, `ApplicationConfirmation`, `ApplicationReady`, `Rejection`,
`NewRepresentative`, `WelcomeWithCredentials`). Плейсхолдеры в options-шаблонах — `{key}`.
Редактирование — вкладка «Шаблоны писем» (сброс = удаление записи в options → откат к
PHP-файлу). Отправки логируются в канал `email_log`.

Новый тип письма: PHP-файл шаблона → кейс в `EmailTemplateType` → метод в `EmailService`.

## 25. Конфигурация плагина

`PluginConfig` (`Services/Shared/`) — типизированные геттеры значений конфигурации
с приоритетом **константа wp-config.php > значение из БД**. Хранение —
`wp_options['fs_lms_plugin_config']` (`PluginConfigRepository`, мержит с DEFAULTS).
UI — вкладка «Конфигурация» в Настройках: поля «мягких» значений, генерация ключей
шифрования; поле, чьё значение задано константой, заблокировано с бейджем «wp-config».
Ключи шифрования в БД не хранятся никогда.

Добавить значение: ключ в `PluginConfigRepository::DEFAULTS` → геттер + `viewState()`
в `PluginConfig` → (если есть константа) кейс в `ConfigConstant` → сохранение в
`ConfigCallbacks::ajaxSaveConfig()` → поле в шаблоне вкладки → payload в
`config-settings.js`.

## 26. Опциональные модули

Модуль — **изолируемый лист**: его можно выключить флагом или полностью вырезать
(удалить каталог + одну строку в `Init::getServices()`), и ядро продолжит работать.
Ядро **никогда** не импортирует классы модуля — связь только через WP-хуки/фильтры;
модуль публичные классы ядра использовать может.

### Текущие модули (inc/Modules/)

| Модуль | Что даёт | Константа | Опция-тумблер (default) | Точка связи с ядром |
|---|---|---|---|---|
| `SocialAuth` | OAuth-вход (VK/Google/GitHub), страница входа `[fs_lms_login_form]` | `FS_LMS_SOCIAL_AUTH` | `fs_lms_social_auth` (**вкл.**) | свои контроллеры страницы/настроек |
| `AdSync` | Провижининг учёток в Active Directory (outbox-очередь + REST для Python-поллера) | `FS_LMS_AD_SYNC`, `FS_LMS_AD_HMAC_SECRET` | `fs_lms_ad_sync` (выкл.) | события заявок; REST `GET /ad/jobs`, `POST /ad/ack`; своя таблица через `AdSchema::ensure()` |
| `EgeComputer` | Альтернативный плеер контрольной «ЕГЭ (компьютерный)» | `FS_LMS_EGE_COMPUTER` (не задана → вкл.) | — | фильтр `fs_lms_assessment_renderer` (§34) |
| `DaData` | Автодополнение ФИО/адреса на `/lms/join` | `FS_LMS_DADATA`, `DADATA_API_TOKEN` | `fs_lms_dadata` (выкл.) | фильтр `fs_lms_join_vars` |
| `SmartCaptcha` | Yandex SmartCaptcha на `/apply/` | `FS_LMS_SMART_CAPTCHA`, ключи капчи | `fs_lms_smart_captcha` (выкл.) | фильтры `fs_lms_captcha_provider`, `fs_lms_apply_vars` |

### Устройство модуля

```
inc/Modules/MyModule/
├── MyModuleModule.php        # bootstrap — единственный класс, известный ядру (строка в Init)
├── Config/MyModuleConfig.php # isEnabled(): константа > опция > default; toggle()
├── Controllers/              # SettingsController (регистрируется ВСЕГДА) + рантайм-контроллеры
├── Callbacks/  Services/  Repositories/  Enums/            # по необходимости
├── templates/                # settings-tab.php или settings-section.php
└── assets/                   # self-contained admin.js/css (вне гульп-бандлов)
```

```php
public function register(): void {
    $this->settings->register();               // всегда: карточка на Dashboard + тумблер
    if ( ! MyModuleConfig::isEnabled() ) {
        return;                                // рантайм молча не подключается
    }
    $this->runtime->register();
}
```

Три уровня выключения: **константа** в wp-config (жёстко, блокирует тумблер) → **тумблер**
на Dashboard (опция в БД; сохраняется через `do_action("fs_lms_module_toggle_{id}")`) →
**вырезание** (каталог + строка в Init).

Карточка на Dashboard регистрируется фильтром `fs_lms_dashboard_modules`
(поля: `id`, `title`, `description`, `enabled`, `const_locked`, `const_key`).

Скрипты модуля: по умолчанию self-contained `assets/admin.js` (IIFE + `wp_enqueue_script`
только на своей странице, с гейтом `isEnabled()`); в core-бандл — только если UI пересекается
со страницами ядра. Стили аналогично; SCSS в core — с токенами из `_variables.scss`.

Целевая модульная архитектура всего плагина (распил ядра на Kernel/Content/Enrollment/Lms,
`ModuleInterface` + `ModuleManager`) — план, описанный в `.docs/ModularArchitecture.md`;
новый код пишем так, чтобы он в неё ложился: новая крупная фича — сначала вопрос
«лист или слой ядра?», по умолчанию — лист.

---

# Часть VII. Система обучения

## 27. Модель: контент vs факты

Главная идея всей подсистемы — два разных мира с разными хранилищами:

| Мир | Что это | Пример | Хранилище |
|---|---|---|---|
| **Контент** («что учим») | многоразовые шаблоны | задание, работа, урок, курс, контрольная | CPT + `post_meta['fs_lms_meta']` |
| **Факты** («что произошло в группе») | события конкретных людей | занятие, сдача, попытка, прогресс, посещаемость | собственные таблицы |

Правило, которое нельзя нарушать: **контент — только в CPT, факты — только в таблицах.**
Контент не копируется в группу — группа **ссылается** на него по ID. Поправил урок —
обновилось у всех, кто на него ссылается (с оговоркой про снапшоты, §31).

Цепочка банков (каждое звено ссылается на предыдущее):

```
задания (tasks) ──► работы (works) ──► уроки (lessons) ──► курсы (courses)
задачи (problems, глобальные) ──┘
контрольные (assessments) ── ссылаются на задания
```

## 28. Банки контента и CPT

### Per-subject CPT

Для каждого предмета `$key` регистрируется набор CPT. Имена — **только** через
`PostTypeResolver` (`Services/Subject/`), никакой конкатенации строк:

| Метод | CPT | Банк |
|---|---|---|
| `tasks($key)` | `{key}_tasks` | задания |
| `articles($key)` | `{key}_articles` | статьи (теория) |
| `works($key)` | `{key}_works` | работы |
| `lessons($key)` | `{key}_lessons` | уроки |
| `courses($key)` | `{key}_courses` | курсы |
| `assessments($key)` | `{key}_assessments` | контрольные |
| `problems()` | `fs_lms_problems` | глобальные задачи (один на систему, `ProblemsController`) |

Обратный разбор и проверки: `subjectFromTaskPostType()`, `isTaskPostType()`,
`isLessonPostType()`, … `isBankPostType()`.

Регистрация — в одном месте: `SubjectController::registerForSubject()` → 
`SubjectCPTRegistrar::addStandardType()`; конфиг каждого типа — `getDefaultCptArgs()`.
Общий блок `$bank_options` прячет банки из стандартного меню (`show_in_menu => false`)
и маппит права на кастомный capability-тип. Перед регистрацией каждого CPT прогоняется
фильтр `apply_filters('fs_lms_cpt_args', $args, $type, $subject)`.

Единая точка входа в скрытые банки — меню **«Обучение»** (`LearningMenuController`).

### Мета банков (в `fs_lms_meta`)

| Банк | `post_title` | `post_content` | Ключи меты |
|---|---|---|---|
| Задание | название | — | поля шаблона (§33) + `template_type` в `PostMetaName::TemplateType` |
| Работа | название | инструкция | `work_type`, `item_ids[]` (ссылки на задания/задачи) |
| Урок | тема | теория (inline) | `steps[]` (§29); `work_ids` — производное |
| Курс | название | описание | `modules[]` (§29); `lesson_ids` — производное |
| Контрольная | название | описание | `task_ids[]`, `kind`, `time_limit_minutes`, `max_attempts`, `pass_score`, `scoring_policy`, `shuffle`, `score_map`, `task_points` |

### Жизненный цикл контента

- `ContentUsageService` — «кто на меня ссылается» (usage-бейджи, пути использования —
  включая цепочку задание → контрольная → урок → курс).
- `ContentDeletionGuard` — запрещает удалить контент с `usage > 0`, предлагает «В архив».
- `ContentLifecycleService` — статусы `draft / publish / fs_archived`.
- `ContentCloneService` — `cloneLesson/Work/Assessment`, `cloneCourse(shallow|deep)`,
  `forkLessonForGroup` (мета `forked_from` / `forked_for_group`).

## 29. Курс: модули, уроки, шаги

Модель авторинга (в стиле Stepik):

```
Курс  = modules[]  → ModuleDTO { id, title, lessonIds[] }
Урок  = steps[]    → StepDTO   { key, type, payload }
```

- **`StepType`** (`Enums/Course/`): `text` (лекция), `video`, `task` (ссылка на задание),
  `work` (ссылка на работу), `assessment` (ссылка на контрольную).
  `isInline()` — контент в `payload`; `isRef()` — ссылка `payload['ref']` на CPT.
- `StepDTO::fromList()/toList()` и `ModuleDTO::…` — round-trip сериализация в мету.
- **Обратная совместимость доставки:** `LessonDTO::workIds()` и `CourseDTO::lessonIds()` —
  вычисляемые (обходят `steps[]` / `modules[]`), поэтому вся логика доставки просто
  спрашивает «работы урока»/«уроки курса», не зная про шаги.
- Настройки шага — `payload['settings']` (`max_attempts`, `shuffle`, `hint_after_errors`),
  гейт шага — `payload['gate']` (`none`/`sequential`/`after:<key>`).

Валидация при публикации: `CoursePublishValidator` обходит модули → уроки → шаги и не даёт
опубликовать курс с пустым шагом (text без content / video без url / ссылочный без ref);
черновики не блокируются.

## 30. Конструктор курса — admin-SPA

Редактирование курса целиком происходит в SPA-конструкторе, а не в нативном редакторе WP
(нативный редактор курса редиректится в конструктор).

### PHP-сторона

| Класс | Роль |
|---|---|
| `Controllers\Course\CourseBuilderController` | скрытая admin-страница `fs_lms_course_builder` + редирект нативного редактора курса |
| `Services\Course\CourseBuilderService` | read/write дерева «курс → модули → уроки → шаги» для JS |
| `Callbacks\Course\CourseBuilderCallbacks` | AJAX конструктора (структура, модули, уроки, мета) — регистрируется в `CourseController` |
| `Services\Course\LessonAuthoringService` | `buildSteps()` — валидация и санитайз шагов, генерация `key`; кандидаты для пикеров |
| `Services\Course\CourseAuthoringService`, `CourseNavService` | авторинг/навигация |

### JS-сторона (`src/js/admin/services/`)

- **`course-builder.js`** — сам SPA. Монтируется на `#fs-lms-course-builder`
  (`data-course-id`, `data-subject`). Слева дерево модулей/уроков (drag&drop, инлайн-правка
  названия курса, импорт урока из банка), справа — редактор выбранного урока.
  Все экшены — через `fs_lms_vars.ajax_actions` (`getCourseBuilder`, `createCourseDraft`,
  `saveCourseStructure`, `createLessonInModule`, `updateLessonMeta`, `saveCourseMeta`, …).
  Нативный submit формы `#post` заблокирован — сохранение только AJAX.
- **`step-editor.js`** / **`lesson-step-editor.js`** — редактор шагов урока: список шагов,
  инлайн-редакторы (TinyMCE для text, WP Media для video) и ссылочные редакторы с пикерами
  (`get_step_candidates`) + инлайн-создание черновиков (`create_{work|task|assessment|article}_draft`).
- **`course-persistence.js`** — сохранение/публикация (в т.ч. обработка ошибок
  `CoursePublishValidator`).
- **`task-editor.js` + `task-fields.js`** — inline-редактор задания (§18, §33).
- **`work-builder.js`**, **`assessment-builder.js`**, **`slot-builder.js`** — конструкторы
  работы/контрольной/слотов на их CPT-экранах.

### Как добавить AJAX-действие конструктора

Кейс в `AjaxHook` → метод в `CourseBuilderCallbacks`
(`authorize(Nonce::AuthorCourse…, Capability::AuthorLmsCourses)` + `Sanitizer`) → пара в
`CourseController::ajaxActions()` → вызов из `course-builder.js` через `acts().<camelCase>`.

## 31. Доставка: программа группы и доступ

### Программа группы

Назначенный курс лежит в `groups.course_id`. Строки программы — `fs_lms_group_lessons`
(§10): каждый ряд — «занятие» с позицией, датой, видимостью.

- `CourseAssignmentService` — назначить курс группе (снапшот уроков в `group_lessons`;
  политика `AssignmentPolicy: append/replace`).
- `ScheduleService` (`Services/Group/`) — добавить/убрать/переупорядочить/датировать занятия.
- `LessonVisibilityService` — `hidden/open/archived` + **copy-on-publish**: при первом
  открытии урока группе текущий список работ замораживается в `work_ids_snapshot` — дальнейшие
  правки эталонного урока не меняют уже открытый материал. Автооткрытие по дате — решение D1:
  видимость эффективная, `LessonAccessPolicy` учитывает дату занятия.
- `EffectiveWorksResolver` — эффективные работы занятия:
  `(snapshot, если открыт; иначе живые lesson.workIds()) + extra_work_ids`. Помни:
  `lesson_id` в занятии **nullable** (слот без темы) — всем потребителям нужен null-guard.

### Доступ и гейтинг

- `LessonAccessPolicy` → `AccessLevel` (`None/Read/ReadSubmit`) по матрице
  «видимость × статус зачисления × даты × политика отчисления»
  (`ExpulsionPolicyRepository`: `retain` — read-only после отчисления, `block` — закрыто).
- `LessonGateResolver` → `GateState` на уровне урока и шага (`payload['gate']`:
  `none`/`sequential`/`after:<key>`). **Первым** проверяется `ExamLockService` (§34) —
  активная экзаменационная попытка блокирует весь контент.
- `GroupAccessGuard` — управление группой (свой `teacher_id` или активная замена)
  и членство ученика.

### Кокпит группы `/group/?gid=N`

`GroupCockpitController` — страница преподавателя (программа, ростер, лента событий) и
ученика (его вид). AJAX кокпита — `fs_lms_cockpit_vars` / `fs_lms_submission_vars` (§15):
видимость уроков, переупорядочивание, расписание, настройки шагов (⚙️), история попыток (📋),
сдачи и журнал.

## 32. Плеер урока и прогресс

### Маршрут и рендеринг

Плеер ученика: `/group/?gid=N&gl=<id занятия>` → `LessonPlayerController`
(`template_include`, регистрируется до кокпита). Проверки: логин → занятие существует →
членство (`GroupAccessGuard::isMemberEver`); преподаватель пропускается в кокпит; посторонний
получает 404. Гейт урока (`LessonGateResolver`) → при `Locked` рендерится `locked.php`.

Шаблон `templates/frontend/lesson-player/player.php` — **изолированная страница** без темы:
серверные партиалы шагов (`partials/step-{text,video,task,work,assessment}.php`, `rail.php`)
рендерятся в скрытые панели `.pstep` с `data-step-type/status/gate`; весь «хром» строит JS.
Оба контроллера плеера взводят фильтр `fs_lms_is_player_route` → `Enqueue` грузит только
бандл `player` + MathJax и локализует `fs_lms_player_vars`
(`actions{markStep, submitTask, submitBatchWork}` + nonces).

**Preview-плеер** (для авторов): `/course-preview/?course=N&lesson=…` →
`CoursePreviewController` + `CoursePreviewService` + свой гейт `CoursePreviewAccessGuard`
(право `AuthorLmsCourses`, статус поста не важен). Тот же `player.php` в режиме
`preview` — без ученика, прогресса и сохранений.

### JS плеера (`src/js/player/`)

| Файл | Роль |
|---|---|
| `player.js` | entry: initShell → initCore → initRail → initStepTask/Work/Video |
| `core.js` | ядро: состояние из панелей `.pstep`, навигация (кнопки/лента/стрелки), deep-link на шаг, `mark(stepKey, status)` → AJAX `markStep` (в preview — no-op) |
| `shell.js` | оболочка: тосты, сворачивание сайдбара (localStorage), прогресс-бары |
| `rail.js` | дерево курса слева (пин в localStorage), дорисовка шагов текущего урока |
| `strip.js` | горизонтальная лента шагов с иконками/статусами |
| `icons.js` | мета и цвета типов шага (дублируются в `player/_variables.scss`) |
| `step-task.js` | шаг-задача: виджет ответа, мгновенная проверка (`SubmitTaskAnswer`), попытки, эталон после исчерпания |
| `step-work.js` | шаг-работа: стек карточек-задач, черновики в localStorage, пакетная сдача (`SubmitBatchWork`), экран результатов |
| `step-video.js` | шаг-видео: кастомный хром нативного `<video>` + главы; oembed-режим без хрома |

### Прогресс

`fs_lms_lesson_progress` (upsert по UNIQUE(person, group_lesson, step_key)):
инлайн-шаги отмечает плеер (`viewed` при показе, `completed` по «Далее»); статусы
work/assessment-шагов **не хранятся** здесь — резолвятся из fact-таблиц
(`LessonProgressService::getStepStatuses()`). AJAX `mark_step_progress` — без capability,
доступ по членству в группе (`LessonProgressController` → `LessonPlayerCallbacks`).

## 33. Типы задач и автопроверка

### Каталог (`Enums\Subject\TaskTemplate`)

Значение кейса — `template_type` в мете задания. Автопроверяемые: `standard_task`,
`triple_task` (шаблон `ThreeInOneTemplate` — три поля ответа; на ЕГЭ разворачивается
в отдельные под-задания), `common_standard_task`, `choice_task`, `matching_task`,
`ordering_task`, `fill_task` (пропуски `[[ответ|синоним]]`), `audio_task`; ручные:
`code_task`, `file_code_task`, `file_task`, `two_file_code_task`, `text_task`,
`file_answer_task`.

```php
$type = TaskTemplate::from( get_post_meta( $postId, PostMetaName::TemplateType->value, true ) );
```

### Автопроверщики

`TaskCheckerRegistry` (единственная точка входа) → `TaskCheckerInterface::check(content,
answer): CheckResultDTO { isCorrect, score, maxScore, itemFeedback }`. Чекеры в
`Services/Task/Checkers/`: Choice, Matching, Ordering, Fill, TextAnswer, TripleAnswer.
Нет чекера → ответ уходит на ручную проверку.

### Поток сдачи ответа (шаг-задача)

```
JS собирает ответ виджета → AJAX SubmitTaskAnswer { group_lesson_id, step_key, task_id, answer }
  → callbacks: verify nonce → лимит попыток (EffectiveStepSettingsResolver) 
  → TaskAttemptRepository::create() → чекер (если есть) → update вердикта
  → success { is_correct, score, max_score, item_feedback }
JS красит виджет (зелёный/красный, пофрагментная подсветка item_feedback)
```

Настройки шага двухуровневые: дефолт в `lesson.steps[].payload.settings` (правится
в конструкторе) → override в `group_lessons.step_settings_overrides` (правится в кокпите, ⚙️).
Мёржит их `EffectiveStepSettingsResolver` — только через него читаются настройки.

### Как добавить новый тип задачи

1. Кейс в `TaskTemplate`.
2. (Опц.) новый класс поля в `MetaBoxes/Fields/` (`editorType()` для JS-схемы).
3. Шаблон в `MetaBoxes/Templates/{PascalCase}Template.php` (`get_fields()`).
   `TemplateRegistry` найдёт его по имени автоматически.
4. JS-рендер поля: ветки в `_renderFields()`/`_collectFields()` в `task-editor.js`
   (если тип поля новый).
5. Виджет в плеере: view-модель в `LessonPlayerService`, HTML в партиале шага,
   сборка ответа в `frontend/components/task-widget.js`.
6. (Опц.) автопроверка: чекер в `Services/Task/Checkers/` + регистрация в
   `TaskCheckerRegistry`.

## 34. Работы и контрольные

### WorkType

`Enums\Course\WorkType`: `practice` / `independent` / `homework`. Задаётся на работе
(мета `work_type`), снапшотится в `submissions.work_type` при сдаче, влияет на
категоризацию в журнале и применимость дедлайна ДЗ.

### Пакетная сдача работы

`SubmissionService::submitBatch()`: доступ через `LessonAccessPolicy` →
`BatchCheckService::check()` прогоняет каждое задание через его чекер (задания без чекера →
`pending`, ручная) → пер-тасковые строки в `submissions` (`task_id IS NOT NULL`) + строка-агрегат
(`task_id IS NULL`, `score = верных / всего`). Ручная оценка задания —
`ajaxGradeBatchTask` → пересчёт агрегата; все пер-тасковые `graded` → агрегат `graded`.

### AssessmentKind

`Enums\Assessment\AssessmentKind`: `control` (обычная контрольная), `ege`, `ege_computer`.
Поведение — **только через предикаты** (не `match` по кейсу):

| Предикат | Что включает |
|---|---|
| `locksContent()` | `ExamLockService` блокирует весь контент ученика на время активной попытки (проверяется первым в `LessonGateResolver`) |
| `hidesAnswers()` | `ExamPayloadFilter` вырезает правильные ответы из payload плеера |
| `usesWeightedScore()` | баллы за задание из `task_points[id]` |
| `needsSecondaryScore()` | вторичный балл по таблице `score_map` |
| `expandsComposites()` | `ThreeInOne` → отдельные под-задания |
| `needsCompletenessCheck()` | `EgeCompletenessChecker` предупреждает о непокрытых номерах |

### Score map (перевод первичных баллов ЕГЭ)

Поле `score_map` на контрольной: вставляешь таблицу из ФИПИ/Excel → «Разобрать»
(`ScoreMapParser::parse()` понимает таб/`;`/`,`/двойной пробел, заголовки игнорирует) или
«Скопировать из другой работы». `SecondaryScoreService::translate(primary, map)` — точный
ключ → ближайший меньший → `null` вне покрытия.

### Журнал оценок

`GradebookService` не знает об источниках оценок: собирает их через `GradeSourceRegistry`
(`GradeSourceInterface`; сейчас — `SubmissionGradeSource`, `AssessmentGradeSource`).
`GradebookEntryDTO.display_type`: `fraction` («5/8»), `score` («72», для ЕГЭ — вторичный),
`pending` («На проверке»); в JS используется готовое `display_value`.

### Свой плеер для типа контрольной

Фильтр `fs_lms_assessment_renderer` (`AssessmentPageController::RENDERER_FILTER`):
верни путь к своему шаблону для нужного `kind` — иначе дефолтный `attempt.php`.
Эталон — модуль `EgeComputer` (шаблон `templates/frontend/assessment/ege-computer.php`).
Новый тип контрольной: кейс в `AssessmentKind` с предикатами (+ при необходимости
модуль с плеером).

## 35. Расписание, КТП, посещаемость, замены, кабинеты

Это самый свежий пласт (этап «Личные кабинеты»). Спека и развилки — `.docs/Courses.md`.

### Расписание-шаблон и календарь

- `groups.meetings` (JSON) — недельный шаблон занятий группы; нормализует его
  `MeetingsNormalizer`.
- Учебный период (`fs_lms_academic_periods`, wp_options) — границы + каникулы (`holidays[]`).
- `SessionCalendarService`:
  - `generate(groupId)` — разворачивает `meetings × период − каникулы` в датированные слоты;
    время слота берётся из расписания группы (не хардкод);
  - `reflow(groupId)` — раскладывает темы (`group_lessons.position`) по слотам **по порядку**;
    закреплённые вручную (`is_pinned`) держат свою дату, остальное переразливается вокруг.
- Привязка тем «по порядку, а не по дате» — отмена занятия/праздник сдвигают хвост сами.

### Занятие (`fs_lms_group_lessons`) как центр фактов

`kind = group` — обычное занятие; `kind = individual` + `student_person_id` — индивидуальное
занятие (создаётся из ростера/КТП, режим «Индивидуальные» в КТП использует sentinel
`INDI_ID = -1`). `status`: `scheduled/held/cancelled/moved`. `continued_from_id` — продолжение
темы. Дедлайны работ занятия — `work_deadlines` (JSON). `program_locked_at` на группе —
блокировка КТП после публикации.

### Посещаемость

`fs_lms_attendance`: одна запись на (занятие, ученик), `is_present` + кто/когда отметил.
`AttendanceService` — bulk-операции («все присутствуют, флипнуть исключения»).
UI — экран «Журнал» кабинета (§37): ячейка = посещаемость + результаты работ по типам.

### Замены преподавателя

`groups.teacher_id` **никогда не перезаписывается** — замена это данные:
`fs_lms_substitutions` (`valid_from..valid_to`, кто назначил). Кто ведёт занятие в дату D,
резолвит `EffectiveTeacherResolver`: разовый override занятия → активная замена → владелец
группы. `GroupAccessGuard::canManage()` учитывает активные замены (time-bound доступ:
истёк `valid_to` — доступ погас сам). Управление — экран «Замены» кабинета (только офис,
`Capability::ManageSchedule`), AJAX `SubstitutionController`.

### Кабинеты (аудитории)

`fs_lms_rooms` + `RoomRepository`; `RoomAssignmentService` / `RoomAvailabilityService`
(свободные кабинеты на слот). Привязка: `groups.room_id` (постоянный) и
`group_lessons.room_id` (override занятия, в т.ч. при замене). Справочник — вкладка
настроек `settings-9-rooms.php`, AJAX — `RoomController`.

---

# Часть VIII. Личный кабинет /profile/ — SPA

## 36. Архитектура кабинета

Кабинет — одностраничное приложение (SPA) на чистом JS, полностью изолированное от темы
и остальных бандлов.

```
/profile/  (PageRoutes::UserProfile)
   │  ProfileController: template_redirect (логин/роль/политика) + template_include
   ▼
templates/frontend/profile.php        ← свой <!DOCTYPE html>, без темы; только каркас:
   sidebar (#profNav, #profUser) + топбар + пустая сцена #profStage + оверлеи/тост
   │  Enqueue::enqueue_profile_assets(): profile.min.css + profile.min.js
   │  + wp_localize_script('fsProfile', ProfileViewResolver::jsConfig($userId))
   ▼
src/js/profile/profile.js → app.js: строит сайдбар, генерит экраны, монтирует их
```

### PHP: ProfileViewResolver (`Services/Profile/`)

- `context($wpUserId)` → `ProfileContext { wpUserId, personId, role, subjectPersonId,
  readOnly, children }`. Роль — `UserRole::primaryForCabinet()`. Родитель: `readOnly = true`,
  `children` — его дети, `subjectPersonId` — выбранный ребёнок.
- `viewFor(role)`: `FSTeacher | FSOffice` → `TeacherProfileView`;
  `FSStudent | FSParent | Student` → `LearnerProfileView`; прочие (методист, маркетолог) →
  `null` → редирект в wp-admin.
- `jsConfig($userId)` собирает весь `window.fsProfile`:
  - общая часть: `role`, `readOnly`, `user{name, initials}`, `subjectPersonId`, `children`,
    `nav` (пункты меню), `screens` (какие экраны монтировать), `ajax{url}`, `homeUrl`,
    `logoutUrl`;
  - препод/офис: `groups`, `coursesTaught`, `coursePreviewUrl` + конфиг-блоки экранов
    `{nonce, actions}`: `schedule` (КТП), `courses`, `journal`, `roster`, `summary`,
    `review`, `attemptGrade`, `dashboard`; **только офису** — `substitutions`;
  - ученик/родитель: блок `learner` (`actions{getProfile}` — один эндпоинт отдаёт всё).

Витрины (`TeacherProfileView`, `LearnerProfileView`) реализуют `ProfileViewInterface::build()`
и решают, какие экраны и пункты меню есть у роли.

### JS: app.js

- **Реестр экранов** `SCREENS = { key → renderer(root, handlers) }` — каждый экран
  экспортирует функцию `renderX(root)` из своего модуля.
- Экраны — секции `<section class="prof-screen" data-screen="…">`, все монтируются при
  старте; навигация `go(screen)` просто переключает класс `.active` (никакого хеш-роутинга).
- `buildSidebar()` — меню из `cfg.nav`, сворачиваемые секции «Мои группы» (`cfg.groups`)
  и «Мои курсы» (`cfg.coursesTaught`, с поиском), блок пользователя.
- Deep-link: `?screen=learner-lessons` в URL открывает конкретный экран (так плеер
  возвращает в «Мои курсы»).
- Клик по группе в сайдбаре открывает ростер (`openGroupsFor`) / журнал (`openJournalFor`)
  с установкой группы экрана; клик по курсу — preview-плеер (`cfg.coursePreviewUrl`).

Утилиты: `utils.js` (тосты, форматтеры, контекстные меню, grade-pop), `constants.js`
(палитры, дни недели, месяцы — единственный источник), `picker.js` (общие пикеры
группы/ученика).

## 37. Экраны кабинета

### Преподаватель / офис (`TeacherProfileView`)

| Экран (`data-screen`) | Файл | Что делает | Конфиг-блок / actions |
|---|---|---|---|
| `dashboard` «Главная» | `dashboard.js` | Кросс-групповой агрегат: расписание сегодня/неделя, ворклист «заполнить/проверить», стат-плитки, маркеры замен | `dashboard{getDashboard}` |
| `journal` «Журнал» | `journal.js` | Сетка ученики × занятия: посещаемость + оценки по типам (СР/ПР/ДЗ/КР/ЭКЗ) | `journal{getJournal, saveAttendance, bulkAttendance}` |
| `summary` «Сводка по ученику» | `summary.js` | Срез по одному ученику + проверка работ и оценивание попыток | `summary{getRoster, getSummary}`, `review{getDetail, saveGrade, returnSubmission}`, `attemptGrade{gradeAttempt}` |
| `ktp` «КТП и расписание» | `ktp.js` | Банк тем + календарь: drag → `pin`, «Распределить» → `reflow`, публикация КТП, дедлайны, индивидуальные занятия (`INDI_ID = -1`) | `schedule{getCalendar, reflow, pin, getProgram, publish/unpublish, get/saveDeadlines, continue, getIndividual, lessonCandidates, assignLesson}`, `courses{getCourses, assignCourse}` |
| `groups` (ростер; без пункта меню — вход кликом по группе) | `groups.js` | Состав группы, создание индивидуального занятия, свободные кабинеты | `roster{getRoster, createIndividual, getFreeRooms}` |
| `substitutions` «Замены» (только офис) | `substitutions.js` | Назначить/снять замену, override кабинета | `substitutions{getData, assign, revoke, setRoom}` |

### Ученик / родитель (`LearnerProfileView`)

Экраны `learner-home` (Главная: расписание, дедлайны, оценки), `learner-lessons`
(«Мои курсы» — вход в плеер), `learner-grades`, `learner-attendance` — все в `learner.js`,
данные одним запросом `learner.getProfile`. Родитель видит то же по выбранному ребёнку
(`fsProfile.children` + `subjectPersonId`), всё read-only (`fsProfile.readOnly`).

### PHP-сторона AJAX кабинета

Контроллеры: `ProfileDashboardController`, `JournalController`, `ScheduleController`,
`SubstitutionController`, `RoomController`, `LearnerProfileController`, `SubmissionController`
(проверка), `AssessmentController` (оценка попыток) → соответствующие Callbacks →
сервисы (`DashboardService`, `JournalService`, `StudentSummaryService`, `ReviewQueueService`,
`SubstitutionService`, `SessionCalendarService`, …).

## 38. Сетевой шов FS_LMS_API

`src/js/profile/api.js` — **единственное** место кабинета, знающее про транспорт
(admin-ajax + nonce). Экраны сами `fetch` не делают.

```js
// низкоуровневый контракт (можно подменить в рантайме):
FS_LMS_API.request( action, nonce, params )
//   POST admin-ajax.php, x-www-form-urlencoded: { action, security: nonce, ...params }
//   credentials: 'same-origin'
//   { success:true, data }  → вернуть data
//   { success:false, data } → throw Error(data.message ?? data)

// фабрика для экрана: блок конфига → функция api()
import { createApi } from './api.js';
const api  = createApi( window.fsProfile.journal );   // { nonce, actions }
const data = await api( 'getJournal', { group_id: 1 } ); // actionKey → actions[…] + nonce
```

Транспорт вызывается **через объект** (`FS_LMS_API.request`), поэтому его можно подменить
без пересборки: `window.FS_LMS_API.request = …` — все экраны подхватят (основа переноса
в Telegram/мобилку, §40).

> **Правило:** новый экран кабинета ходит на бэкенд **только** через `createApi`/`FS_LMS_API`.
> Прямой `fetch`/`XMLHttpRequest` в экране запрещён — иначе теряется единая точка выноса.

Полный контракт — `.docs/FS_LMS_API.md` §7.

## 39. Как добавить новый экран кабинета

PHP:

1. AJAX по стандартному пути (§7): кейсы `AjaxHook`, контроллер (+`Init`), callbacks
   (для кабинета обычно `Nonce::X->verify()` + проверка членства/владения, либо
   `authorize` с capability для препода/офиса), сервис.
2. Конфиг-блок экрана в `ProfileViewResolver` (`teacherConfig()` или `jsConfig()`):
   `'myScreen' => [ 'nonce' => Nonce::X->create(), 'actions' => [ 'getData' => AjaxHook::X->jsAction(), … ] ]`.
3. Экран и пункт меню — в витрину роли (`TeacherProfileView::build()` / `LearnerProfileView`):
   ключ в `screens`, запись в `nav`.

JS:

4. `src/js/profile/my-screen.js`: `export function renderMyScreen( root ) { … }`,
   данные — только через `createApi( window.fsProfile.myScreen )`.
5. Регистрация в `app.js`: импорт + запись в `SCREENS`, подпись в `TOPBAR`, иконка
   в `NAV_ICONS`.
6. Стили — `src/scss/profile/components/_my-screen.scss` + `@use` в `profile.scss`
   (токены из `profile/_variables.scss`).
7. `npx gulp scripts && npx gulp styles:profile`.

## 40. Перенос кабинета в Telegram / мобильное приложение

Кабинет спроектирован под вынос в Telegram Web App / webview-обёртку без переписывания логики:

- логика уже отвязана от транспорта (Services/Repositories переиспользуются целиком);
- фронт фреймворк-независим и читает один объект `window.fsProfile`;
- транспорт подменяем в одну строку (`FS_LMS_API.request`).

Закрыть надо три шва — и все три строятся **модулями**, не правкой ядра:

1. **Авторизация**: Telegram `initData` (HMAC от токена бота) или токен → маппинг на
   WP-пользователя (модуль по образцу SocialAuth/AdSync);
2. **Доставка конфига**: bootstrap-эндпоинт, отдающий `ProfileViewResolver::jsConfig()`
   как JSON (сейчас он инъектится в HTML);
3. **Транспорт**: REST-фасад, зеркалящий те же actions в те же Callbacks/Services.

Чего делать не надо: дублировать логику в REST-контроллерах, хардкодить транспорт
в экранах, тащить внешнюю авторизацию в ядро. Подробный план и пример подмены
`request` — `.docs/FS_LMS_API.md` §7.

---

# Часть IX. Практика

## 41. Troubleshooting

Пошаговая диагностика по цепочке. Нашёл разрыв — чини именно этот узел.

```
[JS] обработчик вызвался? XHR ушёл? → [admin-ajax] action/nonce верны? хук зарегистрирован?
→ [Callback] authorize прошёл? параметры пришли? → [Service/Repo] SQL без ошибок? → [БД]
```

### Шаг 1 — запрос не уходит с клиента

Console на JS-ошибки → `console.log` в начале обработчика → гейт на DOM-элемент существует?
→ `npx gulp scripts` после правок → скрипт вообще подключён на этой странице?
(в админке — флаги в `Enqueue::enqueue_admin_assets()`, §15).

### Шаг 2 — сервер отвечает `0`, `-1` или 403

| Ответ | Причина |
|---|---|
| `0` | хук не зарегистрирован: кейса нет в `AjaxHook`, пары нет в `ajaxActions()`, контроллера нет в `Init::getServices()` |
| `-1` | nonce не прошёл: сравни, что кладёт `Enqueue` и что проверяет `authorize()`/`verify()` |
| `success:false` + 403 | не та capability в `authorize()` или у роли нет права (проверь `UserRole::capabilities()` и `fs_lms_caps_version`) |

### Шаг 3 — callback работает, но в БД ничего нет

- Точка отладки: `$this->success(['debug' => $data])` сразу после санитизации.
- `$wpdb->last_error` после insert/update → `PluginLogger::debug()` → хвост debug.log.
- `TransactionRunner`: исключение внутри замыкания молча откатывает — оберни в try/catch
  и залогируй.
- Конечный автомат: `ApplicationStatus::canTransitionTo()` вернул `false` → операция
  пропущена; проверяй результат и возвращай ошибку пользователю.
- Помни про `Sanitizer`: `requireKey('subject_key')` принимает **имя ключа**,
  не `$_POST['subject_key']` — эта ошибка однажды уронила 65 AJAX-путей.

### Шаг 4 — данные в БД есть, но UI показывает старое

`docker restart wp_app` (OPcache) → transients
(`DELETE FROM wp_options WHERE option_name LIKE '_transient_fs_lms_%'`) → hard refresh
после пересборки JS.

### Шаг 5 — PII пусто, хотя в таблице что-то лежит

- Читай через `PersonReader::read($id, $fields, $reason)` — сырое `*_enc` поле это шифроблоб.
- Проверь, что берёшь правильный `person_id` (родитель ≠ ученик; `parent_person_id`
  заполняется только после сабмита родительской формы — до этого `NULL`, и это норма
  для `pending_parent`).
- Ключи: `FS_LMS_ENC_KEY` задан и не менялся после записи данных (сменился ключ —
  старые данные не расшифруются).

### Быстрая шпаргалка

| Симптом | Куда смотреть |
|---|---|
| Кнопка молчит | Console; гейт init(); сборка |
| Ответ `0` / `-1` | регистрация хука / nonce |
| `success:true`, но не сохранилось | `$wpdb->last_error`; статусный автомат; транзакция |
| PII пустой | `PersonReader`; правильный person_id; `FS_LMS_ENC_KEY` |
| PHP-правка «не применилась» | `docker restart wp_app` |
| Стили/JS не грузятся на новом экране | гейты в `Enqueue` (§15) |
| Урок в плеере «заблокирован» | `LessonGateResolver`: экзамен-лок? дата? гейт шага? |
| Настройка шага «не действует» | читаешь оба уровня через `EffectiveStepSettingsResolver`? |

### Инструменты

```php
PluginLogger::debug( 'MyCallback', 'checkpoint A', [ 'id' => $id ] );  // WP_DEBUG
```

```bash
docker exec wp_app tail -15 /var/www/html/wp-content/debug.log
docker exec wp_db mariadb -u root -proot wordpress -e "SELECT ... FROM wp_fs_lms_...;"
docker compose run --rm wpcli wp option get fs_lms_schema_version
```

## 42. Карта остальной документации

| Файл | Что там |
|---|---|
| `.docs/Courses.md` | Спека этапа «Личные кабинеты»: доменная модель занятий/посещаемости/замен, экраны, развилки решений |
| `.docs/FS_LMS_API.md` | §1–6 — REST-контракт модуля AdSync (HMAC, эндпоинты); §7 — контракт клиентского шва `FS_LMS_API` и план выноса кабинета |
| `.docs/ModularArchitecture.md` | Целевая модульная архитектура (Kernel/Content/Enrollment/Lms + листья) и правила для нового кода |
| `.docs/Roles.md` | Целевая модель ролей/прав (частично уже в коде: `AuthorLmsCourses`, `ManageLmsTeaching`…) |
| `.docs/UI.md` | Дизайн-система админки: примитивы `.fs-btn`/`.fs-card`/`.fs-field`, правила токенизации, ход консолидации |
| `.docs/Tasks.md` | Журнал задач/фаз с разборами первопричин (фазы 1–11 закрыты) |
| `.docs/patterns.md` | Каталог SOLID/паттернов на примерах плагина. **Осторожно: местами устарел** (примеры на легаси `StudentGroup*`; сервисов давно не 13) |
| `CLAUDE.md` | Правила кодовой базы для AI-ассистента (совпадают с правилами этого документа) |

> Если этот документ противоречит коду — прав код; поправь документ в том же PR.

