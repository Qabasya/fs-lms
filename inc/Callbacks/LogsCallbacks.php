<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\CsvColumn;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Repositories\WPDBRepositories\AuditLogRepository;
use Inc\Repositories\WPDBRepositories\PiiAccessLogRepository;
use Inc\Services\CsvExportService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class LogsCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly AuditLogRepository    $auditLog,
		private readonly PiiAccessLogRepository $piiLog,
		private readonly CsvExportService       $csv,
	) {
		parent::__construct();
	}

	public function ajaxExportAuditLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );

		$filters = array_filter( array(
			'action'        => $this->sanitizeKey( 'action_filter' ),
			'actor_user_id' => $this->sanitizeInt( 'actor_id' ) ?: null,
			'date_from'     => $this->sanitizeText( 'date_from' ),
			'date_to'       => $this->sanitizeText( 'date_to' ),
		) );

		$rows    = $this->auditLog->listAll( $filters );
		$columns = array(
			new CsvColumn( 'ID',          fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',        fn( $r ) => $r->createdAt ),
			new CsvColumn( 'User ID',     fn( $r ) => $r->actorUserId ?? '' ),
			new CsvColumn( 'Роль',        fn( $r ) => $r->actorRole ?? '' ),
			new CsvColumn( 'Действие',    fn( $r ) => $r->action ),
			new CsvColumn( 'Тип объекта', fn( $r ) => $r->targetType ?? '' ),
			new CsvColumn( 'ID объекта',  fn( $r ) => $r->targetId ?? '' ),
			new CsvColumn( 'IP',          fn( $r ) => $r->actorIp ),
			new CsvColumn( 'Детали',      fn( $r ) => $r->detailsJson ?? '' ),
		);

		$csv = $this->csv->export( $rows, $columns );
		$url = $this->csv->createDownloadLink( $csv, 'audit-log-' . wp_date( 'Y-m-d' ) . '.csv' );

		$this->success( array( 'url' => $url ) );
	}

	public function ajaxExportPiiLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );

		$filters = array_filter( array(
			'actor_user_id' => $this->sanitizeInt( 'actor_id' ) ?: null,
			'person_id'     => $this->sanitizeInt( 'person_id' ) ?: null,
			'date_from'     => $this->sanitizeText( 'date_from' ),
			'date_to'       => $this->sanitizeText( 'date_to' ),
		) );

		$rows    = $this->piiLog->listAll( $filters );
		$columns = array(
			new CsvColumn( 'ID',        fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',      fn( $r ) => $r->createdAt ),
			new CsvColumn( 'User ID',   fn( $r ) => $r->actorUserId ),
			new CsvColumn( 'Роль',      fn( $r ) => $r->actorRole ?? '' ),
			new CsvColumn( 'Person ID', fn( $r ) => $r->personId ),
			new CsvColumn( 'Поля',      fn( $r ) => $r->fieldsAccessed ),
			new CsvColumn( 'Причина',   fn( $r ) => $r->accessReason ),
			new CsvColumn( 'IP',        fn( $r ) => $r->actorIp ),
		);

		$csv = $this->csv->export( $rows, $columns );
		$url = $this->csv->createDownloadLink( $csv, 'pii-log-' . wp_date( 'Y-m-d' ) . '.csv' );

		$this->success( array( 'url' => $url ) );
	}
}
