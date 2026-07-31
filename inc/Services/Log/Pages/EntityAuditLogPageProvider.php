<?php

declare( strict_types=1 );

namespace Inc\Services\Log\Pages;

use Inc\Contracts\LogPageProviderInterface;
use Inc\DTO\Log\LogPageQueryDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Repositories\WPDBRepositories\Log\EntityAuditLogRepository;
use Inc\Shared\Traits\Sanitizer;

/**
 * Вкладка «Действия» — журнал изменений сущностей.
 *
 * @package Inc\Services\Log\Pages
 */
readonly class EntityAuditLogPageProvider implements LogPageProviderInterface {

	use Sanitizer;

	/**
	 * @param EntityAuditLogRepository $repository Журнал изменений сущностей
	 * @param LogOptionsResolver       $options    Подписи для фильтров
	 */
	public function __construct(
		private EntityAuditLogRepository $repository,
		private LogOptionsResolver       $options,
	) {}

	public function channel(): LogChannel {
		return LogChannel::EntityAudit;
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
					'operation'     => $this->sanitizeGetKey( 'operation' ),
					'entity_type'   => $this->sanitizeGetKey( 'entity_type' ),
					'actor_user_id' => $query->actorId,
				),
				$query->dateFilters()
			)
		);

		return array(
			'entity_audit_filters'       => $filters,
			'entity_audit_page'          => $query->page,
			'entity_audit_total'         => $this->repository->countFiltered( $filters ),
			'entity_audit_rows'          => $this->repository->list( $filters, $query->page, $query->perPage, $query->orderby, $query->order ),
			'entity_audit_operations'    => $this->repository->distinctOperations(),
			'entity_audit_types'         => $this->repository->distinctEntityTypes(),
			'entity_audit_actor_options' => $this->options->actors( $this->repository->distinctActorUserIds() ),
		);
	}
}
