<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\Course\StepType;
use Inc\Managers\Course\LessonManager;
use Inc\Services\Course\LessonWorkLink;
use PHPUnit\Framework\TestCase;

/**
 * Единая ссылка «в плеер урока, сразу к шагу работы» — для дедлайнов, оценок и уведомлений.
 */
class LessonWorkLinkTest extends TestCase {

	private LessonManager $lessons;
	private LessonWorkLink $link;

	protected function setUp(): void {
		$this->lessons = $this->createMock( LessonManager::class );
		$this->link    = new LessonWorkLink( $this->lessons );
	}

	private function lessonWithWork( int $workId ): LessonDTO {
		return new LessonDTO(
			id: 7,
			subjectKey: 'math',
			topic: 'Тема',
			steps: array( new StepDTO( key: 'step-3', type: StepType::Work, payload: array( 'ref' => $workId ) ) ),
			authorId: 1,
			status: 'publish',
		);
	}

	private function row( ?int $lessonId ): GroupLessonDTO {
		return GroupLessonDTO::fromArray( array( 'id' => 100, 'group_id' => 5, 'lesson_id' => $lessonId ) );
	}

	public function test_appends_step_of_the_work_to_the_given_base_url(): void {
		$this->lessons->method( 'get' )->with( 7 )->willReturn( $this->lessonWithWork( 55 ) );

		self::assertSame( '/p/?gl=1&step=step-3', $this->link->forRow( $this->row( 7 ), 55, '/p/?gl=1' ) );
	}

	public function test_keeps_base_url_when_work_is_not_in_the_lesson(): void {
		$this->lessons->method( 'get' )->willReturn( $this->lessonWithWork( 55 ) );

		self::assertSame( '/p/?gl=1', $this->link->forRow( $this->row( 7 ), 99, '/p/?gl=1' ) );
	}

	public function test_empty_base_url_means_no_link(): void {
		$this->lessons->method( 'get' )->willReturn( $this->lessonWithWork( 55 ) );

		self::assertSame( '', $this->link->forRow( $this->row( 7 ), 55, '' ) );
	}

	public function test_without_base_url_builds_the_lesson_player_url(): void {
		self::assertStringContainsString( 'gid=5', $this->link->forRow( $this->row( null ), 55 ) );
	}
}
