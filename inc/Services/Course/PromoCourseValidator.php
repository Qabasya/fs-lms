<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\CourseDTO;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;

/** Проверяет курс перед назначением самостоятельного промо-прохождения. */
class PromoCourseValidator {

	public function __construct(
		private readonly CourseManager $courses,
		private readonly LessonManager $lessons,
		private readonly OpenCourseValidator $openCourseValidator,
	) {}

	/**
	 * Проверяет реальное состояние курса и каждого урока, не ограничивая предмет.
	 *
	 * @throws \InvalidArgumentException Курс или его содержимое недоступны для промо.
	 */
	public function requireUsable( int $courseId ): CourseDTO {
		$course = $this->courses->get( $courseId );
		if ( null === $course || 'publish' !== $course->status ) {
			throw new \InvalidArgumentException( 'Выберите опубликованный курс.' );
		}

		$lessonIds = $course->lessonIds();
		if ( array() === $lessonIds ) {
			throw new \InvalidArgumentException( 'В курсе нет уроков.' );
		}

		foreach ( array_unique( $lessonIds ) as $lessonId ) {
			$lesson = $this->lessons->get( $lessonId );
			if ( null === $lesson || 'publish' !== $lesson->status ) {
				throw new \InvalidArgumentException( 'В курсе есть отсутствующий или неопубликованный урок.' );
			}
			$problems = $this->openCourseValidator->lessonProblems( $lesson );
			if ( array() !== $problems ) {
				throw new \InvalidArgumentException( 'Курс нельзя проходить самостоятельно. ' . implode( '; ', $problems ) );
			}
		}

		return $course;
	}
}
