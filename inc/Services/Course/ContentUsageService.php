<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\ModuleDTO;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Enums\Course\StepType;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class ContentUsageService
 *
 * Единый источник «кто на меня ссылается» по всем банкам контента.
 * Питает и бейдж «используется в N», и гейт удаления (ContentDeletionGuard).
 *
 * Источники ссылок (Этап 1):
 *  - задание / задача ← work.item_ids
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
		private readonly PostManager           $posts,
		private readonly ?GroupLessonRepository $groupLessons = null,
		private readonly ?GroupsRepository      $groups       = null,
	) {}

	/**
	 * Количество потребителей контента. 0 = orphan (удаляемо).
	 *
	 * @param string $type task|work|lesson|course|article
	 * @param int    $postId
	 * @return int
	 */
	public function usageCount( string $type, int $postId ): int {
		return count( $this->usageList( $type, $postId ) ) + $this->deliveryCount( $type, $postId );
	}

	/** Количество delivery-потребителей из БД-таблиц (group_lessons, groups). */
	private function deliveryCount( string $type, int $postId ): int {
		if ( 'lesson' === $type && null !== $this->groupLessons ) {
			return $this->groupLessons->countUsageByLesson( $postId );
		}
		if ( 'course' === $type && null !== $this->groups ) {
			// Считаем группы, которым назначен этот курс.
			return (int) count( array_filter(
				$this->groups->findByFilters( '' ),
				fn( $g ) => isset( $g->course_id ) && (int) $g->course_id === $postId
			) );
		}
		return 0;
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
			PostTypeResolver::isProblemPostType( $post_type )              => 'problem',
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
			'task'    => array( PostTypeResolver::works( PostTypeResolver::subjectFromTaskPostType( $post_type ) ), 'item_ids', false ),
			'problem' => array( '', 'item_ids', false ), // кросс-предметный поиск — TODO Этап 2 (SubjectRepository needed)
			'work'    => array( PostTypeResolver::lessons( PostTypeResolver::subjectFromWorkPostType( $post_type ) ), 'steps:work', false ),
			'lesson'  => array( PostTypeResolver::courses( PostTypeResolver::subjectFromLessonPostType( $post_type ) ), 'modules:lesson', false ),
			'article' => array( PostTypeResolver::lessons( PostTypeResolver::subjectFromArticlePostType( $post_type ) ), 'steps:article', false ),
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
		// Ссылки урока живут в шагах (steps:work / steps:article) — извлекаем по типу шага.
		if ( str_starts_with( $field, 'steps:' ) ) {
			return in_array( $postId, $this->stepRefs( $meta, substr( $field, 6 ) ), true );
		}

		// Ссылки курса на уроки живут в модулях (modules:lesson) — разворачиваем modules[].lesson_ids.
		if ( str_starts_with( $field, 'modules:' ) ) {
			return in_array( $postId, $this->moduleLessonIds( $meta ), true );
		}

		$value = $meta[ $field ] ?? null;

		if ( $is_scalar ) {
			return (int) $value === $postId;
		}

		return is_array( $value ) && in_array( $postId, array_map( 'intval', $value ), true );
	}

	/**
	 * Refs шагов урока указанного вида:
	 * work|assessment|task → payload['ref']; article → payload['article_id'] (material-шаги).
	 *
	 * @param array  $meta Meta урока (с ключом `steps`).
	 * @param string $kind work|assessment|task|article
	 *
	 * @return int[]
	 */
	private function stepRefs( array $meta, string $kind ): array {
		$type = match ( $kind ) {
			'work'       => StepType::Work,
			'assessment' => StepType::Assessment,
			'task'       => StepType::Task,
			'article'    => StepType::Material,
			default      => null,
		};

		if ( null === $type ) {
			return array();
		}

		$payload_key = 'article' === $kind ? 'article_id' : 'ref';
		$steps       = StepDTO::fromList( is_array( $meta['steps'] ?? null ) ? $meta['steps'] : array() );

		$ids = array();
		foreach ( $steps as $step ) {
			if ( $type === $step->type ) {
				$id = (int) ( $step->payload[ $payload_key ] ?? 0 );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		return $ids;
	}

	/**
	 * Плоский список уроков курса из модулей (modules[].lesson_ids).
	 *
	 * @param array $meta Meta курса (с ключом `modules`).
	 *
	 * @return int[]
	 */
	private function moduleLessonIds( array $meta ): array {
		$modules = ModuleDTO::fromList( is_array( $meta['modules'] ?? null ) ? $meta['modules'] : array() );

		$ids = array();
		foreach ( $modules as $module ) {
			foreach ( $module->lessonIds as $lessonId ) {
				$ids[] = $lessonId;
			}
		}

		return $ids;
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
