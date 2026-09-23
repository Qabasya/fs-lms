<?php

declare( strict_types=1 );

namespace Inc\Services\Log\Pages;

use Inc\Contracts\LogPageProviderInterface;
use Inc\DTO\Log\LogPageQueryDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Repositories\WPDBRepositories\Log\ErrorLogRepository;
use Inc\Shared\Traits\Sanitizer;

/**
 * Вкладка «Ошибки» — что пошло не так у пользователей: код, номер инцидента,
 * кто, где, с какого IP.
 *
 * @package Inc\Services\Log\Pages
 */
readonly class ErrorLogPageProvider implements LogPageProviderInterface {

	use Sanitizer;

	public function __construct(
		private ErrorLogRepository $repository,
	) {}

	public function channel(): LogChannel {
		return LogChannel::Errors;
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
					'code'    => strtoupper( $this->sanitizeText( 'code', 'GET' ) ),
					'ref'     => strtoupper( ltrim( $this->sanitizeText( 'ref', 'GET' ), '#' ) ),
					'user_id' => $this->sanitizeInt( 'user_id', 'GET' ) ?: null,
					'source'  => $this->sanitizeGetKey( 'source' ),
				),
				$query->dateFilters()
			)
		);

		return array(
			'error_filters' => $filters,
			'error_page'    => $query->page,
			'error_total'   => $this->repository->countFiltered( $filters ),
			'error_rows'    => $this->repository->list( $filters, $query->page, $query->perPage, $query->orderby, $query->order ),
			'error_codes'   => $this->repository->distinctCodes(),
		);
	}
}
