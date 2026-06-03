<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\CsvColumn;
use Inc\Enums\AuditAction;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Repositories\WPDBRepositories\ExpelledArchiveRepository;
use Inc\Services\AuditService;
use Inc\Services\CsvExportService;
use Inc\Services\ExpulsionService;
use Inc\Services\PiiCryptoService;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;
use RuntimeException;

class ExpulsionCallbacks extends BaseController {

	use Authorizer;
	use AjaxResponse;
	use Sanitizer;

	public function __construct(
		private readonly ExpulsionService         $expulsionService,
		private readonly ExpelledArchiveRepository $archiveRepository,
		private readonly PiiCryptoService         $crypto,
		private readonly CsvExportService         $csvExportService,
		private readonly AuditService             $auditService,
	) {
		parent::__construct();
	}

	public function ajaxExpelStudent(): void {
		$this->authorize( Nonce::Expulsion, Capability::ManagePersons );

		$studentId = $this->requireInt( 'student_id', error: 'Не указан ID студента.' );
		$reason    = $this->sanitizeText( 'reason' );

		try {
			$archive = $this->expulsionService->expel( $studentId, $reason );
			$this->success( array( 'archive_id' => $archive->id ) );
		} catch ( RuntimeException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	public function ajaxExportExpelledRecord(): void {
		$this->authorize( Nonce::Expulsion, Capability::ExportPII );

		$archiveId = $this->requireInt( 'archive_id', error: 'Не указан ID архивной записи.' );

		$archive = $this->archiveRepository->find( $archiveId );
		if ( null === $archive ) {
			$this->error( 'Архивная запись не найдена.' );
			return;
		}

		try {
			$data = json_decode( $this->crypto->decrypt( $archive->dataEnc ), true );
		} catch ( \Throwable ) {
			$this->error( 'Ошибка расшифровки данных архива.' );
			return;
		}

		$student  = $data['student']  ?? [];
		$guardian = $data['guardian'] ?? [];
		$enroll   = $data['enrollment'] ?? [];

		$row = array(
			'student'  => $student,
			'guardian' => $guardian,
			'enroll'   => $enroll,
			'expelled_at' => $archive->expelledAt,
			'reason'      => $archive->reason ?? '',
		);

		$columns = array(
			new CsvColumn( 'Фамилия (ученик)',    fn( $r ) => $r['student']['last_name']   ?? '' ),
			new CsvColumn( 'Имя (ученик)',         fn( $r ) => $r['student']['first_name']  ?? '' ),
			new CsvColumn( 'Отчество (ученик)',    fn( $r ) => $r['student']['middle_name'] ?? '' ),
			new CsvColumn( 'Дата рождения',        fn( $r ) => $r['student']['birth_date']  ?? '' ),
			new CsvColumn( 'Email (ученик)',        fn( $r ) => $r['student']['email']       ?? '' ),
			new CsvColumn( 'Школа',                fn( $r ) => $r['student']['school']      ?? '' ),
			new CsvColumn( 'Класс',                fn( $r ) => (string) ( $r['student']['grade'] ?? '' ) ),
			new CsvColumn( 'Тип документа',        fn( $r ) => $r['student']['doc_type']    ?? '' ),
			new CsvColumn( 'Номер документа',      fn( $r ) => $r['student']['doc_number']  ?? '' ),
			new CsvColumn( 'ИНН (ученик)',         fn( $r ) => $r['student']['inn']         ?? '' ),
			new CsvColumn( 'Фамилия (родитель)',   fn( $r ) => $r['guardian']['last_name']  ?? '' ),
			new CsvColumn( 'Имя (родитель)',       fn( $r ) => $r['guardian']['first_name'] ?? '' ),
			new CsvColumn( 'Отчество (родитель)',  fn( $r ) => $r['guardian']['middle_name'] ?? '' ),
			new CsvColumn( 'Тип связи',            fn( $r ) => $r['guardian']['relation_type'] ?? '' ),
			new CsvColumn( 'Email (родитель)',     fn( $r ) => $r['guardian']['email']      ?? '' ),
			new CsvColumn( 'Телефон',              fn( $r ) => $r['guardian']['phone']      ?? '' ),
			new CsvColumn( 'Адрес',                fn( $r ) => $r['guardian']['address']    ?? '' ),
			new CsvColumn( 'ИНН (родитель)',       fn( $r ) => $r['guardian']['inn']        ?? '' ),
			new CsvColumn( 'Предмет',              fn( $r ) => $r['enroll']['subject_key']  ?? '' ),
			new CsvColumn( 'Период',               fn( $r ) => $r['enroll']['period_key']   ?? '' ),
			new CsvColumn( 'Группа',               fn( $r ) => $r['enroll']['group_id']     ?? '' ),
			new CsvColumn( 'Зачислен',             fn( $r ) => $r['enroll']['enrolled_at']  ?? '' ),
			new CsvColumn( 'Дата отчисления',      fn( $r ) => $r['expelled_at'] ),
			new CsvColumn( 'Причина отчисления',   fn( $r ) => $r['reason'] ),
		);

		$csv      = $this->csvExportService->export( [ $row ], $columns );
		$filename = sprintf( 'expelled_%d_%s.csv', $archiveId, date( 'Y-m-d' ) );
		$url      = $this->csvExportService->createDownloadLink( $csv, $filename );

		$this->auditService->record(
			action:     AuditAction::ExpelledArchiveExported->value,
			targetType: 'expelled_archive',
			targetId:   $archiveId,
		);

		$this->success( array( 'download_url' => $url ) );
	}
}
