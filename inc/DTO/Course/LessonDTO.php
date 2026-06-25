<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

use Inc\Enums\Course\StepType;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class LessonDTO
 *
 * Данные урока предмета. Урок = упорядоченная последовательность ШАГОВ (Courses.md → ★):
 * text/video (инлайн) + task/work/assessment (ссылки). Ссылки на работы/контрольные —
 * производные от соответствующих шагов (см. workIds()/assessmentIds()/taskIds()).
 *
 * @package Inc\DTO\Course
 */
readonly class LessonDTO {

	/**
	 * @param int       $id
	 * @param string    $subjectKey
	 * @param string    $topic = post_title
	 * @param StepDTO[] $steps Упорядоченные шаги урока.
	 * @param int       $authorId
	 * @param string    $status
	 */
	public function __construct(
		public int    $id,
		public string $subjectKey,
		public string $topic,
		public array  $steps,
		public int    $authorId,
		public string $status,
	) {}

	public static function fromPost( \WP_Post $post, array $meta ): self {
		return new self(
			id        : $post->ID,
			subjectKey: PostTypeResolver::subjectFromLessonPostType( $post->post_type ),
			topic     : $post->post_title,
			steps     : StepDTO::fromList( is_array( $meta['steps'] ?? null ) ? $meta['steps'] : array() ),
			authorId  : (int) $post->post_author,
			status    : $post->post_status,
		);
	}

	public static function fromArray( array $data ): self {
		return new self(
			id        : (int) ( $data['id'] ?? 0 ),
			subjectKey: (string) ( $data['subject_key'] ?? '' ),
			topic     : (string) ( $data['topic'] ?? '' ),
			steps     : StepDTO::fromList( is_array( $data['steps'] ?? null ) ? $data['steps'] : array() ),
			authorId  : (int) ( $data['author_id'] ?? 0 ),
			status    : (string) ( $data['status'] ?? 'draft' ),
		);
	}

	public function toArray(): array {
		return array(
			'id'          => $this->id,
			'subject_key' => $this->subjectKey,
			'topic'       => $this->topic,
			'steps'       => StepDTO::toList( $this->steps ),
			'author_id'   => $this->authorId,
			'status'      => $this->status,
		);
	}

	public function isEmpty(): bool {
		return empty( $this->steps );
	}

	/**
	 * Ссылки на работы урока (refs work-шагов, по порядку) — потребитель доставки (Этапы 2–3).
	 *
	 * @return int[]
	 */
	public function workIds(): array {
		return $this->refsOf( StepType::Work );
	}

	/**
	 * Ссылки на контрольные урока (refs assessment-шагов).
	 *
	 * @return int[]
	 */
	public function assessmentIds(): array {
		return $this->refsOf( StepType::Assessment );
	}

	/**
	 * Ссылки на задачи-шаги урока (refs task-шагов).
	 *
	 * @return int[]
	 */
	public function taskIds(): array {
		return $this->refsOf( StepType::Task );
	}

	/**
	 * Собирает refs шагов указанного типа (payload['ref']).
	 *
	 * @return int[]
	 */
	private function refsOf( StepType $type ): array {
		$ids = array();
		foreach ( $this->steps as $step ) {
			if ( $type === $step->type ) {
				$ref = (int) ( $step->payload['ref'] ?? 0 );
				if ( $ref > 0 ) {
					$ids[] = $ref;
				}
			}
		}

		return $ids;
	}
}
