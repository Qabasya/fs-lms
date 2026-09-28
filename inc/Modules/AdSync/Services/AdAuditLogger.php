<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Services;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\AuditLogInputDTO;
use Inc\Enums\Log\AuditTargetType;
use Inc\Modules\AdSync\DTO\AdOutboxItemDTO;
use Inc\Modules\AdSync\Enums\AdAuditAction;
use Inc\Repositories\WPDBRepositories\Log\AuditLogRepository;

/**
 * Class AdAuditLogger
 *
 * Пишет итоги доставки в журнал «Зачисления»: что сделано с доменной учёткой
 * (создана, включена, отключена, пароль) и итоговые неудачи. Промежуточные
 * ошибки с ретраем в журнал не идут — только «мёртвое» задание.
 *
 * Автор записи — система (доставка идёт из cron / после ответа), поэтому без
 * пользователя и IP. Цель — ученик (лицо), для заданий по заявке — заявка.
 *
 * @package Inc\Modules\AdSync\Services
 */
class AdAuditLogger {

	public function __construct(
		private readonly AuditLogRepository $repository,
		private readonly ClockInterface     $clock,
	) {}

	public function done( AdOutboxItemDTO $item, string $outcome ): void {
		$note = 'absent' === $outcome ? 'учётки в домене нет — отключать нечего' : '';

		$this->write( $item, AdAuditAction::fromOutcome( $outcome, $item->event ), $note );
	}

	public function dead( AdOutboxItemDTO $item, string $error ): void {
		$this->write( $item, AdAuditAction::SyncFailed, $error );
	}

	private function write( AdOutboxItemDTO $item, AdAuditAction $action, string $note ): void {
		$byPerson = null !== $item->personId;

		$this->repository->create( new AuditLogInputDTO(
			actorUserId: null,
			actorRole:   null,
			action:      $action->value,
			targetType:  ( $byPerson ? AuditTargetType::Person : AuditTargetType::Application )->value,
			targetId:    $byPerson ? $item->personId : $item->applicationId,
			detailsJson: wp_json_encode( array_filter( array(
				'login' => $item->target,
				'note'  => '' !== $note ? mb_substr( $note, 0, 300 ) : null,
			) ) ) ?: null,
			actorIp:     '',
			actorUa:     null,
			createdAt:   $this->clock->now( 'mysql', true ),
		) );
	}
}
