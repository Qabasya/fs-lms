<?php

declare( strict_types=1 );

namespace Inc\Services\Log\Pages;

use Inc\Contracts\LogPageProviderInterface;
use Inc\DTO\Log\LogPageQueryDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Repositories\WPDBRepositories\Log\PiiAccessLogRepository;

/**
 * Вкладка «Доступ к ПД» — журнал обращений сотрудников к персональным данным.
 *
 * @package Inc\Services\Log\Pages
 */
readonly class PiiAccessLogPageProvider implements LogPageProviderInterface {

	/**
	 * @param PiiAccessLogRepository $repository Журнал доступа к ПД
	 */
	public function __construct(
		private PiiAccessLogRepository $repository,
	) {}

	public function channel(): LogChannel {
		return LogChannel::PiiAccess;
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
					'person_id'     => $query->personId,
				),
				$query->dateFilters()
			)
		);

		return array(
			'pii_filters' => $filters,
			'pii_page'    => $query->page,
			'pii_total'   => $this->repository->countFiltered( $filters ),
			'pii_rows'    => $this->repository->list( $filters, $query->page, $query->perPage, $query->orderby, $query->order ),
		);
	}
}
