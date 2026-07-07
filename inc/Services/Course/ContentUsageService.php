<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\ModuleDTO;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Enums\Course\StepType;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
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
		private readonly PostManager            $posts,
		private readonly SubjectRepository      $subjects,
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
		$count = count( $this->usageList( $type, $postId ) ) + $this->deliveryCount( $type, $postId );
		// Задача (или задача банка) может быть вопросом контрольной (task_ids) ИЛИ
		// прямым task-шагом урока (StepType::Task, payload.ref) — оба это тоже
		// использование. Без них usageList покрывает только задачи внутри работ,
		// поэтому задача, стоящая в уроке напрямую, ошибочно считалась удаляемой
		// (B5): гейт удаления её не защищал.
		if ( 'task' === $type || 'problem' === $type ) {
			$count += count( $this->assessmentsUsingTask( $postId ) );
			$count += count( $this->directLessonConsumers( $postId, $type ) );
		}
		return $count;
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
	 * Возвращает список хлебных крошек использования для задач и задач из банка.
	 *
	 * Цепочка: задача → работа → урок → курс.
	 * Каждый элемент: display = название верхнего уровня (курса), tooltip = полный путь.
	 *
	 * @param string $type task|problem
	 * @param int    $postId
	 * @return array<int, array{display: string, tooltip: string}>
	 */
	public function usagePathList( string $type, int $postId ): array {
		$works = 'problem' === $type
			? $this->problemWorkers( $postId )
			: $this->usageList( $type, $postId );

		[ $courses, $fallbacks ] = $this->coursesFromWorks( $works );

		// Прямые шаги типа task в уроках (без промежуточной работы).
		foreach ( $this->directLessonConsumers( $postId, $type ) as $lesson ) {
			foreach ( $this->usageList( 'lesson', $lesson['id'] ) as $course ) {
				$key = (string) $course['id'];
				if ( ! isset( $courses[ $key ] ) ) {
					$courses[ $key ] = array(
						'display' => $course['title'],
						'tooltip' => $course['title'] . ' / ' . $lesson['title'],
						'url'     => admin_url( 'admin.php?page=fs_lms_course_builder&course=' . $course['id'] . '&lesson=' . $lesson['id'] . '&step_ref=' . $postId ),
					);
				}
			}
		}

		// Задача как вопрос контрольной: задача → контрольная → урок → курс.
		foreach ( $this->assessmentsUsingTask( $postId ) as $assessment ) {
			$lessons = $this->usageList( 'assessment', $assessment['id'] );
			if ( empty( $lessons ) ) {
				$fallbacks[ 'a' . $assessment['id'] ] = array(
					'display' => $assessment['title'],
					'tooltip' => $assessment['title'],
					'url'     => admin_url( 'post.php?post=' . $assessment['id'] . '&action=edit' ),
				);
				continue;
			}
			foreach ( $lessons as $lesson ) {
				$lesson_courses = $this->usageList( 'lesson', $lesson['id'] );
				if ( empty( $lesson_courses ) ) {
					$fallbacks[ 'l' . $lesson['id'] ] = array(
						'display' => $lesson['title'],
						'tooltip' => $lesson['title'] . ' / ' . $assessment['title'],
						'url'     => admin_url( 'post.php?post=' . $lesson['id'] . '&action=edit' ),
					);
					continue;
				}
				foreach ( $lesson_courses as $course ) {
					$key = (string) $course['id'];
					if ( ! isset( $courses[ $key ] ) ) {
						$courses[ $key ] = array(
							'display' => $course['title'],
							'tooltip' => $course['title'] . ' / ' . $lesson['title'] . ' / ' . $assessment['title'],
							'url'     => admin_url( 'admin.php?page=fs_lms_course_builder&course=' . $course['id'] . '&lesson=' . $lesson['id'] . '&step_ref=' . $assessment['id'] ),
						);
					}
				}
			}
		}

		return array_values( ! empty( $courses ) ? $courses : $fallbacks );
	}

	/**
	 * Возвращает ссылки на курсы для уроков, работ и контрольных (без тултипов).
	 *
	 * lesson     → курсы, содержащие урок; ссылка на course builder с lesson=ID.
	 * work|assessment → уроки → курсы; ссылка с lesson=ID&step_ref=postId.
	 *
	 * @param string $type lesson|work|assessment
	 * @param int    $postId
	 * @return array<int, array{display: string, tooltip: string, url: string}>
	 */
	public function courseLinksFor( string $type, int $postId ): array {
		if ( 'lesson' === $type ) {
			$links = array();
			foreach ( $this->usageList( 'lesson', $postId ) as $course ) {
				$links[] = array(
					'display' => $course['title'],
					'tooltip' => $course['title'],
					'url'     => admin_url( 'admin.php?page=fs_lms_course_builder&course=' . $course['id'] . '&lesson=' . $postId ),
				);
			}
			return $links;
		}

		if ( 'work' === $type || 'assessment' === $type ) {
			$courses = array();
			foreach ( $this->usageList( $type, $postId ) as $lesson ) {
				foreach ( $this->usageList( 'lesson', $lesson['id'] ) as $course ) {
					$key = (string) $course['id'];
					if ( ! isset( $courses[ $key ] ) ) {
						$courses[ $key ] = array(
							'display' => $course['title'],
							'tooltip' => $course['title'],
							'url'     => admin_url( 'admin.php?page=fs_lms_course_builder&course=' . $course['id'] . '&lesson=' . $lesson['id'] . '&step_ref=' . $postId ),
						);
					}
				}
			}
			return array_values( $courses );
		}

		return array();
	}

	/**
	 * Находит уроки, которые ссылаются на задачу через прямой шаг (StepType::Task).
	 *
	 * @param int    $postId
	 * @param string $type task|problem
	 * @return array<int, array{id: int, title: string, type: string}>
	 */
	private function directLessonConsumers( int $postId, string $type ): array {
		if ( 'task' === $type ) {
			$post = $this->posts->get( $postId );
			if ( ! $post instanceof \WP_Post ) {
				return array();
			}
			$subject = PostTypeResolver::subjectFromTaskPostType( $post->post_type );
			return $this->lessonsWithTaskStep( $postId, PostTypeResolver::lessons( $subject ) );
		}

		// problem — кросс-предметный поиск.
		$result = array();
		foreach ( $this->subjects->readAll() as $s ) {
			$result = array_merge( $result, $this->lessonsWithTaskStep( $postId, PostTypeResolver::lessons( $s->key ) ) );
		}
		return $result;
	}

	/**
	 * Возвращает уроки из указанного CPT, у которых есть шаг типа task с ref=taskId.
	 *
	 * @param int    $taskId
	 * @param string $lessons_cpt
	 * @return array<int, array{id: int, title: string, type: string}>
	 */
	private function lessonsWithTaskStep( int $taskId, string $lessons_cpt ): array {
		$result = array();
		foreach ( $this->consumers( $lessons_cpt ) as $lesson ) {
			$meta  = $this->posts->getMeta( $lesson->ID, PostMetaName::Meta->value );
			$meta  = is_array( $meta ) ? $meta : array();
			$steps = StepDTO::fromList( is_array( $meta['steps'] ?? null ) ? $meta['steps'] : array() );
			foreach ( $steps as $step ) {
				if ( StepType::Task === $step->type && (int) ( $step->payload['ref'] ?? 0 ) === $taskId ) {
					$result[] = array(
						'id'    => $lesson->ID,
						'title' => $lesson->post_title,
						'type'  => $lesson->post_type,
					);
					break;
				}
			}
		}
		return $result;
	}

	/**
	 * Кросс-предметный поиск работ, ссылающихся на задачу из банка.
	 *
	 * @param int $problemId
	 * @return array<int, array{id: int, title: string, type: string}>
	 */
	private function problemWorkers( int $problemId ): array {
		$result = array();
		foreach ( $this->subjects->readAll() as $subject ) {
			foreach ( $this->consumers( PostTypeResolver::works( $subject->key ) ) as $work ) {
				$meta    = $this->posts->getMeta( $work->ID, PostMetaName::Meta->value );
				$meta    = is_array( $meta ) ? $meta : array();
				$ids     = array_map( 'intval', is_array( $meta['item_ids'] ?? null ) ? $meta['item_ids'] : array() );
				if ( in_array( $problemId, $ids, true ) ) {
					$result[] = array(
						'id'    => $work->ID,
						'title' => $work->post_title,
						'type'  => $work->post_type,
					);
				}
			}
		}
		return $result;
	}

	/**
	 * Кросс-предметный поиск контрольных, у которых задача (или задача банка) —
	 * вопрос (`task_ids`). ID постов глобальны, поэтому скан по всем предметам корректен.
	 *
	 * @param int $taskId
	 * @return array<int, array{id: int, title: string, type: string}>
	 */
	private function assessmentsUsingTask( int $taskId ): array {
		$result = array();
		foreach ( $this->subjects->readAll() as $subject ) {
			foreach ( $this->consumers( PostTypeResolver::assessments( $subject->key ) ) as $assessment ) {
				$meta = $this->posts->getMeta( $assessment->ID, PostMetaName::Meta->value );
				$meta = is_array( $meta ) ? $meta : array();
				$ids  = array_map( 'intval', is_array( $meta['task_ids'] ?? null ) ? $meta['task_ids'] : array() );
				if ( in_array( $taskId, $ids, true ) ) {
					$result[] = array(
						'id'    => $assessment->ID,
						'title' => $assessment->post_title,
						'type'  => $assessment->post_type,
					);
				}
			}
		}
		return $result;
	}

	/**
	 * Строит хлебные крошки по списку работ: работа → урок → курс.
	 * Возвращает [$courses, $fallbacks], оба keyed — дедупликация по ID.
	 *
	 * @param array<int, array{id: int, title: string, type: string}> $works
	 * @return array{array<string, array{display: string, tooltip: string}>, array<string, array{display: string, tooltip: string}>}
	 */
	private function coursesFromWorks( array $works ): array {
		$courses   = array();
		$fallbacks = array();

		foreach ( $works as $work ) {
			$lessons = $this->usageList( 'work', $work['id'] );
			if ( empty( $lessons ) ) {
				$fallbacks[ 'w' . $work['id'] ] = array(
					'display' => $work['title'],
					'tooltip' => $work['title'],
					'url'     => admin_url( 'post.php?post=' . $work['id'] . '&action=edit' ),
				);
				continue;
			}
			foreach ( $lessons as $lesson ) {
				$lesson_courses = $this->usageList( 'lesson', $lesson['id'] );
				if ( empty( $lesson_courses ) ) {
					$fallbacks[ 'l' . $lesson['id'] ] = array(
						'display' => $lesson['title'],
						'tooltip' => $lesson['title'] . ' / ' . $work['title'],
						'url'     => admin_url( 'post.php?post=' . $lesson['id'] . '&action=edit' ),
					);
					continue;
				}
				foreach ( $lesson_courses as $course ) {
					$key = (string) $course['id'];
					if ( ! isset( $courses[ $key ] ) ) {
						$courses[ $key ] = array(
							'display' => $course['title'],
							'tooltip' => $course['title'] . ' / ' . $lesson['title'] . ' / ' . $work['title'],
							'url'     => admin_url( 'admin.php?page=fs_lms_course_builder&course=' . $course['id'] . '&lesson=' . $lesson['id'] . '&step_ref=' . $work['id'] ),
						);
					}
				}
			}
		}

		return array( $courses, $fallbacks );
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
			'task'       => array( PostTypeResolver::works( PostTypeResolver::subjectFromTaskPostType( $post_type ) ), 'item_ids', false ),
			'problem'    => array( '', 'item_ids', false ), // кросс-предметный поиск — TODO Этап 2 (SubjectRepository needed)
			'work'       => array( PostTypeResolver::lessons( PostTypeResolver::subjectFromWorkPostType( $post_type ) ), 'steps:work', false ),
			// Контрольная используется как шаг урока (StepType::Assessment) — как и работа.
			// Без этой ветки usageList('assessment') был пуст: «использование в курсе» не подтягивалось.
			'assessment' => array( PostTypeResolver::lessons( PostTypeResolver::subjectFromAssessmentPostType( $post_type ) ), 'steps:assessment', false ),
			'lesson'     => array( PostTypeResolver::courses( PostTypeResolver::subjectFromLessonPostType( $post_type ) ), 'modules:lesson', false ),
			default      => array( '', '', false ), // course → groups (Этап 2)
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
		// Ссылки урока живут в шагах (steps:work) — извлекаем по типу шага.
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
	 * Refs шагов урока указанного вида: work|assessment|task → payload['ref'].
	 *
	 * @param array  $meta Meta урока (с ключом `steps`).
	 * @param string $kind work|assessment|task
	 *
	 * @return int[]
	 */
	private function stepRefs( array $meta, string $kind ): array {
		$type = match ( $kind ) {
			'work'       => StepType::Work,
			'assessment' => StepType::Assessment,
			'task'       => StepType::Task,
			default      => null,
		};

		if ( null === $type ) {
			return array();
		}

		$steps = StepDTO::fromList( is_array( $meta['steps'] ?? null ) ? $meta['steps'] : array() );

		$ids = array();
		foreach ( $steps as $step ) {
			if ( $type === $step->type ) {
				$id = (int) ( $step->payload['ref'] ?? 0 );
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
