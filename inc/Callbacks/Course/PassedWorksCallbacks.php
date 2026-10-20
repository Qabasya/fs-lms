<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Course\PassedWorksService;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class PassedWorksCallbacks
 *
 * AJAX вкладки «Прошедшие» экрана «Работы»: работы прошедших занятий, ученики работы и «Засчитать».
 *
 * @package Inc\Callbacks\Course
 */
class PassedWorksCallbacks extends BaseController {

	use AjaxResponse;
	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly PassedWorksService $passed,
	) {
		parent::__construct();
	}

	/** Работы прошедших занятий. Params: group_id? (0 — все группы пользователя). */
	public function ajaxGetPassedWorks(): void {
		$this->authorize( Nonce::GradeWork, Capability::ManageLmsTeaching );

		$userId = get_current_user_id();
		$this->success( $this->passed->list( $userId, $this->isOffice( $userId ), $this->sanitizeInt( 'group_id' ) ) );
	}

	/** Ученики работы занятия: сдавшие и нет. Params: group_lesson_id, work_id. */
	public function ajaxGetPassedWorkStudents(): void {
		$this->authorize( Nonce::GradeWork, Capability::ManageLmsTeaching );

		$userId   = get_current_user_id();
		$students = $this->passed->students(
			$this->requireInt( 'group_lesson_id' ),
			$this->requireInt( 'work_id' ),
			$userId,
			$this->isOffice( $userId )
		);
		if ( null === $students ) {
			$this->error( 'Занятие недоступно.' );
			return;
		}

		$this->success( $students );
	}

	/** Засчитывает работу несдавшим. Params: group_lesson_id, work_id, person_ids[]? (пусто — всем несдавшим). */
	public function ajaxCreditWork(): void {
		$this->authorize( Nonce::GradeWork, Capability::ManageLmsTeaching );

		$userId = get_current_user_id();
		try {
			$credited = $this->passed->credit(
				$this->requireInt( 'group_lesson_id' ),
				$this->requireInt( 'work_id' ),
				$this->sanitizeIntList( 'person_ids' ),
				$userId,
				$this->isOffice( $userId )
			);
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
			return;
		}
		if ( null === $credited ) {
			$this->error( 'Занятие недоступно.' );
			return;
		}

		$this->success( array( 'credited' => $credited ) );
	}

	/** Снимает зачёт, выставленный вручную. Params: group_lesson_id, work_id, person_id. */
	public function ajaxUncreditWork(): void {
		$this->authorize( Nonce::GradeWork, Capability::ManageLmsTeaching );

		$userId  = get_current_user_id();
		$revoked = $this->passed->uncredit(
			$this->requireInt( 'group_lesson_id' ),
			$this->requireInt( 'work_id' ),
			$this->requireInt( 'person_id' ),
			$userId,
			$this->isOffice( $userId )
		);
		if ( null === $revoked ) {
			$this->error( 'Занятие недоступно.' );
			return;
		}
		if ( ! $revoked ) {
			$this->error( 'Зачёт не найден: работу ученик сдавал сам либо зачёт уже снят.' );
			return;
		}

		$this->success();
	}

	/** Офис видит все группы, препод — свои + активные замены (как ReviewQueueCallbacks). */
	private function isOffice( int $userId ): bool {
		return user_can( $userId, Capability::ManageLmsPlatform->value );
	}
}
