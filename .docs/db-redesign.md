# Редизайн схемы БД: разделение PII и поток зачисления

> Актуальна для ветки `stage_10`. Версия документа: 2026-06-05.

---

## 1. Что меняется и почему

| Было | Станет | Причина |
|---|---|---|
| `persons` — всё вместе (ФИО enc + doc enc + inn enc + phone enc + email plain) | `persons` (plain ФИО + DOB + роль) + `person_documents` (всё PII зашифровано) | Чёткая граница: persons — идентификация, person_documents — весь PII |
| Email хранится открыто в `persons` | Email зашифрован в `person_documents`, hash для поиска | Email — персональные данные наравне с телефоном |
| `relationships` (guardian → student) | Связь родитель→ученик хранится в `archive` (student_person_id + parent_person_id); отдельная таблица не нужна | Archive уже создаётся при зачислении и содержит обоих участников |
| `expelled_archive` — заполняется только при отчислении | `archive` — заполняется при зачислении, expelled_at = NULL | Архив с первого дня, а не только при выходе |
| Группы в `wp_options` (матрица group_id → [user_ids]) | `groups` — полноценная таблица; `enrollments` и `archive` ссылаются только на `group_key` | Убрать дублирование subject_key + period_key во всех дочерних таблицах |
| Нет отдельной таблицы активных учеников | `enrollments WHERE status='active'` — уже содержит всё нужное; таблица `students` не создаётся | Не дублировать данные без причины |

---

## 2. Предлагаемая схема

### 2.1 `fs_lms_persons` — идентификация (нечувствительные данные)

```sql
CREATE TABLE fs_lms_persons (
    id            int unsigned        NOT NULL AUTO_INCREMENT,
    wp_user_id    bigint(20) unsigned DEFAULT NULL,  -- bigint: должен совпадать с wp_users.ID
    full_name     varchar(255)        NOT NULL DEFAULT '',
    birth_date    date                DEFAULT NULL,
    role          enum('student','parent') NOT NULL,
    deleted_at    datetime            DEFAULT NULL,
    created_at    datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY wp_user_id (wp_user_id),
    KEY role (role)
);
```

**Что хранится:** имя, дата рождения, роль. Никаких контактов и документов. ФИО хранится открыто — осознанный компромисс: сотрудники работают с именами ежедневно, при этом ФИО без паспорта и контактов не позволяет установить личность достаточно для злоупотребления.

---

### 2.2 `fs_lms_person_documents` — весь PII (зашифровано, всегда с маскированием в UI)

```sql
CREATE TABLE fs_lms_person_documents (
    id                  int unsigned  NOT NULL AUTO_INCREMENT,
    person_id           int unsigned  NOT NULL,
    email_enc           blob          DEFAULT NULL,
    email_hash          char(64)      DEFAULT NULL,
    phone_enc           blob          DEFAULT NULL,
    phone_hash          char(64)      DEFAULT NULL,
    doc_type            varchar(30)   DEFAULT NULL,
    doc_number_enc      blob          DEFAULT NULL,
    doc_number_hash     char(64)      DEFAULT NULL,
    doc_issued_by_enc   blob          DEFAULT NULL,  -- кем выдан; только для родителей
    doc_issued_date     date          DEFAULT NULL,   -- дата выдачи; только для родителей
    inn_enc             blob          DEFAULT NULL,
    inn_hash            char(64)      DEFAULT NULL,
    address_enc         blob          DEFAULT NULL,   -- только для родителей
    PRIMARY KEY  (id),
    UNIQUE KEY person_id (person_id),
    KEY email_hash (email_hash),
    KEY phone_hash (phone_hash),
    KEY doc_number_hash (doc_number_hash),
    KEY inn_hash (inn_hash)
);
```

**Разделение по роли (одна таблица, часть полей NULL):**

| Поле | Ученик | Родитель |
|---|---|---|
| email_enc / email_hash | ✓ | ✓ |
| phone_enc / phone_hash | ✓ | ✓ |
| doc_type, doc_number_enc / hash | ✓ | ✓ |
| inn_enc / inn_hash | ✓ | ✓ |
| doc_issued_by_enc | — | ✓ |
| doc_issued_date | — | ✓ |
| address_enc | — | ✓ |

Поля `doc_issued_by_enc`, `doc_issued_date`, `address_enc` для учеников всегда `NULL`. Роль известна из `persons.role`.

**Тип blob вместо longblob.** Зашифрованный текст для этих полей не превысит 64 КБ (`blob`). `longblob` (4 ГБ) здесь избыточен.

**Замечание про email и WP.** Email по-прежнему хранится открыто в `wp_users.user_email` — это таблица WordPress, вне нашей зоны контроля. В `person_documents` мы шифруем свою копию. При поиске "занят ли email" — `email_hash` или `UserManager::findByEmail()`.

---

### 2.3 `fs_lms_groups` — группы (заменяет матрицу в `wp_options`)

```sql
CREATE TABLE fs_lms_groups (
    id          smallint unsigned   NOT NULL AUTO_INCREMENT,  -- школьных групп не бывает тысячи
    group_key   varchar(100)        NOT NULL,
    subject_key varchar(50)         NOT NULL,
    period_key  varchar(50)         NOT NULL,
    name        varchar(255)        DEFAULT NULL,
    schedule    varchar(500)        DEFAULT NULL,             -- расписание: «Пн, Ср 10:00–11:30»
    created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    UNIQUE KEY group_key (group_key),
    KEY subject_key (subject_key),
    KEY period_key (period_key)
);
```

`group_key` — тот же строковый id, что сейчас в матрице `wp_options`. `enrollments` и `archive` хранят только `group_key`; subject, period и schedule берутся JOIN-ом. **Матрица `StudentGroupMatrix` из `wp_options` удаляется** — эта таблица её полностью заменяет: список учеников группы = `SELECT student_person_id FROM enrollments WHERE group_key = X AND status = 'active'`.

**Отдельная таблица `students` не создаётся.** Список активных учеников = `SELECT FROM enrollments WHERE status = 'active'`. Дублирующая таблица без новых данных не нужна.

---

### 2.4 `fs_lms_archive` — архив зачислений (переименован из `expelled_archive`)

```sql
CREATE TABLE fs_lms_archive (
    id                    int unsigned  NOT NULL AUTO_INCREMENT,
    enrollment_id         int unsigned  DEFAULT NULL,
    student_person_id     int unsigned  NOT NULL,
    parent_person_id      int unsigned  NOT NULL,
    contract_no           varchar(50)   DEFAULT NULL,
    contract_date         date          DEFAULT NULL,
    order_no              varchar(50)   DEFAULT NULL,
    order_date            date          DEFAULT NULL,
    group_key             varchar(100)  DEFAULT NULL,
    enrolled_at           datetime      NOT NULL,
    expelled_at           datetime      DEFAULT NULL,
    expelled_by_user_id   bigint(20) unsigned DEFAULT NULL,  -- bigint: WP user ID
    reason                varchar(500)  DEFAULT NULL,
    created_at            datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY enrollment_id (enrollment_id),
    KEY student_person_id (student_person_id),
    KEY parent_person_id (parent_person_id),
    KEY group_key (group_key),
    KEY expelled_at (expelled_at)
);
```

**Запись создаётся при зачислении** (`expelled_at = NULL`). При отчислении — `UPDATE SET expelled_at = NOW()`. Subject, period и schedule не хранятся — берутся `JOIN groups ON archive.group_key = groups.group_key`.

**Восстановление не записывается.** При повторном зачислении (например, в другую группу) создаётся новая запись в archive. Поля `restored_at` и `restored_by_user_id` убраны.

**Связь родитель→ученик хранится здесь.** Найти родителя ученика: `SELECT parent_person_id FROM archive WHERE student_person_id = X AND expelled_at IS NULL`. Если ученик учится на нескольких предметах — несколько строк archive с одним `parent_person_id`. Отдельная таблица `parents` не нужна.

---

### 2.5 Таблицы без изменений

| Таблица | Статус |
|---|---|
| `fs_lms_enrollments` | без изменений |
| `fs_lms_applications` | без изменений |
| `fs_lms_consents` | без изменений |
| `fs_lms_audit_log` | без изменений |
| `fs_lms_pii_access_log` | без изменений |
| `fs_lms_relationships` | **удаляется** |
| `fs_lms_expelled_archive` | **переименовывается** в `fs_lms_archive` |

---

## 3. Поток зачисления (новый)

После срабатывания события Enroll:

```
applications (student_data_enc, parent_data_enc)
    │
    ▼ расшифровать
    │
    ├─► persons (student) — full_name, birth_date, role='student'
    │       └─► person_documents — email_enc, phone_enc, doc_number_enc, inn_enc, address_enc
    │           (+ хэши для каждого поля)
    │
    ├─► persons (parent) — full_name, birth_date, role='parent'
    │       └─► person_documents — email_enc, phone_enc, doc_number_enc, inn_enc, address_enc
    │           (+ хэши для каждого поля)
    │
    ├─► enrollments — student_person_id, group_key, snapshot_enc
    │
    ├─► archive — enrollment_id, student_person_id, parent_person_id,
    │             contract_no, contract_date, order_no, order_date,
    │             group_key, enrolled_at  [expelled_at = NULL]
    │
    └─► applications.forceDelete(id)
```

Всё внутри одной транзакции `inTransaction()` в `EnrollmentService`.

---

## 4. Затронутые файлы при переходе

| Файл | Изменение |
|---|---|
| `inc/Migrations/Migration_1_0_0.php` | Новые таблицы: `groups`, `person_documents`, `parents`, `archive`; DROP: `relationships`, `expelled_archive` |
| `inc/Enums/TableName.php` | Добавить: `Groups`, `PersonDocuments`, `Parents`, `Archive`; удалить: `Relationships`, `ExpelledArchive`; убрать `PersonContacts` если был |
| `inc/DTO/PersonDTO.php` | Разбить на `PersonDTO` + `PersonDocumentsDTO` |
| `inc/Repositories/WPDBRepositories/PersonRepository.php` | Разбить на `PersonRepository` + `PersonDocumentsRepository` |
| `inc/Repositories/OptionsRepositories/StudentGroupMatrixRepository.php` | Перевести на `GroupsRepository` (WPDB) |
| `inc/Repositories/WPDBRepositories/RelationshipRepository.php` | Удалить |
| `inc/Repositories/WPDBRepositories/ExpelledArchiveRepository.php` | Переименовать в `ArchiveRepository`, обновить поля |
| `inc/Services/Enrollment/EnrollmentService.php` | Писать в `person_documents`; убрать `RelationshipService`; убрать `StudentGroupMatrixRepository`; убрать запись в `parents` |
| `inc/Services/Person/RelationshipService.php` | Удалить |
| `inc/Enums/RelationType.php` | Удалить |

---

## 5. Итоговая граница разделения данных

```
persons                          person_documents
────────────────────────         ──────────────────────────────────────────
full_name   varchar  plain       email_enc       longblob  зашифрован
birth_date  date     plain       email_hash      varchar64 для поиска
role        enum     plain       phone_enc       longblob  зашифрован
wp_user_id  bigint   plain       phone_hash      varchar64 для поиска
                                 doc_type        varchar30 plain (тип, не номер)
                                 doc_number_enc  longblob  зашифрован
                                 doc_number_hash varchar64 для поиска
                                 inn_enc         longblob  зашифрован
                                 inn_hash        varchar64 для поиска
                                 address_enc     longblob  зашифрован
```

**Принцип:** если данные позволяют установить контакт с человеком или подтвердить его личность — они шифруются. ФИО без контактов и документов недостаточно для злоупотребления, поэтому остаётся plain.

**JOIN overhead** минимален: один JOIN по `UNIQUE KEY person_id` = lookup по B-tree индексу, одна страница данных. На практике незаметно.

---

## 6. Диаграмма новой схемы

```
groups (group_key, subject_key, period_key)
  │
  │ group_key ◄──────────────────────────────────┐
  │                                               │
persons (full_name, birth_date, role)             │
  │ 1:1                                           │
  └──── person_documents (email_enc, phone_enc,   │
  │                       doc_number_enc, inn_enc, address_enc)
  │                                               │
  │ (role='student') ──── enrollments ─────────────────┤
  │                                                    │
  └──── archive (student_person_id, parent_person_id,  │
                 contract_no, contract_date,            │
                 order_no, order_date,                  │
                 group_key ─────────────────────────────┘
                 enrolled_at, expelled_at)
       ↑ связь родитель→ученик тоже здесь
```

