<?php

declare( strict_types=1 );

namespace Inc\Services\Log\Pages;

use Inc\Contracts\LogPageProviderInterface;
use Inc\DTO\Log\LogPageQueryDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Repositories\WPDBRepositories\Log\AuditLogRepository;
use Inc\Shared\Traits\Sanitizer;

/**
 * Вкладка «Зачисления» — журнал действий в системе зачисления.
 *
 * @package Inc\Services\Log\Pages
 */
readonly class EnrollmentAuditLogPageProvider implements LogPageProviderInterface {

	use Sanitizer;

	/**
	 * @param AuditLogRepository $repository Журнал зачислений
	 * @param LogOptionsResolver $options    Подписи для фильтров
	 */
	public function __construct(
		private AuditLogRepository $repository,
		private LogOptionsResolver $options,
	) {}

	public function channel(): LogChannel {
		return LogChannel::EnrollmentAudit;
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
					'action'        => $this->sanitizeGetKey( 'action' ),
					'actor_user_id' => $query->actorId,
				),
				$query->dateFilters()
			)
		);

		return array(
			'audit_filters'       => $filters,
			'audit_page'          => $query->page,
			'audit_total'         => $this->repository->countFiltered( $filters ),
			'audit_rows'          => $this->repository->list( $filters, $query->page, $query->perPage, $query->orderby, $query->order ),
			'audit_actions'       => $this->repository->distinctActions(),
			'audit_actor_options' => $this->options->actors( $this->repository->distinctActorUserIds() ),
		);
	}
}
