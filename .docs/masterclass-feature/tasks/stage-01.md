# Этап 1. Каркас модуля

Зависимости: этап 0 (швы существуют, сканер границы зелёный).
Результат: `MasterclassModule` в `Init::getServices()`, схема из трёх таблиц, конфиг, энумы, DTO, три репозитория, писатель журнала,
вкладка настроек, карточка Dashboard, подстраница-заглушка. Мастер-классов создавать ещё нельзя.

Перед началом прочитать `README.md`, `../CONTRACTS.md` §1, §3–§9 (контроллеры) и образцы: `inc/Modules/VideoLibrary/` (`VideoSchema`,
`VideoLibrarySettingsController`), `inc/Modules/AdSync/` (`AdSyncModule`, `AdSyncCronController`), `AbstractExamRepository`.

**Порядок:** 1.1 → 1.2 → 1.3 → 1.4 → 1.5 → 1.6 → 1.7.

## Статус

Не начат. *(Переписать по факту этапа 0: номера версий, имена, что оказалось лишним.)*

---

## 1.1 Схема

- [ ] `Schema\MasterclassSchema`: `VERSION_OPTION = 'fs_lms_masterclass_schema_version'`, `VERSION = '1'`; `masterclasses()`, `accounts()`, `log()`
  — имена таблиц; `ensure()` — `dbDelta` трёх DDL по `CONTRACTS.md` §3 (колонки и ключи берутся оттуда, **не выдумываются**), только
  переносимый SQL (без `IF NOT EXISTS` у колонок), «два пробела» перед скобкой после `PRIMARY KEY`, каждая колонка в своей строке.
- [ ] Тест на MariaDB: `ensure()` на чистой базе создаёт три таблицы; повторный вызов — без ошибок и без изменений (`SHOW CREATE TABLE`).

**Готово, когда:** обе проверки зелёные на `wp_db`, `uninstall.php` по префиксу `fs_lms_` покрывает таблицы (проверить чтением).

## 1.2 Конфигурация и энумы

- [ ] `Config\MasterclassConfig extends ModuleConfig` (`OPTION = 'fs_lms_masterclass'`, тумблер `FS_LMS_MASTERCLASS`, ключи и границы —
  `SPEC.md` §6, `bounded()` как в `PluginConfig`). В core `OptionName` и `PluginConfigRepository` ничего не добавляется.
- [ ] Энумы §5 `CONTRACTS.md`: `MasterclassAccountState`, `LoginPlace`, `MasterclassContactKind`, `AccountDeleteReason`, `MasterclassLogAction`
  (значения — snake_case, **не менять**; в том числе `LoginsUnlocked`).
- [ ] Тесты: границы всех ключей (ниже/выше/мусор → по умолчанию), `endTime` позже `startTime`, `fromRequest()`, `label()` у каждого кейса.

**Готово, когда:** тесты зелёные.

## 1.3 DTO

- [ ] DTO §6 `CONTRACTS.md` (`readonly class`): `MasterclassDTO` (`isFrozen`, `isRunning`, `isPurged`), `MasterclassInputDTO`, `MasterclassAccountDTO`,
  `MasterclassViewDTO`, `MasterclassFlyerDTO`, `MasterclassFunnelDTO`, `MasterclassLogInputDTO`, `MasterclassLogDTO`.
- [ ] Тесты: `fromArray()` на строках с `NULL`; `isFrozen`/`isRunning` на границах (`now == freeze_at`, `now == starts_at`, `now == ends_at`).

**Готово, когда:** тесты зелёные.

## 1.4 Репозитории

- [ ] `MasterclassRepository`, `MasterclassAccountRepository` (включая `liveUserIds()`), `MasterclassLogRepository` — все методы §7, `extends
  AbstractExamRepository`, ошибка БД = исключение. Условные `UPDATE … IS NULL` (`markFirstLogin/markHome/markContact/markFrozen`) возвращают `bool`.
- [ ] Тесты **на MariaDB**: `insertPending()` отдаёт возрастающий `id` (= `N`) и после `markDeleted()` номер не повторяется; `markFirstLogin()` второй раз
  `false`; `markFrozen()` один раз при двух вызовах; `listDue*` не выбирают уже обработанное; `funnel()` считает и удалённые.

**Готово, когда:** тесты на `wp_db` зелёные, данные тестов убраны по точным id.

## 1.5 Писатель журнала

- [ ] `Services\MasterclassLogWriter` (`write( MasterclassLogAction, array )`, подпись «Ученик#N» по `account_id`, IP — из `RequestContextProvider`);
  сбой записи гасится (`PluginLogger::exception( 'MasterclassLog', …, true )`). Пароли и ПД не пишутся.
- [ ] Тесты: ключи `$data`, подпись, сбой репозитория не бросает, в контекст не попадает пароль.

**Готово, когда:** тесты зелёные.

## 1.6 Контроллеры и настройки

- [ ] `MasterclassModule implements ServiceInterface` — порядок `register()` по `CONTRACTS.md` §9 (схема **до** любых зависящих от таблиц
  регистраций, в том числе при выключенном модуле); строка в `Init::getServices()` рядом с `VideoLibraryModule` (+ `use`).
- [ ] `MasterclassSettingsController` (всегда): `SAVE_ACTION`, вкладка `fs_lms_settings_tabs` (только при включённом), карточка `fs_lms_dashboard_modules`
  (`const_key FS_LMS_MASTERCLASS`), `fs_lms_module_toggle_masterclass`; `MasterclassSettingsCallbacks::ajaxSave()` (`Nonce::Config` +
  `ManageLmsPlatform`, ввод через `Sanitizer`); `templates/settings-tab.php`.
- [ ] `MasterclassIntegrityController` (всегда): `fs_lms_lesson_access` → пока заглушка-проход (`MasterclassAccessGuard` приходит в этапе 3),
  `deleted_user` → пока пустой обработчик (этап 4). **Не регистрировать то, чего ещё нет** — только каркас точек.
- [ ] `MasterclassAdminController` (минимум): подстраница «FS LMS → Мастер-классы» под `Menu::Main` через `MenuManager::register()`,
  `ManageLmsPlatform`, шаблон-заглушка с заголовком; без AJAX.
- [ ] Тесты: `MasterclassModuleTest` — выключен: зарегистрированы только настройки/интегрити; включён — ещё и админ-контроллер. Коллбек настроек:
  нет права / неверный nonce → отказ; значения вне границ приводятся; сохраняются только присланные ключи.

**Готово, когда:** тесты зелёные; на стенде модуль включается тумблером и константой, вкладка и пункт меню появляются/пропадают.

## 1.7 Документы модуля

- [ ] `inc/Modules/Masterclass/CLAUDE.md` — правила `CONTRACTS.md` §13; абзац в корневом `CLAUDE.md`, раздел «Модули».

**Готово, когда:** правила записаны, ссылки на классы существуют.

---

## Проверки этапа

PHPUnit полностью; **DI-загрузка с модулем включённым и выключенным**; PHPStan уровня 5 по `inc/Modules/Masterclass/` и правленым файлам ядра;
`npx eslint .`; `npx stylelint`; `npx gulp build`; схема и репозитории — на `wp_db`; `grep -rni masterclass inc --exclude-dir=Modules` пуст
(сканер этапа 0 зелёный). Затем **переписать этап 2**.
