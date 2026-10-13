<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories\Log;

use Inc\DTO\Log\ContentReportLogDTO;
use Inc\DTO\Log\ContentReportLogInputDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Enums\Log\LogFilterType;

/**
 * Class ContentReportLogRepository
 *
 * Журнал «Найденные ошибки» (content_reports): сообщения читателей со страниц
 * заданий и статей. Чтение — в {@see AbstractLogRepository}.
 *
 * ### Фильтры
 *
 * - post_id — страница, с которой отправлено
 *
 * @method ContentReportLogDTO[] list( array $filters, int $page, int $perPage, string $orderby = 'id', string $order = 'DESC' )
 * @method ContentReportLogDTO[] listAll( array $filters )
 */
class ContentReportLogRepository extends AbstractLogRepository {

	protected function channel(): LogChannel {
		return LogChannel::ContentReports;
	}

	/**
	 * @return array<string, array{0: string, 1: LogFilterType}>
	 */
	protected function filterMap(): array {
		return array(
			'post_id' => array( 'post_id', LogFilterType::Number ),
		);
	}

	/**
	 * @param array<string, mixed> $row Строка таблицы
	 */
	protected function hydrate( array $row ): ContentReportLogDTO {
		return ContentReportLogDTO::fromArray( $row );
	}

	public function create( ContentReportLogInputDTO $input ): int {
		return $this->insertRow( $input->toArray() );
	}
}
