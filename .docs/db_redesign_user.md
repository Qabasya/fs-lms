# Редизайн схемы БД: по мнению пользователя

---

## 1. Таблицы
* applications - почти без изменений. Здесь хранятся заявки ДО принятия решения о зачислении.

* persons - данные о пользователях без Пдн

* person_documents - данные о пользователях с Пдн, связь через ID пользователя

* groups - данные о существующих группах

* student_records (бывшая Archive) - главная таблица системы. Здесь хранятся все записи о всей истории обучения. Фактически каждая запись представляет не ученика, а факт обучения по конкретному договору (1 ученик, 1 родитель, 1 предмет, дата зачисления и отчисления)

* Таблицы логов (audit_log, pii_access_log) и согласий (consents) без изменений
---

## 2. Предлагаемая схема

### 2.1 `fs_lms_applications ` — хранилище данных о заявках

```sql
CREATE TABLE fs_lms_applications (
                                     id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,

                                     student_person_id           INT UNSIGNED DEFAULT NULL,
                                     parent_person_id            INT UNSIGNED DEFAULT NULL,

                                     status                      ENUM(
                                                                        'pending_parent',
                                                                        'ready_for_review',
                                                                        'expired'
                                                                    ) NOT NULL,

                                     join_code_hash              CHAR(64) DEFAULT NULL,
                                     join_code_enc               BLOB DEFAULT NULL,
                                     join_code_expires_at        DATETIME DEFAULT NULL,

                                     student_email_hash          CHAR(64) DEFAULT NULL,

                                     student_data_enc            LONGBLOB NOT NULL,
                                     parent_data_enc             LONGBLOB DEFAULT NULL,

                                     converted_record_id         INT UNSIGNED DEFAULT NULL,

                                     parent_submitted_ip         VARBINARY(16) DEFAULT NULL,
                                     parent_submitted_ua         VARCHAR(500) DEFAULT NULL,

                                     reviewed_by_user_id         BIGINT(20) UNSIGNED DEFAULT NULL,

                                     created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                     updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                                     PRIMARY KEY (id),

                                     KEY status (status),
                                     KEY student_person_id (student_person_id),
                                     KEY parent_person_id (parent_person_id)
);
```

**Что хранится:** Этап 1 (pending_parent) только данные ребёнка (без ИНН и номера документа). Этап 2 (ready_for_review) все данные ребёнка и родителя. Этап 3 - запись удаляется, данные перемещаются в другие таблицы. Для передачи используется DTO

status - из ApplicationStatus Enum, но не все, на момент зачисления статус не нужен, ведь при статусе converted запись уже будет удалена.

---

### 2.2 `fs_lms_persons` — нечувствительные данные по которым можно получить все данные о пользователе

```sql
CREATE TABLE fs_lms_persons (
                                id                INT UNSIGNED NOT NULL AUTO_INCREMENT,

                                last_name         VARCHAR(100) NOT NULL,
                                first_name        VARCHAR(100) NOT NULL,
                                middle_name       VARCHAR(100) DEFAULT NULL,

                                birth_date        DATE DEFAULT NULL,

                                role              ENUM('student','parent') NOT NULL,

                                wp_user_id        BIGINT(20) UNSIGNED DEFAULT NULL,

                                created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                deleted_at        DATETIME DEFAULT NULL,

                                PRIMARY KEY (id),

                                KEY role (role),
                                KEY wp_user_id (wp_user_id),
                                KEY full_name (last_name, first_name, middle_name)
);
```

**Что хранится:** ФИО человека (раздельными полями), дата рождения (для более точной идентификации), его роль, адрес его профиля wp_user_id и даты. Через ключ id можем получать данные из других таблиц.Для передачи используется DTO

---

### 2.3 `fs_lms_person_documents` — все PII (чувствительные данные) в зашифрованном виде

```sql
CREATE TABLE fs_lms_person_documents (
                                         id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,

                                         person_id             INT UNSIGNED NOT NULL,

                                         email_enc             BLOB DEFAULT NULL,
                                         email_hash            CHAR(64) DEFAULT NULL,

                                         phone_enc             BLOB DEFAULT NULL,
                                         phone_hash            CHAR(64) DEFAULT NULL,

                                         doc_type              VARCHAR(30) DEFAULT NULL,

                                         doc_number_enc        BLOB DEFAULT NULL,
                                         doc_number_hash       CHAR(64) DEFAULT NULL,

                                         doc_issued_by_enc     BLOB DEFAULT NULL,
                                         doc_issued_date       DATE DEFAULT NULL,

                                         inn_enc               BLOB DEFAULT NULL,
                                         inn_hash              CHAR(64) DEFAULT NULL,

                                         address_enc           BLOB DEFAULT NULL,

                                         created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                         updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                                         PRIMARY KEY (id),

                                         UNIQUE KEY person_id (person_id),

                                         KEY email_hash (email_hash),
                                         KEY phone_hash (phone_hash),
                                         KEY doc_number_hash (doc_number_hash),
                                         KEY inn_hash (inn_hash)
);
```

**Что хранится:** все чувствительные данные. Они хранятся в обезличенном виде. Для их получения используется person_id из таблицы fs_lms_persons. У ученика не заполнены поля: doc_issued_by_enc, doc_issued_date, address_enc. Для передачи используется DTO

---

### 2.4 `fs_lms_groups` — данные о группах

```sql
CREATE TABLE fs_lms_groups (
                               id                    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,

                               academic_period_id    VARCHAR(50) NOT NULL,

                               subject_key              VARCHAR(50) NOT NULL,

                               group_id                 VARCHAR(100) NOT NULL,

                               name                     VARCHAR(255) NOT NULL,

                               teacher_id               INT UNSIGNED DEFAULT NULL,

                               schedule              TEXT DEFAULT NULL,

                               created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                               updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                               deleted_at            DATETIME DEFAULT NULL,

                               PRIMARY KEY (id),

                               UNIQUE KEY group_key (group_key),

                               KEY subject_key (subject_key),
                               KEY academic_year_key (academic_year_key)
);
```

**Что хранится:** данные о группе. 

* `academic_period_id` строго из `AcademicPeriodDTO->id`
* `subject_key` строго из `SubjectViewDTO`
* `group_id` и остальные данные о группе строго из `StudentGroupDTO`

---

### 2.5 `fs_lms_student_records` — данные о факте обучения

```sql
CREATE TABLE fs_lms_student_records (
                                        id                         INT UNSIGNED NOT NULL AUTO_INCREMENT,

                                        student_person_id          INT UNSIGNED NOT NULL,
                                        parent_person_id           INT UNSIGNED NOT NULL,

                                        group_id                   SMALLINT UNSIGNED NOT NULL,

                                        contract_no                VARCHAR(50) DEFAULT NULL,
                                        contract_date              DATE DEFAULT NULL,

                                        order_no                   VARCHAR(50) DEFAULT NULL,
                                        order_date                 DATE DEFAULT NULL,

                                        status                     ENUM(
                                            'active',
                                            'finished',
                                            'expelled',
                                            'transferred'
                                            ) NOT NULL DEFAULT 'active',

                                        enrolled_at                DATETIME NOT NULL,

                                        expelled_at                DATETIME DEFAULT NULL,

                                        expelled_by_user_id        BIGINT(20) UNSIGNED DEFAULT NULL,

                                        expel_reason               VARCHAR(500) DEFAULT NULL,

                                        created_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                        updated_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                                        PRIMARY KEY (id),

                                        KEY student_person_id (student_person_id),
                                        KEY parent_person_id (parent_person_id),
                                        KEY group_id (group_id),
                                        KEY status (status),
                                        KEY enrolled_at (enrolled_at),
                                        KEY expelled_at (expelled_at)
);
```
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
    │
    ├─► student_records — student_person_id, parent_person_id,
    │             contract_no, contract_date, order_no, order_date,
    │             group_id, enrolled_at  [expelled_at = NULL]
    │
    └─► applications.forceDelete(id)
```

Всё внутри одной транзакции `inTransaction()` в `EnrollmentService`.

---

## 4. Особенности

Записывать любые изменения данных в логи. 

Иметь возможность перевести запись из student_records обратно в Заявки для перезачисления студента (как при отчислении, так и без отчисления, например, на еще одно направление). Продумать универсальность.

Связь ученик - группа хранится в student_records
Связь родитель(и) - ученик(и) хранится в student_records
