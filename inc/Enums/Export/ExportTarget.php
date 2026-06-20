<?php

declare( strict_types=1 );

namespace Inc\Enums\Export;

enum ExportTarget: string {
	// Доменные датасеты
	case Groups   = 'groups';
	case Students = 'students';
	case Parents  = 'parents';
	case Archive  = 'archive';

	// Лог-каналы
	case LogEntityAudit    = 'log_entity_audit';
	case LogEnrollment     = 'log_enrollment';
	case LogPiiAccess      = 'log_pii_access';
	case LogExport         = 'log_export';
	case LogDataChange     = 'log_data_change';
	case LogConsentChange  = 'log_consent_change';
	case LogEmail          = 'log_email';
	case LogAuth           = 'log_auth';

	public function label(): string {
		return match( $this ) {
			self::Groups             => 'Группы',
			self::Students           => 'Ученики',
			self::Parents            => 'Родители',
			self::Archive            => 'Архив',
			self::LogEntityAudit     => 'Лог: действия с сущностями',
			self::LogEnrollment      => 'Лог: путь зачисления',
			self::LogPiiAccess       => 'Лог: доступ к ПД',
			self::LogExport          => 'Лог: экспорт',
			self::LogDataChange      => 'Лог: изменения данных',
			self::LogConsentChange   => 'Лог: согласия',
			self::LogEmail           => 'Лог: письма',
			self::LogAuth            => 'Лог: аутентификация',
		};
	}
}
