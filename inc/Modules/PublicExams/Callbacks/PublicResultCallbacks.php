<?php

declare( strict_types=1 );

namespace Inc\Modules\PublicExams\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Modules\EgeComputer\Services\KegeSheetResponseBuilder;
use Inc\Modules\PublicExams\Services\PublicExamPolicy;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class PublicResultCallbacks
 *
 * Лист ответов публичного экзамена для любого посетителя, в том числе без входа.
 * Ничего не пишет ни в БД, ни в лог: браузер присылает накопленные ответы, сервер
 * сличает их с эталоном тем же кодом, что и предпросмотр автора
 * ({@see KegeSheetResponseBuilder}), и возвращает лист.
 *
 * Эндпоинт отдаёт эталонные ответы, поэтому работает только для экзамена, который
 * автор сам открыл всем ({@see PublicExamPolicy}); на любой другой — отказ.
 *
 * @package Inc\Modules\PublicExams\Callbacks
 */
class PublicResultCallbacks extends BaseController {

	use AjaxResponse;
	use Sanitizer;

	/** WP AJAX action (модульная, вне core AjaxHook — см. CLAUDE.md). */
	public const ACTION = 'fs_lms_public_exam_result';

	public function __construct(
		private readonly AssessmentManager        $assessments,
		private readonly PublicExamPolicy         $policy,
		private readonly KegeSheetResponseBuilder $sheetBuilder,
	) {
		parent::__construct();
	}

	public function ajaxPublicResult(): void {
		// Публичный (nopriv) эндпоинт: права не проверяются, нонс — да.
		Nonce::StartAttempt->verify();

		$assessment = $this->assessments->get( $this->requireInt( 'assessment_id' ) );
		if ( null === $assessment || ! $this->policy->isPublic( $assessment ) ) {
			$this->error( 'Экзамен недоступен.' );
			return;
		}

		$this->success( $this->sheetBuilder->fromRequest( $assessment ) );
	}
}
