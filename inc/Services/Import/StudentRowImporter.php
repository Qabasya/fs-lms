<?php

declare( strict_types=1 );

namespace Inc\Services\Import;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Import\ImportContextDTO;
use Inc\DTO\Import\ImportRowResultDTO;
use Inc\DTO\Log\Events\EnrollmentStatusEvent;
use Inc\DTO\Person\PersonInputDTO;
use Inc\Enums\Log\AuditAction;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Enums\Import\ImportColumn;
use Inc\Enums\Import\ImportMode;
use Inc\Enums\Log\LogEvent;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use InvalidArgumentException;

/**
 * Class StudentRowImporter
 *
 * Импортирует одну строку CSV: каскадно создаёт группу, persons ученика и
 * родителя (+ person_documents с шифрованием PII) и запись student_records.
 *
 * ### Особенности
 *
 * - **WP-учётки не создаются** — появятся позже при зачислении из архива
 *   (или сразу — режим {@see \Inc\Services\Import\EnrolledStudentRowImporter}).
 * - **Единый шаблон CSV** — колонки `Логин`/`Пароль` в этом режиме не читаются
 *   и могут быть пустыми.
 * - **Статус записи** определяется по строке: есть дата/причина отчисления →
 *   запись в архиве (`finished`/`transferred`/`expelled`), иначе `active`.
 * - **Идемпотентность** — дубль по ученик+группа+договор → skipped.
 * - **Dry-run** — все резолвы/проверки выполняются, запись пропускается.
 * - **Транзакция** — только сама запись (группа+persons+student_records);
 *   этот импортёр не вызывает WP-функций с side-эффектами, поэтому весь
 *   блок записи безопасно оборачивается целиком.
 *
 * Предмет и период берутся из {@see ImportContextDTO} (выбор в UI), а не из CSV.
 */
readonly class StudentRowImporter extends AbstractRowImporter {

	/**
	 * @param StudentRecordWriter         $writer          Резолв/создание группы и persons
	 * @param StudentRecordRepository     $studentRecords  Дедуп и создание записей
	 * @param ExpulsionResolver           $expulsionResolver Причина → статус
	 * @param DocTypeResolver             $docTypeResolver Тип документа → значение enum
	 * @param ClockInterface              $clock           Текущее время
	 * @param LogEventDispatcherInterface $logEvents       Шина событий логирования
	 */
	public function __construct(
		private StudentRecordWriter         $writer,
		private StudentRecordRepository     $studentRecords,
		private ExpulsionResolver           $expulsionResolver,
		private DocTypeResolver             $docTypeResolver,
		private ClockInterface              $clock,
		private LogEventDispatcherInterface $logEvents,
	) {}

	/**
	 * Обязательные колонки CSV (валидация заголовков файла).
	 *
	 * @return string[]
	 */
	public function requiredHeaders(): array {
		return ImportColumn::required( ImportMode::Archive );
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
		$get = $this->cell( $row );

		$groupName  = $get( ImportColumn::Group );
		$contractNo = $get( ImportColumn::ContractNo );

		$this->requireValues( array(
			ImportColumn::LastName->value        => $get( ImportColumn::LastName ),
			ImportColumn::FirstName->value       => $get( ImportColumn::FirstName ),
			ImportColumn::Group->value           => $groupName,
			ImportColumn::ContractNo->value      => $contractNo,
			ImportColumn::ParentLastName->value  => $get( ImportColumn::ParentLastName ),
			ImportColumn::ParentFirstName->value => $get( ImportColumn::ParentFirstName ),
		) );

		$studentInput = $this->studentInput( $get, $this->docTypeResolver );
		$parentInput  = $this->parentInput( $get, $this->docTypeResolver );

		// Резолв существующих сущностей (только чтение)
		$groupId   = $this->writer->resolveGroupId( $groupName, $ctx );
		$studentId = $this->writer->resolvePersonId( $studentInput );
		$parentId  = $this->writer->resolvePersonId( $parentInput );

		$label = $this->rowLabel( $studentInput, $groupName, $contractNo, $get );

		// Дедуп записи (если ученик и группа уже известны)
		if ( null !== $studentId && null !== $groupId
			&& $this->studentRecords->existsByContract( $studentId, $groupId, $contractNo ) ) {
			return ImportRowResultDTO::skipped( 'Запись с таким договором уже существует.', $label );
		}

		if ( $ctx->dryRun ) {
			return ImportRowResultDTO::created( 'Будет создано (dry-run).', null, $label );
		}

		$now = $this->clock->now( 'mysql', true );

		[ $recordId, $studentId, $parentId, $groupId ] = $this->persistRecord(
			$this->writer, $this->studentRecords, $ctx, $get, $studentInput, $parentInput,
			$groupName, $groupId, $studentId, $parentId, $now,
			fn(): array => $this->resolveLifecycle( $get, $ctx, $now )
		);

		$this->logEvents->dispatch(
			LogEvent::StudentEnrolled,
			new EnrollmentStatusEvent( $ctx->actorId, AuditAction::EnrollStudent, $studentId, $recordId, $groupId )
		);

		return ImportRowResultDTO::created();
	}

	/**
	 * Человекочитаемое описание строки для предпросмотра dry-run.
	 *
	 * @param PersonInputDTO $student    Ученик
	 * @param string         $groupName  Название группы
	 * @param string         $contractNo Номер договора
	 * @param callable       $get        Геттер значения колонки
	 *
	 * @return string «Иванов Иван Иванович — группа «ОГЭ-1», договор № 2024-001, в архив»
	 */
	private function rowLabel( PersonInputDTO $student, string $groupName, string $contractNo, callable $get ): string {
		$label = sprintf(
			'%s — группа «%s», договор № %s',
			$student->fullName(),
			$groupName,
			$contractNo
		);

		// Признак архива определяется так же, как в resolveLifecycle(): дата ИЛИ причина
		if ( '' !== $get( ImportColumn::ExpelledAt ) || '' !== $get( ImportColumn::ExpelReason ) ) {
			$label .= ', в архив';
		}

		return $label;
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
		$expulsion = $this->expulsionResolver->resolve( $get( ImportColumn::ExpelReason ) );
		$expelDate = $this->toDateTime( $get( ImportColumn::ExpelledAt ) );

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



}
