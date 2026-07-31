<?php

declare( strict_types=1 );

namespace Inc\Services\Log\Pages;

use Inc\Contracts\LogPageProviderInterface;
use Inc\DTO\Log\LogPageQueryDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Repositories\WPDBRepositories\Log\DataChangeLogRepository;

/**
 * Вкладка «Изменения данных» — журнал правок персональных данных.
 *
 * @package Inc\Services\Log\Pages
 */
readonly class DataChangeLogPageProvider implements LogPageProviderInterface {

	/**
	 * @param DataChangeLogRepository $repository Журнал изменений данных
	 * @param LogOptionsResolver      $options    Подписи для фильтров
	 */
	public function __construct(
		private DataChangeLogRepository $repository,
		private LogOptionsResolver      $options,
	) {}

	public function channel(): LogChannel {
		return LogChannel::DataChange;
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
					'actor_user_id'    => $query->actorId,
					'target_person_id' => $query->personId,
				),
				$query->dateFilters()
			)
		);

		return array(
			'data_change_filters'        => $filters,
			'data_change_page'           => $query->page,
			'data_change_total'          => $this->repository->countFiltered( $filters ),
			'data_change_rows'           => $this->repository->list( $filters, $query->page, $query->perPage, $query->orderby, $query->order ),
			'data_change_actor_options'  => $this->options->actors( $this->repository->distinctActorUserIds() ),
			'data_change_person_options' => $this->options->persons( $this->repository->distinctPersonIds() ),
		);
	}
}
