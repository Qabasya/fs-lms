<?php

declare( strict_types=1 );

namespace Inc\Services\Import;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Import\ImportContextDTO;
use Inc\DTO\Import\ImportRowResultDTO;
use Inc\DTO\Import\RowCredentialsDTO;
use Inc\DTO\Log\Events\EnrollmentStatusEvent;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Enums\Import\ImportColumn;
use Inc\Enums\Import\ImportMode;
use Inc\Enums\Log\AuditAction;
use Inc\Enums\Log\LogEvent;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Email\EmailService;
use Inc\Services\Enrollment\AccountProvisioningService;
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
readonly class EnrolledStudentRowImporter extends AbstractRowImporter {

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
		$get = $this->cell( $row );

		$groupName  = $get( ImportColumn::Group );
		$contractNo = $get( ImportColumn::ContractNo );
		$username   = $get( ImportColumn::Username );
		$password   = $get( ImportColumn::Password );

		$this->requireValues( array(
			ImportColumn::LastName->value        => $get( ImportColumn::LastName ),
			ImportColumn::FirstName->value       => $get( ImportColumn::FirstName ),
			ImportColumn::Group->value           => $groupName,
			ImportColumn::ContractNo->value      => $contractNo,
			ImportColumn::ParentLastName->value  => $get( ImportColumn::ParentLastName ),
			ImportColumn::ParentFirstName->value => $get( ImportColumn::ParentFirstName ),
			ImportColumn::Username->value        => $username,
			ImportColumn::Password->value        => $password,
			ImportColumn::ParentEmail->value     => $get( ImportColumn::ParentEmail ),
		) );

		$studentInput = $this->studentInput( $get, $this->docTypeResolver );
		$parentInput  = $this->parentInput( $get, $this->docTypeResolver );

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

		[ $recordId, $studentId, $parentId, $groupId ] = $this->persistRecord(
			$this->writer, $this->studentRecords, $ctx, $get, $studentInput, $parentInput,
			$groupName, $groupId, $studentId, $parentId, $now,
			static fn(): array => array( EnrollmentStatus::Active->value, null, null, null )
		);

		// Провизия учёток — после COMMIT: падение wp_insert_user не откатывает запись
		$studentCreds = $this->provisioning->provisionStudent( $studentId, $studentInput, $username, $password );
		$parentCreds  = $this->provisioning->provisionParent( $parentId, $parentInput );

		if ( $ctx->sendEmails && $parentCreds->created ) {
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



}
