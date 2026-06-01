<?php

declare( strict_types=1 );

namespace Inc\Services\Person;

use Inc\Enums\TableName;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\AuditLogRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\PiiAccessLogRepository;
use Inc\Services\AuditService;

class RetentionService {

	private \wpdb $wpdb;

	public function __construct(
		private readonly PersonRepository      $personRepository,
		private readonly ApplicationRepository $applicationRepository,
		private readonly AuditLogRepository    $auditLogRepository,
		private readonly PiiAccessLogRepository $piiAccessLogRepository,
		private readonly AuditService          $auditService,
		private readonly UserManager           $userManager,
		?\wpdb $wpdb = null,
	) {
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
	}

	public function anonymizeDeletedPersons(): int {
		$persons = $this->personRepository->findDeletedOlderThan( 30 );
		$count   = 0;

		foreach ( $persons as $person ) {
			$this->personRepository->anonymize( $person->id );

			if ( null !== $person->wpUserId ) {
				$this->userManager->randomizePassword( $person->wpUserId );
			}

			$this->auditService->record( 'anonymize_person', 'person', $person->id );

			$count++;
		}

		return $count;
	}

	public function purgeExpiredApplications(): int {
		$table        = TableName::Applications->prefixed();
		$statuses     = array( 'expired', 'trash' );
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM %i WHERE status IN ($placeholders) AND updated_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)",
				array_merge( array( $table ), $statuses )
			)
		);

		return (int) $this->wpdb->rows_affected;
	}

	public function purgeOldAuditLogs(): int {
		return $this->auditLogRepository->purgeOlderThan( 3 * 365 );
	}

	public function purgeOldPiiAccessLogs(): int {
		return $this->piiAccessLogRepository->purgeOlderThan( 5 * 365 );
	}
}