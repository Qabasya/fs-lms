# FS LMS — Поток зачисления

Версия: 4.0 (обновлено 2026-06-08)  
Статус: реализован полностью (пути 1A–4B). Остатки: `ExportPii` (регистрация хука), `WithdrawConsent` (регистрация хука), журналы в UI, тесты.

---

## 0. Содержание

1. [Цели и принципы](#1-цели-и-принципы)
2. [Архитектурные решения](#2-архитектурные-решения)
3. [Модель данных](#3-модель-данных)
4. [Пользователи WordPress](#4-пользователи-wordpress)
5. [Шифрование персональных данных](#5-шифрование-персональных-данных)
6. [Матрица зачислений — 4 пути](#6-матрица-зачислений--4-пути)
7. [Поток 1A — новый ученик, новый родитель (join)](#7-поток-1a--новый-ученик-новый-родитель-join)
8. [Поток 2A — старый ученик, новый родитель (join)](#8-поток-2a--старый-ученик-новый-родитель-join)
9. [Поток 3B — новый ученик, старый родитель (admin)](#9-поток-3b--новый-ученик-старый-родитель-admin)
10. [Поток 4B — старый ученик, старый родитель (admin)](#10-поток-4b--старый-ученик-старый-родитель-admin)
11. [Общая логика зачисления (шаг 6 во всех потоках)](#11-общая-логика-зачисления-шаг-6-во-всех-потоках)
12. [Отчисление](#12-отчисление)
13. [Edge cases и восстановление](#13-edge-cases-и-восстановление)
14. [Соответствие 152-ФЗ](#14-соответствие-152-фз)
15. [Маппинг на архитектуру плагина](#15-маппинг-на-архитектуру-плагина)

---

## 1. Цели и принципы

- **Identity ≠ Person ≠ StudentRecord.** WP-аккаунт (`wp_users`) — аутентификация. Человек с ПД — запись в `persons`. Факт обучения — запись в `student_records`. Три разных сущности.
- **Связь родитель→ученик** хранится в `student_records.parent_person_id`. Отдельной таблицы relationships нет.
- **Заявка — это intent.** После конвертации заявка физически удаляется. Источник правды — `student_records`.
- **Пользователи не удаляются никогда.** Только soft delete (`deleted_at`). При повторном зачислении существующие записи переиспользуются.
- **PII шифруется на уровне приложения.** `PiiCryptoService` (XSalsa20-Poly1305). Ключ в `wp-config.php`, не в БД.
- **Имя разбито на поля.** `persons` содержит `last_name / first_name / middle_name` (plain text). Только PII-поля (паспорт, ИНН, телефон, email, адрес) хранятся зашифрованными в `person_documents`.
- **Снимков данных нет.** Исторические изменения ПД отслеживаются через `audit_log`; в БД хранятся только текущие данные.
- **Одна таблица для активных и архивных зачислений.** `student_records` объединяет бывшие `enrollments` и `archive`. Статус записи определяет её состояние: `active`, `finished`, `expelled`, `transferred`.
- **`is_student` вместо `role`.** Поле `persons.is_student tinyint(1)` заменяет `role enum('student','parent')`.
- **Все операции с PII логируются** через `pii_access_log`.

---

## 2. Архитектурные решения

### Custom tables (InnoDB)

Все данные зачисления хранятся в **8 custom tables**, не в `wp_options` и не в `wp_posts`. Причина: растущий объём, нужны индексы, транзакции (ACID), JOIN-запросы. Таблицы `enrollments` и `archive` объединены в единую `student_records`.

### Шифрование

- Алгоритм: `sodium_crypto_secretbox` (XSalsa20-Poly1305), PHP 7.2+.
- Ключ: `define('FS_LMS_ENC_KEY', '<base64>')` в `wp-config.php`.
- Хэши: `sha256(value + FS_LMS_HASH_SALT)` для поиска без расшифровки.
- `last_name`, `first_name`, `middle_name` — **plain text** (не шифруются), используются для отображения без PII-логирования.

### Транзакционность

Шаги INSERT в custom tables (persons, person_documents, student_records, consents) выполняются в одной DB-транзакции через `TransactionRunner` trait. Создание WP-пользователей — **вне транзакции**, потому что `wp_insert_user` запускает хуки сторонних плагинов, которые не откатятся.

### Recovery

Если транзакция прошла, но WP-пользователи не были созданы (крэш сервера), application остаётся в статусе `enrolling`. `RecoveryService::resolveStuckEnrollments()` (cron каждые 15 мин) создаёт WP-пользователей и переводит заявку в `converted`.

---

## 3. Модель данных

### 3.1. `fs_lms_persons`

Идентификация физического лица. Только нечувствительные поля — plain text.

```sql
CREATE TABLE fs_lms_persons (
    id          int unsigned        NOT NULL AUTO_INCREMENT,
    wp_user_id  bigint(20) unsigned DEFAULT NULL,
    last_name   varchar(100)        NOT NULL DEFAULT '',
    first_name  varchar(100)        NOT NULL DEFAULT '',
    middle_name varchar(100)        DEFAULT NULL,
    birth_date  date                DEFAULT NULL,
    is_student  tinyint(1)          NOT NULL DEFAULT 1,
    deleted_at  datetime            DEFAULT NULL,
    created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY wp_user_id (wp_user_id),
    KEY is_student (is_student)
);
```

`last_name / first_name / middle_name` — plain text, используются для отображения без PII-логирования. Метод `PersonDTO::fullName()` склеивает их в полное имя.  
`is_student` — `1` для ученика, `0` для родителя/представителя; заменяет бывший `role enum('student','parent')`.

### 3.2. `fs_lms_person_documents`

Весь PII — зашифрован. Одна запись на человека (UNIQUE по person_id).

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
    doc_issued_by_enc blob         DEFAULT NULL,
    doc_issued_date   date         DEFAULT NULL,
    inn_enc           blob         DEFAULT NULL,
    inn_hash          char(64)     DEFAULT NULL,
    address_enc       blob         DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY person_id (person_id),
    KEY email_hash (email_hash),
    KEY phone_hash (phone_hash),
    KEY doc_number_hash (doc_number_hash),
    KEY inn_hash (inn_hash)
);
```

Хэши используются для поиска без расшифровки (дедупликация при повторном зачислении).

### 3.3. `fs_lms_groups`

Группы занятий. `id` — числовой PK (внутренний FK из `student_records`). `group_id` — внешний строковый идентификатор (отображается в UI).

```sql
CREATE TABLE fs_lms_groups (
    id                 smallint unsigned   NOT NULL AUTO_INCREMENT,
    group_id           varchar(100)        NOT NULL,
    subject_key        varchar(50)         NOT NULL,
    academic_period_id varchar(50)         NOT NULL,
    name               varchar(255)        DEFAULT NULL,
    teacher_id         bigint(20) unsigned DEFAULT NULL,
    schedule           varchar(500)        DEFAULT NULL,
    created_at         datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY group_id (group_id),
    KEY subject_key (subject_key),
    KEY academic_period_id (academic_period_id)
);
```

Состав группы: `student_records WHERE group_id = ? AND status = 'active'` — отдельной таблицы «студент в группе» нет.

### 3.4. `fs_lms_applications`

Заявки на зачисление. Жизненный цикл: `pending_parent` → `ready_for_review` → `enrolling` → `converted` (или `expired` / `trash`).

```sql
CREATE TABLE fs_lms_applications (
    id                      bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    student_person_id       bigint(20) unsigned DEFAULT NULL,
    parent_person_id        bigint(20) unsigned DEFAULT NULL,
    status                  varchar(50)         NOT NULL,
    join_code_hash          varchar(64)         DEFAULT NULL,
    join_code_enc           blob                DEFAULT NULL,
    join_code_expires_at    datetime            DEFAULT NULL,
    student_email_hash      varchar(64)         DEFAULT NULL,
    student_data_enc        longblob            DEFAULT NULL,
    parent_data_enc         longblob            DEFAULT NULL,
    converted_record_id     bigint(20) unsigned DEFAULT NULL,
    parent_submitted_ip     varchar(45)         DEFAULT NULL,
    parent_submitted_ua     varchar(500)        DEFAULT NULL,
    reviewed_by_user_id     bigint(20) unsigned DEFAULT NULL,
    created_at              datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ...
);
```

`converted_record_id` — ID записи в `student_records` (бывший `converted_to_enrollment_id`).  
Поля `student_person_id` и `parent_person_id` заполняются только в путях 2A, 3B, 4B — когда person уже существует в базе.

### 3.5. `fs_lms_student_records`

Единая таблица факта зачисления. Заменяет бывшие `enrollments` + `archive`. Хранит как активные, так и завершённые/отчисленные записи.

```sql
CREATE TABLE fs_lms_student_records (
    id                    int unsigned        NOT NULL AUTO_INCREMENT,
    student_person_id     int unsigned        NOT NULL,
    parent_person_id      int unsigned        DEFAULT NULL,
    group_id              smallint unsigned   DEFAULT NULL,
    contract_no           varchar(50)         DEFAULT NULL,
    contract_date         date                DEFAULT NULL,
    order_no              varchar(50)         DEFAULT NULL,
    order_date            date                DEFAULT NULL,
    status                enum('active','finished','expelled','transferred') NOT NULL DEFAULT 'active',
    enrolled_at           datetime            NOT NULL,
    expelled_at           datetime            DEFAULT NULL,
    expelled_by_user_id   bigint(20) unsigned DEFAULT NULL,
    expel_reason          varchar(500)        DEFAULT NULL,
    created_at            datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY student_person_id (student_person_id),
    KEY parent_person_id (parent_person_id),
    KEY group_id (group_id),
    KEY status (status),
    KEY expelled_at (expelled_at)
);
```

Активные зачисления: `status = 'active'`.  
Архивные: `status IN ('expelled', 'finished', 'transferred')`.  
Текущий родитель ученика: `SELECT parent_person_id FROM student_records WHERE student_person_id = ? AND status = 'active' LIMIT 1`.

### 3.6. `fs_lms_consents`, `fs_lms_audit_log`, `fs_lms_pii_access_log`

Без изменений. Consents — согласия с фиксацией версии текста, IP, UA. Audit log — бизнес-события. PII access log — каждый decrypt для отображения.

### 3.7. ER-схема

```
applications
    │ (converted_record_id)
    ▼
student_records ──── student_person_id ──► persons ──── wp_user_id ──► wp_users
                └─── parent_person_id  ──► persons
                └─── group_id (int FK) ──► groups

persons ◄─── person_documents  (UNIQUE person_id)
```

---

## 4. Пользователи WordPress

### Роли (`UserRole` enum)

| Slug | Enum case | Назначение |
|---|---|---|
| `lms_student` | `FSStudent` | Ученик — доступ к материалам |
| `lms_parent` | `FSParent` | Родитель — доступ к данным своего ребёнка |
| `lms_teacher` | `FSTeacher` | Преподаватель |
| `lms_student_free` | `Student` | Внешний/свободный ученик |
| `lms_teacher_free` | `Teacher` | Внешний/свободный преподаватель |

### Capabilities (`Capability` enum)

| Capability | WP capability | Назначение |
|---|---|---|
| `Admin` | `manage_options` | Полный доступ |
| `ManageApplications` | `fs_lms_manage_applications` | Работа с заявками, списки учеников |
| `EnrollStudent` | `fs_lms_enroll_student` | Кнопка «Зачислить» |
| `ViewPII` | `fs_lms_view_pii` | Просмотр расшифрованных данных |
| `ExportPII` | `fs_lms_export_pii` | CSV-экспорт ПД |
| `ManagePersons` | `fs_lms_manage_persons` | Редактирование данных person, смена представителя |
| `ViewLMSStats` | `fs_lms_view_stats` | Статистика |
| `ManageLMSAssignments` | `fs_lms_manage_assignments` | Управление заданиями |
| `EnrollStudent` | `fs_lms_enroll_student` | Зачисление |

### Usermeta

| Ключ | Значение |
|---|---|
| `fs_lms_person_id` | ID записи из `fs_lms_persons` |

### Создание пользователей

Создаётся в post-transaction части `EnrollmentService::enroll()` через `UserManager::create()`. Логин = email (или `student_{person_id}` если email пуст). При повторном зачислении (`persons.wp_user_id` уже заполнен) — новый WP-пользователь не создаётся.

---

## 5. Шифрование персональных данных

### Ключи

```php
// wp-config.php
define('FS_LMS_ENC_KEY',         '<base64-32-bytes>');
define('FS_LMS_HASH_SALT',       '<random-string>');
define('FS_LMS_OTP_BYPASS_CODE', 'optional-bypass'); // опционально
```

Guard в `fs-lms.php`: если `FS_LMS_ENC_KEY` не определён — `Init::run()` не вызывается, показывается `admin_notices`. Плагин не крашит.

### PersonService — разделение записи

При создании person `PersonService::createOrFindBy(PersonInputDTO)`:

1. Ищет в `person_documents` по `doc_number_hash` (дедупликация).
2. Если не найден: INSERT в `persons` (last_name, first_name, middle_name, birth_date, is_student).
3. INSERT в `person_documents` (все enc/hash-поля).

При обновлении `PersonService::update(personId, changes, actorId)`:
- `last_name`, `first_name`, `middle_name`, `birth_date` → UPDATE `persons`
- email, phone, doc_number, inn, address → UPDATE `person_documents`

### PersonReader — единственная точка чтения PII

`PersonReader::readForDisplay(personId, fields, reason)`:
1. Загружает `PersonDTO` из `persons` (имя — plain text, без лога).
2. Загружает `PersonDocumentsDTO` из `person_documents` (для зашифрованных полей).
3. Расшифровывает только запрошенные поля.
4. Пишет запись в `pii_access_log`.
5. Возвращает `PersonDecryptedDTO`.

Прямой вызов `PiiCryptoService::decrypt()` в Callbacks запрещён. Только через PersonReader (или явно через PersonService при копировании данных между слоями).

---

## 6. Матрица зачислений — 4 пути

| # | Ученик | Родитель | Как родитель привязывается |
|---|---|---|---|
| **1A** | Новый | Новый | Заполняет join-форму самостоятельно |
| **2A** | Старый (из архива) | Новый | Заполняет join-форму самостоятельно |
| **3B** | Новый | Старый (уже в системе) | Админ выбирает из существующих |
| **4B** | Старый (из архива) | Старый (уже в системе) | Администратор выбирает из существующих |

Во всех путях финальный шаг — вызов `EnrollmentService::enroll()`, который автоматически определяет нужную ветку по полям `application.student_person_id` и `application.parent_person_id`.

---

## 7. Поток 1A — новый ученик, новый родитель (join)

Самый частый случай. Ученик подаёт заявку, родитель заполняет join-форму.

### Шаг 1 — Ученик заполняет заявку (`/lms/apply`)

**Форма:** ФИО, логин, пароль, email, школа, класс, дата рождения, телефон, согласие на ПД, капча.

**Двухэтапный OTP-поток:**

**Шаг A** — `AJAX nopriv: send_otp_code`
```
1. Nonce::Apply verify
2. Rate limit: 5 попыток с IP в час
3. Капча (пропускается при FS_LMS_TEST_ENV)
4. EmailOtpService::sendCode($email)
5. → показать экран ввода кода
```

**Шаг B** — `AJAX nopriv: create_application`
```
1. Nonce::VerifyOtp verify
2. EmailOtpService::verify($email, $code)
   (или FS_LMS_OTP_BYPASS_CODE)
3. Проверить нет ли уже активной заявки по email
4. Сгенерировать JOIN-код (JoinCodeService)
5. INSERT applications: status=pending_parent,
   student_data_enc=..., join_code_hash=..., expires=+48h
6. INSERT consents (ученик, от себя)
7. INSERT audit_log: create_application
8. → вернуть join_url = /lms/join/{code}
```

Данные в `applications.student_data_enc`: JSON `{last_name, first_name, middle_name, email, phone, school, grade, birth_date, doc_type, doc_number, inn, username, login_password}`.

### Шаг 2 — Родитель открывает join-ссылку (`/lms/join/{code}`)

```
GET:
1. hash = crypto->hash(code)
2. SELECT application WHERE join_code_hash=? AND status=pending_parent
   AND join_code_expires_at > NOW()
3. Расшифровать student_data_enc → показать форму
   с предзаполненными данными ученика

POST: submit_parent_data (nopriv)
1. Nonce::ParentSubmit verify
2. Санитизация + валидация
3. UPDATE applications:
   parent_data_enc = encrypt(parentData),
   status = ready_for_review
4. INSERT consents (родитель от себя + за ребёнка)
5. INSERT audit_log: submit_parent_data
6. → уведомление админу
```

Данные в `applications.parent_data_enc`: JSON `{last_name, first_name, middle_name, birth_date, doc_type, doc_number, doc_issued_by, doc_issued_date, inn, address, phone, email}`.

### Шаг 3 — Администратор просматривает список заявок

Страница: `?page=fs_lms_userlist&tab=tab-1` → `userlist-1-applications.php`.  
Защита: `Capability::ManageApplications`.

Для статуса `PendingParent` доступны действия:
- **Изменить** данные ученика (модалка `application-modal`)
- **Скопировать join-ссылку** (join_code_enc расшифровывается, ссылка в буфер)
- **+ Назначить родителя** (переход к пути 3B/4B)

Для статуса `ReadyForReview` доступны:
- **Изменить** (редактирование данных ученика и родителя, модалка `application-review-modal`)
- **Зачислить** (переход к шагу 5)

### Шаг 4 — Начало зачисления

`AJAX: start_enrollment` — переводит заявку в статус `enrolling` (блокировка от параллельного клика).  
`AJAX: cancel_enrollment` — откат в `ready_for_review`.

### Шаг 5 — Модалка «Зачислить» (`application-enrollment-modal`)

Поля: номер договора, дата договора, номер приказа, дата приказа, дата зачисления, направление, группа.

Выбор группы — каскадный: период → направление → группа (числовой `id` из `fs_lms_groups`). Подгрузка групп: `AJAX: get_student_groups`.

Submit → `AJAX: enroll_student` → `EnrollmentService::enroll()` (см. раздел 11).

После успеха: возвращаются логин/пароль ученика и родителя. Заявка физически удаляется (`forceDelete`).

---

## 8. Поток 2A — старый ученик, новый родитель (join)

Сценарий: ученик ранее обучался, был отчислен, семья подаёт документы снова с новым родителем.

### Шаг 1 — Администратор восстанавливает из архива

В модалке архивного зачисления (`archive-view-modal`) нажимает «Восстановить из архива».

`AJAX: restore_from_archive` → `EnrollmentService::restoreFromArchive(recordId)`:
```
1. Найти StudentRecordDTO по record_id
2. Найти PersonDTO ученика (record.student_person_id)
3. Найти PersonDocumentsDTO ученика → расшифровать поля
4. Собрать studentData из persons + person_documents (текущие данные)
5. Сгенерировать новый JOIN-код
6. INSERT applications:
   student_person_id = record.student_person_id,  ← pre-linked!
   student_data_enc = encrypt(studentData),
   status = pending_parent,
   join_code_hash = ..., expires = +48h
7. Вернуть {id: appId, join_url: "/lms/join/{code}"}
```

Ключевое отличие от пути 1A: `application.student_person_id` уже заполнен → при зачислении PersonService не создаст дубль. Данные берутся из текущих значений в `persons` / `person_documents` (снимков нет).

### Шаг 2 — Родитель заполняет join-форму

Аналогично пути 1A (шаг 2). Форма показывает предзаполненные данные ученика.

### Шаги 3-5

Аналогично 1A. `EnrollmentService::enroll()` видит `app.studentPersonId != null` → пропускает создание студента, переиспользует существующую запись в `persons`.

---

## 9. Поток 3B — новый ученик, старый родитель (admin)

Сценарий: у родителя уже есть старший ребёнок, теперь он привозит младшего. Ученик — новый, родитель — уже в системе.

### Шаг 1 — Ученик подаёт заявку

Стандартный путь 1A шаг 1. Заявка создаётся со статусом `pending_parent`.

### Шаг 2 — Администратор назначает существующего родителя

В таблице заявок рядом с join-кодом для статуса `PendingParent` есть кнопка «+ Назначить родителя».

Открывается `select-parent-modal`:
- Поле поиска по имени/email среди `lms_parent`-пользователей
- `AJAX: search_parents` → список из `PersonRepository::findByIsStudent(false)`

После выбора:

`AJAX: select_existing_parent` → `EnrollmentService::selectExistingParent(appId, parentPersonId)`:
```
1. Найти application (status = pending_parent)
2. Найти PersonDTO родителя (PersonRepository)
3. Найти PersonDocumentsDTO родителя → расшифровать поля
4. Собрать parentData JSON из persons + person_documents
5. UPDATE applications:
   parent_person_id = parentPersonId,   ← pre-linked!
   parent_data_enc = encrypt(parentData),
   status = ready_for_review
```

После этого заявка переходит в `ready_for_review` без участия родителя.

### Шаги 3-5

Аналогично 1A. `EnrollmentService::enroll()` видит `app.parentPersonId != null` → пропускает создание родителя, переиспользует существующую запись.

---

## 10. Поток 4B — старый ученик, старый родитель (admin)

Сценарий: ученик возвращается в систему, его прежний родитель тоже уже есть в базе.

Комбинация путей 2A и 3B: сначала восстановление из архива, затем выбор существующего родителя.

### Шаг 1 — Восстановление из архива

`AJAX: restore_from_archive` → создаётся заявка с `student_person_id` заполненным.

### Шаг 2 — Назначение существующего родителя

`AJAX: select_existing_parent` → заявка получает `parent_person_id` и переходит в `ready_for_review`.

### Шаги 3-5

`EnrollmentService::enroll()` видит оба поля заполненными → не создаёт ни студента, ни родителя, не создаёт новых WP-пользователей. Только создаёт новую `student_records` запись с правильными `student_person_id` и `parent_person_id`.

---

## 11. Общая логика зачисления (шаг 6 во всех потоках)

`EnrollmentService::enroll(EnrollmentInputDTO)` — единственная точка зачисления для всех 4 путей.

```php
class EnrollmentInputDTO {
    public int    $applicationId;
    public string $contractNo;
    public string $contractDate;
    public string $orderNo;
    public string $orderDate;
    public string $enrolledAt;
    public int    $groupId;        // числовой FK → fs_lms_groups.id
    public bool   $sendEmailAuto;
}
```

### Pre-flight (вне транзакции)

```
1. Authorize: Nonce::Enroll + Capability::EnrollStudent
2. app = applicationRepository->find(applicationId)
3. Проверить status == Enrolling
4. Расшифровать student_data_enc + parent_data_enc
5. Определить existingStudent:
   - если app.studentPersonId != null → personRepository->find(app.studentPersonId)
   - иначе → personDocumentsRepository->findByDocNumberHash(hash) → find по ID
6. Определить existingGuardian — аналогично через app.parentPersonId
7. Проверить email-конфликт в wp_users
```

### Транзакция (custom tables)

```
1. studentPersonId = existingStudent?.id ?? personService->createOrFindBy(studentInput)
2. guardianPersonId = existingGuardian?.id ?? personService->createOrFindBy(guardianInput)
3. recordId = studentRecordRepository->create({
       student_person_id = studentPersonId,
       parent_person_id  = guardianPersonId,
       group_id          = input->groupId,
       contract_no, contract_date, order_no, order_date,
       status = 'active',
       enrolled_at
   })
4. consentService->bindToPersons(appId, {self: studentPersonId, guardian: guardianPersonId})
5. auditService->record(EnrollStudent, 'student_record', recordId)
COMMIT
```

### Post-transaction (внешние эффекты)

```
1. Если student.wpUserId == null → userManager->create() + personRepository->setWpUser()
2. Если guardian.wpUserId == null → userManager->create() + personRepository->setWpUser()
3. applicationRepository->forceDelete(appId)
4. Если sendEmailAuto → emailService->sendWelcomeWithCredentials()
5. Вернуть EnrollmentResultDTO {recordId, studentLogin, studentPassword, guardianLogin, guardianPassword, partialFailure}
```

### Recovery при partial failure

Если post-transaction упал, student_record уже создан в БД, но WP-пользователи могут быть не созданы. Application остаётся в статусе `enrolling`.

`RecoveryService::resolveStuckEnrollments()` (cron каждые 15 мин):
```
Найти application WHERE status=enrolling AND updated_at < NOW()-5min
Если app.studentPersonId == null → вернуть в ready_for_review
Если studentPersonId заполнен:
  record = studentRecordRepository->findActiveByStudentFirst(app.studentPersonId)
  Если record == null → вернуть в ready_for_review
  Иначе → создать WP-пользователей для persons без wp_user_id
          → applicationRepository->markConverted(appId, record.id)
```

---

## 12. Отчисление

`ExpulsionService::expel(studentWpUserId, reason)` — вызывается из `ExpulsionCallbacks::ajaxExpelStudent()`.

```
1. personRepository->findByWpUserId(studentWpUserId)
2. studentRecordRepository->findActiveByStudent(studentPersonId)
   → получить record (с parentPersonId)
3. studentRecordRepository->setExpelled(record.id, now, actorId, reason)
   → UPDATE student_records SET status='expelled', expelled_at=now,
     expelled_by_user_id=actorId, expel_reason=reason
4. personRepository->softDelete(studentPersonId)
5. personRepository->softDelete(parentPersonId)  -- если есть
6. userManager->delete(studentWpUserId)
7. userManager->delete(parentWpUserId)           -- если есть
8. auditService->record(StudentExpelled, 'student_record', record.id)
```

После отчисления:
- Запись в `student_records` имеет `status = 'expelled'`, `expelled_at != null`.
- В таб «Архив» (`userlist-5-archive.php`) строка появляется.
- Кнопка «Восстановить из архива» запускает путь 2A или 4B.

**JS-сторона:** `ExpelModal` (одиночное и массовое). Одиночное — из карточки ученика (`.js-expel-student` на `student-person-modal`). Массовое — `StudentsTable` → `ExpelModal.openBulk(students[])`. После успеха стреляет `$(document).trigger('fs:student:expelled', { studentId })` — закрывает все открытые person-модалки.

**Экспорт записи об отчислении:** `AJAX: export_expelled_record` → `ExpulsionCallbacks::ajaxExportExpelledRecord()` → CSV через одноразовый токен (`/lms/export/{token}`).

---

## 13. Edge cases и восстановление

### Повторная заявка от того же ученика

При создании заявки (шаг B потока 1A) проверяется `student_email_hash` — нет ли активной заявки от этого email. Если есть → сообщение "у вас уже есть незавершённая заявка".

### Родитель уже в системе (другой ребёнок)

При зачислении `personService->findByDocNumberHash(guardianDocHash)` найдёт существующий `person_id` → новый person не создаётся, новый WP-пользователь не создаётся. Создаётся только новая `student_records` запись с тем же `parent_person_id`.

### Конфликт email

Если email родителя уже занят другим WP-пользователем → `DomainException`. Решение вручную: использовать путь 3B/4B и выбрать существующего пользователя.

### JOIN-код истёк

Заявка переходит в `expired` ночным cron. Ученик подаёт заявку заново (путь 1A).

### Замена представителя

Через страницу `?page=fs-lms-person-detail` → вкладка «Представители» → кнопка «Заменить»:

`AJAX: replace_representative` → `PiiCallbacks::ajaxReplaceRepresentative()`:
```
1. Найти student_record WHERE id = recordId
2. Создать/найти нового родителя через personService->createOrFindBy()
3. studentRecordRepository->update(recordId, {parent_person_id: newGuardianId})
```

### Добавление второго представителя

`AJAX: add_representative` → `PiiCallbacks::ajaxAddRepresentative()`:
```
1. Найти активную student_record через
   studentRecordRepository->findActiveByStudentFirst(studentPersonId)
2. Создать/найти родителя
3. studentRecordRepository->update(recordId, {parent_person_id: newGuardianId})
```

Одна `student_records` запись хранит одного родителя (`parent_person_id`). Несколько представителей — несколько активных записей.

### Запрос на удаление ПД (152-ФЗ)

`AJAX: request_pii_deletion` → `PersonService::softDelete(personId)` → `persons.deleted_at = now`.  
Через 30 дней retention job: `PersonService::anonymize(personId)` → обнуляет все enc-поля в `person_documents`.  
WP-пользователь блокируется: `UserManager::randomizePassword()`.

---

## 14. Соответствие 152-ФЗ

| Требование | Реализация |
|---|---|
| Согласие на обработку | `fs_lms_consents` — версия текста, хеш, IP, UA, timestamp |
| Защита ПД от НСД | XSalsa20-Poly1305 шифрование, ACL по capabilities, маскирование в UI |
| Журнал доступа к ПД | `pii_access_log` при каждом вызове `PersonReader::readForDisplay()` |
| Право на доступ | `ExportPii` — одноразовый CSV по одноразовой ссылке |
| Право на удаление | Soft delete + retention anonymization через `PersonService::softDelete/anonymize` |
| Ограничение срока хранения | `RetentionService` cron: удаление заявок, обезличивание persons |
| Минимизация данных | `persons` — только plain-text идентификация; `person_documents` — все PII |

---

## 15. Маппинг на архитектуру плагина

### Таблицы → Репозитории

| Таблица | Репозиторий |
|---|---|
| `fs_lms_persons` | `PersonRepository` |
| `fs_lms_person_documents` | `PersonDocumentsRepository` |
| `fs_lms_groups` | `GroupsRepository` |
| `fs_lms_applications` | `ApplicationRepository` |
| `fs_lms_student_records` | `StudentRecordRepository` |
| `fs_lms_consents` | `ConsentRepository` |
| `fs_lms_audit_log` | `AuditLogRepository` |
| `fs_lms_pii_access_log` | `PiiAccessLogRepository` |

### Сервисы

| Сервис | Назначение |
|---|---|
| `PiiCryptoService` | Шифрование/расшифровка/хеширование |
| `PersonService` | Запись persons + person_documents |
| `PersonReader` | Чтение PII с автологированием в pii_access_log |
| `PiiMaskingService` | Маскирование значений для отображения |
| `JoinCodeService` | Генерация, хеширование, валидация JOIN-кодов |
| `EnrollmentService` | Оркестрация всех 4 путей зачисления; `enroll()`, `restoreFromArchive()`, `selectExistingParent()` |
| `ExpulsionService` | Отчисление: обновление student_records + soft delete |
| `ApplicationService` | Создание заявки с OTP (путь 1A шаг B) |
| `ConsentService` | Привязка согласий к persons при зачислении |
| `AuditService` | Запись в audit_log |
| `RecoveryService` | Cron: дозачисление WP-пользователей после partial failure |
| `RetentionService` | Cron: обезличивание persons, purge заявок/логов |
| `EmailService` | Отправка welcome-письма с логином/паролем |

### AJAX-хуки — зарегистрированные (по контроллеру)

#### ApplicationController → ApplicationCallbacks (nopriv)

| Хук (`jsAction`) | Callback | Nonce |
|---|---|---|
| `send_otp_code` | `ajaxSendOtpCode` | `Nonce::Apply` |
| `create_application` | `ajaxCreateApplication` | `Nonce::VerifyOtp` |
| `submit_parent_data` | `ajaxSubmitParentData` | `Nonce::ParentSubmit` |

#### EnrollmentController → EnrollmentCallbacks

| Хук (`jsAction`) | Callback | Nonce |
|---|---|---|
| `enroll_student` | `ajaxEnrollStudent` | `Nonce::Enroll` |
| `start_enrollment` | `ajaxStartEnrollment` | `Nonce::Enroll` |
| `cancel_enrollment` | `ajaxCancelEnrollment` | `Nonce::Enroll` |
| `get_application_data` | `ajaxGetApplicationData` | `Nonce::Manager` |
| `update_application_data` | `ajaxUpdateApplicationData` | `Nonce::EditApplication` |
| `update_review_data` | `ajaxUpdateReviewData` | `Nonce::ReviewApplication` |
| `move_application_to_trash` | `ajaxMoveApplicationToTrash` | `Nonce::TrashApplication` |
| `restore_application_from_trash` | `ajaxRestoreApplicationFromTrash` | `Nonce::TrashApplication` |
| `empty_applications_trash` | `ajaxEmptyApplicationsTrash` | `Nonce::TrashApplication` |
| `delete_application` | `ajaxDeleteApplication` | `Nonce::TrashApplication` |
| `get_student_groups` | `ajaxGetStudentGroups` | `Nonce::Manager` |
| `reveal_user_credentials` | `ajaxRevealUserCredentials` | `Nonce::RevealPii` |
| `regenerate_user_password` | `ajaxRegenerateUserPassword` | `Nonce::RevealPii` |
| `restore_from_archive` | `ajaxRestoreFromArchive` | `Nonce::RestoreFromArchive` |
| `select_existing_parent` | `ajaxSelectExistingParent` | `Nonce::SelectExistingParent` |
| `search_parents` | `ajaxSearchParents` | `Nonce::Manager` |

#### PiiController → PiiCallbacks

| Хук (`jsAction`) | Callback | Nonce |
|---|---|---|
| `reveal_pii_field` | `ajaxRevealPiiField` | `Nonce::RevealPii` |
| `reveal_all_person_pii` | `ajaxRevealAllPersonPii` | `Nonce::RevealPii` |
| `request_pii_deletion` | `ajaxRequestPiiDeletion` | `Nonce::RequestPiiDeletion` |
| `add_representative` | `ajaxAddRepresentative` | `Nonce::AddRepresentative` |
| `replace_representative` | `ajaxReplaceRepresentative` | `Nonce::ReplaceRepresentative` |
| `update_person` | `ajaxUpdatePerson` | `Nonce::UpdatePerson` |
| `get_person_data` | `ajaxGetPersonData` | `Nonce::Manager` |

#### ExpulsionController → ExpulsionCallbacks

| Хук (`jsAction`) | Callback | Nonce |
|---|---|---|
| `expel_student` | `ajaxExpelStudent` | `Nonce::Expulsion` |
| `export_expelled_record` | `ajaxExportExpelledRecord` | `Nonce::Expulsion` |

#### Незарегистрированные (в AjaxHook enum, не подключены ни к одному контроллеру)

| Хук | Статус |
|---|---|
| `export_pii` | ⚠️ Хук объявлен в enum, используется в JS (`StudentPersonModalManager._export()`), nonce передаётся в `fs_lms_applications_vars.nonces.exportPii`, но `add_action('wp_ajax_export_pii')` нигде не вызывается |
| `withdraw_consent` | ⚠️ Хук объявлен в enum, контроллер не зарегистрирован |

### Nonce enum — полный список

`Nonce::X->create()` вызывается в `Enqueue.php` и передаётся через `wp_localize_script`. `Nonce::X->verify()` проверяет в Callbacks.

| Nonce case | Передаётся в |
|---|---|
| `Apply` | `fs_lms_apply_vars.nonces` (frontend) |
| `VerifyOtp` | `fs_lms_apply_vars.nonces` (frontend) |
| `ParentSubmit` | `fs_lms_apply_vars.nonces` (frontend) |
| `Enroll` | `fs_lms_applications_vars.nonces.enroll` |
| `TrashApplication` | `fs_lms_applications_vars.nonces.trash` |
| `EditApplication` | `fs_lms_applications_vars.nonces.edit` |
| `ReviewApplication` | `fs_lms_applications_vars.nonces.review` |
| `Manager` | `fs_lms_applications_vars.nonces.manager` + `fs_lms_vars.nonces.manager` |
| `RevealPii` | `fs_lms_applications_vars.nonces.revealPii` |
| `UpdatePerson` | `fs_lms_applications_vars.nonces.updatePerson` |
| `ExportPii` | `fs_lms_applications_vars.nonces.exportPii` |
| `RequestPiiDeletion` | `fs_lms_applications_vars.nonces.deletePii` |
| `RestoreFromArchive` | `fs_lms_applications_vars.nonces.restoreFromArchive` |
| `SelectExistingParent` | `fs_lms_applications_vars.nonces.selectExistingParent` |
| `AddRepresentative` | `fs_lms_person_vars.nonces.add_representative` |
| `ReplaceRepresentative` | `fs_lms_person_vars.nonces.replace_representative` |
| `Expulsion` | `fs_lms_vars.nonces.expulsion` |

### `wp_localize_script` — глобальные переменные

| Переменная | Страница | Содержит |
|---|---|---|
| `fs_lms_vars` | Все страницы плагина | `ajaxurl`, `nonces.{subject,manager,expulsion}`, `ajax_actions` (все хуки) |
| `fs_lms_applications_vars` | `fs_lms_userlist` | `nonces.{trash,edit,review,enroll,manager,revealPii,updatePerson,exportPii,deletePii,restoreFromArchive,selectExistingParent}` |
| `fs_lms_person_vars` | `fs-lms-person-detail` | `nonces.{reveal,update,delete,export,add_representative,replace_representative}` |
| `fs_lms_apply_vars` | Frontend `/lms/apply` | `ajax_url`, `actions`, `nonces`, `captcha_key` |
| `fs_lms_task_data` | Страницы CPT задач | `ajax_url`, `nonce`, `subject_key`, `post_type` |

`fs_lms_vars.ajax_actions` генерируется через `AjaxHook::toJsArray()` → `['camelCaseName' => 'snake_case_action']`. Всегда использовать `fs_lms_vars.ajax_actions.hookName` вместо raw-строк.

### DTO

| DTO | Для |
|---|---|
| `PersonDTO` | Строка `fs_lms_persons` (last_name, first_name, middle_name; is_student bool); метод `fullName()` |
| `PersonDocumentsDTO` | Строка `fs_lms_person_documents` (enc/hash поля) |
| `PersonInputDTO` | Вход для `PersonService::createOrFindBy()` (split name fields, is_student bool) |
| `PersonDecryptedDTO` | Выход `PersonReader::readForDisplay()` |
| `ApplicationDTO` | Строка `fs_lms_applications` (converted_record_id; поле `student_person_id`/`parent_person_id` только в путях 2A/3B/4B) |
| `StudentRecordDTO` | Строка `fs_lms_student_records` (заменяет EnrollmentDTO + ArchiveDTO) |
| `EnrollmentInputDTO` | Вход для `EnrollmentService::enroll()` (groupId — числовой int FK) |
| `EnrollmentResultDTO` | Выход `EnrollmentService::enroll()` |

### Шаблоны (templates/admin) — зачисление и управление людьми

| Шаблон | Назначение |
|---|---|
| `userlist.php` | Контейнер табов страницы Пользователи |
| `userlist-tabs/userlist-1-applications.php` | Таб «Заявки» — таблица, фильтры, кнопки действий |
| `userlist-tabs/userlist-2-students.php` | Таб «Ученики» — активные зачисления, bulk-отчисление |
| `userlist-tabs/userlist-3-parents.php` | Таб «Родители» — список представителей |
| `userlist-tabs/userlist-4-teachers.php` | Таб «Преподаватели» |
| `userlist-tabs/userlist-5-archive.php` | Таб «Архив» — отчисленные/завершившие |
| `enrollment/person-detail.php` | Карточка лица: данные, представители, зачисления |
| `modals/application-modal.php` | Редактирование данных ученика (PendingParent) |
| `modals/application-review-modal.php` | Редактирование данных ученика+родителя (ReadyForReview) |
| `modals/application-enrollment-modal.php` | Ввод договора/приказа/группы для зачисления |
| `modals/application-view-modal.php` | Read-only просмотр (Enrolling/Converted/Expired/Trash) |
| `modals/select-parent-modal.php` | Поиск и выбор существующего родителя (пути 3B/4B) |
| `modals/archive-view-modal.php` | Просмотр архивного зачисления + кнопка «Восстановить» |
| `modals/student-person-modal.php` | Карточка ученика из таба «Ученики» |
| `modals/parent-person-modal.php` | Карточка родителя из таба «Родители» |
| `modals/expel-modal.php` | Форма отчисления (одиночное и массовое) |

### JS-архитектура (admin) — зачисление

Все файлы — ES6 modules, jQuery object pattern. Сборка Webpack через Gulp.

#### Паттерн Manager → Modal

Каждая модалка — объект с `_initialized` флагом и методом `init()`. Менеджер вызывает `Modal.init()` перед регистрацией событий:

```js
// Правильный паттерн (как в ExpelModalManager):
init() {
    if ( this._initialized ) return;
    this._initialized = true;
    SomeModal.init();          // ← сначала инициализируем модалку
    this._bindEvents();
},
```

#### Менеджеры и их модалки

| Менеджер | Модалка | Страница |
|---|---|---|
| `ApplicationModalManager` | `ApplicationModal` | `fs_lms_userlist`, таб заявки |
| `ApplicationReviewModalManager` | `ApplicationReviewModal` | `fs_lms_userlist`, таб заявки |
| `ApplicationEnrollmentModalManager` | `ApplicationEnrollmentModal` | `fs_lms_userlist`, таб заявки |
| `StudentPersonModalManager` | `StudentPersonModal` | `fs_lms_userlist`, таб ученики |
| `ParentPersonModalManager` | `ParentPersonModal` | `fs_lms_userlist`, таб родители |
| `ExpelModalManager` | `ExpelModal` | глобально (ученики + bulk) |

#### Автономные модалки (без менеджера)

| Модалка | Страница | Назначение |
|---|---|---|
| `ApplicationViewModal` | `fs_lms_userlist`, таб заявки | Read-only просмотр заявки |
| `SelectParentModal` | `fs_lms_userlist`, таб заявки | Поиск и выбор родителя |
| `ArchiveViewModal` | `fs_lms_userlist`, таб архив | Просмотр + восстановление из архива |

#### Сервисы (без модалки)

| Сервис | Страница | Назначение |
|---|---|---|
| `ApplicationsTable` | `fs_lms_userlist` | Корзина, восстановление, удаление заявок |
| `StudentsTable` | `fs_lms_userlist`, таб ученики | Чекбоксы, bulk-отчисление |
| `PersonDetail` | `fs-lms-person-detail` | PII-раскрытие, редактирование, смена представителя |

#### Глобальные события

| Событие | Кто стреляет | Кто слушает |
|---|---|---|
| `fs:student:expelled` | `ExpelModalManager` | `StudentPersonModalManager`, `ParentPersonModalManager` — закрывают свои модалки |
| `fs-lms:spm-regenerate-password` | `StudentPersonModal` | `StudentPersonModalManager._regeneratePassword()` |
| `fs-lms:regenerate-password` | `ParentPersonModal` | `ParentPersonModalManager._regeneratePassword()` |

---

## Приложение A: Чеклист реализации

- [x] DB-схема (`Migration_1_0_0.php`) — 8 таблиц
- [x] PiiCryptoService + guard в fs-lms.php
- [x] Все репозитории: PersonRepository, PersonDocumentsRepository, GroupsRepository, StudentRecordRepository, ApplicationRepository, и др.
- [x] PersonService (двухтаблична), PersonReader (с PII-логированием)
- [x] JoinCodeService, EmailOtpService
- [x] Публичные формы: apply.php, join.php (этапы 1-2)
- [x] ConsentService + согласия
- [x] ApplicationCallbacks: ajaxSendOtpCode, ajaxCreateApplication, ajaxSubmitParentData
- [x] EnrollmentService::enroll() — путь 1A (базовый)
- [x] Путь 2A: EnrollmentService::restoreFromArchive() + ajaxRestoreFromArchive
- [x] Путь 3B: EnrollmentService::selectExistingParent() + select-parent-modal + ajaxSelectExistingParent
- [x] Путь 4B: комбинация 2A+3B (работает автоматически)
- [x] EnrollmentCallbacks: полный цикл (список, карточка, зачисление, корзина, группы)
- [x] Карточки пользователей: student-person-modal + StudentPersonModalManager
- [x] Карточки пользователей: parent-person-modal + ParentPersonModalManager
- [x] Отчисление: ExpulsionController, ExpulsionService, expel-modal, ExpelModalManager
- [x] archive-view-modal с кнопкой «Восстановить из архива»
- [x] RecoveryService (cron, partial failure)
- [x] RetentionService (cron, anonymize, purge)
- [x] EmailService + email-шаблоны
- [x] Карточка лица: person-detail.php + PersonDetail JS-сервис
- [x] Управление представителями: add_representative, replace_representative
- [x] Экспорт записи об отчислении: export_expelled_record → CSV → /lms/export/{token}
- [ ] `export_pii` — хук не подключён к контроллеру (`ajaxExportPii` отсутствует в PiiCallbacks)
- [ ] `withdraw_consent` — хук не подключён к контроллеру
- [ ] Журналы в UI (AuditLog, PiiAccessLog страницы — таблицы есть, экспорт CSV реализован, UI-страницы рендерятся)
- [ ] Тесты (unit + интеграционные)
