<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

use Inc\Enums\WorkType;
use Inc\Services\PostTypeResolver;

/**
 * Class WorkDTO
 *
 * Данные работы предмета (типизированный пул ссылок на задания).
 *
 * @package Inc\DTO\Course
 */
readonly class WorkDTO {

	/**
	 * @param int      $id
	 * @param string   $subjectKey
	 * @param string   $title        = post_title
	 * @param WorkType $workType
	 * @param int[]    $taskIds      ссылки на {key}_tasks
	 * @param string   $instructions
	 * @param int      $authorId
	 * @param string   $status
	 */
	public function __construct(
		public int      $id,
		public string   $subjectKey,
		public string   $title,
		public WorkType $workType,
		public array    $taskIds,
		public string   $instructions,
		public int      $authorId,
		public string   $status,
	) {}

	public static function fromPost( \WP_Post $post, array $meta ): self {
		return new self(
			id          : $post->ID,
			subjectKey  : PostTypeResolver::subjectFromWorkPostType( $post->post_type ),
			title       : $post->post_title,
			workType    : WorkType::fromValueOrDefault( (string) ( $meta['work_type'] ?? '' ) ),
			taskIds     : self::intIds( $meta['task_ids'] ?? array() ),
			instructions: (string) ( $meta['instructions'] ?? '' ),
			authorId    : (int) $post->post_author,
			status      : $post->post_status,
		);
	}

	public static function fromArray( array $data ): self {
		return new self(
			id          : (int) ( $data['id'] ?? 0 ),
			subjectKey  : (string) ( $data['subject_key'] ?? '' ),
			title       : (string) ( $data['title'] ?? '' ),
			workType    : WorkType::fromValueOrDefault( (string) ( $data['work_type'] ?? '' ) ),
			taskIds     : self::intIds( $data['task_ids'] ?? array() ),
			instructions: (string) ( $data['instructions'] ?? '' ),
			authorId    : (int) ( $data['author_id'] ?? 0 ),
			status      : (string) ( $data['status'] ?? 'draft' ),
		);
	}

	public function toArray(): array {
		return array(
			'id'           => $this->id,
			'subject_key'  => $this->subjectKey,
			'title'        => $this->title,
			'work_type'    => $this->workType->value,
			'task_ids'     => $this->taskIds,
			'instructions' => $this->instructions,
			'author_id'    => $this->authorId,
			'status'       => $this->status,
		);
	}

	public function isEmpty(): bool {
		return empty( $this->taskIds );
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
