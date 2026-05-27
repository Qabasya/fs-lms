<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum CronHook: string {
	/** Истечение stale-заявок (pending_parent / ready_for_review с истёкшим join_code_expires_at) */
	case ExpireApplications = 'fs_lms_expire_applications';

	/** Retention-очистка: анонимизация persons, purge старых заявок и логов */
	case RetentionCleanup = 'fs_lms_retention_cleanup';

	/** Восстановление застрявших зачислений (статус Enrolling без изменений > N минут) */
	case RecoveryTick = 'fs_lms_recovery_tick';
}