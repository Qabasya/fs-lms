# FS LMS — Поток зачисления

Версия: 2.0 (обновлено 2026-06-06)  
Статус: реализован полностью (пути 1A–4B). Остатки: CSV-экспорт, журналы в UI, тесты, документация.

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

- **Identity ≠ Person ≠ Enrollment.** WP-аккаунт (`wp_users`) — аутентификация. Человек с ПД — запись в `persons`. Факт обучения — запись в `enrollments`. Три разных сущности.
- **Связь родитель→ученик** хранится в таблице `archive` (поля `parent_person_id` + `student_person_id`). Отдельной таблицы relationships нет.
- **Заявка — это intent.** После конвертации заявка физически удаляется. Источник правды — `enrollments`.
- **Пользователи не удаляются никогда.** Только soft delete (`deleted_at`). При повторном зачислении существующие записи переиспользуются.
- **PII шифруется на уровне приложения.** `PiiCryptoService` (XSalsa20-Poly1305). Ключ в `wp-config.php`, не в БД.
- **full_name — plain text** в `persons.full_name`. Только PII-поля (паспорт, ИНН, телефон, email, адрес) хранятся зашифрованными в `person_documents`.
- **Запись в archive создаётся при зачислении** (`expelled_at = NULL`) и обновляется при отчислении.
- **Все операции с PII логируются** через `pii_access_log`.

---

## 2. Архитектурные решения

### Custom tables (InnoDB)

Все данные зачисления хранятся в 9 custom tables, не в `wp_options` и не в `wp_posts`. Причина: растущий объём, нужны индексы, транзакции (ACID), JOIN-запросы. Групп и студентов хранится в `wp_options` только конфигурационная часть (предметы, настройки) — не данные.

### Шифрование

- Алгоритм: `sodium_crypto_secretbox` (XSalsa20-Poly1305), PHP 7.2+.
- Ключ: `define('FS_LMS_ENC_KEY', '<base64>')` в `wp-config.php`.
- Хэши: `sha256(value + FS_LMS_HASH_SALT)` для поиска без расшифровки.
- `full_name` — **plain text** (не шифруется), потому что имя нужно для отображения в таблицах без логирования PII-доступа.

### Транзакционность

Шаги INSERT в custom tables (persons, person_documents, enrollments, archive, consents) выполняются в одной DB-транзакции через `TransactionRunner` trait. Создание WP-пользователей — **вне транзакции**, потому что `wp_insert_user` запускает хуки сторонних плагинов, которые не откатятся.

### Recovery

Если транзакция прошла, но WP-пользователи не были созданы (крэш сервера), application остаётся в статусе `enrolling`. `RecoveryService::resolveStuckEnrollments()` (cron каждые 15 мин) создаёт WP-пользователей и переводит заявку в `converted`.

---

## 3. Модель данных

### 3.1. `fs_lms_persons`

Идентификация физического лица. Только нечувствительные поля — plain text.

```sql
CREATE TABLE fs_lms_persons (
    id         int unsigned             NOT NULL AUTO_INCREMENT,
    wp_user_id bigint(20) unsigned      DEFAULT NULL,
    full_name  varchar(255)             NOT NULL DEFAULT '',
    birth_date date                     DEFAULT NULL,
    role       enum('student','parent') NOT NULL,
    deleted_at datetime                 DEFAULT NULL,
    created_at datetime                 NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime                 NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY wp_user_id (wp_user_id),
    KEY role (role)
);
```

`full_name` — plain text, используется для отображения без PII-логирования.  
`role` — `student` или `parent`; помогает фильтровать при поиске родителей для модалки.

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

Хэши используются для поиска без расшифровки (дедупликация при повторном зачислении).

### 3.3. `fs_lms_groups`

Группы — заменяет матрицу из `wp_options`. Группа принадлежит предмету и периоду.

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

Состав группы определяется через `enrollments WHERE group_key = ? AND status = 'active'` — отдельной таблицы "студент в группе" нет.

### 3.4. `fs_lms_applications`

Заявки на зачисление. Жизненный цикл: `pending_parent` → `ready_for_review` → `enrolling` → `converted` (или `expired` / `trash`).

```sql
CREATE TABLE fs_lms_applications (
    id                         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    student_person_id          bigint(20) unsigned DEFAULT NULL,  -- заполнен при restore_from_archive
    parent_person_id           bigint(20) unsigned DEFAULT NULL,  -- заполнен при select_existing_parent
    period_key                 varchar(50)         NOT NULL,
    status                     varchar(50)         NOT NULL,
    join_code_hash             varchar(64)         DEFAULT NULL,
    join_code_enc              blob                DEFAULT NULL,
    join_code_expires_at       datetime            DEFAULT NULL,
    student_email_hash         varchar(64)         DEFAULT NULL,
    student_data_enc           longblob            DEFAULT NULL,
    parent_data_enc            longblob            DEFAULT NULL,
    converted_to_enrollment_id bigint(20) unsigned DEFAULT NULL,
    parent_submitted_ip        varchar(45)         DEFAULT NULL,
    parent_submitted_ua        varchar(500)        DEFAULT NULL,
    reviewed_by_user_id        bigint(20) unsigned DEFAULT NULL,
    created_at                 datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ...
);
```

Поля `student_person_id` и `parent_person_id` заполняются только в путях 2A, 3B, 4B — когда person уже существует в базе.

### 3.5. `fs_lms_enrollments`

Факт зачисления. Связь: один студент → одна группа (group_key).

```sql
CREATE TABLE fs_lms_enrollments (
    id                    int unsigned NOT NULL AUTO_INCREMENT,
    student_person_id     int unsigned NOT NULL,
    source_application_id int unsigned DEFAULT NULL,
    group_key             varchar(100) DEFAULT NULL,   -- ссылка на fs_lms_groups.group_key
    status                varchar(50)  NOT NULL,       -- active | expelled | finished | transferred
    enrolled_at           datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    terminated_at         datetime     DEFAULT NULL,
    terminated_reason     text         DEFAULT NULL,
    terminated_by_user_id int unsigned DEFAULT NULL,
    snapshot_enc          longblob     DEFAULT NULL,   -- JSON с полными данными на момент зачисления
    created_at            datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ...
);
```

`snapshot_enc` — зашифрованный JSON вида `{student:{...}, guardian:{...}, contract_no, contract_date, order_no, order_date, enrolled_at}`. Это слепок всех данных на момент зачисления, не зависящий от последующих изменений в persons.

### 3.6. `fs_lms_archive`

Создаётся при каждом зачислении (`expelled_at = NULL`). При отчислении — обновляется (`expelled_at` заполняется). Хранит связь родитель→ученик.

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

Активные зачисления: `expelled_at IS NULL`.  
Архивные (отчисленные/завершённые): `expelled_at IS NOT NULL`.  
Чтобы найти текущего родителя ученика: `SELECT parent_person_id FROM archive WHERE student_person_id = ? AND expelled_at IS NULL LIMIT 1`.

### 3.7. `fs_lms_consents`, `fs_lms_audit_log`, `fs_lms_pii_access_log`

Без изменений. Consents — согласия с фиксацией версии текста, IP, UA. Audit log — бизнес-события. PII access log — каждый decrypt для отображения.

### 3.8. ER-схема

```
applications
    │ (converted_to_enrollment_id)
    ▼
enrollments ──── student_person_id ──────► persons ──── wp_user_id ──► wp_users
    │                                          │
    └──► archive ──── student_person_id ───────┤
                 └─── parent_person_id  ───────┘
                              │
                persons ──────┘

persons ◄─── person_documents  (UNIQUE person_id)

groups  ◄─── enrollments.group_key
```

---

## 4. Пользователи WordPress

### Роли

| Slug | Используется для |
|---|---|
| `lms_student` | Ученик — доступ к материалам |
| `lms_parent` | Родитель — доступ к данным своего ребёнка |
| `lms_teacher` | Преподаватель |

### Capabilities (Enum `Capability`)

| Capability | Назначение |
|---|---|
| `Admin` = `manage_options` | Полный доступ |
| `ManageApplications` | Работа с заявками, списки учеников |
| `EnrollStudent` | Кнопка «Зачислить» |
| `ViewPII` | Просмотр расшифрованных данных |
| `ExportPII` | CSV-экспорт ПД |
| `ManagePersons` | Редактирование данных person, смена представителя |

### Usermeta

| Ключ | Значение |
|---|---|
| `fs_lms_person_id` | ID записи из `fs_lms_persons` |

### Создание пользователей

Создаётся в post-transaction части `EnrollmentService::enroll()` через `UserManager::create()`. Логин = email (или `student_{person_id}` если email пуст). При повторном зачислении (`persons.wp_user_id` уже заполнен) — новый WP-пользователь не создаётся. Пароль генерируется автоматически и отправляется по email через `EmailService::sendWelcomeWithCredentials()`.

---

## 5. Шифрование персональных данных

### Ключи

```php
// wp-config.php
define('FS_LMS_ENC_KEY',      '<base64-32-bytes>');
define('FS_LMS_HASH_SALT',    '<random-string>');
define('FS_LMS_OTP_BYPASS_CODE', 'optional-bypass'); // опционально
```

Guard в `fs-lms.php`: если `FS_LMS_ENC_KEY` не определён — `Init::run()` не вызывается, показывается `admin_notices`. Плагин не крашит.

### PersonService — разделение записи

При создании person `PersonService::createOrFindBy(PersonInputDTO)`:

1. Ищет в `person_documents` по `doc_number_hash` (дедупликация).
2. Если не найден: INSERT в `persons` (full_name, birth_date, role).
3. INSERT в `person_documents` (все enc/hash-поля).

При обновлении `PersonService::update(personId, changes, actorId)`:
- `full_name`, `birth_date` → UPDATE `persons`
- email, phone, doc_number, inn, address → UPDATE `person_documents`

### PersonReader — единственная точка чтения PII

`PersonReader::readForDisplay(personId, fields, reason)`:
1. Загружает `PersonDTO` из `persons` (для `full_name` — plain text, без лога).
2. Загружает `PersonDocumentsDTO` из `person_documents` (для зашифрованных полей).
3. Расшифровывает только запрошенные поля.
4. Пишет запись в `pii_access_log`.
5. Возвращает `PersonDecryptedDTO`.

Прямой вызов `PiiCryptoService::decrypt()` в Callbacks запрещён. Только через PersonReader (или явно через PersonService при копировании данных между слоями).

### Snapshot в enrollments

`snapshot_enc` — зашифрованный JSON всех данных на момент зачисления:
```json
{
  "student": { "last_name":"...", "first_name":"...", "doc_number":"...", ... },
  "guardian": { "last_name":"...", "phone":"...", ... },
  "contract_no": "...",
  "contract_date": "...",
  "order_no": "...",
  "order_date": "...",
  "enrolled_at": "..."
}
```

Snapshot независим от изменений в `persons`/`person_documents` — это "что было на момент зачисления". Используется в archive-view и при отчислении.

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

Данные в `applications.parent_data_enc`: JSON `{last_name, first_name, middle_name, birth_date, relation_type, doc_type, doc_number, doc_issued_by, doc_issued_date, inn, address, phone, email}`.

### Шаг 3 — Администратор просматривает список заявок

Страница: `?page=fs_lms_userlist&tab=tab-1` → `userlist-1-applications.php`.  
Защита: `Capability::ManageApplications`.

Для статуса `PendingParent` доступны действия:
- **Изменить** данные ученика (модалка)
- **Скопировать join-ссылку** (join_code_enc расшифровывается, ссылка в буфер)
- **+ Назначить родителя** (переход к пути 3B/4B — кнопка рядом с join-кодом)

Для статуса `ReadyForReview` доступны:
- **Изменить** (редактирование данных ученика и родителя, модалка `application-review-modal`)
- **Зачислить** (переход к шагу 5)

### Шаг 4 — Начало зачисления

`AJAX: start_enrollment` — переводит заявку в статус `enrolling` (блокировка от параллельного клика).  
`AJAX: cancel_enrollment` — откат в `ready_for_review`.

### Шаг 5 — Модалка «Зачислить» (`application-enrollment-modal`)

Поля: номер договора, дата договора, номер приказа, дата приказа, дата зачисления, группа (`group_key`).

Submit → `AJAX: enroll_student` → `EnrollmentService::enroll()` (см. раздел 11).

После успеха: возвращаются логин/пароль ученика и родителя. Заявка физически удаляется (`forceDelete`).

---

## 8. Поток 2A — старый ученик, новый родитель (join)

Сценарий: ученик ранее обучался, был отчислен, семья подаёт документы снова с новым родителем (например, сменился опекун).

### Шаг 1 — Администратор восстанавливает из архива

В модалке архивного зачисления (`archive-view-modal`) нажимает «Восстановить из архива».

`AJAX: restore_from_archive` → `EnrollmentService::restoreFromArchive(archiveId)`:
```
1. Найти ArchiveDTO по archive_id
2. Найти PersonDTO ученика (archive.student_person_id)
3. Прочитать snapshot из последнего enrollment
   (восстановить имя, birth_date, email и др.)
4. Сгенерировать новый JOIN-код
5. INSERT applications:
   student_person_id = archive.student_person_id,  ← pre-linked!
   student_data_enc = encrypt(studentData),
   status = pending_parent,
   join_code_hash = ..., expires = +48h
6. Вернуть {id: appId, join_url: "/lms/join/{code}"}
```

Ключевое отличие от пути 1A: `application.student_person_id` уже заполнен → при зачислении PersonService не создаст дубль.

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
- `AJAX: search_parents` → `get_users(['role'=>'lms_parent', 'search'=>...])` → список

После выбора:

`AJAX: select_existing_parent` → `EnrollmentService::selectExistingParent(appId, parentPersonId)`:
```
1. Найти application (status = pending_parent)
2. Найти PersonDTO родителя
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

`EnrollmentService::enroll()` видит оба поля заполненными → не создаёт ни студента, ни родителя, не создаёт новых WP-пользователей. Только создаёт новые `enrollment` и `archive` записи с правильными `student_person_id` и `parent_person_id`.

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
    public string $groupKey;
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
8. Проверить двойное зачисление: enrollmentRepository->existsActive(student, groupKey)
```

### Транзакция (custom tables)

```
1. studentPersonId = existingStudent?.id ?? personService->createOrFindBy(studentInput)
2. guardianPersonId = existingGuardian?.id ?? personService->createOrFindBy(guardianInput)
3. enrollmentId = enrollmentRepository->create({
       student_person_id, group_key, enrolled_at, status='active',
       snapshot_enc = encrypt({student, guardian, contract_no, ...}),
       source_application_id
   })
4. archiveRepository->create({
       enrollment_id, student_person_id, parent_person_id = guardianPersonId,
       contract_no, contract_date, order_no, order_date,
       group_key, enrolled_at, expelled_at=NULL
   })
5. consentService->bindToPersons(appId, {self: studentPersonId, guardian: guardianPersonId})
6. auditService->record(EnrollStudent, enrollment, enrollmentId)
COMMIT
```

### Post-transaction (внешние эффекты)

```
1. Если student.wpUserId == null → userManager->create() + personRepository->setWpUser()
2. Если guardian.wpUserId == null → userManager->create() + personRepository->setWpUser()
3. applicationRepository->forceDelete(appId)
4. Если sendEmailAuto → emailService->sendWelcomeWithCredentials()
5. Вернуть EnrollmentResultDTO {enrollmentId, studentLogin, studentPassword, guardianLogin, guardianPassword, partialFailure}
```

### Recovery при partial failure

Если post-transaction упал (крэш сервера между шагами), enrollment уже создан в БД, но WP-пользователи могут быть не созданы. Application остаётся в статусе `enrolling`.

`RecoveryService::resolveStuckEnrollments()` (cron каждые 15 мин):
```
Найти application WHERE status=enrolling AND updated_at < NOW()-5min
Если enrollment не создан → вернуть в ready_for_review
Если enrollment создан → создать WP-пользователей для persons без wp_user_id
                       → applicationRepository->markConverted()
```

---

## 12. Отчисление

`ExpulsionService::expel(studentWpUserId, reason)`:

```
1. personRepository->findByWpUserId(studentWpUserId)
2. enrollmentRepository->findActiveByStudent(studentPersonId)
3. archiveRepository->findByEnrollmentId(enrollment.id)
   → найти archiveRecord (с parentPersonId)
4. archiveRepository->setExpelled(archiveId, now, actorId, reason)
   → UPDATE archive SET expelled_at = now
5. enrollmentRepository->update() → status = Expelled, terminated_at, reason
6. personRepository->softDelete(studentPersonId)
7. personRepository->softDelete(parentPerson.id)  -- если есть
8. userManager->delete(studentWpUserId)
9. userManager->delete(parentWpUserId)            -- если есть
10. auditService->record(StudentExpelled)
```

После отчисления:
- Запись в `archive` теперь имеет `expelled_at != null`.
- В таб «Архив» (`userlist-5-archive.php`) строка появляется.
- Кнопка «Восстановить из архива» запускает путь 2A или 4B.

---

## 13. Edge cases и восстановление

### Повторная заявка от того же ученика

При создании заявки (шаг B потока 1A) проверяется `student_email_hash` — нет ли активной заявки от этого email. Если есть → сообщение "у вас уже есть незавершённая заявка".

### Родитель уже в системе (другой ребёнок)

При зачислении `personService->findByDocNumberHash(guardianDocHash)` найдёт существующий `person_id` → новый person не создаётся, новый WP-пользователь не создаётся. Создаётся только новый enrollment и archive с тем же `parent_person_id`.

### Конфликт email

Если email родителя уже занят другим WP-пользователем (не родителем из `persons`) → `DomainException`. Решение вручную: использовать путь 3B/4B и выбрать существующего пользователя.

### JOIN-код истёк

Заявка переходит в `expired` ночным cron. Ученик подаёт заявку заново (путь 1A).

### Замена представителя

Через модалку `person-detail.php` → вкладка «Представители» → кнопка «Заменить»:

`AJAX: replace_representative` → `PiiCallbacks::ajaxReplaceRepresentative()`:
```
1. Найти archive WHERE id = archiveId
2. Создать/найти нового родителя через personService->createOrFindBy()
3. archiveRepository->update(archiveId, {parent_person_id: newGuardianId})
```

### Добавление второго представителя

`AJAX: add_representative` → `PiiCallbacks::ajaxAddRepresentative()`:
```
1. Найти активную archive-запись для student
2. Создать/найти родителя
3. archiveRepository->update(archiveId, {parent_person_id: newGuardianId})
```

Примечание: архитектура хранит одного родителя на зачисление (`archive.parent_person_id`). Если нужно несколько — создаётся несколько archive-записей.

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
| `fs_lms_enrollments` | `EnrollmentRepository` |
| `fs_lms_archive` | `ArchiveRepository` |
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
| `ExpulsionService` | Отчисление: обновление archive + enrollment + soft delete |
| `ApplicationService` | Создание заявки с OTP (путь 1A шаг B) |
| `ConsentService` | Привязка согласий к persons при зачислении |
| `AuditService` | Запись в audit_log |
| `RecoveryService` | Cron: дозачисление WP-пользователей после partial failure |
| `RetentionService` | Cron: обезличивание persons, purge заявок/логов |
| `EmailService` | Отправка welcome-письма с логином/паролем |

### AJAX-хуки (AjaxHook enum) — зачисление

| Хук | Callback | Описание |
|---|---|---|
| `send_otp_code` | ApplicationCallbacks | Отправить OTP на email |
| `create_application` | ApplicationCallbacks | Создать заявку (шаг B OTP) |
| `submit_parent_data` | ApplicationCallbacks | Родитель заполнил join-форму |
| `start_enrollment` | EnrollmentCallbacks | Блокировка заявки (→ enrolling) |
| `cancel_enrollment` | EnrollmentCallbacks | Откат в ready_for_review |
| `enroll_student` | EnrollmentCallbacks | Финальное зачисление |
| `restore_from_archive` | EnrollmentCallbacks | Восстановить из архива (пути 2A/4B) |
| `select_existing_parent` | EnrollmentCallbacks | Назначить существующего родителя (пути 3B/4B) |
| `search_parents` | EnrollmentCallbacks | Поиск родителей для модалки |
| `get_application_data` | EnrollmentCallbacks | Данные заявки для карточки |
| `update_application_data` | EnrollmentCallbacks | Редактирование данных заявки |
| `update_review_data` | EnrollmentCallbacks | Редактирование данных на проверке |

### Nonce enum — зачисление

`Apply`, `VerifyOtp`, `ParentSubmit`, `Enroll`, `TrashApplication`, `EditApplication`, `ReviewApplication`, `RestoreFromArchive`, `SelectExistingParent`.

### DTO

| DTO | Для |
|---|---|
| `PersonDTO` | Строка `fs_lms_persons` (full_name plain, role) |
| `PersonDocumentsDTO` | Строка `fs_lms_person_documents` (enc/hash поля) |
| `PersonInputDTO` | Вход для `PersonService::createOrFindBy()` |
| `PersonDecryptedDTO` | Выход `PersonReader::readForDisplay()` |
| `ApplicationDTO` | Строка `fs_lms_applications` |
| `EnrollmentDTO` | Строка `fs_lms_enrollments` (group_key, snapshot_enc) |
| `EnrollmentInputDTO` | Вход для `EnrollmentService::enroll()` |
| `EnrollmentResultDTO` | Выход `EnrollmentService::enroll()` |
| `ArchiveDTO` | Строка `fs_lms_archive` |

### Шаблоны (templates/admin)

| Шаблон | Назначение |
|---|---|
| `userlist-1-applications.php` | Таб «Заявки» — таблица, фильтры, кнопки действий |
| `application-modal.php` | Редактирование данных ученика (PendingParent) |
| `application-review-modal.php` | Редактирование данных ученика+родителя (ReadyForReview) |
| `application-enrollment-modal.php` | Ввод договора/приказа/группы для зачисления |
| `application-view-modal.php` | Read-only просмотр (Enrolling/Converted/Expired/Trash) |
| `select-parent-modal.php` | Поиск и выбор существующего родителя (пути 3B/4B) |
| `userlist-5-archive.php` | Таб «Архив» — отчисленные/завершившие |
| `archive-view-modal.php` | Просмотр архивного зачисления + кнопка «Восстановить» |

---

## Приложение A: Чеклист реализации

- [x] DB-схема (`Migration_1_0_0.php`) — 9 таблиц
- [x] PiiCryptoService + guard в fs-lms.php
- [x] Все репозитории: PersonRepository, PersonDocumentsRepository, GroupsRepository, ArchiveRepository, ApplicationRepository, EnrollmentRepository, и др.
- [x] PersonService (двухтаблична), PersonReader (с PII-логированием)
- [x] JoinCodeService, EmailOtpService
- [x] Публичные формы: apply.php, join.php (этапы 1-2)
- [x] ConsentService + согласия
- [x] ApplicationCallbacks: ajaxSendOtpCode, ajaxCreateApplication, ajaxSubmitParentData
- [x] EnrollmentService::enroll() — путь 1A (базовый)
- [x] Путь 2A: EnrollmentService::restoreFromArchive() + ajaxRestoreFromArchive
- [x] Путь 3B: EnrollmentService::selectExistingParent() + select-parent-modal + ajaxSelectExistingParent
- [x] Путь 4B: комбинация 2A+3B (работает автоматически)
- [x] EnrollmentCallbacks: полный цикл (список, карточка, зачисление, корзина)
- [x] Карточки пользователей: student-person-modal, parent-person-modal
- [x] Отчисление: ExpulsionController, ExpulsionService, expel-modal
- [x] archive-view-modal с кнопкой «Восстановить из архива»
- [x] RecoveryService (cron, partial failure)
- [x] RetentionService (cron, anonymize, purge)
- [x] EmailService + email-шаблоны
- [ ] CSV-экспорт (раздел 8 remaining-tasks)
- [ ] Журналы в UI (AuditLog, PiiAccessLog страницы)
- [ ] Тесты (unit + интеграционные)
