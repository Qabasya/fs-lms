<?php

declare( strict_types=1 );

namespace Inc\Services\Log\Pages;

use Inc\Contracts\LogPageProviderInterface;
use Inc\DTO\Log\LogPageQueryDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Repositories\WPDBRepositories\Log\ConsentChangeLogRepository;
use Inc\Shared\Traits\Sanitizer;

/**
 * Вкладка «Согласия» — журнал изменений версий согласий.
 *
 * @package Inc\Services\Log\Pages
 */
readonly class ConsentChangeLogPageProvider implements LogPageProviderInterface {

	use Sanitizer;

	/**
	 * @param ConsentChangeLogRepository $repository Журнал согласий
	 */
	public function __construct(
		private ConsentChangeLogRepository $repository,
	) {}

	public function channel(): LogChannel {
		return LogChannel::ConsentChange;
	}

	/**
	 * @param LogPageQueryDTO $query Общий контекст страницы
	 *
	 * @return array<string, mixed>
	 */
	public function data( LogPageQueryDTO $query ): array {
		$filters = array_filter(
			array_merge(
				array( 'consent_type' => $this->sanitizeGetKey( 'consent_type' ) ),
				$query->dateFilters()
			)
		);

		return array(
			'consent_filters'      => $filters,
			'consent_page'         => $query->page,
			'consent_total'        => $this->repository->countFiltered( $filters ),
			'consent_rows'         => $this->repository->list( $filters, $query->page, $query->perPage, $query->orderby, $query->order ),
			'consent_type_options' => $this->repository->distinctConsentTypes(),
		);
	}
}
