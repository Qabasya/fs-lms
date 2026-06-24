<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Assessment;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Services\Course\LessonAuthoringService;
use Inc\Services\Subject\PostTypeResolver;
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
	) {
		parent::__construct();
	}

	/**
	 * AJAX-автосейв степ-листа контрольной: упорядоченные task_ids.
	 * Params: assessment_id, item_ids[]
	 */
	public function ajaxSaveAssessmentItems(): void {
		$this->authorize( Nonce::AuthorAssessment, Capability::ManageLMSAssignments );

		$assessment_id = $this->requireInt( 'assessment_id' );
		$item_ids      = array_map( 'intval', (array) ( $_POST['item_ids'] ?? array() ) );

		$raw_points  = (array) ( $_POST['task_points'] ?? array() );
		$task_points = array();
		foreach ( $raw_points as $task_id => $points ) {
			$tid = (int) $task_id;
			$pts = (float) $points;
			if ( $tid > 0 && $pts >= 0 ) {
				$task_points[ $tid ] = $pts;
			}
		}

		if ( $this->assessmentManager->setItemIds( $assessment_id, $item_ids, $task_points ) ) {
			$this->success( array( 'count' => count( $item_ids ) ) );
		} else {
			$this->error( 'Контрольная не найдена.' );
		}
	}

	/**
	 * Возвращает превью задачи для конструктора контрольной.
	 * Params: task_id, subject_key
	 */
	public function ajaxGetTaskPreview(): void {
		$this->authorize( Nonce::AuthorAssessment, Capability::ManageLMSAssignments );

		$task_id = $this->requireInt( 'task_id' );
		$post    = get_post( $task_id );

		if ( ! $post instanceof \WP_Post || ! PostTypeResolver::isTaskPostType( $post->post_type ) ) {
			$this->error( 'Задача не найдена.' );
			return;
		}

		$meta          = get_post_meta( $task_id, PostMetaName::Meta->value, true );
		$meta          = is_array( $meta ) ? $meta : array();
		$template_type = (string) ( get_post_meta( $task_id, PostMetaName::TemplateType->value, true ) ?: '' );

		$task_text = '';
		foreach ( array( 'task_text', 'problem_text', 'question_text', 'content' ) as $key ) {
			if ( ! empty( $meta[ $key ] ) && is_string( $meta[ $key ] ) ) {
				$task_text = wp_strip_all_tags( $meta[ $key ] );
				break;
			}
		}

		$this->success( array(
			'id'            => (int) $task_id,
			'title'         => get_the_title( $post ),
			'edit_url'      => admin_url( 'post.php?post=' . $task_id . '&action=edit' ),
			'status'        => $post->post_status,
			'template_type' => $template_type,
			'task_text'     => $task_text,
		) );
	}

	/**
	 * Создаёт приватную задачу в банке задач предмета (не видна студентам).
	 * Params: subject_key, title
	 */
	public function ajaxCreateAssessmentTaskDraft(): void {
		$this->authorize( Nonce::AuthorAssessment, Capability::ManageLMSAssignments );

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
