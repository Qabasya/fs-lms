<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Course\ModuleDTO;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Enums\Course\StepType;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class CourseBuilderService
 *
 * Read/write-движок Stepik-style конструктора курса (канон — design_handoff_course_builder/).
 * Собирает дерево «курс → модули → уроки → шаги» для JS-приложения и сохраняет структуру.
 * Контент шага правится отдельно (reuse LessonAuthoringService / SaveLessonSteps).
 *
 * @package Inc\Services\Course
 */
class CourseBuilderService {

	public function __construct(
		private readonly CourseManager $courses,
		private readonly LessonManager $lessons,
		private readonly PostManager   $posts,
	) {}

	/**
	 * Создаёт черновик курса (для «Новый курс»).
	 *
	 * @param string $subjectKey
	 * @param string $title
	 *
	 * @return int ID нового курса (0 — неудача).
	 */
	public function createCourse( string $subjectKey, string $title ): int {
		$dto = CourseDTO::fromArray( array(
			'subject_key' => $subjectKey,
			'title'       => '' !== $title ? $title : 'Новый курс',
			'modules'     => array(),
			'author_id'   => get_current_user_id(),
			'status'      => 'draft',
		) );

		return $this->courses->create( $subjectKey, $dto );
	}

	/**
	 * Полное дерево курса для JS-приложения конструктора.
	 *
	 * @param int $courseId
	 *
	 * @return array<string, mixed>|null null — курс не найден.
	 */
	public function buildTree( int $courseId ): ?array {
		$course = $this->courses->get( $courseId );
		if ( null === $course ) {
			return null;
		}

		$modules = array();
		foreach ( $course->modules as $module ) {
			$lessons = array();
			foreach ( $module->lessonIds as $lessonId ) {
				$lesson = $this->lessons->get( $lessonId );
				if ( null !== $lesson ) {
					$lessons[] = $this->lessonNode( $lesson );
				}
			}

			$modules[] = array(
				'id'          => $module->id,
				'title'       => $module->title,
				'description' => $module->description,
				'lessons'     => $lessons,
			);
		}

		return array(
			'id'          => $course->id,
			'title'       => $course->title,
			'subject_key' => $course->subjectKey,
			'status'      => $course->status,
			'author_id'   => $course->authorId,
			'thumbnail'   => get_the_post_thumbnail_url( $course->id, 'medium' ) ?: '',
			'modules'     => $modules,
		);
	}

	/**
	 * Сохраняет структуру курса (модули: порядок, заголовки, состав/порядок уроков).
	 * Заголовок/описание/статус курса не трогаются. Чужие уроки отбрасываются.
	 *
	 * @param int   $courseId
	 * @param array<int, array{id?: string, title?: string, lesson_ids?: array}> $rawModules
	 *
	 * @return bool
	 */
	public function saveStructure( int $courseId, array $rawModules ): bool {
		$course = $this->courses->get( $courseId );
		if ( null === $course ) {
			return false;
		}

		$modules = array();
		foreach ( $rawModules as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$modules[] = ModuleDTO::fromArray( array(
				'id'          => (string) ( $raw['id'] ?? '' ) ?: $this->generateId( 'm' ),
				'title'       => (string) ( $raw['title'] ?? '' ),
				'description' => (string) ( $raw['description'] ?? '' ),
				'lesson_ids'  => $this->validateLessonIds( $course->subjectKey, (array) ( $raw['lesson_ids'] ?? array() ) ),
			) );
		}

		return $this->courses->update( $courseId, $this->withModules( $course, $modules ) );
	}

	/**
	 * Создаёт урок-черновик с одним шагом-лекцией и добавляет его в модуль курса.
	 *
	 * @param int    $courseId
	 * @param string $moduleId
	 * @param string $title
	 *
	 * @return array<string, mixed>|null Нода урока для дерева, либо null.
	 */
	public function createLessonInModule( int $courseId, string $moduleId, string $title ): ?array {
		$course = $this->courses->get( $courseId );
		if ( null === $course ) {
			return null;
		}

		$lessonDto = LessonDTO::fromArray( array(
			'subject_key' => $course->subjectKey,
			'topic'       => '' !== $title ? $title : 'Новый урок',
			'steps'       => array(
				array( 'key' => $this->generateId( 's' ), 'type' => StepType::Text->value, 'payload' => array( 'title' => 'Лекция', 'content' => '' ) ),
			),
			'author_id'   => get_current_user_id(),
			'status'      => 'draft',
		) );

		$lessonId = $this->lessons->create( $course->subjectKey, $lessonDto );
		if ( $lessonId <= 0 ) {
			return null;
		}

		$modules = array();
		$added   = false;
		foreach ( $course->modules as $module ) {
			$lessonIds = $module->lessonIds;
			if ( $module->id === $moduleId ) {
				$lessonIds[] = $lessonId;
				$added       = true;
			}
			$modules[] = new ModuleDTO( $module->id, $module->title, $lessonIds, $module->description );
		}

		if ( ! $added && ! empty( $modules ) ) {
			$last      = $modules[ array_key_last( $modules ) ];
			$modules[ array_key_last( $modules ) ] = new ModuleDTO( $last->id, $last->title, array_merge( $last->lessonIds, array( $lessonId ) ), $last->description );
		}

		$this->courses->update( $courseId, $this->withModules( $course, $modules ) );

		$created = $this->lessons->get( $lessonId );
		return null !== $created ? $this->lessonNode( $created ) : null;
	}

	/**
	 * Обновляет заголовок и статус публикации урока (без перезаписи шагов).
	 *
	 * @param int    $lessonId
	 * @param string $title
	 * @param bool   $published
	 *
	 * @return bool
	 */
	public function updateLessonMeta( int $lessonId, string $title, bool $published ): bool {
		$post = $this->posts->get( $lessonId );
		if ( null === $post || ! PostTypeResolver::isLessonPostType( $post->post_type ) ) {
			return false;
		}

		$this->posts->update( $lessonId, array( 'post_title' => '' !== $title ? $title : 'Без названия' ) );
		$this->posts->updateStatus( $lessonId, $published ? 'publish' : 'draft' );

		return true;
	}

	/**
	 * @param int    $courseId
	 * @param string $title
	 * @param bool   $published
	 *
	 * @return bool
	 */
	public function updateCourseMeta( int $courseId, string $title, string $status, int $authorId = 0, int $thumbnailId = 0 ): bool {
		$post = $this->posts->get( $courseId );
		if ( null === $post || ! PostTypeResolver::isCoursePostType( $post->post_type ) ) {
			return false;
		}

		$allowed_statuses = array( 'draft', 'publish', 'private' );
		$safe_status      = in_array( $status, $allowed_statuses, true ) ? $status : 'draft';

		$this->posts->update( $courseId, array( 'post_title' => '' !== $title ? $title : 'Без названия' ) );
		$this->posts->updateStatus( $courseId, $safe_status );

		if ( $authorId > 0 ) {
			$this->posts->update( $courseId, array( 'post_author' => $authorId ) );
		}

		if ( $thumbnailId > 0 ) {
			set_post_thumbnail( $courseId, $thumbnailId );
		}

		return true;
	}

	/**
	 * Нода урока для дерева конструктора.
	 *
	 * @param LessonDTO $lesson
	 *
	 * @return array<string, mixed>
	 */
	private function lessonNode( LessonDTO $lesson ): array {
		return array(
			'id'        => $lesson->id,
			'title'     => $lesson->topic,
			'published' => 'publish' === $lesson->status,
			'steps'     => array_map( array( $this, 'stepNode' ), $lesson->steps ),
		);
	}

	/**
	 * Нода шага: {key, type, title}. Заголовок инлайн-шага — из payload.title,
	 * ссылочного — из заголовка связанной сущности.
	 *
	 * @param StepDTO $step
	 *
	 * @return array{key: string, type: string, title: string, payload: array}
	 */
	private function stepNode( StepDTO $step ): array {
		$title = (string) ( $step->payload['title'] ?? '' );

		if ( '' === $title && $step->type->isRef() ) {
			$refId = (int) ( $step->payload['ref'] ?? 0 );
			$post  = $refId > 0 ? $this->posts->get( $refId ) : null;
			$title = $post instanceof \WP_Post ? $post->post_title : '';
		}

		return array(
			'key'     => $step->key,
			'type'    => $step->type->value,
			'title'   => '' !== $title ? $title : $step->type->label(),
			'payload' => $step->payload,
		);
	}

	/**
	 * Оставляет только уроки нужного предмета (защита от подмены ссылок).
	 *
	 * @param string $subjectKey
	 * @param array  $lessonIds
	 *
	 * @return int[]
	 */
	private function validateLessonIds( string $subjectKey, array $lessonIds ): array {
		$post_type = PostTypeResolver::lessons( $subjectKey );
		$result    = array();
		foreach ( $lessonIds as $id ) {
			$id   = (int) $id;
			$post = $id > 0 ? $this->posts->get( $id ) : null;
			if ( $post instanceof \WP_Post && $post->post_type === $post_type ) {
				$result[] = $id;
			}
		}

		return $result;
	}

	/**
	 * Копия CourseDTO с заменёнными модулями (DTO неизменяем).
	 *
	 * @param CourseDTO  $course
	 * @param ModuleDTO[] $modules
	 *
	 * @return CourseDTO
	 */
	private function withModules( CourseDTO $course, array $modules ): CourseDTO {
		return new CourseDTO(
			id             : $course->id,
			subjectKey     : $course->subjectKey,
			title          : $course->title,
			descriptionHtml: $course->descriptionHtml,
			modules        : $modules,
			authorId       : $course->authorId,
			status         : $course->status,
		);
	}

	/**
	 * Стабильный course-local идентификатор (модуль/шаг), переживает реордер.
	 */
	private function generateId( string $prefix ): string {
		return $prefix . '_' . bin2hex( random_bytes( 5 ) );
	}
}
