<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\LessonGateResolver;
use Inc\Services\Course\LessonProgressService;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class LessonPlayerCallbacks
 *
 * AJAX пошагового плеера урока (★, T1.5.12): запись прогресса шага учеником.
 * Без capability — доступ по членству и гейту шага; пишет только в строки своего person_id.
 *
 * @package Inc\Callbacks\Course
 */
class LessonPlayerCallbacks extends BaseController {

	use Sanitizer;

	public function __construct(
		private readonly LessonProgressService $progress,
		private readonly PersonRepository      $persons,
		private readonly GroupLessonRepository $groupLessons,
		private readonly GroupAccessGuard      $guard,
		private readonly LessonGateResolver    $gate,
		private readonly LessonManager         $lessons,
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

		$groupLesson = $this->groupLessons->find( $groupLessonId );
		if ( ! $groupLesson || ! $groupLesson->lessonId ) {
			$this->error( 'Занятие не найдено.' );
			return;
		}

		// Прогресс пишет только ученик группы (родитель и посторонние — нет).
		if ( ! $this->guard->isMemberEver( $groupLesson->groupId, $person->id ) ) {
			$this->error( 'Прогресс на этом занятии отмечают только его ученики.' );
			return;
		}

		$lesson = $this->lessons->get( $groupLesson->lessonId );
		$keys   = $lesson ? array_map( static fn( $s ) => $s->key, $lesson->steps ) : array();
		if ( ! in_array( $stepKey, $keys, true ) ) {
			$this->error( 'Шаг не найден.' );
			return;
		}

		if ( ! $this->gate->resolveStep( $person->id, $groupLesson, $stepKey )->isAvailable() ) {
			$this->error( 'Этот шаг сейчас недоступен.' );
			return;
		}

		// Прогресс продолжения темы хранится под строкой-источником.
		$progressId = $groupLesson->progressId();

		if ( 'completed' === $status ) {
			$this->progress->markCompleted( $person->id, $progressId, $stepKey );
		} else {
			$this->progress->markViewed( $person->id, $progressId, $stepKey );
		}

		$this->success( array( 'status' => 'completed' === $status ? 'completed' : 'viewed' ) );
	}
}
