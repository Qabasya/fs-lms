<?php

declare( strict_types=1 );

namespace Inc\Services\Export\Log;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\CsvColumn;
use Inc\Repositories\WPDBRepositories\DataChangeLogRepository;
use Inc\Services\Log\LogNameResolver;
use Inc\Services\PiiCryptoService;

class DataChangeLogExportProvider implements CsvExportProviderInterface {

	public function __construct(
		private readonly DataChangeLogRepository $repository,
		private readonly PiiCryptoService        $crypto,
	) {}

	public function columns(): array {
		return array(
			new CsvColumn( 'ID',              fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',            fn( $r ) => LogNameResolver::date( $r->createdAt ) ),
			new CsvColumn( 'Пользователь',    fn( $r ) => LogNameResolver::userName( $r->actorUserId ) ),
			new CsvColumn( 'Роль',            fn( $r ) => $r->actorRole ?? '' ),
			new CsvColumn( 'Person',          fn( $r ) => LogNameResolver::personName( $r->targetPersonId ) ),
			new CsvColumn( 'Поле',            fn( $r ) => $r->fieldName ),
			new CsvColumn( 'Старое значение', fn( $r ) => $this->decrypt( $r->oldValueEnc ) ),
			new CsvColumn( 'Новое значение',  fn( $r ) => $this->decrypt( $r->newValueEnc ) ),
		);
	}

	public function rows( array $context ): iterable {
		return $this->repository->listAll( $context );
	}

	public function filename(): string {
		return 'data-change-log';
	}

	private function decrypt( ?string $enc ): string {
		if ( null === $enc || '' === $enc ) {
			return '';
		}
		try {
			return $this->crypto->decrypt( $enc );
		} catch ( \Throwable ) {
			return '';
		}
	}
}
