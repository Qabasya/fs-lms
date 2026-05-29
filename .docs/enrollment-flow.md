# FS LMS — Спецификация потока зачисления учеников

Версия: 1.0
Статус: проект для реализации

---

## 0. Содержание

1. [Цели и принципы](#1-цели-и-принципы)
2. [Архитектурные решения и обоснование](#2-архитектурные-решения-и-обоснование)
3. [Модель данных](#3-модель-данных)
4. [Пользователи WordPress](#4-пользователи-wordpress)
5. [Шифрование персональных данных](#5-шифрование-персональных-данных)
6. [Согласия на обработку ПД и аудит](#6-согласия-на-обработку-пд-и-аудит)
7. [Безопасность приглашений и паролей](#7-безопасность-приглашений-и-паролей)
8. [Транзакционность и ACID](#8-транзакционность-и-acid)
9. [Поток зачисления — пошагово](#9-поток-зачисления--пошагово)
10. [Edge cases и сценарии восстановления](#10-edge-cases-и-сценарии-восстановления)
11. [Соответствие 152-ФЗ](#11-соответствие-152-фз)
12. [Маппинг на существующую архитектуру плагина](#12-маппинг-на-существующую-архитектуру-плагина)

---

## 1. Цели и принципы

### Цель документа
Описать end-to-end процесс зачисления ученика в FS LMS — от заполнения публичной формы заявки до создания учётных записей ученика и его представителя — на уровне, достаточном для реализации без дополнительных решений со стороны разработчика.

### Базовые принципы

- **Identity ≠ Person ≠ Enrollment.** Учётная запись WP (`wp_users`) — только аутентификационная оболочка. Человек как сущность с ПД — отдельная запись в `wp_lms_persons`. Факт обучения — третья сущность в `wp_lms_enrollments`. Эти концепции не сливаются.
- **Заявка — это intent, не сущность.** Применённая заявка переходит в архив, а не удаляется. Источником правды о действующем зачислении служит `wp_lms_enrollments`.
- **Связь "представитель ↔ ученик" — many-to-many.** Один родитель может представлять несколько детей, у одного ребёнка может быть несколько представителей, связи могут заменяться во времени.
- **PII (персонально идентифицируемая информация)(паспорт, ИНН, СНИЛС, адрес, телефон) шифруется на уровне приложения.** Не at-rest БД — а application-level: дешифровать может только код плагина с ключом из `wp-config`.
- **Никто, кроме самого пользователя, не должен знать его пароль.** Установка пароля — через одноразовую ссылку с TTL.
- **Все операции с PII и зачислением — атомарны или идемпотентны.** Падение между шагами не оставляет систему в противоречивом состоянии.
- **Любой доступ к ПД логируется.** Это требование 152-ФЗ и хорошая практика.

---

## 2. Архитектурные решения и обоснование

### 2.1. Custom tables vs wp_options vs CPT

| Сущность | Хранилище | Почему |
|---|---|---|
| Subjects, настройки auth, boilerplates | `wp_options` | Малый объём, редкие изменения, нужны целиком — текущая архитектура корректна |
| Applications, Enrollments, Persons, Relationships, Consents, Audit log | **Custom tables (InnoDB)** | Растущий объём, нужны индексы, фильтры, транзакции, FK-семантика |
| Tasks, Articles | CPT (текущая архитектура) | Контентные сущности, редактируются через стандартный WP UI |
| Groups | Taxonomy (текущая архитектура) | Иерархическая классификация, готовый UI |

**Почему `wp_options` не подходит для заявок и зачислений:** глобальная key-value таблица не даёт row-level locking, не индексируется, не позволяет атомарные транзакции на уровне отдельных записей, и при autoload загружается целиком в каждый WP-запрос. На объёме 500+ заявок это становится узким местом памяти и DB CPU.

Прецедент: WooCommerce в 2022 году ввёл HPOS (High-Performance Order Storage) и увёл заказы из `wp_posts`/`wp_postmeta` в custom tables ровно по этим причинам. То же делают LifterLMS, LearnDash, Tutor LMS для transactional данных.

### 2.2. Шифрование PII

- **Алгоритм:** XSalsa20-Poly1305 через `sodium_crypto_secretbox` (входит в PHP 7.2+ из коробки).
- **Ключ:** в `wp-config.php` через `define('FS_LMS_ENC_KEY', '<base64>')`. Не в БД, не в коде плагина.
- **Дедупликация:** для поиска "есть ли уже такой паспорт" хранится `sha256(value + app_salt)` в отдельной колонке `*_hash`. Хэш позволяет искать по точному совпадению без раскрытия значения.
- **Маскирование в UI:** по умолчанию документы показываются как `•••• 1234`. Полное значение — только по явному действию админа с записью в audit log.

### 2.3. Транзакционность

Все custom tables создаются с движком InnoDB. Операция зачисления оборачивается в `START TRANSACTION` / `COMMIT` через `$wpdb->query()`. Создание WP-пользователей выносится **за границу транзакции** (т.к. `wp_insert_user` запускает хуки сторонних плагинов, которые не откатятся) — используется паттерн "сначала commit core data, потом — внешние эффекты, со статусной машиной и recovery job".

### 2.4. Соответствие 152-ФЗ

Закон требует: явное согласие на обработку, локализацию ПД граждан РФ на территории РФ (инфраструктура), защиту от НСД (шифрование + ACL + лог доступа), ограничение срока хранения, право субъекта на доступ/изменение/удаление, уведомление об инцидентах. Технические меры реализуются в этом плагине, организационные (уведомление Роскомнадзора, политика обработки ПД, согласие пользовательской документации) — на уровне юрлица-оператора.

---

## 3. Модель данных

Все таблицы создаются с префиксом `$wpdb->prefix` (по умолчанию `wp_`), движок InnoDB, кодировка utf8mb4. Создание через `dbDelta()` при активации плагина (миграция версии).

### 3.1. `{prefix}fs_lms_applications`

Хранит заявки на зачисление в течение всего их жизненного цикла, включая converted и rejected (для аудита).

```sql
CREATE TABLE wp_fs_lms_applications (
    id                          BIGINT UNSIGNED      NOT NULL AUTO_INCREMENT,
    status                      VARCHAR(32)          NOT NULL DEFAULT 'pending_parent',
                                -- pending_parent | ready_for_review | enrolling
                                -- | converted | rejected | expired
    join_code_hash              CHAR(64)             NOT NULL,
                                -- sha256(code + global_salt)
    join_code_expires_at        DATETIME             NOT NULL,

    student_data_enc            BLOB                 NOT NULL,
                                -- зашифрованный JSON: ФИО, email, школа, класс,
                                -- дата рождения, и т.п.
    parent_data_enc             BLOB                 NULL,
                                -- зашифрованный JSON, появляется после шага 2

    submitted_by_ip             VARBINARY(16)        NULL,
    submitted_by_ua             VARCHAR(255)         NULL,
    parent_submitted_ip         VARBINARY(16)        NULL,
    parent_submitted_ua         VARCHAR(255)         NULL,

    converted_to_enrollment_id  BIGINT UNSIGNED      NULL,
    rejected_reason             VARCHAR(500)         NULL,
    reviewed_by_user_id         BIGINT UNSIGNED      NULL,
    reviewed_at                 DATETIME             NULL,

    created_at                  DATETIME             NOT NULL,
    updated_at                  DATETIME             NOT NULL,

    PRIMARY KEY (id),
    KEY idx_status (status),
    KEY idx_join_hash (join_code_hash),
    KEY idx_expires (join_code_expires_at),
    KEY idx_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Замечания по полям:**
- `student_data_enc` / `parent_data_enc` — JSON, полностью зашифрованный целиком. Это допустимо, т.к. до момента зачисления данные не индексируются — поиск идёт по статусу и хэшу JOIN-кода.
- `submitted_by_ip` хранится как VARBINARY(16) (`inet_pton`), а не VARCHAR — компактно, поддерживает IPv6.
- `join_code_hash` — sha256 от кода + соли, сам код в БД не лежит.

### 3.2. `{prefix}fs_lms_persons`

Источник правды о человеке. PII — здесь, в зашифрованном виде.

```sql
CREATE TABLE wp_fs_lms_persons (
    id                  BIGINT UNSIGNED      NOT NULL AUTO_INCREMENT,
    wp_user_id          BIGINT UNSIGNED      NULL,
                        -- NULL допустим: person может существовать до создания
                        -- WP-юзера (или у уволенного — после удаления)

    -- ФИО
    full_name_enc       BLOB                 NOT NULL,
    full_name_hash      CHAR(64)             NOT NULL,
                        -- sha256(normalize(name) + salt); normalize: trim,
                        -- lowercase, схлопывание пробелов

    -- Демография
    birth_date          DATE                 NULL,
    gender              CHAR(1)              NULL,  -- 'm' | 'f' | NULL

    -- Документы
    doc_type            VARCHAR(32)          NULL,
                        -- pass_rf | birth_certificate | foreign_pass
    doc_number_enc      BLOB                 NULL,
    doc_number_hash     CHAR(64)             NULL,  -- для дедупликации
    doc_issued_by_enc   BLOB                 NULL,
    doc_issued_date     DATE                 NULL,

    inn_enc             BLOB                 NULL,
    inn_hash            CHAR(64)             NULL,
    snils_enc           BLOB                 NULL,
    snils_hash          CHAR(64)             NULL,

    -- Контакты
    email               VARCHAR(190)         NULL,  -- не шифруется: логин
    phone_enc           BLOB                 NULL,
    phone_hash          CHAR(64)             NULL,
    address_enc         BLOB                 NULL,

    -- Служебное
    encryption_version  TINYINT UNSIGNED     NOT NULL DEFAULT 1,
                        -- для ротации ключей в будущем
    created_at          DATETIME             NOT NULL,
    updated_at          DATETIME             NOT NULL,
    deleted_at          DATETIME             NULL,
                        -- soft delete; физическое удаление — через retention job

    PRIMARY KEY (id),
    UNIQUE KEY uq_wp_user (wp_user_id),
    KEY idx_doc_hash (doc_hash),
    KEY idx_inn_hash (inn_hash),
    KEY idx_email (email),
    KEY idx_phone_hash (phone_hash),
    KEY idx_full_name_hash (full_name_hash),
    KEY idx_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Замечания:**
- `wp_user_id` — UNIQUE, чтобы один WP-юзер не привязывался к двум persons. Но `NULL` повторно допустим (заявители без созданного юзера).
- `email` хранится открыто — это логин и инструмент поиска. На уровне БД он и так доступен через `wp_users`.
- `encryption_version` — задел под ротацию мастер-ключа: при смене ключа фоновый job перешифровывает строки и инкрементирует поле.

### 3.3. `{prefix}fs_lms_relationships`

Связь "представитель ↔ ученик". Many-to-many, темпорально валидная.

```sql
CREATE TABLE wp_fs_lms_relationships (
    id                  BIGINT UNSIGNED      NOT NULL AUTO_INCREMENT,
    guardian_person_id  BIGINT UNSIGNED      NOT NULL,
    student_person_id   BIGINT UNSIGNED      NOT NULL,

    relation_type       VARCHAR(32)          NOT NULL,
                        -- mother | father | guardian | grandparent | foster | other
    is_primary_contact  TINYINT(1)           NOT NULL DEFAULT 0,
    has_legal_authority TINYINT(1)           NOT NULL DEFAULT 1,
                        -- право подписи документов от имени ребёнка

    valid_from          DATE                 NOT NULL,
    valid_to            DATE                 NULL,
                        -- NULL = действует; смена опекуна = установить valid_to

    created_by_user_id  BIGINT UNSIGNED      NULL,
    created_at          DATETIME             NOT NULL,
    updated_at          DATETIME             NOT NULL,

    PRIMARY KEY (id),
    KEY idx_guardian (guardian_person_id, valid_to),
    KEY idx_student (student_person_id, valid_to),
    UNIQUE KEY uq_active_pair
        (guardian_person_id, student_person_id, valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Поддержка сценариев:**
- **Один родитель, двое детей:** две строки с одинаковым `guardian_person_id`.
- **Двое родителей, один ребёнок:** две строки с одинаковым `student_person_id`, один из них `is_primary_contact = 1`.
- **Смена опекуна:** старой строке проставляется `valid_to`, создаётся новая.
- **Бабушка вместо родителя:** `relation_type = 'guardian'` или `'grandparent'`.

Получить актуальных представителей ребёнка:
```sql
SELECT * FROM wp_fs_lms_relationships
WHERE student_person_id = ?
  AND (valid_to IS NULL OR valid_to > CURDATE());
```

### 3.4. `{prefix}fs_lms_enrollments`

Факт зачисления. Иммутабельный snapshot на момент зачисления + актуальный статус.

```sql
CREATE TABLE wp_fs_lms_enrollments (
    id                  BIGINT UNSIGNED      NOT NULL AUTO_INCREMENT,
    student_person_id   BIGINT UNSIGNED      NOT NULL,

    subject_key         VARCHAR(64)          NOT NULL,
    group_term_id       BIGINT UNSIGNED      NULL,
                        -- ID термина из {subject}_{taxonomy}
    period_key          VARCHAR(32)          NOT NULL,
                        -- например '2026-spring' или '2026-2027'

    contract_no         VARCHAR(64)          NOT NULL,
    contract_date       DATE                 NOT NULL,
    order_no            VARCHAR(64)          NOT NULL,
                        -- номер приказа
    order_date          DATE                 NOT NULL,

    enrolled_at         DATETIME             NOT NULL,
    enrolled_by_user_id BIGINT UNSIGNED      NOT NULL,

    status              VARCHAR(32)          NOT NULL DEFAULT 'active',
                        -- active | finished | expelled | transferred
    terminated_at       DATETIME             NULL,
    terminated_reason   VARCHAR(500)         NULL,
    terminated_by_user_id BIGINT UNSIGNED    NULL,

    snapshot_enc        BLOB                 NOT NULL,
                        -- зашифрованный JSON: полные данные ученика и
                        -- представителя на момент зачисления (на случай,
                        -- если потом данные в persons изменятся, для аудита
                        -- остаётся слепок)

    source_application_id BIGINT UNSIGNED    NULL,

    created_at          DATETIME             NOT NULL,
    updated_at          DATETIME             NOT NULL,

    PRIMARY KEY (id),
    KEY idx_student (student_person_id),
    KEY idx_subject_period (subject_key, period_key),
    KEY idx_group (group_term_id),
    KEY idx_status (status),
    UNIQUE KEY uq_active_enrollment
        (student_person_id, subject_key, period_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

UNIQUE-индекс `uq_active_enrollment` защищает от двойного зачисления одного ученика на один предмет в один период.

### 3.5. `{prefix}fs_lms_consents`

Согласия на обработку ПД с доказательной фиксацией.

```sql
CREATE TABLE wp_fs_lms_consents (
    id                  BIGINT UNSIGNED      NOT NULL AUTO_INCREMENT,
    person_id           BIGINT UNSIGNED      NULL,
                        -- может быть NULL пока persons ещё не создан,
                        -- тогда привязка через application_id
    application_id      BIGINT UNSIGNED      NULL,

    consent_type        VARCHAR(32)          NOT NULL,
                        -- pd_processing | pd_transfer | marketing |
                        -- pd_child_processing (от лица представителя)
    document_version    VARCHAR(32)          NOT NULL,
                        -- версия текста, который подписан
    document_hash       CHAR(64)             NOT NULL,
                        -- sha256 от полного текста на момент подписи

    signed_by_role      VARCHAR(16)          NOT NULL,
                        -- self | guardian (родитель за ребёнка)
    signed_for_person_id BIGINT UNSIGNED     NULL,
                        -- если signed_by_role=guardian — за кого

    signed_at           DATETIME             NOT NULL,
    signed_ip           VARBINARY(16)        NOT NULL,
    signed_ua           VARCHAR(255)         NOT NULL,

    valid_until         DATETIME             NULL,
    withdrawn_at        DATETIME             NULL,
    withdrawn_reason    VARCHAR(500)         NULL,

    created_at          DATETIME             NOT NULL,

    PRIMARY KEY (id),
    KEY idx_person (person_id),
    KEY idx_application (application_id),
    KEY idx_type_active (consent_type, withdrawn_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Важно:** хранится хэш текста согласия, а не сам текст. Тексты версионируются в файлах плагина (`templates/consents/v1/pd_processing.html`), и `document_hash = sha256(file_contents)`. Это даёт доказательство "что именно человек подписал" без дублирования текста в каждой строке.

### 3.6. `{prefix}fs_lms_audit_log`

Журнал бизнес-действий (создание заявки, зачисление, изменение данных).

```sql
CREATE TABLE wp_fs_lms_audit_log (
    id                  BIGINT UNSIGNED      NOT NULL AUTO_INCREMENT,
    actor_user_id       BIGINT UNSIGNED      NULL,
                        -- NULL для анонимных действий (создание заявки)
    actor_role          VARCHAR(32)          NULL,

    action              VARCHAR(64)          NOT NULL,
                        -- create_application | submit_parent_data |
                        -- enroll_student | update_person | etc.
    target_type         VARCHAR(32)          NOT NULL,
                        -- application | person | enrollment | relationship
    target_id           BIGINT UNSIGNED      NULL,

    details_json        TEXT                 NULL,
                        -- что именно поменялось (без значений PII!)

    actor_ip            VARBINARY(16)        NULL,
    actor_ua            VARCHAR(255)         NULL,

    created_at          DATETIME             NOT NULL,

    PRIMARY KEY (id),
    KEY idx_actor (actor_user_id, created_at),
    KEY idx_target (target_type, target_id),
    KEY idx_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.7. `{prefix}fs_lms_pii_access_log`

Отдельный append-only лог именно для **чтения** PII. Разнесён с обычным audit_log потому, что:
- его объём может быть на порядок больше (каждый просмотр карточки = запись),
- его retention может отличаться (152-ФЗ может потребовать длительное хранение),
- к нему может быть отдельный read-access у DPO/комплаенс-офицера.

```sql
CREATE TABLE wp_fs_lms_pii_access_log (
    id                  BIGINT UNSIGNED      NOT NULL AUTO_INCREMENT,
    actor_user_id       BIGINT UNSIGNED      NOT NULL,
    actor_role          VARCHAR(32)          NOT NULL,
    person_id           BIGINT UNSIGNED      NOT NULL,
    fields_accessed     VARCHAR(255)         NOT NULL,
                        -- comma-separated: 'pass,inn,address'
    access_reason       VARCHAR(64)          NULL,
                        -- enrollment_review | data_export | gdpr_request | etc.
    actor_ip            VARBINARY(16)        NOT NULL,
    created_at          DATETIME             NOT NULL,
    PRIMARY KEY (id),
    KEY idx_person_time (person_id, created_at),
    KEY idx_actor_time (actor_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.8. ER-схема (текстом)

```
applications  ──converted_to──>  enrollments
     │                                │
     │ consents.application_id        │
     ▼                                ▼
  consents                       enrollments.student_person_id ──> persons
                                                                      │
                                                                      ├── wp_user_id ──> wp_users
                                                                      │
                                relationships.student_person_id ──────┤
                                relationships.guardian_person_id ─────┘
```

### 3.9. Принципиальная оговорка по FK

WordPress не предписывает использование FOREIGN KEY constraints, и многие плагины их избегают (для совместимости с MySQL/MariaDB конфигами без InnoDB по умолчанию, для совместимости с миграциями). **Рекомендация:** FK на уровне БД **не использовать**, целостность поддерживать на уровне приложения через репозитории. Это согласуется с общим стилем WordPress и упрощает обновления схемы.

---

## 4. Пользователи WordPress

### 4.1. Роли

Зарегистрировать через `add_role()` при активации:

| Slug | Label | Назначение |
|---|---|---|
| `lms_student` | Ученик LMS | Доступ к материалам, своим оценкам |
| `lms_parent` | Представитель ученика | Просмотр данных представляемого |
| `lms_teacher` | Преподаватель | Управление группами, проверка |
| `lms_office` | Офис-менеджер | Управление заявками, зачислением |

Базовый admin (`administrator`) сохраняет полный доступ.

### 4.2. Capabilities

Расширить `Inc\Enums\Capability`:

```php
case ManageApplications = 'fs_lms_manage_applications';
case EnrollStudent      = 'fs_lms_enroll_student';
case ViewPII            = 'fs_lms_view_pii';
case ExportPII          = 'fs_lms_export_pii';
case ManagePersons      = 'fs_lms_manage_persons';
```

Маппинг ролям:

| Capability | admin | lms_office | lms_teacher | lms_parent | lms_student |
|---|---|---|---|---|---|
| `ManageApplications` | ✓ | ✓ | | | |
| `EnrollStudent` | ✓ | ✓ | | | |
| `ViewPII` | ✓ | ✓ | | | |
| `ExportPII` | ✓ | | | | |
| `ManagePersons` | ✓ | ✓ | | | |

`ViewPII` — даёт право увидеть **расшифрованные** значения. Без неё в админке показываются маски.

Доступ родителя к данным своего ребёнка и ученика к своим данным — **не WP capability**, а проверка на уровне приложения (через relationship/ownership).

### 4.3. Поля WP_User

**Стандартные (`wp_users`):**
- `user_login` — для родителя и ученика: их email (если email уникален). Если email конфликтует — сгенерированный логин вида `s_{person_id}`.
- `user_email` — реальный email человека.
- `user_pass` — bcrypt-хэш (стандарт WP). Сгенерированный случайный 64-символьный пароль на момент создания (юзер его никогда не узнает — установит свой через reset link).
- `display_name` — ФИО или Имя Фамилия.
- `user_registered` — стандартный timestamp.

**Usermeta (минимум):**

| Ключ | Тип | Назначение |
|---|---|---|
| `first_name`, `last_name` | string | стандартные WP, ожидаются плагинами |
| `fs_lms_person_id` | int | FK на `wp_fs_lms_persons.id` — якорь к "большому" профилю |
| `fs_lms_user_status` | string | `active` \| `suspended` \| `graduated` — для быстрых фильтров без JOIN |
| `fs_lms_primary_enrollment_id` | int | shortcut для UI ученика (один основной enrollment) |

**Чего в usermeta быть НЕ должно:**
- паспорт, ИНН, СНИЛС, адрес, телефон,
- массив `child_ids` или `parent_ids`,
- история зачислений,
- метаданные родительского/ученического профиля сверх минимума.

Логика: всё, что про **бизнес-домен**, лежит в специализированных таблицах. В usermeta — только то, что нужно WP-инфраструктуре или часто читается без расшифровки PII.

### 4.4. Удаление пользователя

При выпуске или удалении ученика:
- WP_User помечается inactive (роль снимается или меняется на `lms_alumni`), но **не удаляется** — иначе исчезнут авторства комментариев, прогресса и т.д.
- `persons.deleted_at` проставляется при запросе на удаление ПД от субъекта.
- Через retention period фоновый job заменяет зашифрованные поля на NULL, оставляя только обезличенный скелет (id, даты, факт существования).

---

## 5. Шифрование персональных данных

### 5.1. Конфигурация ключа

В `wp-config.php`:

```php
// 32 байта случайных данных в base64
define('FS_LMS_ENC_KEY', 'jK9...base64...==');
define('FS_LMS_HASH_SALT', 'другая случайная строка');
```

Генерация ключа:
```bash
php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"
```

При активации плагина — проверка наличия и валидности ключа. Если нет — плагин не активируется, в админку выводится инструкция.

### 5.2. API шифрования

`Inc\Services\PiiCryptoService`:

```php
final class PiiCryptoService
{
    private string $key;

    public function __construct()
    {
        if (!defined('FS_LMS_ENC_KEY')) {
            throw new \RuntimeException('FS_LMS_ENC_KEY is not defined');
        }
        $this->key = base64_decode(FS_LMS_ENC_KEY, true);
        if (strlen($this->key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Invalid encryption key length');
        }
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        return $nonce . $cipher; // храним nonce||cipher
    }

    public function decrypt(string $blob): string
    {
        $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($blob) < $nonceLen + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Invalid ciphertext');
        }
        $nonce = substr($blob, 0, $nonceLen);
        $cipher = substr($blob, $nonceLen);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed');
        }
        return $plain;
    }

    public function hash(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        return hash('sha256', $normalized . FS_LMS_HASH_SALT);
    }
}
```

### 5.3. Дедупликация и поиск

Хэш позволяет проверять "есть ли уже такой паспорт", не расшифровывая существующие записи:

```php
$hash = $crypto->hash($newPassNumber);
$existing = $personRepo->findByDocHash($hash);
```

Для частичного поиска (по фрагменту телефона, например) хэш не подходит. Если нужен такой поиск — либо ограничивать его правом `ViewPII` с массовой расшифровкой в админ-инструменте, либо хранить отдельный bloom-filter-индекс. **На первой итерации частичный поиск по PII не реализуется.**

### 5.4. Маскирование в UI

Helper `Pii::mask(string $value, string $type): string`:
- `pass`: `4015 •• •••• 1234` (первые 4 + последние 4)
- `inn`: `•••• •••• 1234`
- `phone`: `+7 9•• ••• 12 34`
- `address`: `г. Москва, ••••••`

По умолчанию в любом UI выводится маска. Полное значение — отдельная AJAX-операция `fs_lms_reveal_pii_field`, которая:
1. Проверяет `current_user_can(Capability::ViewPII->value)`.
2. Пишет запись в `pii_access_log`.
3. Возвращает расшифрованное значение.
4. В UI оно показывается на 30 секунд и скрывается.

### 5.5. Ротация ключа

При компрометации или плановой ротации:
1. Новый ключ добавляется как `FS_LMS_ENC_KEY_V2`.
2. Класс `PiiCryptoService` начинает читать `encryption_version` из строки и выбирать ключ.
3. Фоновый WP-cron job перешифровывает строки пачками по 100, инкрементирует `encryption_version`.
4. После полного перехода старый ключ удаляется из `wp-config`.

На первой итерации — только `version = 1`, но колонка должна быть с самого начала.

---

## 6. Согласия на обработку ПД и аудит

### 6.1. Тексты согласий

Хранятся в файлах плагина:
```
templates/consents/
  v1/
    pd_processing.html         -- согласие на обработку
    pd_child_processing.html   -- согласие представителя за ребёнка
    pd_transfer.html           -- передача третьим лицам (если нужно)
```

При деплое новой версии текста — новая папка `v2/`, старые версии **остаются** в репозитории навсегда (для возможности воспроизвести "что именно было подписано Ивановым в 2024 году").

Хэш текста (`document_hash` в `consents`) — sha256 от точного содержимого файла на момент подписи.

### 6.2. UI согласия

В форме заявки и в форме родителя:
- Чекбокс **обязательный** (без него submit невозможен).
- Рядом — ссылка "Прочитать текст согласия" → модалка с полным текстом из текущей версии.
- При submit фиксируется: версия, хэш, IP, UA, timestamp.

### 6.3. Audit log — что писать

Минимальный набор событий:

| action | target_type | когда |
|---|---|---|
| `create_application` | `application` | при создании заявки учеником |
| `submit_parent_data` | `application` | когда родитель заполнил |
| `view_application` | `application` | админ открыл карточку заявки |
| `reject_application` | `application` | отклонение |
| `enroll_student` | `enrollment` | завершено зачисление |
| `terminate_enrollment` | `enrollment` | отчисление |
| `create_relationship` | `relationship` | новая связь представитель↔ученик |
| `replace_relationship` | `relationship` | смена опекуна |
| `update_person` | `person` | изменение данных |
| `consent_signed` | `consents` | подписание |
| `consent_withdrawn` | `consents` | отзыв согласия |
| `password_link_generated` | `wp_user` | сгенерирована ссылка установки пароля |
| `password_set` | `wp_user` | пароль установлен пользователем |

В `details_json` пишутся только **метаданные изменения** (какие поля изменились, без новых значений PII). Пример:
```json
{"changed_fields": ["phone", "address"], "old_phone_hash": "abc123..."}
```

### 6.4. PII Access log — что писать

Каждый раз, когда код вызывает `PiiCryptoService::decrypt()` в контексте показа пользователю (не в фоновых job-ах), пишется запись:
```php
$piiAccessLog->record(
    actor: get_current_user_id(),
    person_id: $personId,
    fields: ['pass', 'inn'],
    reason: 'enrollment_review',
);
```

Реализовать через wrapper `PersonReader::readForDisplay(int $personId, array $fields, string $reason)`, чтобы случайно не забыть логирование.

---

## 7. Безопасность приглашений и паролей

### 7.1. JOIN-код

**Формат:** `JOIN-XXXX-XXXX-XXXX`, где X — символ из алфавита `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (без визуально похожих 0/O/1/I/l). 12 значащих символов → энтропия ≈ 60 бит.

**Генерация:**
```php
function generateJoinCode(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $segments = [];
    for ($s = 0; $s < 3; $s++) {
        $seg = '';
        for ($i = 0; $i < 4; $i++) {
            $seg .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $segments[] = $seg;
    }
    return 'JOIN-' . implode('-', $segments);
}
```

**Хранение:** в БД лежит только `sha256(code + FS_LMS_HASH_SALT)`. Сам код возвращается клиенту единожды, восстановлению не подлежит. Если родитель потерял ссылку — админ либо генерирует новую (с инвалидацией старой), либо ученик подаёт заявку заново.

**TTL:** 14 дней. Хранится в `join_code_expires_at`. Истекшие заявки переводятся в статус `expired` ночным cron-job.

**Rate limiting:** на endpoint `/lms/join/{code}`:
- 10 попыток с одного IP в час → 429.
- 50 попыток на любые коды с одного IP в сутки → блокировка на 24 часа.

Реализация через transient: `fs_lms_join_attempts_{ip_hash}` со списком timestamp-ов.

### 7.2. Установка пароля через одноразовую ссылку

WordPress даёт штатный механизм через `get_password_reset_key()`:

```php
$user = get_user_by('id', $newUserId);
$key = get_password_reset_key($user);
if (is_wp_error($key)) {
    throw new \RuntimeException('Cannot generate reset key');
}
$link = network_site_url(
    sprintf(
        'wp-login.php?action=rp&key=%s&login=%s',
        rawurlencode($key),
        rawurlencode($user->user_login)
    ),
    'login'
);
```

**Свойства штатной ссылки:**
- Срок действия по умолчанию — определяется фильтром `password_reset_expiration` (стандартно 24 часа).
- После использования — `user_activation_key` обнуляется, ссылка становится недействительной.
- На странице `wp-login.php?action=rp` родитель видит свой логин (предзаполнен) и поле для нового пароля × 2.

**Кастомизация на стороне плагина:**
- Заменить шаблон страницы установки пароля на брендированный (фильтр `login_form_rp` или собственная страница).
- Увеличить TTL до 48-72 часов для первичной активации (фильтр `password_reset_expiration`).
- Добавить требования к паролю (минимум 12 символов, проверка против `haveibeenpwned` через k-anonymity API — опционально).

**Доставка ссылки родителю:**

- После завершения зачисления админу показывается:
    - "Зачисление выполнено."
    - "Логин родителя: parent@example.com"
    - "**Ссылка для установки пароля:** https://...&key=..."
    - "Передайте ссылку родителю любым удобным способом. Срок действия: 48 часов."

**Что админу НЕ показывается никогда:** сам пароль.

**Регенерация ссылки:**
- Кнопка "Сгенерировать новую ссылку" в карточке пользователя.
- Вызов `get_password_reset_key()` инвалидирует предыдущий key (затирает `user_activation_key`).
- Каждый вызов пишется в `audit_log: password_link_generated`.

### 7.3. Ссылка для ученика

Здесь просто стандартная форма с сохранением состояния по ключу. Логин и пароль ученик вводит в форме. Эти же данные используются в домене. Для поля email в wordpress добавляется постфикс: `@fs.local` (Пример: логин - `drborov`, почта - `drborov@fs.local`)

### 7.4. Защита самой страницы установки пароля

Стандартный `wp-login.php` от brute-force не защищён сам по себе. Рекомендации:
- Подключить плагин типа Limit Login Attempts Reloaded или эквивалент.
- На странице установки пароля включить капчу (фильтр `lostpassword_form` / собственный rp-флоу).
- Логировать в `audit_log: password_set` для аналитики.

---

## 8. Транзакционность и ACID

### 8.1. Что внутри транзакции

Операция зачисления (шаг 6 потока ниже) включает несколько INSERT/UPDATE по custom tables:

```php
$wpdb->query('START TRANSACTION');
try {
    // 1. Создать или найти student person
    $studentPersonId = $personRepo->createOrFindBy($studentData);

    // 2. Создать или найти guardian person
    $guardianPersonId = $personRepo->createOrFindBy($guardianData);

    // 3. Создать relationship (с UNIQUE-защитой от дубликатов)
    $relationshipRepo->createIfNotExists(
        $guardianPersonId,
        $studentPersonId,
        $relationType
    );

    // 4. Создать enrollment (UNIQUE на student+subject+period
    //    защищает от двойного)
    $enrollmentId = $enrollmentRepo->create([...]);

    // 5. Привязать consents к persons
    $consentRepo->bindApplicationConsentsToPersons(
        $applicationId,
        ['student' => $studentPersonId, 'guardian' => $guardianPersonId]
    );

    // 6. Audit log
    $auditLog->record('enroll_student', 'enrollment', $enrollmentId, ...);

    // 7. Application → status 'enrolling'
    $applicationRepo->setStatus($applicationId, 'enrolling');

    $wpdb->query('COMMIT');
} catch (\Throwable $e) {
    $wpdb->query('ROLLBACK');
    throw $e;
}
```

Все таблицы — InnoDB, поэтому транзакция атомарна.

### 8.2. Что вне транзакции (саго)

После commit транзакции выполняются "внешние эффекты":

```php
// Эти шаги — за пределами транзакции
try {
    // 8. Создать WP-юзеров
    $studentUserId = $userFactory->createForPerson($studentPersonId);
    $guardianUserId = $userFactory->createForPerson($guardianPersonId);

    // 9. Привязать wp_user_id обратно в persons
    $personRepo->setWpUser($studentPersonId, $studentUserId);
    $personRepo->setWpUser($guardianPersonId, $guardianUserId);

    // 10. Application → 'converted'
    $applicationRepo->markConverted($applicationId, $enrollmentId);

    // 11. Сгенерировать reset-ссылки
    $studentLink  = $passwordLinkService->generate($studentUserId);
    $guardianLink = $passwordLinkService->generate($guardianUserId);

    // 12. Отправить email (если выбран авторежим)
    if ($sendEmailAuto) {
        $mailer->sendPasswordSetup($guardianUserId, $guardianLink);
    }

    // 13. Очистить кеш заявок
    $cache->invalidateApplicationsList();
} catch (\Throwable $e) {
    // Не откатываем транзакцию — данные уже зафиксированы.
    // Application остаётся в статусе 'enrolling'.
    // Recovery job подберёт.
    $logger->error('Post-transaction step failed', [...]);
    throw new EnrollmentPartialFailure($enrollmentId, $e);
}
```

### 8.3. Почему `wp_insert_user` вне транзакции

`wp_insert_user` запускает действия `user_register`, `set_user_role` и др., в которых сторонние плагины могут писать в свои таблицы или вызывать внешние API (CRM, мейлеры, аналитика). Эти действия не откатываются `ROLLBACK`. Включать `wp_insert_user` в транзакцию — значит создавать иллюзию атомарности при её фактическом отсутствии.

Решение: внутри транзакции фиксируется **бизнес-факт** (person + enrollment + связи), а WP-юзер — это **инфраструктурная привязка**, которую можно досоздать ретроспективно. Recovery job находит persons без `wp_user_id` и enrollments в применённой application со статусом 'enrolling' — и доделывает.

### 8.4. Recovery cron job

Запускается каждые 15 минут (`wp_schedule_event`):

```php
foreach ($applicationRepo->findStuckEnrolling(olderThanMinutes: 5) as $app) {
    try {
        $enrollment = $enrollmentRepo->findBySourceApplication($app->id);
        if (!$enrollment) {
            // Транзакция упала — application можно вернуть в ready_for_review
            $applicationRepo->setStatus($app->id, 'ready_for_review');
            continue;
        }
        // Транзакция прошла, но post-шаги — нет
        $persons = $personRepo->findByEnrollment($enrollment->id);
        foreach ($persons as $person) {
            if (!$person->wp_user_id) {
                $userId = $userFactory->createForPerson($person->id);
                $personRepo->setWpUser($person->id, $userId);
            }
        }
        $applicationRepo->markConverted($app->id, $enrollment->id);
    } catch (\Throwable $e) {
        $logger->error('Recovery failed', ['app_id' => $app->id, 'e' => $e]);
    }
}
```

### 8.5. Идемпотентность

Каждый шаг pre-transaction и post-transaction должен быть идемпотентным:
- `personRepo->createOrFindBy()` — по хэшу документа: если есть — возвращает существующего.
- `relationshipRepo->createIfNotExists()` — UNIQUE на паре `(guardian, student, valid_from)`.
- `enrollmentRepo->create()` — UNIQUE на `(student, subject, period)`, ловит дубль и возвращает существующий.
- `userFactory->createForPerson()` — проверяет `persons.wp_user_id`, если уже есть — возвращает.

Это позволяет recovery job безопасно retry без побочных эффектов.

### 8.6. Изоляция

Уровень изоляции MySQL по умолчанию — REPEATABLE READ. Для текущих операций этого достаточно. Длинных транзакций (более 1-2 секунд) не предполагается; основная масса работы — pre-flight checks и post-transaction effects.

---

## 9. Поток зачисления — пошагово

### Этап 0: Инициализация плагина

При активации:
1. Проверка `FS_LMS_ENC_KEY` и `FS_LMS_HASH_SALT` в `wp-config`. Если нет — `deactivate_plugins()` + admin notice.
2. `dbDelta()` создаёт все таблицы (см. раздел 3).
3. `add_role()` создаёт `lms_student`, `lms_parent`, `lms_teacher`, `lms_office`.
4. Регистрируется WP-cron event `fs_lms_recovery_tick` каждые 15 минут.
5. Регистрируется WP-cron event `fs_lms_expire_applications` ежедневно.
6. Версия схемы записывается в `wp_options.fs_lms_schema_version`.

### Этап 1: Ученик заполняет заявку

**1.1. Точка входа:**
Публичная страница `/lms/apply` (короткий код или дочерняя страница). Регистрация маршрута через `rewrite_rule` в `ApplicationController`.

**1.2. Форма:**
- ФИО (три поля текста)
- Логин
- Пароль
- Email (желательно google/git)
- Школа (текст)
- Класс (число)
- Дата рождения (date)
- Телефон
- Чекбокс согласия на ПД с ссылкой на текст
- Капча

Входить можно по логину или по email + привяжется профиль социальной сети с таким же email.

**1.3. Submit — двухэтапный поток:**

**Шаг A — капча + отправка OTP (`wp_ajax_nopriv_fs_lms_send_otp_code`):**

```
1. Authorize: nonce 'fs_lms_apply' (генерируется на GET)
2. Rate limit: 5 попыток с IP в час → 429
3. Капча → невалидна → 400
4. Sanitize email
5. Cooldown: EmailOtpService::canResend($email) → если false,
   ответить {"error": "Повторная отправка через N секунд"}
6. EmailOtpService::sendCode($email)
7. Response: {"success": true, "masked_email": "p****@gmail.com"}
   → JS показывает экран ввода кода
```

**Шаг B — верификация OTP + создание заявки (`wp_ajax_nopriv_fs_lms_create_application`):**

```
1. Authorize: nonce 'fs_lms_verify_otp' (генерируется после шага A)
2. Rate limit: 5 попыток с IP в час → 429
3. Sanitize всех полей + otp_code
4. OTP: EmailOtpService::verify($email, $otpCode) → 400 если
   неверный или истёкший код
5. Валидация:
   - email уникален среди active applications (status IN
     pending_parent, ready_for_review) — иначе подсказка
     "у вас уже есть незавершённая заявка"
   - дата рождения в разумном диапазоне
   - согласие отмечено
6. Транзакция:
   a. Сгенерировать JOIN-код
   b. INSERT в applications: status='pending_parent',
      student_data_enc, join_code_hash, expires=NOW()+14d
   c. INSERT в consents: type='pd_processing',
      application_id=<id>, signed_by_role='self',
      document_version + hash
   d. INSERT в audit_log: action='create_application'
   COMMIT
7. Сформировать URL: https://site/lms/join/{code}
8. Response:
   {
     "success": true,
     "join_url": "...",
     "expires_at": "2026-06-05T12:00:00Z",
     "message": "Передайте эту ссылку родителю..."
   }
9. На странице — экран с ссылкой и инструкцией.
```

**Bypass-код для внутреннего использования:**

Если в `wp-config.php` определена константа `FS_LMS_OTP_BYPASS_CODE` — ввод этого значения вместо кода из письма всегда проходит верификацию. Предназначено исключительно для тестирования и демо-сессий. В продакшне без обоснованной необходимости не использовать.

**Где какие данные:**
- `applications.student_data_enc` ← JSON `{full_name, email, school, grade, birth_date}` зашифрованный.
- `applications.join_code_hash` ← sha256(code + salt).
- `consents` ← одна запись для ученика.
- Сам JOIN-код существует **только в response** и нигде не сохраняется.

### Этап 2: Родитель открывает ссылку

**2.1. GET `/lms/join/{code}`:**

```
1. Rate limit на IP: 10 попыток открытия любых кодов в час
2. hash = sha256(code + salt)
3. SELECT * FROM applications
   WHERE join_code_hash = ? AND status = 'pending_parent'
     AND join_code_expires_at > NOW()
4. Если не найдено → 404 generic (не раскрывать причину)
5. Дешифровать student_data_enc
6. Audit log: action='view_join_link'
7. Рендер формы с предзаполненными полями ученика
   (можно редактировать)
```

**2.2. Форма родителя:**
Зависит от введённого возраста ученика. Если 14+ лет:
- ФИО (три поля текста)
- Дата рождения
- Серия/номер паспорта родителя
- Кем выдан, дата выдачи
- Серия/номер паспорта ребёнка
- Кем выдан, дата выдачи
- ИНН родителя
- ИНН ребёнка
- Адрес прописки
- Телефон
- Email

Зависит от введённого возраста ученика. Если <14 лет:
- ФИО (три поля текста)
- Дата рождения
- Серия/номер паспорта родителя
- Кем выдан, дата выдачи
- Свидетельство о рождении ребёнка
- ИНН родителя
- ИНН ребёнка
- Адрес прописки
- Телефон
- Email

Поля для редактирования (унаследованы из заявки ученика):
- ФИО ученика (можно поправить опечатку)
- Дата рождения
- Школа, класс

Согласия:
- Согласие на обработку **своих** ПД (чекбокс)
- Согласие на обработку ПД **ребёнка** как законный представитель (чекбокс)

**2.3. POST (`wp_ajax_nopriv_fs_lms_submit_parent_data`):**

```
1. Authorize: nonce + JOIN-код в payload, повторная валидация
2. Sanitize всех полей
3. Валидация: обязательные поля, формат паспорта/ИНН,
   согласия отмечены
4. Транзакция:
   a. Зашифровать parent_data (включая обновлённые
      поля ученика и документы)
   b. Зашифровать обновлённый student_data
   c. UPDATE applications SET
        student_data_enc = ?,
        parent_data_enc = ?,
        status = 'ready_for_review',
        updated_at = NOW(),
        parent_submitted_ip = ?,
        parent_submitted_ua = ?
   d. INSERT в consents: 2 записи (свои + от лица ребёнка)
   e. INSERT в audit_log: action='submit_parent_data'
   COMMIT
5. Уведомление админу: email + admin notification
   (через действие 'fs_lms_application_ready')
6. Response: "Спасибо, заявка принята к рассмотрению.
   После проверки администратором вы получите email
   с инструкцией по доступу в личный кабинет."
```

**Где какие данные:**
- `applications.parent_data_enc` ← полный JSON по родителю + документы ребёнка.
- `applications.student_data_enc` ← обновлён, если родитель что-то поправил.
- `consents` ← 2 новые записи (`pd_processing` self + `pd_child_processing` guardian).

**Важно:** на этом этапе **никакие persons и WP-юзеры ещё не создаются**. Заявка — это только заявка.

### Этап 3: Админ просматривает список заявок

**3.1. Страница `/wp-admin/admin.php?page=fs-lms-applications`:**

Защита: `current_user_can(Capability::ManageApplications->value)`.

Таблица (`WP_List_Table`):

| Дата | ФИО ученика | ФИО представителя | Школа/класс | Статус | Действия |
|---|---|---|---|---|---|

В списке **показываются только имена и нечувствительные поля**. ФИО получаются через ограниченный set-decrypt (одно поле) — это всё ещё PII, но публичные имена — общедоступная категория в иерархии конфиденциальности.

Фильтры: статус, период подачи, search по фрагменту имени (по `full_name_hash` точное совпадение → не работает; полнотекстовый поиск по name на этой итерации не реализуется, чтобы не хранить открыто).

**По умолчанию список не показывает заявки в статусе `Trash`** — они скрыты. Переключатель "Корзина" показывает только `Trash`-заявки. В представлении "Корзина" вместо обычных действий доступны: "Восстановить" и кнопка "Очистить корзину" (удаляет все `Trash`-заявки физически, требует confirm-диалога).

**Audit log:** `view_applications_list` пишется **не на каждую загрузку страницы** (лог распухнет), а суммарно — sample 1:50 или по фильтру `WP_DEBUG`.

### Этап 4: Админ открывает заявку

**4.1. GET `/wp-admin/admin.php?page=fs-lms-applications&id=N`:**

```
1. Authorize: ManageApplications + ViewPII
2. SELECT application by id
3. Дешифровка student_data + parent_data
4. PII Access log:
   actor=current_user, person_id=NULL (нет ещё),
   fields_accessed='application_data',
   reason='application_review'
5. Audit log: action='view_application'
6. Рендер карточки:
   - данные ученика и родителя
   - документы — маскированные, кнопка "Показать"
   - история заявки (timestamps подачи, изменения)
   - подписанные согласия с версиями
   - кнопки: "Зачислить" | "Отклонить"
```

**4.2. "Показать паспорт":**
- AJAX `fs_lms_reveal_pii_field` с указанием field
- Проверка ViewPII
- INSERT в `pii_access_log`
- Возврат расшифрованного значения, показ на 30 секунд

**4.3. "Отклонить":**
- Модалка с причиной (свободный текст, обязательное)
- UPDATE applications SET status='rejected', rejected_reason=?,
  reviewed_by_user_id=?, reviewed_at=NOW()
- Audit log: action='reject_application'
- (опционально) уведомление родителю email-ом

**4.4. "В корзину":**
- Доступно для `PendingParent`, `ReadyForReview`, `Rejected`, `Expired`
- Отличается от "Отклонить": корзина — административный мусор (дубли, тестовые заявки, ошибочные записи), отклонение — бизнес-решение с официальной причиной и уведомлением
- Без модалки с причиной; confirm-диалог в JS достаточен
- UPDATE applications SET status='trash'
- Audit log: action='move_to_trash'
- Заявка исчезает из основного списка, попадает в раздел "Корзина"
- Восстановление из корзины: `setStatus($id, PendingParent|ReadyForReview)` в зависимости от заполненности `parent_data_enc`

### Этап 5: Модалка "Зачислить"

Поля:
- Номер договора
- Дата договора
- Номер приказа
- Дата приказа
- Дата зачисления
- Период (dropdown: `2025-2026`, `2026-spring` и т.д. заранее выбран Текущий)
- Предмет (dropdown из `Subjects`)
- Группа (dropdown из `StudentGroup`)


Submit → AJAX `fs_lms_enroll`.

### Этап 6: Логика зачисления (детально)

**6.1. Pre-flight (вне транзакции, валидация и поиск дубликатов):**

```php
// Authorize
$this->authorize(Nonce::Enroll, Capability::EnrollStudent);

// Sanitize всех полей формы
$input = $this->sanitizeEnrollmentInput($_POST);

// Загрузить и дешифровать application
$app = $applicationRepo->find($input['application_id']);
if ($app->status !== 'ready_for_review') {
    throw new \DomainException('Application is not ready for review');
}
$studentData = json_decode($crypto->decrypt($app->student_data_enc), true);
$parentData  = json_decode($crypto->decrypt($app->parent_data_enc), true);

// PII access log
$piiLog->record(
    actor: get_current_user_id(),
    person_id: null,
    fields: ['student_data', 'parent_data'],
    reason: 'enrollment',
);

// Дедупликация — поиск существующих persons
$studentDocHash  = $crypto->hash($studentData['doc_number']);
$existingStudent = $personRepo->findByDocHash($studentDocHash);

$guardianDocHash  = $crypto->hash($parentData['doc_number']);
$existingGuardian = $personRepo->findByDocHash($guardianDocHash);

// Проверка email-конфликтов в wp_users
if (!$existingGuardian && get_user_by('email', $parentData['email'])) {
    throw new \DomainException(
        'Email родителя уже используется другим пользователем. '
        . 'Проверьте дубликаты вручную.'
    );
}

// Проверка двойного зачисления
if ($existingStudent && $enrollmentRepo->existsActive(
    $existingStudent->id, $input['subject_key'], $input['period_key']
)) {
    throw new \DomainException(
        'Этот ученик уже зачислен на этот предмет в этот период.'
    );
}
```

**6.2. Транзакция (custom tables):**

```php
$wpdb->query('START TRANSACTION');
try {
    // 1. Student person
    if ($existingStudent) {
        $studentPersonId = $existingStudent->id;
        // Опционально — обновить устаревшие поля
    } else {
        $studentPersonId = $personRepo->create([
            'full_name_enc'  => $crypto->encrypt($studentData['full_name']),
            'full_name_hash' => $crypto->hash($studentData['full_name']),
            'birth_date'     => $studentData['birth_date'],
            'doc_type'       => $studentData['doc_type'],
            'doc_number_enc' => $crypto->encrypt($studentData['doc_number']),
            'doc_number_hash'=> $studentDocHash,
            'inn_enc'        => $crypto->encrypt($studentData['inn'] ?? ''),
            'inn_hash'       => $crypto->hash($studentData['inn'] ?? ''),
            'email'          => $studentData['email'] ?? null,
            // ... остальные поля
        ]);
    }

    // 2. Guardian person
    if ($existingGuardian) {
        $guardianPersonId = $existingGuardian->id;
    } else {
        $guardianPersonId = $personRepo->create([
            'full_name_enc'  => $crypto->encrypt($parentData['full_name']),
            'full_name_hash' => $crypto->hash($parentData['full_name']),
            // ... все поля родителя
            'email'          => $parentData['email'],
        ]);
    }

    // 3. Relationship
    $relationshipRepo->createIfNotExists(
        guardianPersonId: $guardianPersonId,
        studentPersonId:  $studentPersonId,
        relationType:     $parentData['relation_type'],
        isPrimaryContact: true,
        hasLegalAuthority: true,
        validFrom: today(),
        createdByUserId: get_current_user_id(),
    );

    // 4. Enrollment
    $snapshot = [
        'student'  => $studentData,
        'guardian' => $parentData,
        'relation' => $parentData['relation_type'],
        'enrolled_at' => now(),
    ];
    $enrollmentId = $enrollmentRepo->create([
        'student_person_id'   => $studentPersonId,
        'subject_key'         => $input['subject_key'],
        'group_term_id'       => $input['group_id'],
        'period_key'          => $input['period_key'],
        'contract_no'         => $input['contract_no'],
        'contract_date'       => $input['contract_date'],
        'order_no'            => $input['order_no'],
        'order_date'          => $input['order_date'],
        'enrolled_at'         => $input['enrolled_at'],
        'enrolled_by_user_id' => get_current_user_id(),
        'status'              => 'active',
        'snapshot_enc'        => $crypto->encrypt(json_encode($snapshot)),
        'source_application_id' => $app->id,
    ]);

    // 5. Привязать согласия к persons
    $consentRepo->bindApplicationConsentsToPersons($app->id, [
        'self'      => $studentPersonId,    // согласие ученика
        'guardian'  => $guardianPersonId,   // согласие родителя за себя
        'for_child' => [
            'signed_by' => $guardianPersonId,
            'signed_for' => $studentPersonId,
        ],
    ]);

    // 6. Audit
    $auditLog->record('enroll_student', 'enrollment', $enrollmentId, [
        'application_id' => $app->id,
        'subject_key'    => $input['subject_key'],
        'period_key'     => $input['period_key'],
    ]);

    // 7. Application → enrolling (промежуточный, защита от
    //    повторного клика; финальный 'converted' будет после
    //    post-transaction шагов)
    $applicationRepo->setStatus($app->id, 'enrolling');

    $wpdb->query('COMMIT');
} catch (\Throwable $e) {
    $wpdb->query('ROLLBACK');
    $auditLog->record('enroll_student_failed', 'application', $app->id, [
        'error' => $e->getMessage(),
    ]);
    throw $e;
}
```

**6.3. Post-transaction (внешние эффекты):**

```php
$results = [];

try {
    // 8. Создать WP-юзеров
    if (!$existingStudent || !$existingStudent->wp_user_id) {
        $studentUserId = $userFactory->createForPerson(
            personId: $studentPersonId,
            role:     'lms_student',
            email:    $studentData['email'] ?? null,
            displayName: $studentData['full_name'],
        );
        $personRepo->setWpUser($studentPersonId, $studentUserId);
    } else {
        $studentUserId = $existingStudent->wp_user_id;
    }

    if (!$existingGuardian || !$existingGuardian->wp_user_id) {
        $guardianUserId = $userFactory->createForPerson(
            personId: $guardianPersonId,
            role:     'lms_parent',
            email:    $parentData['email'],
            displayName: $parentData['full_name'],
        );
        $personRepo->setWpUser($guardianPersonId, $guardianUserId);
    } else {
        $guardianUserId = $existingGuardian->wp_user_id;
    }

    // 9. Application → converted
    $applicationRepo->markConverted($app->id, $enrollmentId);

    // 10. Сгенерировать reset-ссылки
    $guardianLink = $passwordLinkService->generate($guardianUserId);
    $studentLink  = $studentData['email']
        ? $passwordLinkService->generate($studentUserId)
        : null;

    $auditLog->record('password_link_generated', 'wp_user', $guardianUserId);

    // 11. Отправить email или вернуть ссылку админу
    if ($input['send_email_auto']) {
        $mailer->sendPasswordSetup($guardianUserId, $guardianLink);
        if ($studentLink) {
            $mailer->sendPasswordSetup($studentUserId, $studentLink);
        }
    } else {
        $results['guardian_password_link'] = $guardianLink;
        $results['student_password_link']  = $studentLink;
    }

    // 12. Invalidate cache
    $cache->invalidateApplicationsList();

    return $this->success(array_merge([
        'enrollment_id' => $enrollmentId,
        'student_user_id' => $studentUserId,
        'guardian_user_id' => $guardianUserId,
        'message' => 'Зачисление выполнено',
    ], $results));
} catch (\Throwable $e) {
    // Транзакция уже зафиксирована, частичная неудача.
    // Application в статусе 'enrolling' → recovery job подберёт.
    $auditLog->record('enroll_post_failed', 'enrollment', $enrollmentId, [
        'error' => $e->getMessage(),
    ]);
    return $this->error(
        'Зачисление выполнено, но возникла ошибка при создании '
        . 'учётных записей. Система автоматически завершит операцию '
        . 'в ближайшие 15 минут. Если этого не произойдёт — обратитесь '
        . 'к разработчику. Enrollment ID: ' . $enrollmentId
    );
}
```

### Этап 7: Родитель устанавливает пароль


**7.1. Открытие ссылки:**

URL: `https://site/wp-login.php?action=rp&key=xxx&login=parent@example.com`

Стандартный WP-флоу:
1. Валидация key через `check_password_reset_key()`.
2. Если key валиден — форма с двумя полями пароля.
3. Если key истёк — сообщение об истечении + ссылка "запросить новую".
4. После submit:
    - Пароль валидируется (длина ≥ 12, наличие цифр/букв — через фильтр).
    - `reset_password($user, $newPass)` сохраняет хэш.
    - `user_activation_key` обнуляется.
    - Опционально — авто-логин.
    - Redirect на дашборд родителя.
5. Audit log: `password_set`.

**7.2. Истёкшая ссылка:**

Кнопка "Запросить новую ссылку" → email-форма → если email есть в системе → отправка нового key. Стандартный WP-механизм восстановления пароля.

Для самой первой активации, если родитель не успел в 48 часов, админ может вручную регенерировать ссылку в карточке родителя в админке.

---

## 10. Edge cases и сценарии восстановления

### 10.1. Двойная подача заявки

Ученик уже подал заявку, она в статусе `pending_parent`, и подаёт снова с тем же email/ФИО+датой рождения.

**Решение:** при создании заявки проверять existing `pending_parent` / `ready_for_review` по этим данным ученика. Если есть — возвращать сообщение "у вас уже есть незавершённая заявка" с возможностью получить ссылку повторно. 

**Альтернативное решение:** все заявки попадают во временное хранилище. Модерация происходит по согласованию с преподавателем / актуальной считается последняя заявка по ФИО + дате рождения / email.

### 10.2. JOIN-код украден

Кто-то получил ссылку и заполнил поля родителя за фейкового персонажа.

**Митигация:**
- TTL 14 дней (короткое окно атаки).
- Энтропия 60 бит (перебор практически невозможен).
- Rate limiting (медленный перебор тоже).
- Админ при review видит карточку и может отклонить если данные подозрительные.


### 10.3. Родитель уже в системе (другой ребёнок)

Кейс: у Иванова уже есть в системе ребёнок (старший), и он подаёт заявку на младшего.

**Поведение:**
1. На этапе заполнения родитель указывает свой паспорт и email.
2. На этапе зачисления `personRepo->findByDocHash($guardianDocHash)` находит существующего.
3. Используется существующий `guardian_person_id`.
4. Создаётся новый relationship (старший ребёнок — старая связь, младший — новая).
5. Новый WP-юзер **не создаётся** — используется существующий.


### 10.4. Конфликт email

Родитель указал email, который уже принадлежит другому WP-юзеру (например, преподавателю).

**Поведение:**
- На pre-flight check бросается DomainException.
- Админу показывается:
    - "Email parent@example.com уже используется пользователем X (роль: ...)."
    - "Вариант 1: использовать другой email родителя (попросить уточнить)."
    - "Вариант 2: если это тот же человек — привязать существующего WP-юзера к новой персоне (требует дополнительной верификации)."
- На этой итерации **автоматического слияния не делается** — только ручное решение.

### 10.5. Смена опекуна

Через год после зачисления у ребёнка опеку получает другая бабушка.

**Процесс:**
1. Админ открывает карточку ученика → раздел "Представители".
2. Действие "Заменить опекуна":
    - У текущей связи проставляется `valid_to = today()`.
    - Создаётся новая person для новой бабушки (через ту же форму, что и при заявке).
    - Создаётся новая relationship с `valid_from = tomorrow()` (или today() если решено мгновенно).
3. Новой бабушке отправляется reset-ссылка для входа в кабинет.
4. У старой бабушки WP-юзер **остаётся, но больше не имеет доступа к данным этого ребёнка** (логика доступа смотрит на актуальные relationships).

### 10.6. Два родителя

Мать и отец оба хотят доступ к данным ребёнка.

**Поток:**
- Первый родитель проходит стандартную заявку → создаётся мать как `is_primary_contact = 1`.
- Для второго родителя админ в карточке ученика жмёт "Добавить представителя":
    - Открывается форма (та же, что и в заявке родителя).
    - После заполнения создаётся person + relationship.
    - Отправляется reset-ссылка.

### 10.7. Падение между транзакцией и post-effects

Описано в разделе 8.4. Recovery job каждые 15 минут.

### 10.8. Падение внутри транзакции

`ROLLBACK` откатывает всё, application остаётся в `enrolling` или возвращается в `ready_for_review`. Recovery job переводит обратно в `ready_for_review`, админ повторяет операцию.

### 10.9. Истечение заявки

Если родитель не заполнил данные за 14 дней — заявка переводится cron-job-ом в `expired`. Ученик может подать заново.

### 10.10. Запрос на удаление ПД (152-ФЗ)

Пользователь требует удалить свои данные.

**Поток:**
1. Запрос фиксируется в admin-интерфейсе (или из ЛК пользователя).
2. Админ проверяет идентичность и нажимает "Удалить ПД".
3. Транзакция:
    - `persons.deleted_at = NOW()` (soft delete).
    - В audit log запись `pii_deletion_requested`.
4. Через retention period (например, 30 дней — окно для отмены) фоновый job:
    - Заменяет `*_enc` поля на `NULL`.
    - Оставляет `id`, `created_at`, `deleted_at`, обезличенный скелет (нужен для целостности связанных enrollments).
5. Связанные согласия отзываются (`withdrawn_at`).
6. WP-юзер блокируется (роль снимается, пароль рандомизируется).

Enrollment snapshot **сохраняется**, но также подлежит обезличиванию через retention. Это компромисс между требованием удалить ПД и обязанностью хранить документы об обучении (договоры, приказы) определённый срок (бухучёт, лицензионные требования образовательной деятельности).

### 10.11. Экспорт ПД (право на доступ)

Пользователь запрашивает все свои данные.

Действие "Экспорт ПД" для админа с capability `ExportPII`:
- Собирает все записи по `person_id`: persons, relationships, enrollments, consents.
- Дешифрует и формирует JSON / PDF.
- Запись в `pii_access_log: reason='gdpr_export'`.
- Файл отдаётся через одноразовую ссылку с TTL 1 час.

---

## 11. Соответствие 152-ФЗ

### 11.1. Технические меры (реализуются в плагине)

| Требование | Реализация |
|---|---|
| Защита от НСД | application-level шифрование PII, ACL по capabilities, маскирование в UI |
| Локализация ПД на территории РФ | инфраструктурное требование (сервер в РФ) |
| Согласие на обработку | `consents` с фиксацией версии текста, IP, UA, timestamp |
| Цель обработки | указывается в тексте согласия (версионируется) |
| Минимизация | usermeta содержит только необходимое, PII — только в `persons` |
| Ограничение срока хранения | soft delete + retention job, обезличивание |
| Право на доступ | функция экспорта ПД |
| Право на изменение | админ-интерфейс редактирования person |
| Право на удаление | функция удаления ПД с retention окном |
| Журналирование доступа | `pii_access_log` на каждое чтение PII |
| Защита канала | HTTPS (вне плагина, инфраструктура) |
| Защита от атак | rate limiting, капча, защита логина, audit log |

### 11.2. Организационные меры (вне плагина)

- Регистрация юрлица как оператора ПД в Роскомнадзоре.
- Назначение ответственного за обработку ПД (DPO).
- Политика обработки ПД (опубликована на сайте).
- Положение об обработке ПД (внутренний документ).
- Согласия в актуальной редакции, опубликованные публично.
- Обучение сотрудников.
- Регламент реагирования на инциденты (уведомление РКН в течение 24 часов).
- Регламент уничтожения носителей ПД.

### 11.3. Retention периоды (рекомендация для обсуждения с юристом)

| Категория данных | Срок хранения |
|---|---|
| Согласие на обработку ПД | пока действует + 3 года после отзыва |
| Договоры и приказы о зачислении | 5 лет (по образовательной отчётности) |
| Заявки converted | 1 год после зачисления, затем — обезличивание |
| Заявки rejected/expired | 6 месяцев |
| Audit log | 3 года |
| PII Access log | 5 лет |
| Активные enrollments | пока действует + 5 лет после finished |

Эти значения должны быть подтверждены юристом и зафиксированы в политике обработки ПД.

---

## 12. Маппинг на существующую архитектуру плагина

### 12.1. Слои и классы

В соответствии с архитектурой CLAUDE.md:

**Repositories (`Inc/Repositories/WPDBRepositories/`):**
- `ApplicationRepository`
- `PersonRepository`
- `RelationshipRepository`
- `EnrollmentRepository`
- `ConsentRepository`
- `AuditLogRepository`
- `PiiAccessLogRepository`

Каждый — обёртка над `$wpdb` для конкретной таблицы. Работают **только со структурированными массивами / DTO**, никаких прямых SQL-фрагментов наружу.

**Services (`inc/Services/`):**
- `PiiCryptoService` — шифрование/хеширование.
- `JoinCodeService` — генерация/валидация JOIN-кодов.
- `PasswordLinkService` — `get_password_reset_key()` + сборка URL.
- `UserFactory` — `wp_insert_user` с правильными ролями и привязкой к person.
- `EnrollmentService` — оркестрация всего потока зачисления (использует все репозитории + крипто + transaction).
- `PersonReader` — обёртка над `PersonRepository::find()` с автоматическим `pii_access_log`.

**DTO (`inc/DTO/`):**
- `ApplicationDTO`, `PersonDTO`, `EnrollmentDTO`, `RelationshipDTO`, `ConsentDTO` — для передачи между слоями.
- `EnrollmentInput` — входной DTO для `EnrollmentService::enroll()`.

**Controllers (`inc/Controllers/`):**
- `ApplicationController` — регистрирует rewrite rules для `/lms/apply` и `/lms/join/{code}`, регистрирует AJAX-хуки.
- `EnrollmentController` — регистрирует админ-страницу заявок и AJAX-хук `fs_lms_enroll`.
- `PiiAccessController` — регистрирует `fs_lms_reveal_pii_field`.
- `RecoveryController` — регистрирует cron events `fs_lms_recovery_tick`, `fs_lms_expire_applications`.

**Callbacks (`inc/Callbacks/`):**
- `ApplicationCallbacks` — `ajaxCreateApplication`, `ajaxSubmitParentData`.
- `EnrollmentCallbacks` — `ajaxEnrollStudent`, `ajaxRejectApplication`.
- `PiiCallbacks` — `ajaxRevealPiiField`.

**Enums (`inc/Enums/`):**
- `Nonce::Apply`, `Nonce::ParentSubmit`, `Nonce::Enroll`, `Nonce::Reject`, `Nonce::RevealPii`.
- `AjaxHook::CreateApplication`, `AjaxHook::SubmitParentData`, `AjaxHook::EnrollStudent`, `AjaxHook::RejectApplication`, `AjaxHook::RevealPiiField`.
- `Capability::ManageApplications`, `EnrollStudent`, `ViewPII`, `ExportPII`, `ManagePersons`.
- `OptionName::SchemaVersion` (`fs_lms_schema_version`).
- `ApplicationStatus` (новый): `PendingParent`, `ReadyForReview`, `Enrolling`, `Converted`, `Rejected`, `Expired`.
- `EnrollmentStatus` (новый): `Active`, `Finished`, `Expelled`, `Transferred`.
- `RelationType` (новый): `Mother`, `Father`, `Guardian`, `Grandparent`, `Foster`, `Other`.
- `ConsentType` (новый): `PdProcessing`, `PdChildProcessing`, `PdTransfer`, `Marketing`.

**Migrations:**
- `inc/Migrations/Migration_1_0_0.php` — `dbDelta()` для всех таблиц.
- `Inc\Init` при активации/обновлении версии сравнивает `OptionName::SchemaVersion` с текущей и прогоняет нужные миграции.

**Shared traits:**
- использовать существующие `Authorizer`, `Sanitizer`, `AjaxResponse`, `TemplateRenderer`.
- добавить новый трейт `TransactionRunner`:
  ```php
  trait TransactionRunner {
      protected function inTransaction(callable $fn) {
          global $wpdb;
          $wpdb->query('START TRANSACTION');
          try {
              $result = $fn();
              $wpdb->query('COMMIT');
              return $result;
          } catch (\Throwable $e) {
              $wpdb->query('ROLLBACK');
              throw $e;
          }
      }
  }
  ```

### 12.2. Регистрация в DI

В `Init::getServices()` добавить все новые контроллеры. Сервисы и репозитории резолвятся автоматически через autowiring контейнера.

### 12.3. Соответствие правилам CLAUDE.md

- ✅ Все данные через Repositories, прямого `$wpdb` за их пределами нет.
- ✅ Контроллеры регистрируют только хуки, логика в Callbacks/Services.
- ✅ `declare(strict_types=1)`, типизированные сигнатуры.
- ✅ Нонсы через `Nonce` enum, AJAX через `AjaxHook` enum.
- ✅ Санитизация через `Sanitizer` trait.
- ✅ Ответы через `AjaxResponse` trait.
- ⚠️ Новое: custom tables + транзакции — отступление от паттерна "wp_options для всего". Документировать в архитектурном CLAUDE.md.
- ⚠️ Новое: PII-шифрование, audit log, retention jobs — новые архитектурные элементы. Зафиксировать как стандарт для работы с любыми чувствительными данными в будущем.

---

## Приложение A: Контрольный список реализации

Порядок implementation для команды:

1. [ ] Миграция схемы: создать все таблицы.
2. [ ] `PiiCryptoService` + конфигурация ключей (`FS_LMS_ENC_KEY`, `FS_LMS_HASH_SALT`, опционально `FS_LMS_OTP_BYPASS_CODE`).
3. [ ] Все Repositories (CRUD без бизнес-логики), включая `ApplicationRepository::delete()` (только из `trash`).
4. [ ] DTO и Enums, включая `ApplicationStatus::Trash` и `AuditAction::MoveToTrash/RestoreFromTrash/EmptyTrash`.
5. [ ] `JoinCodeService`, `PasswordLinkService`, `UserFactory`.
6. [ ] `EmailOtpService` + шаблон письма `templates/emails/otp-code.php`.
7. [ ] `PiiAccessLogRepository` + `PersonReader`.
8. [ ] `ApplicationController` + публичные формы (этапы 1-2) с двухэтапным OTP-потоком.
9. [ ] Тексты согласий + версионирование.
10. [ ] Админ-список заявок с фильтром "Корзина" + карточка (этапы 3-4).
11. [ ] AJAX `reveal_pii_field` + маскирование в UI.
12. [ ] AJAX корзины: `move_to_trash`, `restore_from_trash`, `empty_trash`.
13. [ ] `EnrollmentService` + транзакционная оркестрация (этап 6).
14. [ ] Модалка зачисления + AJAX `enroll_student` (этап 5).
15. [ ] Recovery cron job.
16. [ ] Email-шаблоны + интеграция с password reset link.
17. [ ] Управление представителями (добавить/заменить) — этап 10.5-10.6.
18. [ ] Функции экспорта и удаления ПД.
19. [ ] Retention cron jobs.
20. [ ] Регистрация ролей и capabilities.
21. [ ] Интеграционные тесты ключевых сценариев (создание заявки → OTP → зачисление → установка пароля).

## Приложение B: Открытые вопросы для уточнения

1. **Период (`period_key`)** — это `wp_options`-справочник или отдельная таблица? Рекомендация: справочник в `wp_options` (их мало), формат `YYYY-YYYY` или `YYYY-season`.
2. **Доступ родителя к ученику** — реализуется на уровне приложения через relationships (актуальные на сегодня). Нужно ли давать родителю просмотр исторических данных ребёнка (до того, как опека была передана)? Юридический вопрос.
3. **Бумажные согласия** — поддерживать ли сценарий, когда родитель приходит лично и подписывает на бумаге? Если да — нужна функция "загрузить скан согласия" + флаг `signed_offline`.
4. **Несколько детей в одной заявке** — допустить ли подачу одной заявкой? Усложняет модель `application = 1 student`. Рекомендация: на MVP — одна заявка = один ученик; "связать с уже зачисленным братом" — отдельный flow.