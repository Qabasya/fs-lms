<?php

declare( strict_types=1 );

namespace Inc\Services\Export\Log;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\CsvColumn;
use Inc\Repositories\WPDBRepositories\DeletionLogRepository;
use Inc\Services\Log\LogNameResolver;

class DeletionLogExportProvider implements CsvExportProviderInterface {

	public function __construct(
		private readonly DeletionLogRepository $repository,
	) {}

	public function columns(): array {
		return array(
			new CsvColumn( 'ID',               fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',             fn( $r ) => LogNameResolver::date( $r->createdAt ) ),
			new CsvColumn( 'Пользователь',     fn( $r ) => LogNameResolver::userName( $r->actorUserId ) ),
			new CsvColumn( 'Роль',             fn( $r ) => $r->actorRole ?? '' ),
			new CsvColumn( 'Тип сущности',     fn( $r ) => $r->entityType ),
			new CsvColumn( 'ID сущности',      fn( $r ) => $r->entityId ),
			new CsvColumn( 'Каскадно удалено', fn( $r ) => $r->cascadedSummary ?? '' ),
			new CsvColumn( 'IP',               fn( $r ) => $r->actorIp ),
		);
	}

	public function rows( array $context ): iterable {
		return $this->repository->listAll( $context );
	}

	public function filename(): string {
		return 'deletion-log';
	}
}
