<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Assessment;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Course\WorkManager;
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
		private readonly WorkManager            $workManager,
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

		$is_valid = $post instanceof \WP_Post
			&& ( PostTypeResolver::isTaskPostType( $post->post_type ) || PostTypeResolver::isProblemPostType( $post->post_type ) );
		if ( ! $is_valid ) {
			$this->error( 'Задача не найдена.' );
			return;
		}

		$this->success( $this->buildTaskPreviewData( $post ) );
	}

	/**
	 * Возвращает список задач работы или контрольной для отображения в степ-редакторе урока.
	 * Params: ref_id, ref_type (work|assessment)
	 */
	public function ajaxGetRefPreview(): void {
		$this->authorize( Nonce::AuthorAssessment, Capability::ManageLMSAssignments );

		$ref_id   = $this->requireInt( 'ref_id' );
		$ref_type = $this->sanitizeKey( 'ref_type' );

		$post = get_post( $ref_id );
		if ( ! $post instanceof \WP_Post ) {
			$this->error( 'Не найдено.' );
			return;
		}

		if ( 'work' === $ref_type ) {
			if ( ! PostTypeResolver::isWorkPostType( $post->post_type ) ) {
				$this->error( 'Не найдено.' );
				return;
			}
			$dto      = $this->workManager->get( $ref_id );
			$item_ids = $dto ? $dto->itemIds : array();
		} else {
			if ( ! PostTypeResolver::isAssessmentPostType( $post->post_type ) ) {
				$this->error( 'Не найдено.' );
				return;
			}
			$dto      = $this->assessmentManager->get( $ref_id );
			$item_ids = $dto ? $dto->taskIds : array();
		}

		$tasks = array();
		foreach ( $item_ids as $task_id ) {
			$task_post = get_post( (int) $task_id );
			if ( $task_post instanceof \WP_Post ) {
				$tasks[] = $this->buildTaskPreviewData( $task_post );
			}
		}

		$this->success( array(
			'title'    => get_the_title( $post ),
			'edit_url' => admin_url( 'post.php?post=' . $ref_id . '&action=edit' ),
			'tasks'    => $tasks,
		) );
	}

	private function buildTaskPreviewData( \WP_Post $post ): array {
		$task_id       = $post->ID;
		$meta          = get_post_meta( $task_id, PostMetaName::Meta->value, true );
		$meta          = is_array( $meta ) ? $meta : array();
		$template_type = (string) ( get_post_meta( $task_id, PostMetaName::TemplateType->value, true ) ?: '' );

		$common_html = '';
		if ( ! empty( $meta['common_condition'] ) && is_string( $meta['common_condition'] ) ) {
			$common_html = wp_kses_post( $meta['common_condition'] );
		}

		$condition_html = '';
		foreach ( array( 'task_condition', 'task_text', 'problem_text', 'question_text', 'content' ) as $key ) {
			if ( ! empty( $meta[ $key ] ) && is_string( $meta[ $key ] ) ) {
				$condition_html = wp_kses_post( $meta[ $key ] );
				break;
			}
		}
		if ( $common_html ) {
			$condition_html = $common_html . ( $condition_html ? '<br>' . $condition_html : '' );
		}

		$options = isset( $meta['task_options'] ) && is_array( $meta['task_options'] ) ? $meta['task_options'] : null;
		$pairs   = isset( $meta['task_pairs'] ) && is_array( $meta['task_pairs'] ) ? $meta['task_pairs'] : null;

		$order_items = isset( $meta['task_order_items'] ) && is_array( $meta['task_order_items'] ) ? $meta['task_order_items'] : null;

		$gap_text = '';
		if ( ! empty( $meta['task_gap_text']['text'] ) && is_string( $meta['task_gap_text']['text'] ) ) {
			$gap_text = wp_kses_post( $meta['task_gap_text']['text'] );
		}

		$three_in_one = null;
		foreach ( array( '19', '20', '21' ) as $k ) {
			$cond   = ! empty( $meta[ 'task_' . $k . '_condition' ] ) ? wp_kses_post( $meta[ 'task_' . $k . '_condition' ] ) : '';
			$answer = ! empty( $meta[ 'task_' . $k . '_answer' ] ) ? wp_strip_all_tags( $meta[ 'task_' . $k . '_answer' ] ) : '';
			if ( $cond || $answer ) {
				$three_in_one[] = array( 'condition' => $cond, 'answer' => $answer );
			}
		}

		$answer_html = '';
		foreach ( array( 'task_answer', 'answer', 'answer_text', 'correct_answer' ) as $key ) {
			if ( ! empty( $meta[ $key ] ) && is_string( $meta[ $key ] ) ) {
				$answer_html = wp_kses_post( $meta[ $key ] );
				break;
			}
		}

		$solution_html = '';
		foreach ( array( 'task_solution', 'solution', 'task_hint', 'hint' ) as $key ) {
			if ( ! empty( $meta[ $key ] ) && is_string( $meta[ $key ] ) ) {
				$solution_html = wp_kses_post( $meta[ $key ] );
				break;
			}
		}

		$audio_url = '';
		if ( isset( $meta['task_audio']['attachment_id'] ) ) {
			$url = wp_get_attachment_url( (int) $meta['task_audio']['attachment_id'] );
			if ( $url ) {
				$audio_url = $url;
			}
		}

		return array(
			'id'             => $task_id,
			'title'          => get_the_title( $post ),
			'edit_url'       => admin_url( 'post.php?post=' . $task_id . '&action=edit' ),
			'status'         => $post->post_status,
			'template_type'  => $template_type,
			'condition_html' => $condition_html,
			'options'        => $options,
			'pairs'          => $pairs,
			'order_items'    => $order_items,
			'gap_text'       => $gap_text,
			'three_in_one'   => $three_in_one,
			'answer_html'    => $answer_html,
			'solution_html'  => $solution_html,
			'audio_url'      => $audio_url,
		);
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
