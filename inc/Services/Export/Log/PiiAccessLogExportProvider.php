<?php

declare( strict_types=1 );

namespace Inc\Services\Export\Log;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\CsvColumn;
use Inc\Repositories\WPDBRepositories\PiiAccessLogRepository;
use Inc\Services\Log\LogNameResolver;

class PiiAccessLogExportProvider implements CsvExportProviderInterface {

	public function __construct(
		private readonly PiiAccessLogRepository $repository,
	) {}

	public function columns(): array {
		return array(
			new CsvColumn( 'ID',           fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',         fn( $r ) => LogNameResolver::date( $r->createdAt ) ),
			new CsvColumn( 'Пользователь', fn( $r ) => LogNameResolver::userName( $r->actorUserId ) ),
			new CsvColumn( 'Роль',         fn( $r ) => $r->actorRole ?? '' ),
			new CsvColumn( 'Person',       fn( $r ) => LogNameResolver::personName( $r->personId ) ),
			new CsvColumn( 'Поля',         fn( $r ) => $r->fieldsAccessed ),
			new CsvColumn( 'Причина',      fn( $r ) => $r->accessReason ),
			new CsvColumn( 'IP',           fn( $r ) => $r->actorIp ),
			new CsvColumn( 'Устройство',   fn( $r ) => $r->actorUa ?? '' ),
		);
	}

	public function rows( array $context ): iterable {
		return $this->repository->listAll( $context );
	}

	public function filename(): string {
		return 'pii-access-log';
	}
}
