<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\CsvColumn;
use Inc\Enums\AuditAction;
use Inc\Enums\Capability;
use Inc\Enums\ExpulsionReasons;
use Inc\Enums\Nonce;
use Inc\Repositories\WPDBRepositories\ArchiveRepository;
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
		private readonly ExpulsionService $expulsionService,
		private readonly ArchiveRepository $archiveRepository,
		private readonly PiiCryptoService $crypto,
		private readonly CsvExportService $csvExportService,
		private readonly AuditService     $auditService,
	) {
		parent::__construct();
	}

	public function ajaxExpelStudent(): void {
		$this->authorize( Nonce::Expulsion, Capability::ManagePersons );

		$studentId = $this->requireInt( 'student_id', error: 'Не указан ID студента.' );

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

		$row = array(
			'contract_no'   => $archive->contractNo ?? '',
			'contract_date' => $archive->contractDate ?? '',
			'order_no'      => $archive->orderNo ?? '',
			'order_date'    => $archive->orderDate ?? '',
			'group_key'     => $archive->groupKey ?? '',
			'enrolled_at'   => $archive->enrolledAt,
			'expelled_at'   => $archive->expelledAt ?? '',
			'reason'        => $archive->reason ?? '',
		);

		$columns = array(
			new CsvColumn( '№ договора',         fn( $r ) => $r['contract_no'] ),
			new CsvColumn( 'Дата договора',      fn( $r ) => $r['contract_date'] ),
			new CsvColumn( '№ приказа',          fn( $r ) => $r['order_no'] ),
			new CsvColumn( 'Дата приказа',       fn( $r ) => $r['order_date'] ),
			new CsvColumn( 'Группа',             fn( $r ) => $r['group_key'] ),
			new CsvColumn( 'Зачислен',           fn( $r ) => $r['enrolled_at'] ),
			new CsvColumn( 'Дата отчисления',    fn( $r ) => $r['expelled_at'] ),
			new CsvColumn( 'Причина отчисления', fn( $r ) => $r['reason'] ),
		);

		$csv      = $this->csvExportService->export( [ $row ], $columns );
		$filename = sprintf( 'expelled_%d_%s.csv', $archiveId, date( 'Y-m-d' ) );
		$url      = $this->csvExportService->createDownloadLink( $csv, $filename );

		$this->auditService->record(
			action:     AuditAction::ExpelledArchiveExported->value,
			targetType: 'archive',
			targetId:   $archiveId,
		);

		$this->success( array( 'download_url' => $url ) );
	}
}
