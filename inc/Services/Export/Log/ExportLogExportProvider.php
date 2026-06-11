<?php

declare( strict_types=1 );

namespace Inc\Services\Export\Log;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\CsvColumn;
use Inc\Repositories\WPDBRepositories\ExportLogRepository;
use Inc\Services\Log\LogNameResolver;

class ExportLogExportProvider implements CsvExportProviderInterface {

	public function __construct(
		private readonly ExportLogRepository $repository,
	) {}

	public function columns(): array {
		return array(
			new CsvColumn( 'ID',           fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',         fn( $r ) => LogNameResolver::date( $r->createdAt ) ),
			new CsvColumn( 'Пользователь', fn( $r ) => LogNameResolver::userName( $r->actorUserId ) ),
			new CsvColumn( 'Роль',         fn( $r ) => $r->actorRole ?? '' ),
			new CsvColumn( 'Тип данных',   fn( $r ) => $r->dataType ),
			new CsvColumn( 'Тип действия', fn( $r ) => $r->actionType ),
			new CsvColumn( 'ID целей',     fn( $r ) => $r->targetIdsJson ?? '' ),
		);
	}

	public function rows( array $context ): iterable {
		return $this->repository->listAll( $context );
	}

	public function filename(): string {
		return 'export-log';
	}
}
