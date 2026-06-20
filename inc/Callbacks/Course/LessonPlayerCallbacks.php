<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Course\LessonProgressService;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class LessonPlayerCallbacks
 *
 * AJAX пошагового плеера урока (★, T1.5.12): запись прогресса шага учеником.
 * Без capability — доступ по членству; пишет только в строки своего person_id.
 *
 * @package Inc\Callbacks\Course
 */
class LessonPlayerCallbacks extends BaseController {

	use Sanitizer;

	public function __construct(
		private readonly LessonProgressService $progress,
		private readonly PersonRepository      $persons,
	) {
		parent::__construct();
	}

	/**
	 * Отметить шаг просмотренным/пройденным. Params: group_lesson_id, step_key, status (viewed|completed)
	 */
	public function ajaxMarkStepProgress(): void {
		Nonce::MarkStepProgress->verify();

		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$stepKey       = $this->requireKey( 'step_key' );
		$status        = $this->sanitizeKey( 'status' );

		$person = $this->persons->findByWpUserId( get_current_user_id() );
		if ( ! $person ) {
			$this->error( 'Профиль не найден.' );
			return;
		}

		if ( 'completed' === $status ) {
			$this->progress->markCompleted( $person->id, $groupLessonId, $stepKey );
		} else {
			$this->progress->markViewed( $person->id, $groupLessonId, $stepKey );
		}

		$this->success( array( 'status' => 'completed' === $status ? 'completed' : 'viewed' ) );
	}
}
