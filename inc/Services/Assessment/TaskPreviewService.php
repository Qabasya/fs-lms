<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Course\WorkManager;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class TaskPreviewService
 *
 * Сборка read-only превью задачи/работы/контрольной для степ-редактора урока
 * (пикер шага "task"/"work"/"assessment"). Вынесена из `AssessmentAuthorCallbacks`
 * (Этап 3): нужна двум Callback-классам — методисту (`AssessmentAuthorCallbacks`,
 * capability `AuthorLmsCourses`) и преподавателю (`TeacherLessonCallbacks`,
 * capability `ManageLmsTeaching`) — оба показывают один и тот же контент, отличается
 * только слой авторизации над вызовом, поэтому сама сборка данных здесь одна.
 *
 * @package Inc\Services\Assessment
 */
class TaskPreviewService {

	public function __construct(
		private readonly WorkManager       $workManager,
		private readonly AssessmentManager $assessmentManager,
	) {}

	/**
	 * Превью одной задачи (subject-задача или банковская). `null` — не найдена/не задача.
	 *
	 * @return array<string, mixed>|null
	 */
	public function getTaskPreview( int $taskId ): ?array {
		$post = get_post( $taskId );

		$is_valid = $post instanceof \WP_Post
			&& ( PostTypeResolver::isTaskPostType( $post->post_type ) || PostTypeResolver::isProblemPostType( $post->post_type ) );
		if ( ! $is_valid ) {
			return null;
		}

		return $this->buildTaskPreviewData( $post );
	}

	/**
	 * Превью работы/контрольной для степ-редактора: заголовок + задачи (без ответов).
	 * `null` — не найдено/не тот тип поста.
	 *
	 * @return array{title:string, edit_url:string, tasks:array<int,array<string,mixed>>}|null
	 */
	public function getRefPreview( int $refId, string $refType ): ?array {
		$post = get_post( $refId );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		if ( 'work' === $refType ) {
			if ( ! PostTypeResolver::isWorkPostType( $post->post_type ) ) {
				return null;
			}
			$dto      = $this->workManager->get( $refId );
			$item_ids = $dto ? $dto->itemIds : array();
		} else {
			if ( ! PostTypeResolver::isAssessmentPostType( $post->post_type ) ) {
				return null;
			}
			$dto      = $this->assessmentManager->get( $refId );
			$item_ids = $dto ? $dto->taskIds : array();
		}

		$tasks = array();
		foreach ( $item_ids as $task_id ) {
			$task_post = get_post( (int) $task_id );
			if ( $task_post instanceof \WP_Post ) {
				$tasks[] = $this->buildTaskPreviewData( $task_post );
			}
		}

		return array(
			'title'    => get_the_title( $post ),
			'edit_url' => admin_url( 'post.php?post=' . $refId . '&action=edit' ),
			'tasks'    => $tasks,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildTaskPreviewData( \WP_Post $post ): array {
		$task_id       = $post->ID;
		$meta          = get_post_meta( $task_id, PostMetaName::Meta->value, true );
		$meta          = is_array( $meta ) ? $meta : array();
		$template_type = (string) ( get_post_meta( $task_id, PostMetaName::TemplateType->value, true ) ?: '' );

		$common_html = '';
		if ( ! empty( $meta['common_condition'] ) && is_string( $meta['common_condition'] ) ) {
			$common_html = wp_kses_post( $meta['common_condition'] );
		}

		// Условие — только task_condition (+ common_condition как префикс). task_text — это
		// поле «Решение», не условие; problem_text/question_text/content в шаблонах не существуют.
		$condition_html = '';
		if ( ! empty( $meta['task_condition'] ) && is_string( $meta['task_condition'] ) ) {
			$condition_html = wp_kses_post( $meta['task_condition'] );
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

		// Ответ — только task_answer (answer/answer_text/correct_answer в шаблонах нет).
		$answer_html = '';
		if ( ! empty( $meta['task_answer'] ) && is_string( $meta['task_answer'] ) ) {
			$answer_html = wp_kses_post( $meta['task_answer'] );
		}

		// Решение — поле «Решение/пояснение» (task_text, есть у TaskTextSolution).
		$solution_html = '';
		if ( ! empty( $meta['task_text'] ) && is_string( $meta['task_text'] ) ) {
			$solution_html = wp_kses_post( $meta['task_text'] );
		}

		// Подсказка (task_hint) — отдельная секция; раньше уезжала в блок «Решение».
		$hint_html = '';
		if ( ! empty( $meta['task_hint'] ) && is_string( $meta['task_hint'] ) ) {
			$hint_html = wp_kses_post( $meta['task_hint'] );
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
			'hint_html'      => $hint_html,
			'audio_url'      => $audio_url,
		);
	}
}
