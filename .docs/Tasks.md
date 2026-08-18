# Bug fix

...

---

# Подготовка к релизу 1.0.0

## Контекст

Плагин достиг финального состояния по фичам. Дальше **не добавляются**: витрины курсов,
мобильное приложение, Telegram-интеграция, внешние (свободные) преподаватели и ученики.
Предметы — только информатика: ЕГЭ, ОГЭ, Программирование на Python, Робототехника Ардуино.
От унификации под произвольные школьные предметы отказываемся, но **сама механика
произвольного предмета остаётся** — на ней стоят все четыре (свой ключ, свои таксономии,
`hasBank = false` для Python/Ардуино без банка заданий).

Нужно две вещи. Первая — вычистить код от того, что дальше не дописывается: догоняющие
data-миграции, одноразовые бэкфиллы, мёртвые ключи и швы под несуществующие фичи. Вторая —
завести раннер, который по тегу собирает устанавливаемый ZIP без исходников, тестов и
документации, прогнав перед этим линтеры и тесты.

Итог: тег `v1.0.0` → в GitHub Release лежит `fs-lms-1.0.0.zip`, который ставится на чистый
WordPress и работает.

---

## Зафиксированные решения

| Вопрос | Решение |
|---|---|
| Боевая установка | Нет, ставим с нуля → цепочку версий схемы можно схлопнуть |
| Одноразовые WP-CLI | Удалить (`task-bundle migrate`, `article reslug`) |
| `OptionName::Periods`, `AuthGroups` | Удалить (мёртвые; живой справочник — `AcademicPeriods`) |
| Неотправляемые типы писем | Удалить 4 из 7 |
| Модуль SocialAuth | Снести целиком, страницу входа перенести в ядро |
| Пакет переноса предмета (Bundle) | Оставить |
| Типы задач и шагов | Не трогать |
| Сборка релиза | Тег `v*` → ZIP ассетом GitHub Release |
| `/assets/`, `/vendor/` | Остаются в `.gitignore`, собираются в раннере |

Почему сборка в раннере, а не коммит `/assets/`: коммиченный билд протухает молча — этот
случай уже записан в `gulpfile.js:108` («так `frontend.min.css` неделями собирался из старого
кода при сломанном SCSS»). Плюс минифицированный JS в одну строку на 240 КБ даёт конфликт
слияния на каждой ветке. Дрейф версий закрывается тем, что уже есть: `npm ci` +
`package-lock.json`, `composer install` + `composer.lock`, пин `node-version: '20'`.
Плата: плагин больше нельзя ставить `git clone`'ом в `wp-content/plugins` — только из ZIP.

---

## Этап 0. Эталон схемы (делается ПЕРВЫМ, до любых правок)

Текущая dev-БД — единственный носитель полной схемы: она получила `Migration_1_0_0` + блок
`Cleanup` (~35 `ALTER`) + `Migration_1_1_0` + `AssessmentAnswerUniqueMigration`. Схлопывание
миграций — это перенос результата всех этих `ALTER` внутрь `CREATE TABLE`, и сверять его надо
с фактом, а не с чтением кода.

- [ ] Снять `SHOW CREATE TABLE` со всех `wp_fs_lms_*` в `schema-before.sql`

```bash
docker exec wp_db mariadb -u root -proot wordpress -e "SHOW TABLES LIKE 'wp_fs_lms%'"
# для каждой: SHOW CREATE TABLE
```

Эталон — 25 core-таблиц. `wp_fs_lms_ad_outbox` и `wp_fs_lms_video_recordings` в него не входят:
их заводят сами модули (`AdSync/Schema/AdSchema`, `VideoLibrary/Schema/VideoSchema`).

---

## Этап 1. Схема: одна миграция вместо цепочки

**`inc/Migrations/Migration_1_0_0.php`**

- [ ] Убрать блок «Сброс старой схемы» (строки 48–62): дропает `fs_lms_expelled_archive`,
      `fs_lms_relationships`, `fs_lms_enrollments`, `fs_lms_archive` и чистит
      `fs_lms_student_group_matrix` — на чистой установке этого нет никогда
- [ ] Влить блок «Cleanup — добавление колонок для уже существующих установок»
      (строки 617–681) внутрь соответствующих `CREATE TABLE`. Затрагивает:
      `student_records` (6 snapshot-колонок + `enrolled_by_user_id`),
      `groups` (`course_id`, `meetings`, `room_id`, `program_locked_at`, `access_mode`,
      без `group_id`),
      `group_lessons` (`ends_at`, `is_pinned`, `label`, `step_settings_overrides`, `kind`,
      `status`, `student_person_id`, `room_id`, `work_deadlines`, `continued_from_id`,
      `lesson_id` nullable),
      `assessment_attempts` (`group_lesson_id`),
      `assessment_answers` (`grader_note`, `criteria_scores`),
      `lesson_progress` / `submissions` (полный набор значений `enum`),
      `pii_access_log`, `export_log`, `email_log`, `applications`, `consent_change_log`,
      `entity_audit_log`, `persons` (`expelled_at` + индекс).
      **Определения брать из `schema-before.sql`, а не выводить вручную** — там уже финал
- [ ] Влить `UNIQUE KEY attempt_task (attempt_id, task_id)` прямо в `CREATE TABLE`
      `assessment_answers` (секция 19)
- [ ] Влить `CREATE TABLE notifications` из `Migration_1_1_0` как секцию 25
- [ ] Удалить приватные хелперы `addColumn()` / `dropColumn()` / `dropIndex()` / `addIndex()` /
      `hasColumn()` — после вливания они не вызываются
- [ ] Проверить, что список в `down()` полон (25 таблиц, `Notifications` уже есть)
- [ ] Обновить докблок класса: он до сих пор описывает снятые `enrollments` / `archive` /
      `deletion_log`
- [ ] Удалить `inc/Migrations/Migration_1_1_0.php`

`MigrationRunner` и `MigrationInterface` **оставить**: это штатный способ довезти DDL до
установок в будущих патч-релизах (см. скилл `db-migrations`).

---

## Этап 2. Догоняющие data-миграции → в основной код

Все четыре существуют, чтобы починить уже задеплоенные dev-установки. На установке с нуля их
гейт-опция просто ставится, а работы нет.

| Класс | Что с ним | Куда переезжает результат |
|---|---|---|
| `BroadcastStepMigration` | Удалить | Ничего не нужно: `StepType::Broadcast` уже в энуме, `recording_slot`-данных на чистой БД нет |
| `ArticlesSectionMigration` | Удалить | Ничего: `SubjectPageType::Articles` уже `'articles'` |
| `AssessmentAnswerUniqueMigration` | Удалить | Ключ уехал в DDL (этап 1) |
| `RoutingPagesMigration` | Удалить класс | `Activate::generatePages()` — заменить `createPageIfNeeded()` на `ensurePublished()` (восстанавливает удалённую/черновиковую страницу, а не только создаёт отсутствующую) и добавить туда `/sign-in/` |

- [ ] Удалить четыре класса миграций
- [ ] `Activate::generatePages()` — перевести на `ensurePublished()`, добавить `/sign-in/`
- [ ] `inc/Init.php` — вырезать блок строк 252–288 (`BroadcastStepMigration::ensure()`,
      регистрация и `run()` `MigrationRunner`, `ArticlesSectionMigration`,
      `AssessmentAnswerUniqueMigration`, `add_action('init', RoutingPagesMigration)`)
      и соответствующие `use`. Побочный эффект — минус 4 `get_option()` на каждом запросе;
      миграции остаются только в `Activate::activate()`
- [ ] `SubjectLandingController::redirectLegacySection()` (редирект `/textbook/` →
      `/articles/`) — проверить и удалить, если он существует только ради переехавших установок

---

## Этап 3. Снос SocialAuth, страница входа — в ядро

Модуль по умолчанию **выключен** (`ModuleConfig::isEnabled()` → `?? false`), а страница
`/sign-in/` живёт внутри него. То есть на чистой установке `Activate` создаёт страницу с
шорткодом `fs_lms_login_form`, но регистрировать шорткод некому — страница пустая, редиректа
с `wp-login.php` нет. Перенос в ядро чинит это, а не украшает.

- [ ] Создать `inc/Controllers/Person/AuthPageController.php` (ядро, в `Init::getServices()`) —
      копия `SocialAuthPageController` без `$providers`: `add_shortcode(ShortCode::LoginForm)`,
      `redirectToCustomLogin()` (перехват `wp-login.php`), `redirectFailedLogin()` на
      `wp_login_failed` с приоритетом 20, `forceCleanAuthLayout()` через `template_include`
- [ ] `templates/frontend/auth-page.php` — оставить форму, убрать блок
      `if ( ! empty( $providers ) )` целиком; убрать закомментированный блок
      «Зарегистрироваться как преподаватель»; `<a href="#">` в футере заменить реальными
      ссылками (страница согласия из `ConsentDefinitionsRepository`) либо снять абзац
- [ ] `src/scss/frontend/components/_auth-page.scss` — снять `&__socials`, `&__btn-social`,
      `.fs-social-icon` (строки ~83–115)
- [ ] Удалить `inc/Modules/SocialAuth/` целиком (18 файлов)
- [ ] Удалить `SocialAuthModule` из `Init::getServices()` + `use`
- [ ] Удалить `OptionName::AuthSettings` — читается только `SocialAuthSettingsRepository`
- [ ] Убрать `hybridauth/hybridauth` из `composer.json`, пересобрать `composer.lock`
- [ ] `templates/admin/components/modals/partials/provider-logo.php` — удалять **только если**
      после правки `help-modal.php` он больше не нужен: сейчас его подключает и
      `settings-tab.php` модуля, и `help-modal.php` (три бренд-логотипа в инструкции по OAuth).
      Инструкцию из help-модалки убрать вместе с модулем — тогда партиал уходит следом
- [ ] Вычистить упоминания SocialAuth / `AuthStrategyInterface` из `CLAUDE.md`

---

## Этап 4. Свободные роли Student / Teacher

`UserRole::Teacher` (`lms_teacher_free`) уже мёртв. `UserRole::Student` (`lms_student_free`)
держится на трёх точках, все три уходят вместе с SocialAuth или переписываются.

- [ ] `inc/Enums/Access/UserRole.php` — снять кейсы `Student` и `Teacher`, за ними строки в
      `label()`, `baseCapabilities()`, `capabilities()`
- [ ] Разобрать фолбэки: `primary()` возвращает `self::Student`, когда ни один слаг не совпал →
      сменить на `?self` с `null` (и поправить вызывающих) либо на `FSStudent`. Выбрать по
      вызывающим: `primaryForCabinet()`, `primarySlug()`, `UserDTO::fromArray()` (строка 77 —
      `?? UserRole::Student`)
- [ ] `frontCabinetRoles()` — убрать `Student`; порядок приоритета в `primary()` — убрать оба
      хвостовых кейса
- [ ] `inc/Services/Profile/ProfileViewResolver.php` — строки 84 и 111, `UserRole::Student` из
      обоих `in_array`
- [ ] `inc/Managers/Person/RoleManager.php` — в `registerAll()` добавить `remove_role()` для
      `lms_student_free` и `lms_teacher_free`: на dev-БД роли уже созданы и сами не исчезнут
- [ ] Поднять `$capsVersion` в `Init.php:245` с `'5.3'` до `'5.4'`, иначе пересинхронизация не
      запустится
- [ ] `src/js/profile/app.js` — строка 60 (`lms_student_free: 'Ученик'`) и строка 207
      (`hideRole`-список)
- [ ] Тесты: `tests/Unit/Enums/UserRoleTest.php` (строки 29, 33),
      `tests/Unit/Services/Profile/ProfileViewResolverTest.php` (строка 51)

---

## Этап 5. Мёртвый код и ключи

**Блокер, чинить обязательно:** `uninstall.php:47` обращается к
`Capability::ManageLMSAssignments` — такого кейса в энуме нет (там 14 других). Удаление
плагина из админки падает фаталом.

- [ ] `uninstall.php` — убрать строку 47, список `$lms_caps` привести к фактическому набору из
      `UserRole::FSOffice->capabilities()`

**Энумы:**

- [ ] `OptionName::Periods` (`fs_lms_periods_list`) — дубль-пустышка; живой ключ —
      `AcademicPeriods` (`fs_lms_academic_periods`), его читает `AcademicPeriodRepository`
- [ ] `OptionName::AuthGroups` (`fs_lms_auth_group`)
- [ ] `EmailTemplateType::PasswordSetup`, `ApplicationConfirmation`, `ApplicationReady`,
      `Rejection` — редактируются во вкладке «Шаблоны писем», но ни одно письмо этих типов не
      отправляется (`EmailService` шлёт только `WelcomeWithCredentials`, `CourseGranted`,
      `OtpCode`). Снять кейсы и строки в `label()`; проверить вкладку настроек и
      `EmailTemplateSettingsCallbacks`
- [ ] `ShortCode::RegisterForm` (`fs_lms_register_form`)
- [ ] `ShortCode::GroupLessons` (`fs_lms_group_lessons`, остаток снятого кокпита группы)
- [ ] `PageRoutes::ConsentPage` (`'consent'`) — проверить `templates/frontend/consent-page.php`:
      если страница рендерится по другому маршруту, кейс мёртв
- [ ] `Inc\Enums\Course\LessonKind` — файл никем не импортируется, `group_lessons.kind`
      читается сырой строкой. Либо удалить энум, либо (лучше по CLAUDE.md «ключи только через
      энумы») завести его в `GroupLessonDTO`. **Решить, посмотрев на потребителей**

**Классы:**

- [ ] `inc/Cli/TaskBundleMigrationCommand.php` + `inc/Services/Task/TaskBundleMigrationPlanner.php`
      + `inc/DTO/Task/TaskBundleReferenceChangeDTO.php` (проверить, что DTO больше нигде не нужен)
- [ ] `inc/Cli/ArticleSlugCommand.php`
- [ ] Обе команды — из `Init::getServices()` (`Init.php:177` и рядом)
- [ ] `BoilerplatePageController` — снять `implements ServiceInterface` и пустой `register()`
      (класс резолвится контейнером из `AdminCallbacks`, сервисом не является)
- [ ] `BoilerplateController` — снять неиспользуемый `use TemplateRenderer`

**Файлы:**

- [ ] `assets/css/{all,fontawesome,solid}.min.css`, `assets/webfonts/` — FontAwesome грузится
      с CDN (`BundleLoader.php:75`), локальные копии не используются и не в git
- [ ] `.docs/db-backups/legacy-tables-pre-migration-reset.sql`

---

## Этап 6. Хвосты снятых с плана фич

- [ ] `src/js/profile/api.js` (шапка файла) — обещание «перенести кабинет в Telegram Web App
      или мобильное приложение» и мост `window.FS_LMS_API.request`. **Код оставить** —
      косвенность транспорта ничего не стоит и это хорошая изоляция; переписать комментарий
      так, чтобы он описывал текущий шов, а не будущую интеграцию
- [ ] `inc/Services/Profile/NotificationService.php:50` — «шов будущего Telegram-модуля
      Notifier», комментарий
- [ ] `inc/DTO/Person/UserDTO.php` — поле `telegramId` и чтение меты `fs_telegram_id`: нигде
      не заполняется и не читается, снять
- [ ] `inc/Callbacks/System/AdminCallbacks.php:85` — Dashboard помечен «временная заглушка»,
      но фактически рендерит `admin/dashboard` с фильтром `fs_lms_dashboard_modules`. Снять
      формулировку
- [ ] `inc/Services/Course/ContentUsageService.php:452` — `TODO Этап 2` про кросс-предметный
      поиск. Решить: доделать или зафиксировать как сознательное ограничение в докблоке
- [ ] `AdSync`: `AdSyncStatusCallbacks.php:41` и `AdSyncController.php:75` — `TODO(текст)`,
      недописанные пользовательские сообщения. Дописать

---

## Этап 7. Раннер релиза

- [ ] **`.distignore`** (новый файл в корне) — что не едет на сервер:

```
/src/
/tests/
/.docs/
/.github/
/.claude/
/.idea/
/node_modules/
/.phpunit.cache/
/.scss-check/
/assets/css/maps/
*.map
*.LICENSE.txt
/composer.json
/composer.lock
/package.json
/package-lock.json
/gulpfile.js
/eslint.config.cjs
/.stylelintrc.json
/phpcs.xml
/phpunit.xml
/.gitignore
/.gitattributes
/.distignore
/CLAUDE.md
/README.md
/Tasks.md
inc/Services/Subject/Bundle/CLAUDE.md
```

Едут: `fs-lms.php`, `uninstall.php`, `inc/` (включая `inc/Modules/*/assets/` — рукописные,
ничем не собираются), `templates/`, `assets/js/*.min.js`, `assets/css/*.min.css`, `vendor/`.

- [ ] **`.github/workflows/release.yml`** (новый), триггер `push: tags: ['v*']`:

1. `actions/checkout@v4`
2. `actions/setup-node@v4` (node 20, `cache: npm`) → `npm ci`
3. `shivammathur/setup-php@v2` (php 8.3) → `composer install --no-interaction --prefer-dist`
4. **Гейт качества** (любой красный шаг рушит релиз): `npm run lint:js`, `npm run lint:css`,
   `npm run build:check`, `vendor/bin/phpunit`
5. **Гейт версии**: тег без `v` должен совпасть с `Version:` в шапке `fs-lms.php` и с
   `FS_LMS_VERSION` — иначе `exit 1`. Без этого в манифест пакета переноса предмета уедет
   неверная версия сборки
6. `npx gulp build`
7. `rm -rf vendor && composer install --no-dev --optimize-autoloader --classmap-authoritative`
8. `rsync -a --exclude-from=.distignore ./ build/fs-lms/`
9. `cd build && zip -r ../fs-lms-${VERSION}.zip fs-lms` — папка `fs-lms/` **внутри** ZIP
   обязательна, иначе WP распакует файлы в корень `wp-content/plugins/`
10. `softprops/action-gh-release@v2` с ZIP в `files`

- [ ] **`.github/workflows/ci.yml`** — добавить `npm run lint:css` в job `assets`: сейчас его
      там нет, хотя `npm run ci` его гоняет — то есть stylelint не проверяется на PR
- [ ] **Версия** — поднять `0.0.1` → `1.0.0` в трёх местах: шапка `fs-lms.php`, константа
      `FS_LMS_VERSION`, `package.json`. Заодно `@since 0.0.1` в `uninstall.php`

---

## Проверка

**1. Схема идентична эталону** (после этапов 1–2)

Деактивировать плагин → `DROP TABLE` все `wp_fs_lms_*` → удалить опции `fs_lms_%` →
активировать → снять `SHOW CREATE TABLE` заново и сравнить с `schema-before.sql`.
Сравнивать **состав колонок, типы, ключи и индексы**, не текст целиком: порядок колонок после
`ALTER` и после чистого `CREATE` закономерно расходится — это не расхождение схемы.

**2. `npm run ci` зелёный** (lint:js + lint:css + build:check + phpunit)

**3. Четыре предмета работают**

Завести на чистой БД: ЕГЭ информатика и ОГЭ информатика (с банком, `hasBank = true`),
Программирование на Python и Робототехника Ардуино (без банка, `hasBank = false`).
По каждому:

- лендинг `/{key}/` и разделы `/{key}/trainer/`, `/{key}/articles/`, `/{key}/courses/`
  (у безбанковых — только описание и курсы)
- создать задание, проверить адрес `/{key}/trainer/{номер}/`
- создать статью, опубликовать, проверить слаг `article-task-{N}-{i}` и `ArticlePublishValidator`
- собрать курс с уроком, назначить группе, открыть КТП, опубликовать программу
- открыть плеер `/lesson/?gid=&gl=`, пройти шаги, сдать работу
- контрольная: попытка, автосохранение ответа, оценивание
- модуль EgeComputer на ЕГЭ и ОГЭ (компьютерный формат)

**4. Вход без модуля**

`/sign-in/` рендерит форму; `wp-login.php` редиректит на неё; неверный пароль возвращает на
страницу с ошибкой и подставленным логином; `logout` не зацикливается; ролей
`lms_student_free` / `lms_teacher_free` в БД нет.

**5. Удаление плагина не падает**

«Удалить» в списке плагинов → `uninstall.php` отрабатывает, таблицы и опции `fs_lms_%` снесены,
capabilities с администратора сняты.

**6. Релизный ZIP**

Тег `v1.0.0-rc1` в тестовой ветке → раннер собрал ZIP → распаковать в чистый WP (можно поднять
второй контейнер), активировать, повторить пункты 3–5 на нём. Проверить, что внутри ZIP нет
`src/`, `tests/`, `.docs/`, `*.map`, а `vendor/autoload.php` есть.

---

## Порядок работ

```
Этап 0  → снять эталон схемы
Этап 1  → схлопнуть миграции          ─┐
Этап 2  → убрать догоняющие миграции   ├─ проверка 1
Этап 3  → SocialAuth → ядро           ─┐
Этап 4  → свободные роли               ├─ проверка 4
Этап 5  → мёртвый код + фикс uninstall ├─ проверка 5
Этап 6  → хвосты                      ─┘
Этап 7  → раннер                      ─── проверки 2, 3, 6
```

Этапы 3 и 4 связаны (роль `Student` создаётся только соцвходом) — делать подряд.
Этапы 5 и 6 независимы от остальных, можно в любом порядке.

---

## Что НЕ трогаем

- Механику произвольного предмета: `SubjectRepository`, создание предмета, пользовательские
  таксономии, `hasBank`, `SubjectPageType` — на ней стоят все четыре предмета
- Типы заданий (`TaskTemplate`, `MetaBoxes/Templates/*`) и типы шагов (`StepType`)
- Пакет переноса предмета `inc/Services/Subject/Bundle/` + `wp fs-lms subject export|import`
- Модули `AdSync`, `DaData`, `EgeComputer`, `SmartCaptcha`, `VideoLibrary`
- `MigrationRunner` / `MigrationInterface` — нужны для DDL будущих патч-релизов
- `.docs/` в репозитории (исключается только из релизного ZIP)
