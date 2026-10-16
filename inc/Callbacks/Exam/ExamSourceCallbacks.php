<?php
/**
 * AJAX источников приглашений на экзамен (этап 4.6): школа, класс, преподаватель и пригласительная ссылка.
 *
 * @package FS_LMS
 */

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

	/** Делегирует проверку области доступа и жизненный цикл приглашения сервису источников. */
	public function __construct(
		private readonly ExamSourceService $sources,
	) {
		parent::__construct();
	}

	/** Возвращает источники приглашений выбранного проведения. */
	public function ajaxGetExamSources(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExamGuests );

		$eventId = $this->requireInt( 'event_id' );

		$this->run(
			fn (): array => array(
				'sources' => $this->sources->list( get_current_user_id(), $eventId ),
				'public'  => $this->sources->publicRegistration( get_current_user_id(), $eventId ),
			)
		);
	}

	/** Включает или выключает открытую запись проведения (гость сам выбирает класс на странице записи). */
	public function ajaxSetExamPublicRegistration(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExamGuests );

		$eventId = $this->requireInt( 'event_id' );
		$enabled = $this->sanitizeBool( 'enabled' );

		$this->run( fn (): array => array( 'public' => $this->sources->setPublicRegistration( get_current_user_id(), $eventId, $enabled ) ) );
	}

	/** Сохраняет источник и возвращает обновлённый список источников. */
	public function ajaxSaveExamSource(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExamGuests );

		$eventId  = $this->requireInt( 'event_id' );
		$sourceId = $this->sanitizeInt( 'source_id' );
		$version  = $this->hasParam( 'version' ) ? $this->sanitizeInt( 'version' ) : null;
		$input    = array(
			'school_name'  => $this->sanitizeText( 'school_name' ),
			'school_key'   => $this->sanitizeKey( 'school_key' ),
			'teacher_name' => $this->sanitizeText( 'teacher_name' ),
			'grade'        => $this->sanitizeInt( 'grade' ),
		);
		if ( $this->hasParam( 'discount_percent' ) ) {
			$input['discount_percent'] = $this->sanitizeInt( 'discount_percent' );
		}

		$this->run(
			function () use ( $eventId, $sourceId, $version, $input ): array {
				$source = $this->sources->save( get_current_user_id(), $eventId, $input, $sourceId > 0 ? $sourceId : null, $sourceId > 0 ? $version : null );

				return array(
					'source_id' => $source->id,
					'sources'   => $this->sources->list( get_current_user_id(), $eventId ),
				);
			}
		);
	}

	/** Возвращает ссылку приглашения выбранного источника. */
	public function ajaxIssueExamSourceLink(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExamGuests );

		$sourceId = $this->requireInt( 'source_id' );

		$this->run( fn (): array => array( 'url' => $this->sources->issueLink( get_current_user_id(), $sourceId ) ) );
	}

	/** Переиздаёт приглашение источника через сервис управления ключами. */
	public function ajaxReissueExamSourceLink(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExamGuests );

		$sourceId = $this->requireInt( 'source_id' );

		$this->run( fn (): array => array( 'url' => $this->sources->reissueLink( get_current_user_id(), $sourceId ) ) );
	}

	/** Отзывает приглашение и подтверждает ID обработанного источника. */
	public function ajaxRevokeExamSourceLink(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExamGuests );

		$sourceId = $this->requireInt( 'source_id' );

		$this->run(
			function () use ( $sourceId ): array {
				$this->sources->revokeLink( get_current_user_id(), $sourceId );

				return array( 'source_id' => $sourceId );
			}
		);
	}

	/** Удаляет источник: ссылка, если создана, отзывается; источник с заявками или записями не удаляется. */
	public function ajaxDeleteExamSource(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExamGuests );

		$sourceId = $this->requireInt( 'source_id' );

		$this->run(
			function () use ( $sourceId ): array {
				$this->sources->deleteSource( get_current_user_id(), $sourceId );

				return array( 'source_id' => $sourceId );
			}
		);
	}

	/** Меняет доступность источника и возвращает установленное состояние. */
	public function ajaxToggleExamSource(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExamGuests );

		$sourceId = $this->requireInt( 'source_id' );
		$active   = $this->sanitizeBool( 'active' );

		$this->run(
			function () use ( $sourceId, $active ): array {
				$this->sources->setActive( get_current_user_id(), $sourceId, $active );

				return array(
					'source_id' => $sourceId,
					'active'    => $active,
				);
			}
		);
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
