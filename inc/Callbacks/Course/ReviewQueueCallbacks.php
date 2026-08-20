<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Course\ReviewQueueService;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class ReviewQueueCallbacks
 *
 * AJAX-обработчики вкладки «Работы» (D3, .docs/Tasks.md) — список работ/экзаменов
 * на проверку и сдачи по конкретной работе, в разрезе трёх вкладок.
 *
 * @package Inc\Callbacks\Course
 */
class ReviewQueueCallbacks extends BaseController {

	use AjaxResponse;
	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly ReviewQueueService $queue,
	) {
		parent::__construct();
	}

	/** Работы/экзамены активной вкладки. Params: tab (pending|confirm|done). */
	public function ajaxGetPendingWorks(): void {
		$this->authorize( Nonce::GradeWork, Capability::ManageLmsTeaching );

		$tab = $this->sanitizeKey( 'tab' );
		if ( ! in_array( $tab, array( 'pending', 'confirm', 'done' ), true ) ) {
			$this->error( 'Неизвестная вкладка.' );
			return;
		}

		$userId = get_current_user_id();
		$this->success( $this->queue->pendingWorks( $userId, $this->isOffice( $userId ), $tab ) );
	}

	/** Сдачи конкретной работы/экзамена. Params: source_type (work|assessment), source_id, tab. */
	public function ajaxGetWorkSubmissions(): void {
		$this->authorize( Nonce::GradeWork, Capability::ManageLmsTeaching );

		$sourceType = $this->sanitizeKey( 'source_type' );
		$sourceId   = $this->requireInt( 'source_id' );
		$tab        = $this->sanitizeKey( 'tab' );
		if ( ! in_array( $sourceType, array( 'work', 'assessment' ), true )
			|| ! in_array( $tab, array( 'pending', 'confirm', 'done' ), true ) ) {
			$this->error( 'Некорректные параметры.' );
			return;
		}

		$userId = get_current_user_id();
		$this->success(
			$this->queue->submissionsFor( $sourceType, $sourceId, $userId, $this->isOffice( $userId ), $tab )
		);
	}

	/** Офис видит все группы, препод — свои + активные замены (как DashboardCallbacks). */
	private function isOffice( int $userId ): bool {
		return user_can( $userId, Capability::ManageLmsPlatform->value );
	}
}
