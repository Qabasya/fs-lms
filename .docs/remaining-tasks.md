# FS LMS — Оставшиеся задачи

Версия: 2026-06-05  
Статус: инфраструктура, отчисление и карточки пользователей реализованы. Остаётся: CSV-экспорт, настройки, безопасность, журналы, тесты, документация.

---

## Что уже реализовано

**Инфраструктура:**
- Все enum-ы, все репозитории, все сервисы, все DTO, все shared traits
- `UserManager`, `RoleManager`, `CronManager`, `MigrationRunner`, `Migration_1_0_0` (единственная, всё слито)
- `PiiCryptoService`, `PiiMaskingService`, `PersonReader`, `PersonService`
- `RelationshipService`, `RetentionService`, `ApplicationService`
- `EnrollmentService` (включая snapshot с contract_no/contract_date/order_no/order_date)
- `EmailService` + strategy pattern (`WpOptionsEmailTemplate` / `PhpEmailTemplate`)
- `CsvExportService` + `CsvColumn` — Column Projection, одноразовые ссылки `/lms/export/{token}`
- Guard в `fs-lms.php`: при отсутствии `FS_LMS_ENC_KEY` — `admin_notices` + `Init::run()` не вызывается; `Activate::showConfigNotice()`

**Контроллеры** (все зарегистрированы в Init.php):
- `ApplicationController` — маршруты `/lms/apply`, `/lms/join/{code}`
- `EnrollmentController` — AJAX зачисления и корзины (отдельной страницы карточки заявки нет)
- `PiiController` — AJAX PII, страница карточки person
- `RecoveryController` — cron-задачи, TTL ссылки пароля (48ч для LMS-ролей)
- `ConsentController` — маршрут `/lms/consent/{type}/{version}`
- `ExpulsionController` — весь цикл отчисления

**Callbacks** (все методы реализованы):
- `ApplicationCallbacks` — ajaxSendOtpCode, ajaxCreateApplication, ajaxSubmitParentData
- `EnrollmentCallbacks` — весь цикл заявок (список, карточка, зачисление, корзина, редактирование)
- `PiiCallbacks` — reveal, soft-delete, add/replace representative, update person, renderPersonDetailPage
- `RecoveryCallbacks` — все cron-тики
- `ExpulsionCallbacks` — ajaxExpelStudent, ajaxExportExpelledRecord

**Шаблоны и JS — готовы:**
- `templates/frontend/apply.php` + `src/js/frontend/services/apply-form.js` (300 строк)
- `templates/frontend/join.php` + `src/js/frontend/services/join-form.js` (147 строк)
- `templates/admin/components/tabs/userlist-tabs/userlist-1-applications.php` — таблица заявок с фильтрами, пагинацией, модалками
- `templates/admin/components/tabs/userlist-tabs/userlist-2-students.php` — зачисленные ученики
- `templates/admin/components/tabs/userlist-tabs/userlist-3-parents.php` — родители/представители
- `templates/admin/components/tabs/userlist-tabs/userlist-4-teachers.php` — преподаватели
- `templates/admin/components/tabs/userlist-tabs/userlist-5-archive.php` — архив отчисленных/завершивших
- `templates/admin/components/modals/archive-view-modal.php` + `archive-view-modal.js` — аккордеон с данными
- Все модальные окна: enrollment, review, change, student-view, parent-view, teacher-view
- `application-view-modal` — read-only просмотр заявки для статусов Enrolling/Converted/Expired/Trash
- Все 6 email-шаблонов
- `templates/admin/components/modals/student-person-modal.php` + `student-person-modal.js` + `student-person-modal-manager.js`
- `templates/admin/components/modals/parent-person-modal.php` + `parent-person-modal.js` + `parent-person-modal-manager.js`
- `templates/admin/components/modals/expel-modal.php` + `expel-modal.js` + `expel-modal-manager.js`

**Модальная логика просмотра заявок (финальная):**
- `PendingParent` → `.js-edit-application` → `application-modal.php` (редактирование данных ученика)
- `ReadyForReview` → `.js-review-application` → `application-review-modal.php` (редактирование ученика + родителя)
- `Enrolling`, `Converted`, `Expired`, `Trash` → `.js-view-application` → `application-view-modal.php` (read-only)
- Отдельной страницы карточки заявки нет и не нужно.


---

## Содержимое таблиц

### Таблица "Ученики"

Столбцы:

ФИО, Телефон, Предмет, Группа, Расписание, № договора, действия

### Таблица "Родители"

Столбцы:

ФИО родителя, ФИО ребёнка, Телефон, Почта, действия

### Таблица "Преподаватели"

Столбцы:

ФИО ,  Предметы, Группы, действия

### Таблица "Архив"

Столбцы:

ФИО ученика, Предмет, Группа, Расписание, № договора, статус, действие Просмотреть.

По нажатию на Просмотреть открывается модальное окно-аккордеон с данными (как при зачислении):
1. Данные ребёнка (все)
1. Данные родителя (все)
1. Данные о зачислении (номер, дата договора, номер, дата приказа, предмет, группа, расписание, причина отчисления)


---

## 1. Карточки пользователей

### 1.1. Модальные окна с данными ученика и родителя — ✅ реализовано

`student-person-modal.php` + `student-person-modal.js` + `student-person-modal-manager.js`  
`parent-person-modal.php` + `parent-person-modal.js` + `parent-person-modal-manager.js`

Вызываются кнопкой "Просмотреть" в таблицах Ученики и Родители.  
Реализованы: просмотр (с маскированием PII), reveal, редактирование, удаление.

---

### 1.2 `userlist-5-archive.php` — ✅ реализовано

`EnrollmentRepository::list()` поддерживает массив статусов (`IN (...)`).  
Источник: `['status' => ['expelled', 'finished', 'transferred']]`.  
Колонки: ФИО, Направление, Группа, Статус, Дата завершения, Причина, Действия.  
Модалка `archive-view-modal` — аккордеон: данные ребёнка / родителя / зачисления.

---

## 2. Настройки — отсутствующие вкладки

Сейчас в `settings.php` 3 вкладки. Нужно добавить:

### 2.1 Вкладка «Шаблоны писем» (`settings-4-email-templates.php`) — ✅ реализовано

Callback: `EmailTemplateSettingsCallbacks` (создать файл).

Для каждого типа письма (otp_code, password_setup, application_confirmation, application_ready, rejection, new_representative):
- Поле темы (`<input type="text">`)
- Поле тела (`<textarea>` или `wp_editor()`)
- Подсказка с доступными плейсхолдерами
- Кнопка «Сбросить к умолчанию»

AJAX-методы:
- `ajaxSaveEmailTemplate()` — сохраняет в `OptionName::EmailTemplates`; использовать `$this->authorize(Nonce::Manager, Capability::Admin)` + `wp_kses_post($body)`
- `ajaxResetEmailTemplate()` — удаляет ключ из options; `WpOptionsEmailTemplate` автоматически fallback-ится на PHP-файл

Регистрация в контроллере настроек: новые хуки + вкладка в навигацию.

---

### 2.2 Вкладка «Согласия» (`settings-5-consents.php`) — ✅ реализовано

Тексты согласий хранятся как WordPress-документ (страница `/consent`). `ConsentController` уже обслуживает маршрут `/lms/consent/{type}/{version}` и отдаёт содержимое. Хеш текста согласия на момент подписания уже хранится в таблице `consents.document_hash` — `ConsentService::getDocumentHash()` считает его автоматически.

**Что нужно реализовать — UI в настройках:**

Вкладка «Согласия» в `settings.php`. Для каждого `ConsentType::cases()`:
- Текущая версия и дата публикации
- Таблица всех версий: номер версии, дата, хеш, ссылка «Просмотреть» → `/lms/consent/{type}/{version}`
- Поле «Восстановить по хешу»: ввод хеша → поиск по всем версиям → показ соответствующего текста (нужно для ответа на вопрос «что именно подписал родитель в дату X»)

Callback `ConsentSettingsCallbacks::renderConsentSettingsTab()` — только чтение, без редактирования версий (тексты согласий иммутабельны — новые версии добавляются только через деплой или отдельный инструмент).

---

## 3. Безопасность — хуки WordPress

Эти хуки нигде не зарегистрированы. Добавить в `UserController` или новый `PasswordController`:

### 3.1 Политика паролей — ✅ реализовано

**Модель паролей по ролям:**

- **Преподаватель (`lms_teacher`)** — пароль устанавливается администратором через WP admin. Стандартный WP-поток.
- **Ученик (`lms_student`)** — пароль выбирает сам при заполнении формы. Ограничений нет
- **Родитель (`lms_parent`)** — пароль генерируется автоматически сервисом `PasswordGeneratorService` при зачислении и отправляется на email вместе с логином. Родитель **не может** менять пароль самостоятельно.

**Что уже реализовано:**
- `PasswordGeneratorService` генерирует пароль и хранит его зашифрованным в `user_meta`
- `ajaxRevealUserCredentials` в `EnrollmentCallbacks` позволяет администратору увидеть пароль родителя и переслать

**Что нужно реализовать — блокировка самостоятельного сброса:**

Запрет для **всех LMS-ролей** (`lms_student`, `lms_parent`, `lms_teacher`) сбрасывать пароль через стандартный WP-поток «Забыли пароль» на wp-login.php. Это единая политика: пароли управляются только через администратора.

```php
// В UserController::register() или новом PasswordController:
add_filter('allow_password_reset', [$this, 'blockPasswordReset'], 10, 2);
```

```php
public function blockPasswordReset(bool $allow, int $userId): bool {
    $user = get_userdata($userId);
    foreach (UserRole::lmsRoles() as $role) {
        if (in_array($role->value, (array) $user->roles, true)) {
            return false; // wp-login.php покажет стандартное сообщение об ошибке
        }
    }
    return $allow;
}
```

`UserRole::lmsRoles()` — добавить статический метод, возвращающий `[FSStudent, FSParent, FSTeacher]`.

### 3.3 HTTPS-предупреждение — ✅ реализовано

Добавить в `AdminController::register()` или `Activate.php`:

```php
add_action('admin_init', function() {
    if (!is_ssl() && !defined('WP_DEBUG')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>FS LMS: плагин работает без HTTPS. Это недопустимо при обработке персональных данных.</p></div>';
        });
    }
});
```

---

## 4. Журналы в админке

### 4.1 Таб «Журнал действий» — ✅ реализовано

Подменю FS LMS, доступна по `Capability::ManageApplications`.

Таблица: дата, пользователь, роль, действие, объект, детали.  
Фильтры: action, target_type, actor, date range.  
Данные: `AuditLogRepository::listByTarget()` / `listByActor()`.

### 4.2 Таб «Журнал доступа к ПД» — ✅ реализовано

Доступна только по `Capability::ExportPII`.

Таблица: дата/время, кто смотрел, чьи данные, какие поля, с какой целью.  
Фильтры: person_id, actor, date range.

Добавить метод `countByActorInLastHour(int $userId): int` в `PiiAccessLogRepository` (нужен для алерта при >50 reveal/час из `PiiCallbacks`).

---

## 5. Тесты

### 5.1 Unit: `PiiCryptoService`

- encrypt → decrypt возвращает оригинал
- два вызова encrypt → разные результаты (random nonce)
- decrypt на усечённом blob → RuntimeException
- decrypt при подмене байта → RuntimeException
- hash детерминистичен; hash нормализует пробелы и регистр

### 5.2 Unit: `PiiMaskingService`

- mask(Pass): первые 4 + последние 4 символа
- mask(Inn): последние 4 цифры
- mask(Phone): +7 9** *** ** **
- maskBulk: массив обрабатывается корректно

### 5.3 Unit: `EmailOtpService`

- verify с правильным кодом → true, transient удалён
- verify с неверным кодом → false
- verify после истечения transient → false
- canResend: false пока cooldown активен, true после
- bypass-код FS_LMS_OTP_BYPASS_CODE → true

### 5.4 Интеграционный: happy path

1. Ученик создаёт заявку → pending_parent, JOIN-ссылка
2. Родитель заполняет форму → ready_for_review
3. Админ открывает карточку (данные маскированы)
4. Reveal паспорта → запись в pii_access_log
5. Зачисление → enrollment active, application converted
6. WP-юзеры созданы для студента и родителя
7. Password setup ссылки сгенерированы

### 5.5 Интеграционный: recovery после падения

Смоделировать: транзакция прошла, WP-юзера не созданы, application в `enrolling`.  
Ожидание: `RecoveryService::resolveStuckEnrollments()` создаёт юзеров, переводит в `converted`, идемпотентен.

---

## 6. Документация

### 6.1 Обновить CLAUDE.md

Добавить секции:
- **Custom tables** — когда использовать вместо wp_options (растущий объём, фильтры, транзакции)
- **PII-шифрование** — encrypt при записи, читать только через PersonReader
- **Транзакции** — TransactionRunner trait; wp_insert_user вне транзакции
- **Audit log** — все действия через AuditService; PII-доступ через PersonReader (логирует автоматически)
- **OTP flow** — SendOtpCode → CreateApplication

### 6.2 `INSTALL.md`

Требования, генерация ключей шифрования, настройка системного cron, настройка капчи, первая активация, чеклист.

### 6.3 `ADMIN_GUIDE.md`

Как работать с заявками, зачислением, корзиной, PII-reveal, добавлением/заменой представителей, запросом удаления ПД, застрявшими зачислениями.

---

## 7. Отчисление ученика — ✅ реализовано

`ExpulsionController` + `ExpulsionService` + `ExpulsionCallbacks`  
`AjaxHook::ExpelStudent`, `AjaxHook::ExportExpelledRecord`  
`ExpelledArchiveRepository`, `ExpulsionReasons`  
`expel-modal.php` + `expel-modal.js` + `expel-modal-manager.js`

После `expel()` строка автоматически попадает в `userlist-5-archive.php`.

---

## 8. CSV-экспорт

Инфраструктура готова: `CsvExportService` + `CsvColumn` (Column Projection).  
Маршрут скачивания: `/lms/export/{token}` → `PiiController::handleExportDownload()`.  
Авторизация: `Nonce::ExportPii` + `Capability::ExportPII`.

### 8.1 Экспорт одного ученика (из карточки)

**Триггер**: кнопка «Экспорт» в модалке `student-person-modal` / `person-detail.php`.

**`AjaxHook`**: `ExportPii` (уже есть, но не зарегистрирован — добавить в `PiiController::ajaxActions()`).

**`PiiCallbacks::ajaxExportStudentCsv()`**:
- Принимает `enrollment_id`
- Расшифровывает `snapshot_enc` из enrollments
- Читает данные группы/предмета из репозиториев
- Вызывает `CsvExportService::export()` с колонками:

```
ФИО ученика, Дата рождения, Email, Телефон, Школа, Класс,
Тип документа, Номер документа, ИНН,
ФИО родителя, Роль, Телефон родителя, Email родителя,
Паспорт родителя, ИНН родителя, Адрес,
Предмет, Группа, № договора, Дата договора, № приказа, Дата приказа
```

- Возвращает одноразовый URL через `CsvExportService::createDownloadLink($csv, 'student-{id}.csv')`

### 8.2 Экспорт одного родителя (из карточки)

Аналогично 8.1, но данные берутся из snapshot первого активного зачисления подопечного.  
Имя файла: `parent-{person_id}.csv`.

### 8.3 Экспорт таблицы учеников (bulk)

**Триггер**: кнопка «Экспорт в CSV» над таблицей `userlist-2-students.php`.

**`AjaxHook`**: добавить `case ExportStudents = 'export_students'`.

Источник: `EnrollmentRepository::list(['status' => 'active'])` — все активные зачисления.  
Для каждой строки расшифровывается `snapshot_enc`.  
Колонки те же что в 8.1.  
Имя файла: `students-{date}.csv`.

### 8.4 Экспорт из архива (bulk)

**Триггер**: кнопка «Экспорт в CSV» над таблицей `userlist-5-archive.php`.

**`AjaxHook`**: добавить `case ExportArchive = 'export_archive'`.

Источник: `EnrollmentRepository::list(['status' => ['expelled', 'finished', 'transferred']])`.  
Дополнительные колонки: Статус, Дата завершения, Причина отчисления.  
Имя файла: `archive-{date}.csv`.

---

---

## 9. Рефакторинг схемы БД — ✅ реализовано

> Полное описание новой схемы: `.docs/db-redesign.md`

### 9.1 Миграция (Migration_1_0_0.php)

Добавить новые таблицы, удалить старые. Порядок операций:

**Создать:**

`fs_lms_groups` (заменяет матрицу из `wp_options`):
```sql
CREATE TABLE fs_lms_groups (
    id          smallint unsigned NOT NULL AUTO_INCREMENT,
    group_key   varchar(100)      NOT NULL,
    subject_key varchar(50)       NOT NULL,
    period_key  varchar(50)       NOT NULL,
    name        varchar(255)      DEFAULT NULL,
    schedule    varchar(500)      DEFAULT NULL,
    created_at  datetime          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY group_key (group_key),
    KEY subject_key (subject_key),
    KEY period_key (period_key)
);
```

`fs_lms_person_documents` (весь PII; студентам address/doc_issued_by/doc_issued_date = NULL):
```sql
CREATE TABLE fs_lms_person_documents (
    id                int unsigned NOT NULL AUTO_INCREMENT,
    person_id         int unsigned NOT NULL,
    email_enc         blob         DEFAULT NULL,
    email_hash        char(64)     DEFAULT NULL,
    phone_enc         blob         DEFAULT NULL,
    phone_hash        char(64)     DEFAULT NULL,
    doc_type          varchar(30)  DEFAULT NULL,
    doc_number_enc    blob         DEFAULT NULL,
    doc_number_hash   char(64)     DEFAULT NULL,
    doc_issued_by_enc blob         DEFAULT NULL,  -- только родители
    doc_issued_date   date         DEFAULT NULL,   -- только родители
    inn_enc           blob         DEFAULT NULL,
    inn_hash          char(64)     DEFAULT NULL,
    address_enc       blob         DEFAULT NULL,   -- только родители
    PRIMARY KEY (id),
    UNIQUE KEY person_id (person_id),
    KEY email_hash (email_hash),
    KEY phone_hash (phone_hash),
    KEY doc_number_hash (doc_number_hash),
    KEY inn_hash (inn_hash)
);
```

`fs_lms_archive` (переименован из `fs_lms_expelled_archive`; без restored_at/restored_by):
```sql
CREATE TABLE fs_lms_archive (
    id                  int unsigned        NOT NULL AUTO_INCREMENT,
    enrollment_id       int unsigned        DEFAULT NULL,
    student_person_id   int unsigned        NOT NULL,
    parent_person_id    int unsigned        NOT NULL,
    contract_no         varchar(50)         DEFAULT NULL,
    contract_date       date                DEFAULT NULL,
    order_no            varchar(50)         DEFAULT NULL,
    order_date          date                DEFAULT NULL,
    group_key           varchar(100)        DEFAULT NULL,
    enrolled_at         datetime            NOT NULL,
    expelled_at         datetime            DEFAULT NULL,
    expelled_by_user_id bigint(20) unsigned DEFAULT NULL,
    reason              varchar(500)        DEFAULT NULL,
    created_at          datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY enrollment_id (enrollment_id),
    KEY student_person_id (student_person_id),
    KEY parent_person_id (parent_person_id),
    KEY group_key (group_key),
    KEY expelled_at (expelled_at)
);
```

**Удалить:**
- `fs_lms_relationships`
- `fs_lms_expelled_archive`

**Изменить `fs_lms_persons`:**
- Убрать: `email`, `full_name_enc`, `doc_type`, `doc_number_enc`, `doc_number_hash`, `inn_enc`, `inn_hash`, `address_enc`, `phone_enc`
- Добавить: `full_name varchar(255)` (plain), `role enum('student','parent')`
- Тип `id`: `bigint(20) unsigned` → `int unsigned`

**Изменить `fs_lms_enrollments`:**
- `group_id varchar(100)` → `group_key varchar(100)` (ссылка на `fs_lms_groups.group_key`)
- Убрать `subject_key`, `period_key` — берутся JOIN-ом
- Тип `id`, `student_person_id`, `source_application_id`, `terminated_by_user_id`: `bigint` → `int unsigned`

**Удалить из `wp_options`:**
- Ключ матрицы групп (`fs_lms_student_group_matrix`) — данные мигрируют в `fs_lms_groups` + `fs_lms_enrollments`

---

### 9.2 Enums

| Файл | Действие |
|---|---|
| `inc/Enums/TableName.php` | Добавить: `Groups`, `PersonDocuments`, `Archive`; удалить: `Relationships`, `ExpelledArchive` |
| `inc/Enums/RelationType.php` | Удалить файл |

---

### 9.3 DTO

| Файл | Действие |
|---|---|
| `inc/DTO/PersonDTO.php` | Убрать enc-поля; добавить `full_name`, `role` |
| Новый `inc/DTO/PersonDocumentsDTO.php` | `email_enc`, `email_hash`, `phone_enc`, `phone_hash`, `doc_type`, `doc_number_enc`, `doc_number_hash`, `inn_enc`, `inn_hash`, `address_enc` |
| `inc/DTO/ExpelledArchiveDTO.php` | Переименовать в `ArchiveDTO`; добавить поля `contract_no`, `contract_date`, `order_no`, `order_date`, `group_key`, `enrolled_at` |

---

### 9.4 Репозитории

| Файл | Действие |
|---|---|
| `inc/Repositories/WPDBRepositories/PersonRepository.php` | Убрать enc/hash-поля и email; работает только с `persons` (full_name, birth_date, role, wp_user_id) |
| Новый `inc/Repositories/WPDBRepositories/PersonDocumentsRepository.php` | CRUD для `person_documents`; методы: `findByEmailHash()`, `findByPhoneHash()`, `findByDocNumberHash()`, `findByInnHash()`; при записи для ученика не заполнять `doc_issued_by_enc`, `doc_issued_date`, `address_enc` |
| Новый `inc/Repositories/WPDBRepositories/GroupsRepository.php` | CRUD для `groups` (group_key, subject_key, period_key, name, schedule); заменяет `StudentGroupMatrixRepository`; список учеников группы — через `EnrollmentRepository`, не через эту таблицу |
| `inc/Repositories/WPDBRepositories/ExpelledArchiveRepository.php` | Переименовать в `ArchiveRepository`; методы: `createOnEnroll()`, `setExpelled()`; убрать `restored_at`/`restored_by`; убрать `data_enc` |
| `inc/Repositories/WPDBRepositories/RelationshipRepository.php` | Удалить |
| `inc/Repositories/OptionsRepositories/StudentGroupMatrixRepository.php` | Удалить; данные мигрируют: группы → `GroupsRepository`, состав групп → `enrollments WHERE status=active` |

---

### 9.5 Сервисы

| Файл | Действие |
|---|---|
| `inc/Services/Person/PersonService.php` | Писать в `person_documents` (email, phone, doc, inn, address); убрать enc из persons |
| `inc/Services/Person/RelationshipService.php` | Удалить |
| `inc/Services/Enrollment/EnrollmentService.php` | См. раздел 10 — полный рефакторинг потоков зачисления |
| `inc/Services/ExpulsionService.php` | `UPDATE archive SET expelled_at` вместо INSERT в `expelled_archive` |

---

## 10. Рефакторинг потоков зачисления — ✅ реализовано

> Это самый большой блок изменений. Текущий `EnrollmentService` реализует только путь 1А.

### Принципы

- **Пользователи не удаляются никогда.** `persons`, `wp_users` — только мягкое удаление (`deleted_at`). При повторном зачислении существующая запись переиспользуется.
- **Запись в archive создаётся при зачислении** (`expelled_at = NULL`), обновляется при отчислении.
- **Связь родитель→ученик** хранится в `archive` (поля `student_person_id` + `parent_person_id`). Отдельной таблицы нет.

---

### Матрица событий

| # | Ученик | Родитель | Путь A (join) | Путь B (admin выбирает) |
|---|---|---|---|---|
| 1 | Новый | Новый | apply → join → зачисление | apply → модальное окно → зачисление |
| 2 | Старый | Новый | восстановление из archive → join → зачисление | восстановление из archive → модальное окно → зачисление |
| 3 | Новый | Старый | — (нет смысла: родитель уже есть, но ученик новый — только через путь B) | apply → модальное окно → зачисление |
| 4 | Старый | Старый | — (оба уже есть — только через путь B) | восстановление из archive → модальное окно → зачисление |

---

### 10.1 Событие 1A — новый ученик, новый родитель (join)

**Текущая реализация, требует адаптации под новую схему БД.**

```
apply.php  →  [OTP]  →  application (pending_parent)
    │
join.php (родитель заполняет)  →  application (ready_for_review)
    │
Администратор нажимает «Зачислить»
    │
EnrollmentService::enroll()
    ├─ persons (student) + person_documents
    ├─ persons (parent) + person_documents
    ├─ wp_users (student) — создать
    ├─ wp_users (parent) — создать
    ├─ enrollments
    ├─ archive (enrolled_at, contract_no/date, order_no/date, group_key; expelled_at=NULL)
    └─ applications.forceDelete()
```

---

### 10.2 Событие 2A — старый ученик, новый родитель (join)

**Новый поток. Нужны: `ArchiveRepository::restoreAsApplication()`, новый метод в `EnrollmentService`.**

```
Администратор: «Восстановить из архива» (кнопка в archive-view-modal)
    │
ArchiveRepository::restoreAsApplication(archiveId)
    ├─ Читает archive (student_person_id)
    ├─ Читает persons + person_documents ученика
    └─ Создаёт новую application (status=pending_parent) с student_data_enc из данных ученика
       и student_person_id привязан; join_code генерируется заново
    │
join.php (новый родитель заполняет)  →  application (ready_for_review)
    │
EnrollmentService::enrollWithExistingStudent()
    ├─ Проверить: persons[student_person_id] существует, wp_user_id не NULL
    ├─ persons (parent, новый) + person_documents
    ├─ wp_users (parent) — создать
    ├─ enrollments (student_person_id = существующий)
    ├─ archive (новая запись; expelled_at=NULL)
    └─ applications.forceDelete()
```

---

### 10.3 Событие 3B — новый ученик, старый родитель (admin выбирает)

**Новый поток. Нужны: модальное окно выбора родителя, новый метод в `EnrollmentService`.**

```
apply.php → application (pending_parent)
    │
Администратор: вместо отправки join — открывает модальное окно «Выбрать родителя»
    (поиск по ФИО / email среди persons WHERE role='parent')
    │
Администратор выбирает существующего родителя → application (ready_for_review)
    с parent_person_id = выбранный; parent_data_enc = из person_documents родителя
    │
EnrollmentService::enrollWithExistingParent()
    ├─ persons (student, новый) + person_documents
    ├─ wp_users (student) — создать
    ├─ Проверить: persons[parent_person_id] существует, wp_user_id не NULL
    ├─ enrollments
    ├─ archive (enrolled_at, parent_person_id = выбранный; expelled_at=NULL)
    └─ applications.forceDelete()
```

**UI:** в `userlist-1-applications.php` на статусе `PendingParent` добавить кнопку «Назначить родителя» рядом с «Отправить join». Открывает новую модалку `select-parent-modal.php` с поиском по persons.

---

### 10.4 Событие 4B — старый ученик, старый родитель (admin выбирает)

**Новый поток. Комбинация 2 + 3: восстановление из archive + выбор существующего родителя.**

```
Администратор: «Восстановить из архива»
    │
ArchiveRepository::restoreAsApplication(archiveId)
    └─ application (status=pending_parent), student_person_id = из archive
    │
Администратор: «Назначить родителя» → модалка → выбирает существующего
    │
EnrollmentService::enrollBothExisting()
    ├─ Проверить: persons[student_person_id] существует, wp_user_id не NULL
    ├─ Проверить: persons[parent_person_id] существует, wp_user_id не NULL
    ├─ Новые WP-пользователи НЕ создаются
    ├─ enrollments (оба person_id = существующие)
    ├─ archive (новая запись; expelled_at=NULL)
    └─ applications.forceDelete()
```

---

### 10.5 Что нужно реализовать (чеклист)

**Сервисы:**
- [x] `EnrollmentService` — рефакторинг: `enroll()` использует `app.student_person_id`/`app.parent_person_id` (все 4 пути); добавлены `restoreFromArchive()`, `selectExistingParent()`
- [x] `EnrollmentService::restoreFromArchive()` — создаёт заявку на основе archive-записи (читает snapshot из enrollment)
- [x] Убрать `RelationshipService` из `EnrollmentService` — сделано в пункте 9

**UI (шаблоны):**
- [x] `select-parent-modal.php` — поиск и выбор существующего родителя из `persons WHERE role='parent'`
- [x] Кнопка «Назначить родителя» в `userlist-1-applications.php` (рядом с join-кодом, для статуса PendingParent)
- [x] Кнопка «Восстановить из архива» в `archive-view-modal.php`

**AJAX:**
- [x] `AjaxHook::SelectExistingParent` — привязка parent_person_id к заявке (`EnrollmentCallbacks::ajaxSelectExistingParent`)
- [x] `AjaxHook::RestoreFromArchive` — создание новой заявки из archive-записи (`EnrollmentCallbacks::ajaxRestoreFromArchive`)
- [x] `AjaxHook::SearchParents` — поиск родителей для модалки

---

## Приоритет

```
0. Рефакторинг схемы БД (раздел 9)               ← всё остальное зависит от этого
   └─ 9.1 Миграция → 9.2 Enums → 9.3 DTO → 9.4 Repos → 9.5 Services
1. Рефакторинг потоков зачисления (раздел 10)    ← строится поверх новой схемы
   └─ Начинать с 10.1 (адаптация текущего), потом 10.2→10.3→10.4
2. CSV-экспорт (раздел 8)                        ← карточки и архив готовы
3. Политика паролей + HTTPS-предупреждение       ← безопасность
4. Вкладка «Шаблоны писем» в настройках         ← управление без деплоя
5. Вкладка «Согласия» в настройках              ← управление без деплоя
6. Журналы (AuditLog, PiiAccessLog) в админке   ← compliance
7. Тесты                                         ← стабильность
8. Документация                                  ← передача знаний
```
