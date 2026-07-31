<?php

declare( strict_types=1 );

namespace Inc\Services\Log\Pages;

use Inc\Contracts\LogPageProviderInterface;
use Inc\DTO\Log\LogPageQueryDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Repositories\WPDBRepositories\Log\EmailLogRepository;
use Inc\Shared\Traits\Sanitizer;

/**
 * Вкладка «Письма» — журнал отправки email.
 *
 * @package Inc\Services\Log\Pages
 */
readonly class EmailLogPageProvider implements LogPageProviderInterface {

	use Sanitizer;

	/**
	 * @param EmailLogRepository $repository Журнал писем
	 * @param LogOptionsResolver $options    Подписи для фильтров
	 */
	public function __construct(
		private EmailLogRepository $repository,
		private LogOptionsResolver $options,
	) {}

	public function channel(): LogChannel {
		return LogChannel::Email;
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
					'email_type'       => $this->sanitizeGetKey( 'email_type' ),
					'status'           => $this->sanitizeGetKey( 'status' ),
					'target_person_id' => $query->personId,
				),
				$query->dateFilters()
			)
		);

		return array(
			'email_filters'        => $filters,
			'email_page'           => $query->page,
			'email_total'          => $this->repository->countFiltered( $filters ),
			'email_rows'           => $this->repository->list( $filters, $query->page, $query->perPage, $query->orderby, $query->order ),
			'email_type_options'   => $this->repository->distinctEmailTypes(),
			'email_person_options' => $this->options->persons( $this->repository->distinctPersonIds() ),
		);
	}
}
