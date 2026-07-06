<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Profile;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Enrollment\OpenGroupEnrollmentService;
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
		private readonly LearnerService             $service,
		private readonly ProfileViewResolver        $resolver,
		private readonly OpenGroupEnrollmentService $openGroupEnrollment,
	) {
		parent::__construct();
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

		$this->success( $this->service->build( $personId ) );
	}

	/**
	 * Самозапись ученика в открытую группу (Эпик 15, П10). Params: group_id.
	 *
	 * Только сам ученик (родитель — read-only); группа обязана быть открытой —
	 * гард и события зачисления в OpenGroupEnrollmentService.
	 */
	public function ajaxSelfEnrollOpenGroup(): void {
		Nonce::LearnerProfile->verify();

		if ( ! is_user_logged_in() ) {
			$this->error( __( 'Требуется вход.', 'fs-lms' ) );
			return;
		}

		$ctx = $this->resolver->context( get_current_user_id() );
		if ( $ctx->readOnly || null === $ctx->personId ) {
			$this->error( __( 'Записаться на курс может только ученик.', 'fs-lms' ) );
			return;
		}

		$groupId = $this->requireInt( 'group_id' );

		try {
			$summary = $this->openGroupEnrollment->enrollMany( array( $ctx->personId ), $groupId, get_current_user_id() );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		if ( 0 === $summary['added'] ) {
			$this->error( __( 'Вы уже записаны на этот курс.', 'fs-lms' ) );
			return;
		}

		$this->success( $summary );
	}
}
