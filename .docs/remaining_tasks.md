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

1. Таб Ученики✅

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

2. Отчисление✅

Нужно при отчислении ученика выбирать из какой именно группы его отчислить. Так, если он зачислен сразу в 2 группы, отчисление должно быть только из одной (1 отчисление = 1 дополнение записи в student_records)

При отчислении у ученика в Таблице "Ученики" просто убирается эта группа из столбца “Группа” и её расписание из “Расписание” при необходимости и предмет из столбца “Предмет”.

Если у ученика не осталось групп (отчислен из всех) - он пропадает из этой таблицы на табе Ученики.

Если у родителя не осталось зачисленных детей (их больше нет на табе Ученики) - то родитель пропадает из таблицы на табе Родители

Изменить поле deleted_at в wp_fs_lms_persons. Оно теперь не выполняет свою роль. Нужна иная проверка, что у ученика не осталось больше активных групп (из которых он не отчислен), после чего его можно удалить из таблицы Ученики.

Нужно адаптировать сюда поведение -OrphanCheckHandler. Или вынести в отдельный сервис логику проверки "у сущности не осталось больше связанных с ней записей" (например, у ученика не осталось больше групп, из которых он не отчислен)

3. Проверить трансфер данных✅

Периодически пропадают данные из таблиц или модальных окон. 

Проверить целостность передаваемых данных и соответствие полям таблицы/формы/модального окна. 

Проверить что все данные передаются ТОЛЬКО в DTO.

Даже если для отображения нужны не все данные, все равно подгружать весь объект DTO. 

Данные между базой и частями программы передаются единым объектом DTO (студента, родителя, зачисления и другие. Лишние и узконаправленные не создавать. Даже если нужны не все поля DTO - всё равно передавать весь объект). 

Привести все id полей к единому виду и получать данные только из DTO. Унифицировать и упростить передачу аргументов, чтобы в будущем было проще добавлять какое-либо новое значение

4. Рефактор JS ✅

Для соблюдения DRY нужно вынести методы по типу маскирования телефона или данных паспорта в отдельные общие методы

5. Отображение телефона в -person-modal ✅

В модальных окнах ученика и родителя телефон отображается по-разному: у ученика с маской, у родителя - без. Сделать везде отображение с маской.

6. Иконка у "Скопировать"✅
   
У сообщения “Скопировано” пропала иконка (перемести её вправо и сделай галочкой)

7. Row-actions ✅

Из модалок -person-modal убрать кнопки Экспорт и Отчислить - Поставить их как действия в row-actions. Сделать их доступными для bulk-actions (только у Учеников).

Модальное окно application-enrollment-modal привести к виду student-person-modal по ширине и расположению полей (только существующих, никаких новых не добавлять)

8. Новая колонка в student_records ✅

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

## Логирование

Везде логируется дата и время + если в таблице фигурируют человекочитаемые имена и названия, то в самой базе хранятся именно идентификаторы (ID).

### Архитектура: событийное логирование (паттерн Observer)

> Раздел рассчитан на параллельную работу нескольких разработчиков. Каждый канал лога — независимый вертикальный срез (таблица → enum → DTO → репозиторий → writer → подписчик → админка → экспорт), который можно делать отдельным человеком, не блокируя остальных. Общую инфраструктуру (шина + enum событий + реестр экспорта) делает один человек **первой**, до начала работы над каналами.

#### Контекст

Сейчас каждый writer (`ExportLogWriter`, `DataChangeLogWriter`, …) инжектится **напрямую** в ~15 сервисов и колбэков. Сервис обязан знать про логирование; чтобы добавить новый лог к существующему действию — приходится править сам сервис. Логи 1 (сущности) и 2 (зачисление) физически лежат в одной дженерик-таблице `audit_log`, что нарушает требование «в логе зачисления — только путь зачисления».

#### Целевой паттерн — Observer (Pub/Sub) поверх внутренней шины событий

Источник действия **не знает** про логирование. Он лишь **объявляет факт** («задание создано», «школа изменена», «заявка подписана»), передавая типизированный DTO. Каждый канал лога — **подписчик**, который сам решает, на какие события реагировать, и зовёт свой writer.

```
                       ┌─────────────────────────────┐
   Источник события    │   LogEventDispatcher (шина)  │     Подписчики (каналы)
   (Service/Callback)   │   dispatch(LogEvent, $dto)   │
        │              │   — generic, НЕ меняется     │   ┌── EntityAuditSubscriber ─→ EntityAuditLogWriter
        │ dispatch ───▶ │     никогда                  │──▶├── EnrollmentSubscriber  ─→ EnrollmentAuditLogWriter
        │              │   рассылает событие всем,    │   ├── DataChangeSubscriber  ─→ DataChangeLogWriter
        │              │   кто на него подписан        │   ├── ConsentSubscriber     ─→ ConsentChangeLogWriter
        │              └─────────────────────────────┘   ├── EmailSubscriber       ─→ EmailLogWriter
                                                            ├── DeletionSubscriber    ─→ DeletionLogWriter
   WP-нативные события на КРАЮ системы                      ├── ExportSubscriber      ─→ ExportLogWriter
   (wp_login, password_reset, delete_user, …)               ├── PiiAccessSubscriber   ─→ PiiAccessLogWriter
        └──── add_action в Controller-подписчике ──────────▶└── AuthSubscriber        ─→ AuthLogWriter
```

**«Оркестратора с маршрутизацией нет».** Соблазн сделать один сервис с `match($event) → нужный writer` — это god-object, который придётся править на каждое новое событие (нарушает OCP). Вместо этого: шина тупая и неизменная, «понимание, что за действие» живёт **в каждом подписчике**, который сам объявляет, что слушает. Образец уже в коде — `AuthLogController` подписан на `wp_login` / `password_reset` и зовёт `AuthLogWriter`.

#### Компоненты

| Компонент | Роль | Где |
|---|---|---|
| `LogEvent` (enum) | Каталог имён доменных событий — единая точка правды для источников и подписчиков (от опечаток в строках) | `inc/Enums/LogEvent.php` |
| `LogEventInterface` + payload-DTO | Типизированная нагрузка события (вместо мешка аргументов WP-хука) | `inc/DTO/Log/` |
| `LogEventDispatcherInterface` + `LogEventDispatcher` | Внутренняя шина: `subscribe(LogEvent, callable)` / `dispatch(LogEvent, $dto)`. Generic, без бизнес-логики | `inc/Services/Log/` |
| `*Subscriber` (Controller) | Тонкий контроллер-подписчик на канал: в `register()` подписывается на свои `LogEvent` и делегирует своему writer | `inc/Controllers/` |
| `*LogWriter` | Узкий writer канала (уже есть для 8 каналов) | `inc/Services/Log/` |
| `LogExportProvider` (интерфейс) + реестр | `columns()` + `rows()` на канал; контроллер экспорта резолвит провайдер по `LogChannel` | `inc/Services/Log/Export/` |

#### Почему внутренняя шина, а НЕ глобальный `do_action`

Для PII/GDPR-системы глобальный `do_action('fs_lms/person/data_changed', $dto)` — дыра: любой сторонний плагин может повесить `add_action` и прочитать расшифрованные старое/новое значение паспорта из payload. Поэтому:

- **Доменные события плагина** (изменение данных, согласие, зачисление, экспорт) → собственный `LogEventDispatcher` (приватный объект, не глобальные хуки). Payload типизирован и не покидает плагин.
- **WP-нативные события на краю** (`wp_login`, `wp_login_failed`, `password_reset`, `delete_user`, `profile_update`) → обычные `add_action` внутри Controller-подписчика, как сейчас в `AuthLogController`. Подписчик нормализует их в свой writer.

#### Принципы и правила

- **SRP / ISP** — один writer = один канал = один узкий метод. Без дженерик `record()`.
- **OCP** — добавить лог нового действия = `+1` case в `LogEvent` + `dispatch(...)` в источнике + метод-подписчик. Шина, экспорт-контроллер и остальные каналы **не трогаются**.
- **DIP** — источник инжектит `LogEventDispatcherInterface`, а не конкретные writer'ы. Подписчик инжектит только свой writer. Мокается в тестах.
- **DRY** — сбор контекста (actor/IP/UA) остаётся в трейте `RequestContextProvider`.
- **Тайминг = после коммита.** Compliance-логи (удаление, согласие, зачисление, изменение данных) `dispatch` строго **после** успешной транзакции, иначе залогируем откатившееся.
- **Подписчик defensive.** Логирование синхронное и inline — подписчик обязан ловить исключения внутри (try/catch) и не ронять основной поток. Падение лога ≠ падение зачисления. Не полагаться на порядок подписчиков.
- **Читаемость в админке.** В БД — только ID и технические значения. В админке всё резолвится в человекочитаемое: `display_name`, названия сущностей/групп/предметов, label из enum. Никаких слагов/ID в UI. Резолв id→имя — задача слоя отображения (Callback/Template), не writer'а.
- `PluginLogger` (технический файловый лог) — отдельный слой, не трогаем. Разделение корректно: `PluginLogger` → файл (ops), каналы → БД (compliance).
- `AuditService` после миграции на шину становится лишним — удалить (не оставлять как фасад).

#### Карта каналов (требование → канал → таблица)

| № | Требование | `LogChannel` | Таблица | Статус |
|---|---|---|---|---|
| 1 | Действия с сущностями (CRUD) | `EntityAudit` *(новый)* | `entity_audit_log` *(новая)* | ⬜ выделить из `audit_log` |
| 2 | Путь зачисления (только статусы) | `EnrollmentAudit` | `audit_log` *(перепрофилировать → enrollment-only)* | 🔄 |
| 3 | Доступ к ПД | `PiiAccess` | `pii_access_log` | ✅ таблица |
| 4 | Экспорт CSV | `Export` | `export_log` | ✅ таблица |
| 5 | Изменения данных | `DataChange` | `data_change_log` | ✅ таблица |
| 6 | Изменения согласия | `ConsentChange` | `consent_change_log` | ✅ таблица |
| 7 | Отправка писем | `Email` | `email_log` | ✅ таблица |
| 8 | Аутентификация | `Auth` | `auth_log` | ✅ таблица |
| — | GDPR-удаления (сквозной) | `Deletion` | `deletion_log` | ✅ таблица |

> ⚠️ `LogChannel` сейчас содержит `EnrollmentAudit`, но не `EntityAudit`. При расщеплении `audit_log` нужно: добавить case `EntityAudit`, новую `TableName::EntityAuditLog`, и развести `AuditAction` на «действия с сущностями» (лог 1) и «действия зачисления» (лог 2) — см. подзадачи логов 1 и 2.

Легенда статусов в чек-листах ниже: ✅ готово · 🔄 переделать/допилить · ⬜ не начато.

#### Общая инфраструктура (делается ПЕРВОЙ, один человек)

- [x] **`LogEvent` enum** — `inc/Enums/LogEvent.php`. Backed enum, каталог всех доменных событий (`SubjectCreated`, `TaskDeleted`, `PersonDataChanged`, `ParentSigned`, `StudentEnrolled`, `ConsentChanged`, `EmailSent`, `EntityHardDeleted`, …). Единая точка правды для источников и подписчиков.
- [x] **`LogEventInterface`** — `inc/Contracts/LogEventInterface.php`. Контракт payload-DTO события (минимум — маркерный интерфейс, чтобы типизировать `dispatch`).
- [x] **`LogEventDispatcherInterface`** — `inc/Contracts/LogEventDispatcherInterface.php`. Методы `subscribe(LogEvent $e, callable $listener): void`, `dispatch(LogEvent $e, LogEventInterface $payload): void`.
- [x] **`LogEventDispatcher`** — `inc/Services/Log/LogEventDispatcher.php`. Реализация: хранит `[event => listeners[]]`, рассылает synchronously, оборачивает каждый listener в try/catch (`PluginLogger::exception`), наружу не бросает. Регистрируется в DI как singleton.
- [x] **DI / bootstrap** — `LogEventDispatcher` как singleton в `Container`; источники инжектят `LogEventDispatcherInterface`. Все `*Subscriber` добавить в `Init::getServices()` (реализуют `ServiceInterface`, в `register()` подписываются на шину).
- [x] **`LogExportProvider` интерфейс + реестр** — `CsvExportProviderInterface` + `CsvExportProviderRegistry` + `ExportServiceBootstrap`; `ExportService` оркестрирует; `LogsCallbacks` делегирует через `ExportTarget` enum.
- [ ] **Удалить `AuditService`** — после перевода всех источников на `dispatch`. Снять прямые инъекции writer'ов из ~15 сервисов (`ApplicationService`, `EnrollmentService`, `ConsentService`, `PersonService`, `EmailService`, `*DeletionHandler`, и т.д.).
- [x] **Базовый трейт/хелпер резолва имён для админки** — `LogNameResolver` (`inc/Services/Log/LogNameResolver.php`): `userName`, `userNameWithRole`, `personName`, `entityName`, `date`.



1. Лог действий с сущностями

Нужно логировать действия с сущностями плагина: предметы, таксономии, визуальные шаблоны, типовые условия (boilerplate), задания, статьи, группы, учебные периоды, пользователи (ученики, родители, учителя).

Логировать по сути операций: создание, изменение, удаление.

Типовая структура таблицы в базе: 
* ID
* ID пользователя 
* Тип операции (создание, изменение, удаление)
* Тип сущности (предмет, таксономия и т.д.)
* ID сущности
* Дополнительно - только для операции изменения - фиксируется СТАРОЕ значение (в ID будут и так новые значение). Данные пользователей не раскрываются. К примеру, если изменен паспорт пользователя, то запись в этой колонке отсутствует (конкретные значения логируются в отдельной таблице)!
* Дата, время

Типовая структура таблицы в админке:
* ID
* Имя пользователя (display_name)
* Тип операции (создание, изменение, удаление) (badge)
* Тип сущности (предмет, таксономия и т.д.) (badge)
* ID сущности (Название сущности, к примеру: Русский язык (предмет), Автор (таксономия))
* Дополнительно прошлое название сущности (Английский язык (предмет))
* Дата, время


**Подзадачи лога 1 — `EntityAudit`** (новый канал, выделяется из `audit_log`):

- [x] **Таблица** — `TableName::EntityAuditLog` = `fs_lms_entity_audit_log`; DDL в `Migration_1_0_0::up()`.
- [x] **Enum `OperationType`** — `Create`/`Update`/`Delete` + `label()` + `badgeClass()`.
- [x] **Enum `EntityType`** — 11 типов + `label()` + `badgeClass()`.
- [x] **Enum `LogChannel`** — добавлен case `EntityAudit`.
- [ ] **Enum `AuditAction`** 🔄 — расщепить: оставить только действия зачисления, CRUD сущностей → в `EntityAudit` канал.
- [x] **DTO** — `EntityAuditLogDTO` + `EntityAuditLogInputDTO`.
- [x] **Payload-событий** — `EntityChangedEvent implements LogEventInterface`.
- [x] **Repository** — `EntityAuditLogRepository` (`create()`, `list()`, `countFiltered()`, `listAll()`).
- [x] **Writer** — `EntityAuditLogWriter::record(actorUserId, OperationType, EntityType, entityId, oldLabel?)`.
- [x] **События** — `dispatch(...)` в источниках: Subject (SubjectCrudCallbacks + SubjectDeletionCascadeHandler), Taxonomy (TaxonomySettingsCallbacks), Group (StudentGroupCallbacks), Period (AcademicPeriodCallbacks), Task/Article (PostEntityAuditController), Boilerplate (BoilerplateCallbacks), User (EnrollmentService + Student/ParentDeletionHandler).
- [x] **Подписчик** — `EntityAuditSubscriber` зарегистрирован в `Init::getServices()`.
- [x] **Админка** — `templates/admin/components/tabs/logs-tabs/logs-0-entity-audit.php`: badge операции, badge типа, `LogNameResolver::entityName`, колонка «Прошлое название», `LogNameResolver::date`.
- [x] **Экспорт** — `EntityAuditLogExportProvider`.

---

2. Лог действий зачисления
Нужно логировать все изменения статусов ученика: получение заявки, заполнение родителем, зачисление, отчисление и т.д.

ТОЛЬКО статусы заявки, никаких других данных

Типовая структура таблицы в базе:
* ID
* ID пользователя
* Тип операции (из enum AuditAction (пересмотреть кейсы), к примеру, SubmitParentData => 'Подписано родителем')
* ID цели (ученика)
* Дополнительно - только для операции зачисления - ID студента, ID группы 
* Дата, время

Типовая структура таблицы в админке:
* ID
* Имя пользователя  (display_name)
* Тип операции (из enum AuditAction (пересмотреть кейсы), к примеру, SubmitParentData => 'Подписано родителем') (badge)
* Имя цели (ФИО ученика)
* Дополнительно - только для операции зачисления - ФИО ученика, название группы
* Дата, время

**Подзадачи лога 2 — `EnrollmentAudit`** (перепрофилировать существующий `audit_log`):

- [ ] **Таблица** 🔄 — оставить `fs_lms_audit_log`, но очистить семантику: только путь зачисления. Убрать из неё entity-CRUD (ушли в лог 1) и hard_delete (есть в `deletion_log`). Опционально добавить `student_record_id`, `group_id` (заполняются только для операции зачисления — см. ТЗ «Дополнительно»).
- [ ] **Enum `AuditAction`** 🔄 — пересмотреть кейсы: оставить только статусы заявки/зачисления (`CreateApplication`, `SubmitParentData`, `StartEnrollment`, `EnrollStudent`, `StudentExpelled`, `CancelEnrollment`, `ExpireApplication`, …). Проверить `label()` на читаемость. Entity- и deletion-кейсы вынести.
- [ ] **DTO** 🔄 — `AuditLogDTO` (есть) допилить: резолв `target_id`→ФИО ученика, для зачисления — название группы; `action`→label. `AuditLogInputDTO` для записи (сейчас writer пишет массивом — завести Input DTO для типобезопасности).
- [x] **Payload-событий** — `EnrollmentStatusEvent` реализован.
- [x] **Repository** ✅ — `AuditLogRepository` есть.
- [x] **Writer** ✅ — `EnrollmentAuditLogWriter` есть.
- [ ] **События** ⬜ — `dispatch(...)` в `ApplicationService` / `EnrollmentService` / `ExpulsionService` **после коммита**. Пока AuditService используется напрямую.
- [x] **Подписчик** — `EnrollmentAuditSubscriber` зарегистрирован в `Init::getServices()`.
- [ ] **Админка** 🔄 — `logs-1-audit.php`: `display_name`, badge действия, ФИО цели, для зачисления — ФИО+название группы.
- [x] **Экспорт** — `EnrollmentAuditLogExportProvider`.

---

3. Лог действий просмотра Пдн

Логировать кто из администраторов (имя+id+ip-адрес+с какого устройства сессия) просмотрел данные.

Типовая структура таблицы в базе:
* ID
* ID пользователя
* ID цели (ученика, родителя)
* IP адрес пользователя
* UserAgent пользователя
* Дата, время

Типовая структура таблицы в админке:
* ID
* Имя пользователя  (display_name)
* Имя цели (ФИО ученика или родителя)
* IP адрес пользователя
* UserAgent пользователя
* Дата, время

**Подзадачи лога 3 — `PiiAccess`**:

- [ ] **Таблица** ✅ — `fs_lms_pii_access_log` есть (`actor_user_id`, `person_id`, `fields_accessed`, `access_reason`, `actor_ip`, `actor_ua`, `created_at`). Колонка `actor_ua` (Устройство) добавлена.
- [ ] **DTO** ✅ — `PiiAccessLogDTO` + `PiiAccessLogInputDTO` (с `actorUa`). Проверить резолв `person_id`→ФИО для админки.
- [x] **Payload-события** — `PiiRevealedEvent` реализован.
- [x] **Repository** ✅ — `PiiAccessLogRepository`.
- [x] **Writer** ✅ — `PiiAccessLogWriter`.
- [x] **Событие + подписчик** — `LogEvent::PiiRevealed`; `dispatch` в `PersonReader::logAccess()`; `PiiAccessSubscriber` зарегистрирован.
- [ ] **Админка** 🔄 — `logs-2-pii.php`: `display_name`, ФИО цели, IP, UserAgent (Устройство). Без слагов/ID.
- [x] **Экспорт** — `PiiAccessLogExportProvider`.

---

4. Лог экспорта CSV

Логируется каждый запрос экспорта данных администратором.

Типовая структура таблицы в базе:
* ID
* ID пользователя
* Тип данных откуда был вызван экспорт: группы, ученики, родители, архивные записи, лог
* Тип действия для групп, учеников, родителей и архивных записей - массовое действие или одна запись
* ID цели (ученика, родителя) если одна запись, то записывается ID записи, если массовое действие - перечисляются все затронутые ID
* IP адрес пользователя
* UserAgent пользователя
* Дата, время

Типовая структура таблицы в админке:
* ID
* Имя пользователя  (display_name)
* Тип данных откуда был вызван экспорт: группы, ученики, родители, архивные записи, лог
* Тип действия для групп, учеников, родителей и архивных записей - массовое действие или одна запись
* Имя цели (ФИО ученика или родителя)
* IP адрес пользователя
* UserAgent пользователя
* Дата, время


**Подзадачи лога 4 — `Export`**:

- [ ] **Таблица** ✅ — `fs_lms_export_log` (`actor_user_id`, `data_type`, `action_type`, `target_ids_json`, `created_at`). Проверить наличие `actor_ip`/`actor_ua` если нужны в админке (сейчас их нет — добавить при необходимости).
- [ ] **Enum `ExportDataType`** ⬜ — `Groups`, `Students`, `Parents`, `Archive`, `Logs` + `label()`. Источник экспорта.
- [ ] **Enum `ExportActionType`** ⬜ — `Single`/`Bulk` + `label()` (одна запись / массовое действие).
- [ ] **DTO** ✅ — `ExportLogDTO` + `ExportLogInputDTO`. Допилить резолв `target_ids_json`→ФИО (для single — имя; для bulk — перечень/кол-во).
- [ ] **Payload-события** ⬜ — `DataExportedEvent` (actor, dataType, actionType, targetIds[]).
- [x] **Repository** ✅ — `ExportLogRepository`.
- [x] **Writer** ✅ — `ExportLogWriter` — вызывается в `ExportService::run()` после каждого экспорта.
- [ ] **Событие + подписчик** ⬜ — `LogEvent::DataExported`; `ExportSubscriber`. Пока `ExportLogWriter` вызывается прямо из `ExportService`.
- [ ] **Админка** 🔄 — `logs-3-export.php`: `display_name`, тип данных (label), тип действия (badge), ФИО цели, IP/Устройство.
- [x] **Экспорт** — `ExportLogExportProvider`.

---

5. Лог изменений данных

Логируется каждое изменение данных пользователя через модальные окна.

Типовая структура таблицы в базе:
* ID
* ID пользователя
* ID цели (ученика, родителя)
* Тип данных: какое поле данных было изменено (например - школа)
* Старое значение
* Новое значение
* Дата, время

Типовая структура таблицы в админке:
* ID
* Имя пользователя  (display_name)
* Имя цели (ФИО ученика или родителя)
* Тип данных: какое поле данных было изменено (например - школа)
* Старое значение
* Новое значение
* Дата, время


**Хранение old/new значений:** значения сохраняются в восстановимом (зашифрованном) виде — как `person_documents`. В админской таблице старое и новое значение показываются **маской** (например `+7 9** *** ** 34`), при CSV-экспорте — в **открытом (расшифрованном)** виде. Это снимает конфликт с правилом «не писать PII в `details_json`»: значения не лежат в логе открытым текстом.

**Подзадачи лога 5 — `DataChange`**:

- [ ] **Таблица** ✅ — `fs_lms_data_change_log` (`actor_user_id`, `target_person_id`, `field_name`, `old_value_enc` blob, `new_value_enc` blob, `created_at`). Значения зашифрованы (как `person_documents`).
- [ ] **Enum `DataFieldType`** ⬜ — поля, которые логируем (`School`, `Grade`, `Passport`, `Phone`, `Email`, `LastName`, …) + `label()` для админки.
- [ ] **DTO** ✅ — `DataChangeLogDTO` + `DataChangeLogInputDTO`. Реализовать в DTO: расшифровку + **маскирование** для админки (`+7 9** *** ** 34`) и расшифровку в открытом виде для CSV-экспорта.
- [x] **Payload-события** — `PersonDataChangedEvent` реализован.
- [x] **Repository** ✅ — `DataChangeLogRepository`.
- [x] **Writer** ✅ — `DataChangeLogWriter` (шифрует значения).
- [x] **Событие + подписчик** — `LogEvent::PersonDataChanged`; `dispatch` в `PersonService::updatePerson()` по каждому полю; `DataChangeSubscriber` зарегистрирован.
- [ ] **Админка** 🔄 — `logs-4-data-change.php`: `display_name`, ФИО цели, `field_name`→label, старое/новое значение **маской**.
- [x] **Экспорт** — `DataChangeLogExportProvider` — значения расшифровываются через `PiiCryptoService`.

---

6. Лог изменений согласия на обработку Пдн

Хранятся все изменения согласия.

Типовая структура таблицы в базе:
* ID
* ID пользователя
* Тип согласия 
* Старое значение
* Новое значение
* Дата, время

Типовая структура таблицы в админке:
* ID
* Имя пользователя  (display_name)
* Тип согласия
* Старое значение
* Новое значение
* Дата, время

Записывается значение хеша согласий.

**Подзадачи лога 6 — `ConsentChange`**:

- [ ] **Таблица** ✅ — `fs_lms_consent_change_log` (`actor_user_id`, `person_id` nullable, `consent_type`, `old_hash`, `new_hash`, `created_at`). Хранится хеш согласий.
- [ ] **Enum `ConsentType`** ✅ — есть; проверить `label()` под админку.
- [ ] **DTO** ✅ — `ConsentChangeLogDTO` + `ConsentChangeLogInputDTO`. Резолв `person_id`→ФИО.
- [x] **Payload-события** — `ConsentChangedEvent` реализован (`newHash` nullable для отзыва).
- [x] **Repository** ✅ — `ConsentChangeLogRepository`.
- [x] **Writer** ✅ — `ConsentChangeLogWriter`.
- [x] **Событие + подписчик** — `LogEvent::ConsentChanged`; `dispatch` в `ConsentService` (3 точки); `ConsentChangeSubscriber` зарегистрирован.
- [ ] **Админка** 🔄 — `logs-5-consent-change.php`: `display_name`, ФИО цели, тип согласия (label), старый/новый хеш.
- [x] **Экспорт** — `ConsentChangeLogExportProvider`.

---

7. Лог отправки писем

Логируется каждая отправка письма плагином (через `EmailService` / `wp_mail`).

Типовая структура таблицы в базе:
* ID
* ID пользователя
* Тип письма
* ID получателя
* Email назначения
* Статус (отправлено/ошибка)
* Дата, время

Типовая структура таблицы в админке:
* ID
* Имя пользователя  (display_name)
* Тип письма
* ФИО получателя
* Email назначения
* Статус (отправлено/ошибка)
* Дата, время


Тип письма - кейс из `EmailTemplateType` (OTP, установка пароля, подтверждение заявки и т.д.)


**Подзадачи лога 7 — `Email`**:

- [ ] **Таблица** ✅ — `fs_lms_email_log` (`actor_user_id`, `email_type`, `target_person_id`, `status`, `error_message`, `created_at`). Нет колонки «Email назначения» из ТЗ — добавить `recipient_email` (varchar) при необходимости.
- [ ] **Enum `EmailTemplateType`** ✅ — есть (OTP, установка пароля, подтверждение заявки и т.д.); тип письма = case enum + `label()`.
- [ ] **DTO** ✅ — `EmailLogDTO` + `EmailLogInputDTO`. Резолв `target_person_id`→ФИО получателя.
- [x] **Payload-события** — `EmailSentEvent` реализован.
- [x] **Repository** ✅ — `EmailLogRepository`.
- [x] **Writer** ✅ — `EmailLogWriter`.
- [x] **Событие + подписчик** — `LogEvent::EmailSent`; `dispatch` в `EmailService` (6 методов); `EmailSubscriber` зарегистрирован.
- [ ] **Админка** 🔄 — `logs-6-email.php`: `display_name`, тип письма (label), ФИО получателя, Email назначения, статус (badge sent/error).
- [x] **Экспорт** — `EmailLogExportProvider`.

---

8. Лог аутентификации

Логируется вход, неуспешный вход, OTP и сброс пароля.

Типовая структура таблицы в базе:
* ID
* ID пользователя
* Действие
* Результат
* IP адрес пользователя
* UserAgent пользователя
* Дата, время

Типовая структура таблицы в админке:
* ID
* ID пользователя
* Действие
* Результат
* Дата, время

Имя пользователя - для неуспешного входа хранить введённый логин/ID попытки (без пароля)

Действие - login / login_failed / otp_sent / otp_verified /

Результат - успех/неуспех

**Подзадачи лога 8 — `Auth`**:

- [ ] **Таблица** ✅ — `fs_lms_auth_log` (`login_identifier`, `action`, `result`, `actor_ip`, `actor_ua`, `created_at`). Для неуспешного входа хранится введённый логин/ID попытки (без пароля).
- [ ] **Enum `AuthAction`** ⬜ — `Login`, `LoginFailed`, `OtpSent`, `OtpVerified`, `PasswordReset` + `label()`.
- [ ] **Enum `AuthResult`** ⬜ — `Success`/`Failure` + `label()`.
- [ ] **DTO** ✅ — `AuthLogDTO` + `AuthLogInputDTO`.
- [ ] **Payload-события** ⬜ — `AuthEvent` (loginIdentifier, action, result).
- [ ] **Repository** ✅ — `AuthLogRepository`.
- [ ] **Writer** ✅ — `AuthLogWriter`.
- [ ] **Событие + подписчик** ✅ — `AuthLogController` (эталон): подписан на `wp_login` / `wp_login_failed` / `password_reset`; OTP-события (`otp_sent`/`otp_verified`) пишутся из `ApplicationCallbacks`. При желании унифицировать строковые `action`/`result` через enum выше.
- [ ] **Админка** 🔄 — `logs-8-auth.php`: логин/ID попытки, действие (label), результат (badge). По ТЗ — без UA в админской таблице.
- [x] **Экспорт** — `AuthLogExportProvider`.

---

TODO: перенести удаления в таблицу 1 (действия с сущностями): логировать удаления предметов, учеников, родителей. Не создавать новую таблицу.
**Подзадачи канала `Deletion`** (сквозной GDPR-журнал жёстких удалений; не входит в нумерацию 1–8, но обязателен):

- [ ] **Таблица** ✅ — `fs_lms_deletion_log` (`actor_user_id`, `entity_type`, `entity_id`, `cascaded_summary`, `actor_ip`, `created_at`).
- [ ] **DTO** ✅ — `DeletionLogDTO` + `DeletionLogInputDTO`.
- [ ] **Repository / Writer** ✅ — `DeletionLogRepository`, `DeletionLogWriter` (уже интегрирован в `Student/Parent/Group` DeletionHandler'ы).
- [x] **Событие + подписчик** — `LogEvent::EntityHardDeleted`; `dispatch` в Student/Parent/GroupDeletionHandler ПОСЛЕ транзакции; `DeletionSubscriber` зарегистрирован.
- [ ] **`AuditAction` hard_delete_*** 🔄 — кейсы `HardDeletePerson/Group/Subject/Period` пока остаются в `audit_log`; убрать после полного отказа от `AuditService`.
- [ ] **Админка** 🔄 — `logs-7-deletion.php`: `display_name`, тип сущности (label), сводка каскада, IP.
- [x] **Экспорт** — `DeletionLogExportProvider`.

---

## CSV-экспорт

### Архитектура: единый CSV-экспорт (паттерны Strategy + Registry)


#### Контекст

`CsvExportService` (паттерн **Column Projection**) уже готов и его **не трогаем**: он принимает `iterable $rows` + `CsvColumn[]` (заголовок + closure-экстрактор), отдаёт BOM-CSV и одноразовую ссылку `createDownloadLink()`. Это «как писать CSV». Варьируется только **«какие данные собрать и в какие колонки разложить»** для конкретного датасета.

«Одиночный / массовый экспорт» — **не отдельная ось и не отдельный паттерн**: одиночный = массив из одного ID, массовый = массив из N. Конвейер один; на вход всегда `int[] $ids`. Разница только в AJAX-параметре кнопки (`id` vs `ids[]` vs «все по фильтру»). Все сущности поддерживают экспорт как одной записи, так и нескольких (через массовые действия).

#### Целевой паттерн — Strategy (провайдер на датасет) + Registry (резолв по enum) + тонкий оркестратор (Template Method)

```
ExportTarget (enum)        CsvExportProviderInterface           Registry              Orchestrator (фикс. скелет)
  Groups   ─┐                columns(): CsvColumn[]               [target => provider]   1. authorize (Nonce+Capability::ExportPII)
  Students  │  резолв ─▶      rows(int[] $ids): iterable      ◀─ резолв по enum ─▶       2. provider = registry->resolve(target)
  Parents   │   по enum       filename(): string                                         3. rows = provider->rows($ids)
  Archive   │                                                                             4. csv  = CsvExportService->export(rows, provider->columns())
  Log:* ───┘   ┌── GroupsExportProvider                                                  5. ExportLogWriter / событие DataExported (лог 4)
                ├── StudentsExportProvider                                                 6. createDownloadLink()
                ├── ParentsExportProvider
                ├── ArchiveExportProvider
                └── *LogExportProvider × 9   (тот же интерфейс — см. раздел «Логирование»)
```

#### Компоненты

| Компонент | Роль | Где |
|---|---|---|
| `ExportTarget` (enum) | Каталог целей экспорта (`Groups`, `Students`, `Parents`, `Archive` + по одному на лог-канал) + `label()` | `inc/Enums/ExportTarget.php` |
| `CsvExportProviderInterface` | Контракт стратегии: `columns()`, `rows(int[] $ids)`, `filename()` | `inc/Contracts/CsvExportProviderInterface.php` |
| `*ExportProvider` | Стратегия датасета: собирает row-DTO из репозиториев + объявляет колонки | `inc/Services/Export/` |
| `CsvExportProviderRegistry` | Маппинг `ExportTarget → provider`; резолв в оркестраторе | `inc/Services/Export/` |
| `ExportService` (оркестратор) | Фиксированный скелет: authorize → resolve → rows → CSV → лог → ссылка | `inc/Services/Export/` |
| `*ExportRowDTO` | Типизированная строка экспорта (резолв id→имя, расшифровка PII) | `inc/DTO/Export/` |
| `CsvExportService` + `CsvColumn` | Механизм записи (готов) | `inc/Services/CsvExportService.php` |

#### Принципы и правила

- **OCP** — новый экспорт = `+1` case в `ExportTarget`, `+1` провайдер, `+1` строка в реестр. Оркестратор, AJAX-колбэк и `CsvExportService` **не трогаются**.
- **SRP** — провайдер только собирает данные + объявляет колонки; запись CSV, авторизация, логирование, ссылка — в оркестраторе.
- **DIP** — колбэк зависит от `ExportService`/реестра, а не от конкретных провайдеров.
- **Единый контракт с экспортом логов.** `CsvExportProviderInterface` = тот же `LogExportProvider` из раздела «Логирование». Не плодить два механизма: один интерфейс, один реестр, один оркестратор обслуживают и доменные экспорты, и 9 лог-каналов. «Экспорт логов» (п.5) перестаёт быть отдельной задачей — это ещё 9 провайдеров.
- **Сбор в DTO до колонок.** `rows()` сначала агрегирует данные из репозиториев в типизированные `*ExportRowDTO` (резолв предмета/периода/ФИО, расшифровка PII), и только потом `CsvColumn`-экстракторы тянут из DTO. Никаких запросов в БД из экстракторов.
- **Каждый экспорт логируется** (лог 4) и закрыт `Capability::ExportPII`. PII-экспорты содержат расшифрованные данные — отдавать только через одноразовую ссылку с TTL.

#### ⚠️ Сквозная колонка «Пароль» — хранится в `usermeta` (`fs_lms_enc_password`)

WordPress в `users.user_pass` держит **только хеш** — восстановить нельзя. **Решение:** сгенерированный пароль (`PasswordGeneratorService`) сохраняется в `usermeta` (зашифрованным — как `person_documents`) в момент создания аккаунта, и оттуда же берётся в экспорт. Касается учеников и родителей.

- [x] Писать пароль в `usermeta` при генерации (ученик и родитель) — через `Manager`, не `update_user_meta` напрямую.
- [x] Экспорт читает «Пароль» из `usermeta` (расшифровка), а не из `user_pass`.
- ⚠️ ПДн-риск: `usermeta` с живым паролем — чувствительные данные. Экспорт только под `Capability::ExportPII` + одноразовая ссылка с TTL; доступ к meta-ключу ограничить.

#### Общая инфраструктура (делается ПЕРВОЙ, один человек)

- [x] **`ExportTarget` enum** — `inc/Enums/ExportTarget.php`: 13 кейсов (Groups, Students, Parents, Archive + 9 лог-каналов) + `label()`.
- [x] **`CsvExportProviderInterface`** — `inc/Contracts/CsvExportProviderInterface.php`: `columns()`, `rows(array $context)`, `filename()`.
- [x] **`CsvExportProviderRegistry`** — `inc/Services/Export/CsvExportProviderRegistry.php`; `ExportServiceBootstrap` регистрирует все 13 провайдеров.
- [x] **`ExportService` (оркестратор)** — `inc/Services/Export/ExportService.php`: resolve → rows → csv → `ExportLogWriter::record` → link.
- [x] **AJAX/колбэк** — `LogsCallbacks` делегирует в `ExportService::run()`; 14 новых AJAX-хуков в `AjaxHook`; `LogsController` зарегистрирован.
- [x] **Пароль в `usermeta`** — `StudentsExportProvider` / `ParentsExportProvider` читают `MetaKeys::EncPassword` → `base64_decode` → `PiiCryptoService::decrypt`.
- [x] **Хелпер расшифровки PII** — `PiiCryptoService` используется в `StudentsExportProvider`, `ParentsExportProvider`, `DataChangeLogExportProvider`.

Легенда: ✅ готово · 🔄 переделать · ⬜ не начато.

---

1. Экспорт групп — `GroupsExportProvider` (страница `groups`)

| Колонка | Источник |
|---|---|
| ID | `groups.id` |
| Название предмета | `options[fs_lms_subjects_list][subject_key].name` по `groups.subject_key` |
| Учебный период | `options[fs_lms_academic_periods][...].name` по `groups.academic_period_id` |
| Название группы | `groups.name` |
| ФИО преподавателя | `users.display_name` по `groups.teacher_id` |
| Расписание | `groups.schedule` |
| Кол-во учеников | `COUNT(student_records WHERE group_id = groups.id)` |
| Создана / Обновлена / Удалена | `groups.created_at / updated_at / deleted_at` |

**Подзадачи:**
- [x] `GroupsExportProvider` — `inc/Services/Export/GroupsExportProvider.php`; 8 колонок; резолв предмета/периода/преподавателя; `findActiveByGroupId` для счётчика.
- [x] Регистрация в реестре — `ExportServiceBootstrap`.

2. Экспорт учеников — `StudentsExportProvider` (страница `userlist-2-students`)

| Колонка         | Источник |
|-----------------|---|
| ID              | `persons.id` (ученика) |
| ФИО ученика     | `persons.last_name/first_name/middle_name` |
| ФИО родителя    | через `student_records.parent_person_id` → `persons` |
| Дата рождения   | `persons.birth_date` |
| Школа / Класс   | `persons.school / persons.grade` |
| Предмет         | `student_records.group_id` → `groups.subject_key` → `subjects_list[...].name` (неск. через `;`) |
| Группа          | `groups.name` (неск. через `;`) |
| Расписание      | `groups.schedule` (неск. через `;`) |
| Номер договора  | `student_records.contract_no` (неск. через `;`) |
| Телефон / Почта | `person_documents` **ученика** (расшифровка) |
| Логин           | `users.user_login` по `persons.wp_user_id` |
| Пароль          | `usermeta` ученика (расшифровка) |
| Статус          | `student_records.status` |

**⚠️ Анализ колонок:**
- ✅ **«Пароль»** — из `usermeta` ученика (см. сквозную колонку «Пароль»).
- ✅ **Мульти-группа:** одна строка на ученика; предметы/группы/расписания/договоры — в одной ячейке через `;`.
- ✅ **Телефон/Почта** — собственные данные ученика (`person_documents` ученика), не родителя.

**Подзадачи:**
- [x] `StudentsExportProvider` — `inc/Services/Export/StudentsExportProvider.php`; PII расшифровка; пароль из `MetaKeys::EncPassword`; группы/предметы через `;`.
- [x] Регистрация в реестре — `ExportServiceBootstrap`.

3. Экспорт родителей — `ParentsExportProvider` (страница `userlist-3-parents`)

| Колонка | Источник |
|---|---|
| ID | `persons.id` (родителя) |
| ФИО родителя | `persons` родителя |
| ФИО ученика | дети через `student_records.parent_person_id` → `student_person_id` → `persons` (несколько — через `;`) |
| Предмет / Группа | по `student_records.group_id` (несколько — через `;`) |
| Номер договора | `student_records.contract_no` |
| Дата заключения договора | `student_records.contract_date` |
| Телефон / Почта | `person_documents` **родителя** (расшифровка) |
| Логин | почта родителя (`users.user_login` = email) |
| Пароль | `usermeta` родителя (расшифровка) |

**⚠️ Анализ колонок:**
- ✅ **«Пароль»** — из `usermeta` родителя.
- ✅ **«Логин» = почта** родителя (`user_login` совпадает с email)
- ✅ **Телефон/Почта** — собственные данные родителя.
- Мульти-ребёнок/мульти-группа через `;` — корректно.

**Подзадачи:**
- [x] `ParentsExportProvider` — `inc/Services/Export/ParentsExportProvider.php`; дети/группы/предметы через `;`; PII расшифровка; пароль из `MetaKeys::EncPassword`.
- [x] Регистрация в реестре — `ExportServiceBootstrap`.

4. Экспорт архива — `ArchiveExportProvider` (страница `userlist-4-archive`)

| Колонка | Источник |
|---|---|
| ФИО ученика |`student_records.snapshot_last/first/middle_name` |
| ФИО родителя | `student_records.parent_person_id` → `persons` |
| Школа / Класс | `student_records.snapshot_school / snapshot_grade` |
| ID группы | `student_records.group_id` |
| Предмет / Группа | по `group_id` → `groups` |
| Номер договора / Дата подписания | `student_records.contract_no / contract_date` |
| Номер приказа | `student_records.order_no` |
| Дата зачисления | `student_records.enrolled_at` |
| Статус | `student_records.status` |
| Дата отчисления | `student_records.expelled_at` |
| Причина отчисления | `student_records.expel_reason` |

**Подзадачи:**
- [x] `ArchiveExportProvider` — `inc/Services/Export/ArchiveExportProvider.php`; snapshot-поля; резолв группы/предмета/ФИО родителя; пагинированная итерация по `list()`.
- [x] Регистрация в реестре — `ExportServiceBootstrap`.

5. Экспорт логов — `*LogExportProvider` (страница `logs`)

Экспорт каждого из 9 каналов (см. раздел «Логирование», подзадачи «Экспорт» в каждом канале). Реализуется тем же `CsvExportProviderInterface` и тем же реестром/оркестратором — отдельной инфраструктуры не требует.

**⚠️ Важно:** значения, которые в админке показываются **маской** (лог 5 — изменения данных), в CSV выгружаются в **расшифрованном** виде. Сам экспорт логов тоже пишется в `export_log` (`data_type = Logs`).

**Подзадачи:**
- [x] 9 `*LogExportProvider` реализованы в `inc/Services/Export/Log/`: EntityAudit, EnrollmentAudit, PiiAccess, ExportLog, DataChange, ConsentChange, Email, Deletion, Auth.
- [x] Все зарегистрированы в `ExportServiceBootstrap` по `ExportTarget` кейсу.


---

## CSV-импорт (ученики + родители + группы, минуя заявки)

### Архитектура: зеркало CSV-экспорта (паттерны Strategy + Registry)

#### Контекст

Нужно загружать учеников прошлых лет из собственной CSV-таблицы напрямую в БД, **минуя заявочный флоу** (applications, OTP, join-коды). Импорт повторяет «постзаявочную» часть `EnrollmentService::enroll()`: persons + person_documents (PII шифруется) + WP-учётки + groups + student_records. Вертикаль — зеркало экспорта: контракт → провайдер на датасет → реестр → оркестратор → bootstrap → AJAX-колбэк.

Ключевое ограничение схемы: `student_records.parent_person_id` и `group_id` — `NOT NULL`. Зачисление без родителя и группы невозможно, поэтому **одна строка CSV учеников = ученик + родитель + группа** (find-or-create родителя и группы инлайн). Отдельные цели `Groups` и `Parents` — для предзагрузки справочников, но необязательны.

#### Целевой паттерн

```
ImportTarget (enum)     CsvImportProviderInterface            Registry              ImportService (фикс. скелет)
  Students ─┐             headers(): string[]                  [target => provider]   1. authorize — в колбэке (Nonce::Manager + Capability::Admin)
  Parents   │ резолв ─▶   importRow(array $row,            ◀─ резолв по enum ─▶       2. provider = registry->resolve(target)
  Groups  ──┘  по enum      ImportContextDTO): ImportRowResultDTO                     3. rows = CsvParseService->parse($file)
                                                                                      4. validateHeaders(provider->headers())
              ┌── StudentsImportProvider                                              5. foreach row: транзакция → importRow → счётчики/ошибки
              ├── ParentsImportProvider                                               6. лог импорта (шина событий)
              └── GroupsImportProvider                                                7. return ImportReportDTO
```

#### Компоненты

| Компонент | Роль | Где |
|---|---|---|
| `ImportTarget` (enum) | Каталог целей импорта (`Students`, `Parents`, `Groups`) + `label()` | `inc/Enums/ImportTarget.php` |
| `CsvImportProviderInterface` | Контракт стратегии: `headers()`, `importRow()` | `inc/Contracts/CsvImportProviderInterface.php` |
| `*ImportProvider` | Стратегия датасета: валидация строки + запись через существующие сервисы | `inc/Services/Import/` |
| `CsvImportProviderRegistry` | Маппинг `ImportTarget → provider`; резолв в оркестраторе | `inc/Services/Import/` |
| `ImportService` (оркестратор) | Фикс. скелет: resolve → parse → validate → rows → отчёт → лог | `inc/Services/Import/` |
| `CsvParseService` | Механизм чтения: BOM, кодировка, разделитель, маппинг заголовков | `inc/Services/Import/CsvParseService.php` |
| `ImportReportDTO` / `ImportRowResultDTO` / `ImportContextDTO` | Типизированный отчёт, результат строки, контекст запуска | `inc/DTO/Import/` |

#### Принципы и правила

- **OCP** — новый импорт = `+1` case в `ImportTarget`, `+1` провайдер, `+1` строка в bootstrap. Оркестратор, парсер и колбэк **не трогаются**.
- **SRP** — парсер только читает; провайдер валидирует и пишет одну строку; оркестратор держит скелет, транзакции и отчёт.
- **Переиспользование** — запись только через существующие сервисы/репозитории: `PersonService`, `UserManager`, `PasswordGeneratorService`, `GroupsRepository`, `StudentRecordRepository`. Никаких прямых wpdb-вставок в провайдерах.
- **Идемпотентность** — повторная загрузка того же файла даёт `skipped`, а не дубли (см. Шаг 2 — дедупликация).
- **Транзакция на строку** (`TransactionRunner`) + try/catch: одна битая строка не валит файл; ошибка попадает в отчёт со своим номером строки.
- **Dry-run** — тот же конвейер без записи: провайдер выполняет все резолвы и проверки, но пропускает create. Первая обкатка реальной таблицы — всегда в dry-run.
- **Минуем заявки осознанно**: записи в `applications` не создаются вовсе; `consents` не создаются (согласия собраны вне плагина; если потребуется строка для отчётности — `consent_type = 'imported'` отдельной подзадачей); welcome-письма не отправляются.
- **Совместимость с экспортом** — колонки ученика совпадают с `StudentsExportProvider`: прошлогодний экспорт подходит как входной формат (логины/пароли переносятся).

---

#### Шаг 1 — Инфраструктура импорта (делается ПЕРВОЙ)

- [ ] **`ImportTarget` enum** — `inc/Enums/ImportTarget.php`: cases `Students = 'students'`, `Parents = 'parents'`, `Groups = 'groups'` + `label()` (зеркало `ExportTarget`).
- [ ] **`CsvImportProviderInterface`** — `inc/Contracts/CsvImportProviderInterface.php`:

```php
interface CsvImportProviderInterface {
	/** @return string[] Обязательные заголовки CSV (валидация файла + образец) */
	public function headers(): array;

	/** Импортирует одну строку. Бросает InvalidArgumentException с текстом для отчёта. */
	public function importRow( array $row, ImportContextDTO $ctx ): ImportRowResultDTO;
}
```

- [ ] **DTO** — `inc/DTO/Import/`:
	- `ImportContextDTO` (readonly): `bool $dryRun`, `int $actorId`, `int $rowNumber`;
	- `ImportRowResultDTO` (readonly): `string $status` (`created` | `skipped`), `?string $note`;
	- `ImportReportDTO`: `int $created`, `int $skipped`, `array $errors` (`[№ строки => сообщение]`), `bool $dryRun`, метод `toArray()` для AJAX-ответа.
- [ ] **`CsvParseService`** — `inc/Services/Import/CsvParseService.php`:
	- `parse( string $filePath ): iterable` — генератор ассоц-массивов «заголовок → значение»: срезает UTF-8 BOM; авто-детект разделителя (`;` или `,` по первой строке); перекодировка cp1251 → UTF-8 (`mb_check_encoding` / `mb_convert_encoding`); потоковый `fgetcsv` без загрузки файла в память;
	- `validateHeaders( array $expected, array $actual ): void` — бросает `InvalidArgumentException` со списком недостающих колонок.
- [ ] **`CsvImportProviderRegistry`** — `inc/Services/Import/CsvImportProviderRegistry.php`: `register( ImportTarget, CsvImportProviderInterface )`, `resolve( ImportTarget )`, `has( ImportTarget )` — копия `CsvExportProviderRegistry`.
- [ ] **`ImportService` (оркестратор)** — `inc/Services/Import/ImportService.php` (readonly, `use TransactionRunner`):
	- `run( ImportTarget $target, string $filePath, bool $dryRun = false ): ImportReportDTO`;
	- скелет: `registry->resolve()` → `CsvParseService::parse()` → `validateHeaders()` → foreach строк: `inTransaction( fn() => $provider->importRow( $row, $ctx ) )` в try/catch (`InvalidArgumentException` / `DomainException` → `errors[№]`; иначе счётчики created/skipped) → диспатч сводного лог-события (Шаг 9) → return report.
- [ ] **`ImportServiceBootstrap`** — `inc/Services/Import/ImportServiceBootstrap.php`, implements `ServiceInterface`: в `register()` регистрирует 3 провайдера в реестре (зеркало `ExportServiceBootstrap`). Добавить в `Init::getServices()`.

#### Шаг 2 — Дедупликация persons (новые методы поиска)

⚠️ `PersonService::createOrFindBy()` дедуплицирует **только по `doc_number_hash`**. В CSV прошлых лет паспортных данных нет, а пустой `doc_number` не хешируется (`buildDocumentData` пропускает пустые значения) — каждый повторный запуск создавал бы дубликаты persons.

- [ ] **`PersonRepository::findByNameAndBirthDate( string $lastName, string $firstName, ?string $middleName, ?string $birthDate, bool $isStudent ): ?PersonDTO`** — `inc/Repositories/WPDBRepositories/PersonRepository.php`: поиск по ФИО (+ дата рождения, если задана) и `is_student`.
- [ ] **`PersonImportResolver`** — `inc/Services/Import/PersonImportResolver.php` (readonly), метод `resolve( PersonInputDTO $input ): ?int`. Порядок поиска: `doc_number_hash` (если в CSV есть документ) → `email_hash` (`PersonService::findByEmailHash`) → ФИО + дата рождения → `null`. Используется провайдерами Students и Parents **перед** созданием person.

#### Шаг 3 — `PersonAccountService`: выделение создания WP-учёток из `EnrollmentService`

Блок создания WP-пользователя (`EnrollmentService::enroll()`, ~строки 182–267) дублируется для ученика и родителя и целиком нужен импорту. Выделить, чтобы не копипастить:

- [ ] **`AccountResultDTO`** — `inc/DTO/Person/AccountResultDTO.php` (readonly): `int $userId`, `string $login`, `string $password`, `bool $created`.
- [ ] **`PersonAccountService`** — `inc/Services/Person/PersonAccountService.php` (readonly):
	- `ensureAccount( int $personId, UserRole $role, string $email, string $login = '', string $plainPassword = '', bool $regeneratePassword = false ): AccountResultDTO`;
	- логика: у person есть `wpUserId` → вернуть его (пароль трогать только при `$plainPassword` / `$regeneratePassword` — через `setFromPlain()` / `generateAndSet()`) → иначе `UserManager::findByEmail()` → иначе `UserManager::create()` (login: переданный → email → `{role}_{personId}`; пароль: переданный → `PasswordGeneratorService::generatePlain()`), затем `storeEncrypted()`, `PersonRepository::setWpUser()` + `UserManager::setPersonId()`, диспатч `LogEvent::UserCreated` (`EntityChangedEvent`).
- [ ] **Рефакторинг `EnrollmentService::enroll()`** — заменить оба inline-блока на `ensureAccount()` (ученик: `login/password` из `StudentDataDTO`; родитель: `regeneratePassword = true`). Welcome-письма остаются в `EnrollmentService`. Поведение не меняется.

Для импорта семантика: учётка уже существует → пароль **не** трогаем (`regeneratePassword = false`).

#### Шаг 4 — `GroupsImportProvider`

Формат CSV: `Предмет;Период;Название;Преподаватель (email);Расписание` (преподаватель и расписание — опциональны, `groups.teacher_id` nullable).

- [ ] **`GroupsRepository::findByNameSubjectPeriod( string $name, string $subjectKey, string $periodId ): ?object`** — `inc/Repositories/WPDBRepositories/GroupsRepository.php`: поиск дубля группы.
- [ ] **`GroupsImportProvider`** — `inc/Services/Import/GroupsImportProvider.php`:
	- `headers()` — 5 колонок выше;
	- `importRow()`: резолв предмета (`SubjectRepository::getByKey()`, fallback — по названию из `getAll()`), периода (`AcademicPeriodRepository` — по ID, fallback — по названию), преподавателя (`UserManager::findByEmail()`, опционально); дубль по `findByNameSubjectPeriod()` → `skipped`; иначе `GroupsRepository::create()`.
- [ ] Регистрация в `ImportServiceBootstrap`.

#### Шаг 5 — `StudentsImportProvider` (основной)

Одна строка = ученик + родитель + группа + договор:

| Колонка CSV | Куда пишется |
|---|---|
| Фамилия / Имя / Отчество | `persons` ученика + снапшот в `student_records` |
| Дата рожд. | `persons.birth_date` |
| Класс / Школа | `persons.grade / school` + снапшот |
| Email / Телефон | `person_documents` ученика (`PiiCryptoService`: enc + hash) |
| Логин / Пароль | WP-учётка ученика (роль `FSStudent`); пусто → генерируются |
| Родитель: Фамилия / Имя / Отчество | `persons` родителя |
| Родитель: Email | `person_documents` родителя + логин учётки (роль `FSParent`) |
| Родитель: Телефон | `person_documents` родителя |
| Предмет / Группа / Период | резолв или создание `groups` (как Шаг 4) |
| № договора / Дата договора | `student_records.contract_no / contract_date` |
| Дата зачисления (опц.) | `student_records.enrolled_at`; пусто → `ClockInterface::now()` |

- [ ] **`StudentsImportProvider`** — `inc/Services/Import/StudentsImportProvider.php`. `importRow()` по шагам:
	1. резолв предмета и периода — не найден → ошибка строки;
	2. группа: `GroupsRepository::findByNameSubjectPeriod()` → иначе `create()`;
	3. родитель: `PersonImportResolver::resolve()` → иначе `PersonService::createOrFindBy( PersonInputDTO, isStudent: false )`;
	4. учётка родителя: `PersonAccountService::ensureAccount( ..., UserRole::FSParent, email родителя )`;
	5. ученик: `PersonImportResolver::resolve()` → иначе `createOrFindBy( ..., isStudent: true )`;
	6. учётка ученика: `ensureAccount( ..., UserRole::FSStudent, login/password из CSV )`;
	7. `StudentRecordRepository::existsActive( $studentId, $groupId )` → `skipped`;
	8. `StudentRecordRepository::create()`: снапшоты ФИО/школы/класса из CSV, `contract_no/date`, `status = 'active'`, `enrolled_at` из CSV или `now()`, `enrolled_by_user_id = $ctx->actorId`;
	9. диспатч `LogEvent::StudentEnrolled` (`EnrollmentStatusEvent`, `AuditAction::EnrollStudent`).
	Welcome-письма не отправляются; `consents` / `applications` не создаются.
- [ ] Регистрация в `ImportServiceBootstrap`.

#### Шаг 6 — `ParentsImportProvider`

Формат CSV: `Фамилия;Имя;Отчество;Email;Телефон;Логин;Пароль`. Создаёт только person + person_documents + WP-учётку (`FSParent`); связь с учениками появится при импорте учеников (резолвер найдёт родителя по email).

- [ ] **`ParentsImportProvider`** — `inc/Services/Import/ParentsImportProvider.php`: `headers()` + `importRow()` = шаги 3–4 из Шага 5; дубль по резолверу → `skipped`.
- [ ] Регистрация в `ImportServiceBootstrap`.

#### Шаг 7 — AJAX-хуки и колбэки

- [ ] **`AjaxHook`** — `inc/Enums/AjaxHook.php`: cases `ImportGroups = 'import_groups'`, `ImportStudents = 'import_students'`, `ImportParents = 'import_parents'`.
- [ ] **`ImportCallbacks`** — `inc/Callbacks/ImportCallbacks.php` (extends `BaseController`, `use Authorizer`, `use Sanitizer`): методы `ajaxImportGroups()`, `ajaxImportStudents()`, `ajaxImportParents()`:
	1. `$this->authorize( Nonce::Manager, Capability::Admin )` (как у экспорта);
	2. валидация `$_FILES['file']`: `UPLOAD_ERR_OK`, расширение `.csv`, лимит размера;
	3. `$dryRun = $this->sanitizeBool( $_POST['dry_run'] ?? false )`;
	4. `$report = $this->importService->run( ImportTarget::X, $_FILES['file']['tmp_name'], $dryRun )`;
	5. `$this->success( $report->toArray() )`; доменные исключения → `$this->error()`.
- [ ] **`ImportController`** — `inc/Controllers/ImportController.php` (implements `ServiceInterface`): в `register()` три `add_action( AjaxHook::ImportX->action(), ... )` → `ImportCallbacks`. Добавить в `Init::getServices()` (вместе с `ImportServiceBootstrap` из Шага 1).

#### Шаг 8 — JS и UI

- [ ] **`import-csv.js`** — `src/js/admin/services/import-csv.js` (jQuery object pattern: `init()`, `bindEvents()`): file-input + чекбокс «Только проверить (dry-run)»; отправка `FormData` (`processData: false, contentType: false`); action из `fs_lms_vars.ajax_actions.importStudents` / `importParents` / `importGroups`, nonce `Manager`; рендер отчёта: created/skipped + список ошибок «строка N: сообщение».
- [ ] **Кнопки «Импорт CSV»** — рядом с кнопками экспорта на табах `groups`, `userlist-2-students`, `userlist-3-parents` (PHP-шаблоны табов).
- [ ] Инициализация в `admin.js` с guard по селектору.
- [ ] Стили отчёта — только SCSS-компонент с токенами из `_variables.scss`; без инлайна.

#### Шаг 9 — Логирование импорта

- [ ] Per-entity события уже диспатчатся из сервисов: `LogEvent::UserCreated` (Шаг 3), `LogEvent::StudentEnrolled` (Шаг 5) — каналы EntityAudit / EnrollmentAudit заполняются как при обычном зачислении.
- [ ] **Сводка файла** — новый case `LogEvent::CsvImported` + диспатч из `ImportService::run()` после цикла (payload: target, created, skipped, кол-во ошибок, actor); подписчик канала EntityAudit пишет в `entity_audit_log` (`operation = 'import'`, `entity_type` = цель импорта, `old_label` = краткая сводка). OCP: шина и остальные каналы не трогаются.

#### Шаг 10 — Тесты

- [ ] `CsvParseService`: BOM; разделители `;` и `,`; cp1251; недостающие заголовки → исключение.
- [ ] `PersonImportResolver`: порядок doc → email → ФИО+ДР; пустой документ не матчится.
- [ ] `StudentsImportProvider`: повторный импорт того же файла → все строки `skipped`; `existsActive` → `skipped`; несуществующий предмет → ошибка строки, остальные строки импортируются.
- [ ] `ImportService`: ошибка в строке N не прерывает файл; dry-run не пишет в БД; отчёт содержит номера строк.

#### Порядок реализации

1. **Шаг 1** — инфраструктура (enum, контракт, DTO, парсер, реестр, оркестратор, bootstrap)
2. **Шаг 2–3** — дедупликация + `PersonAccountService` (с рефакторингом `EnrollmentService`)
3. **Шаг 4 → 5 → 6** — провайдеры: группы → ученики → родители
4. **Шаг 7–8** — AJAX и JS/UI
5. **Шаг 9–10** — логирование и тесты (параллельно с 7–8)

---

## Тесты

---

**Unit: `EnrollmentService`**

- `enroll()` бросает `InvalidArgumentException`, если заявка не найдена
- `enroll()` бросает `DomainException`, если статус заявки ≠ `Enrolling`
- `enroll()` бросает `DomainException`, если email родителя занят другим WP-пользователем
- `enroll()` бросает `DomainException`, если ученик уже активен в группе (`existsActive`)
- повторное зачисление отчисленного: `studentPersonId` задан, `expelledAt !== null` → вызывается `personRepository->update(id, ['expelled_at' => null])`
- поиск существующего ученика по `doc_number_hash`, когда `studentPersonId === null` → новый person НЕ создаётся
- новый ученик + новый родитель → `createOrFindBy` вызван дважды с корректными DTO
- guardian найден по `parentPersonId` → `createOrFindBy` для родителя не вызывается

---

**Unit: `EnrollmentService::restoreFromArchive()`**

- восстановление с родителем (`$withParent = true`) — вызывается `selectExistingParent()`
- восстановление без родителя (`$withParent = false`) — `selectExistingParent()` не вызывается
- soft-deleted person ученика → `expelled_at` очищается, дубликат не создаётся
- `birth_date` и документные поля подтягиваются из `PersonDocumentsRepository`
- `$record->parentPersonId === null` при `withParent = true` → `InvalidArgumentException`

---

**Unit: `EnrollmentService::selectExistingParent()` / `removeParentAssignment()`**

- статус заявки остаётся `PendingParent`, join-код ротируется
- привязка к несуществующей заявке / родителю → ошибка

---

**Unit: `PersonService`**

- `createOrFindBy()` возвращает существующий `personId` при совпадении hash
- найденный person отчислён (`expelledAt !== null`) → `expelled_at` очищается
- person не найден → создаётся новый + запись в `person_documents`

---

**Unit: `TaskPublishValidator`**

- обязательная таксономия без термов → возвращает строку ошибки из `getBlockingError()`
- обязательная таксономия с термами, но не выбрана → возвращает строку ошибки
- обязательная таксономия выбрана → `getBlockingError()` возвращает `null`
- шаблон с обязательным полем `task_answer`, поле пустое → `getSoftError()` возвращает строку
- шаблон без поля `task_answer` → `getSoftError()` возвращает `null`
- неизвестный `templateId` → `getSoftError()` возвращает `null`
- `findEmptyRequired()` возвращает только обязательные таксономии с 0 термов

---

**Интеграционный: `PersonRepository`**

- `find()` НЕ возвращает person с `expelled_at IS NOT NULL`
- `findIncludingDeleted()` возвращает отчисленного person
- `softDelete()` выставляет `expelled_at`
- `findDeletedOlderThan()` корректно фильтрует по дате

---

**Интеграционный: `StudentRecordRepository`**

- `existsActive()` — активная запись возвращает `true`
- `existsActive()` — отчисленная запись (`student_records.expelled_at`) возвращает `false`
- `existsActive()` — другая группа возвращает `false`

---

**Интеграционный: полный цикл зачисления**

1. Заявка → `enroll()` → строки созданы в `persons`, `person_documents`, `student_records`
2. WP-пользователь создан для ученика и родителя
3. Consent записан
4. Откат транзакции при исключении внутри `inTransaction` → ни одной строки в таблицах не осталось
5. Отчисление → повторное зачисление того же ученика → дубликат person НЕ создан

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

---

## Рефакторинг: соответствие архитектуре и SOLID (план)

> Итог аудита (июнь 2026). Сгруппировано по типу нарушения; внутри — конкретные файлы. Порядок выполнения: 1 → 7 (от механических правок к структурным).

### 1. Санитизация — только через трейт `Sanitizer`

Сырые WP-функции вместо методов трейта:

- [x] `inc/Callbacks/AdminCallbacks.php` (~15 вызовов `sanitize_key`/`sanitize_text_field` на `$_GET`-фильтрах логов) — в трейт `Sanitizer` добавить методы чтения из `$_GET` (`sanitizeGetKey()`, `sanitizeGetText()`), перевести.
- [x] `inc/Callbacks/Subject/SubjectValidationCallbacks.php:74` (`sanitize_key`), `inc/Callbacks/Subject/SubjectDataCallbacks.php:113` (`absint`), `inc/Callbacks/Settings/EmailTemplateSettingsCallbacks.php:67` (`wp_kses_post`), `inc/Callbacks/Settings/ConsentSettingsCallbacks.php:131` — заменить на `sanitizeKey()`/`sanitizeInt()`/`sanitizeText()`/`sanitizeHtml()`.
- [ ] `inc/Callbacks/StudentGroupCallbacks.php:88,94,95` — значения из JSON-массива (не суперглобалы), трейт не применим напрямую.
- [x] `inc/Callbacks/ApplicationCallbacks.php:344` (`sanitize_email`) — добавить `sanitizeEmail()` в трейт, использовать.
- [x] `inc/Callbacks/EnrollmentCallbacks.php:641` — `sanitize_text_field($_SERVER['REMOTE_ADDR'])` + `current_time()` вручную → использовать `RequestContextProvider::requestContext()` и `ClockInterface` (см. также п. 4: эта запись в `pii_access_log` идёт мимо writer'а напрямую в репозиторий).
- [x] Контроллеры, парсящие `$_GET` сами: `TaskCreationController.php:55`, `BoilerplatePageController.php:56-59,126`, `Enqueue.php:72,338` — добавить `use Sanitizer;`, заменить прямые `$_GET` на `sanitizeText('key', 'GET')`. `PiiController.php:160` — использует `get_query_var()`, изменений не требует.
- [x] `inc/Services/Subject/SubjectImportService.php` — документированное исключение: импорт JSON не является HTTP-входом, сырые вызовы допустимы.

### 2. Авторизация — только `Authorizer::authorize()` / `Nonce::verify()`

- [x] `inc/Callbacks/EnrollmentCallbacks.php:84,120` — `current_user_can()` → `$this->requireCap(...)` (новый метод Authorizer трейта для страниц).
- [x] `inc/Callbacks/AdminCallbacks.php:185` — то же.
- [x] `inc/Callbacks/Person/PersonViewCallbacks.php:208,227` — то же (239 — условное чтение PII, допустимо, но вынести в `PersonReader`).
- [x] `inc/Controllers/MetaBoxController.php:152` — `wp_verify_nonce` + `current_user_can('edit_post')` в `save_post` — не AJAX, допустимо, но обернуть в хелпер трейта (например `Authorizer::authorizePostSave()`), чтобы правило «не звать WP напрямую» не имело исключений.

### 3. Данные — только DTO, не массивы

- [x] `AuditLogRepository` / `EnrollmentAuditLogWriter` — писать через `AuditLogInputDTO`, убрать `create(array)`.
- [x] `ApplicationRepository::create(array)`, `StudentRecordRepository::create(array)`, `PersonRepository::create(array)`, `ConsentRepository::create(array)` — Input-DTO: `ApplicationRecordInputDTO`, `StudentRecordInputDTO`, `PersonRecordInputDTO`, `ConsentInputDTO`. Места вызова обновлены.
- [x] `EnrollmentService::restoreFromArchive()/selectExistingParent()/removeParentAssignment()` → `RestoreResultDTO`, `ParentAssignmentResultDTO`, `RemoveParentResultDTO`.
- [x] `UserManager::create(array)` → `UserInputDTO`.

### 4. Enum вместо строковых констант

- [x] `inc/Controllers/AuthLogController.php:64,75,86` — `'login'`, `'login_failed'`, `'password_reset'` → `AuthAction::*->value`; bool `$success` → `AuthResult`.
- [x] `inc/Callbacks/ApplicationCallbacks.php:208,267` — `'otp_sent'`, `'otp_verified'` → `AuthAction::*`.
- [x] `AuthLogWriter::record()` — сигнатуру перевести на `AuthAction` + `AuthResult` вместо `string`/`bool`.
- [x] `EnrollmentAuditLogWriter::record(string $action, string $targetType, ...)` — принимать `AuditAction` и enum типа цели (новый `AuditTargetType`: `StudentRecord|Application|Person|User`), строки `'student_record'`/`'application'`/`'user'` разбросаны по подписчикам и сервисам.
- [x] Статусы `'success'`/`'failed'` в `EmailLogWriter` → enum `EmailStatus` (+ использовать в `logs-6-email.php` для badge).
- [x] `templates/admin/components/tabs/subject-tabs/subject-4-taxonomies.php:6` — `$display_labels` → enum `TaxonomyDisplayType` c `label()`.
- [x] `ajaxRevealUserCredentials` (`EnrollmentCallbacks.php:639-640`) — `'login,password'`, `'admin_reveal_credentials'` → `PiiField::Login/Password->value`, новый `PiiAccessReason` enum. Также закрыт `'student_data'`/`'application_review'` (строка 144).

### 5. JS: убрать `alert()`/`confirm()` (см. basic_doc «Система уведомлений»)

- [x] `src/js/admin/services/logs-table.js:64,69` — `alert` → `AlertModal.show`.
- [x] `src/js/admin/services/consent-settings.js:31,115,118` — `confirm` → `ConfirmModal.confirm`, `alert` → `AlertModal.show`.
- [x] `src/js/admin/modals/select-parent-modal.js:126,132,137,151,156` — `alert`/`confirm` → `AlertModal.show` / `ConfirmModal.confirm`.
- [x] `src/js/admin/services/person-detail.js:189` — `confirm` → `ConfirmModal.confirm`.
- [x] `src/js/admin/modals/alert-modal.js:19` — нативный `alert` оставить только как fallback при отсутствии шаблона (допустимо, комментарий уже есть).
- [x] Включить ESLint-правило `no-alert` в error (сейчас глушится `eslint-disable`).

### 6. Логирование: добить миграцию на шину и удалить `AuditService`

Осталось мигрировать на `LogEvent::dispatch` (после коммита транзакций):

- [x] `ConsentService` (3 вызова `auditService`) → события `ConsentChanged`/новые.
- [x] `PersonService` (2: `UpdatePerson`, `PiiDeletionRequested`).
- [x] `PasswordGeneratorService` (2: `PasswordGenerated`, `PasswordSet`) — убрать зависимость от `AuditService`.
- [x] `RetentionService` (1: `anonymize_person` — строка! → case в `AuditAction` или `EntityAudit`).
- [x] `RecoveryService` (1: `recovery_completed` — строка! → case).
- [x] `SubjectDeletionCascadeHandler`, `PeriodDeletionCascadeHandler` — `AuditAction::HardDelete*` события уйти в `deletion_log`/`EntityAudit`.
- [x] Callbacks: `ApplicationCallbacks`, `EnrollmentCallbacks`, `ExpulsionCallbacks` — снять прямые инъекции `AuditService`.
- [x] `EnrollmentCallbacks.php:635` — прямой `$this->piiAccessLog->create(...)` (мимо writer'а и шины) → `dispatch(LogEvent::PiiRevealed, ...)`.
- [x] **Удалить `inc/Services/AuditService.php`** и почистить стейл-комментарии (`ExpulsionService.php:36`, `RequestContextProvider.php`, `RequestContextDTO.php`).

### 7. Интерфейсы и структура

**Удалить:**

- [x] `RepositoryInterface` — пустой маркер, реализован только в 5 из ~15 репозиториев (непоследовательно), ни одного type-hint на него нет. Удалить + снять `implements` из 5 файлов.
- [x] `MenuBuilderInterface` — одна реализация, type-hint можно заменить на класс `SubjectsMenuBuilder`. YAGNI; вернуть интерфейс, когда появится второй билдер. Обновить CLAUDE.md (раздел Contracts).

**Оставить (осознанно):** `ClockInterface` (тестовый seam, широко используется), `CaptchaProviderInterface` (strategy-seam для будущих провайдеров, Null-объект), `MigrationInterface`, `EmailTemplateInterface` (2 реализации), `LogEventInterface`/`LogEventDispatcherInterface`, `CsvExportProviderInterface`, `AuthStrategyInterface`, `ServiceInterface`, `FieldInterface`.

**`FieldInterface::sanitize()` — оставить.** Полиморфная санитизация по типу поля (текст/HTML/URL) — корректный Strategy; без неё `MetaBoxController::save()` получит `match` по типам полей (нарушение OCP). Доработать:
- [x] Типизировать контракт: `declare(strict_types=1)`, `render(\WP_Post $post, ...)`, `sanitize(mixed $value): mixed`.
- [x] Реализации делегируют в методы трейта `Sanitizer` (единая точка правды), а не зовут `sanitize_text_field`/`wp_kses_post` напрямую (`InputField.php:56`, `ConditionField.php:76`).
- [x] `MetaBoxController::save()` — цикл санитизации + `update_post_meta` вынесены в `MetaBoxManager::saveFields()`; `MetaBoxManager` инжектирован в `MetaBoxController`.

**Группировка директорий:**

Принцип (по сложившейся конвенции проекта): первый уровень — слой, второй — домен или механика; подпапка заводится только когда в папке 10+ файлов с явной общей осью. Папки на 1–2 файла — шум, хуже плоского списка.

- [x] `inc/Controllers/` (33 файла в корне) → подпапки по **механике**: `Controllers/Subscribers/` (7 `*Subscriber` + `AuthLogController` + `PostEntityAuditController` — все девять суть подписчики лог-каналов с одинаковым устройством), `Controllers/Pages/` (`AuthPageController`, `ApplyPageController`, `BoilerplatePageController`, `TaskPageController` — публичные страницы через `template_include`/rewrite). Оставшиеся ~20 — «один контроллер на домен», доменные подпапки дали бы по одному файлу — оставить в корне.
- [x] `inc/Callbacks/` (13 файлов в корне + готовые `Subject/`, `Person/`, `Settings/`) — докатить уже существующую доменную конвенцию: `Callbacks/Enrollment/` (`ApplicationCallbacks`, `EnrollmentCallbacks`, `ExpulsionCallbacks`, `RecoveryCallbacks`, `DeletionCallbacks` — жизненный цикл зачисления), `Callbacks/Task/` (`TaskCreationCallbacks`, `BoilerplateCallbacks`, `TemplateCallbacks`, `TemplateManagerCallbacks`). Остаток (`AuthCallbacks`, `AdminCallbacks`, `LogsCallbacks`, `StudentGroupCallbacks`) — 4 файла, оставить в корне. Обновить раздел «Callbacks subdirectories» в CLAUDE.md.
- [x] `inc/DTO/` (10 корневых) → `AuditLogDTO` → `DTO/Log/`; `ParentDataDTO`, `ParentSubmissionInputDTO`, `RepresentativeInputDTO`, `UserDTO` → `DTO/Person/`; `CsvColumn` → `DTO/Export/`; `EmailTemplateData` → `DTO/Email/`; `AcademicPeriodDTO` → `DTO/Settings/`.
- [x] `inc/Services/` корень (15 файлов) → `PiiCryptoService`, `PasswordGeneratorService`, `RateLimitService` → `Services/Security/`; `CsvExportService` → `Services/Export/`; `ExpulsionService` → `Services/Enrollment/`; `WpClock` → `Services/Shared/`; `CaptchaService` → `Services/CaptchaProviders/` (переименовать в `Services/Captcha/`).
- [x] `inc/Repositories/WPDBRepositories/` (15 файлов) → подпапка `Log/` для 9 `*LogRepository` (`AuditLog`, `AuthLog`, `ConsentChangeLog`, `DataChangeLog`, `DeletionLog`, `EmailLog`, `EntityAuditLog`, `ExportLog`, `PiiAccessLog`) — зеркалит `Services/Log/` и `DTO/Log/`. Первый уровень (`OptionsRepositories` / `WPDBRepositories` — по типу хранилища) корректен, не трогать.
- [x] CLAUDE.md, раздел «JS Architecture», устарел: фактическая структура — `admin/{managers,modals,modules,services}`, авто-лоадер `ui.js` грузит `../modals`, а не `components/`. Привести документацию в соответствие (или переименовать `modals` → `components` — решить до правки).

**Оставить плоскими (осознанные решения, не возвращаться):**

- `inc/Enums/` (35) — enum'ы суть листовые типы, на них ссылаются все слои; перенос = массовая правка `use` при нулевом выигрыше; домен уже закодирован в имени (`Auth*`, `Log*`, `Export*`); ищутся всегда по имени, а не обходом папки.
- `inc/Contracts/` (15) — плоская папка интерфейсов — стандарт.
- `inc/Managers/` (12), `inc/Registrars/` (5), `inc/Shared/`, `inc/Core/` — не доросли до группировки / уже структурированы.

**Регламент переноса (PSR-4: папка = namespace ⇒ перенос меняет FQCN и все `use`):**

1. Один коммит = одна папка; никаких функциональных правок в том же коммите — поведение не меняется вообще.
2. Перенос через IDE (Move Class — обновляет namespace и все ссылки) либо `git mv` + поиск по старому FQCN по всему репо (включая строки `::class`).
3. После переноса проверить `Init::getServices()` и `Container` (классы перечислены через `::class` — IDE обновит, но убедиться), затем `composer dump-autoload`.
4. После каждой папки — обновить CLAUDE.md (таблица слоёв, списки подпапок).
5. Делать между фичами; ветки с открытыми правками в перемещаемых файлах — смержить до переноса (иначе конфликты на ровном месте).

**Инлайн-стили (CSS Rules):**

- [x] Инлайн-стили убраны из всех `templates/admin/**` (logs-tabs, settings-tabs, subject-tabs, userlist-tabs, модалки, boilerplates, person-detail). Ширины — классы из `_widths.scss` (`tw-*`, `input-width-*`, `max-tw-50`); отступы/текст — утилиты `_utilities.scss` (`fs-mt-*`, `fs-mb-*`, `fs-text-*`, `fs-code-sm`, `fs-flex-row`); dashicons — единый `fs-dashicon` (+`--muted`/`--danger`); компонент согласий — `_consents.scss`. JS-инлайн (`css('transform')`, `css('color')`) переведён на классы `is-open`/`is-success`/`is-error`. Не тронуты: `templates/emails/*` (инлайн обязателен для почтовых клиентов) и 2 success-блока во frontend (`apply.php`, `join.php` — связаны с `style.display` в JS).
- Привести все view файлы в папке src/admin/components/tabs к единой структуре.
- Найти мертвый код (неиспользуемые классы) в JS
- [x] Провести анализ _variables.scss и удалить дубликаты: `$wp-admin-blue`/`$wp-admin-red`/`$wp-admin-gray`/`$dashboard-tabs-bg-inactive`/`$table-row-border`/`$table-row-add-bg`/`$dashboard-tabs-transition` теперь алиасы базовых токенов вместо повторённых hex-значений. Ширины в шаблонах — только классы из `_widths.scss`.

---

## Багфикс
1. Избавиться от таблицы Удаление (wp_fs_lms_deletion_log). Перенести удаления сущностей в таблицу изменения (crud) сущностей (wp_fs_lms_audit_log). Поскольку удаление предмета или удаление ученика относится к CRUD операциям с сущностями.
2. Поправить таблицу логирования Экспорта (в бд и админке): должен фиксироваться и уже существующий импорт предмета и добавленный в будущем импорт учеников/родителей/групп/учебных периодов (можно добавить тип операции: Импорт или Экспорт).
3. ~~EntityChangedEvent->$entityId принимает целочисленное значение идентификатора сущности. Но у объектов из wp_options (предметы, таксономии и т.д.) нет целочисленного ID, как у сущностей из самостоятельных таблиц. Нужно исправить это для работы как с сущностями таблиц wp_fs_lms_... так и wp_options (например, принимать слаг)~~ ✅ Исправлено: `int|string` в EntityChangedEvent, EntityAuditLogWriter, EntityAuditLogInputDTO, DDL миграции (`varchar(100)`), все callers обновлены.
