<?php

declare( strict_types=1 );

namespace Inc\Services\Import;

use Closure;
use Inc\Contracts\RowImporterInterface;
use Inc\DTO\Enrollment\StudentRecordInputDTO;
use Inc\DTO\Import\ImportContextDTO;
use Inc\DTO\Person\PersonInputDTO;
use Inc\Enums\Import\ImportColumn;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Shared\Traits\TransactionRunner;
use DateTime;
use InvalidArgumentException;

/**
 * Class AbstractRowImporter
 *
 * Общее для импортёров строки CSV: проверка обязательных значений, разбор дат
 * в человеческих форматах, чтение ученика/родителя из строки и запись «группа +
 * persons + student_records» одной транзакцией.
 *
 * @package Inc\Services\Import
 *
 * Домен строки (какие колонки читать, что создавать, где граница транзакции)
 * остаётся у наследника — {@see StudentRowImporter} и {@see EnrolledStudentRowImporter}.
 */
abstract readonly class AbstractRowImporter implements RowImporterInterface {

	use TransactionRunner;

	/** Поддерживаемые форматы дат в CSV (Y-m-d — канонический). */
	private const DATE_FORMATS = array( 'Y-m-d', 'd.m.Y', 'd/m/Y', 'd-m-Y' );

	/**
	 * Бросает исключение, если какое-то обязательное значение пустое.
	 *
	 * @param array<string, string> $values [колонка => значение]
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException
	 */
	protected function requireValues( array $values ): void {
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
	protected function toDate( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		foreach ( self::DATE_FORMATS as $format ) {
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
	protected function toDateTime( string $value ): ?string {
		$date = $this->toDate( $value );

		return null === $date ? null : $date . ' 00:00:00';
	}

	/**
	 * Читалка ячеек строки: значение колонки без пробелов по краям, отсутствующая — пустая строка.
	 *
	 * @param array<string, string> $row Строка «заголовок → значение»
	 */
	protected function cell( array $row ): Closure {
		return static fn( ImportColumn $col ): string => trim( (string) ( $row[ $col->value ] ?? '' ) );
	}

	/** Ученик из колонок строки (логин и пароль сюда не входят — это учётка, а не персона). */
	protected function studentInput( Closure $get, DocTypeResolver $docTypes ): PersonInputDTO {
		$email = $get( ImportColumn::Email );

		return new PersonInputDTO(
			lastName:   $get( ImportColumn::LastName ),
			firstName:  $get( ImportColumn::FirstName ),
			docNumber:  $get( ImportColumn::DocNumber ),
			isStudent:  true,
			middleName: $get( ImportColumn::MiddleName ),
			docType:    $docTypes->resolve( $get( ImportColumn::DocType ) ),
			birthDate:  $this->toDate( $get( ImportColumn::BirthDate ) ) ?? '',
			inn:        $get( ImportColumn::Inn ),
			phone:      $get( ImportColumn::Phone ),
			school:     $get( ImportColumn::School ),
			grade:      $get( ImportColumn::Grade ),
			email:      '' !== $email ? $email : null,
		);
	}

	/** Родитель из колонок строки; пустой email — null. */
	protected function parentInput( Closure $get, DocTypeResolver $docTypes ): PersonInputDTO {
		$email = $get( ImportColumn::ParentEmail );

		return new PersonInputDTO(
			lastName:      $get( ImportColumn::ParentLastName ),
			firstName:     $get( ImportColumn::ParentFirstName ),
			docNumber:     $get( ImportColumn::ParentDocNumber ),
			isStudent:     false,
			middleName:    $get( ImportColumn::ParentMiddleName ),
			docType:       $docTypes->resolve( $get( ImportColumn::ParentDocType ) ),
			birthDate:     $this->toDate( $get( ImportColumn::ParentBirthDate ) ) ?? '',
			inn:           $get( ImportColumn::ParentInn ),
			address:       $get( ImportColumn::ParentAddress ),
			phone:         $get( ImportColumn::ParentPhone ),
			docIssuedBy:   $get( ImportColumn::ParentDocIssuedBy ),
			docIssuedDate: $this->toDate( $get( ImportColumn::ParentDocIssuedDate ) ) ?? '',
			email:         '' !== $email ? $email : null,
		);
	}

	/**
	 * Транзакция записи: недостающая группа и персоны, затем запись student_records.
	 *
	 * Статус записи и поля отчисления отдаёт `$lifecycle` — вызывается внутри транзакции,
	 * потому что у режима «архив» он зависит от времени и контекста запуска.
	 *
	 * @param callable():array{0:string,1:?string,2:?string,3:?int} $lifecycle [status, reason, expelledAt, expelledBy]
	 *
	 * @return array{0:int,1:int,2:int,3:int} [recordId, studentId, parentId, groupId]
	 */
	protected function persistRecord(
		StudentRecordWriter $writer,
		StudentRecordRepository $records,
		ImportContextDTO $ctx,
		Closure $get,
		PersonInputDTO $student,
		PersonInputDTO $parent,
		string $groupName,
		?int $groupId,
		?int $studentId,
		?int $parentId,
		string $now,
		callable $lifecycle,
	): array {
		return $this->inTransaction(
			function () use ( $writer, $records, $ctx, $get, $student, $parent, $groupName, $groupId, $studentId, $parentId, $now, $lifecycle ): array {
				$groupId   ??= $writer->createGroup( $groupName, $ctx );
				$parentId  ??= $writer->createPerson( $parent );
				$studentId ??= $writer->createPerson( $student );

				[ $status, $reason, $expelledAt, $expelledBy ] = $lifecycle();

				$recordId = $records->create( new StudentRecordInputDTO(
					studentPersonId:    $studentId,
					parentPersonId:     $parentId,
					status:             $status,
					enrolledAt:         $this->toDateTime( $get( ImportColumn::EnrolledAt ) ) ?? $now,
					createdAt:          $now,
					updatedAt:          $now,
					groupId:            $groupId,
					snapshotLastName:   $student->lastName,
					snapshotFirstName:  $student->firstName,
					snapshotMiddleName: $get( ImportColumn::MiddleName ) ?: null,
					snapshotSchool:     $get( ImportColumn::School ) ?: null,
					snapshotGrade:      $get( ImportColumn::Grade ) ?: null,
					contractNo:         $get( ImportColumn::ContractNo ),
					contractDate:       $this->toDate( $get( ImportColumn::ContractDate ) ),
					orderNo:            $get( ImportColumn::OrderNo ) ?: null,
					orderDate:          $this->toDate( $get( ImportColumn::OrderDate ) ),
					enrolledByUserId:   $ctx->actorId ?: null,
					expelledAt:         $expelledAt,
					expelReason:        $reason,
					expelledByUserId:   $expelledBy,
				) );

				return array( $recordId, $studentId, $parentId, $groupId );
			}
		);
	}
}
