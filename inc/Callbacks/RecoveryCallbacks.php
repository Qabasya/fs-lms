<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Services\ApplicationService;
use Inc\Services\RecoveryService;
use Inc\Services\RetentionService;

/**
 * Class RecoveryCallbacks
 *
 * Cron-коллбеки для recovery и retention задач.
 *
 * @package Inc\Callbacks
 */
class RecoveryCallbacks extends BaseController {

	public function __construct(
		private readonly RecoveryService  $recoveryService,
		private readonly ApplicationService $applicationService,
		private readonly RetentionService $retentionService,
	) {
		parent::__construct();
	}

	public function cronRecoveryTick(): void {
		try {
			$this->recoveryService->resolveStuckEnrollments();
		} catch ( \Throwable $e ) {
			error_log( '[FS LMS] Recovery tick error: ' . $e->getMessage() );
		}
	}

	public function cronExpireApplications(): void {
		try {
			$this->applicationService->expireStale();
		} catch ( \Throwable $e ) {
			error_log( '[FS LMS] Expire applications error: ' . $e->getMessage() );
		}
	}

	public function cronRetentionCleanup(): void {
		try {
			$this->retentionService->anonymizeDeletedPersons();
			$this->retentionService->purgeExpiredApplications();
			$this->retentionService->purgeOldAuditLogs();
			$this->retentionService->purgeOldPiiAccessLogs();
		} catch ( \Throwable $e ) {
			error_log( '[FS LMS] Retention cleanup error: ' . $e->getMessage() );
		}
	}
}