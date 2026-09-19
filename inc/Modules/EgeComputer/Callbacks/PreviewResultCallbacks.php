<?php

declare( strict_types=1 );

namespace Inc\Modules\EgeComputer\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Modules\EgeComputer\Services\KegeResultSheetService;
use Inc\Modules\EgeComputer\Services\KegeSheetResponseBuilder;
use Inc\Services\Assessment\AssessmentAccessPolicy;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class PreviewResultCallbacks
 *
 * Лист ответов станции КЕГЭ в предпросмотре автора (T15.10-preview): попытки в
 * БД нет и не будет (`AttemptPageService::buildPreview()`) — накопленные в JS
 * ответы (`kege-exam.js`, Map `savedAnswers`) отправляются сюда напрямую, когда
 * автор жмёт «Завершить экзамен». Расчёт баллов и сличение с эталоном идут тем
 * же кодом, что и для настоящей попытки ({@see KegeResultSheetService::buildFromAnswers()}),
 * только источник ответов другой — сама попытка нигде не сохраняется. Сборку листа
 * делит с публичным экзаменом {@see KegeSheetResponseBuilder}.
 *
 * @package Inc\Modules\EgeComputer\Callbacks
 */
class PreviewResultCallbacks extends BaseController {

	use AjaxResponse;
	use Sanitizer;

	/** WP AJAX action (модульная, вне core AjaxHook — см. CLAUDE.md). */
	public const ACTION = 'fs_lms_kege_preview_result';

	public function __construct(
		private readonly AssessmentManager        $assessments,
		private readonly AssessmentAccessPolicy   $access,
		private readonly KegeSheetResponseBuilder $sheetBuilder,
	) {
		parent::__construct();
	}

	public function ajaxPreviewResult(): void {
		Nonce::StartAttempt->verify();

		$assessmentId = $this->requireInt( 'assessment_id' );

		$userId = get_current_user_id();
		// Тот же гейт, что открывает саму страницу вхолостую (AssessmentPageController):
		// без него любой залогиненный мог бы дёрнуть эндпоинт с чужим assessment_id
		// и получить эталонные ответы контрольной, до которой у него доступа нет.
		if ( ! $userId || ! $this->access->canPreview( $userId, $assessmentId ) ) {
			$this->error( 'Доступ запрещён.' );
			return;
		}

		$assessment = $this->assessments->get( $assessmentId );
		if ( ! $assessment ) {
			$this->error( 'Контрольная не найдена.' );
			return;
		}

		$this->success( $this->sheetBuilder->fromRequest( $assessment ) );
	}
}
