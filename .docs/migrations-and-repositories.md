# Миграции и репозитории

Раздел описывает инфраструктуру для работы с кастомными таблицами БД системы зачисления. В отличие от существующих репозиториев (которые работают с `wp_options` через `get_option`/`update_option`), все классы этого раздела работают с реляционными таблицами через `$wpdb`.

---

## 1. `MigrationInterface`

**Файл:** `inc/Contracts/MigrationInterface.php`  
**Пространство имён:** `Inc\Contracts`  
**Тип:** Interface

Контракт для всех миграций. По аналогии с существующими `ServiceInterface`, `FieldInterface`, `AuthStrategyInterface` в `inc/Contracts/`.

```
interface MigrationInterface
```

### Методы

| Метод | Возвращает | Описание |
|---|---|---|
| `up(): void` | `void` | Применяет миграцию — создаёт таблицы, добавляет колонки, индексы |
| `down(): void` | `void` | Откатывает миграцию — удаляет таблицы в обратном порядке |
| `version(): string` | `string` | Возвращает строку версии в формате semver: `'1.0.0'` |

### Связь с архитектурой

`MigrationRunner` принимает `MigrationInterface` как тип зависимости в `register()`. DI-контейнер (`Inc\Core\Container`) создаёт конкретные реализации автоматически по типу.

---

## 2. `MigrationRunner`

**Файл:** `inc/Migrations/MigrationRunner.php`  
**Пространство имён:** `Inc\Migrations`  
**Директория создаётся впервые**

Оркестратор применения миграций. Сравнивает текущую версию схемы с зарегистрированными миграциями и применяет недостающие.

```
class MigrationRunner
```

### Свойства

| Свойство | Тип | Описание |
|---|---|---|
| `$migrations` | `MigrationInterface[]` | Реестр зарегистрированных миграций |

### Методы

#### `register(MigrationInterface $migration): void`

Добавляет миграцию в реестр. Вызывается при инициализации в `Activate::activate()` для каждой реализации `MigrationInterface`.

#### `run(): void`

1. Читает текущую версию: `get_option(OptionName::SchemaVersion->value, '0.0.0')` — использует существующий `OptionName::SchemaVersion` из `inc/Enums/OptionName.php`
2. Сортирует `$this->migrations` по результату `version()` в порядке возрастания (`version_compare`)
3. Перебирает миграции: если `version_compare($migration->version(), $current, '>')` — вызывает `$migration->up()`
4. После каждого успешного `up()` обновляет версию: `update_option(OptionName::SchemaVersion->value, $migration->version())`

### Жизненный цикл

`run()` вызывается **в двух местах** — оба уже существуют в проекте:

- **При активации:** `inc/Core/Activate::activate()` — добавить вызов после блока `CronManager`. Метод уже использует `Container`, поэтому `$container->get(MigrationRunner::class)` достаточно.
- **При обновлении:** через хук `upgrader_process_complete` в контроллере (по архитектуре — хуки только в Controllers).

---

## 3. `Migration_1_0_0`

**Файл:** `inc/Migrations/Migration_1_0_0.php`  
**Пространство имён:** `Inc\Migrations`  
**Реализует:** `MigrationInterface`

Создаёт все 7 таблиц системы зачисления за один прогон.

```
class Migration_1_0_0 implements MigrationInterface
```

### Методы

#### `version(): string`

Возвращает `'1.0.0'`.

#### `up(): void`

Вызывает WordPress-функцию `dbDelta()` (требует подключения `upgrade.php`):

```php
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
dbDelta($sql);
```

Создаёт таблицы в следующем порядке (важен из-за FK-зависимостей):

| # | Таблица | Назначение |
|---|---|---|
| 1 | `{prefix}fs_lms_persons` | Люди и их зашифрованные ПД |
| 2 | `{prefix}fs_lms_applications` | Заявки на зачисление |
| 3 | `{prefix}fs_lms_relationships` | Связи представитель ↔ ученик |
| 4 | `{prefix}fs_lms_enrollments` | Активные зачисления |
| 5 | `{prefix}fs_lms_consents` | Согласия на обработку ПД |
| 6 | `{prefix}fs_lms_audit_log` | Журнал всех действий |
| 7 | `{prefix}fs_lms_pii_access_log` | Журнал доступа к ПД |

Все таблицы: `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.

Префикс берётся из `$wpdb->prefix` — глобальная переменная WordPress, доступная везде.

#### `down(): void`

`DROP TABLE IF EXISTS` в обратном порядке (от зависимых к базовым):

```
pii_access_log → audit_log → consents → enrollments → relationships → applications → persons
```

### Связь с архитектурой

`dbDelta()` — стандартный WordPress-способ создания таблиц при активации плагина. Идемпотентен: повторный вызов не пересоздаёт таблицы, только применяет изменения схемы.

---

## 4. `RepositoryInterface`

**Файл:** `inc/Contracts/RepositoryInterface.php`  
**Пространство имён:** `Inc\Contracts`  
**Тип:** Interface

Маркерный интерфейс для DB-репозиториев. Отличает репозитории, работающие с `$wpdb`-таблицами, от репозиториев `wp_options` (которые интерфейс не реализуют).

```
interface RepositoryInterface
```

Интерфейс пустой — общих методов у всех репозиториев нет (у каждого своя сигнатура `find`, `create` и т.д.). Служит для типизации в DI и тестах.

---

## 5. `ApplicationRepository`

**Файл:** `Inc/Repositories/WPDBRepositories/ApplicationRepository.php`  
**Пространство имён:** `Inc\Repositories\WPDBRepositories`  
**Реализует:** `RepositoryInterface`  
**Зависимости (через конструктор):** `\wpdb $wpdb`

Работает с таблицей `{prefix}fs_lms_applications`. Центральный репозиторий системы зачисления.

**Заметка о DI:** `$wpdb` — глобальная переменная, не класс. DI-контейнер (`inc/Core/Container.php`) не резолвит встроенные типы без значений по умолчанию. Для `$wpdb` используется паттерн с дефолтом в конструкторе:

```php
public function __construct(private readonly \wpdb $wpdb = null) {
    $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
}
```

### Методы

#### `find(int $id): ?ApplicationDTO`

`SELECT * FROM {table} WHERE id = %d` → маппинг строки в `ApplicationDTO::fromArray($row)`.

#### `findByJoinCodeHash(string $hash): ?ApplicationDTO`

```sql
SELECT * FROM {table}
WHERE join_code_hash = %s
  AND status IN ('pending_parent', 'ready_for_review', 'enrolling')
LIMIT 1
```

Возвращает заявку только если она в активном статусе. Используется при переходе по ссылке `join_url`.

#### `findActiveByEmail(string $email): ?ApplicationDTO`

```sql
SELECT * FROM {table}
WHERE student_email_hash = %s
  AND status IN ('pending_parent', 'ready_for_review')
LIMIT 1
```

Проверка перед созданием новой заявки — не должно быть незавершённой.

#### `list(array $filters, int $page, int $perPage): array`

Постраничный список для административного интерфейса. Фильтры: `status`, `date_from`, `date_to`. Строит WHERE-условия динамически через `$wpdb->prepare()`. Возвращает `ApplicationDTO[]`.

#### `count(array $filters): int`

Те же фильтры что у `list()`, но `SELECT COUNT(*)`. Нужен для пагинации.

#### `create(array $data): int`

`$wpdb->insert($table, $data)` → `$wpdb->insert_id`. Возвращает ID новой строки.

#### `update(int $id, array $data): bool`

`$wpdb->update($table, $data, ['id' => $id])` → `false | int` (число затронутых строк). Обновляет только переданные поля — не перезаписывает всю строку.

#### `setStatus(int $id, ApplicationStatus $status): bool`

1. `$this->find($id)` — получает текущую заявку
2. `$current->status->canTransitionTo($status)` — использует метод из существующего `inc/Enums/ApplicationStatus.php`
3. Если переход запрещён — бросает `\InvalidArgumentException` с описанием запрещённого перехода
4. `$this->update($id, ['status' => $status->value, 'updated_at' => current_time('mysql')])`

#### `markConverted(int $id, int $enrollmentId): bool`

```php
$this->update($id, [
    'status'                    => ApplicationStatus::Converted->value,
    'converted_to_enrollment_id' => $enrollmentId,
    'updated_at'                => current_time('mysql'),
])
```

Финальная операция при успешном зачислении.

#### `findStuckEnrolling(int $minMinutes): array`

```sql
SELECT * FROM {table}
WHERE status = 'enrolling'
  AND updated_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)
```

Используется recovery cron-задачей (уже зарегистрирован в `inc/Enums/CronHook.php` как `RecoveryTick`).

#### `findExpiredPending(): array`

```sql
SELECT * FROM {table}
WHERE status IN ('pending_parent', 'ready_for_review')
  AND join_code_expires_at < NOW()
```

Используется expire cron-задачей (`CronHook::ExpireApplications`).

---

## 6. `PersonRepository`

**Файл:** `Inc/Repositories/WPDBRepositories/PersonRepository.php`  
**Пространство имён:** `Inc\Repositories\WPDBRepositories`  
**Реализует:** `RepositoryInterface`  
**Зависимости:** `\wpdb`

Центральное хранилище людей и их зашифрованных ПД. Колонки `*_enc` содержат бинарные BLOB-данные, зашифрованные через `PiiCryptoService::encrypt()`. Репозиторий **не расшифровывает** — возвращает `PersonDTO` с полем `*_enc` как `string`.

### Методы

#### `find(int $id): ?PersonDTO`

`SELECT * WHERE id = %d` → `PersonDTO::fromArray($row)`. Включает удалённые записи (`deleted_at IS NOT NULL`) — нужно для administrative tasks.

#### `findByWpUserId(int $userId): ?PersonDTO`

`SELECT * WHERE wp_user_id = %d AND deleted_at IS NULL`. Связь WP-пользователь ↔ person устанавливается при зачислении через `setWpUser()`.

#### `findByDocHash(string $hash): ?PersonDTO`

`SELECT * WHERE doc_number_hash = %s AND deleted_at IS NULL`. Поиск по хэшу паспорта/свидетельства о рождении. Хэш считается через `PiiCryptoService::hash()` — детерминированный SHA-256 с солью.

#### `findByInnHash(string $hash): ?PersonDTO`

Аналогично `findByDocHash`, но по `inn_hash`.

#### `findByEmail(string $email): ?PersonDTO`

`SELECT * WHERE email = %s AND deleted_at IS NULL`. Email хранится в открытом виде (не PII).

#### `findByEnrollment(int $enrollmentId): array`

```sql
SELECT p.*
FROM {persons} p
JOIN {enrollments} e ON e.student_person_id = p.id AND e.id = %d
LEFT JOIN {relationships} r ON r.student_person_id = p.id
    AND (r.valid_to IS NULL OR r.valid_to > CURDATE())
LEFT JOIN {persons} g ON g.id = r.guardian_person_id
```

Возвращает `[student: PersonDTO, guardians: PersonDTO[]]`. Используется Recovery Job для восстановления незавершённых зачислений.

#### `create(array $data): int`

`$wpdb->insert()` → `$wpdb->insert_id`.

#### `update(int $id, array $data): bool`

`$wpdb->update()`. Только переданные поля.

#### `setWpUser(int $id, int $wpUserId): bool`

Специализированный UPDATE: `wp_user_id = %d WHERE id = %d`. Вызывается из `EnrollmentService` после создания WP-пользователя.

#### `softDelete(int $id): bool`

`UPDATE SET deleted_at = NOW() WHERE id = %d`. Данные не удаляются физически — нужно для GDPR-процедур (`findDeletedOlderThan()`).

#### `anonymize(int $id): bool`

```sql
UPDATE {table}
SET full_name_enc = NULL,
    doc_number_enc = NULL,
    inn_enc = NULL,
    address_enc = NULL,
    phone_enc = NULL,
    doc_number_hash = NULL,
    inn_hash = NULL
WHERE id = %d
```

Финальная стадия GDPR-удаления. Хэши тоже обнуляются, чтобы запись перестала находиться через поиск.

#### `findDeletedOlderThan(int $days): array`

```sql
SELECT * WHERE deleted_at IS NOT NULL
  AND deleted_at < DATE_SUB(NOW(), INTERVAL %d DAY)
```

Вызывается retention cron-задачей (`CronHook::RetentionCleanup`) для запуска `anonymize()`.

---

## 7. `RelationshipRepository`

**Файл:** `Inc/Repositories/WPDBRepositories/RelationshipRepository.php`  
**Пространство имён:** `Inc\Repositories\WPDBRepositories`  
**Реализует:** `RepositoryInterface`  
**Зависимости:** `\wpdb`

Связи представитель ↔ ученик с временны́ми периодами (`valid_from`, `valid_to`). Активная связь — `valid_to IS NULL OR valid_to > CURDATE()`.

### Методы

#### `find(int $id): ?RelationshipDTO`

`SELECT * WHERE id = %d`.

#### `findActiveByStudent(int $studentPersonId): array`

```sql
SELECT * WHERE student_person_id = %d
  AND (valid_to IS NULL OR valid_to > CURDATE())
```

Все действующие представители ученика.

#### `findActiveByGuardian(int $guardianPersonId): array`

Симметрично — все действующие ученики представителя.

#### `findActivePair(int $guardianId, int $studentId): ?RelationshipDTO`

```sql
SELECT * WHERE guardian_person_id = %d AND student_person_id = %d
  AND (valid_to IS NULL OR valid_to > CURDATE())
LIMIT 1
```

Используется перед `createIfNotExists()` для понятного сообщения об ошибке.

#### `create(array $data): int`

`$wpdb->insert()` → `$wpdb->insert_id`.

#### `createIfNotExists(array $data): int`

```sql
INSERT IGNORE INTO {table} (guardian_person_id, student_person_id, valid_from, ...)
VALUES (%d, %d, %s, ...)
```

После `INSERT IGNORE` — если `$wpdb->rows_affected === 0`, значит строка уже была: делает `findActivePair()` и возвращает её ID. Иначе возвращает `$wpdb->insert_id`.

#### `terminate(int $id, ?string $date = null): bool`

```php
$date = $date ?? current_time('Y-m-d');
$wpdb->update($table, ['valid_to' => $date], ['id' => $id]);
```

---

## 8. `EnrollmentRepository`

**Файл:** `Inc/Repositories/WPDBRepositories/EnrollmentRepository.php`  
**Пространство имён:** `Inc\Repositories\WPDBRepositories`  
**Реализует:** `RepositoryInterface`  
**Зависимости:** `\wpdb`

Зачисления студентов. Уникальность: `(student_person_id, subject_key, period_key)` — студент не может быть зачислен дважды на один предмет в одном периоде.

### Методы

#### `find(int $id): ?EnrollmentDTO`

`SELECT * WHERE id = %d`.

#### `findBySourceApplication(int $appId): ?EnrollmentDTO`

`SELECT * WHERE source_application_id = %d LIMIT 1`. Связь заявка → зачисление.

#### `findActiveByStudent(int $personId): array`

```sql
SELECT * WHERE student_person_id = %d AND status = 'active'
```

Использует `EnrollmentStatus::Active->value` — существующий `inc/Enums/EnrollmentStatus.php`.

#### `existsActive(int $personId, string $subjectKey, string $periodKey): bool`

```sql
SELECT COUNT(*) FROM {table}
WHERE student_person_id = %d
  AND subject_key = %s
  AND period_key = %s
  AND status = 'active'
```

Вызывается **до** `create()` для понятного сообщения об ошибке дублирования. Не полагается на исключение из UNIQUE-ключа.

#### `list(array $filters, int $page, int $perPage): array`

Фильтры: `status`, `subject_key`, `period_key`, `date_from`, `date_to`.

#### `create(array $data): int`

`$wpdb->insert()` → `$wpdb->insert_id`.

#### `setStatus(int $id, EnrollmentStatus $status, ?array $terminationData = null): bool`

```php
$fields = [
    'status'     => $status->value,
    'updated_at' => current_time('mysql'),
];

if ($terminationData !== null) {
    $fields['terminated_at']             = $terminationData['terminated_at'];
    $fields['terminated_reason']         = $terminationData['reason'];
    $fields['terminated_by_user_id']     = $terminationData['by_user_id'];
}

$wpdb->update($table, $fields, ['id' => $id]);
```

Метод не валидирует переходы — в отличие от `ApplicationRepository::setStatus()`. `EnrollmentStatus` не имеет FSM: завершение зачисления — административное действие.

---

## 9. `ConsentRepository`

**Файл:** `Inc/Repositories/WPDBRepositories/ConsentRepository.php`  
**Пространство имён:** `Inc\Repositories\WPDBRepositories`  
**Реализует:** `RepositoryInterface`  
**Зависимости:** `\wpdb`

Согласия на обработку ПД. Согласие создаётся на этапе заявки, ещё до привязки к конкретному person. Привязка происходит при зачислении через `bindApplicationConsentsToPersons()`.

### Методы

#### `find(int $id): ?ConsentDTO`

`SELECT * WHERE id = %d`.

#### `findByApplication(int $appId): array`

`SELECT * WHERE application_id = %d`. Все согласия, собранные в рамках заявки.

#### `findActiveByPerson(int $personId): array`

```sql
SELECT * WHERE person_id = %d
  AND withdrawn_at IS NULL
  AND (valid_until IS NULL OR valid_until > NOW())
```

Действующие согласия физлица — нужны при экспорте данных и проверке правомерности обработки.

#### `create(array $data): int`

`$wpdb->insert()` → `$wpdb->insert_id`.

#### `bindApplicationConsentsToPersons(int $appId, array $personMap): int`

```php
// $personMap = ['self' => $studentId, 'guardian' => $guardianId, 'for_child' => $studentId]
$consents = $this->findByApplication($appId);
$updated  = 0;

foreach ($consents as $consent) {
    $personId = $personMap[$consent->subject_role] ?? null;
    if ($personId !== null) {
        $wpdb->update($table, ['person_id' => $personId], ['id' => $consent->id]);
        $updated++;
    }
}

return $updated;
```

Вызывается из `EnrollmentService` после создания персон. Связывает согласия (созданные без person_id) с конкретными людьми.

#### `withdraw(int $id, string $reason): bool`

```php
$wpdb->update($table, [
    'withdrawn_at'     => current_time('mysql'),
    'withdrawn_reason' => $reason,
], ['id' => $id]);
```

---

## 10. `AuditLogRepository`

**Файл:** `Inc/Repositories/WPDBRepositories/AuditLogRepository.php`  
**Пространство имён:** `Inc\Repositories\WPDBRepositories`  
**Реализует:** `RepositoryInterface`  
**Зависимости:** `\wpdb`

Append-only журнал. Никаких UPDATE и DELETE записей вручную — только `purgeOlderThan()` по расписанию.

### Методы

#### `record(array $event): int`

```php
$wpdb->insert($table, [
    'actor_user_id' => $event['actor_user_id'],
    'actor_role'    => $event['actor_role'],
    'action'        => $event['action'],      // AuditAction::*->value
    'target_type'   => $event['target_type'],
    'target_id'     => $event['target_id'],
    'details_json'  => wp_json_encode($event['details'] ?? null),
    'actor_ip'      => $event['actor_ip'],
    'actor_ua'      => $event['actor_ua'],
    'created_at'    => current_time('mysql'),
]);
return $wpdb->insert_id;
```

Значение `action` — `AuditAction::*->value` из существующего `inc/Enums/AuditAction.php`. `details_json` — только метаданные изменений, **никогда не значения PII** (только хэши и имена изменённых полей).

Прямой вызов `record()` из бизнес-кода не предполагается — только через `AuditService::record()` (описан в разделе «Сервисы»).

#### `listByTarget(string $targetType, int $targetId, int $limit = 50): array`

```sql
SELECT * WHERE target_type = %s AND target_id = %d
ORDER BY created_at DESC LIMIT %d
```

#### `listByActor(int $userId, int $limit = 50): array`

```sql
SELECT * WHERE actor_user_id = %d ORDER BY created_at DESC LIMIT %d
```

#### `purgeOlderThan(int $days): int`

```sql
DELETE FROM {table}
WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
```

Возвращает `$wpdb->rows_affected`. Вызывается retention cron-задачей (`CronHook::RetentionCleanup`).

---

## 11. `PiiAccessLogRepository`

**Файл:** `Inc/Repositories/WPDBRepositories/PiiAccessLogRepository.php`  
**Пространство имён:** `Inc\Repositories\WPDBRepositories`  
**Реализует:** `RepositoryInterface`  
**Зависимости:** `\wpdb`

Отдельный append-only журнал доступа к ПД. Хранится отдельно от `audit_log` — специфичная отчётность по 152-ФЗ.

### Методы

#### `record(array $event): int`

```php
$wpdb->insert($table, [
    'actor_user_id'  => $event['actor_user_id'],
    'actor_role'     => $event['actor_role'],
    'person_id'      => $event['person_id'],
    'fields_accessed' => implode(',', $event['fields']), // PiiField::*->value через запятую
    'access_reason'  => $event['reason'],
    'actor_ip'       => $event['actor_ip'],
    'created_at'     => current_time('mysql'),
]);
return $wpdb->insert_id;
```

`fields_accessed` — список значений `PiiField::*->value` из существующего `inc/Enums/PiiField.php`.

#### `listByPerson(int $personId, int $limit = 50): array`

`SELECT * WHERE person_id = %d ORDER BY created_at DESC LIMIT %d`.

#### `listByActor(int $userId, int $limit = 50): array`

`SELECT * WHERE actor_user_id = %d ORDER BY created_at DESC LIMIT %d`.

#### `purgeOlderThan(int $days): int`

Аналогично `AuditLogRepository::purgeOlderThan()`.

---

## Сводная таблица

| Файл | Тип | Реализует | Зависимости |
|---|---|---|---|
| `inc/Contracts/MigrationInterface.php` | Interface | — | — |
| `inc/Contracts/RepositoryInterface.php` | Interface | — | — |
| `inc/Migrations/MigrationRunner.php` | Class | — | `MigrationInterface[]`, `OptionName::SchemaVersion` |
| `inc/Migrations/Migration_1_0_0.php` | Class | `MigrationInterface` | `$wpdb` |
| `Inc/Repositories/WPDBRepositories/ApplicationRepository.php` | Class | — | `$wpdb`, `ApplicationStatus` |
| `Inc/Repositories/WPDBRepositories/PersonRepository.php` | Class | `RepositoryInterface` | `$wpdb` |
| `Inc/Repositories/WPDBRepositories/RelationshipRepository.php` | Class | `RepositoryInterface` | `$wpdb` |
| `Inc/Repositories/WPDBRepositories/EnrollmentRepository.php` | Class | `RepositoryInterface` | `$wpdb`, `EnrollmentStatus` |
| `Inc/Repositories/WPDBRepositories/ConsentRepository.php` | Class | `RepositoryInterface` | `$wpdb` |
| `Inc/Repositories/WPDBRepositories/AuditLogRepository.php` | Class | `RepositoryInterface` | `$wpdb`, `AuditAction` |
| `Inc/Repositories/WPDBRepositories/PiiAccessLogRepository.php` | Class | `RepositoryInterface` | `$wpdb`, `PiiField` |

## Интеграция в Activate/Deactivate

`MigrationRunner::run()` встраивается в существующий `inc/Core/Activate::activate()`:

```php
// После блока CronManager, перед generatePages()
/** @var MigrationRunner $migrationRunner */
$migrationRunner = $container->get(MigrationRunner::class);
$migrationRunner->register(new Migration_1_0_0());
$migrationRunner->run();
```

`down()` миграций при деактивации **не вызывается** — данные сохраняются для повторной активации. `down()` используется только при полном удалении плагина (`uninstall.php`).

## Существующие enum-ы, используемые в этом разделе

| Enum | Файл | Используется в |
|---|---|---|
| `OptionName::SchemaVersion` | `inc/Enums/OptionName.php` | `MigrationRunner` |
| `ApplicationStatus` | `inc/Enums/ApplicationStatus.php` | `ApplicationRepository::setStatus()` |
| `EnrollmentStatus` | `inc/Enums/EnrollmentStatus.php` | `EnrollmentRepository::setStatus()`, `findActiveByStudent()` |
| `AuditAction` | `inc/Enums/AuditAction.php` | `AuditLogRepository::record()` |
| `PiiField` | `inc/Enums/PiiField.php` | `PiiAccessLogRepository::record()` |
| `CronHook` | `inc/Enums/CronHook.php` | Recovery/Retention/Expire cron-задачи, вызывающие методы репозиториев |
