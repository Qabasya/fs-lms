<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Course\ModuleDTO;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\Course\StepType;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Services\Course\CoursePublishValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * №5 (D17.5): пустой видео-шаг НЕ блокирует публикацию; прочие пустые шаги блокируют.
 */
class CoursePublishValidatorTest extends TestCase {

	private CourseManager&MockObject $courses;
	private LessonManager&MockObject $lessons;
	private CoursePublishValidator   $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->courses   = $this->createMock( CourseManager::class );
		$this->lessons   = $this->createMock( LessonManager::class );
		$this->validator = new CoursePublishValidator( $this->courses, $this->lessons );
	}

	private function courseWithLesson(): void {
		$this->courses->method( 'get' )->willReturn( new CourseDTO(
			id: 1, subjectKey: 'inf', title: 'Курс', descriptionHtml: '',
			modules: array( new ModuleDTO( 'm1', 'Модуль', array( 10 ) ) ),
			authorId: 1, status: 'draft',
		) );
	}

	private function lessonWithStep( StepDTO $step ): void {
		$this->lessons->method( 'get' )->willReturn( new LessonDTO(
			id: 10, subjectKey: 'inf', topic: 'Урок', steps: array( $step ), authorId: 1, status: 'draft',
		) );
	}

	public function test_empty_video_step_does_not_block_publish(): void {
		$this->courseWithLesson();
		$this->lessonWithStep( new StepDTO( 's1', StepType::Video, array( 'url' => '' ) ) );

		self::assertNull( $this->validator->firstEmptyStepError( 1 ) );
	}

	public function test_video_step_with_url_ok(): void {
		$this->courseWithLesson();
		$this->lessonWithStep( new StepDTO( 's1', StepType::Video, array( 'url' => 'https://s3.example.com/v.mp4' ) ) );

		self::assertNull( $this->validator->firstEmptyStepError( 1 ) );
	}

	public function test_empty_text_step_still_blocks(): void {
		$this->courseWithLesson();
		$this->lessonWithStep( new StepDTO( 's1', StepType::Text, array( 'content' => '' ) ) );

		$error = $this->validator->firstEmptyStepError( 1 );
		self::assertNotNull( $error );
		self::assertStringContainsString( 'пустой', $error );
	}

	public function test_empty_ref_step_still_blocks(): void {
		$this->courseWithLesson();
		$this->lessonWithStep( new StepDTO( 's1', StepType::Assessment, array( 'ref' => 0 ) ) );

		self::assertNotNull( $this->validator->firstEmptyStepError( 1 ) );
	}
}
