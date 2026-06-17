<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

use Inc\Services\PostTypeResolver;

/**
 * Class LessonDTO
 *
 * Данные урока предмета. Урок ссылается на работы (work_ids), не на задачи.
 *
 * @package Inc\DTO\Course
 */
readonly class LessonDTO {

	/**
	 * @param int    $id
	 * @param string $subjectKey
	 * @param string $topic           = post_title
	 * @param string $theoryHtml      = post_content
	 * @param int    $theoryArticleId 0 = inline-теория
	 * @param int[]  $workIds         ссылки на {key}_works
	 * @param int    $authorId
	 * @param string $status
	 */
	public function __construct(
		public int    $id,
		public string $subjectKey,
		public string $topic,
		public string $theoryHtml,
		public int    $theoryArticleId,
		public array  $workIds,
		public int    $authorId,
		public string $status,
	) {}

	public static function fromPost( \WP_Post $post, array $meta ): self {
		return new self(
			id              : $post->ID,
			subjectKey      : PostTypeResolver::subjectFromLessonPostType( $post->post_type ),
			topic           : $post->post_title,
			theoryHtml      : $post->post_content,
			theoryArticleId : (int) ( $meta['theory_article_id'] ?? 0 ),
			workIds         : self::intIds( $meta['work_ids'] ?? array() ),
			authorId        : (int) $post->post_author,
			status          : $post->post_status,
		);
	}

	public static function fromArray( array $data ): self {
		return new self(
			id              : (int) ( $data['id'] ?? 0 ),
			subjectKey      : (string) ( $data['subject_key'] ?? '' ),
			topic           : (string) ( $data['topic'] ?? '' ),
			theoryHtml      : (string) ( $data['theory_html'] ?? '' ),
			theoryArticleId : (int) ( $data['theory_article_id'] ?? 0 ),
			workIds         : self::intIds( $data['work_ids'] ?? array() ),
			authorId        : (int) ( $data['author_id'] ?? 0 ),
			status          : (string) ( $data['status'] ?? 'draft' ),
		);
	}

	public function toArray(): array {
		return array(
			'id'                => $this->id,
			'subject_key'       => $this->subjectKey,
			'topic'             => $this->topic,
			'theory_html'       => $this->theoryHtml,
			'theory_article_id' => $this->theoryArticleId,
			'work_ids'          => $this->workIds,
			'author_id'         => $this->authorId,
			'status'            => $this->status,
		);
	}

	public function isEmpty(): bool {
		return empty( $this->workIds );
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
