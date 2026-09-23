<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\DTO\Log\ErrorLogDTO;
use Inc\DTO\Log\ErrorLogInputDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Enums\Log\LogFilterType;

/**
 * Class ErrorLogRepository
 *
 * Журнал ошибок пользователей (error_log): отказы AJAX-обработчиков, истёкшие
 * сессии, сбои сети и сервера, пойманные в браузере.
 *
 * Чтение (list/countFiltered/listAll) — в {@see AbstractLogRepository}.
 *
 * ### Фильтры
 *
 * - code    — код ошибки
 * - ref     — номер инцидента (со скриншота пользователя)
 * - user_id — пользователь
 * - source  — server / client
 *
 * @method ErrorLogDTO[] list( array $filters, int $page, int $perPage, string $orderby = 'id', string $order = 'DESC' )
 * @method ErrorLogDTO[] listAll( array $filters )
 */
class ErrorLogRepository extends AbstractLogRepository {

	protected function channel(): LogChannel {
		return LogChannel::Errors;
	}

	/**
	 * @return array<string, array{0: string, 1: LogFilterType}>
	 */
	protected function filterMap(): array {
		return array(
			'code'    => array( 'code', LogFilterType::Text ),
			'ref'     => array( 'ref', LogFilterType::Text ),
			'user_id' => array( 'user_id', LogFilterType::Number ),
			'source'  => array( 'source', LogFilterType::Text ),
		);
	}

	/**
	 * @param array<string, mixed> $row Строка таблицы
	 */
	protected function hydrate( array $row ): ErrorLogDTO {
		return ErrorLogDTO::fromArray( $row );
	}

	public function create( ErrorLogInputDTO $input ): int {
		return $this->insertRow( $input->toArray() );
	}

	/**
	 * Коды, которые встречаются в журнале, — словарь для фильтра.
	 *
	 * @return string[]
	 */
	public function distinctCodes(): array {
		return $this->distinctValues( 'code' );
	}
}
