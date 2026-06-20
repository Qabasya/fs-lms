<?php

declare( strict_types=1 );

namespace Inc\Services\Export;

use Inc\Contracts\ServiceInterface;
use Inc\Enums\Export\ExportTarget;
use Inc\Services\Export\Log\AuthLogExportProvider;
use Inc\Services\Export\Log\ConsentChangeLogExportProvider;
use Inc\Services\Export\Log\DataChangeLogExportProvider;
use Inc\Services\Export\Log\EmailLogExportProvider;
use Inc\Services\Export\Log\EnrollmentAuditLogExportProvider;
use Inc\Services\Export\Log\EntityAuditLogExportProvider;
use Inc\Services\Export\Log\ExportLogExportProvider;
use Inc\Services\Export\Log\PiiAccessLogExportProvider;

/**
 * Class ExportServiceBootstrap
 *
 * Класс для регистрации всех провайдеров CSV-экспорта в реестре.
 *
 * @package Inc\Services\Export
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация провайдеров** — добавление всех провайдеров экспорта в реестр
 *    с привязкой к соответствующим ExportTarget.
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс ServiceInterface для единообразной инициализации.
 * Вызывается при старте плагина для регистрации всех провайдеров экспорта.
 *
 * ### Принципы:
 *
 * - **Single Responsibility** — класс отвечает только за регистрацию провайдеров.
 * - **Dependency Injection** — все провайдеры внедряются через конструктор.
 * - **OCP (Open/Closed Principle)** — новый тип экспорта требует только
 *   новый провайдер + его регистрацию в этом классе.
 *
 * ### Поддерживаемые типы экспорта:
 *
 * - **Данные предметной области**: Groups, Students, Parents, Archive
 * - **Логи**: EntityAudit, Enrollment, PiiAccess, Export, DataChange,
 *   ConsentChange, Email, Deletion, Auth
 */
class ExportServiceBootstrap implements ServiceInterface {

	/**
	 * Конструктор бутстрапа.
	 *
	 * @param CsvExportProviderRegistry            $registry       Реестр провайдеров CSV-экспорта
	 * @param GroupsExportProvider                 $groups         Провайдер экспорта групп
	 * @param StudentsExportProvider               $students       Провайдер экспорта студентов
	 * @param ParentsExportProvider                $parents        Провайдер экспорта родителей
	 * @param ArchiveExportProvider                $archive        Провайдер экспорта архива
	 * @param EntityAuditLogExportProvider         $entityAudit    Провайдер экспорта аудита сущностей
	 * @param EnrollmentAuditLogExportProvider     $enrollment     Провайдер экспорта аудита зачислений
	 * @param PiiAccessLogExportProvider           $piiAccess      Провайдер экспорта доступа к PII
	 * @param ExportLogExportProvider              $exportLog      Провайдер экспорта журнала экспорта
	 * @param DataChangeLogExportProvider          $dataChange     Провайдер экспорта изменений данных
	 * @param ConsentChangeLogExportProvider       $consentChange  Провайдер экспорта изменений согласий
	 * @param EmailLogExportProvider               $email          Провайдер экспорта отправки email
	 * @param AuthLogExportProvider                $auth           Провайдер экспорта аутентификации
	 */
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
		private readonly AuthLogExportProvider         $auth,
	) {}

	/**
	 * Регистрирует все провайдеры экспорта в реестре.
	 *
	 * @return void
	 */
	public function register(): void {
		// Доменные данные
		$this->registry->register( ExportTarget::Groups,   $this->groups );
		$this->registry->register( ExportTarget::Students, $this->students );
		$this->registry->register( ExportTarget::Parents,  $this->parents );
		$this->registry->register( ExportTarget::Archive,  $this->archive );

		// Журналы аудита
		$this->registry->register( ExportTarget::LogEntityAudit,  $this->entityAudit );
		$this->registry->register( ExportTarget::LogEnrollment,   $this->enrollment );
		$this->registry->register( ExportTarget::LogPiiAccess,    $this->piiAccess );
		$this->registry->register( ExportTarget::LogExport,       $this->exportLog );
		$this->registry->register( ExportTarget::LogDataChange,   $this->dataChange );
		$this->registry->register( ExportTarget::LogConsentChange, $this->consentChange );
		$this->registry->register( ExportTarget::LogEmail,        $this->email );
		$this->registry->register( ExportTarget::LogAuth,         $this->auth );
	}
}