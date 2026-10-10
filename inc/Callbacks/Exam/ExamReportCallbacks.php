<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Exam\ExamReportService;
use Inc\Shared\CodedException;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX школьных отчётов (этап 12.2): список, создание, состав, выдача и отзыв ссылки.
 *
 * Право `ShareExamResults` и nonce `ExamManage`; доступ к проведению проверяет сервис. Выдача и копирование ссылки не требуют
 * `ExportPII` и `ManageLmsPlatform`: ссылка отчёта ПД не содержит, а отчёт — только чтение (без контактов).
 */
class ExamReportCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly ExamReportService $reports,
	) {
		parent::__construct();
	}

	public function ajaxGetExamReports(): void {
		$this->authorize( Nonce::ExamManage, Capability::ShareExamResults );

		$eventId = $this->requireInt( 'event_id' );

		$this->run( fn (): array => $this->reports->overview( get_current_user_id(), $eventId ) );
	}

	public function ajaxSaveExamReport(): void {
		$this->authorize( Nonce::ExamManage, Capability::ShareExamResults );

		$eventId  = $this->requireInt( 'event_id' );
		$title    = $this->sanitizeText( 'title' );
		$ids      = $this->sanitizeIntList( 'participation_ids' );
		$source   = $this->sanitizeInt( 'recipient_source_id' );
		$days     = $this->sanitizeInt( 'days' );

		$this->run( function () use ( $eventId, $title, $ids, $source, $days ): array {
			$report = $this->reports->create( get_current_user_id(), $eventId, $title, $ids, $source > 0 ? $source : null, $days > 0 ? $days : null );

			return array( 'report_id' => $report->id ) + $this->reports->overview( get_current_user_id(), $eventId );
		} );
	}

	public function ajaxAddExamReportMember(): void {
		$this->authorize( Nonce::ExamManage, Capability::ShareExamResults );

		$reportId        = $this->requireInt( 'report_id' );
		$participationId = $this->requireInt( 'participation_id' );
		$version         = $this->sanitizeInt( 'version' );

		$this->run( function () use ( $reportId, $participationId, $version ): array {
			$report = $this->reports->addMember( get_current_user_id(), $reportId, $participationId, $version );

			return $this->reports->overview( get_current_user_id(), $report->eventId );
		} );
	}

	public function ajaxRemoveExamReportMember(): void {
		$this->authorize( Nonce::ExamManage, Capability::ShareExamResults );

		$reportId        = $this->requireInt( 'report_id' );
		$participationId = $this->requireInt( 'participation_id' );
		$version         = $this->sanitizeInt( 'version' );

		$this->run( function () use ( $reportId, $participationId, $version ): array {
			$report = $this->reports->removeMember( get_current_user_id(), $reportId, $participationId, $version );

			return $this->reports->overview( get_current_user_id(), $report->eventId );
		} );
	}

	public function ajaxIssueExamReportLink(): void {
		$this->authorize( Nonce::ExamManage, Capability::ShareExamResults );

		$reportId = $this->requireInt( 'report_id' );

		$this->run( fn (): array => array( 'url' => $this->reports->issueLink( get_current_user_id(), $reportId ) ) );
	}

	public function ajaxRevokeExamReportLink(): void {
		$this->authorize( Nonce::ExamManage, Capability::ShareExamResults );

		$reportId = $this->requireInt( 'report_id' );
		$version  = $this->sanitizeInt( 'version' );

		$this->run( function () use ( $reportId, $version ): array {
			$report = $this->reports->revoke( get_current_user_id(), $reportId, $version );

			return $this->reports->overview( get_current_user_id(), $report->eventId );
		} );
	}

	/**
	 * Успех — данными, отказ сервиса с кодом — `fail()`.
	 *
	 * @param callable():array<string, mixed> $action
	 */
	private function run( callable $action ): void {
		try {
			$this->success( $action() );
		} catch ( CodedException $e ) {
			$this->fail( $e->errorCode, $e->getMessage() );
		}
	}
}
