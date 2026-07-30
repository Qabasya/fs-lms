<?php

declare( strict_types=1 );

namespace Inc\Services\Import;

use DateTime;
use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\RowImporterInterface;
use Inc\DTO\Enrollment\StudentRecordInputDTO;
use Inc\DTO\Import\ImportContextDTO;
use Inc\DTO\Import\ImportRowResultDTO;
use Inc\DTO\Import\RowCredentialsDTO;
use Inc\DTO\Log\Events\EnrollmentStatusEvent;
use Inc\DTO\Person\PersonInputDTO;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Enums\Import\ImportColumn;
use Inc\Enums\Import\ImportMode;
use Inc\Enums\Log\AuditAction;
use Inc\Enums\Log\LogEvent;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Email\EmailService;
use Inc\Services\Enrollment\AccountProvisioningService;
use Inc\Shared\Traits\TransactionRunner;
use InvalidArgumentException;

/**
 * Class EnrolledStudentRowImporter
 *
 * Импортирует одну строку CSV в режиме «полное зачисление»: как
 * {@see StudentRowImporter}, но запись всегда `active` (колонок отчисления
 * в этом режиме нет) и **создаются WP-учётки** ученика и родителя.
 *
 * ### Особенности
 *
 * - **Единый шаблон CSV** — колонки отчисления (`Дата отчисления`, `Причина
 *   отчисления`) в этом режиме не читаются: запись всегда `active`.
 * - **Логин/пароль ученика — обязательные колонки CSV** (генерации нет);
 *   пустые значения → ошибка строки. Родителю логин = email (обязателен),
 *   пароль генерируется ({@see AccountProvisioningService::provisionParent()}).
 * - **Идемпотентность** — дубль по ученик+группа+договор → skipped,
 *   учётки при этом не трогаются.
 * - **Dry-run** — все резолвы/проверки выполняются; ни записи, ни учёток.
 * - **Транзакция** — только запись (группа+persons+student_records).
 *   Провизия учёток — после COMMIT: `wp_insert_user()` не поддерживает
 *   откат, а его падение (коллизия логина) не должно откатывать
 *   корректную запись — оно превращается в ошибку строки.
 * - **Письмо родителю** (`WelcomeWithCredentials`) — только при
 *   `ctx->sendEmails` (чекбокс в UI, по умолчанию выкл).
 *
 * Предмет и период берутся из {@see ImportContextDTO} (выбор в UI), а не из CSV.
 */
readonly class EnrolledStudentRowImporter implements RowImporterInterface {

	use TransactionRunner;

	/**
	 * @param StudentRecordWriter         $writer         Резолв/создание группы и persons
	 * @param StudentRecordRepository     $studentRecords Дедуп и создание записей
	 * @param DocTypeResolver             $docTypeResolver Тип документа → значение enum
	 * @param AccountProvisioningService  $provisioning   Создание/привязка WP-учёток
	 * @param EmailService                $emailService   Письмо родителю с кредами
	 * @param ClockInterface              $clock          Текущее время
	 * @param LogEventDispatcherInterface $logEvents      Шина событий логирования
	 */
	public function __construct(
		private StudentRecordWriter         $writer,
		private StudentRecordRepository     $studentRecords,
		private DocTypeResolver             $docTypeResolver,
		private AccountProvisioningService  $provisioning,
		private EmailService                $emailService,
		private ClockInterface              $clock,
		private LogEventDispatcherInterface $logEvents,
	) {}

	/**
	 * Обязательные колонки CSV (валидация заголовков файла).
	 *
	 * @return string[]
	 */
	public function requiredHeaders(): array {
		return ImportColumn::required( ImportMode::Enrolled );
	}

	/**
	 * Импортирует одну строку: active-запись + WP-учётки ученика и родителя.
	 *
	 * @param array<string, string> $row Ассоц-массив «заголовок → значение»
	 * @param ImportContextDTO      $ctx Контекст запуска
	 *
	 * @return ImportRowResultDTO
	 *
	 * @throws InvalidArgumentException При отсутствии обязательных значений
	 * @throws \RuntimeException        Коллизия логина/email при создании учётки
	 */
	public function import( array $row, ImportContextDTO $ctx ): ImportRowResultDTO {
		$get = static fn( ImportColumn $col ): string => trim( (string) ( $row[ $col->value ] ?? '' ) );

		$lastName    = $get( ImportColumn::LastName );
		$firstName   = $get( ImportColumn::FirstName );
		$groupName   = $get( ImportColumn::Group );
		$contractNo  = $get( ImportColumn::ContractNo );
		$pLastName   = $get( ImportColumn::ParentLastName );
		$pFirstName  = $get( ImportColumn::ParentFirstName );
		$username    = $get( ImportColumn::Username );
		$password    = $get( ImportColumn::Password );
		$parentEmail = $get( ImportColumn::ParentEmail );

		$this->requireValues( array(
			ImportColumn::LastName->value        => $lastName,
			ImportColumn::FirstName->value       => $firstName,
			ImportColumn::Group->value           => $groupName,
			ImportColumn::ContractNo->value      => $contractNo,
			ImportColumn::ParentLastName->value  => $pLastName,
			ImportColumn::ParentFirstName->value => $pFirstName,
			ImportColumn::Username->value        => $username,
			ImportColumn::Password->value        => $password,
			ImportColumn::ParentEmail->value     => $parentEmail,
		) );

		$studentEmail = $get( ImportColumn::Email );

		$studentInput = new PersonInputDTO(
			lastName:   $lastName,
			firstName:  $firstName,
			docNumber:  $get( ImportColumn::DocNumber ),
			isStudent:  true,
			middleName: $get( ImportColumn::MiddleName ),
			docType:    $this->docTypeResolver->resolve( $get( ImportColumn::DocType ) ),
			birthDate:  $this->toDate( $get( ImportColumn::BirthDate ) ) ?? '',
			inn:        $get( ImportColumn::Inn ),
			phone:      $get( ImportColumn::Phone ),
			school:     $get( ImportColumn::School ),
			grade:      $get( ImportColumn::Grade ),
			email:      '' !== $studentEmail ? $studentEmail : null,
		);

		$parentInput = new PersonInputDTO(
			lastName:      $pLastName,
			firstName:     $pFirstName,
			docNumber:     $get( ImportColumn::ParentDocNumber ),
			isStudent:     false,
			middleName:    $get( ImportColumn::ParentMiddleName ),
			docType:       $this->docTypeResolver->resolve( $get( ImportColumn::ParentDocType ) ),
			birthDate:     $this->toDate( $get( ImportColumn::ParentBirthDate ) ) ?? '',
			inn:           $get( ImportColumn::ParentInn ),
			address:       $get( ImportColumn::ParentAddress ),
			phone:         $get( ImportColumn::ParentPhone ),
			docIssuedBy:   $get( ImportColumn::ParentDocIssuedBy ),
			docIssuedDate: $this->toDate( $get( ImportColumn::ParentDocIssuedDate ) ) ?? '',
			email:         $parentEmail,
		);

		// Резолв существующих сущностей (только чтение)
		$groupId   = $this->writer->resolveGroupId( $groupName, $ctx );
		$studentId = $this->writer->resolvePersonId( $studentInput );
		$parentId  = $this->writer->resolvePersonId( $parentInput );

		$label = sprintf(
			'%s — группа «%s», договор № %s, логин %s',
			$studentInput->fullName(),
			$groupName,
			$contractNo,
			$username
		);

		// Дедуп записи: повторный импорт не задваивает ни записи, ни учётки
		if ( null !== $studentId && null !== $groupId
			&& $this->studentRecords->existsByContract( $studentId, $groupId, $contractNo ) ) {
			return ImportRowResultDTO::skipped( 'Запись с таким договором уже существует.', $label );
		}

		if ( $ctx->dryRun ) {
			return ImportRowResultDTO::created( 'Будет зачислено (dry-run).', null, $label );
		}

		$now = $this->clock->now( 'mysql', true );

		[ $recordId, $studentId, $parentId, $groupId ] = $this->inTransaction(
			function () use ( $groupId, $studentId, $parentId, $groupName, $ctx, $studentInput, $parentInput, $get, $now, $lastName, $firstName, $contractNo ): array {
				$groupId   ??= $this->writer->createGroup( $groupName, $ctx );
				$parentId  ??= $this->writer->createPerson( $parentInput );
				$studentId ??= $this->writer->createPerson( $studentInput );

				$recordId = $this->studentRecords->create( new StudentRecordInputDTO(
					studentPersonId:    $studentId,
					parentPersonId:     $parentId,
					status:             EnrollmentStatus::Active->value,
					enrolledAt:         $this->toDateTime( $get( ImportColumn::EnrolledAt ) ) ?? $now,
					createdAt:          $now,
					updatedAt:          $now,
					groupId:            $groupId,
					snapshotLastName:   $lastName,
					snapshotFirstName:  $firstName,
					snapshotMiddleName: $get( ImportColumn::MiddleName ) ?: null,
					snapshotSchool:     $get( ImportColumn::School ) ?: null,
					snapshotGrade:      $get( ImportColumn::Grade ) ?: null,
					contractNo:         $contractNo,
					contractDate:       $this->toDate( $get( ImportColumn::ContractDate ) ),
					orderNo:            $get( ImportColumn::OrderNo ) ?: null,
					orderDate:          $this->toDate( $get( ImportColumn::OrderDate ) ),
					enrolledByUserId:   $ctx->actorId ?: null,
				) );

				return array( $recordId, $studentId, $parentId, $groupId );
			}
		);

		// Провизия учёток — после COMMIT: падение wp_insert_user не откатывает запись
		$studentCreds = $this->provisioning->provisionStudent( $studentId, $studentInput, $username, $password );
		$parentCreds  = $this->provisioning->provisionParent( $parentId, $parentInput );

		if ( $ctx->sendEmails ) {
			$this->emailService->sendWelcomeWithCredentials(
				$parentCreds->userId,
				$parentCreds->password,
				array(
					'student_full_name'  => $studentInput->fullName(),
					'parent_first_name'  => $parentInput->firstName,
					'parent_middle_name' => $parentInput->middleName,
				),
				$parentId
			);
		}

		$this->logEvents->dispatch(
			LogEvent::StudentEnrolled,
			new EnrollmentStatusEvent( $ctx->actorId, AuditAction::EnrollStudent, $studentId, $recordId, $groupId )
		);

		return ImportRowResultDTO::created( null, new RowCredentialsDTO(
			studentName:     $studentInput->fullName(),
			studentLogin:    $studentCreds->login,
			studentPassword: $studentCreds->password,
			parentLogin:     $parentCreds->login,
			parentPassword:  $parentCreds->password,
		) );
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
