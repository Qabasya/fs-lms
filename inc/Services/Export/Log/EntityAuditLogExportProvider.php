<?php

declare( strict_types=1 );

namespace Inc\Services\Export\Log;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\CsvColumn;
use Inc\Repositories\WPDBRepositories\EntityAuditLogRepository;
use Inc\Services\Log\LogNameResolver;

class EntityAuditLogExportProvider implements CsvExportProviderInterface {

	public function __construct(
		private readonly EntityAuditLogRepository $repository,
	) {}

	public function columns(): array {
		return array(
			new CsvColumn( 'ID',              fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',            fn( $r ) => LogNameResolver::date( $r->createdAt ) ),
			new CsvColumn( 'Пользователь',    fn( $r ) => LogNameResolver::userName( $r->actorUserId ) ),
			new CsvColumn( 'Роль',            fn( $r ) => $r->actorRole ?? '' ),
			new CsvColumn( 'Операция',        fn( $r ) => $r->operation ),
			new CsvColumn( 'Тип сущности',    fn( $r ) => $r->entityType ),
			new CsvColumn( 'Сущность',        fn( $r ) => LogNameResolver::entityName( $r->entityId, $r->entityType, $r->oldLabel ) ),
			new CsvColumn( 'Прошлое название', fn( $r ) => $r->oldLabel ?? '' ),
			new CsvColumn( 'IP',              fn( $r ) => $r->actorIp ?? '' ),
		);
	}

	public function rows( array $context ): iterable {
		return $this->repository->listAll( $context );
	}

	public function filename(): string {
		return 'entity-audit-log';
	}
}
