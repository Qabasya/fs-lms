<?php

declare( strict_types=1 );

namespace Inc\Services\Export\Log;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\Export\CsvColumn;
use Inc\Enums\Log\ErrorCode;
use Inc\Repositories\WPDBRepositories\Log\ErrorLogRepository;
use Inc\Services\Log\LogNameResolver;

/**
 * Class ErrorLogExportProvider
 *
 * Экспорт журнала ошибок пользователей (error_log) в CSV.
 *
 * @package Inc\Services\Export\Log
 */
class ErrorLogExportProvider implements CsvExportProviderInterface {

	public function __construct(
		private readonly ErrorLogRepository $repository,
	) {}

	/**
	 * @return CsvColumn[]
	 */
	public function columns( array $context = array() ): array {
		return array(
			new CsvColumn( 'Инцидент',     fn( $r ) => '#' . $r->ref ),
			new CsvColumn( 'Дата',         fn( $r ) => LogNameResolver::date( $r->createdAt ) ),
			new CsvColumn( 'Пользователь', fn( $r ) => $r->userId ? wp_strip_all_tags( LogNameResolver::userName( $r->userId ) ) : 'Гость' ),
			new CsvColumn( 'Код',          fn( $r ) => $r->code ),
			new CsvColumn( 'Описание кода', fn( $r ) => ErrorCode::fromCode( $r->code )?->label() ?? '' ),
			new CsvColumn( 'Сообщение',    fn( $r ) => $r->message ),
			new CsvColumn( 'Источник',     fn( $r ) => 'client' === $r->source ? 'Браузер' : 'Сервер' ),
			new CsvColumn( 'Действие',     fn( $r ) => $r->action ?? '' ),
			new CsvColumn( 'Страница',     fn( $r ) => $r->url ?? '' ),
			new CsvColumn( 'Подробности',  fn( $r ) => array() !== $r->context ? (string) wp_json_encode( $r->context, JSON_UNESCAPED_UNICODE ) : '' ),
			new CsvColumn( 'IP',           fn( $r ) => $r->actorIp ),
			new CsvColumn( 'Устройство',   fn( $r ) => $r->actorUa ?? '' ),
		);
	}

	public function rows( array $context ): iterable {
		return $this->repository->listAll( $context );
	}

	public function filename(): string {
		return 'error-log';
	}
}
