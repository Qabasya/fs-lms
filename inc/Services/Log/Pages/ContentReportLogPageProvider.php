<?php

declare( strict_types=1 );

namespace Inc\Services\Log\Pages;

use Inc\Contracts\LogPageProviderInterface;
use Inc\DTO\Log\LogPageQueryDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Repositories\WPDBRepositories\Log\ContentReportLogRepository;

/**
 * Вкладка «Найденные ошибки» — сообщения читателей кнопки «Нашли ошибку?».
 *
 * @package Inc\Services\Log\Pages
 */
readonly class ContentReportLogPageProvider implements LogPageProviderInterface {

	public function __construct(
		private ContentReportLogRepository $repository,
	) {}

	public function channel(): LogChannel {
		return LogChannel::ContentReports;
	}

	/**
	 * @param LogPageQueryDTO $query Общий контекст страницы
	 *
	 * @return array<string, mixed>
	 */
	public function data( LogPageQueryDTO $query ): array {
		$filters = array_filter( $query->dateFilters() );

		return array(
			'report_filters' => $filters,
			'report_page'    => $query->page,
			'report_total'   => $this->repository->countFiltered( $filters ),
			'report_rows'    => $this->repository->list( $filters, $query->page, $query->perPage, $query->orderby, $query->order ),
		);
	}
}
