<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

use Inc\Enums\Course\WorkType;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class WorkDTO
 *
 * Данные работы предмета (типизированный пул ссылок на задания и задачи).
 *
 * @package Inc\DTO\Course
 */
readonly class WorkDTO {

	/**
	 * @param int      $id
	 * @param string   $subjectKey
	 * @param string   $title        = post_title
	 * @param WorkType $workType
	 * @param int[]    $itemIds      упорядоченные WP post ID ({key}_tasks или fs_lms_problems)
	 * @param string   $instructions = post_content (описание/инструкция перед началом; ранее жила в мете)
	 * @param int      $authorId
	 * @param string   $status
	 * @param array<int, int> $taskPoints Баллы заданий этой работы, по умолчанию один.
	 * @param int      $maxAttempts  Лимит сдач работы учеником; 0 — без ограничений (мета `max_attempts`)
	 */
	public function __construct(
		public int      $id,
		public string   $subjectKey,
		public string   $title,
		public WorkType $workType,
		public array    $itemIds,
		public string   $instructions,
		public int      $authorId,
		public string   $status,
		public int      $maxAttempts = 0,
		public array $taskPoints = array(),
	) {}

	public static function fromPost( \WP_Post $post, array $meta ): self {
		return new self(
			id          : $post->ID,
			subjectKey  : PostTypeResolver::subjectFromWorkPostType( $post->post_type ),
			title       : $post->post_title,
			workType    : WorkType::fromValueOrDefault( (string) ( $meta['work_type'] ?? '' ) ),
			itemIds     : self::intIds( $meta['item_ids'] ?? array() ),
			instructions: $post->post_content,
			authorId    : (int) $post->post_author,
			status      : $post->post_status,
			maxAttempts : max( 0, (int) ( $meta['max_attempts'] ?? 0 ) ),
			taskPoints  : self::normalizePoints( self::intIds( $meta['item_ids'] ?? array() ), $meta['task_points'] ?? array() ),
		);
	}

	public static function fromArray( array $data ): self {
		return new self(
			id          : (int) ( $data['id'] ?? 0 ),
			subjectKey  : (string) ( $data['subject_key'] ?? '' ),
			title       : (string) ( $data['title'] ?? '' ),
			workType    : WorkType::fromValueOrDefault( (string) ( $data['work_type'] ?? '' ) ),
			itemIds     : self::intIds( $data['item_ids'] ?? array() ),
			instructions: (string) ( $data['instructions'] ?? '' ),
			authorId    : (int) ( $data['author_id'] ?? 0 ),
			status      : (string) ( $data['status'] ?? 'draft' ),
			maxAttempts : max( 0, (int) ( $data['max_attempts'] ?? 0 ) ),
			taskPoints  : self::normalizePoints( self::intIds( $data['item_ids'] ?? array() ), $data['task_points'] ?? array() ),
		);
	}

	public function toArray(): array {
		return array(
			'id'           => $this->id,
			'subject_key'  => $this->subjectKey,
			'title'        => $this->title,
			'work_type'    => $this->workType->value,
			'item_ids'     => $this->itemIds,
			'instructions' => $this->instructions,
			'author_id'    => $this->authorId,
			'status'       => $this->status,
			'max_attempts' => $this->maxAttempts,
			'task_points'  => self::normalizePoints( $this->itemIds, $this->taskPoints ),
		);
	}

	/** Настроенные баллы задачи этой работы; старые работы используют один балл. */
	public function pointsForTask( int $taskId ): float {
		return (float) ( self::normalizePoints( $this->itemIds, $this->taskPoints )[ $taskId ] ?? 1 );
	}

	/** Нормализация прочитанной меты: только члены работы, повреждённые веса заменяются единицей. */
	private static function normalizePoints( array $itemIds, mixed $raw ): array {
		$raw    = is_array( $raw ) ? $raw : array();
		$points = array();
		foreach ( $itemIds as $id ) {
			$value        = $raw[ $id ] ?? 1;
			$valid        = ( is_int( $value ) || is_float( $value ) || is_string( $value ) ) && preg_match( '/^[0-9]+$/', (string) $value ) && (int) $value >= 1 && (int) $value <= 999;
			$points[ $id ] = $valid ? (int) $value : 1;
		}
		return $points;
	}

	public function isEmpty(): bool {
		return empty( $this->itemIds );
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
