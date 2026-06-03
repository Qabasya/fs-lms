# FS LMS — Задачи по системе зачисления

---

## Инфраструктура и перечисления

---

**Расширить `Capability` enum**

Добавить в `inc/Enums/Capability.php` следующие кейсы:
- `ManageApplications = 'manage_applications'`
- `EnrollStudent = 'enroll_student'`
- `ViewPII = 'view_pii'`
- `ExportPII = 'export_pii'`
- `ManagePersons = 'manage_persons'`

Существующие кейсы не трогать.

---

**Расширить `Nonce` enum**

Добавить в `inc/Enums/Nonce.php` следующие кейсы:
- `Apply`
- `ParentSubmit`
- `Enroll`
- `Reject`
- `RevealPii`
- `AddRepresentative`
- `ReplaceRepresentative`
- `UpdatePerson`
- `WithdrawConsent`
- `RequestPiiDeletion`
- `ExportPii`
- `VerifyOtp`
- `TrashApplication`

Убедиться, что методы `create()` и `verify()` работают для каждого нового кейса.

---

**Расширить `AjaxHook` enum**

Добавить в `inc/Enums/AjaxHook.php` кейсы для всех новых AJAX-операций:
- `CreateApplication`
- `SubmitParentData`
- `EnrollStudent`
- `RejectApplication`
- `RevealPiiField`
- `AddRepresentative`
- `ReplaceRepresentative`
- `UpdatePerson`
- `WithdrawConsent`
- `RequestPiiDeletion`
- `ExportPii`
- `SendOtpCode`
- `MoveApplicationToTrash`
- `RestoreApplicationFromTrash`
- `EmptyApplicationsTrash`

Убедиться, что `toJsArray()` экспортирует их корректно и они появляются в `fs_lms_vars.ajax_actions` на фронте.

---

**Расширить `OptionName` enum**

Добавить в `inc/Enums/OptionName.php`:
- `SchemaVersion = 'fs_lms_schema_version'` — версия схемы БД
- `Periods = 'fs_lms_periods_list'` — справочник учебных периодов

---

**Расширить `UserRole` enum**

Добавить в `inc/Enums/UserRole.php`:
- `LmsStudent = 'lms_student'`
- `LmsParent = 'lms_parent'`
- `LmsTeacher = 'lms_teacher'`
- `LmsOffice = 'lms_office'`

У каждого реализовать метод `label(): string` с русским названием роли и метод `capabilities(): array` с перечнем Capability, который должна получить роль при создании.

---

**Создать новые доменные enum-ы**

Создать следующие enum-ы в `inc/Enums/`:

`ApplicationStatus: string`
- Кейсы: `PendingParent`, `ReadyForReview`, `Enrolling`, `Converted`, `Rejected`, `Expired`, `Trash`
- Метод `canTransitionTo(self $next): bool` — жёстко задаёт разрешённые переходы статусной машины. Разрешённые переходы: `PendingParent` → `ReadyForReview`; `ReadyForReview` → `Enrolling` или `Rejected`; `Enrolling` → `Converted` или `ReadyForReview` (при откате); `ReadyForReview`, `PendingParent`, `Rejected` и `Expired` → `Trash`; `Trash` → `PendingParent` или `ReadyForReview` (восстановление из корзины); `ReadyForReview` и `PendingParent` → `Expired`
- Метод `isTrashable(): bool` — возвращает true для `PendingParent`, `ReadyForReview`, `Rejected`, `Expired`

`EnrollmentStatus: string`
- Кейсы: `Active`, `Finished`, `Expelled`, `Transferred`
- Метод `isTerminal(): bool` — возвращает true для `Finished`, `Expelled`, `Transferred`

`RelationType: string`
- Кейсы: `Mother`, `Father`, `Guardian`, `Grandparent`, `Foster`, `Other`
- Метод `label(): string` — возвращает "Мать", "Отец", "Опекун" и т.д.

`ConsentType: string`
- Кейсы: `PdProcessing`, `PdChildProcessing`, `PdTransfer`, `Marketing`
- Метод `templateFile(string $version): string` — возвращает путь к файлу согласия, например `templates/consents/v1/pd_processing.html`

`DocumentType: string`
- Кейсы: `PassRf`, `BirthCertificate`, `ForeignPass`
- Метод `label(): string`

`PiiField: string`
- Кейсы: `FullName`, `Pass`, `Inn`, `Snils`, `Address`, `Phone`
- Метод `maskPattern(): string` — возвращает шаблон маски для этого типа поля

`AuditAction: string`
- Кейсы для всех действий, которые нужно логировать: `CreateApplication`, `SubmitParentData`, `ViewJoinLink`, `ViewApplication`, `EnrollStudent`, `EnrollStudentFailed`, `RejectApplication`, `TerminateEnrollment`, `CreateRelationship`, `ReplaceRelationship`, `TerminateRelationship`, `UpdatePerson`, `ConsentSigned`, `ConsentWithdrawn`, `PasswordLinkGenerated`, `PasswordSet`, `PiiDeletionRequested`, `PiiExported`, `ViewApplicationsList`, `ExpireApplication`, `MoveToTrash`, `RestoreFromTrash`, `EmptyTrash`

---

**Создать `RoleManager`**

Создать класс `RoleManager` в `inc/Managers/`. Задача класса — регистрировать LMS-роли и управлять их capabilities.

Методы:
- `registerAll(): void` — вызывает `add_role()` для каждой роли из `UserRole` enum с правильным набором capabilities
- `syncCapabilities(): void` — для каждой существующей роли приводит capabilities к актуальной матрице (нужно при обновлении плагина, если матрица изменилась)
- `unregisterAll(): void` — вызывает `remove_role()` для каждой LMS-роли при деинсталляции плагина

Матрица capabilities (что какой роли выдавать):

| Capability | admin | lms_office | lms_teacher | lms_parent | lms_student |
|---|---|---|---|---|---|
| ManageApplications | ✓ | ✓ | | | |
| EnrollStudent | ✓ | ✓ | | | |
| ViewPII | ✓ | ✓ | | | |
| ExportPII | ✓ | | | | |
| ManagePersons | ✓ | ✓ | | | |

`registerAll()` должен вызываться при активации плагина. `unregisterAll()` — при деактивации.

---

**Создать `CronManager`**

Создать класс `CronManager` в `inc/Managers/`.

Методы:
- `schedule(string $hook, string $recurrence): void` — планирует cron-событие через `wp_schedule_event`, предварительно проверяя что оно не запланировано уже
- `unschedule(string $hook): void`
- `unscheduleAll(): void` — снимает все LMS-события разом; вызывается при деактивации плагина
- `addCustomInterval(string $name, int $seconds, string $label): void` — добавляет кастомный интервал через фильтр `cron_schedules`

При активации через `addCustomInterval` зарегистрировать интервал `every_15_minutes` (900 секунд).

---

## Шифрование

---

**Задокументировать конфигурацию ключей шифрования**

Написать секцию в README или отдельный `ENCRYPTION.md` с описанием:

1. Что нужно добавить в `wp-config.php`:
```php
define('FS_LMS_ENC_KEY', '<base64>');
define('FS_LMS_HASH_SALT', '<случайная строка>');
```

2. Как сгенерировать ключ:
```bash
php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"
```

3. Требования: ключ — ровно 32 байта в base64, соль — любая непустая строка. Ключ не должен быть в коде плагина или в БД — только в `wp-config.php`.

4. Как хранить: не коммитить `wp-config.php` в репозиторий, на сервере — ограничить права чтения файла (`chmod 640`).

---

**Реализовать `PiiCryptoService`**

Создать класс `PiiCryptoService` в `inc/Services/`.

Конструктор — читает `FS_LMS_ENC_KEY` из констант. Если константа отсутствует или ключ имеет неверную длину — бросает `RuntimeException`. Класс не должен работать с невалидным ключом.

Методы:
- `encrypt(string $plaintext): string` — шифрует через `sodium_crypto_secretbox`. Генерирует случайный nonce (`random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)`), возвращает конкатенацию `nonce || ciphertext`. Результат — бинарная строка для хранения в BLOB.
- `decrypt(string $blob): string` — разбирает `nonce || ciphertext`, расшифровывает. Если данные повреждены или ключ не тот — бросает `RuntimeException`.
- `hash(string $value): string` — нормализует строку (`mb_strtolower(trim($value))`), затем `hash('sha256', $normalized . FS_LMS_HASH_SALT)`. Используется для хранения searchable-хэшей PII (паспорт, ИНН, телефон, ФИО).
- `isAvailable(): bool` — проверяет наличие и валидность ключа; не бросает, возвращает bool.

Написать unit-тесты: round-trip encrypt→decrypt, корректный отказ на повреждённых данных, детерминизм hash.

---

**Добавить проверку ключей при активации плагина**

В хуке активации плагина вызывать `PiiCryptoService::isAvailable()`. Если возвращает false:
1. Вызвать `deactivate_plugins(plugin_basename(__FILE__))`.
2. Вывести admin notice с инструкцией по настройке (`ENCRYPTION.md`).

Плагин не должен активироваться без валидного ключа шифрования.

---

**Реализовать `PiiMaskingService`**

Создать класс `PiiMaskingService` в `inc/Services/`.

Метод `mask(string $value, PiiField $type): string` возвращает маскированное представление:
- `PiiField::Pass` → оставить первые 4 символа и последние 4, середину заменить на `••`: `4015 •• •••• 1234`
- `PiiField::Inn` → оставить последние 4 цифры: `•••• •••• 1234`
- `PiiField::Phone` → `+7 9•• ••• 12 34`
- `PiiField::Address` → оставить только название города, остальное `••••••`: `г. Москва, ••••••`
- `PiiField::FullName` → маскировать не нужно, ФИО показываются открыто
- `PiiField::Snils` → оставить последние 4: `••• ••• •••-34`

Метод `maskBulk(array $values, array $types): array` — обрабатывает массив за один вызов.

Написать unit-тесты на каждый тип.

---

## Миграции и репозитории

---

**Создать контракт `MigrationInterface` и класс `MigrationRunner`**

Создать интерфейс `MigrationInterface` в `inc/Contracts/`:
- `up(): void`
- `down(): void`
- `version(): string`

Создать класс `MigrationRunner` в `inc/Migrations/`:
- `register(MigrationInterface $migration): void` — регистрирует миграцию в реестре
- `run(): void` — читает текущую версию из `OptionName::SchemaVersion`, сравнивает с зарегистрированными, выполняет `up()` для всех недостающих в порядке возрастания версии, обновляет `SchemaVersion`

`MigrationRunner::run()` должен вызываться при активации плагина и при обновлении версии плагина.

---

**Создать `Migration_1_0_0` — все 7 таблиц**

Создать класс `Migration_1_0_0` в `inc/Migrations/`, реализующий `MigrationInterface`. Версия: `'1.0.0'`.

В методе `up()` через `dbDelta()` создать следующие таблицы (DDL см. в `ENROLLMENT_SPEC.md` §3):
1. `{prefix}fs_lms_applications` — заявки
2. `{prefix}fs_lms_persons` — люди / PII
3. `{prefix}fs_lms_relationships` — связи представитель↔ученик
4. `{prefix}fs_lms_enrollments` — зачисления
5. `{prefix}fs_lms_consents` — согласия на обработку ПД
6. `{prefix}fs_lms_audit_log` — журнал действий
7. `{prefix}fs_lms_pii_access_log` — журнал доступа к ПД

Все таблицы: движок InnoDB, charset utf8mb4_unicode_ci, все индексы и UNIQUE-ключи из спеки.

В методе `down()` — `DROP TABLE IF EXISTS` для каждой таблицы в обратном порядке.

После активации на dev-окружении проверить через phpMyAdmin или CLI, что все таблицы созданы с верной структурой.

---

**Создать `ApplicationRepository`**

Создать класс `ApplicationRepository` в `Inc/Repositories/WPDBRepositories/`. Работает с таблицей `{prefix}fs_lms_applications` через `$wpdb`.

Методы:
- `find(int $id): ?ApplicationDTO` — SELECT по id
- `findByJoinCodeHash(string $hash): ?ApplicationDTO` — SELECT по `join_code_hash`; возвращает только если status входит в активные статусы
- `findActiveByEmail(string $email): ?ApplicationDTO` — ищет незавершённую заявку от этого ученика; статусы `pending_parent`, `ready_for_review`
- `list(array $filters, int $page, int $perPage): array` — постраничный список; фильтры: status, date_from, date_to
- `count(array $filters): int` — для пагинации
- `create(array $data): int` — INSERT, возвращает вставленный ID
- `update(int $id, array $data): bool` — UPDATE только переданных полей
- `setStatus(int $id, ApplicationStatus $status): bool` — проверяет `canTransitionTo()` перед обновлением, бросает если переход запрещён
- `markConverted(int $id, int $enrollmentId): bool` — статус `converted` + `converted_to_enrollment_id`
- `findStuckEnrolling(int $minMinutes): array` — applications в статусе `enrolling` старше N минут (для recovery)
- `findExpiredPending(): array` — applications в статусах `pending_parent`/`ready_for_review` с истёкшим `join_code_expires_at`
- `delete(int $id): bool` — физическое удаление; бросает `\LogicException` если статус не `trash`; защита от случайного удаления активных заявок

---

**Создать `PersonRepository`**

Создать класс `PersonRepository implements RepositoryInterface` в `Inc/Repositories/WPDBRepositories/`. Таблица `{prefix}fs_lms_persons`.

Методы:
- `find(int $id): ?PersonDTO`
- `findByWpUserId(int $userId): ?PersonDTO`
- `findByDocHash(string $hash): ?PersonDTO` — поиск по `doc_number_hash`
- `findByInnHash(string $hash): ?PersonDTO`
- `findByEmail(string $email): ?PersonDTO`
- `findByEnrollment(int $enrollmentId): array` — возвращает студента + его актуальных представителей (нужно для recovery job)
- `create(array $data): int`
- `update(int $id, array $data): bool`
- `setWpUser(int $id, int $wpUserId): bool` — UPDATE `wp_user_id`
- `softDelete(int $id): bool` — `deleted_at = NOW()`
- `anonymize(int $id): bool` — обнуляет все `*_enc` колонки в NULL
- `findDeletedOlderThan(int $days): array` — для retention job

---

**Создать `RelationshipRepository`**

Создать класс `RelationshipRepository implements RepositoryInterface` в `Inc/Repositories/WPDBRepositories/`. Таблица `{prefix}fs_lms_relationships`.

Методы:
- `find(int $id): ?RelationshipDTO`
- `findActiveByStudent(int $studentPersonId): array` — `valid_to IS NULL OR valid_to > CURDATE()`
- `findActiveByGuardian(int $guardianPersonId): array`
- `findActivePair(int $guardianId, int $studentId): ?RelationshipDTO`
- `create(array $data): int`
- `createIfNotExists(array $data): int` — INSERT IGNORE по UNIQUE-ключу `(guardian_person_id, student_person_id, valid_from)`; если строка уже есть — возвращает её ID
- `terminate(int $id, ?string $date = null): bool` — UPDATE `valid_to`; если дата не передана — TODAY()

---

**Создать `EnrollmentRepository`**

Создать класс `EnrollmentRepository implements RepositoryInterface` в `Inc/Repositories/WPDBRepositories/`. Таблица `{prefix}fs_lms_enrollments`.

Методы:
- `find(int $id): ?EnrollmentDTO`
- `findBySourceApplication(int $appId): ?EnrollmentDTO`
- `findActiveByStudent(int $personId): array`
- `existsActive(int $personId, string $subjectKey, string $periodKey): bool` — проверяет UNIQUE `(student_person_id, subject_key, period_key)` через SELECT; используется до INSERT для понятного сообщения об ошибке
- `list(array $filters, int $page, int $perPage): array`
- `create(array $data): int`
- `setStatus(int $id, EnrollmentStatus $status, ?array $terminationData = null): bool` — при передаче `terminationData` также заполняет `terminated_at`, `terminated_reason`, `terminated_by_user_id`

---

**Создать `ConsentRepository`**

Создать класс `ConsentRepository implements RepositoryInterface` в `Inc/Repositories/WPDBRepositories/`. Таблица `{prefix}fs_lms_consents`.

Методы:
- `find(int $id): ?ConsentDTO`
- `findByApplication(int $appId): array` — все согласия, привязанные к заявке
- `findActiveByPerson(int $personId): array` — `withdrawn_at IS NULL AND (valid_until IS NULL OR valid_until > NOW())`
- `create(array $data): int`
- `bindApplicationConsentsToPersons(int $appId, array $personMap): int` — обновляет записи согласий, привязанных к заявке, проставляя `person_id`; `personMap` вида `['self' => studentPersonId, 'guardian' => guardianPersonId, 'for_child' => studentPersonId]`; возвращает количество обновлённых строк
- `withdraw(int $id, string $reason): bool` — `withdrawn_at = NOW()`, `withdrawn_reason = ?`

---

**Создать `AuditLogRepository`**

Создать класс `AuditLogRepository implements RepositoryInterface` в `Inc/Repositories/WPDBRepositories/`. Таблица `{prefix}fs_lms_audit_log`.

Методы — только чтение и добавление, никакого редактирования или удаления записей:
- `record(array $event): int` — INSERT; поля: `actor_user_id`, `actor_role`, `action`, `target_type`, `target_id`, `details_json`, `actor_ip`, `actor_ua`, `created_at`
- `listByTarget(string $targetType, int $targetId, int $limit = 50): array`
- `listByActor(int $userId, int $limit = 50): array`
- `purgeOlderThan(int $days): int` — DELETE записей старше N дней; возвращает количество удалённых (для retention job)

---

**Создать `PiiAccessLogRepository`**

Создать класс `PiiAccessLogRepository implements RepositoryInterface` в `Inc/Repositories/WPDBRepositories/`. Таблица `{prefix}fs_lms_pii_access_log`. Аналогично AuditLogRepository — только append и чтение.

Методы:
- `record(array $event): int` — поля: `actor_user_id`, `actor_role`, `person_id`, `fields_accessed`, `access_reason`, `actor_ip`, `created_at`
- `listByPerson(int $personId, int $limit = 50): array`
- `listByActor(int $userId, int $limit = 50): array`
- `purgeOlderThan(int $days): int`

---

## DTO

---

**Создать Row-DTO и Decrypted-DTO**

Создать в `inc/DTO/` следующие readonly-классы:

Row-DTO (отражают строки таблиц; поля `*_enc` хранятся как `string` — бинарный blob, не расшифровываются):
- `ApplicationDTO` — все поля таблицы `fs_lms_applications`
- `PersonDTO` — все поля таблицы `fs_lms_persons`
- `RelationshipDTO`
- `EnrollmentDTO`
- `ConsentDTO`

Decrypted-DTO (с расшифрованными данными для отображения; создаются сервисами, не репозиториями):
- `ApplicationDecryptedDTO` — содержит `StudentDataDTO $studentData` и `?ParentDataDTO $parentData`
- `StudentDataDTO` — расшифрованные данные ученика из заявки: `fullName`, `email`, `school`, `grade`, `birthDate`, `docType`, `docNumber`, `inn`
- `ParentDataDTO` — расшифрованные данные родителя: `fullName`, `birthDate`, `relationType`, `docType`, `docNumber`, `docIssuedBy`, `docIssuedDate`, `inn`, `address`, `phone`, `email`
- `PersonDecryptedDTO` — расшифрованные поля person: `fullName`, `pass`, `inn`, `address`, `phone`
- `EnrollmentDecryptedDTO` — с `array $snapshot`

У каждого Row-DTO реализовать `fromArray(array $row): static` и `toArray(): array`.

---

**Создать Input-DTO и Result-DTO**

Создать в `inc/DTO/`:

Input-DTO (входные данные для сервисов из форм):
- `ApplicationInputDTO` — данные из формы ученика: `fullName`, `email`, `school`, `grade`, `birthDate`, `consentAccepted`, `captchaToken`, `ip`, `userAgent`
- `ParentSubmissionInputDTO` — данные из формы родителя: все поля ParentDataDTO + поправленные поля ученика + `joinCode`
- `EnrollmentInputDTO` — данные из модалки зачисления: `applicationId`, `contractNo`, `contractDate`, `orderNo`, `orderDate`, `enrolledAt`, `subjectKey`, `groupId`, `periodKey`, `sendEmailAuto`
- `RepresentativeInputDTO` — форма добавления представителя
- `RequestContext` — контекст запроса: `ip`, `userAgent`, `actorUserId`

Result-DTO:
- `ApplicationCreatedDTO` — `joinUrl`, `expiresAt`, `applicationId`
- `EnrollmentResultDTO` — `enrollmentId`, `studentUserId`, `guardianUserId`, `guardianPasswordLink`, `studentPasswordLink` (последние два — null если отправлено на email)

---

## Shared Traits

---

**Создать trait `TransactionRunner`**

Создать trait `TransactionRunner` в `inc/Shared/Traits/`.

Метод `inTransaction(callable $fn): mixed`:
1. Вызывает `$wpdb->query('START TRANSACTION')`
2. Выполняет `$fn()`
3. При успехе — `COMMIT`, возвращает результат `$fn()`
4. При исключении — `ROLLBACK`, перебрасывает исключение

Пример использования в сервисе:
```php
$enrollmentId = $this->inTransaction(function() use (...) {
    // INSERT persons, enrollment, etc.
    return $enrollmentId;
});
```

---

**Создать trait `RequestContextProvider`**

Создать trait `RequestContextProvider` в `inc/Shared/Traits/`.

Метод `requestContext(): RequestContext`:
- IP берётся из `$_SERVER['REMOTE_ADDR']`; если сервер за proxy — проверять `HTTP_X_FORWARDED_FOR`, но только если IP прокси в доверенном списке из настроек (иначе можно подделать)
- User Agent из `$_SERVER['HTTP_USER_AGENT']`
- Actor — `get_current_user_id()` (0 для анонимных)

IP хранить как бинарный через `inet_pton` для совместимости с VARBINARY(16) в БД.

---

## Сервисы

---

**Реализовать `AuditService`**

Создать класс `AuditService` в `inc/Services/`. Единая точка записи в audit log — всё остальное через него, прямых вызовов `AuditLogRepository::record()` из бизнес-кода быть не должно.

Зависимости: `AuditLogRepository`. Использует trait `RequestContextProvider`.

Методы:
- `record(string $action, string $targetType, ?int $targetId, ?array $details = null): void` — собирает контекст запроса (actor, IP, UA), формирует запись, пишет в репозиторий. В `details_json` писать только метаданные изменений — никогда не писать значения PII (только хэши и названия изменённых полей)
- `recordAnonymous(string $action, string $targetType, ?int $targetId, ?array $details = null): void` — то же, но для публичных endpoint-ов без авторизации

---

**Реализовать `JoinCodeService`**

Создать класс `JoinCodeService` в `inc/Services/`.

Зависимости: `PiiCryptoService`.

Методы:
- `generate(): string` — генерирует код формата `JOIN-XXXX-XXXX-XXXX`. Алфавит символов: `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (без визуально похожих 0/O/1/I/l). Каждый символ выбирается через `random_int()`. 12 значащих символов → ~60 бит энтропии.
- `hash(string $code): string` — делегирует в `PiiCryptoService::hash()`
- `isValidFormat(string $code): bool` — проверяет regex `^JOIN-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$`

---

**Реализовать `PasswordLinkService`**

Создать класс `PasswordLinkService` в `inc/Services/`.

Зависимости: `UserManager`, `AuditService`.

Методы:
- `generate(int $userId): string` — получает WP-юзера, вызывает `get_password_reset_key($user)`, обрабатывает `WP_Error`, собирает URL вида `wp-login.php?action=rp&key=...&login=...`, пишет audit log (`AuditAction::PasswordLinkGenerated`), возвращает полный URL
- `invalidate(int $userId): void` — затирает `user_activation_key` для данного юзера
- `getDefaultTtl(): int` — возвращает текущий TTL через `apply_filters('password_reset_expiration', DAY_IN_SECONDS)`

Дополнительно — зарегистрировать фильтр `password_reset_expiration` в контроллере, который увеличивает TTL до 48 часов для пользователей с LMS-ролями.

---

**Реализовать `RateLimitService`**

Создать класс `RateLimitService` в `inc/Services/`.

Реализация через WP-transients. Ключ transient хранит не IP напрямую, а `hash('sha256', $ip . FS_LMS_HASH_SALT)`.

Методы:
- `allowApplicationCreation(string $ip): bool` — не более 5 попыток в час с одного IP
- `allowJoinAttempt(string $ip): bool` — не более 10 попыток открытия любых кодов в час
- `allowParentSubmit(string $ip): bool` — не более 3 submit-ов в час
- `allowPiiReveal(int $userId): bool` — не более 100 reveal-операций с одного user_id в час
- `reset(string $key): void` — сбросить конкретный счётчик (для тестов и ручного управления)

Каждый метод должен инкрементировать счётчик при вызове. При превышении лимита — возвращать false (вызывающий код отвечает за ответ 429).

---

**Реализовать `CaptchaService`**

Создать класс `CaptchaService` в `inc/Services/`. Класс — адаптер над конкретным провайдером капчи (выбор провайдера согласовать с командой; рекомендуемые варианты: hCaptcha или Yandex SmartCaptcha).

Методы:
- `validate(string $token, string $remoteIp): bool` — отправляет запрос к API провайдера для верификации токена
- `getSiteKey(): string` — возвращает публичный ключ для рендера капчи на фронте

Ключи (site key и secret key) хранятся в wp_options, редактируются через страницу настроек FS LMS. При отсутствии ключей — `validate()` возвращает true (с предупреждением в admin notice, что капча не настроена).

---

**Реализовать `EmailService`**

Создать класс `EmailService` в `inc/Services/`.

Зависимости: `UserManager`. Использует trait `TemplateRenderer` для шаблонов писем.

Методы:
- `sendPasswordSetup(int $userId, string $link): bool` — отправляет письмо со ссылкой установки пароля; шаблон `templates/emails/password-setup.php`
- `sendApplicationConfirmation(int $appId): bool` — ученику после создания заявки; шаблон `templates/emails/application-confirmation.php`
- `sendApplicationReadyNotificationToAdmin(int $appId): bool` — всем пользователям с `ManageApplications` capability
- `sendRejectionNotification(int $appId): bool` — родителю при отклонении
- `sendNewRepresentativeNotification(int $userId, ?string $link): bool` — когда к родителю добавили нового ребёнка; если `$link` передан — включить в письмо
- `sendOtpCode(string $email, string $code): bool` — отправляет 6-значный код подтверждения; шаблон `templates/emails/otp-code.php`; тема письма: "Код подтверждения — FS LMS"

Все письма использовать через `wp_mail()` с Content-Type `text/html`. Отправитель настраивается через фильтры `wp_mail_from` и `wp_mail_from_name`.

Создать HTML-шаблоны писем в `templates/emails/`. Минимальный дизайн с логотипом и брендингом.

---

**Реализовать `EmailOtpService`**

Создать класс `EmailOtpService` в `inc/Services/`.

Зависимости: `EmailService`.

Хранение кода — в WP-transient. Ключ: `fs_lms_otp_{sha256(email)}`, TTL 10 минут. Сохраняется хэш кода `hash('sha256', $code . FS_LMS_HASH_SALT)`, а не сам код.

Методы:
- `sendCode(string $email): void` — генерирует `random_int(100000, 999999)`, сохраняет хэш в transient, отправляет через `EmailService::sendOtpCode()`. Не отправлять если cooldown ещё активен — выбрасывать `\RuntimeException('Повторная отправка возможна через N секунд')`
- `verify(string $email, string $code): bool` — сравнивает `hash('sha256', $code . FS_LMS_HASH_SALT)` с сохранённым transient; при совпадении — удаляет transient (одноразовость), возвращает true. Возвращает false если transient истёк или хэш не совпал. Если константа `FS_LMS_OTP_BYPASS_CODE` определена и `$code === FS_LMS_OTP_BYPASS_CODE` — возвращает true без проверки transient (только для внутреннего тестирования, никогда не раскрывать публично)
- `canResend(string $email): bool` — проверяет cooldown: повторная отправка не ранее чем через 60 секунд. Отдельный transient `fs_lms_otp_cooldown_{sha256(email)}` с TTL 60 секунд
- `invalidate(string $email): void` — удаляет transient вручную (при отмене заявки)

Bypass-код `FS_LMS_OTP_BYPASS_CODE`:
- Опциональная константа в `wp-config.php`
- Предназначена только для внутреннего тестирования и демо-сессий
- Никогда не использовать в продакшне без понимания рисков; задокументировать это явно
- Генерировать как минимум 16-символьную случайную строку

---

**Реализовать `PersonService`**

Создать класс `PersonService` в `inc/Services/`.

Зависимости: `PersonRepository`, `PiiCryptoService`, `AuditService`.

Методы:
- `createOrFindBy(array $rawData): int` — ищет существующего person по `doc_number_hash` через репозиторий. Если находит — возвращает его ID. Если нет — шифрует PII, создаёт нового через репозиторий, возвращает ID. Метод идемпотентен.
- `update(int $personId, array $changes, int $actorId): void` — обновляет данные person с шифрованием; в audit log пишет только список изменённых полей (без значений): `{'changed_fields': ['phone', 'address']}`
- `softDelete(int $personId, int $actorId): void` — делегирует в `PersonRepository::softDelete()`, пишет audit log
- `anonymize(int $personId): void` — вызывается retention job; затирает все `*_enc` через `PersonRepository::anonymize()`

---

**Реализовать `PersonReader`**

Создать класс `PersonReader` в `inc/Services/`. Это единственный санкционированный способ читать PII для отображения. Прямой вызов `PiiCryptoService::decrypt()` из контроллеров и callbacks запрещён.

Зависимости: `PersonRepository`, `PiiCryptoService`, `PiiAccessLogRepository`. Использует trait `RequestContextProvider`.

Методы:
- `readForDisplay(int $personId, array $fields, string $reason): PersonDecryptedDTO` — загружает person, расшифровывает запрошенные поля, автоматически пишет запись в `pii_access_log` (actor, person_id, fields_accessed, reason, IP), возвращает DTO
- `readField(int $personId, string $field, string $reason): string` — расшифровывает одно поле; используется для AJAX reveal

Важно: если поле `*_enc` равно NULL (обезличенная запись) — возвращать пустую строку, не бросать исключение.

---

**Реализовать `RelationshipService`**

Создать класс `RelationshipService` в `inc/Services/`.

Зависимости: `RelationshipRepository`, `AuditService`.

Методы:
- `addRepresentative(int $guardianPersonId, int $studentPersonId, RelationType $type, bool $isPrimary): int` — создаёт связь через `createIfNotExists`; пишет audit log
- `replaceRepresentative(int $oldRelationshipId, int $newGuardianPersonId, RelationType $newType): int` — в одной операции: проставляет `valid_to = today()` старой связи + создаёт новую. Транзакционно через `TransactionRunner`.
- `terminate(int $relationshipId, string $reason): void`
- `getActiveRepresentatives(int $studentPersonId): array` — возвращает `RelationshipDTO[]`
- `getActiveDependents(int $guardianPersonId): array`
- `canRepresent(int $guardianWpUserId, int $studentPersonId): bool` — проверяет, есть ли активная связь между wp_user.fs_lms_person_id и student; используется в проверках доступа родителя к данным ребёнка на уровне приложения

---

## Согласия (152-ФЗ)

---

**Создать тексты согласий v1**

Создать директорию `templates/consents/v1/`.

Написать и согласовать с юристом следующие HTML-файлы:
- `pd_processing.html` — согласие законного представителя на обработку ПД ребёнка

Требования к текстам: должны содержать цель обработки, перечень обрабатываемых данных, срок хранения, право на отзыв согласия, контакты оператора.

Важно: папка `v1/` и её содержимое никогда не удаляются и не изменяются — только добавляются новые папки `v2/`, `v3/` и т.д. Это нужно для воспроизведения "что именно подписал конкретный человек в конкретную дату".

---

**Реализовать `ConsentService`**

Создать класс `ConsentService` в `inc/Services/`.

Зависимости: `ConsentRepository`, `AuditService`. Читает файлы из `templates/consents/`.

Методы:
- `getCurrentVersion(ConsentType $type): string` — возвращает последнюю версию, определяя её по наличию папок в `templates/consents/` (lexicographic max: `v1`, `v2` и т.д.)
- `getDocumentText(ConsentType $type, string $version): string` — возвращает содержимое файла; бросает если версия не существует
- `getDocumentHash(ConsentType $type, string $version): string` — sha256 от точного содержимого файла
- `recordSelfConsent(?int $appId, ConsentType $type, RequestContext $ctx): int` — создаёт запись в consents: версия, хэш, IP, UA, timestamp; возвращает ID
- `recordGuardianConsent(?int $appId, int $forPersonId, RequestContext $ctx): int` — то же, `signed_by_role = 'guardian'`, заполняет `signed_for_person_id`
- `bindToPersons(int $appId, array $personMap): void` — делегирует в `ConsentRepository::bindApplicationConsentsToPersons()`
- `withdraw(int $consentId, string $reason): void`

---

**Зарегистрировать публичную страницу просмотра согласия**

В `ConsentController` добавить:
1. Rewrite rule для маршрута `/lms/consent/{type}/{version}`
2. Template callback, который рендерит текст согласия из файла через `ConsentService::getDocumentText()`

Если запрошенная версия не существует — возвращать 404.

Эта страница используется в модалке форм заявки ("Прочитать текст согласия").

Страница должна  автоматически создаваться при активации плагина через generatePages (PageGeneratorService)

---

**Реализовать управление текстом согласия через вкладку Settings**

Добавить вкладку "Согласие на ПД" в страницу настроек плагина (`templates/admin/settings.php`).
Администратор должен иметь возможность вводить и публиковать текст согласия без деплоя файлов.

Структура хранения в `wp_options` (ключ `fs_lms_consent_texts`):
```json
{
  "pd_child_processing": {
    "v1": { "text": "<p>...</p>", "saved_at": "2026-01-15 10:00:00" },
    "v2": { "text": "<p>...</p>", "saved_at": "2026-03-10 14:30:00" }
  }
}
```
Версии иммутабельны — только добавляются, не редактируются. Текущая = наибольшая по `natsort`.

Шаги реализации:

1. Добавить `case ConsentTexts = 'fs_lms_consent_texts'` в `OptionName`.
2. Добавить `case SaveConsentVersion = 'SaveConsentVersion'` в `AjaxHook`.
3. Зарегистрировать хук `wp_ajax_save_consent_version` в контроллере.
4. Реализовать `ajaxSaveConsentVersion()` в Callbacks:
   - `authorize(Nonce::Manager, Capability::Admin)`
   - Определить следующую версию (`v{N+1}`) из текущих ключей option
   - Сохранить `['text' => wp_kses_post($text), 'saved_at' => current_time('mysql', true)]`
   - `update_option(OptionName::ConsentTexts->value, $options)`
5. Обновить `ConsentService::getDocumentText()`: сначала читает из option, fallback на файл.
6. Обновить `ConsentService::getCurrentVersion()`: сначала читает ключи из option, fallback на сканирование директории.
7. Создать `templates/admin/components/tabs/settings-tabs/settings-4-consent.php`:
   - Верхняя зона: таблица версий (read-only) с датой и ссылкой `/lms/consent/{type}/{version}`
   - Нижняя зона: `wp_editor()` для текста + кнопка "Опубликовать как v{N+1}" → AJAX
8. Добавить `tab-4` в `settings.php`.
9. Добавить JS-модуль: собрать текст через `wp.editor.getContent()`, отправить AJAX, обновить таблицу версий.
   `getDocumentHash()` не меняется — `sha256` считается от того, что вернул `getDocumentText()`.

---

## Audit log и PII access log

---

**Создать UI просмотра Audit Log в админке**

Создать страницу "Журнал действий" как подменю в FS LMS (доступна по capability `ManageApplications`).

Показывать таблицу с колонками: дата, пользователь, роль, действие, объект, детали.
Добавить фильтры: action, target_type, actor, период (date range).
Пагинация — через стандартный WP-механизм.

Данные берутся через `AuditLogRepository::listByTarget()` и `listByActor()`.

---

**Создать UI просмотра PII Access Log в админке**

Создать отдельную страницу "Журнал доступа к ПД". Доступна только пользователям с `ExportPII` capability (отдельное право, выдаётся только DPO или главному администратору).

Фильтры: person_id, actor, период.
Показывает: дата/время, кто смотрел, чьи данные, какие поля, с какой целью.

Важен для проверки соответствия 152-ФЗ: по нему можно ответить на вопрос "кто когда просматривал паспорт ученика X".

---

**Реализовать `PersonReader`**

(Уже описан выше в разделе "Сервисы" — здесь как напоминание, что именно он обязателен для любого отображения PII.)

Важно задокументировать в CLAUDE.md: **любое чтение PII для отображения пользователю — только через `PersonReader`**. Прямые вызовы `PiiCryptoService::decrypt()` в callbacks и контроллерах запрещены.

---

## Публичная форма ученика

---

**Зарегистрировать маршрут `/lms/apply` и шаблон страницы**

В `ApplicationController::register()`:
1. Добавить rewrite rule для `/lms/apply`
2. Зарегистрировать фильтр `template_include`, подменяющий шаблон для этого URL на `templates/frontend/apply.php`
3. Сбросить rewrite rules при активации плагина (`flush_rewrite_rules()`)

Страница должна быть доступна анонимным пользователям.

---

**Создать HTML/CSS формы ученика**

Создать шаблон `templates/frontend/apply.php`.

Поля формы:
- ФИО (текст, обязательное)
- Email (обязательное, с валидацией формата)
- Школа (текст, опциональное)
- Класс (число от 1 до 11)
- Дата рождения (date-picker)
- Чекбокс "Я согласен(а) на обработку персональных данных" (обязательное) + ссылка "Прочитать" рядом с ним; по клику — модалка с текстом из `pd_processing.html`
- Слот для капчи
- Кнопка "Подать заявку"

Вёрстка: адаптивная, базовые стили в `src/scss/frontend/`. Без зависимостей от сторонних CSS-фреймворков.

---

**Создать JS-логику формы ученика**

Создать модуль в `src/js/frontend/apply-form.js`.

Логика (двухэтапный поток):

**Этап 1 — Капча → OTP:**
1. Клиентская валидация всех полей: формат email, дата рождения в диапазоне (от 6 до 18 лет), чекбокс согласия отмечен
2. При нажатии "Подать заявку" — показать виджет капчи (не рендерить заранее)
3. После успешного прохождения капчи — AJAX на `AjaxHook::SendOtpCode` с captcha token + email
4. При ошибке (cooldown, капча невалидна) — показать сообщение
5. При успехе — скрыть основную форму, показать экран ввода кода:
   - Текст: "Код отправлен на {maskedEmail}"
   - Поле ввода 6 цифр (autofocus, цифровая клавиатура на мобильных: `inputmode="numeric"`)
   - Кнопка "Подтвердить"
   - Ссылка "Отправить ещё раз" (активна через 60 секунд, показывает обратный отсчёт)

**Этап 2 — Верификация OTP → создание заявки:**
1. AJAX на `AjaxHook::CreateApplication` с кодом + всеми полями формы
2. При ошибке (неверный код, истёкший) — показать сообщение под полем ввода, не сбрасывать
3. При успехе — показать экран с JOIN-ссылкой:
   - URL ссылки крупно
   - Кнопка "Скопировать ссылку"
   - Инструкция: "Передайте эту ссылку родителю или законному представителю. Ссылка действительна 14 дней."
   - Срок истечения (из response)

---

**Реализовать `ApplicationService::createApplication`**

Реализовать метод в `ApplicationService`.

Принимает `ApplicationInputDTO`, возвращает `ApplicationCreatedDTO`.

Логика:
1. Проверить нет ли активной незавершённой заявки от этого email (`findActiveByEmail`)
2. Сгенерировать JOIN-код через `JoinCodeService::generate()`
3. Зашифровать student_data через `PiiCryptoService::encrypt(json_encode(...))`
4. Запустить транзакцию через `TransactionRunner`:
   - INSERT в applications (status `pending_parent`, `join_code_hash`, `student_data_enc`, `expires = NOW() + 14 days`)
   - INSERT согласия через `ConsentService::recordSelfConsent()`
   - INSERT audit log через `AuditService::recordAnonymous(AuditAction::CreateApplication, ...)`
5. Вернуть `ApplicationCreatedDTO` с join_url, expiresAt, applicationId

JOIN-код включается в `join_url`, но **не сохраняется в БД** — только его хэш.

---

**Создать `ApplicationCallbacks::ajaxSendOtpCode`**

Реализовать метод в `ApplicationCallbacks`. Это первый шаг двухэтапной формы.

Последовательность:
1. Проверить nonce (`Nonce::Apply->verify()`)
2. Проверить rate limit (`RateLimitService::allowApplicationCreation()`) → 429 если превышен
3. Проверить капчу (`CaptchaService::validate()`) → 400 если невалидна
4. Санитизировать email (`Sanitizer::requireText()`)
5. Проверить cooldown через `EmailOtpService::canResend()` → если false, ответить с сообщением через сколько секунд можно повторить
6. Отправить код через `EmailOtpService::sendCode($email)`
7. Вернуть `success(['email' => $maskedEmail])` — замаскировать email перед показом (например, `p****@gmail.com`)

---

**Создать `ApplicationCallbacks::ajaxCreateApplication`**

Реализовать метод в `ApplicationCallbacks`. Это второй шаг — после верификации OTP.

Последовательность:
1. Проверить nonce (`Nonce::VerifyOtp->verify()`)
2. Проверить rate limit (`RateLimitService::allowApplicationCreation()`) → 429 если превышен
3. Санитизировать поля формы и `otp_code` через `Sanitizer` trait
4. Верифицировать OTP: `EmailOtpService::verify($email, $otpCode)` → 400 если неверный или истёкший
5. Собрать `ApplicationInputDTO`
6. Вызвать `ApplicationService::createApplication()`
7. Вернуть ответ через `AjaxResponse` trait с join_url и expires_at

---

## Публичная форма родителя

---

**Зарегистрировать маршрут `/lms/join/{code}`**

В `ApplicationController::register()` добавить rewrite rule с параметром `code`. Шаблон — `templates/frontend/join.php`.

---

**Реализовать серверную логику при открытии JOIN-ссылки**

В `ApplicationCallbacks::renderJoinPage(string $code)`:
1. Проверить rate limit (`RateLimitService::allowJoinAttempt()`)
2. Хэшировать код через `JoinCodeService::hash()`
3. Искать заявку через `ApplicationRepository::findByJoinCodeHash()`
4. Если не найдено / статус не `pending_parent` / истекло → отдать generic 404 (НЕ раскрывать причину в тексте ошибки)
5. Расшифровать `student_data_enc` через `PiiCryptoService::decrypt()`
6. Записать `AuditService::recordAnonymous(AuditAction::ViewJoinLink, ...)`
7. Отрендерить форму, прокинув расшифрованные данные ученика в шаблон

---

**Создать HTML/CSS формы родителя**

Создать шаблон `templates/frontend/join.php`.

Форма состоит из трёх блоков:

Блок 1 — "Данные ученика" (предзаполнены, можно редактировать):
- ФИО ученика, дата рождения, школа, класс
- Тип документа ученика (паспорт / свидетельство о рождении), серия и номер, ИНН ученика

Блок 2 — "Данные представителя":
- ФИО, дата рождения
- Серия и номер паспорта, кем выдан, дата выдачи
- ИНН, СНИЛС
- Адрес прописки, телефон, email
- Тип отношения (выпадающий список из `RelationType`)

Блок 3 — "Согласия":
- Чекбокс "Согласен на обработку своих персональных данных" + ссылка на `pd_processing.html`
- Чекбокс "Как законный представитель даю согласие на обработку ПД ребёнка" + ссылка на `pd_child_processing.html`
- Оба чекбокса обязательны

---

**Создать JS-логику формы родителя**

Создать модуль в `src/js/frontend/join-form.js`.

Логика:
1. Валидация: формат паспорта, ИНН (12 цифр для физлица, 10 для юрлица), телефон, оба чекбокса отмечены
2. AJAX submit с JOIN-кодом в payload
3. При ошибке — показать сообщения
4. При успехе — экран "Заявка принята к рассмотрению. Когда администратор проверит данные, вы получите письмо с инструкцией по доступу в личный кабинет."

---

**Реализовать `ApplicationService::submitParentData`**

Реализовать метод, принимающий `ParentSubmissionInputDTO`.

Логика:
1. Найти и валидировать заявку по JOIN-коду (статус должен быть `pending_parent`)
2. Зашифровать `parent_data`; если родитель поправил данные ученика — перезашифровать `student_data`
3. Транзакционно:
   - UPDATE applications: `parent_data_enc`, `student_data_enc`, `status = ready_for_review`, `parent_submitted_ip`, `parent_submitted_ua`
   - INSERT два согласия через `ConsentService::recordSelfConsent()` и `ConsentService::recordGuardianConsent()`
   - INSERT audit log `AuditAction::SubmitParentData`
4. Запустить хук `do_action('fs_lms_application_ready', $appId)` — на него подписан `EmailService` для уведомления админов

---

**Создать `ApplicationCallbacks::ajaxSubmitParentData`**

Аналогично `ajaxCreateApplication`:
1. Nonce + rate limit + sanitize
2. Проверить формат JOIN-кода через `JoinCodeService::isValidFormat()`
3. Собрать `ParentSubmissionInputDTO`
4. Делегировать в `ApplicationService::submitParentData()`
5. Ответ через `AjaxResponse`

---

## Админ-интерфейс заявок

---

**Добавить подменю "Заявки" в админ-панели**

В `EnrollmentController::register()` добавить `admin_menu` хук, который добавляет страницу "Заявки" как подменю к существующему меню FS LMS.

Доступна пользователям с `Capability::ManageApplications`.
Callback отрисовки — `EnrollmentCallbacks::renderApplicationsListPage()`.

---

**Создать `WP_List_Table` для списка заявок**

Реализовать `EnrollmentCallbacks::renderApplicationsListPage()`.

Таблица с колонками: Дата подачи, ФИО ученика, ФИО представителя, Школа/класс, Статус, Действия.
ФИО получать через `PersonReader` только для строк, где данные уже расшифрованы — для списка допустимо читать имена без полного PII (это менее чувствительные данные), но каждый показ логировать с reason `applications_list`.
Фильтр по статусу (включая представление "Корзина" — показывает только `Trash`).
По умолчанию список **не показывает** заявки со статусом `Trash` — они отфильтрованы.
Пагинация.
В колонке "Действия":
- Ссылка "Открыть" (для активных заявок)
- Ссылка "В корзину" (для `PendingParent`, `ReadyForReview`, `Rejected`, `Expired`)
- Ссылка "Восстановить" (только в представлении "Корзина")
В шапке таблицы — кнопка "Очистить корзину" (только в представлении "Корзина", требует confirm-диалога).

---

**Реализовать AJAX "Переместить заявку в корзину"**

Реализовать `ApplicationCallbacks::ajaxMoveApplicationToTrash()`.

1. Проверить nonce (`Nonce::TrashApplication->verify()`)
2. Проверить `Capability::ManageApplications`
3. Санитизировать `application_id`
4. Вызвать `ApplicationRepository::setStatus($id, ApplicationStatus::Trash)` — метод проверит `isTrashable()` перед переходом
5. Audit log `AuditAction::MoveToTrash`
6. Ответ через `AjaxResponse`

---

**Реализовать AJAX "Восстановить заявку из корзины"**

Реализовать `ApplicationCallbacks::ajaxRestoreApplicationFromTrash()`.

1. Проверить nonce (`Nonce::TrashApplication->verify()`)
2. Проверить `Capability::ManageApplications`
3. Санитизировать `application_id`
4. Определить статус для восстановления: если у заявки заполнен `parent_data_enc` → `ReadyForReview`, иначе → `PendingParent`
5. Вызвать `ApplicationRepository::setStatus($id, $targetStatus)`
6. Audit log `AuditAction::RestoreFromTrash`
7. Ответ через `AjaxResponse`

---

**Реализовать AJAX "Очистить корзину"**

Реализовать `ApplicationCallbacks::ajaxEmptyApplicationsTrash()`.

1. Проверить nonce (`Nonce::TrashApplication->verify()`)
2. Проверить `Capability::ManageApplications`
3. Найти все заявки со статусом `Trash` через `ApplicationRepository::list(['status' => Trash])`
4. Для каждой: физически удалить через `ApplicationRepository::delete($id)` (жёсткое удаление — только из корзины)
5. Audit log `AuditAction::EmptyTrash` с количеством удалённых
6. Ответ через `AjaxResponse`

Метод `ApplicationRepository::delete(int $id): bool` — DELETE только если status = `Trash`, иначе бросает исключение (защита от случайного удаления активных заявок).

---

**Создать карточку заявки в админке**

Реализовать `EnrollmentCallbacks::renderApplicationDetailPage(int $appId)`.

Страница `?page=fs-lms-applications&id=N`. Проверяет `Capability::ViewPII`.

Отображает:
- Данные ученика — все поля, документы **маскированы** через `PiiMaskingService`, рядом с каждым кнопка "Показать"
- Данные представителя — аналогично
- История заявки: когда создана, когда родитель заполнил, текущий статус
- Список подписанных согласий: тип, версия, дата подписи
- Кнопки: "Зачислить" (открывает модалку) и "Отклонить" (открывает модалку с полем причины)

При открытии страницы записывать в `PiiAccessLogRepository` через `PersonReader`.

---

**Реализовать AJAX "Показать PII-поле"**

Реализовать `PiiCallbacks::ajaxRevealPiiField()`.

Принимает: `person_id`, `field` (тип PiiField), `reason`.

Логика:
1. Проверить nonce (`Nonce::RevealPii`)
2. Проверить capability `Capability::ViewPII`
3. Проверить rate limit `RateLimitService::allowPiiReveal()`
4. Вызвать `PersonReader::readField(person_id, field, reason)`
5. Вернуть расшифрованное значение

На фронте: значение показывается в UI на 30 секунд, затем возвращается обратно маска. Реализовать через JS timeout.

---

**Реализовать уведомление админа о новой заявке**

Подписаться на хук `fs_lms_application_ready` (который триггерит `ApplicationService::submitParentData`).

Обработчик:
1. Находит всех пользователей с `Capability::ManageApplications`
2. Отправляет им email через `EmailService::sendApplicationReadyNotificationToAdmin()`

Опционально — admin notice с счётчиком `ready_for_review` заявок в дашборде FS LMS.

---

## Зачисление

---

**Создать модалку "Зачислить" (UI)**

Модалка открывается по кнопке "Зачислить" на карточке заявки.

Поля:
- Номер договора (текст)
- Дата договора (date)
- Номер приказа (текст)
- Дата приказа (date)
- Дата зачисления (date, по умолчанию сегодня)
- Период (выпадающий список из `OptionName::Periods`)
- Предмет (выпадающий список из существующих Subjects)
- Группа (выпадающий список — зависит от предмета; подгружается AJAX-запросом при смене предмета)
- Чекбокс "Отправить ссылки на email автоматически" (по умолчанию включён)

JS-валидация всех полей перед отправкой.

---

**Реализовать `EnrollmentService::enroll` — pre-flight проверки**

Первая часть метода `enroll(EnrollmentInputDTO $input)`.

Pre-flight выполняется вне транзакции, это чисто валидация:
1. Авторизация: nonce + `Capability::EnrollStudent`
2. Загрузить и валидировать заявку: должна быть в статусе `ready_for_review`
3. Расшифровать `student_data` и `parent_data` через `PiiCryptoService::decrypt()`
4. Записать в `PiiAccessLogRepository`: actor, fields=student_data/parent_data, reason=enrollment
5. Вычислить хэши документов: `$studentDocHash = $crypto->hash($studentData['doc_number'])`
6. Поискать существующих persons по хэшу документа ученика и родителя
7. Если родитель новый — проверить через `UserManager::findByEmail()` нет ли конфликта email с другим WP-юзером; если есть — бросить DomainException с понятным сообщением
8. Если ученик уже есть в системе — проверить через `EnrollmentRepository::existsActive()` нет ли двойного зачисления; если есть — бросить DomainException

---

**Реализовать `EnrollmentService::enroll` — транзакция**

Вторая часть метода после pre-flight. Оборачивается в `TransactionRunner::inTransaction()`.

Внутри транзакции:
1. Создать или найти person ученика: `PersonService::createOrFindBy($studentData)` → `$studentPersonId`
2. Создать или найти person родителя → `$guardianPersonId`
3. Создать связь: `RelationshipService::addRepresentative($guardianPersonId, $studentPersonId, ...)`
4. Создать enrollment: INSERT в `fs_lms_enrollments` с зашифрованным `snapshot_enc` (содержит полный слепок всех данных на момент зачисления — нужен для аудита если данные изменятся позже)
5. Привязать согласия: `ConsentService::bindToPersons($appId, [...])`
6. Записать audit log: `AuditAction::EnrollStudent`
7. Перевести application в статус `enrolling` (промежуточный — защита от двойного submit)

Возвращает `$enrollmentId` для следующего шага.

---

**Реализовать `EnrollmentService::enroll` — post-transaction эффекты**

Третья часть метода, выполняется после COMMIT. Не оборачивается в транзакцию.

1. Создать WP-юзера для ученика через `UserManager::create()` (если person без `wp_user_id`); привязать обратно через `PersonRepository::setWpUser()`
2. Создать WP-юзера для родителя аналогично
3. Перевести application в `converted`: `ApplicationRepository::markConverted()`
4. Сгенерировать password reset ссылки через `PasswordLinkService::generate()`
5. Если `sendEmailAuto = true` — отправить письма через `EmailService::sendPasswordSetup()`; иначе — включить ссылки в `EnrollmentResultDTO`
6. Сохранить `fs_lms_primary_enrollment_id` в usermeta ученика

Если на этом шаге что-то падает — НЕ откатывать транзакцию (данные уже зафиксированы). Application остаётся в `enrolling` — recovery job подберёт. Логировать ошибку. Вернуть EnrollmentResultDTO с флагом partial_failure.

---

**Создать `EnrollmentCallbacks::ajaxEnrollStudent`**

1. Nonce + `Capability::EnrollStudent`
2. Sanitize всех полей модалки
3. Собрать `EnrollmentInputDTO`
4. Вызвать `EnrollmentService::enroll()`
5. При полном успехе:
   - Если email-авторежим — toast "Зачисление выполнено, ссылки отправлены на email"
   - Если ручной режим — показать модалку со ссылками + кнопки "Скопировать"; **пароль не показывать**
6. При partial_failure — показать: "Зачисление выполнено. Учётные записи будут созданы автоматически в течение 15 минут. Enrollment ID: N."
7. При ошибке — ответ через `AjaxResponse::error()` с понятным сообщением

---

## Создание учётных записей и пароли

---

**Реализовать `UserManager`**

Создать класс `UserManager` в `inc/Managers/`.

Методы:
- `create(array $data): int` — обёртка над `wp_insert_user()`; если возвращает `WP_Error` — бросает исключение с сообщением
- `update(int $id, array $data): bool` — `wp_update_user()`
- `find(int $id): ?WP_User` — `get_user_by('id', $id)`, null если не найден
- `findByEmail(string $email): ?WP_User`
- `findByLogin(string $login): ?WP_User`
- `exists(int $id): bool`
- `setRole(int $id, string $role): void` — полная замена роли
- `addRole(int $id, string $role): void`
- `removeRole(int $id, string $role): void`
- `randomizePassword(int $id): void` — генерирует и устанавливает случайный 64-символьный пароль; используется при блокировке аккаунта после удаления ПД
- `setPersonId(int $userId, int $personId): void` — `update_user_meta($userId, 'fs_lms_person_id', $personId)`
- `getPersonId(int $userId): ?int` — `get_user_meta()`
- `setStatus(int $userId, string $status): void` — usermeta `fs_lms_user_status`

---

**Брендировать страницу установки пароля**

Стандартная страница `wp-login.php?action=rp` должна выглядеть в стиле FS LMS.

Реализовать через фильтр `login_headerurl` и `login_headertitle`, хук `login_enqueue_scripts` для подключения стилей и хук `login_form_rp` для добавления контента.

На странице:
- Логотип школы
- Текст "Установка пароля для входа в личный кабинет FS LMS"
- Поле "Логин" — предзаполнено, только для чтения
- Два поля пароля (стандартные WP)
- Индикатор силы пароля (стандартный WP или кастомный)

---

**Добавить требования к паролю**

Зарегистрировать хук `validate_password_reset`:
1. Минимум 12 символов
2. Содержит хотя бы одну цифру
3. Содержит хотя бы одну букву

При нарушении — добавить ошибку в `WP_Error` с понятным текстом на русском.

Клиентская проверка: реализовать JS-валидацию при вводе, показывать ошибки в реальном времени без сабмита.

---

**Увеличить TTL ссылки установки пароля до 48 часов**

Зарегистрировать фильтр `password_reset_expiration`:

Если у пользователя есть одна из LMS-ролей (`lms_student`, `lms_parent`) — возвращать `48 * HOUR_IN_SECONDS`. Для остальных — оставить стандартное значение.

---

**Реализовать регенерацию ссылки в карточке пользователя**

На странице редактирования WP-юзера в админке добавить секцию "FS LMS" с кнопкой "Сгенерировать новую ссылку для установки пароля".

По клику:
1. AJAX-запрос (можно через существующий паттерн callbacks)
2. `PasswordLinkService::generate(userId)` — инвалидирует старую ссылку и создаёт новую
3. Показать новую ссылку с возможностью скопировать
4. Кнопка "Отправить на email"

**Пароль не показывать в любом виде.**

---

## Управление представителями

---

**Создать карточку Person в админке**

Создать страницу `?page=fs-lms-persons&id=N` как подменю "Люди" в FS LMS.

Отображает:
- Личные данные person с маскированными PII + кнопки "Показать"
- Вкладка "Представители" — таблица связей (исторические с `valid_to` и активные); кнопки "Добавить представителя", "Заменить"
- Вкладка "Зачисления" — таблица enrollments этого person
- Кнопка "Редактировать данные"
- Кнопка "Запросить удаление ПД"

---

**Реализовать AJAX "Добавить представителя"**

Модалка с формой (аналогична форме родителя в заявке).

Логика:
1. Nonce + `Capability::ManagePersons`
2. Поиск существующего родителя по хэшу паспорта
3. Если найден — использовать существующий person; WP-юзер уже есть → отправить уведомление без ссылки пароля
4. Если не найден — создать нового person + WP-юзера + сгенерировать password setup link
5. Создать relationship через `RelationshipService::addRepresentative()`
6. `EmailService::sendNewRepresentativeNotification()`
7. Audit log `AuditAction::CreateRelationship`

---

**Реализовать AJAX "Заменить представителя"**

Модалка выбора заменяемой связи + форма нового представителя.

Логика:
1. Nonce + `Capability::ManagePersons`
2. Вызвать `RelationshipService::replaceRepresentative(oldRelId, newGuardianPersonId, newType)` — метод сам: `terminate(old)` + `create(new)` транзакционно
3. Если новый родитель — создать WP-юзера + отправить ссылку
4. У старого родителя WP-юзер **остаётся**, но он потеряет доступ к данным этого ребёнка (т.к. `canRepresent()` будет возвращать false)
5. Audit log `AuditAction::ReplaceRelationship`

---

**Реализовать AJAX "Обновить данные Person"**

Форма редактирования данных person.

Логика:
1. Nonce + `Capability::ManagePersons`
2. Шифровать изменённые PII-поля перед сохранением
3. Обновить хэши для полей с дедупликацией (паспорт, ИНН, телефон, ФИО)
4. Через `AuditService::record()` написать какие поля изменились — **без значений**: `{"changed_fields": ["phone", "address"]}`

---

## Recovery и Retention

---

**Реализовать `RecoveryService::resolveStuckEnrollments`**

Создать класс `RecoveryService` в `inc/Services/`.

Метод `resolveStuckEnrollments(): int`:
1. Найти applications в статусе `enrolling` старше 5 минут через `ApplicationRepository::findStuckEnrolling(5)`
2. Для каждой:
   - Проверить есть ли enrollment по `source_application_id`
   - Если enrollment нет — транзакция упала; перевести application обратно в `ready_for_review`
   - Если enrollment есть — транзакция прошла, но post-effects не завершились; найти persons без `wp_user_id`, создать для них WP-юзеров через `UserManager`, привязать через `PersonRepository::setWpUser()`, перевести application в `converted`
3. Вернуть количество разрешённых случаев

Метод идемпотентен: повторный вызов не создаёт дублей.

---

**Зарегистрировать cron `fs_lms_recovery_tick`**

В `RecoveryController::register()`:
1. Зарегистрировать кастомный интервал `every_15_minutes` через `CronManager::addCustomInterval()`
2. Запланировать событие `fs_lms_recovery_tick` каждые 15 минут через `CronManager::schedule()`
3. Подписать callback `RecoveryCallbacks::cronRecoveryTick()` на хук
4. `cronRecoveryTick()` вызывает `RecoveryService::resolveStuckEnrollments()`

---

**Реализовать истечение стale заявок**

Добавить в `ApplicationService` метод `expireStale(): int`:
- Находит заявки в `pending_parent`/`ready_for_review` с `join_code_expires_at < NOW()`
- Переводит их в статус `expired`
- Пишет audit log для каждой
- Возвращает количество

Зарегистрировать cron `fs_lms_expire_applications` (ежедневно). Callback вызывает `expireStale()`.

---

**Реализовать `RetentionService` — обезличивание удалённых persons**

Создать класс `RetentionService` в `inc/Services/`.

Метод `anonymizeDeletedPersons(): int`:
1. Находит persons с `deleted_at` старше retention window (по умолчанию 30 дней; настраивается через wp_options)
2. Для каждого: `PersonRepository::anonymize($id)` — затирает все `*_enc` в NULL
3. Через `ConsentRepository` отзывает все активные согласия этого person
4. Через `UserManager` блокирует WP-юзера: снимает роль, рандомизирует пароль
5. Audit log
6. Возвращает количество обезличенных

---

**Реализовать `RetentionService` — очистка старых заявок и логов**

Добавить методы в `RetentionService`:
- `purgeExpiredApplications(): int` — DELETE заявок `rejected`/`expired` старше 6 месяцев
- `purgeOldAuditLogs(): int` — DELETE audit_log записей старше 3 лет
- `purgeOldPiiAccessLogs(): int` — DELETE pii_access_log записей старше 5 лет

Retention periods должны читаться из wp_options (не хардкодить) — чтобы юрист мог их скорректировать через настройки.

Зарегистрировать cron `fs_lms_retention_cleanup` (ежедневно, запускать ночью). Callback вызывает все три метода последовательно.

---

## Права субъекта ПД

---

**Реализовать `PiiExportService`**

Создать класс `PiiExportService` в `inc/Services/`.

Метод `buildExport(int $personId, int $actorId): string`:
1. Загрузить все данные по person: сам person, его relationships (и связанных persons), enrollments, consents
2. Расшифровать все `*_enc` поля через `PiiCryptoService::decrypt()`
3. Записать в `PiiAccessLogRepository`: reason=`gdpr_export`, fields=all
4. Собрать в единый JSON со структурой:
   ```json
   {
     "exported_at": "...",
     "person": {...},
     "relationships": [...],
     "enrollments": [...],
     "consents": [...]
   }
   ```
5. Вернуть JSON-строку

Метод `createDownloadLink(string $payload): string`:
1. Сохранить payload во временный файл в `uploads/lms-exports/` (директория с защитой от прямого доступа через `.htaccess`)
2. Создать transient с токеном → путь к файлу, TTL 1 час
3. Вернуть URL вида `/lms/pii-export/{token}`

---

**Реализовать скачивание экспорта**

Зарегистрировать маршрут `/lms/pii-export/{token}`.

Обработчик:
1. Найти файл по токену из transient
2. Если токен не найден/истёк — 404
3. Отдать файл с заголовками `Content-Disposition: attachment` и удалить transient + файл после отдачи (одноразово)

---

**Реализовать soft-delete по запросу субъекта ПД**

В `PersonCallbacks::ajaxRequestPiiDeletion()`:
1. Nonce + проверка прав (либо `ManagePersons` для админа, либо сам субъект — проверка ownership)
2. Показать модалку с предупреждением: "После удаления данных доступ в личный кабинет будет прекращён. Физическое удаление произойдёт через 30 дней — это время для отмены решения."
3. По подтверждению: `PersonService::softDelete($personId, $actorId)`
4. Audit log `AuditAction::PiiDeletionRequested`
5. Уведомить ответственного за ПД (email)

---

**Реализовать самостоятельный экспорт ПД из личного кабинета**

В личный кабинет LMS-пользователя добавить кнопку "Скачать мои данные".

По нажатию:
1. Определить `person_id` текущего юзера через usermeta `fs_lms_person_id`
2. Вызвать `PiiExportService::buildExport()` и `createDownloadLink()`
3. Отправить ссылку на email пользователя
4. Показать: "Ссылка для скачивания отправлена на ваш email. Ссылка действительна 1 час."

---

## Безопасность

---

**Убедиться в generic-ответах на публичных endpoint-ах**

Проверить все публичные endpoint-ы (`/lms/apply`, `/lms/join/{code}`) на отсутствие информационных утечек:
- Ошибки "не найдено" и "истекло" должны давать одинаковый ответ (generic 404/400)
- Текст ошибки не должен раскрывать существует ли код в БД
- В `$wpdb->last_error` и debug-логе причина логируется, в response — нет

---

**Защита от массового раскрытия PII**

В `RateLimitService::allowPiiReveal()` ограничить количество reveal-операций с одного user_id.

Дополнительно: если за час более 50 reveal-операций с одного user_id — отправить уведомление на email администратора с audit trail.

---

**Защита от работы плагина без HTTPS**

Добавить в activation hook и в admin_init проверку:
```php
if (!is_ssl() && !defined('WP_DEBUG')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>FS LMS: плагин работает без HTTPS. Это недопустимо при обработке персональных данных.</p></div>';
    });
}
```

Задокументировать в README: HTTPS — обязательное требование для эксплуатации плагина.

---

## Тестирование

---

**Unit-тесты `PiiCryptoService`**

Написать PHPUnit-тесты:
- Round-trip: `encrypt → decrypt` возвращает оригинальную строку
- Разные вызовы `encrypt` с одним текстом дают разные результаты (nonce)
- `decrypt` бросает на усечённом blob
- `decrypt` бросает при подмене одного байта
- `hash` детерминистичен: одинаковый вход → одинаковый хэш
- `hash` нормализует: пробелы по краям и разный регистр дают одинаковый хэш

---

**Unit-тесты репозиториев**

Написать тесты для каждого репозитория с тестовой БД:
- `create` → `find` возвращает созданную запись
- `update` → изменения сохраняются
- `softDelete` → запись остаётся в БД, `deleted_at` заполнен
- Уникальные ограничения: попытка дублирующего INSERT — корректная обработка
- Фильтры в `list` работают

---

**Интеграционный тест: полный happy path**

Описать и прогнать сценарий:
1. Ученик создаёт заявку → заявка в `pending_parent`, JOIN-ссылка получена
2. Родитель открывает ссылку, заполняет форму → статус `ready_for_review`
3. Админ открывает карточку → данные видны (маскированы)
4. Reveal паспорта → запись в pii_access_log
5. Зачисление → enrollment создан, application → `converted`
6. WP-юзера созданы для студента и родителя
7. Password setup ссылки сгенерированы

Проверить: все записи в нужных таблицах, статусы верны, PII зашифрованы в БД.

---

**Интеграционный тест: дубликат родителя**

Сценарий: родитель уже в системе (зачислен другой ребёнок), подаёт заявку на второго.

Ожидаемое поведение:
- Pre-flight находит существующего person по хэшу паспорта
- Новый person для родителя не создаётся
- Создаётся только новый relationship
- WP-юзер родителя не создаётся повторно
- Email-уведомление отправляется без ссылки пароля

---

**Интеграционный тест: recovery после падения**

Сценарий: смоделировать частичный сбой (транзакция прошла, WP-юзера не созданы, application в `enrolling`).

Ожидаемое поведение recovery:
- После запуска `RecoveryService::resolveStuckEnrollments()` — WP-юзера созданы
- Application → `converted`
- Повторный запуск recovery — ничего лишнего не создаёт (идемпотентность)

---

**Интеграционный тест: смена опекуна**

Сценарий: ученик есть, у него мать как активный представитель → заменить на отца.

Ожидаемое поведение:
- У старой связи `valid_to = today()`
- Новая связь активна с `valid_from = today()`
- `RelationshipService::canRepresent()` для старого родителя → false
- `RelationshipService::canRepresent()` для нового → true

---

**Интеграционный тест: удаление ПД**

Сценарий: soft delete → симуляция прохождения retention period → обезличивание.

Ожидаемое поведение:
- После soft delete: person есть, `deleted_at` заполнен, данные ещё доступны
- После `RetentionService::anonymizeDeletedPersons()` (с форсированной датой): все `*_enc` = NULL, WP-юзер заблокирован

---

**Создать manual QA checklist**

Написать документ `QA_CHECKLIST.md` с пошаговыми сценариями для ручного тестирования:

- [ ] Создание заявки (успех)
- [ ] Повторная заявка от того же email (ожидаемая ошибка)
- [ ] Открытие истёкшей JOIN-ссылки (generic 404)
- [ ] Открытие несуществующей JOIN-ссылки (generic 404)
- [ ] Заполнение формы родителя
- [ ] Просмотр заявки в админке, reveal PII
- [ ] Зачисление с email-авторежимом
- [ ] Зачисление с ручной доставкой ссылок
- [ ] Попытка двойного зачисления того же ученика (ожидаемая ошибка)
- [ ] Установка пароля по ссылке
- [ ] Установка пароля по истёкшей ссылке (ожидаемое сообщение)
- [ ] Регенерация ссылки админом
- [ ] Добавление второго родителя
- [ ] Замена опекуна
- [ ] Отзыв согласия
- [ ] Запрос удаления ПД

---

## Документация и развёртывание

---

**Обновить CLAUDE.md**

Добавить в архитектурный CLAUDE.md следующие разделы:

1. **Custom tables** — когда использовать вместо wp_options: растущий объём, нужны фильтры/транзакции/индексы
2. **PII-шифрование** — обязательный паттерн для чувствительных данных: `PiiCryptoService`, ключи в wp-config, чтение только через `PersonReader`
3. **Транзакции** — использовать `TransactionRunner` trait, `wp_insert_user` вне транзакции
4. **Audit log** — все действия через `AuditService`, PII-доступ через `PersonReader` (который логирует автоматически)

---

**Написать руководство по установке**

Создать `INSTALL.md`:
1. Требования: PHP 7.2+, MySQL/MariaDB InnoDB, HTTPS обязателен
2. Установка плагина
3. Генерация ключей шифрования и добавление в wp-config
4. Настройка WP-cron (системный cron вместо псевдо-cron для надёжности)
5. Настройка капчи
6. Первая активация и проверка миграций
7. Проверочный чеклист после установки

---

**Написать руководство администратора**

Создать `ADMIN_GUIDE.md` со скриншотами:
1. Как выглядит входящая заявка
2. Как просматривать данные (маски, reveal)
3. Как зачислить (шаги модалки)
4. Как отклонить
5. Как добавить второго родителя
6. Как заменить опекуна
7. Как обработать запрос на удаление ПД
8. Что делать если зачисление "зависло" (stuck enrolling)

---

**Написать FAQ для родителей**

Создать короткий документ или страницу на сайте:
- Что такое JOIN-ссылка и зачем её передавать родителю
- Что делать если ссылка истекла
- Как установить пароль
- Куда обращаться если письмо не пришло
- Как отозвать согласие на обработку данных
- Как запросить копию своих данных

