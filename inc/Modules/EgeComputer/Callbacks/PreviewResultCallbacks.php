<?php

declare( strict_types=1 );

namespace Inc\Modules\EgeComputer\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Modules\EgeComputer\Services\KegeResultSheetService;
use Inc\Services\Assessment\AssessmentAccessPolicy;
use Inc\Services\Assessment\AttemptTaskViewBuilder;
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
 * только источник ответов другой — сама попытка нигде не сохраняется.
 *
 * @package Inc\Modules\EgeComputer\Callbacks
 */
class PreviewResultCallbacks extends BaseController {

	use AjaxResponse;
	use Sanitizer;

	/** WP AJAX action (модульная, вне core AjaxHook — см. CLAUDE.md). */
	public const ACTION = 'fs_lms_kege_preview_result';

	public function __construct(
		private readonly AssessmentManager      $assessments,
		private readonly AssessmentAccessPolicy $access,
		private readonly AttemptTaskViewBuilder $taskViews,
		private readonly KegeResultSheetService $resultSheet,
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

		$answerText = array();
		foreach ( $this->unslashArray( 'answers' ) as $taskId => $value ) {
			$taskId = absint( $taskId );
			if ( $taskId > 0 ) {
				$answerText[ $taskId ] = $this->sanitizeHtmlValue( $value );
			}
		}

		$taskViews = $this->taskViews->build( $assessment->taskIds, $assessment->subjectKey, $assessment->kind );
		$sheet     = $this->resultSheet->buildFromAnswers( $assessment, $answerText, $taskViews );

		$this->success( array(
			'rows'          => $sheet->rows,
			'answered'      => $sheet->answered,
			'total'         => $sheet->total(),
			'primary'       => $sheet->primary,
			'primary_max'   => $sheet->primaryMax,
			'secondary'     => $sheet->secondary,
			'secondary_max' => $sheet->secondaryMax,
		) );
	}
}
