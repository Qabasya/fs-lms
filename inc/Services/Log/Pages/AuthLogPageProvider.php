<?php

declare( strict_types=1 );

namespace Inc\Services\Log\Pages;

use Inc\Contracts\LogPageProviderInterface;
use Inc\DTO\Log\LogPageQueryDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Repositories\WPDBRepositories\Log\AuthLogRepository;
use Inc\Shared\Traits\Sanitizer;

/**
 * Вкладка «Аутентификация» — журнал входов и сбросов пароля.
 *
 * @package Inc\Services\Log\Pages
 */
readonly class AuthLogPageProvider implements LogPageProviderInterface {

	use Sanitizer;

	/**
	 * @param AuthLogRepository $repository Журнал аутентификации
	 */
	public function __construct(
		private AuthLogRepository $repository,
	) {}

	public function channel(): LogChannel {
		return LogChannel::Auth;
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
					'action' => $this->sanitizeGetKey( 'action' ),
					'result' => $this->sanitizeGetKey( 'result' ),
				),
				$query->dateFilters()
			)
		);

		return array(
			'auth_filters' => $filters,
			'auth_page'    => $query->page,
			'auth_total'   => $this->repository->countFiltered( $filters ),
			'auth_rows'    => $this->repository->list( $filters, $query->page, $query->perPage, $query->orderby, $query->order ),
			'auth_actions' => $this->repository->distinctActions(),
		);
	}
}
