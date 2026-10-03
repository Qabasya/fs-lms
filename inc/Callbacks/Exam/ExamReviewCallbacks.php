<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Access\Capability;
use Inc\Services\Exam\ExamReviewProjection;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Интеграция разбора экзамена с work-review.js (7.4).
 *
 * Для студента и учителя эндпоинт openWorkReview('attempt', attemptId)
 * отправляет AJAX getDetail с source_type=attempt, и мы отдаём объект
 * с подробным разбором задач, вердиктами и баллами.
 *
 * @package Inc\Callbacks\Exam
 */
class ExamReviewCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly ExamReviewProjection $projection,
		private readonly AssessmentAttemptRepository $attemptRepo,
	) {
		parent::__construct();
	}

	/**
	 * Получить детали попытки для разбора (для work-review.js) (7.4.1).
	 *
	 * AJAX: POST fs-lms-vars.ajax_actions.getWorkReviewDetail
	 * Запрос: {source_type: 'attempt', source_id: attemptId}
	 * Ответ: детали работы из ExamReviewProjection
	 *
	 * Режимы:
	 * - Учитель (manage): видит всё + result_version
	 * - Ученик (read_only): видит только разрешённое после утверждения
	 */
	public function ajaxGetDetail(): void {
		$this->authorize( Nonce::GetWorkReviewDetail, Capability::ManageLmsPlatform );

		$sourceType = $this->sanitizeKey( $_POST['source_type'] ?? '' );
		$sourceId = $this->sanitizeInt( $_POST['source_id'] ?? 0 );

		if ( 'attempt' !== $sourceType || ! $sourceId ) {
			$this->error( 'Некорректные параметры.' );
			return;
		}

		$attempt = $this->attemptRepo->find( $sourceId );
		if ( ! $attempt ) {
			$this->error( 'Попытка не найдена.' );
			return;
		}

		// TODO: Проверить доступ (учитель группы | студент | родитель)
		// На этапе 7.4 добавить гвард ExamReviewAccess

		// Выбрать режим: учитель (manage) или ученик (read_only)
		$mode = current_user_can( Capability::ManageLmsPlatform->value ) ? 'manage' : 'read_only';

		// Проекция применит reveal policy автоматически
		$detail = $this->projection->forViewer( $sourceId, $mode );

		if ( ! $detail ) {
			$this->error( 'Детали попытки не доступны.' );
			return;
		}

		$this->success( $detail );
	}
}
