<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

use Inc\Services\PostTypeResolver;

/**
 * Class CourseDTO
 *
 * Данные курса-шаблона (Courses.md → ★): упорядоченные МОДУЛИ → уроки.
 * Модуль — course-local (ModuleDTO). Плоский список уроков курса (для назначения
 * группе, Этап 2) — производный: разворот модулей по порядку (см. lessonIds()).
 *
 * @package Inc\DTO\Course
 */
readonly class CourseDTO {

	/**
	 * @param int         $id
	 * @param string      $subjectKey
	 * @param string      $title           = post_title
	 * @param string      $descriptionHtml = post_content
	 * @param ModuleDTO[] $modules         Упорядоченные модули курса.
	 * @param int         $authorId
	 * @param string      $status
	 */
	public function __construct(
		public int    $id,
		public string $subjectKey,
		public string $title,
		public string $descriptionHtml,
		public array  $modules,
		public int    $authorId,
		public string $status,
	) {}

	public static function fromPost( \WP_Post $post, array $meta ): self {
		return new self(
			id             : $post->ID,
			subjectKey     : PostTypeResolver::subjectFromCoursePostType( $post->post_type ),
			title          : $post->post_title,
			descriptionHtml: $post->post_content,
			modules        : ModuleDTO::fromList( is_array( $meta['modules'] ?? null ) ? $meta['modules'] : array() ),
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
			modules        : ModuleDTO::fromList( is_array( $data['modules'] ?? null ) ? $data['modules'] : array() ),
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
			'modules'          => ModuleDTO::toList( $this->modules ),
			'author_id'        => $this->authorId,
			'status'           => $this->status,
		);
	}

	public function isEmpty(): bool {
		return empty( $this->lessonIds() );
	}

	/**
	 * Плоский упорядоченный список уроков курса (разворот модулей по порядку).
	 * Потребитель — назначение курса группе (Этап 2); снапшот в `group_lessons` остаётся плоским.
	 *
	 * @return int[]
	 */
	public function lessonIds(): array {
		$ids = array();
		foreach ( $this->modules as $module ) {
			foreach ( $module->lessonIds as $lessonId ) {
				$ids[] = $lessonId;
			}
		}

		return $ids;
	}
}
