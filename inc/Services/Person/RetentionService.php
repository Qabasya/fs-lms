<?php

declare( strict_types=1 );

namespace Inc\Services\Person;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\EntityHardDeletedEvent;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\Log\AuditLogRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\Log\PiiAccessLogRepository;
use Inc\Services\Person\PersonService;

class RetentionService {

	public function __construct(
		private readonly PersonRepository            $personRepository,
		private readonly ApplicationRepository       $applicationRepository,
		private readonly AuditLogRepository          $auditLogRepository,
		private readonly PiiAccessLogRepository      $piiAccessLogRepository,
		private readonly LogEventDispatcherInterface $logEvents,
		private readonly UserManager                 $userManager,
		private readonly PersonService               $personService,
	) {}

	public function anonymizeDeletedPersons(): int {
		$persons = $this->personRepository->findDeletedOlderThan( 30 );
		$count   = 0;

		foreach ( $persons as $person ) {
			$this->personService->anonymize( $person->id );

			if ( null !== $person->wpUserId ) {
				$this->userManager->randomizePassword( $person->wpUserId );
			}

			$this->logEvents->dispatch(
				LogEvent::EntityHardDeleted,
				new EntityHardDeletedEvent( 0, 'person', $person->id, 'pii_anonymized' )
			);

			$count++;
		}

		return $count;
	}

	public function purgeExpiredApplications(): int {
		return $this->applicationRepository->purgeExpiredOlderThan( 6, array( 'expired', 'trash' ) );
	}

	public function purgeOldAuditLogs(): int {
		return $this->auditLogRepository->purgeOlderThan( 3 * 365 );
	}

	public function purgeOldPiiAccessLogs(): int {
		return $this->piiAccessLogRepository->purgeOlderThan( 5 * 365 );
	}
}