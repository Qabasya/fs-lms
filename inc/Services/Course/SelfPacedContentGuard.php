<?php
/**
 * Candidate guards for explicitly self-paced courses.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\Enums\Course\StepType;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Template\TemplateResolver;

/** Candidate validation for explicitly self-paced courses; legacy open groups are unchanged. */
class SelfPacedContentGuard {
	use \Inc\Shared\Traits\ScopedFilter;

	/** Resolve all used tasks against an unsaved assignment map, restoring filters even on refusal. */
	public function assertTemplateAssignments( array $assignments ): void {
		$this->withFilter(
			'pre_option_' . \Inc\Enums\Settings\OptionName::Metaboxes->value,
			static fn() => $assignments,
			fn() => $this->assertMetadata( 0, PostMetaName::Meta->value, null ),
			PHP_INT_MAX,
		);
	}

	/** Task post types whose REST writes can change the effective checking template. */
	public function taskPostTypes(): array {
		return array_values( array_map( static fn( $subject ): string => PostTypeResolver::tasks( $subject->key ), $this->subjects->readAll() ) );
	}

	/** Receive storage and validation dependencies. */
	public function __construct(
		private readonly PostManager $posts,
		private readonly SubjectRepository $subjects,
		private readonly TemplateResolver $templates,
		private readonly TaskCheckerRegistry $checkers,
	) {}

	/** Validate an explicit self-paced candidate before enabling or publishing. */
	public function assertCourse( CourseDTO $course ): void {
		if ( $course->selfPacedEnabled ) {
			$this->inspect( $course );
		}
	}

	/** Validate a task-number change before the relationship is written. */
	public function assertTaskTerms( int $id, array $terms ): void {
		$post = $this->posts->get( $id );
		if ( null === $post || ! PostTypeResolver::isTaskPostType( $post->post_type ) ) {
			return;
		}
		$this->assertMetadata( $id, PostMetaName::TemplateType->value, $this->posts->getMeta( $id, PostMetaName::TemplateType->value ), $terms );
	}

	/** Check persisted content before publication. */
	public function assertPublication( int $id ): void {
		$post = $this->posts->get( $id );
		if ( null !== $post && PostTypeResolver::isCoursePostType( $post->post_type ) && $this->posts->getMeta( $id, PostMetaName::CourseSelfPacedEnabled->value ) ) {
			$this->inspect( CourseDTO::fromPost( $post, $this->meta( $id, 0, '', null ) ) );
		}
	}

	/** Validate a metadata candidate before WordPress writes it, including importer writes. */
	public function assertMetadata( int $postId, string $key, mixed $value, ?array $candidateTerms = null ): void {
		if ( ! in_array( $key, array( PostMetaName::Meta->value, PostMetaName::TemplateType->value, PostMetaName::CourseSelfPacedEnabled->value ), true ) ) {
			return;
		}
		foreach ( $this->subjects->readAll() as $subject ) {
			foreach ( $this->posts->search(
				PostTypeResolver::courses( $subject->key ),
				array(
					'status' => 'publish',
					'limit'  => -1,
				)
			) as $post ) {
				$enabled = $post->ID === $postId && PostMetaName::CourseSelfPacedEnabled->value === $key
					? (bool) $value : (bool) $this->posts->getMeta( $post->ID, PostMetaName::CourseSelfPacedEnabled->value );
				if ( ! $enabled ) {
					continue;
				}
				$meta   = $this->meta( $post->ID, $postId, $key, $value );
				$course = CourseDTO::fromPost( $post, $meta );
				$this->inspect( $course, $postId, $key, $value, $candidateTerms );
			}
		}
	}

	/**
	 * Validate only courses containing the changed entity, using its candidate metadata.
	 *
	 * @throws \InvalidArgumentException Incompatible self-paced content.
	 */
	private function inspect( CourseDTO $course, int $changedId = 0, string $key = '', mixed $value = null, ?array $candidateTerms = null ): void {
		$affected = 0 === $changedId || $course->id === $changedId;
		$problems = array();
		foreach ( $course->lessonIds() as $lessonId ) {
			$affected = $affected || $lessonId === $changedId;
			$post     = $this->posts->get( $lessonId );
			if ( null === $post || ! PostTypeResolver::isLessonPostType( $post->post_type ) ) {
				$problems[] = 'Урок не найден';
				continue;
			}
			$lesson = LessonDTO::fromPost( $post, $this->meta( $lessonId, $changedId, $key, $value ) );
			foreach ( $lesson->steps as $step ) {
				$ref = (int) ( $step->payload['ref'] ?? 0 );
				if ( StepType::Assessment === $step->type ) {
					$affected   = $affected || $ref === $changedId;
					$problems[] = 'Контрольные недоступны';
				} elseif ( StepType::Task === $step->type ) {
					$affected = $affected || $ref === $changedId;
					$this->checkTask( $ref, $changedId, $key, $value, $problems, $candidateTerms );
				} elseif ( StepType::Work === $step->type ) {
					$affected = $affected || $ref === $changedId;
					$work     = $this->posts->get( $ref );
					if ( null === $work || ! PostTypeResolver::isWorkPostType( $work->post_type ) ) {
						$problems[] = 'Работа не найдена';
						continue;
					}
					$meta = $this->meta( $ref, $changedId, $key, $value );
					foreach ( (array) ( $meta['item_ids'] ?? array() ) as $taskId ) {
						$taskId   = (int) $taskId;
						$affected = $affected || $taskId === $changedId;
						$this->checkTask( $taskId, $changedId, $key, $value, $problems, $candidateTerms );
					}
				}
			}
		}
		if ( $affected && array() !== $problems ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain message is escaped by the rendering caller.
			throw new \InvalidArgumentException( 'Самостоятельный курс должен содержать только автопроверяемый контент: ' . implode( '; ', array_unique( $problems ) ) );
		}
	}

	/** Read metadata with an optional unsaved replacement. */
	private function meta( int $id, int $changedId, string $key, mixed $value ): array {
		$meta = $id === $changedId && PostMetaName::Meta->value === $key ? $value : $this->posts->getMeta( $id, PostMetaName::Meta->value );
		return is_array( $meta ) ? $meta : array();
	}

	/** Check that a referenced task exists and has an automatic checker. */
	private function checkTask( int $id, int $changedId, string $key, mixed $value, array &$problems, ?array $candidateTerms = null ): void {
		$post = $this->posts->get( $id );
		if ( null === $post || ( ! PostTypeResolver::isTaskPostType( $post->post_type ) && ! PostTypeResolver::isProblemPostType( $post->post_type ) ) ) {
			$problems[] = 'Задача не найдена';
			return;
		}
		$template = $id === $changedId && PostMetaName::TemplateType->value === $key
			? TaskTemplate::tryFrom( $this->templates->resolveId( $post, $candidateTerms, array( $key => $value ) ) ) : TaskTemplate::tryFrom( $this->templates->resolveId( $post ) );
		if ( null === $template || ! $this->checkers->has( $template ) ) {
			$problems[] = 'Задача без автопроверки';
		}
	}
}
