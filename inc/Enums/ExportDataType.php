<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum ExportDataType: string {
	case Groups   = 'groups';
	case Students = 'students';
	case Parents  = 'parents';
	case Archive  = 'archive';
	case EntityLog  = 'log_entity_audit';
	case EnrollmentLog  = 'log_enrollment';
	case PIIAccessLog  = 'log_pii_access';
	case ExportImportLog  = 'log_export';
	case DataChangeLog  = 'log_data_change';
	case ConsentChangeLog  = 'log_consent_change';
	case EmailLog  = 'log_email';
	case AuthLog  = 'log_auth';


	public function label(): string {
		return match ( $this ) {
			self::Groups   => 'Группы',
			self::Students => 'Ученики',
			self::Parents  => 'Родители',
			self::Archive  => 'Архив',
			self::EntityLog     => 'Журнал действий',
			self::EnrollmentLog     => 'Журнал зачислений',
			self::PIIAccessLog     => 'Журнал доступа к ПД',
			self::ExportImportLog     => 'Журнал экспорта/импорта',
			self::DataChangeLog     => 'Журнал изменения данных',
			self::ConsentChangeLog     => 'Журнал подписи согласий',
			self::EmailLog     => 'Журнал отправки писем',
			self::AuthLog     => 'Журнал авторизации',
		};
	}
}
