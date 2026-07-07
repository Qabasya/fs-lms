<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\CourseDTO;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Course\WorkManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Template\TemplateResolver;

/**
 * Class OpenCourseValidator
 *
 * Валидация курса для открытых групп (Эпик 15, D-C).
 *
 * @package Inc\Services\Course
 *
 * Открытый курс проходится без преподавателя, поэтому весь оцениваемый контент
 * обязан проверяться автоматически: task-шаги и задачи внутри работ — только с
 * автопроверкой (для шаблона задачи зарегистрирован чекер), контрольные
 * (assessment-шаги) на первом этапе запрещены целиком.
 */
class OpenCourseValidator {

	public function __construct(
		private readonly LessonManager       $lessons,
		private readonly WorkManager         $works,
		private readonly PostManager         $posts,
		private readonly TemplateResolver    $templateResolver,
		private readonly TaskCheckerRegistry $checkerRegistry,
	) {}

	/**
	 * Проверяет, что весь оцениваемый контент курса автопроверяемый.
	 *
	 * @param CourseDTO $course Курс из банка.
	 *
	 * @throws \InvalidArgumentException Курс содержит контрольные или задачи без автопроверки.
	 */
	public function assertSelfCheckable( CourseDTO $course ): void {
		$problems = $this->problems( $course );

		if ( ! empty( $problems ) ) {
			throw new \InvalidArgumentException(
				'Курс нельзя назначить открытой группе — весь контент должен иметь автопроверку. '
				. implode( '; ', $problems )
			);
		}
	}

	/**
	 * Список нарушений D-C по урокам курса (пустой — курс полностью автопроверяемый).
	 * Используется и гейтом назначения (assert), и предупреждением в конструкторе.
	 *
	 * @return string[]
	 */
	public function problems( CourseDTO $course ): array {
		$problems = array();

		foreach ( $course->lessonIds() as $lessonId ) {
			$lesson = $this->lessons->get( (int) $lessonId );
			if ( null === $lesson ) {
				continue;
			}

			if ( ! empty( $lesson->assessmentIds() ) ) {
				$problems[] = sprintf( '«%s»: контрольные недоступны в открытом курсе', $lesson->topic );
			}

			$workTaskIds = array();
			foreach ( $lesson->workIds() as $workId ) {
				$workTaskIds = array_merge( $workTaskIds, $this->works->get( (int) $workId )?->itemIds ?? array() );
			}

			$manualTasks = $this->manualTaskCount( array_merge( $lesson->taskIds(), $workTaskIds ) );
			if ( $manualTasks > 0 ) {
				$problems[] = sprintf( '«%s»: задач без автопроверки — %d', $lesson->topic, $manualTasks );
			}
		}

		return $problems;
	}

	/**
	 * Число задач без автопроверки (шаблон без зарегистрированного чекера).
	 *
	 * @param int[] $taskIds
	 */
	private function manualTaskCount( array $taskIds ): int {
		$manual = 0;
		foreach ( array_unique( array_map( 'intval', $taskIds ) ) as $taskId ) {
			$post = $this->posts->get( $taskId );
			if ( ! $post ) {
				continue;
			}
			if ( ! $this->checkerRegistry->has( $this->templateResolver->resolveEnum( $post ) ) ) {
				++$manual;
			}
		}

		return $manual;
	}
}
