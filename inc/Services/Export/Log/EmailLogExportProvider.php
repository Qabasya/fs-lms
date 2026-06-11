<?php

declare( strict_types=1 );

namespace Inc\Services\Export\Log;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\CsvColumn;
use Inc\Repositories\WPDBRepositories\EmailLogRepository;
use Inc\Services\Log\LogNameResolver;

class EmailLogExportProvider implements CsvExportProviderInterface {

	public function __construct(
		private readonly EmailLogRepository $repository,
	) {}

	public function columns(): array {
		return array(
			new CsvColumn( 'ID',           fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',         fn( $r ) => LogNameResolver::date( $r->createdAt ) ),
			new CsvColumn( 'Пользователь', fn( $r ) => LogNameResolver::userName( $r->actorUserId ) ),
			new CsvColumn( 'Тип письма',   fn( $r ) => $r->emailType ),
			new CsvColumn( 'Получатель',   fn( $r ) => LogNameResolver::personName( $r->targetPersonId ) ),
			new CsvColumn( 'Статус',       fn( $r ) => $r->status ),
			new CsvColumn( 'Ошибка',       fn( $r ) => $r->errorMessage ?? '' ),
		);
	}

	public function rows( array $context ): iterable {
		return $this->repository->listAll( $context );
	}

	public function filename(): string {
		return 'email-log';
	}
}
