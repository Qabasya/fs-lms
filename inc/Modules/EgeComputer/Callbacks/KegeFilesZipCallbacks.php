<?php

declare( strict_types=1 );

namespace Inc\Modules\EgeComputer\Callbacks;

use Inc\Controllers\Pages\AssessmentPageController;
use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Modules\EgeComputer\Services\KegeMaterialsZipService;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Assessment\AssessmentAccessPolicy;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class KegeFilesZipCallbacks
 *
 * «Скачать все файлы» станции одним ZIP. Доступ — как у самой страницы работы: публичный
 * экзамен (фильтр {@see AssessmentPageController::PUBLIC_ACCESS_FILTER}, гость тоже),
 * автор/преподаватель в предпросмотре либо ученик с доступом к работе. Эндпоинт
 * отдаёт только адрес архива из материалов, которые и так показаны на панелях заданий.
 *
 * @package Inc\Modules\EgeComputer\Callbacks
 */
class KegeFilesZipCallbacks extends BaseController {

	use AjaxResponse;
	use Sanitizer;

	/** WP AJAX action (модульная, вне core AjaxHook — см. CLAUDE.md). */
	public const ACTION = 'fs_lms_kege_files_zip';

	public function __construct(
		private readonly AssessmentManager       $assessments,
		private readonly AssessmentAccessPolicy  $access,
		private readonly PersonRepository        $persons,
		private readonly KegeMaterialsZipService $zip,
	) {
		parent::__construct();
	}

	public function ajaxFilesZip(): void {
		// Публичный (nopriv) эндпоинт: права проверяются ниже по доступу к работе, нонс — здесь.
		Nonce::StartAttempt->verify();

		$assessment = $this->assessments->get( $this->requireInt( 'assessment_id' ) );
		if ( null === $assessment ) {
			$this->error( 'Работа не найдена.' );
			return;
		}

		if ( ! $this->canOpen( $assessment->id, $assessment ) ) {
			$this->error( 'Доступ запрещён.' );
			return;
		}

		$url = $this->zip->urlFor( $assessment );
		if ( '' === $url ) {
			$this->error( 'В работе нет файлов для скачивания.' );
			return;
		}

		$this->success( array( 'url' => $url ) );
	}

	private function canOpen( int $assessmentId, \Inc\DTO\Assessment\AssessmentDTO $assessment ): bool {
		if ( (bool) apply_filters( AssessmentPageController::PUBLIC_ACCESS_FILTER, false, $assessment ) ) {
			return true;
		}

		$userId = get_current_user_id();
		if ( ! $userId ) {
			return false;
		}

		if ( $this->access->canPreview( $userId, $assessmentId ) ) {
			return true;
		}

		$person = $this->persons->findByWpUserId( $userId );

		return null !== $person && $this->access->canAccess( $person->id, $assessmentId );
	}
}
