<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\ServiceInterface;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\LogEvent;
use Inc\Services\Log\EntityAuditLogWriter;

/**
 * Subscriber канала EntityAudit.
 *
 * Подписывается на все entity-события шины и делегирует EntityAuditLogWriter.
 * Не содержит бизнес-логики — только маппинг событие → writer.
 */
class EntityAuditSubscriber implements ServiceInterface {

	public function __construct(
		private readonly LogEventDispatcherInterface $dispatcher,
		private readonly EntityAuditLogWriter        $writer,
	) {}

	public function register(): void {
		$entityEvents = array(
			LogEvent::SubjectCreated,
			LogEvent::SubjectUpdated,
			LogEvent::SubjectDeleted,
			LogEvent::TaxonomyCreated,
			LogEvent::TaxonomyUpdated,
			LogEvent::TaxonomyDeleted,
			LogEvent::TemplateCreated,
			LogEvent::TemplateUpdated,
			LogEvent::TemplateDeleted,
			LogEvent::BoilerplateCreated,
			LogEvent::BoilerplateUpdated,
			LogEvent::BoilerplateDeleted,
			LogEvent::TaskCreated,
			LogEvent::TaskUpdated,
			LogEvent::TaskDeleted,
			LogEvent::ArticleCreated,
			LogEvent::ArticleUpdated,
			LogEvent::ArticleDeleted,
			LogEvent::GroupCreated,
			LogEvent::GroupUpdated,
			LogEvent::GroupDeleted,
			LogEvent::PeriodCreated,
			LogEvent::PeriodUpdated,
			LogEvent::PeriodDeleted,
			LogEvent::UserCreated,
			LogEvent::UserUpdated,
			LogEvent::UserDeleted,
		);

		foreach ( $entityEvents as $event ) {
			$this->dispatcher->subscribe( $event, array( $this, 'handle' ) );
		}
	}

	public function handle( EntityChangedEvent $payload ): void {
		$this->writer->record(
			$payload->actorUserId,
			$payload->operation,
			$payload->entityType,
			$payload->entityId,
			$payload->oldLabel,
		);
	}
}
