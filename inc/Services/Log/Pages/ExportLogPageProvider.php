<?php

declare( strict_types=1 );

namespace Inc\Services\Log\Pages;

use Inc\Contracts\LogPageProviderInterface;
use Inc\DTO\Log\LogPageQueryDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Repositories\WPDBRepositories\Log\ExportLogRepository;
use Inc\Shared\Traits\Sanitizer;

/**
 * Вкладка «Экспорт» — журнал выгрузок данных.
 *
 * @package Inc\Services\Log\Pages
 */
readonly class ExportLogPageProvider implements LogPageProviderInterface {

	use Sanitizer;

	/**
	 * @param ExportLogRepository $repository Журнал экспорта
	 * @param LogOptionsResolver  $options    Подписи для фильтров
	 */
	public function __construct(
		private ExportLogRepository $repository,
		private LogOptionsResolver  $options,
	) {}

	public function channel(): LogChannel {
		return LogChannel::Export;
	}

	/**
	 * @param LogPageQueryDTO $query Общий контекст страницы
	 *
	 * @return array<string, mixed>
	 */
	public function data( LogPageQueryDTO $query ): array {
		$filters = array_filter(
			array_merge(
				array(
					'actor_user_id' => $query->actorId,
					'data_type'     => $this->sanitizeGetKey( 'data_type' ),
				),
				$query->dateFilters()
			)
		);

		return array(
			'export_filters'       => $filters,
			'export_page'          => $query->page,
			'export_total'         => $this->repository->countFiltered( $filters ),
			'export_rows'          => $this->repository->list( $filters, $query->page, $query->perPage, $query->orderby, $query->order ),
			'export_data_types'    => $this->repository->distinctDataTypes(),
			'export_actor_options' => $this->options->actors( $this->repository->distinctActorUserIds() ),
		);
	}
}
