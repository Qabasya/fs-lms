<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Enums;

/**
 * Действия модуля в журнале «Зачисления»: что сервер в офисе сделал с доменной учёткой.
 * Вне core AuditAction — ядро о модуле не знает; подписи журнал получает фильтром
 * `fs_lms_audit_action_label` ({@see \Inc\Modules\AdSync\Controllers\AdAuditLabelController}).
 *
 * @package Inc\Modules\AdSync\Enums
 */
enum AdAuditAction: string {
	case AccountCreated     = 'ad_account_created';
	case AccountReactivated = 'ad_account_reactivated';
	case AccountUpdated     = 'ad_account_updated';
	case AccountDisabled    = 'ad_account_disabled';
	case PasswordChanged    = 'ad_password_changed';
	/** Задание так и не выполнено («мёртвое») — нужен администратор. */
	case SyncFailed         = 'ad_sync_failed';

	public function label(): string {
		return match ( $this ) {
			self::AccountCreated     => 'Домен: учётка создана',
			self::AccountReactivated => 'Домен: учётка включена (повторное зачисление)',
			self::AccountUpdated     => 'Домен: учётка обновлена',
			self::AccountDisabled    => 'Домен: учётка отключена',
			self::PasswordChanged    => 'Домен: пароль изменён',
			self::SyncFailed         => 'Домен: ошибка синхронизации',
		};
	}

	/**
	 * Действие по итогу сервера (`outcome`) и типу задания. Сервер без `outcome`
	 * (старая версия) — по типу задания.
	 */
	public static function fromOutcome( string $outcome, string $event ): self {
		return match ( $outcome ) {
			'created'          => self::AccountCreated,
			'reactivated'      => self::AccountReactivated,
			'updated'          => self::AccountUpdated,
			'deprovisioned',
			'absent'           => self::AccountDisabled,
			'password_changed' => self::PasswordChanged,
			default            => match ( $event ) {
				AdSyncEvent::Deprovision->value => self::AccountDisabled,
				AdSyncEvent::Password->value    => self::PasswordChanged,
				default                         => self::AccountUpdated,
			},
		};
	}
}
