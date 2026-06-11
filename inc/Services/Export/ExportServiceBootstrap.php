<?php

declare( strict_types=1 );

namespace Inc\Services\Export;

use Inc\Contracts\ServiceInterface;
use Inc\Enums\ExportTarget;
use Inc\Services\Export\Log\AuthLogExportProvider;
use Inc\Services\Export\Log\ConsentChangeLogExportProvider;
use Inc\Services\Export\Log\DataChangeLogExportProvider;
use Inc\Services\Export\Log\DeletionLogExportProvider;
use Inc\Services\Export\Log\EmailLogExportProvider;
use Inc\Services\Export\Log\EnrollmentAuditLogExportProvider;
use Inc\Services\Export\Log\EntityAuditLogExportProvider;
use Inc\Services\Export\Log\ExportLogExportProvider;
use Inc\Services\Export\Log\PiiAccessLogExportProvider;

class ExportServiceBootstrap implements ServiceInterface {

	public function __construct(
		private readonly CsvExportProviderRegistry    $registry,
		private readonly GroupsExportProvider          $groups,
		private readonly StudentsExportProvider        $students,
		private readonly ParentsExportProvider         $parents,
		private readonly ArchiveExportProvider         $archive,
		private readonly EntityAuditLogExportProvider  $entityAudit,
		private readonly EnrollmentAuditLogExportProvider $enrollment,
		private readonly PiiAccessLogExportProvider    $piiAccess,
		private readonly ExportLogExportProvider       $exportLog,
		private readonly DataChangeLogExportProvider   $dataChange,
		private readonly ConsentChangeLogExportProvider $consentChange,
		private readonly EmailLogExportProvider        $email,
		private readonly DeletionLogExportProvider     $deletion,
		private readonly AuthLogExportProvider         $auth,
	) {}

	public function register(): void {
		$this->registry->register( ExportTarget::Groups,          $this->groups );
		$this->registry->register( ExportTarget::Students,        $this->students );
		$this->registry->register( ExportTarget::Parents,         $this->parents );
		$this->registry->register( ExportTarget::Archive,         $this->archive );
		$this->registry->register( ExportTarget::LogEntityAudit,  $this->entityAudit );
		$this->registry->register( ExportTarget::LogEnrollment,   $this->enrollment );
		$this->registry->register( ExportTarget::LogPiiAccess,    $this->piiAccess );
		$this->registry->register( ExportTarget::LogExport,       $this->exportLog );
		$this->registry->register( ExportTarget::LogDataChange,   $this->dataChange );
		$this->registry->register( ExportTarget::LogConsentChange,$this->consentChange );
		$this->registry->register( ExportTarget::LogEmail,        $this->email );
		$this->registry->register( ExportTarget::LogDeletion,     $this->deletion );
		$this->registry->register( ExportTarget::LogAuth,         $this->auth );
	}
}
