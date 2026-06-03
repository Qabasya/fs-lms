# FS LMS — Оставшиеся задачи

Версия: 2026-06-02  
Статус: вся инфраструктура реализована. Остаётся: карточки пользователей, отчисление, CSV-экспорт, настройки, безопасность, тесты, документация.

---

## Что уже реализовано

**Инфраструктура:**
- Все enum-ы, все репозитории, все сервисы, все DTO, все shared traits
- `UserManager`, `RoleManager`, `CronManager`, `MigrationRunner`, `Migration_1_0_0` (единственная, всё слито)
- `PiiCryptoService`, `PiiMaskingService`, `PersonReader`, `PersonService`
- `EnrollmentService` (включая snapshot с contract_no/contract_date/order_no/order_date)
- `EmailService` + strategy pattern (`WpOptionsEmailTemplate` / `PhpEmailTemplate`)
- `CsvExportService` + `CsvColumn` — Column Projection, одноразовые ссылки `/lms/export/{token}`

**Контроллеры** (все зарегистрированы в Init.php):
- `ApplicationController` — маршруты `/lms/apply`, `/lms/join/{code}`
- `EnrollmentController` — AJAX зачисления и корзины (отдельной страницы карточки заявки нет)
- `PiiController` — AJAX PII, страница карточки person
- `RecoveryController` — cron-задачи, TTL ссылки пароля (48ч для LMS-ролей)
- `ConsentController` — маршрут `/lms/consent/{type}/{version}`

**Callbacks** (все методы реализованы):
- `ApplicationCallbacks` — ajaxSendOtpCode, ajaxCreateApplication, ajaxSubmitParentData
- `EnrollmentCallbacks` — весь цикл заявок (список, карточка, зачисление, корзина, редактирование)
- `PiiCallbacks` — reveal, soft-delete, add/replace representative, update person, renderPersonDetailPage
- `RecoveryCallbacks` — все cron-тики

**Шаблоны — готовы:**
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

### 1.1. Модальные окна с данными ученика и родителя
Вызываются через нажатие кнопки "Просмотреть" в таблицах Ученики и Родители.

### Вид карточки для ученика:
* Фамилия Имя Отчество, номер договора

* Предмет, Группа, Расписание

* Телефон, почта

* Логин пароль

* Школа, класс

– маска --

* Данные паспорта, ИНН, дата рождения


### Вид карточки для родителя:

* Фамилия Имя Отчество. Роль

* Телефон, почта
* ФИО подопечного

  – маска --

* Пароль

* Данные паспорта, ИНН (Родителя). Дата рождения родителя

* Данные паспорта, ИНН (ребёнка). Дата рождения ребёнка

* Прописка

Действия (кнопки) в модальном окне:
* Закрыть модальное окно
* Редактировать (вход в режим редактирования, все данные становятся инпутами (редактировать можно ВСЕ поля, кроме "Предмет, Группа и Расписание" у ученика и "Пароль" у родителя), логгирование)
* Показать данные (маски у всех данных убираются, показываются дешифрованные персональные данные)
* Экпорт (данные в csv, реализуется позже)
* Удалить (пользователя). Требуется дополнительное окно подтверждения confirm-modal

---

### 1.2 `userlist-5-archive.php` — ✅ реализовано

`EnrollmentRepository::list()` поддерживает массив статусов (`IN (...)`).  
Источник: `['status' => ['expelled', 'finished', 'transferred']]`.  
Колонки: ФИО, Направление, Группа, Статус, Дата завершения, Причина, Действия.  
Модалка `archive-view-modal` — аккордеон: данные ребёнка / родителя / зачисления.

---

## 2. Настройки — отсутствующие вкладки

Сейчас в `settings.php` 3 вкладки. Нужно добавить:

### 2.1 Вкладка «Шаблоны писем» (`settings-4-email-templates.php`)

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

### 2.2 Вкладка «Согласия» (`settings-5-consents.php`)

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

### 3.1 Политика паролей

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

### 3.3 HTTPS-предупреждение

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

### 4.1 Страница «Журнал действий»

Подменю FS LMS, доступна по `Capability::ManageApplications`.

Таблица: дата, пользователь, роль, действие, объект, детали.  
Фильтры: action, target_type, actor, date range.  
Данные: `AuditLogRepository::listByTarget()` / `listByActor()`.

### 4.2 Страница «Журнал доступа к ПД»

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

## 7. Отчисление ученика

Кнопка «Отчислить» в карточке ученика (`person-detail.php`) или в таблице учеников (`userlist-2-students.php`).

### 7.1 Бэкенд

**`AjaxHook`**: добавить `case ExpelStudent = 'expel_student'`.

**`EnrollmentCallbacks::ajaxExpelStudent()`**:
- `$this->authorize(Nonce::Manager, Capability::ManageApplications)`
- Принимает: `enrollment_id`, `reason` (текст причины)
- Вызывает `EnrollmentService::expel(int $enrollmentId, string $reason, int $actorId)`

**`EnrollmentService::expel()`**:
1. Найти enrollment, проверить что статус `active`
2. `EnrollmentRepository::update()` — установить `status = expelled`, `terminated_at = now()`, `terminated_reason`, `terminated_by_user_id`
3. Удалить WP-пользователей ученика и родителя (`wp_delete_user()`), если у родителя нет других активных подопечных
4. Удалить ученика из матрицы группы (`StudentGroupMatrixRepository::removeStudent()`)
5. Записать в `AuditService`: `AuditAction::ExpelStudent`

**Регистрация** в `EnrollmentController::ajaxActions()`.

### 7.2 Фронтенд

Модальное окно подтверждения с полем «Причина отчисления» (обязательное).  
После успеха — строка исчезает из таблицы Ученики, появляется в Архиве.

### 7.3 Данные в архиве

После `expel()` строка enrollment со статусом `expelled` автоматически попадает в `userlist-5-archive.php` — дополнительных действий не требуется. `snapshot_enc` с момента зачисления остаётся неизменным.

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

## Приоритет

```
1. templates/admin/enrollment/person-detail.php  ← блокирует карточку ученика/родителя
2. Отчисление ученика (раздел 7)                 ← нужно для наполнения архива
3. CSV-экспорт (раздел 8)                        ← зависит от person-detail и архива
4. Политика паролей + HTTPS-предупреждение       ← безопасность
5. Вкладка «Шаблоны писем» в настройках         ← управление без деплоя
6. Вкладка «Согласия» в настройках              ← управление без деплоя
7. Журналы (AuditLog, PiiAccessLog) в админке   ← compliance
8. Тесты                                         ← стабильность
9. Документация                                  ← передача знаний
```
