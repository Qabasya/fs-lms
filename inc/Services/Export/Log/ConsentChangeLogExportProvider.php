<?php

declare( strict_types=1 );

namespace Inc\Services\Export\Log;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\CsvColumn;
use Inc\Repositories\WPDBRepositories\ConsentChangeLogRepository;
use Inc\Services\Log\LogNameResolver;

class ConsentChangeLogExportProvider implements CsvExportProviderInterface {

	public function __construct(
		private readonly ConsentChangeLogRepository $repository,
	) {}

	public function columns(): array {
		return array(
			new CsvColumn( 'ID',           fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',         fn( $r ) => LogNameResolver::date( $r->createdAt ) ),
			new CsvColumn( 'Пользователь', fn( $r ) => LogNameResolver::userName( $r->actorUserId ) ),
			new CsvColumn( 'Person',       fn( $r ) => LogNameResolver::personName( $r->personId ) ),
			new CsvColumn( 'Тип согласия', fn( $r ) => $r->consentType ),
			new CsvColumn( 'Старый хеш',  fn( $r ) => $r->oldHash ?? '' ),
			new CsvColumn( 'Новый хеш',   fn( $r ) => $r->newHash ?? '' ),
		);
	}

	public function rows( array $context ): iterable {
		return $this->repository->listAll( $context );
	}

	public function filename(): string {
		return 'consent-change-log';
	}
}
