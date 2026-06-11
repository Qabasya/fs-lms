<?php

declare( strict_types=1 );

namespace Inc\Services\Export\Log;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\CsvColumn;
use Inc\Repositories\WPDBRepositories\AuditLogRepository;
use Inc\Services\Log\LogNameResolver;

class EnrollmentAuditLogExportProvider implements CsvExportProviderInterface {

	public function __construct(
		private readonly AuditLogRepository $repository,
	) {}

	public function columns(): array {
		return array(
			new CsvColumn( 'ID',           fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',         fn( $r ) => LogNameResolver::date( $r->createdAt ) ),
			new CsvColumn( 'Пользователь', fn( $r ) => LogNameResolver::userName( $r->actorUserId ) ),
			new CsvColumn( 'Роль',         fn( $r ) => $r->actorRole ?? '' ),
			new CsvColumn( 'Действие',     fn( $r ) => $r->action ),
			new CsvColumn( 'Тип объекта',  fn( $r ) => $r->targetType ?? '' ),
			new CsvColumn( 'ID объекта',   fn( $r ) => $r->targetId ?? '' ),
			new CsvColumn( 'IP',           fn( $r ) => $r->actorIp ),
			new CsvColumn( 'Детали',       fn( $r ) => $r->detailsJson ?? '' ),
		);
	}

	public function rows( array $context ): iterable {
		return $this->repository->listAll( $context );
	}

	public function filename(): string {
		return 'enrollment-audit-log';
	}
}
