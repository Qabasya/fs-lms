<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum LogChannel: string {
	case EnrollmentAudit = 'enrollment_audit';
	case PiiAccess       = 'pii_access';
	case Export          = 'export';
	case DataChange      = 'data_change';
	case ConsentChange   = 'consent_change';
	case Email           = 'email';
	case Deletion        = 'deletion';
	case Auth            = 'auth';

	public function label(): string {
		return match ( $this ) {
			self::EnrollmentAudit => 'Журнал действий зачисления',
			self::PiiAccess       => 'Журнал доступа к ПД',
			self::Export          => 'Журнал экспорта',
			self::DataChange      => 'Журнал изменений данных',
			self::ConsentChange   => 'Журнал согласий',
			self::Email           => 'Журнал писем',
			self::Deletion        => 'Журнал удалений',
			self::Auth            => 'Журнал аутентификации',
		};
	}

	public function tableName(): TableName {
		return match ( $this ) {
			self::EnrollmentAudit => TableName::AuditLog,
			self::PiiAccess       => TableName::PiiAccessLog,
			self::Export          => TableName::ExportLog,
			self::DataChange      => TableName::DataChangeLog,
			self::ConsentChange   => TableName::ConsentChangeLog,
			self::Email           => TableName::EmailLog,
			self::Deletion        => TableName::DeletionLog,
			self::Auth            => TableName::AuthLog,
		};
	}
}
