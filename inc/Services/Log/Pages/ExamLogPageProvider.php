<?php

declare( strict_types=1 );

namespace Inc\Services\Log\Pages;

use Inc\Contracts\LogPageProviderInterface;
use Inc\DTO\Log\LogPageQueryDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Repositories\WPDBRepositories\Log\ExamLogRepository;
use Inc\Shared\Traits\Sanitizer;

/**
 * Вкладка «Экзамены» — действия с проведениями, сеансами, заявками и попытками.
 *
 * @package Inc\Services\Log\Pages
 */
readonly class ExamLogPageProvider implements LogPageProviderInterface {

	use Sanitizer;

	public function __construct(
		private ExamLogRepository $repository,
	) {}

	public function channel(): LogChannel {
		return LogChannel::Exams;
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
					'action'          => $this->sanitizeGetKey( 'action' ),
					'result'          => $this->sanitizeGetKey( 'result' ),
					'event_id'        => $this->sanitizeGetInt( 'event_id' ),
					'session_id'      => $this->sanitizeGetInt( 'session_id' ),
					'registration_id' => $this->sanitizeGetInt( 'registration_id' ),
					'person'          => $this->sanitizeGetText( 'person' ),
				),
				$query->dateFilters()
			)
		);

		return array(
			'exam_filters' => $filters,
			'exam_page'    => $query->page,
			'exam_total'   => $this->repository->countFiltered( $filters ),
			'exam_rows'    => $this->repository->list( $filters, $query->page, $query->perPage, $query->orderby, $query->order ),
			'exam_actions' => $this->repository->distinctActions(),
		);
	}
}
