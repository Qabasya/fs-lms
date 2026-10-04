<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Exam\ExamSourceService;
use Inc\Shared\CodedException;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX источников приглашений на экзамен (этап 4.6): школа, класс, преподаватель и пригласительная ссылка.
 *
 * Под `Capability::ManageExamGuests` и nonce `ExamManage`. Выдача и копирование ссылок **не требуют** `ExportPII` и `ManageLmsPlatform`
 * (SPEC §2): персональные данные тут не выгружаются. В списке нет ни ключа, ни хеша: открытый ключ приходит один раз —
 * в ответе выдачи и перевыпуска, поле `url`.
 */
class ExamSourceCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly ExamSourceService $sources,
	) {
		parent::__construct();
	}

	public function ajaxGetExamSources(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExamGuests );

		$eventId = $this->requireInt( 'event_id' );

		$this->run( fn (): array => array( 'sources' => $this->sources->list( get_current_user_id(), $eventId ) ) );
	}

	public function ajaxSaveExamSource(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExamGuests );

		$eventId  = $this->requireInt( 'event_id' );
		$sourceId = $this->sanitizeInt( 'source_id' );
		$version  = $this->hasParam( 'version' ) ? $this->sanitizeInt( 'version' ) : null;
		$input    = array(
			'school_name'  => $this->sanitizeText( 'school_name' ),
			'teacher_name' => $this->sanitizeText( 'teacher_name' ),
			'grade'        => $this->sanitizeInt( 'grade' ),
		);

		$this->run( function () use ( $eventId, $sourceId, $version, $input ): array {
			$source = $this->sources->save( get_current_user_id(), $eventId, $input, $sourceId > 0 ? $sourceId : null, $sourceId > 0 ? $version : null );

			return array( 'source_id' => $source->id, 'sources' => $this->sources->list( get_current_user_id(), $eventId ) );
		} );
	}

	public function ajaxIssueExamSourceLink(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExamGuests );

		$sourceId = $this->requireInt( 'source_id' );

		$this->run( fn (): array => array( 'url' => $this->sources->issueLink( get_current_user_id(), $sourceId ) ) );
	}

	public function ajaxReissueExamSourceLink(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExamGuests );

		$sourceId = $this->requireInt( 'source_id' );

		$this->run( fn (): array => array( 'url' => $this->sources->reissueLink( get_current_user_id(), $sourceId ) ) );
	}

	public function ajaxRevokeExamSourceLink(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExamGuests );

		$sourceId = $this->requireInt( 'source_id' );

		$this->run( function () use ( $sourceId ): array {
			$this->sources->revokeLink( get_current_user_id(), $sourceId );

			return array( 'source_id' => $sourceId );
		} );
	}

	public function ajaxToggleExamSource(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExamGuests );

		$sourceId = $this->requireInt( 'source_id' );
		$active   = $this->sanitizeBool( 'active' );

		$this->run( function () use ( $sourceId, $active ): array {
			$this->sources->setActive( get_current_user_id(), $sourceId, $active );

			return array( 'source_id' => $sourceId, 'active' => $active );
		} );
	}

	/**
	 * Выполняет действие и отвечает: успех — данными, отказ сервиса с кодом — `fail()`, прочая ошибка проверки — `error()`.
	 *
	 * @param callable():array<string, mixed> $action
	 */
	private function run( callable $action ): void {
		try {
			$this->success( $action() );
		} catch ( CodedException $e ) {
			$this->fail( $e->errorCode, $e->getMessage() );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		}
	}
}
