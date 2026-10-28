# Этап 1. Каркас модуля

Зависимости: этап 0 (швы существуют, сканер границы зелёный).
Результат: `MasterclassModule` в `Init::getServices()`, схема из трёх таблиц, конфиг, энумы, DTO, три репозитория, писатель журнала,
вкладка настроек, карточка Dashboard, подстраница-заглушка. Мастер-классов создавать ещё нельзя.

Перед началом прочитать `README.md`, `../CONTRACTS.md` §1, §3–§9 (контроллеры) и образцы: `inc/Modules/VideoLibrary/` (`VideoSchema`,
`VideoLibrarySettingsController`), `inc/Modules/AdSync/` (`AdSyncModule`, `AdSyncCronController`), `AbstractExamRepository`.

**Порядок:** 1.1 → 1.2 → 1.3 → 1.4 → 1.5 → 1.6 → 1.7.

## Статус

**Готов и проверен 2026-10-28** (код не закоммичен). Итоги и проверки — в конце файла («Итоги этапа»). **Переписан по факту этапа 0 (2026-10-28):**

- Швы готовы и покрыты тестами; подписчикам нужны сигнатуры из `CONTRACTS.md` §2 (раздел «Сделано» у каждого шва). Ш1 — `add_filter( …, 10, 3 )`; Ш6 —
  `add_filter( …, 10, 4 )`; Ш3 — `add_action( …, 10, 2 )`; Ш4 — один аргумент; Ш5 — один; плеер — экшены `( int $userId, GroupLessonDTO $row )`.
- **Ш3 и Ш4 регистрируются «всегда»** (решено): `MasterclassIntegrityController` держит четыре подписки — Ш1, Ш3, Ш4 и `deleted_user`.
- Тестовые заглушки в `tests/bootstrap.php` (для тестов модуля): `apply_filters` возвращает `$GLOBALS['_fs_test_filter_returns'][хук]`, а при
  `_fs_test_record_filters` пишет вызовы в `_fs_test_filter_calls`; `do_action` пишет в `_fs_test_actions` и бросает `_fs_test_action_throws[хук]`;
  `add_filter`/`add_action` — no-op. Подписчика, то есть **сам метод контроллера, тестировать вызовом напрямую**, а не через хук.
- В БД `group_id` — `smallint unsigned` (≤ 65 535, больший молча обрезается): в тестах на `wp_db` брать `group_id` ≤ 65 535.
- Локальный запуск PHPUnit: `php -d extension_dir=D:/Site/php/ext -d extension=sodium -d extension=fileinfo -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit`;
  проверки на БД и DI — в контейнере (`docker cp` файла-скрипта под префиксом `zz_mk_`, запуск, `docker exec wp_app rm -f` по точному имени).
- Тест границы `ModuleBoundaryTest` уже есть и зелёный: `inc/Modules/Masterclass/` он пропускает, остальное ядро — нет.

---

## 1.1 Схема

- [x] `Schema\MasterclassSchema`: `VERSION_OPTION = 'fs_lms_masterclass_schema_version'`, `VERSION = '1'`; `masterclasses()`, `accounts()`, `log()`
  — имена таблиц; `ensure()` — `dbDelta` трёх DDL по `CONTRACTS.md` §3 (колонки и ключи берутся оттуда, **не выдумываются**), только
  переносимый SQL (без `IF NOT EXISTS` у колонок), «два пробела» перед скобкой после `PRIMARY KEY`, каждая колонка в своей строке.
- [x] Тест на MariaDB: `ensure()` на чистой базе создаёт три таблицы; повторный вызов — без ошибок и без изменений (`SHOW CREATE TABLE`).

**Готово, когда:** обе проверки зелёные на `wp_db`, `uninstall.php` по префиксу `fs_lms_` покрывает таблицы (проверить чтением).

## 1.2 Конфигурация и энумы

- [x] `Config\MasterclassConfig extends ModuleConfig` (`OPTION = 'fs_lms_masterclass'`, тумблер `FS_LMS_MASTERCLASS`, ключи и границы —
  `SPEC.md` §6, `bounded()` как в `PluginConfig`). В core `OptionName` и `PluginConfigRepository` ничего не добавляется.
- [x] Энумы §5 `CONTRACTS.md`: `MasterclassAccountState`, `LoginPlace`, `MasterclassContactKind`, `AccountDeleteReason`, `MasterclassLogAction`
  (значения — snake_case, **не менять**; в том числе `LoginsUnlocked`).
- [x] Тесты: границы всех ключей (ниже/выше → ближайшая граница, мусор → по умолчанию — как `PluginConfig::bounded()`), `endTime` позже `startTime`, `fromRequest()`, `label()` у каждого кейса.

**Готово, когда:** тесты зелёные.

## 1.3 DTO

- [x] DTO §6 `CONTRACTS.md` (`readonly class`): `MasterclassDTO` (`isFrozen`, `isRunning`, `isPurged`), `MasterclassInputDTO`, `MasterclassAccountDTO`,
  `MasterclassViewDTO`, `MasterclassFlyerDTO`, `MasterclassFunnelDTO`, `MasterclassLogInputDTO`, `MasterclassLogDTO`.
- [x] Тесты: `fromArray()` на строках с `NULL`; `isFrozen`/`isRunning` на границах (`now == freeze_at`, `now == starts_at`, `now == ends_at`).

**Готово, когда:** тесты зелёные.

## 1.4 Репозитории

- [x] `MasterclassRepository`, `MasterclassAccountRepository` (включая `liveUserIds()`), `MasterclassLogRepository` — все методы §7, `extends
  AbstractExamRepository`, ошибка БД = исключение. Условные `UPDATE … IS NULL` (`markFirstLogin/markHome/markContact/markFrozen`) возвращают `bool`.
- [x] Тесты **на MariaDB**: `insertPending()` отдаёт возрастающий `id` (= `N`) и после `markDeleted()` номер не повторяется; `markFirstLogin()` второй раз
  `false`; `markFrozen()` один раз при двух вызовах; `listDue*` не выбирают уже обработанное; `funnel()` считает и удалённые.

**Готово, когда:** тесты на `wp_db` зелёные, данные тестов убраны по точным id.

## 1.5 Писатель журнала

- [x] `Services\MasterclassLogWriter` (`write( MasterclassLogAction, array )`, подпись «Ученик#N» по `account_id`, IP — из `RequestContextProvider`);
  сбой записи гасится (`PluginLogger::exception( 'MasterclassLog', …, true )`). Пароли и ПД не пишутся.
- [x] Тесты: ключи `$data`, подпись, сбой репозитория не бросает, в контекст не попадает пароль.

**Готово, когда:** тесты зелёные.

## 1.6 Контроллеры и настройки

- [x] `MasterclassModule implements ServiceInterface` — порядок `register()` по `CONTRACTS.md` §9 (схема **до** любых зависящих от таблиц
  регистраций, в том числе при выключенном модуле); строка в `Init::getServices()` рядом с `VideoLibraryModule` (+ `use`).
- [x] `MasterclassSettingsController` (всегда): `SAVE_ACTION`, вкладка `fs_lms_settings_tabs` (только при включённом), карточка `fs_lms_dashboard_modules`
  (`const_key FS_LMS_MASTERCLASS`), `fs_lms_module_toggle_masterclass`; `MasterclassSettingsCallbacks::ajaxSave()` (`Nonce::Config` +
  `ManageLmsPlatform`, ввод через `Sanitizer`); `templates/settings-tab.php`.
- [x] `MasterclassIntegrityController` (всегда): четыре подписки — Ш1 `fs_lms_lesson_access` (пока проход: `MasterclassAccessGuard` приходит в этапе 3), `deleted_user`
  (обработчик в этапе 4), Ш4 `fs_lms_service_group_ids` (готовится уже здесь: подписчик читает `MasterclassRepository::activeGroupIds()` напрямую — `MasterclassService::serviceGroupIds()` не нужен, кеш на запрос есть в `ServiceGroupResolver`),
  Ш3 `fs_lms_group_deleting` (обработчик в этапе 4). **Не регистрировать подписку на метод, которого ещё нет** — недописанные точки оставить за этапом их появления,
  но Ш4 сделать сейчас: без неё группа, созданная в этапе 2, попадёт в списки зачисления.
- [x] `MasterclassAdminController` (минимум): подстраница «FS LMS → Мастер-классы» под `Menu::Main` через `MenuManager::register()`,
  `ManageLmsPlatform`, шаблон-заглушка с заголовком; без AJAX.
- [x] Тесты: `MasterclassModuleTest` — выключен: зарегистрированы только настройки/интегрити (Ш1, Ш3, Ш4, `deleted_user`); включён — ещё и админ-контроллер.
  Тест Ш4: при выключенном модуле `serviceGroupIds()` по-прежнему отдаёт группы (читается таблица, не флаг модуля). Коллбек настроек:
  нет права / неверный nonce → отказ; значения вне границ приводятся; сохраняются только присланные ключи.

**Готово, когда:** тесты зелёные; на стенде модуль включается тумблером и константой, вкладка и пункт меню появляются/пропадают.

## 1.7 Документы модуля

- [x] `inc/Modules/Masterclass/CLAUDE.md` — правила `CONTRACTS.md` §13; абзац в корневом `CLAUDE.md`, раздел «Модули».

**Готово, когда:** правила записаны, ссылки на классы существуют.

---

## Проверки этапа

PHPUnit полностью; **DI-загрузка с модулем включённым и выключенным**; PHPStan уровня 5 по `inc/Modules/Masterclass/` и правленым файлам ядра;
`npx eslint .`; `npx stylelint`; `npx gulp build`; схема и репозитории — на `wp_db`; `grep -rni masterclass inc --exclude-dir=Modules` пуст
(сканер этапа 0 зелёный). Затем **переписать этап 2**.

## Итоги этапа (2026-10-28)

**Сделано:** `MasterclassModule` (+ строка в `Init::getServices()`), `MasterclassSchema` (3 таблицы), `MasterclassConfig` (+ `normalize()`), 5 энумов, 8 DTO,
3 репозитория, `MasterclassLogWriter`, `MasterclassSettingsController` + `MasterclassSettingsCallbacks` + `templates/settings-tab.php` + `assets/settings.js`,
`MasterclassIntegrityController` (пока Ш4), `MasterclassAdminController` + `templates/admin-page.php` (заглушка), `inc/Modules/Masterclass/CLAUDE.md`, абзац в корневом
`CLAUDE.md` и `AGENTS.md`. Граница: `ModuleBoundaryTest` теперь разрешает единственное упоминание — строки с `MasterclassModule` в `inc/Init.php`.

**Проверено:**
- PHPUnit в контейнере целиком: 4356 тестов, ошибок и падений нет (на этапе 0 было 4205; модуль — 153 теста, из них 39 на дубле wpdb). Предупреждения (`GroupLessonDTO` «Undefined array key
  position», 4 шт.) — старые, не из модуля.
- Мутации (18 штук: условные `UPDATE`, границы `isFrozen` / `isRunning` / конфига, порядок `register()`, сбой Ш4, пароли в журнале и др.) — все убиты. Отдельно: возврат ключа `data` вкладки к `config` роняет рендер-тест.
- **Настоящая MariaDB** (`tests/stand/masterclass-schema-check.php`): `ensure()` создаёт таблицы, повторный `dbDelta` ничего не меняет, типы (`group_id smallint unsigned`), `UNIQUE group_id` / `user_id`,
  `markFrozen` / `markFirstLogin` / `markDeleted` — один раз, номер удалённого аккаунта не повторяется, очереди тика (`listDueFor*`), `runningFor`, воронка, страницы, журнал. Настоящие таблицы не тронуты.
- DI: `tests/stand/di-boot-check.php on|off` — 107 из 107 сервисов собираются; при выключенном модуле есть подписка Ш4, настройки и карточка Dashboard, нет вкладки и пункта меню; при включённом — есть.
- PHPStan уровня 5 по `inc/Modules/Masterclass/` и `inc/Init.php`: на моих строках только «`dbDelta` not found» (функция WP из `upgrade.php`, шум окружения).
- `npx eslint .` — чисто. SCSS и `src/js` не менялись, поэтому `stylelint` и `gulp build` не гонялись.
- **По HTTP на dev-стеке** (cookie администратора + nonce): вкладка «Мастер-классы» рендерится, скрипт и `fsLmsMasterclassSettings` на месте; сохранение, клампы, отказы по времени и ссылке
  (`javascript:`, `ftp:`, текст), 403 без nonce; страница модуля и пункт меню — при включённом, 403 и нет пункта — при выключенном; тумблер Dashboard `fs_lms_toggle_module` включает и выключает. Опция dev возвращена в прежнее состояние.
- **Не проверено:** вид вкладки в браузере (скриншот), клик по «Сохранить» в настоящем JS (проверен только HTTP-запрос тем же набором полей), `gulp build` (не нужен — исходники не менялись).

**Найденные ошибки (в правило модуля и тест):**
1. Вкладка падала на живой странице: `settings.php` держит `$config` — массив настроек плагина, а `extract( $tab['data'], EXTR_SKIP )` существующую переменную не перезапишет; шаблон модуля получал массив. Юнит-тест этого не видел, поймал только HTTP.
   Теперь ключ данных `masterclass_config`, рендер-тест повторяет механизм страницы настроек. Правило — в `inc/Modules/Masterclass/CLAUDE.md`.
2. Строка `"«$name»"`: `»` — часть имени переменной в PHP; в тестах с русским текстом нужно `{$name}`. Правило — там же.

**Для этапа 2:** см. переписанный `stage-02.md` (имена готовых классов, что уже есть, что оказалось лишним).
