<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\Course\ProgressStatus;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Wp\PostManager;

/**
 * Class LessonPlayerService
 *
 * Сборка view-модели пошагового плеера урока (★, T1.5.12): упорядоченные шаги с
 * гейтом (доступ) и статусом (прогресс) для конкретного ученика в уроке программы.
 * Инлайн-шаги отдают контент для рендера; ссылочные — id+заголовок связанной сущности.
 *
 * @package Inc\Services\Course
 */
class LessonPlayerService {

	public function __construct(
		private readonly LessonManager         $lessons,
		private readonly LessonGateResolver    $gate,
		private readonly LessonProgressService $progress,
		private readonly PostManager           $posts,
	) {}

	/**
	 * @return array{group_lesson_id:int, lesson_id:int, topic:string, steps:array<int, array<string,mixed>>}|null
	 */
	public function buildView( int $studentPersonId, GroupLessonDTO $groupLesson ): ?array {
		$lesson = $groupLesson->lessonId ? $this->lessons->get( $groupLesson->lessonId ) : null;
		if ( null === $lesson ) {
			return null;
		}

		$statuses = $this->progress->getStepStatuses( $studentPersonId, $groupLesson->id );

		$steps = array();
		foreach ( $lesson->steps as $step ) {
			$status = $statuses[ $step->key ] ?? ProgressStatus::Available;
			$gate   = $this->gate->resolveStep( $studentPersonId, $groupLesson, $step->key );

			$steps[] = array(
				'key'    => $step->key,
				'type'   => $step->type->value,
				'title'  => $this->resolveTitle( $step ),
				'gate'   => $gate->value,
				'status' => $status->value,
				'render' => $this->renderData( $step, $groupLesson ),
			);
		}

		return array(
			'group_lesson_id' => $groupLesson->id,
			'lesson_id'       => $lesson->id,
			'topic'           => $lesson->topic,
			'steps'           => $steps,
		);
	}

	/** Заголовок шага: инлайн — из payload/типа, ссылочный — из связанной сущности. */
	private function resolveTitle( StepDTO $step ): string {
		$title = (string) ( $step->payload['title'] ?? '' );
		if ( '' !== $title ) {
			return $title;
		}

		$refId = (int) ( $step->payload['ref'] ?? $step->payload['article_id'] ?? 0 );
		if ( $refId > 0 ) {
			$post = $this->posts->get( $refId );
			if ( $post instanceof \WP_Post && '' !== $post->post_title ) {
				return $post->post_title;
			}
		}

		return $step->type->label();
	}

	/**
	 * Данные для рендера шага по типу.
	 *
	 * @return array<string, mixed>
	 */
	private function renderData( StepDTO $step, GroupLessonDTO $groupLesson ): array {
		return match ( $step->type->value ) {
			'text'  => array( 'content' => (string) ( $step->payload['content'] ?? '' ) ),
			'video' => array(
				'url'            => $this->resolveVideoUrl( $step, $groupLesson ),
				'description'    => (string) ( $step->payload['description'] ?? '' ),
				'provider'       => (string) ( $step->payload['provider'] ?? '' ),
				'recording_slot' => (bool) ( $step->payload['recording_slot'] ?? false ),
			),
			'material'   => array( 'ref' => (int) ( $step->payload['article_id'] ?? 0 ) ),
			default      => array( 'ref' => (int) ( $step->payload['ref'] ?? 0 ) ),
		};
	}

	/**
	 * URL видео-шага: для слота записи подставляет recordingUrl группового урока (S3-readiness, T1.5.13).
	 * Внешний url шага используется, если слот не помечен или запись ещё не готова.
	 */
	private function resolveVideoUrl( StepDTO $step, GroupLessonDTO $groupLesson ): string {
		$isSlot = (bool) ( $step->payload['recording_slot'] ?? false );
		if ( $isSlot && null !== $groupLesson->recordingUrl && '' !== $groupLesson->recordingUrl ) {
			return $groupLesson->recordingUrl;
		}

		return (string) ( $step->payload['url'] ?? '' );
	}
}
