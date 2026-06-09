# FS LMS — Задачи: снимок student_records + каскадное удаление + возврат из архива

---

## Архитектурная модель каскадных удалений

Каскад реализуется через **цепочку событий**: каждый обработчик не знает о существовании следующего уровня — он только собирает данные, выполняет свою работу и диспатчит следующее событие. Проверки «есть ли ещё записи» вынесены в отдельный сервис `DeletionPredicates` для переиспользования.

```
DeleteSubject → [foreach group] → DeleteGroup
DeletePeriod  → [foreach group] → DeleteGroup
DeleteGroup   → удаляет student_records для группы
                → [foreach student_id]  → StudentRecordsRemoved
                → [foreach parent_id]   → ParentRecordsRemoved
                → удаляет саму группу

StudentRecordsRemoved + предикат hasNoRecords(student) → DeleteStudent
ParentRecordsRemoved  + предикат hasNoRecords(parent)  → DeleteParent
```

---

## Фаза 0 — Инфраструктура событий  ✅

---

**Создать `DeletionEventDispatcher`**

Создать файл `inc/Services/Deletion/DeletionEventDispatcher.php`.

```php
interface DeletionEventInterface {}

readonly class DeletionEventDispatcher {
    private array $listeners = [];

    public function listen( string $eventClass, callable $handler ): void {
        $this->listeners[ $eventClass ][] = $handler;
    }

    public function dispatch( DeletionEventInterface $event ): void {
        foreach ( $this->listeners[ $event::class ] ?? [] as $handler ) {
            $handler( $event );
        }
    }
}
```

Зарегистрировать как singleton в `Inc\Core\Container` (или в `Init::getServices()`).

---

**Создать event-классы удаления**

Создать файл `inc/Services/Deletion/DeletionEvents.php` (все в одном файле):

```php
readonly class DeleteSubjectEvent implements DeletionEventInterface {
    public function __construct( public string $subjectKey, public int $actorId ) {}
}

readonly class DeletePeriodEvent implements DeletionEventInterface {
    public function __construct( public string $periodId, public int $actorId ) {}
}

readonly class DeleteGroupEvent implements DeletionEventInterface {
    public function __construct( public int $groupId, public int $actorId ) {}
}

// Промежуточное событие: student_records для группы удалены, нужно проверить осиротел ли ученик
readonly class StudentRecordsRemovedFromGroupEvent implements DeletionEventInterface {
    public function __construct( public int $studentPersonId, public int $actorId ) {}
}

// Промежуточное событие: аналогично для родителя
readonly class ParentRecordsRemovedFromGroupEvent implements DeletionEventInterface {
    public function __construct( public int $parentPersonId, public int $actorId ) {}
}

readonly class DeleteStudentEvent implements DeletionEventInterface {
    public function __construct( public int $studentPersonId, public int $actorId ) {}
}

readonly class DeleteParentEvent implements DeletionEventInterface {
    public function __construct( public int $parentPersonId, public int $actorId ) {}
}
```

---

**Создать `DeletionPredicates`**

Создать файл `inc/Services/Deletion/DeletionPredicates.php`.

Единственная обязанность — «есть ли ещё что-то у кого-то» после частичного удаления.

```php
readonly class DeletionPredicates {

    public function __construct(
        private StudentRecordRepository $studentRecords,
    ) {}

    /** true если у ученика не осталось ни одной записи в student_records */
    public function studentHasNoRemainingRecords( int $studentPersonId ): bool {
        return ! $this->studentRecords->hasAnyRecord( $studentPersonId );
    }

    /** true если у родителя не осталось ни одной записи в student_records */
    public function parentHasNoRemainingRecords( int $parentPersonId ): bool {
        return 0 === count( $this->studentRecords->findAllByParent( $parentPersonId ) );
    }
}
```

---

## Фаза 1 — Снимок данных в student_records  ✅

---

**Добавить столбцы снимка в таблицу student_records (миграция)**

В `Migration_1_0_0::up()` в DDL таблицы `student_records` добавить после `group_id` (не вместо `student_person_id` — он остаётся для каскадных запросов):

```sql
snapshot_last_name   varchar(100) NOT NULL DEFAULT '',
snapshot_first_name  varchar(100) NOT NULL DEFAULT '',
snapshot_middle_name varchar(100) DEFAULT NULL,
snapshot_school      varchar(255) DEFAULT NULL,
snapshot_grade       varchar(10)  DEFAULT NULL,
```

В секцию "Cleanup" того же файла добавить:
```php
$wpdb->query( "ALTER TABLE `{$student_records}` 
    ADD COLUMN IF NOT EXISTS `snapshot_last_name`   varchar(100) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS `snapshot_first_name`  varchar(100) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS `snapshot_middle_name` varchar(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `snapshot_school`      varchar(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `snapshot_grade`       varchar(10)  DEFAULT NULL"
);
```

Сбросить версию схемы командой:
```
docker exec wp_db mariadb -u root -proot wordpress -e "UPDATE wp_options SET option_value='0.0.0' WHERE option_name='fs_lms_schema_version';"
```

---

**Обновить `StudentRecordDTO` — добавить поля снимка**

В `inc/DTO/StudentRecordDTO.php` добавить в конструктор:

```php
public string  $snapshotLastName,
public string  $snapshotFirstName,
public ?string $snapshotMiddleName,
public ?string $snapshotSchool,
public ?string $snapshotGrade,
```

В `fromArray()` добавить маппинг:

```php
snapshotLastName:   (string) ( $data['snapshot_last_name']   ?? '' ),
snapshotFirstName:  (string) ( $data['snapshot_first_name']  ?? '' ),
snapshotMiddleName: isset( $data['snapshot_middle_name'] ) ? (string) $data['snapshot_middle_name'] : null,
snapshotSchool:     isset( $data['snapshot_school'] )      ? (string) $data['snapshot_school']      : null,
snapshotGrade:      isset( $data['snapshot_grade'] )       ? (string) $data['snapshot_grade']       : null,
```

---

**Захватывать снимок при зачислении в `EnrollmentService::enroll()`**

В блоке `$this->studentRecordRepository->create(...)` добавить:

```php
'snapshot_last_name'   => $studentDto->lastName,
'snapshot_first_name'  => $studentDto->firstName,
'snapshot_middle_name' => $studentDto->middleName ?: null,
'snapshot_school'      => $studentDto->school      ?: null,
'snapshot_grade'       => (string) $studentDto->grade ?: null,
```

Снимок берётся из `$studentDto` (данные на момент зачисления), не из persons-таблицы.

---

**Использовать снимок в `EnrollmentService::restoreFromArchive()`**

В методе `restoreFromArchive()` данные ученика брать из снимка записи:

```php
$studentData = [
    'last_name'   => $record->snapshotLastName,
    'first_name'  => $record->snapshotFirstName,
    'middle_name' => $record->snapshotMiddleName ?? '',
    'birth_date'  => $studentPerson->birthDate ?? '',  // дата рождения — из persons (не меняется)
    'email'       => '',                                // заполняется из person_documents ниже
    'school'      => $record->snapshotSchool  ?? '',
    'grade'       => $record->snapshotGrade   ?? '',
];
```

---

## Фаза 2 — Репозиторные методы для каскада  ✅

---

**Добавить методы в `StudentRecordRepository`**

```php
/** Все записи ученика (любой статус, любая группа) */
public function findAllByStudent( int $studentPersonId ): array

/** Все записи родителя (любой статус) */
public function findAllByParent( int $parentPersonId ): array

/** Все записи группы (любой статус) */
public function findAllByGroup( int $groupId ): array

/** true если у ученика есть хотя бы одна запись (любой статус) */
public function hasAnyRecord( int $studentPersonId ): bool {
    return 0 < (int) $this->wpdb->get_var(
        $this->wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE student_person_id = %d',
            $this->table, $studentPersonId
        )
    );
}

/**
 * Удаляет все записи группы и возвращает коллекции затронутых person IDs.
 * @return array{ students: int[], parents: int[] }
 */
public function deleteAllByGroupAndCollect( int $groupId ): array {
    $rows = $this->findAllByGroup( $groupId );
    $studentIds = array_unique( array_column( $rows, 'student_person_id' ) );
    $parentIds  = array_unique( array_column( $rows, 'parent_person_id' ) );

    $this->wpdb->query(
        $this->wpdb->prepare( 'DELETE FROM %i WHERE group_id = %d', $this->table, $groupId )
    );

    return [ 'students' => $studentIds, 'parents' => $parentIds ];
}
```

---

**Добавить методы в `GroupsRepository`**

```php
/** Физически удаляет группу */
public function hardDelete( int $id ): bool {
    return false !== $this->wpdb->delete( $this->table, [ 'id' => $id ] );
}

/** Все группы периода */
public function findByPeriodId( string $periodId ): array {
    return $this->wpdb->get_results(
        $this->wpdb->prepare(
            'SELECT * FROM %i WHERE academic_period_id = %s',
            $this->table, $periodId
        )
    ) ?: [];
}

/** Количество уникальных учеников в группе (для UI-предупреждения) */
public function countUniqueStudents( int $groupId ): int {
    return (int) $this->wpdb->get_var(
        $this->wpdb->prepare(
            'SELECT COUNT(DISTINCT student_person_id) FROM %i WHERE group_id = %d',
            TableName::StudentRecords->prefixed(), $groupId
        )
    );
}
```

---

**Добавить методы в `PersonRepository` и `PersonDocumentsRepository`**

```php
// PersonRepository
public function hardDelete( int $personId ): bool {
    return false !== $this->wpdb->delete( $this->table, [ 'id' => $personId ] );
}

// PersonDocumentsRepository
public function hardDeleteByPersonId( int $personId ): bool {
    return false !== $this->wpdb->delete( $this->table, [ 'person_id' => $personId ] );
}
```

---

**Добавить метод в `ConsentRepository`**

```php
/** Физически удаляет все согласия person (как субъект и как подписант) */
public function hardDeleteByPersonId( int $personId ): void {
    $this->wpdb->query(
        $this->wpdb->prepare( 'DELETE FROM %i WHERE person_id = %d OR signed_for_person_id = %d',
            $this->table, $personId, $personId )
    );
}
```

---

**Добавить метод в `ApplicationRepository`**

```php
/**
 * Физически удаляет заявки ученика (safety net для зависших enrolling-заявок).
 * После успешного зачисления заявки уже удалены через forceDelete(), 
 * этот метод нужен только при каскадном удалении тестовых данных.
 */
public function hardDeleteByStudentPersonId( int $personId ): void {
    $this->wpdb->query(
        $this->wpdb->prepare( 'DELETE FROM %i WHERE student_person_id = %d', $this->table, $personId )
    );
}

public function hardDeleteByParentPersonId( int $personId ): void {
    $this->wpdb->query(
        $this->wpdb->prepare( 'DELETE FROM %i WHERE parent_person_id = %d', $this->table, $personId )
    );
}
```

---

## Фаза 3 — Обработчики событий удаления ✅

---

**Создать `StudentDeletionHandler`**

Файл `inc/Services/Deletion/StudentDeletionHandler.php`.

Слушает: `DeleteStudentEvent`.

```php
readonly class StudentDeletionHandler {
    use TransactionRunner;

    public function __construct(
        private PersonRepository          $personRepository,
        private PersonDocumentsRepository $personDocumentsRepository,
        private StudentRecordRepository   $studentRecordRepository,
        private ConsentRepository         $consentRepository,
        private ApplicationRepository     $applicationRepository,
        private UserManager               $userManager,
        private AuditService              $auditService,
    ) {}

    public function handle( DeleteStudentEvent $event ): void {
        $person = $this->personRepository->find( $event->studentPersonId );
        $wpUserId = $person?->wpUserId;

        $this->inTransaction( function () use ( $event ) {
            $this->consentRepository->hardDeleteByPersonId( $event->studentPersonId );
            $this->applicationRepository->hardDeleteByStudentPersonId( $event->studentPersonId );
            $this->studentRecordRepository->deleteAllByStudent( $event->studentPersonId );
            $this->personDocumentsRepository->hardDeleteByPersonId( $event->studentPersonId );
            $this->personRepository->hardDelete( $event->studentPersonId );
        } );

        // После транзакции — wp_delete_user не транзакционный
        if ( null !== $wpUserId ) {
            wp_delete_user( $wpUserId );
        }

        $this->auditService->record(
            AuditAction::HardDeletePerson->value, 'student', $event->studentPersonId,
            [ 'actor_id' => $event->actorId ]
        );
    }
}
```

Метод `deleteAllByStudent` добавить в `StudentRecordRepository` (удаляет все записи ученика без коллекции ID).

---

**Создать `ParentDeletionHandler`**

Файл `inc/Services/Deletion/ParentDeletionHandler.php`.

Слушает: `DeleteParentEvent`. Аналогичен `StudentDeletionHandler`, но вместо `deleteAllByStudent` — ничего (student_records уже удалены выше по цепочке).

```php
public function handle( DeleteParentEvent $event ): void {
    $person = $this->personRepository->find( $event->parentPersonId );
    $wpUserId = $person?->wpUserId;

    $this->inTransaction( function () use ( $event ) {
        $this->consentRepository->hardDeleteByPersonId( $event->parentPersonId );
        $this->applicationRepository->hardDeleteByParentPersonId( $event->parentPersonId );
        $this->personDocumentsRepository->hardDeleteByPersonId( $event->parentPersonId );
        $this->personRepository->hardDelete( $event->parentPersonId );
    } );

    if ( null !== $wpUserId ) {
        wp_delete_user( $wpUserId );
    }

    $this->auditService->record(
        AuditAction::HardDeletePerson->value, 'parent', $event->parentPersonId,
        [ 'actor_id' => $event->actorId ]
    );
}
```

---

**Создать `StudentOrphanCheckHandler`**

Файл `inc/Services/Deletion/StudentOrphanCheckHandler.php`.

Слушает: `StudentRecordsRemovedFromGroupEvent`. Проверяет предикат и диспатчит `DeleteStudentEvent` если нужно.

```php
readonly class StudentOrphanCheckHandler {

    public function __construct(
        private DeletionPredicates    $predicates,
        private DeletionEventDispatcher $dispatcher,
    ) {}

    public function handle( StudentRecordsRemovedFromGroupEvent $event ): void {
        if ( $this->predicates->studentHasNoRemainingRecords( $event->studentPersonId ) ) {
            $this->dispatcher->dispatch( new DeleteStudentEvent( $event->studentPersonId, $event->actorId ) );
        }
    }
}
```

---

**Создать `ParentOrphanCheckHandler`**

Аналогично `StudentOrphanCheckHandler`, слушает `ParentRecordsRemovedFromGroupEvent`:

```php
public function handle( ParentRecordsRemovedFromGroupEvent $event ): void {
    if ( $this->predicates->parentHasNoRemainingRecords( $event->parentPersonId ) ) {
        $this->dispatcher->dispatch( new DeleteParentEvent( $event->parentPersonId, $event->actorId ) );
    }
}
```

---

**Создать `GroupDeletionHandler`**

Файл `inc/Services/Deletion/GroupDeletionHandler.php`.

Слушает: `DeleteGroupEvent`.

```php
readonly class GroupDeletionHandler {

    public function __construct(
        private StudentRecordRepository $studentRecordRepository,
        private GroupsRepository        $groupsRepository,
        private DeletionEventDispatcher $dispatcher,
    ) {}

    public function handle( DeleteGroupEvent $event ): void {
        // 1. Удалить student_records, собрать затронутые IDs
        [ 'students' => $studentIds, 'parents' => $parentIds ] =
            $this->studentRecordRepository->deleteAllByGroupAndCollect( $event->groupId );

        // 2. Удалить саму группу
        $this->groupsRepository->hardDelete( $event->groupId );

        // 3. Диспатчить промежуточные события (проверки происходят в отдельных обработчиках)
        foreach ( $studentIds as $studentId ) {
            $this->dispatcher->dispatch(
                new StudentRecordsRemovedFromGroupEvent( (int) $studentId, $event->actorId )
            );
        }
        foreach ( $parentIds as $parentId ) {
            $this->dispatcher->dispatch(
                new ParentRecordsRemovedFromGroupEvent( (int) $parentId, $event->actorId )
            );
        }
    }
}
```

---

**Создать `SubjectDeletionCascadeHandler`**

Файл `inc/Services/Deletion/SubjectDeletionCascadeHandler.php`.

Слушает: `DeleteSubjectEvent`. Диспатчит `DeleteGroupEvent` для каждой группы предмета, затем делегирует в существующий `SubjectDeletionService` для удаления WP-контента.

```php
readonly class SubjectDeletionCascadeHandler {

    public function __construct(
        private GroupsRepository        $groupsRepository,
        private SubjectDeletionService  $wpContentDeletion,
        private DeletionEventDispatcher $dispatcher,
    ) {}

    public function handle( DeleteSubjectEvent $event ): void {
        // Сначала каскадно удалить группы (через события)
        $groups = $this->groupsRepository->findBySubjectKey( $event->subjectKey );
        foreach ( $groups as $group ) {
            $this->dispatcher->dispatch( new DeleteGroupEvent( (int) $group->id, $event->actorId ) );
        }

        // Затем удалить WP-контент (посты, таксономии, настройки)
        // Сигнатуру метода расширить параметром actorId
        $this->wpContentDeletion->deleteWithCascade( $event->subjectKey );
    }
}
```

---

**Создать `PeriodDeletionCascadeHandler`**

Файл `inc/Services/Deletion/PeriodDeletionCascadeHandler.php`.

Слушает: `DeletePeriodEvent`.

```php
readonly class PeriodDeletionCascadeHandler {

    public function __construct(
        private GroupsRepository           $groupsRepository,
        private AcademicPeriodRepository   $periodRepository,
        private DeletionEventDispatcher    $dispatcher,
    ) {}

    public function handle( DeletePeriodEvent $event ): void {
        $groups = $this->groupsRepository->findByPeriodId( $event->periodId );
        foreach ( $groups as $group ) {
            $this->dispatcher->dispatch( new DeleteGroupEvent( (int) $group->id, $event->actorId ) );
        }
        $this->periodRepository->delete( $event->periodId );
    }
}
```

---

## Фаза 4 — Регистрация обработчиков ✅

---

**Зарегистрировать слушателей в `DeletionController` (новый контроллер)**

Создать `inc/Controllers/DeletionController.php`. Реализует `ServiceInterface`.

В методе `register()` через DI-контейнер разрешить все обработчики и подписать их на диспетчер:

```php
public function register(): void {
    $bus = $this->container->get( DeletionEventDispatcher::class );

    $bus->listen( DeleteGroupEvent::class,
        [ $this->container->get( GroupDeletionHandler::class ), 'handle' ] );

    $bus->listen( StudentRecordsRemovedFromGroupEvent::class,
        [ $this->container->get( StudentOrphanCheckHandler::class ), 'handle' ] );

    $bus->listen( ParentRecordsRemovedFromGroupEvent::class,
        [ $this->container->get( ParentOrphanCheckHandler::class ), 'handle' ] );

    $bus->listen( DeleteStudentEvent::class,
        [ $this->container->get( StudentDeletionHandler::class ), 'handle' ] );

    $bus->listen( DeleteParentEvent::class,
        [ $this->container->get( ParentDeletionHandler::class ), 'handle' ] );

    $bus->listen( DeleteSubjectEvent::class,
        [ $this->container->get( SubjectDeletionCascadeHandler::class ), 'handle' ] );

    $bus->listen( DeletePeriodEvent::class,
        [ $this->container->get( PeriodDeletionCascadeHandler::class ), 'handle' ] );
}
```

Добавить `DeletionController` в `Init::getServices()`.

---

## Фаза 5 — AJAX-хуки для операций удаления ✅

---

**Добавить в `AjaxHook` enum**

```php
case CheckGroupDeletion  = 'CheckGroupDeletion';
case DeleteGroup         = 'DeleteGroup';
case CheckSubjectDeletion = 'CheckSubjectDeletion';
case DeleteSubject       = 'DeleteSubject';        // уже может существовать — проверить
case CheckPeriodDeletion = 'CheckPeriodDeletion';
case DeletePeriod        = 'DeletePeriod';
case HardDeleteStudent   = 'HardDeleteStudent';
```

**Добавить в `Nonce` enum**

```php
case DeleteGroup
case DeleteSubject   // если нет
case DeletePeriod
case HardDeleteStudent
```

---

**Реализовать Callbacks**

В `StudentGroupCallbacks` (или новом `DeletionCallbacks`):

`ajaxCheckGroupDeletion()`:
1. `authorize( Nonce::DeleteGroup, Capability::Admin )`
2. Вернуть `['student_count' => $groupsRepository->countUniqueStudents($groupId)]`

`ajaxDeleteGroup()`:
1. `authorize( Nonce::DeleteGroup, Capability::Admin )`
2. `$dispatcher->dispatch( new DeleteGroupEvent($groupId, get_current_user_id()) )`
3. `$this->success()`

`ajaxCheckSubjectDeletion()`:
1. Найти все группы предмета, суммировать `countUniqueStudents`
2. Вернуть `['student_count' => N, 'group_count' => M]`

`ajaxDeleteSubject()`:
1. `authorize( Nonce::DeleteSubject, Capability::Admin )`
2. `$dispatcher->dispatch( new DeleteSubjectEvent($subjectKey, get_current_user_id()) )`

`ajaxCheckPeriodDeletion()` / `ajaxDeletePeriod()` — аналогично.

`ajaxHardDeleteStudent()`:
1. `authorize( Nonce::HardDeleteStudent, Capability::Admin )`
2. `$dispatcher->dispatch( new DeleteStudentEvent($studentPersonId, get_current_user_id()) )`

---

**Добавить нонсы в `Enqueue.php`**

В `fs_lms_vars` добавить:

```php
'nonces' => [
    ...
    'deleteGroup'       => Nonce::DeleteGroup->create(),
    'deleteSubject'     => Nonce::DeleteSubject->create(),
    'deletePeriod'      => Nonce::DeletePeriod->create(),
    'hardDeleteStudent' => Nonce::HardDeleteStudent->create(),
]
```

---

## Фаза 6 — JS: модалки подтверждения ✅

**Общий принцип для всех операций удаления:**
1. Клик "Удалить X" → AJAX `checkXDeletion` → получить `student_count`.
2. Если `student_count === 0` → сразу AJAX `deleteX` без модалки.
3. Если `student_count > 0` → показать модалку с предупреждением:
   - Для группы: «В группе {N} учеников: {список учеников}. Ученики без других зачислений будут удалены полностью. Продолжить?»
   - Для предмета: «Предмет содержит {M} групп и {N} учеников. Все группы и ученики без других зачислений будут удалены. Продолжить?»
   - Для периода: аналогично предмету.
4. Подтверждение → AJAX `deleteX` → обновить UI.

**Для "Удалить ученика" (жёсткое удаление):**
Всегда показывать модалку: «Ученик и все связанные данные (зачисления, документы, учётная запись) будут удалены безвозвратно. Продолжить?»

---

## Фаза 7 — Возврат из архива с опцией родителя ✅

---
### Сделать функционал таба Архив
Выглядит по шаблону таба "Заявки" таблица с фильтрацией по статусам отчисления (enum EnrollmentStatus): 
1. Обучается (сюда попадают все пользователи после зачисления и ДО отчисления), 
2. Завершено (отчислен со статусом End из enum ExpulsionReasons), 
3. Переведён (отчислен со статусом Transfer из enum ExpulsionReasons), 
4. Отчислен (оставшиеся два статуса ExpulsionReasons)

У всех есть действие: `Вернуть в заявки` и `Просмотреть` (открывается модалка в виде аккордеона со всеми имеющимися данными: Ученика, Родителя, О зачислении и об отчислении если есть. Есть кнопка для экспорта) + массовое действие `Экспортировать`


**Разделить `restoreFromArchive()` на два режима**:
появляется модальное окно с выбором: **Вернуть в заявки только с данными ученика** `with_parent: 0`(появляется заполненная заявка со статусом pending_parent), **Вернуть в заявки с данными родителя** `with_parent: 1` (появляется заполненная заявка со статусом ready_for_review). 
Выбор через option, две кнопки: Вернуть, Отмена. В стиле confirm_modal

После успеха: показать join-ссылки и (если with_parent) имя родителя. В блоке с join-ссылкой по-прежнему можно назначать родителя из существующих. Даже при восстановлении без данных родителя, всё равно можно выбрать этого же родителя. Такое поведение нужно для формирования join-ссылки в которой зафиксированы прежние данные родителя, но есть возможность поменять/дописать данные ИНН и документа ученика.

В `EnrollmentService::restoreFromArchive()` добавить параметр:

```php
public function restoreFromArchive( int $recordId, bool $withParent = false ): array
```

Если `withParent = true` (Вернуть в заявки с данными родителя):
- Проверить, что `$record->parentPersonId` не null (иначе `InvalidArgumentException`)
- После создания заявки вызвать `$this->selectExistingParent( $appId, $record->parentPersonId )`
- Вернуть `['id' => $appId, 'join_url' => ..., 'parent_name' => ...]`

Если `withParent = false` (Вернуть в заявки только с данными ученика) — текущее поведение без изменений.

---

**Обновить AJAX-callback восстановления из архива**

В соответствующем Callbacks добавить:

```php
$withParent = (bool) $this->sanitizeInt( $_POST['with_parent'] ?? 0 );
$result = $this->enrollmentService->restoreFromArchive( $recordId, $withParent );
$this->success( $result );
```

---

## Новые AuditAction кейсы

В `inc/Enums/AuditAction.php` добавить:

```php
case HardDeletePerson  = 'hard_delete_person';
case HardDeleteGroup   = 'hard_delete_group';
case HardDeleteSubject = 'hard_delete_subject';
case HardDeletePeriod  = 'hard_delete_period';
case RestoreFromArchive = 'restore_from_archive';
```

В `GroupDeletionHandler`, `SubjectDeletionCascadeHandler`, `PeriodDeletionCascadeHandler` — добавить запись через `AuditService` после выполнения.

---

## Порядок реализации

1. **Фаза 0** — инфраструктура событий (Dispatcher + Events + Predicates)
2. **Фаза 1** — снимок данных (миграция → DTO → захват при зачислении → restoreFromArchive)
3. **Фаза 2** — репозиторные методы (всё вместе, это просто SQL)
4. **Фаза 3** — обработчики в порядке: Student → Parent → StudentOrphanCheck → ParentOrphanCheck → Group → Subject → Period
5. **Фаза 4** — регистрация в DeletionController
6. **Фаза 5** — AJAX-хуки и Callbacks
7. **Фаза 6** — JS модалки
8. **Фаза 7** — возврат из архива (параллельно с 5–6)

---

## Доработка

1. Таб Ученики

В таблице Ученики должна быть только 1 запись для ученика. Все остальные данные записываются в тех же колонках, но с новой строки (с символом переноса строки)
Это может произойти только если ученик записан на разные предметы. Следовательно номера договоров тоже будут разные. 

Нужно поправить и отображение в модалке student-person-modal: под каждый номер договора своя строка:

|Номер договора | Предмет | Группа | Расписание |
| --- | --- | --- | --- |

К аналогичному виду привести таблицу с Преподавателями (группировка групп по предмету, т.е. сначала предмет, потом в колонке Группы все группы этого предмета)

| ФИО                  | Предметы              | Группы                | 
|----------------------|-----------------------|-----------------------| 
| Иванов Иван Иванович | Русский\n\nМатематика | Русс-1\nРусс-2\nМат-1 | 

Поле группой можно редактировать (вместе с остальными полями по нажатию на кнопку Редактировать"). Группы выбираются только исходя из выбранного предмета (не редактируется). В след за группой подтягивается и расписание этой группы.

2. Отчисление

Нужно при отчислении ученика выбирать из какой именно группы его отчислить. Так, если он зачислен сразу в 2 группы, отчисление должно быть только из одной (1 отчисление = 1 дополнение записи в student_records)

При отчислении у ученика в Таблице "Ученики" просто убирается эта группа из столбца “Группа” и её расписание из “Расписание” при необходимости и предмет из столбца “Предмет”.

Если у ученика не осталось групп (отчислен из всех) - он пропадает из этой таблицы на табе Ученики.

Если у родителя не осталось зачисленных детей (их больше нет на табе Ученики) - то родитель пропадает из таблицы на табе Родители

Изменить поле deleted_at в wp_fs_lms_persons. Оно теперь не выполняет свою роль. Нужна иная проверка, что у ученика не осталось больше активных групп (из которых он не отчислен), после чего его можно удалить из таблицы Ученики.

Нужно адаптировать сюда поведение -OrphanCheckHandler. Или вынести в отдельный сервис логику проверки "у сущности не осталось больше связанных с ней записей" (например, у ученика не осталось больше групп, из которых он не отчислен)

3. Проверить трансфер данных

Периодически пропадают данные из таблиц или модальных окон. 

Проверить целостность передаваемых данных и соответствие полям таблицы/формы/модального окна. 

Проверить что все данные передаются ТОЛЬКО в DTO.

Даже если для отображения нужны не все данные, все равно подгружать весь объект DTO. 

Данные между базой и частями программы передаются единым объектом DTO (студента, родителя, зачисления и другие. Лишние и узконаправленные не создавать. Даже если нужны не все поля DTO - всё равно передавать весь объект). 

Привести все id полей к единому виду и получать данные только из DTO. Унифицировать и упростить передачу аргументов, чтобы в будущем было проще добавлять какое-либо новое значение

4. Рефактор JS 

Для соблюдения DRY нужно вынести методы по типу маскирования телефона или данных паспорта в отдельные общие методы

5. Отображение телефона в -person-modal

В модальных окнах ученика и родителя телефон отображается по-разному: у ученика с маской, у родителя - без. Сделать везде отображение с маской.

6. Иконка у "Скопировать"
   
У сообщения “Скопировано” пропала иконка (перемести её вправо и сделай галочкой)

7. Row-actions

Из модалок -person-modal убрать кнопки Экспорт и Отчислить - Поставить их как действия в row-actions. Сделать их доступными для bulk-actions (только у Учеников).

Модальное окно application-enrollment-modal привести к виду student-person-modal по ширине и расположению полей (только существующих, никаких новых не добавлять)

8. Новая колонка в student_records

Нужно добавить информацию о том, КТО провёл зачисление (по аналогии expelled_by_user_id) - enrolled_by_user_id


---

## Безопасность

---

**Заблокировать сброс пароля через WP для LMS-ролей** ✅

Добавить в `UserController::register()` (или новый `PasswordController`):

```php
add_filter( 'allow_password_reset', [ $this, 'blockPasswordReset' ], 10, 2 );
```

```php
public function blockPasswordReset( bool $allow, int $userId ): bool {
    $user = get_userdata( $userId );
    foreach ( UserRole::lmsRoles() as $role ) {
        if ( in_array( $role->value, (array) $user->roles, true ) ) {
            return false;
        }
    }
    return $allow;
}
```

Добавить статический метод `UserRole::lmsRoles(): array` — возвращает `[FSStudent, FSParent, FSTeacher]`.

Политика: пароли для всех LMS-ролей управляются только через администратора. Стандартный WP-поток «Забыли пароль» отключён для этих ролей.

---

## CSV-экспорт

*в процессе написания документации*

---

## Логирование

*в процессе написания документации*

---

## Тесты

---

**Unit: `PiiCryptoService`**

- `encrypt → decrypt` возвращает оригинал
- два вызова `encrypt` с одним текстом → разные результаты (random nonce)
- `decrypt` на усечённом blob → `RuntimeException`
- `decrypt` при подмене байта → `RuntimeException`
- `hash` детерминистичен; `hash` нормализует пробелы и регистр

---

**Unit: `PiiMaskingService`**

- `mask(Pass)`: первые 4 + последние 4 символа, середина — `••`
- `mask(Inn)`: последние 4 цифры
- `mask(Phone)`: `+7 9•• ••• •• ••`
- `maskBulk`: массив обрабатывается корректно

---

**Unit: `EmailOtpService`**

- `verify` с правильным кодом → true, transient удалён
- `verify` с неверным кодом → false
- `verify` после истечения transient → false
- `canResend`: false пока cooldown активен, true после
- bypass-код `FS_LMS_OTP_BYPASS_CODE` → true

---

**Интеграционный: happy path**

1. Ученик создаёт заявку → `pending_parent`, JOIN-ссылка
2. Родитель заполняет форму → `ready_for_review`
3. Админ открывает карточку (данные маскированы)
4. Reveal паспорта → запись в `pii_access_log`
5. Зачисление → `student_records` active, application deleted
6. WP-юзеры созданы для ученика и родителя
7. Password setup ссылки сгенерированы

---

**Интеграционный: recovery после падения**

Смоделировать: транзакция прошла, WP-юзера не созданы, application в `enrolling`.
Ожидание: `RecoveryService::resolveStuckEnrollments()` создаёт юзеров, переводит в `converted`, идемпотентен.

---

## Документация

---

**Обновить CLAUDE.md**

Добавить секции:
- **Custom tables** — когда использовать вместо wp_options (растущий объём, фильтры, транзакции)
- **PII-шифрование** — encrypt при записи, читать только через `PersonReader`
- **Транзакции** — `TransactionRunner` trait; `wp_insert_user` вне транзакции
- **Audit log** — все действия через `AuditService`; PII-доступ через `PersonReader` (логирует автоматически)
- **OTP flow** — `SendOtpCode` → `CreateApplication`
- **Каскадные удаления** — через `DeletionEventDispatcher`; предикаты в `DeletionPredicates`

---

**`INSTALL.md`**

Требования, генерация ключей шифрования (`FS_LMS_ENC_KEY`, `FS_LMS_HASH_SALT`), настройка системного cron, настройка капчи, первая активация, чеклист после установки.

---

**`ADMIN_GUIDE.md`**

Как работать с заявками, зачислением, корзиной, PII-reveal, добавлением/заменой представителей, запросом удаления ПД, застрявшими зачислениями, каскадным удалением тестовых данных.
