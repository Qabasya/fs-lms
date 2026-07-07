<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\Course\GateState;
use Inc\Enums\Course\ProgressStatus;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Course\WorkManager;

/**
 * Class CoursePreviewService
 *
 * Сборка view-модели preview-плеера курса (Фаза 5, D3/D4) — тот же контракт вывода,
 * что `LessonPlayerService::buildView()`, но обходит `CourseDTO → ModuleDTO →
 * LessonDTO → StepDTO` напрямую, без ученика/занятия: гейт и статус шага всегда
 * "available", сдачи/попытки/подсказки-после-ошибок отсутствуют (это предпросмотр,
 * не прохождение). Контент шага рендерит общий `StepContentRenderer`.
 *
 * @package Inc\Services\Course
 */
class CoursePreviewService {

	public function __construct(
		private readonly CourseManager       $courses,
		private readonly LessonManager       $lessons,
		private readonly WorkManager         $works,
		private readonly AssessmentManager   $assessments,
		private readonly StepContentRenderer $stepRenderer,
	) {}

	/**
	 * @return array{course_id:int, lesson_id:int, topic:string, steps:array<int, array<string,mixed>>, preview:true}|null
	 */
	public function buildView( int $courseId, int $lessonId ): ?array {
		$course = $this->courses->get( $courseId );
		if ( null === $course || ! in_array( $lessonId, $course->lessonIds(), true ) ) {
			return null;
		}

		$lesson = $this->lessons->get( $lessonId );
		if ( null === $lesson ) {
			return null;
		}

		$steps = array();
		foreach ( $lesson->steps as $step ) {
			$steps[] = array(
				'key'    => $step->key,
				'type'   => $step->type->value,
				'title'  => $this->stepRenderer->resolveTitle( $step ),
				'gate'   => GateState::Available->value,
				'status' => ProgressStatus::Available->value,
				'render' => $this->renderData( $step ),
			);
		}

		return array(
			'course_id' => $course->id,
			'lesson_id' => $lesson->id,
			'topic'     => $lesson->topic,
			'steps'     => $steps,
			'preview'   => true,
		);
	}

	/**
	 * Оболочка плеера (аналог `CourseNavService::shell()`, без ученика/прогресса):
	 * `course_progress`/`next_lesson` намеренно `null` — `player.php` их уже
	 * умеет прятать при отсутствии.
	 *
	 * @return array{course_title:string, module_label:string, course_progress:null, student_name:string, student_role:string, next_lesson:null}
	 */
	public function shell( CourseDTO $course, int $lessonId ): array {
		return array(
			'course_title'    => $course->title,
			'module_label'    => $this->moduleLabel( $course, $lessonId ),
			'course_progress' => null,
			'student_name'    => '',
			'student_role'    => '',
			'next_lesson'     => null,
		);
	}

	/**
	 * Дерево курса для рейки (аналог `CourseNavService::tree()`): все уроки
	 * "available"/"current" (никогда done/locked). ВАЖНО: поле `group_lesson_id`
	 * каждого узла здесь хранит lesson_id, а не id занятия — `rail.php` в
	 * preview-режиме читает его именно так (см. $is_preview-ветку $rail_lesson_url).
	 *
	 * @return array{modules: array<int, array{number:int, title:string, state:string, lessons: array<int, array{group_lesson_id:int, number:int, title:string, state:string}>}>}
	 */
	public function tree( CourseDTO $course, int $currentLessonId ): array {
		$modules  = array();
		$position = 0;

		foreach ( $course->modules as $mi => $module ) {
			$lessons  = array();
			$hasCurrent = false;

			foreach ( $module->lessonIds as $lessonId ) {
				++$position;
				$lesson = $this->lessons->get( $lessonId );
				$isCurrent = $lessonId === $currentLessonId;
				$hasCurrent = $hasCurrent || $isCurrent;

				$lessons[] = array(
					'group_lesson_id' => $lessonId,
					'number'          => $position,
					'title'           => $lesson?->topic ?? '',
					'state'           => $isCurrent ? 'current' : 'available',
				);
			}

			$modules[] = array(
				'number'  => $mi + 1,
				'title'   => $module->title,
				'state'   => $hasCurrent ? 'current' : 'available',
				'lessons' => $lessons,
			);
		}

		return array( 'modules' => $modules );
	}

	private function moduleLabel( CourseDTO $course, int $lessonId ): string {
		foreach ( $course->modules as $module ) {
			if ( in_array( $lessonId, $module->lessonIds, true ) ) {
				return $module->title;
			}
		}
		return '';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function renderData( StepDTO $step ): array {
		return match ( $step->type->value ) {
			'text'       => array( 'content' => (string) ( $step->payload['content'] ?? '' ) ),
			'video'      => $this->stepRenderer->renderVideoData( $step, null ),
			'task'       => $this->renderTaskData( $step ),
			'work'       => $this->renderWorkData( $step ),
			'assessment' => $this->renderAssessmentData( $step ),
			default      => array( 'ref' => (int) ( $step->payload['ref'] ?? 0 ) ),
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function renderTaskData( StepDTO $step ): array {
		$taskId = (int) ( $step->payload['ref'] ?? 0 );
		$bundle = $this->stepRenderer->taskBundle( $taskId );
		if ( null === $bundle ) {
			return array(
				'ref'            => $taskId,
				'auto_grade'     => false,
				'template'       => '',
				'condition_html' => '',
				'widget_data'    => array(),
				'hint_html'      => '',
				'settings'       => array(),
				'attempts_used'  => 0,
				'reveal_hint'    => false,
			);
		}

		return array(
			// #5: ref нужен клиенту для dry-run проверки (PreviewCheckTask).
			'ref'            => $taskId,
			'auto_grade'     => $bundle['auto_grade'],
			'template'       => $bundle['template'],
			'condition_html' => $bundle['condition_html'],
			'widget_data'    => $bundle['widget_data'],
			// D4: в preview нет попыток/сдач — подсказка-после-N-ошибок неприменима.
			'hint_html'      => '',
			'settings'       => array(),
			'attempts_used'  => 0,
			'reveal_hint'    => false,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function renderWorkData( StepDTO $step ): array {
		$workId = (int) ( $step->payload['ref'] ?? 0 );
		$empty  = array( 'ref' => $workId, 'work_found' => false );

		$work = $workId ? $this->works->get( $workId ) : null;
		if ( null === $work ) {
			return $empty;
		}

		$tasks = array();
		foreach ( $work->itemIds as $taskId ) {
			$bundle = $this->stepRenderer->taskBundle( (int) $taskId );
			if ( null === $bundle ) {
				continue;
			}
			if ( ! $bundle['auto_grade'] ) {
				$bundle['widget_data'] = array( 'type' => 'text_answer' );
			}
			unset( $bundle['meta'] );
			$tasks[] = $bundle;
		}

		return array(
			'ref'             => $workId,
			'work_found'      => true,
			'title'           => $work->title,
			'work_type'       => $work->workType->value,
			'work_type_label' => $work->workType->label(),
			'instructions'    => wp_kses_post( $work->instructions ),
			'task_count'      => count( $tasks ),
			'total_points'    => count( $tasks ),
			'tasks'           => $tasks,
			'submission'      => null,
			'task_results'    => array(),
		);
	}

	/**
	 * Preview-рендер контрольной: как renderWorkData, но по AssessmentDTO —
	 * набор тех же задач (fs_lms_problems), собранных через taskBundle (эталоны
	 * в них не попадают). Позволяет автору прорешать контрольную в предпросмотре
	 * инлайном, без attempt-флоу/таймера/сохранения (#5, D-2).
	 *
	 * @return array<string, mixed>
	 */
	private function renderAssessmentData( StepDTO $step ): array {
		$asmId = (int) ( $step->payload['ref'] ?? 0 );
		$asm   = $asmId ? $this->assessments->get( $asmId ) : null;
		if ( null === $asm || 'publish' !== $asm->status ) {
			return array( 'ref' => $asmId, 'assessment_found' => false );
		}

		$tasks = array();
		foreach ( $asm->taskIds as $taskId ) {
			$bundle = $this->stepRenderer->taskBundle( (int) $taskId );
			if ( null === $bundle ) {
				continue;
			}
			if ( ! $bundle['auto_grade'] ) {
				$bundle['widget_data'] = array( 'type' => 'text_answer' );
			}
			unset( $bundle['meta'] );
			$tasks[] = $bundle;
		}

		return array(
			'ref'              => $asmId,
			'assessment_found' => true,
			'title'            => $asm->title,
			'time_limit_min'   => $asm->timeLimit,
			'max_attempts'     => $asm->attemptsAllowed,
			'task_count'       => count( $tasks ),
			'total_points'     => (int) $asm->maxPrimary(),
			'tasks'            => $tasks,
		);
	}
}
