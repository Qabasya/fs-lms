<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Enums\PostMetaName;
use Inc\Managers\PostManager;
use Inc\Services\PostTypeResolver;

/**
 * Class ContentUsageService
 *
 * Единый источник «кто на меня ссылается» по всем банкам контента.
 * Питает и бейдж «используется в N», и гейт удаления (ContentDeletionGuard).
 *
 * Источники ссылок (Этап 1):
 *  - задание ← work.task_ids
 *  - работа  ← lesson.work_ids
 *  - урок    ← course.lesson_ids
 *  - статья  ← lesson.theory_article_id
 *  - курс    ← groups.course_id (Этап 2 — пока 0)
 *
 * @package Inc\Services\Course
 */
class ContentUsageService {

	/**
	 * Все непустые (не-trash) статусы — ссылка существует, пока существует пост.
	 *
	 * @var string[]
	 */
	private const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future', 'fs_archived' );

	public function __construct(
		private readonly PostManager $posts,
	) {}

	/**
	 * Количество потребителей контента. 0 = orphan (удаляемо).
	 *
	 * @param string $type task|work|lesson|course|article
	 * @param int    $postId
	 * @return int
	 */
	public function usageCount( string $type, int $postId ): int {
		return count( $this->usageList( $type, $postId ) );
	}

	/**
	 * Список потребителей контента.
	 *
	 * @param string $type task|work|lesson|course|article
	 * @param int    $postId
	 * @return array<int, array{id: int, title: string, type: string}>
	 */
	public function usageList( string $type, int $postId ): array {
		$post = $this->posts->get( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		[ $consumer_cpt, $field, $is_scalar ] = $this->relationFor( $type, $post->post_type );
		if ( '' === $consumer_cpt ) {
			return array();
		}

		$result = array();
		foreach ( $this->consumers( $consumer_cpt ) as $consumer ) {
			$meta = $this->posts->getMeta( $consumer->ID, PostMetaName::Meta->value );
			$meta = is_array( $meta ) ? $meta : array();

			if ( $this->references( $meta, $field, $is_scalar, $postId ) ) {
				$result[] = array(
					'id'    => $consumer->ID,
					'title' => $consumer->post_title,
					'type'  => $consumer->post_type,
				);
			}
		}

		return $result;
	}

	/**
	 * Определяет тип банка по post type (task|work|lesson|course|article|'').
	 *
	 * @param string $post_type
	 * @return string
	 */
	public static function kindOf( string $post_type ): string {
		return match ( true ) {
			PostTypeResolver::isWorkPostType( $post_type )                  => 'work',
			PostTypeResolver::isLessonPostType( $post_type )               => 'lesson',
			PostTypeResolver::isCoursePostType( $post_type )               => 'course',
			str_ends_with( $post_type, PostTypeResolver::ARTICLES_SUFFIX ) => 'article',
			PostTypeResolver::isTaskPostType( $post_type )                 => 'task',
			default                                                        => '',
		};
	}

	/**
	 * Возвращает [consumer_cpt, meta_field, is_scalar] для типа контента.
	 *
	 * @param string $type
	 * @param string $post_type Тип записи потребляемого поста (для резолва предмета).
	 * @return array{0: string, 1: string, 2: bool}
	 */
	private function relationFor( string $type, string $post_type ): array {
		return match ( $type ) {
			'task'    => array( PostTypeResolver::works( PostTypeResolver::subjectFromTaskPostType( $post_type ) ), 'task_ids', false ),
			'work'    => array( PostTypeResolver::lessons( PostTypeResolver::subjectFromWorkPostType( $post_type ) ), 'work_ids', false ),
			'lesson'  => array( PostTypeResolver::courses( PostTypeResolver::subjectFromLessonPostType( $post_type ) ), 'lesson_ids', false ),
			'article' => array( PostTypeResolver::lessons( PostTypeResolver::subjectFromArticlePostType( $post_type ) ), 'theory_article_id', true ),
			default   => array( '', '', false ), // course → groups (Этап 2)
		};
	}

	/**
	 * @param array  $meta
	 * @param string $field
	 * @param bool   $is_scalar
	 * @param int    $postId
	 * @return bool
	 */
	private function references( array $meta, string $field, bool $is_scalar, int $postId ): bool {
		$value = $meta[ $field ] ?? null;

		if ( $is_scalar ) {
			return (int) $value === $postId;
		}

		return is_array( $value ) && in_array( $postId, array_map( 'intval', $value ), true );
	}

	/**
	 * @param string $consumer_cpt
	 * @return \WP_Post[]
	 */
	private function consumers( string $consumer_cpt ): array {
		return $this->posts->search( $consumer_cpt, array(
			'status' => self::STATUSES,
			'limit'  => -1,
		) );
	}
}
