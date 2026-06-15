<?php

declare( strict_types=1 );

namespace Inc\Services\Import;

use DateTime;
use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Enrollment\StudentRecordInputDTO;
use Inc\DTO\Import\ImportContextDTO;
use Inc\DTO\Import\ImportRowResultDTO;
use Inc\DTO\Log\Events\EnrollmentStatusEvent;
use Inc\DTO\Person\PersonInputDTO;
use Inc\Enums\AuditAction;
use Inc\Enums\EnrollmentStatus;
use Inc\Enums\LogEvent;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Person\PersonService;
use InvalidArgumentException;

/**
 * Class StudentRowImporter
 *
 * Импортирует одну строку CSV: каскадно создаёт группу, persons ученика и
 * родителя (+ person_documents с шифрованием PII) и запись student_records.
 *
 * ### Особенности
 *
 * - **WP-учётки не создаются** — появятся позже при зачислении из архива.
 * - **Статус записи** определяется по строке: есть дата/причина отчисления →
 *   запись в архиве (`finished`/`transferred`/`expelled`), иначе `active`.
 * - **Идемпотентность** — дубль по ученик+группа+договор → skipped.
 * - **Dry-run** — все резолвы/проверки выполняются, запись пропускается.
 *
 * Предмет и период берутся из {@see ImportContextDTO} (выбор в UI), а не из CSV.
 */
readonly class StudentRowImporter {

	private const COL_LAST          = 'Фамилия';
	private const COL_FIRST         = 'Имя';
	private const COL_MIDDLE        = 'Отчество';
	private const COL_BIRTH         = 'Дата рожд.';
	private const COL_GRADE         = 'Класс';
	private const COL_SCHOOL        = 'Школа';
	private const COL_EMAIL         = 'Email';
	private const COL_PHONE         = 'Телефон';
	private const COL_P_LAST        = 'Родитель: Фамилия';
	private const COL_P_FIRST       = 'Родитель: Имя';
	private const COL_P_MIDDLE      = 'Родитель: Отчество';
	private const COL_P_EMAIL       = 'Родитель: Email';
	private const COL_P_PHONE       = 'Родитель: Телефон';
	private const COL_GROUP         = 'Группа';
	private const COL_CONTRACT_NO   = '№ договора';
	private const COL_CONTRACT_DATE = 'Дата договора';
	private const COL_ENROLLED_AT   = 'Дата зачисления';
	private const COL_EXPELLED_AT   = 'Дата отчисления';
	private const COL_REASON        = 'Причина отчисления';

	/**
	 * @param GroupsRepository            $groups          Репозиторий групп (find-or-create)
	 * @param PersonImportResolver        $personResolver  Дедуп persons по doc/email/ФИО
	 * @param PersonService               $personService   Создание persons + person_documents
	 * @param StudentRecordRepository     $studentRecords  Дедуп и создание записей
	 * @param ExpulsionResolver           $expulsionResolver Причина → статус
	 * @param ClockInterface              $clock           Текущее время
	 * @param LogEventDispatcherInterface $logEvents       Шина событий логирования
	 */
	public function __construct(
		private GroupsRepository            $groups,
		private PersonImportResolver        $personResolver,
		private PersonService               $personService,
		private StudentRecordRepository     $studentRecords,
		private ExpulsionResolver           $expulsionResolver,
		private ClockInterface              $clock,
		private LogEventDispatcherInterface $logEvents,
	) {}

	/**
	 * Обязательные колонки CSV (валидация заголовков файла).
	 *
	 * @return string[]
	 */
	public function requiredHeaders(): array {
		return array(
			self::COL_LAST,
			self::COL_FIRST,
			self::COL_GROUP,
			self::COL_CONTRACT_NO,
			self::COL_P_LAST,
			self::COL_P_FIRST,
		);
	}

	/**
	 * Импортирует одну строку.
	 *
	 * @param array<string, string> $row Ассоц-массив «заголовок → значение»
	 * @param ImportContextDTO       $ctx Контекст запуска
	 *
	 * @return ImportRowResultDTO
	 *
	 * @throws InvalidArgumentException При отсутствии обязательных значений
	 */
	public function import( array $row, ImportContextDTO $ctx ): ImportRowResultDTO {
		$get = static fn( string $key ): string => trim( (string) ( $row[ $key ] ?? '' ) );

		$lastName   = $get( self::COL_LAST );
		$firstName  = $get( self::COL_FIRST );
		$groupName  = $get( self::COL_GROUP );
		$contractNo = $get( self::COL_CONTRACT_NO );
		$pLastName  = $get( self::COL_P_LAST );
		$pFirstName = $get( self::COL_P_FIRST );

		$this->requireValues( array(
			self::COL_LAST        => $lastName,
			self::COL_FIRST       => $firstName,
			self::COL_GROUP       => $groupName,
			self::COL_CONTRACT_NO => $contractNo,
			self::COL_P_LAST      => $pLastName,
			self::COL_P_FIRST     => $pFirstName,
		) );

		$studentEmail = $get( self::COL_EMAIL );
		$parentEmail  = $get( self::COL_P_EMAIL );

		$studentInput = new PersonInputDTO(
			lastName:   $lastName,
			firstName:  $firstName,
			docNumber:  '',
			isStudent:  true,
			middleName: $get( self::COL_MIDDLE ),
			birthDate:  $this->toDate( $get( self::COL_BIRTH ) ) ?? '',
			phone:      $get( self::COL_PHONE ),
			school:     $get( self::COL_SCHOOL ),
			grade:      $get( self::COL_GRADE ),
			email:      '' !== $studentEmail ? $studentEmail : null,
		);

		$parentInput = new PersonInputDTO(
			lastName:   $pLastName,
			firstName:  $pFirstName,
			docNumber:  '',
			isStudent:  false,
			middleName: $get( self::COL_P_MIDDLE ),
			phone:      $get( self::COL_P_PHONE ),
			email:      '' !== $parentEmail ? $parentEmail : null,
		);

		// Резолв существующих сущностей (только чтение)
		$existingGroup = $this->groups->findByNameSubjectPeriod( $groupName, $ctx->subjectKey, $ctx->periodId );
		$groupId       = $existingGroup ? (int) $existingGroup->id : null;
		$studentId     = $this->personResolver->resolve( $studentInput );
		$parentId      = $this->personResolver->resolve( $parentInput );

		// Дедуп записи (если ученик и группа уже известны)
		if ( null !== $studentId && null !== $groupId
			&& $this->studentRecords->existsByContract( $studentId, $groupId, $contractNo ) ) {
			return ImportRowResultDTO::skipped( 'Запись с таким договором уже существует.' );
		}

		if ( $ctx->dryRun ) {
			return ImportRowResultDTO::created( 'Будет создано (dry-run).' );
		}

		$now = $this->clock->now( 'mysql', true );

		if ( null === $groupId ) {
			$groupId = $this->groups->create( array(
				'name'               => $groupName,
				'subject_key'        => $ctx->subjectKey,
				'academic_period_id' => $ctx->periodId,
				'teacher_id'         => null,
				'schedule'           => null,
				'created_at'         => $now,
				'updated_at'         => $now,
			) );
		}

		if ( null === $parentId ) {
			$parentId = $this->personService->createOrFindBy( $parentInput );
		}
		if ( null === $studentId ) {
			$studentId = $this->personService->createOrFindBy( $studentInput );
		}

		[ $status, $reason, $expelledAt, $expelledBy ] = $this->resolveLifecycle( $get, $ctx, $now );

		$recordId = $this->studentRecords->create( new StudentRecordInputDTO(
			studentPersonId:    $studentId,
			parentPersonId:     $parentId,
			status:             $status,
			enrolledAt:         $this->toDateTime( $get( self::COL_ENROLLED_AT ) ) ?? $now,
			createdAt:          $now,
			updatedAt:          $now,
			groupId:            $groupId,
			snapshotLastName:   $lastName,
			snapshotFirstName:  $firstName,
			snapshotMiddleName: $get( self::COL_MIDDLE ) ?: null,
			snapshotSchool:     $get( self::COL_SCHOOL ) ?: null,
			snapshotGrade:      $get( self::COL_GRADE ) ?: null,
			contractNo:         $contractNo,
			contractDate:       $this->toDate( $get( self::COL_CONTRACT_DATE ) ),
			enrolledByUserId:   $ctx->actorId ?: null,
			expelledAt:         $expelledAt,
			expelReason:        $reason,
			expelledByUserId:   $expelledBy,
		) );

		$this->logEvents->dispatch(
			LogEvent::StudentEnrolled,
			new EnrollmentStatusEvent( $ctx->actorId, AuditAction::EnrollStudent, $studentId, $recordId, $groupId )
		);

		return ImportRowResultDTO::created();
	}

	/**
	 * Определяет статус записи и поля отчисления.
	 *
	 * Запись попадает в архив, если задана дата ИЛИ причина отчисления.
	 *
	 * @param callable         $get Геттер значения колонки
	 * @param ImportContextDTO $ctx Контекст
	 * @param string           $now Текущее время (mysql)
	 *
	 * @return array{0:string, 1:?string, 2:?string, 3:?int} [status, reason, expelledAt, expelledBy]
	 */
	private function resolveLifecycle( callable $get, ImportContextDTO $ctx, string $now ): array {
		$expulsion = $this->expulsionResolver->resolve( $get( self::COL_REASON ) );
		$expelDate = $this->toDateTime( $get( self::COL_EXPELLED_AT ) );

		if ( null === $expulsion && null === $expelDate ) {
			return array( EnrollmentStatus::Active->value, null, null, null );
		}

		$status = $expulsion['status'] ?? EnrollmentStatus::Expelled;
		$reason = $expulsion['reason'] ?? null;

		return array(
			$status->value,
			$reason,
			$expelDate ?? $now,
			$ctx->actorId ?: null,
		);
	}

	/**
	 * Бросает исключение, если какое-то обязательное значение пустое.
	 *
	 * @param array<string, string> $values [колонка => значение]
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException
	 */
	private function requireValues( array $values ): void {
		foreach ( $values as $label => $value ) {
			if ( '' === $value ) {
				throw new InvalidArgumentException( "Не заполнена обязательная колонка «{$label}»." );
			}
		}
	}

	/**
	 * Нормализует дату в формат Y-m-d.
	 *
	 * @param string $value Дата в формате Y-m-d / d.m.Y / d/m/Y / d-m-Y
	 *
	 * @return string|null Y-m-d или null
	 */
	private function toDate( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		foreach ( array( 'Y-m-d', 'd.m.Y', 'd/m/Y', 'd-m-Y' ) as $format ) {
			$date   = DateTime::createFromFormat( '!' . $format, $value );
			$errors = DateTime::getLastErrors();
			$clean  = false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] );

			if ( $date instanceof DateTime && $clean ) {
				return $date->format( 'Y-m-d' );
			}
		}

		$timestamp = strtotime( $value );

		return false !== $timestamp ? gmdate( 'Y-m-d', $timestamp ) : null;
	}

	/**
	 * Нормализует дату в datetime (полночь) для колонок типа datetime.
	 *
	 * @param string $value Дата
	 *
	 * @return string|null Y-m-d 00:00:00 или null
	 */
	private function toDateTime( string $value ): ?string {
		$date = $this->toDate( $value );

		return null === $date ? null : $date . ' 00:00:00';
	}
}
