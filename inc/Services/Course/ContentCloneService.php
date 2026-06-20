<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Course\ModuleDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\AssessmentManager;
use Inc\Managers\CourseManager;
use Inc\Managers\LessonManager;
use Inc\Managers\PostManager;
use Inc\Managers\WorkManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Services\PostTypeResolver;

/**
 * Class ContentCloneService
 *
 * Клонирование / форк банков контента (T1.5.11):
 * - cloneLesson / cloneWork / cloneAssessment — независимая копия в том же банке
 * - cloneCourse(shallow|deep) — копия курса; deep рекурсивно форкает уроки
 * - forkLessonForGroup — групповой форк урока (meta forked_from + forked_for_group)
 * - forkModuleForGroup / forkCourseForGroup — массовый форк
 *
 * @package Inc\Services\Course
 */
class ContentCloneService {

	public function __construct(
		private readonly PostManager           $posts,
		private readonly LessonManager         $lessons,
		private readonly WorkManager           $works,
		private readonly CourseManager         $courses,
		private readonly AssessmentManager     $assessments,
		private readonly GroupLessonRepository $groupLessons,
	) {}

	/**
	 * Клон урока: новый пост + копия steps[]. Шаги-ссылки (work/assessment/task) остаются ссылками.
	 *
	 * @return int ID нового урока или 0 при ошибке.
	 */
	public function cloneLesson( int $lessonId ): int {
		$lesson = $this->lessons->get( $lessonId );
		if ( null === $lesson ) {
			return 0;
		}

		return $this->lessons->create(
			$lesson->subjectKey,
			new LessonDTO(
				id        : 0,
				subjectKey: $lesson->subjectKey,
				topic     : $lesson->topic . ' (копия)',
				steps     : $lesson->steps,
				authorId  : get_current_user_id(),
				status    : 'draft',
			)
		);
	}

	/**
	 * Клон работы: новый пост + копия item_ids[].
	 *
	 * @return int ID новой работы или 0 при ошибке.
	 */
	public function cloneWork( int $workId ): int {
		$work = $this->works->get( $workId );
		if ( null === $work ) {
			return 0;
		}

		return $this->works->create(
			$work->subjectKey,
			new WorkDTO(
				id          : 0,
				subjectKey  : $work->subjectKey,
				title       : $work->title . ' (копия)',
				instructions: $work->instructions,
				workType    : $work->workType,
				itemIds     : $work->itemIds,
				authorId    : get_current_user_id(),
				status      : 'draft',
			)
		);
	}

	/**
	 * Клон контрольной: новый пост + копия task_ids[] и настроек.
	 *
	 * @return int ID новой контрольной или 0 при ошибке.
	 */
	public function cloneAssessment( int $assessmentId ): int {
		$assessment = $this->assessments->get( $assessmentId );
		if ( null === $assessment ) {
			return 0;
		}

		$newId = $this->posts->insert( array(
			'post_title'  => $assessment->title . ' (копия)',
			'post_type'   => PostTypeResolver::assessments( $assessment->subjectKey ),
			'post_status' => 'draft',
			'post_author' => get_current_user_id(),
		) );

		if ( $newId > 0 ) {
			$this->posts->updateMeta( $newId, PostMetaName::Meta->value, array(
				'task_ids'            => $assessment->taskIds,
				'time_limit_minutes'  => $assessment->timeLimit,
				'max_attempts'        => $assessment->attemptsAllowed,
				'pass_score'          => $assessment->passScore,
				'shuffle'             => $assessment->shuffle,
				'scoring_policy'      => $assessment->scoringPolicy->value,
			) );
		}

		return $newId;
	}

	/**
	 * Клон курса.
	 * shallow — те же ссылки на уроки (по умолчанию).
	 * deep    — рекурсивно форкает каждый урок.
	 *
	 * @param string $mode 'shallow' | 'deep'
	 * @return int ID нового курса или 0 при ошибке.
	 */
	public function cloneCourse( int $courseId, string $mode = 'shallow' ): int {
		$course = $this->courses->get( $courseId );
		if ( null === $course ) {
			return 0;
		}

		$modules = 'deep' === $mode
			? $this->deepCloneModules( $course )
			: $course->modules;

		return $this->courses->create(
			$course->subjectKey,
			new CourseDTO(
				id             : 0,
				subjectKey     : $course->subjectKey,
				title          : $course->title . ' (копия)',
				descriptionHtml: $course->descriptionHtml,
				modules        : $modules,
				authorId       : get_current_user_id(),
				status         : 'draft',
			)
		);
	}

	/**
	 * Групповой форк урока:
	 * — создаёт новый `{key}_lessons` с метами `forked_from` + `forked_for_group`
	 * — перецепляет `group_lessons.lesson_id` на форк
	 * — если переданная строка уже является форком для этой группы — возвращает её lessonId.
	 *
	 * @param int $groupId       ID группы.
	 * @param int $groupLessonId ID строки `group_lessons`.
	 * @return int ID форка или 0 при ошибке.
	 */
	public function forkLessonForGroup( int $groupId, int $groupLessonId ): int {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( null === $row || $row->groupId !== $groupId ) {
			return 0;
		}

		// Уже является форком для этой группы — идемпотентность.
		$existingGroup = (int) $this->posts->getMeta( $row->lessonId, PostMetaName::ForkedForGroup->value );
		if ( $existingGroup === $groupId && $groupId > 0 ) {
			return $row->lessonId;
		}

		$lesson = $this->lessons->get( $row->lessonId );
		if ( null === $lesson ) {
			return 0;
		}

		$forkId = $this->lessons->create(
			$lesson->subjectKey,
			new LessonDTO(
				id        : 0,
				subjectKey: $lesson->subjectKey,
				topic     : $lesson->topic,
				steps     : $lesson->steps,
				authorId  : get_current_user_id(),
				status    : $lesson->status,
			)
		);

		if ( $forkId <= 0 ) {
			return 0;
		}

		$this->posts->updateMeta( $forkId, PostMetaName::ForkedFrom->value, $row->lessonId );
		$this->posts->updateMeta( $forkId, PostMetaName::ForkedForGroup->value, $groupId );
		$this->groupLessons->setLessonId( $groupLessonId, $forkId );

		return $forkId;
	}

	/**
	 * Форкает все уроки указанного модуля курса для группы.
	 *
	 * @return bool true — хотя бы один форк выполнен.
	 */
	public function forkModuleForGroup( int $groupId, int $courseId, string $moduleId ): bool {
		$course = $this->courses->get( $courseId );
		if ( null === $course ) {
			return false;
		}

		$module = null;
		foreach ( $course->modules as $m ) {
			if ( $m->id === $moduleId ) {
				$module = $m;
				break;
			}
		}

		if ( null === $module ) {
			return false;
		}

		return $this->forkLessonListForGroup( $groupId, $module->lessonIds );
	}

	/**
	 * Форкает все уроки всех модулей курса для группы.
	 *
	 * @return bool true — хотя бы один форк выполнен.
	 */
	public function forkCourseForGroup( int $groupId, int $courseId ): bool {
		$course = $this->courses->get( $courseId );
		if ( null === $course ) {
			return false;
		}

		return $this->forkLessonListForGroup( $groupId, $course->lessonIds() );
	}

	/**
	 * Для каждого lessonId из списка находит строку group_lessons и форкает урок.
	 *
	 * @param int[] $lessonIds
	 */
	private function forkLessonListForGroup( int $groupId, array $lessonIds ): bool {
		if ( empty( $lessonIds ) ) {
			return false;
		}

		$rows     = $this->groupLessons->listByGroup( $groupId );
		$byLesson = array();
		foreach ( $rows as $row ) {
			$byLesson[ $row->lessonId ] = $row->id;
		}

		$ok = false;
		foreach ( $lessonIds as $lessonId ) {
			if ( isset( $byLesson[ $lessonId ] ) ) {
				$forkId = $this->forkLessonForGroup( $groupId, $byLesson[ $lessonId ] );
				if ( $forkId > 0 ) {
					$ok = true;
				}
			}
		}

		return $ok;
	}

	/**
	 * Deep-клон модулей курса: форкает каждый урок, заменяет ссылки на форки.
	 *
	 * @param CourseDTO $course
	 * @return ModuleDTO[]
	 */
	private function deepCloneModules( CourseDTO $course ): array {
		$modules = array();
		foreach ( $course->modules as $module ) {
			$newLessonIds = array();
			foreach ( $module->lessonIds as $lessonId ) {
				$cloneId = $this->cloneLesson( $lessonId );
				if ( $cloneId > 0 ) {
					$newLessonIds[] = $cloneId;
				}
			}
			$modules[] = new ModuleDTO(
				id       : $module->id,
				title    : $module->title,
				lessonIds: $newLessonIds,
			);
		}

		return $modules;
	}
}
