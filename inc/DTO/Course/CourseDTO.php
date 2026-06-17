<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

use Inc\Services\PostTypeResolver;

/**
 * Class CourseDTO
 *
 * Данные курса-шаблона: упорядоченные ссылки на уроки.
 *
 * @package Inc\DTO\Course
 */
readonly class CourseDTO {

	/**
	 * @param int    $id
	 * @param string $subjectKey
	 * @param string $title           = post_title
	 * @param string $descriptionHtml = post_content
	 * @param int[]  $lessonIds       ссылки на {key}_lessons
	 * @param int    $authorId
	 * @param string $status
	 */
	public function __construct(
		public int    $id,
		public string $subjectKey,
		public string $title,
		public string $descriptionHtml,
		public array  $lessonIds,
		public int    $authorId,
		public string $status,
	) {}

	public static function fromPost( \WP_Post $post, array $meta ): self {
		return new self(
			id             : $post->ID,
			subjectKey     : PostTypeResolver::subjectFromCoursePostType( $post->post_type ),
			title          : $post->post_title,
			descriptionHtml: $post->post_content,
			lessonIds      : self::intIds( $meta['lesson_ids'] ?? array() ),
			authorId       : (int) $post->post_author,
			status         : $post->post_status,
		);
	}

	public static function fromArray( array $data ): self {
		return new self(
			id             : (int) ( $data['id'] ?? 0 ),
			subjectKey     : (string) ( $data['subject_key'] ?? '' ),
			title          : (string) ( $data['title'] ?? '' ),
			descriptionHtml: (string) ( $data['description_html'] ?? '' ),
			lessonIds      : self::intIds( $data['lesson_ids'] ?? array() ),
			authorId       : (int) ( $data['author_id'] ?? 0 ),
			status         : (string) ( $data['status'] ?? 'draft' ),
		);
	}

	public function toArray(): array {
		return array(
			'id'               => $this->id,
			'subject_key'      => $this->subjectKey,
			'title'            => $this->title,
			'description_html' => $this->descriptionHtml,
			'lesson_ids'       => $this->lessonIds,
			'author_id'        => $this->authorId,
			'status'           => $this->status,
		);
	}

	public function isEmpty(): bool {
		return empty( $this->lessonIds );
	}

	/**
	 * @param mixed $raw
	 * @return int[]
	 */
	private static function intIds( mixed $raw ): array {
		return is_array( $raw )
			? array_values( array_filter( array_map( 'intval', $raw ) ) )
			: array();
	}
}
