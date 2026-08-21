<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Profile;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Course\OwnWorkDetailService;
use Inc\Services\Profile\LearnerService;
use Inc\Services\Profile\ProfileViewResolver;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX профиля учащегося/родителя (Эпик 7).
 *
 * Без capability (у ученика/родителя нет LMS-прав) — доступ гейтится нонсом +
 * авторизацией на данные: ученик видит ТОЛЬКО себя, родитель — только своих детей
 * ({@see ProfileContext}). Клиентский `student_person_id` не доверяем: для ученика
 * игнорируется, для родителя проверяется против списка детей.
 *
 * @package Inc\Callbacks\Profile
 */
class LearnerCallbacks extends BaseController {

	use AjaxResponse;
	use Sanitizer;

	public function __construct(
		private readonly LearnerService       $service,
		private readonly ProfileViewResolver  $resolver,
		private readonly OwnWorkDetailService $ownDetail,
	) {
		parent::__construct();
	}

	/**
	 * Деталь СВОЕЙ работы/попытки (задачи 12/13): ученик — своё, родитель — ребёнка
	 * (ProfileContext). Эталонные ответы отдаются только для завершённых попыток.
	 * Params: source_type, source_id, [student_person_id].
	 */
	public function ajaxGetOwnWorkDetail(): void {
		Nonce::LearnerProfile->verify();

		if ( ! is_user_logged_in() ) {
			$this->error( __( 'Требуется вход.', 'fs-lms' ) );
			return;
		}

		$ctx      = $this->resolver->context( get_current_user_id() );
		$personId = $ctx->resolveSubjectPersonId( $this->sanitizeInt( 'student_person_id' ) );
		if ( ! $personId ) {
			$this->error( __( 'Профиль учащегося не найден.', 'fs-lms' ) );
			return;
		}

		$sourceType = $this->sanitizeText( 'source_type' );
		$sourceId   = $this->requireInt( 'source_id' );

		$detail = $this->ownDetail->forOwner( $sourceType, $sourceId, $personId );
		if ( null === $detail ) {
			$this->error( __( 'Результат недоступен.', 'fs-lms' ) );
			return;
		}

		$this->success( $detail );
	}

	public function ajaxGetLearnerProfile(): void {
		Nonce::LearnerProfile->verify();

		if ( ! is_user_logged_in() ) {
			$this->error( __( 'Требуется вход.', 'fs-lms' ) );
			return;
		}

		// Правило «родитель видит только своих детей» — в ProfileContext.
		$ctx      = $this->resolver->context( get_current_user_id() );
		$personId = $ctx->resolveSubjectPersonId( $this->sanitizeInt( 'student_person_id' ) );

		if ( ! $personId ) {
			$this->error( __( 'Профиль учащегося не найден.', 'fs-lms' ) );
			return;
		}

		$this->success( $this->service->build( $personId )->toArray() );
	}
}
