<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

use Inc\Enums\PostMetaName;
use Inc\Services\PostTypeResolver;

/**
 * Class LessonDTO
 *
 * Данные урока предмета.
 *
 * @package Inc\DTO\Course
 */
readonly class LessonDTO {

	/**
	 * @param int    $id
	 * @param string $subjectKey
	 * @param string $topic        = post_title
	 * @param string $theoryHtml   = post_content
	 * @param int    $theoryArticleId 0 = inline-теория
	 * @param int    $taskType        0 = не задан
	 * @param array{content: string, task_ids: int[]} $practice
	 * @param array{content: string, task_ids: int[]} $independent
	 * @param array{content: string, task_ids: int[]} $homework
	 * @param int    $authorId
	 * @param string $status
	 */
	public function __construct(
		public int    $id,
		public string $subjectKey,
		public string $topic,
		public string $theoryHtml,
		public int    $theoryArticleId,
		public int    $taskType,
		public array  $practice,
		public array  $independent,
		public array  $homework,
		public int    $authorId,
		public string $status,
	) {}

	public static function fromPost( \WP_Post $post, array $meta ): self {
		$empty_bucket = array( 'content' => '', 'task_ids' => array() );

		return new self(
			id              : $post->ID,
			subjectKey      : PostTypeResolver::subjectFromLessonPostType( $post->post_type ),
			topic           : $post->post_title,
			theoryHtml      : $post->post_content,
			theoryArticleId : (int) ( $meta['theory_article_id'] ?? 0 ),
			taskType        : (int) ( $meta['task_type'] ?? 0 ),
			practice        : is_array( $meta['practice'] ?? null ) ? $meta['practice'] : $empty_bucket,
			independent     : is_array( $meta['independent'] ?? null ) ? $meta['independent'] : $empty_bucket,
			homework        : is_array( $meta['homework'] ?? null ) ? $meta['homework'] : $empty_bucket,
			authorId        : (int) $post->post_author,
			status          : $post->post_status,
		);
	}

	public static function fromArray( array $data ): self {
		$empty_bucket = array( 'content' => '', 'task_ids' => array() );

		return new self(
			id              : (int) ( $data['id'] ?? 0 ),
			subjectKey      : (string) ( $data['subject_key'] ?? '' ),
			topic           : (string) ( $data['topic'] ?? '' ),
			theoryHtml      : (string) ( $data['theory_html'] ?? '' ),
			theoryArticleId : (int) ( $data['theory_article_id'] ?? 0 ),
			taskType        : (int) ( $data['task_type'] ?? 0 ),
			practice        : is_array( $data['practice'] ?? null ) ? $data['practice'] : $empty_bucket,
			independent     : is_array( $data['independent'] ?? null ) ? $data['independent'] : $empty_bucket,
			homework        : is_array( $data['homework'] ?? null ) ? $data['homework'] : $empty_bucket,
			authorId        : (int) ( $data['author_id'] ?? 0 ),
			status          : (string) ( $data['status'] ?? 'draft' ),
		);
	}

	public function toArray(): array {
		return array(
			'id'               => $this->id,
			'subject_key'      => $this->subjectKey,
			'topic'            => $this->topic,
			'theory_html'      => $this->theoryHtml,
			'theory_article_id'=> $this->theoryArticleId,
			'task_type'        => $this->taskType,
			'practice'         => $this->practice,
			'independent'      => $this->independent,
			'homework'         => $this->homework,
			'author_id'        => $this->authorId,
			'status'           => $this->status,
		);
	}

	public function isEmpty(): bool {
		$has_tasks = fn( array $bucket ): bool => ! empty( $bucket['task_ids'] );
		return ! $has_tasks( $this->practice )
			&& ! $has_tasks( $this->independent )
			&& ! $has_tasks( $this->homework );
	}
}
