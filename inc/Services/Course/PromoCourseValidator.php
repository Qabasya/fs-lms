<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Course\WorkManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Subject\PostTypeResolver;

/** Проверяет курс перед назначением самостоятельного промо-прохождения. */
class PromoCourseValidator {

	public function __construct(
		private readonly CourseManager $courses,
		private readonly LessonManager $lessons,
		private readonly OpenCourseValidator $openCourseValidator,
		private readonly WorkManager $works,
		private readonly PostManager $posts,
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
			$this->assertReferencesExist( $lesson );
			$problems = $this->openCourseValidator->lessonProblems( $lesson );
			if ( array() !== $problems ) {
				throw new \InvalidArgumentException( 'Курс нельзя проходить самостоятельно. ' . implode( '; ', $problems ) );
			}
		}

		return $course;
	}

	/** Проверяет ссылки, которые общий валидатор пропускает при отсутствии объектов. */
	private function assertReferencesExist( LessonDTO $lesson ): void {
		$taskIds = $lesson->taskIds();
		foreach ( $lesson->workIds() as $workId ) {
			$work = $this->works->get( $workId );
			if ( null === $work || 'publish' !== $work->status ) {
				throw new \InvalidArgumentException( 'В курсе есть отсутствующая или неопубликованная работа.' );
			}
			$taskIds = array_merge( $taskIds, $work->itemIds );
		}
		foreach ( array_unique( $taskIds ) as $taskId ) {
			$post = $this->posts->get( (int) $taskId );
			if ( null === $post || 'publish' !== $post->post_status
				|| ( ! PostTypeResolver::isTaskPostType( $post->post_type ) && ! PostTypeResolver::isProblemPostType( $post->post_type ) ) ) {
				throw new \InvalidArgumentException( 'В курсе есть отсутствующее или неопубликованное задание.' );
			}
		}
	}
}
