<?php

declare( strict_types=1 );

namespace Inc\Services\Export\Log;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\CsvColumn;
use Inc\Repositories\WPDBRepositories\AuthLogRepository;
use Inc\Services\Log\LogNameResolver;

class AuthLogExportProvider implements CsvExportProviderInterface {

	public function __construct(
		private readonly AuthLogRepository $repository,
	) {}

	public function columns(): array {
		return array(
			new CsvColumn( 'ID',        fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',      fn( $r ) => LogNameResolver::date( $r->createdAt ) ),
			new CsvColumn( 'Логин',     fn( $r ) => $r->loginIdentifier ?? '' ),
			new CsvColumn( 'Действие',  fn( $r ) => $r->action ),
			new CsvColumn( 'Результат', fn( $r ) => $r->result ),
			new CsvColumn( 'IP',        fn( $r ) => $r->actorIp ),
			new CsvColumn( 'Устройство', fn( $r ) => $r->actorUa ?? '' ),
		);
	}

	public function rows( array $context ): iterable {
		return $this->repository->listAll( $context );
	}

	public function filename(): string {
		return 'auth-log';
	}
}
