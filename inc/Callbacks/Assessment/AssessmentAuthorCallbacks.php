<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Assessment;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Services\Assessment\EgeCompletenessChecker;
use Inc\Services\Assessment\TaskPreviewService;
use Inc\Services\Course\LessonAuthoringService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class AssessmentAuthorCallbacks
 *
 * AJAX-обработчики конструктора контрольной (степ-лист заданий).
 *
 * @package Inc\Callbacks\Assessment
 */
class AssessmentAuthorCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly AssessmentManager      $assessmentManager,
		private readonly LessonAuthoringService $authoringService,
		private readonly EgeCompletenessChecker $completeness,
		private readonly TaskPreviewService     $taskPreview,
	) {
		parent::__construct();
	}

	/**
	 * AJAX-автосейв степ-листа контрольной: упорядоченные task_ids.
	 * Params: assessment_id, item_ids[]
	 */
	public function ajaxSaveAssessmentItems(): void {
		$this->authorize( Nonce::AuthorAssessment, Capability::AuthorLmsCourses );

		$assessment_id = $this->requireInt( 'assessment_id' );
		$item_ids      = $this->sanitizeIntList( 'item_ids' );

		$raw_points  = $this->unslashArray( 'task_points' );
		$task_points = array();
		foreach ( $raw_points as $task_id => $points ) {
			$tid = (int) $task_id;
			$pts = (float) $points;
			if ( $tid > 0 && $pts >= 0 ) {
				$task_points[ $tid ] = $pts;
			}
		}

		// Задача 8: номера банковских задач (fs_lms_problems) — задаются в конструкторе,
		// т.к. у глобального банка нет таксономии {subject}_task_number.
		$raw_numbers  = $this->unslashArray( 'task_numbers' );
		$task_numbers = array();
		foreach ( $raw_numbers as $task_id => $number ) {
			$tid = (int) $task_id;
			$num = $this->sanitizeTextValue( $number );
			if ( $tid > 0 && '' !== $num ) {
				$task_numbers[ $tid ] = $num;
			}
		}

		if ( ! $this->assessmentManager->setItemIds( $assessment_id, $item_ids, $task_points, $task_numbers ) ) {
			$this->error( 'Экзамен не найден.' );
			return;
		}

		$response = array( 'count' => count( $item_ids ) );

		// T16.10: для ЕГЭ/КЕГЭ возвращаем строгий вердикт полноты (D16.2) —
		// живой индикатор «заполнено X/N» и подсветка пропусков/дублей в конструкторе.
		$assessment = $this->assessmentManager->get( $assessment_id );
		if ( null !== $assessment && $assessment->kind->needsCompletenessCheck() ) {
			$result                   = $this->completeness->validate( $assessment, $assessment->subjectKey );
			$response['completeness'] = array(
				'isComplete'    => $result->isStrictlyComplete(),
				'expectedCount' => $result->expectedCount,
				'actualCount'   => $result->actualCount,
				'missing'       => $result->missing,
				'duplicated'    => $result->duplicated,
				'orphans'       => $result->orphans,
				'summary'       => $result->summary(),
			);
		}

		$this->success( $response );
	}

	/**
	 * Возвращает превью задачи для конструктора контрольной.
	 * Params: task_id, subject_key
	 */
	public function ajaxGetTaskPreview(): void {
		$this->authorize( Nonce::AuthorAssessment, Capability::AuthorLmsCourses );

		$task_id = $this->requireInt( 'task_id' );
		$data    = $this->taskPreview->getTaskPreview( $task_id );
		if ( null === $data ) {
			$this->error( 'Задача не найдена.' );
			return;
		}

		$this->success( $data );
	}

	/**
	 * Возвращает список задач работы или контрольной для отображения в степ-редакторе урока.
	 * Params: ref_id, ref_type (work|assessment)
	 */
	public function ajaxGetRefPreview(): void {
		$this->authorize( Nonce::AuthorAssessment, Capability::AuthorLmsCourses );

		$ref_id   = $this->requireInt( 'ref_id' );
		$ref_type = $this->sanitizeKey( 'ref_type' );

		$data = $this->taskPreview->getRefPreview( $ref_id, $ref_type );
		if ( null === $data ) {
			$this->error( 'Не найдено.' );
			return;
		}

		$this->success( $data );
	}

	/**
	 * Создаёт приватную задачу в банке задач предмета (не видна студентам).
	 * Params: subject_key, title
	 */
	public function ajaxCreateAssessmentTaskDraft(): void {
		$this->authorize( Nonce::AuthorAssessment, Capability::AuthorLmsCourses );

		$subject_key = $this->requireKey( 'subject_key' );
		$title       = $this->sanitizeText( 'title' ) ?: 'Новая задача';
		$id          = $this->authoringService->createPrivateTaskDraft( $subject_key, $title );

		if ( $id > 0 ) {
			$this->success( array( 'id' => $id, 'title' => $title ) );
		} else {
			$this->error( 'Не удалось создать задачу.' );
		}
	}
}
