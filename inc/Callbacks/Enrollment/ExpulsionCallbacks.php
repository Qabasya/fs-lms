<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Enrollment;

use Inc\Core\BaseController;
use Inc\DTO\Export\CsvColumn;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Enums\Capability;
use Inc\Enums\ExpulsionReasons;
use Inc\Enums\Nonce;
use Inc\Enums\WeekDay;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Export\CsvExportService;
use Inc\Services\Enrollment\ExpulsionService;
use Inc\Services\Log\ExportLogWriter;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;
use RuntimeException;

/**
 * Class ExpulsionCallbacks
 *
 * AJAX-обработчики для отчисления студентов и экспорта записей об отчислении.
 *
 * @package Inc\Callbacks
 */
class ExpulsionCallbacks extends BaseController {

	use Authorizer;
	use AjaxResponse;
	use Sanitizer;

	public function __construct(
		private readonly ExpulsionService        $expulsionService,
		private readonly StudentRecordRepository $studentRecordRepository,
		private readonly CsvExportService        $csvExportService,
		private readonly ExportLogWriter         $exportLog,
		private readonly GroupsRepository        $groupsRepository,
		private readonly SubjectRepository       $subjectRepository,
	) {
		parent::__construct();
	}

	/**
	 * Отчисляет студента из конкретной группы (записи зачисления).
	 *
	 * @return void
	 */
	public function ajaxExpelStudent(): void {
		$this->authorize( Nonce::Expulsion, Capability::ManagePersons );

		$studentId = $this->requireInt( 'student_id', error: 'Не указан ID студента.' );
		$recordId  = $this->sanitizeInt( 'record_id' ) ?: null;

		$reason = $this->sanitizeText( 'reason' );

		if ( '' === $reason ) {
			$this->error( 'Не указана причина отчисления.' );
			return;
		}

		$isOtherReason = str_starts_with(
			$reason,
			ExpulsionReasons::Other->value . ':'
		);

		$isValidReason = in_array(
			$reason,
			ExpulsionReasons::values(),
			true
		);

		if ( ! $isValidReason && ! $isOtherReason ) {
			$this->error( 'Недопустимая причина отчисления.' );
			return;
		}

		try {
			$result = $this->expulsionService->expel( $studentId, $reason, $recordId );

			$this->success( array(
				'archive_id'            => $result['archive_id'],
				'remaining_enrollments' => $this->mapEnrollments( $result['remaining_active_records'] ),
			) );
		} catch ( RuntimeException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	/**
	 * Экспортирует запись об отчислении в CSV-файл.
	 *
	 * @return void
	 */
	public function ajaxExportExpelledRecord(): void {
		$this->authorize( Nonce::Expulsion, Capability::ExportPII );

		$recordId = $this->requireInt( 'archive_id', error: 'Не указан ID записи.' );

		$record = $this->studentRecordRepository->find( $recordId );
		if ( null === $record ) {
			$this->error( 'Запись не найдена.' );
			return;
		}

		$row = array(
			'contract_no'   => $record->contractNo ?? '',
			'contract_date' => $record->contractDate ?? '',
			'order_no'      => $record->orderNo ?? '',
			'order_date'    => $record->orderDate ?? '',
			'group_id'      => $record->groupId ?? 0,
			'enrolled_at'   => $record->enrolledAt,
			'expelled_at'   => $record->expelledAt ?? '',
			'expel_reason'  => $record->expelReason ?? '',
		);

		$columns = array(
			new CsvColumn( '№ договора',         fn( $r ) => $r['contract_no'] ),
			new CsvColumn( 'Дата договора',      fn( $r ) => $r['contract_date'] ),
			new CsvColumn( '№ приказа',          fn( $r ) => $r['order_no'] ),
			new CsvColumn( 'Дата приказа',       fn( $r ) => $r['order_date'] ),
			new CsvColumn( 'Группа',             fn( $r ) => $r['group_id'] ),
			new CsvColumn( 'Зачислен',           fn( $r ) => $r['enrolled_at'] ),
			new CsvColumn( 'Дата отчисления',    fn( $r ) => $r['expelled_at'] ),
			new CsvColumn( 'Причина отчисления', fn( $r ) => $r['expel_reason'] ),
		);

		$csv      = $this->csvExportService->export( array( $row ), $columns );
		$filename = sprintf( 'expelled_%d_%s.csv', $recordId, date( 'Y-m-d' ) );
		$url      = $this->csvExportService->createDownloadLink( $csv, $filename );

		$this->exportLog->record( 'expelled_archive', 'single', array( $recordId ) );

		$this->success( array( 'download_url' => $url ) );
	}

	/**
	 * Маппит массив StudentRecordDTO в упрощённые данные для JS-ответа.
	 *
	 * @param StudentRecordDTO[] $records
	 *
	 * @return array[]
	 */
	private function mapEnrollments( array $records ): array {
		$allSubjects = array();
		foreach ( $this->subjectRepository->readAll() as $dto ) {
			$allSubjects[ $dto->key ] = $dto->name;
		}

		return array_map( function ( StudentRecordDTO $record ) use ( $allSubjects ): array {
			$group         = $record->groupId ? $this->groupsRepository->findById( $record->groupId ) : null;
			$scheduleArray = is_string( $group?->meetings )
				? ( json_decode( $group->meetings, true ) ?? array() )
				: array();

			return array(
				'record_id'    => $record->id,
				'subject_name' => $allSubjects[ $group?->subject_key ?? '' ] ?? ( $group?->subject_key ?? '—' ),
				'group_title'  => $group?->name ?? '—',
				'schedule'     => WeekDay::formatSchedule( $scheduleArray ),
				'contract_no'  => $record->contractNo ?? '',
			);
		}, $records );
	}
}
